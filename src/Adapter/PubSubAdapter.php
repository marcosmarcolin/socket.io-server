<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Closure;
use LogicException;
use Marcolin\SocketIO\Adapter\Cluster\Channels;
use Marcolin\SocketIO\Adapter\Cluster\MessageType;
use Marcolin\SocketIO\Adapter\Cluster\Nodes;
use Marcolin\SocketIO\Adapter\Cluster\Request;
use Marcolin\SocketIO\Adapter\Cluster\Wire;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Emitter;
use Marcolin\SocketIO\Engine\Scheduler;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Parser\Decoder;
use Marcolin\SocketIO\Parser\InvalidPacketException;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Parser\PacketType;
use Marcolin\SocketIO\RemoteSocket;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\TimeoutException;

/**
 * Runs a namespace over several servers through publish and subscribe, with the reference's cluster protocol: the
 * base of ChannelAdapter and RedisAdapter, and of adapters on other brokers, which only need a PubSub.
 *
 * - Broadcasts reach the sockets of this process at once, then are published already encoded, so every other
 *   process writes them to its own sockets without encoding them again.
 * - socketsJoin, socketsLeave and disconnectSockets apply on every server.
 * - fetchClusterSockets asks every server for its selected sockets, and gets them as RemoteSocket.
 * - Broadcasts with an acknowledgement reach every server, which reports how many sockets it reached and relays
 *   their answers.
 * - serverSideEmit reaches the namespace on the other servers, and may wait for one response from each.
 * - Each server publishes a heartbeat when it has published nothing for HEARTBEAT_INTERVAL, so the others know how
 *   many servers there are; one silent for HEARTBEAT_TIMEOUT, or that closed its adapter, is gone.
 *
 * Channels: see Cluster\Channels, with the key "socket.io" by default:
 * - "<key>#<nsp>#", which every server of the namespace subscribes to;
 * - "<key>#<nsp>#<room>#", which a server subscribes to while one of its sockets is in the room;
 * - "<key>-response#<nsp>#<uid>#", where a server gets the responses to its requests.
 *
 * A socket's own room (24 hex characters) gets no channel: it goes through the namespace channel.
 *
 * With the local flag, every operation stays in this process, as the reference's `local` flag.
 *
 * Messages are arrays of scalars and lists: "uid" (the sending adapter), "type" (a MessageType) and the type's
 * fields. Packets travel as their encoded parts. Malformed messages are ignored.
 *
 * With a Stream (RedisStreamsAdapter), what every server must apply goes through it instead of the channels:
 * broadcasts without an acknowledgement, socketsJoin, socketsLeave and disconnectSockets (Wire::durable()). A server
 * that lost the broker reads them when it comes back, so no room channels are needed. With connectionStateRecovery,
 * an event's offset is its entry id, the same on every server, and sessions are kept in the Stream, so a client
 * recovers on any server:
 * - a recoverable broadcast reaches this process's sockets once the Stream gave its id, and the ones after it wait,
 *   so they keep their order; a local one goes through the Stream too, for its offset, and the others skip it;
 * - receivers add the entry id as the event's last argument to its encoded text, without encoding it again.
 */
abstract class PubSubAdapter extends InMemoryAdapter
{
    public const HEARTBEAT_INTERVAL = 5000;

    public const HEARTBEAT_TIMEOUT = 10000;

    public const REQUEST_TIMEOUT = 5000;

    // Event names the namespace keeps for itself, as in the reference.
    private const RESERVED_SERVER_EVENTS = ['connect', 'connection', 'new_namespace'];

    private readonly Channels $channels;

    private readonly string $responseChannel;

    // Tells this adapter's messages from the others', since a publisher receives its own.
    private readonly string $uid;

    private readonly Scheduler $scheduler;

    private readonly Nodes $nodes;

    private ?int $heartbeat = null;

    private bool $closed = false;

    /** @var array<string, Request> requests waiting for responses, by id */
    private array $requests = [];

    /** @var array<string, array{Closure(int): void, Closure(mixed...): void}> broadcasts waiting for acks, by id */
    private array $ackRequests = [];

    /** @var array<int, (Closure(): void)|null> local deliveries by position; null while waiting for the Stream */
    private array $deliveries = [];

    private int $nextDelivery = 0;

    private int $firstDelivery = 0;

    /**
     * @param string $key the first part of every channel name, which keeps apps sharing a broker apart
     * @param Stream|null $stream where what every server must apply goes, instead of the channels
     */
    public function __construct(
        Nsp $nsp,
        private readonly PubSub $pubSub,
        string $key = 'socket.io',
        private readonly ?Stream $stream = null,
    ) {
        parent::__construct($nsp);
        $this->uid = bin2hex(random_bytes(8));
        $this->channels = new Channels($key, $nsp->name);
        $this->responseChannel = $this->channels->response($this->uid);
        $this->scheduler = $nsp->server->scheduler();
        $this->nodes = new Nodes($this->scheduler, self::HEARTBEAT_TIMEOUT, $this->nodeGone(...));
        $this->listen($this->channels->namespace);
        $this->listen($this->responseChannel);
        $this->stream?->subscribe($nsp->name, function (array $message, string $id): void {
            $this->onMessage($message, $id);
        });
        // The others answer with a heartbeat, so this server learns about them at once.
        $this->publish($this->channels->namespace, MessageType::InitialHeartbeat);
    }

    /**
     * Reaches the sockets of every server. Each other server reports its client count and relays its sockets'
     * answers until the timeout, after which they are dropped.
     *
     * @throws LogicException without a timeout in the options
     */
    final public function broadcastWithAck(
        Packet $packet,
        BroadcastOptions $options,
        Closure $clientCount,
        Closure $ack,
    ): void {
        if ($options->local) {
            parent::broadcastWithAck($packet, $options, $clientCount, $ack);

            return;
        }
        $timeout = $options->timeout ?? throw new LogicException('A broadcast with an acknowledgement needs a timeout');
        $requestId = bin2hex(random_bytes(8));
        $this->ackRequests[$requestId] = [$clientCount, $ack];
        $this->scheduler->after($timeout, function () use ($requestId): void {
            unset($this->ackRequests[$requestId]);
        });
        // Not a room channel: every counted server must report, members or not.
        $this->publish($this->channels->namespace, MessageType::Broadcast, [
            'opts' => Wire::options($options),
            'parts' => Wire::parts($this->encode($packet)),
            'requestId' => $requestId,
        ]);
        parent::broadcastWithAck($packet, $options, $clientCount, $ack);
    }

    final public function broadcast(Packet $packet, BroadcastOptions $options): void
    {
        if ($this->stream !== null) {
            $this->broadcastThroughStream($this->stream, $packet, $options);

            return;
        }
        $offset = $this->recoverable($packet, $options) ? $this->offset() : null;
        if ($offset !== null) {
            $packet = self::withOffset($packet, $offset);
        }
        $parts = $this->encode($packet);
        if ($offset !== null) {
            $this->keep($offset, $parts, $options);
        }
        $this->deliver($parts, $options, self::eventData($packet));
        if ($options->local) {
            return;
        }
        if ($offset === null) {
            $this->publish($this->channels->forRooms($options->rooms), MessageType::Broadcast, [
                'opts' => Wire::options($options),
                'parts' => Wire::parts($parts),
            ]);

            return;
        }
        // Every process keeps it, through the namespace channel: one whose last member of these rooms dropped
        // has left their channels, but keeps that member's session.
        $this->publish($this->channels->namespace, MessageType::Broadcast, [
            'opts' => Wire::options($options),
            'parts' => Wire::parts($parts),
            'offset' => $offset,
        ]);
    }

    /**
     * Keeps the session in the Stream, when there is one, so any server can restore it.
     */
    final public function persistSession(Session $session): void
    {
        $recovery = $this->nsp->server->connectionStateRecovery;
        if ($this->stream === null || $recovery === null) {
            parent::persistSession($session);

            return;
        }
        // The uid tells which local broadcasts reached it: only this server's.
        $this->stream->saveSession($session->pid, self::json([
            'sid' => $session->sid,
            'pid' => $session->pid,
            'rooms' => $session->rooms,
            'data' => $session->data,
            'uid' => $this->uid,
        ]), $recovery->maxDisconnectionDuration);
    }

    final public function restoreSession(string $pid, string $offset, Closure $callback): void
    {
        if ($this->stream === null) {
            parent::restoreSession($pid, $offset, $callback);

            return;
        }
        $this->stream->restoreSession(
            $this->nsp->name,
            $pid,
            $offset,
            function (?string $kept, array $entries) use ($callback): void {
                $found = $kept === null ? null : self::session($kept);
                if ($found === null) {
                    $callback(null);

                    return;
                }
                [$session, $uid] = $found;
                $missed = [];
                foreach ($entries as [$message, $id]) {
                    $parts = self::missed($message, $id, $session, $uid);
                    if ($parts !== null) {
                        $missed[] = $parts;
                    }
                }
                $callback(new RestoredSession($session, $missed));
            },
        );
    }

    final public function addSockets(BroadcastOptions $options, array $rooms): void
    {
        if (! $options->local) {
            $this->publish($this->channels->forRooms($options->rooms), MessageType::SocketsJoin, [
                'opts' => Wire::options($options),
                'rooms' => $rooms,
            ]);
        }
        parent::addSockets($options, $rooms);
    }

    final public function delSockets(BroadcastOptions $options, array $rooms): void
    {
        if (! $options->local) {
            $this->publish($this->channels->forRooms($options->rooms), MessageType::SocketsLeave, [
                'opts' => Wire::options($options),
                'rooms' => $rooms,
            ]);
        }
        parent::delSockets($options, $rooms);
    }

    final public function disconnectSockets(BroadcastOptions $options, bool $close): void
    {
        if (! $options->local) {
            $this->publish($this->channels->forRooms($options->rooms), MessageType::DisconnectSockets, [
                'opts' => Wire::options($options),
                'close' => $close,
            ]);
        }
        parent::disconnectSockets($options, $close);
    }

    final public function fetchClusterSockets(BroadcastOptions $options, Closure $callback): void
    {
        $local = $this->fetchSockets($options);
        $uids = $options->local ? [] : $this->nodes->uids();
        if ($uids === []) {
            $callback(null, $local);

            return;
        }
        // Not a room channel: every server must get the request to answer it, members or not.
        $this->request(
            $uids,
            MessageType::FetchSockets,
            ['opts' => Wire::options($options)],
            static function (?TimeoutException $error, array $responses) use ($local, $callback): void {
                /** @var list<list<RemoteSocket>> $responses */
                $callback($error, [...$local, ...array_merge(...$responses)]);
            },
        );
    }

    final public function serverCount(): int
    {
        return 1 + $this->nodes->count();
    }

    final public function serverSideEmit(array $packet): void
    {
        $last = $packet === [] ? null : $packet[array_key_last($packet)];
        $ack = $last instanceof Closure ? $last : null;
        if ($ack !== null) {
            array_pop($packet);
        }
        $fields = ['parts' => Wire::parts($this->encode(new Packet(PacketType::Event, '/', $packet)))];
        if ($ack === null) {
            $this->publish($this->channels->namespace, MessageType::ServerSideEmit, $fields);

            return;
        }

        $uids = $this->nodes->uids();
        if ($uids === []) {
            $ack(null, []);

            return;
        }
        $this->request($uids, MessageType::ServerSideEmit, $fields, $ack);
    }

    /**
     * Tells the other servers this one left, and stops listening.
     */
    final public function close(): void
    {
        $this->publish($this->channels->namespace, MessageType::AdapterClose);
        $this->closed = true;
        if ($this->heartbeat !== null) {
            $this->scheduler->cancel($this->heartbeat);
        }
        $this->nodes->clear();
        foreach (array_keys($this->rooms) as $room) {
            $this->roomDeleted((string) $room);
        }
        $this->pubSub->unsubscribe($this->channels->namespace);
        $this->pubSub->unsubscribe($this->responseChannel);
        $this->stream?->unsubscribe($this->nsp->name);
    }

    final protected function roomCreated(string $room): void
    {
        // With a Stream, every server reads every durable message and filters by the rooms itself.
        if ($this->stream === null && ! Channels::isSocketRoom($room)) {
            $this->listen($this->channels->room($room));
        }
    }

    final protected function roomDeleted(string $room): void
    {
        if ($this->stream === null && ! Channels::isSocketRoom($room)) {
            $this->pubSub->unsubscribe($this->channels->room($room));
        }
    }

    /**
     * A broadcast with a Stream. A recoverable one goes through it even when local, since its offset is its entry
     * id; its local delivery waits for the id, and later ones wait for it, so this process keeps the order.
     */
    private function broadcastThroughStream(Stream $stream, Packet $packet, BroadcastOptions $options): void
    {
        $parts = $this->encode($packet);
        $fields = ['opts' => Wire::options($options), 'parts' => Wire::parts($parts)];
        if (! $this->recoverable($packet, $options)) {
            $this->deliverInOrder(function () use ($parts, $options, $packet): void {
                $this->deliver($parts, $options, self::eventData($packet));
            });
            if (! $options->local) {
                $this->publish($this->channels->namespace, MessageType::Broadcast, $fields);
            }

            return;
        }
        $position = $this->nextDelivery++;
        $this->deliveries[$position] = null;
        $fields['recoverable'] = true;
        if ($options->local) {
            $fields['local'] = true;
        }
        $stream->append(
            $this->nsp->name,
            Wire::message($this->uid, MessageType::Broadcast, $fields),
            function (?string $id) use ($position, $parts, $packet, $options): void {
                $this->deliveries[$position] = function () use ($id, $parts, $packet, $options): void {
                    // Without an id, the sockets get the event all the same, only without an offset.
                    if ($id !== null) {
                        $packet = self::withOffset($packet, $id);
                        $parts = $this->encode($packet);
                    }
                    $this->deliver($parts, $options, self::eventData($packet));
                };
                $this->flushDeliveries();
            },
        );
        $this->postponeHeartbeat();
    }

    /**
     * Delivers now, or after the broadcasts still waiting for their entry id.
     *
     * @param Closure(): void $delivery
     */
    private function deliverInOrder(Closure $delivery): void
    {
        if ($this->firstDelivery === $this->nextDelivery) {
            $delivery();

            return;
        }
        $this->deliveries[$this->nextDelivery++] = $delivery;
    }

    private function flushDeliveries(): void
    {
        while (($delivery = $this->deliveries[$this->firstDelivery] ?? null) !== null) {
            unset($this->deliveries[$this->firstDelivery]);
            ++$this->firstDelivery;
            $delivery();
        }
    }

    private function listen(string $channel): void
    {
        $this->pubSub->subscribe($channel, function (mixed $message): void {
            $this->onMessage($message);
        });
    }

    /**
     * Publishes a message, and puts off the next heartbeat: any message tells the others this server is alive.
     *
     * @param array<string, mixed> $fields
     */
    private function publish(string $channel, MessageType $type, array $fields = []): void
    {
        $message = Wire::message($this->uid, $type, $fields);
        if ($this->stream !== null && Wire::durable($type, $fields)) {
            $this->stream->append($this->nsp->name, $message, static function (): void {
            });
        } else {
            $this->pubSub->publish($channel, $message);
        }
        $this->postponeHeartbeat();
    }

    private function postponeHeartbeat(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->heartbeat !== null) {
            $this->scheduler->cancel($this->heartbeat);
        }
        $this->heartbeat = $this->scheduler->after(self::HEARTBEAT_INTERVAL, function (): void {
            $this->heartbeat = null;
            $this->publish($this->channels->namespace, MessageType::Heartbeat);
        });
    }

    /**
     * Publishes a request to the namespace channel, and waits for one response from each of these servers.
     *
     * @param list<string> $uids
     * @param array<string, mixed> $fields
     * @param Closure(?TimeoutException, list<mixed>): void $callback
     */
    private function request(array $uids, MessageType $type, array $fields, Closure $callback): void
    {
        $requestId = bin2hex(random_bytes(8));
        $this->requests[$requestId] = new Request(
            $uids,
            $this->scheduler,
            self::REQUEST_TIMEOUT,
            $callback,
            function () use ($requestId): void {
                unset($this->requests[$requestId]);
            },
        );
        $this->publish($this->channels->namespace, $type, $fields + ['requestId' => $requestId]);
    }

    /**
     * @param string|null $id the entry id, for a message read from the Stream
     */
    private function onMessage(mixed $message, ?string $id = null): void
    {
        if (! is_array($message)) {
            return;
        }
        $uid = $message['uid'] ?? null;
        $type = $message['type'] ?? null;
        $type = is_int($type) ? MessageType::tryFrom($type) : null;
        if (! is_string($uid) || $uid === $this->uid || $type === null) {
            return;
        }
        if ($type === MessageType::AdapterClose) {
            $this->nodes->remove($uid);

            return;
        }
        // An Emitter publishes but is no server, so it never counts, as in the reference.
        if ($uid !== Emitter::UID && $this->nodes->seen($uid)) {
            $this->log(sprintf('server %s joined; %d servers', $uid, $this->serverCount()));
        }

        match ($type) {
            MessageType::InitialHeartbeat => $this->publish($this->channels->namespace, MessageType::Heartbeat),
            MessageType::Broadcast => $this->onBroadcast($uid, $message, $id),
            MessageType::SocketsJoin, MessageType::SocketsLeave => $this->onRooms($type, $message),
            MessageType::DisconnectSockets => $this->onDisconnectSockets($message),
            MessageType::ServerSideEmit => $this->onServerSideEmit($uid, $message),
            MessageType::ServerSideEmitResponse => $this->onServerSideEmitResponse($uid, $message),
            MessageType::FetchSockets => $this->onFetchSockets($uid, $message),
            MessageType::FetchSocketsResponse => $this->onFetchSocketsResponse($uid, $message),
            MessageType::BroadcastClientCount, MessageType::BroadcastAck => $this->onBroadcastResponse($type, $message),
            default => null,
        };
    }

    /**
     * Delivers another server's broadcast to the sockets of this one.
     *
     * @param array<mixed> $message
     * @param string|null $id the entry id, for a broadcast read from the Stream
     */
    private function onBroadcast(string $uid, array $message, ?string $id): void
    {
        $options = self::options($message['opts'] ?? null);
        $parts = self::parts($message['parts'] ?? null);
        if ($options === null || $parts === null) {
            return;
        }
        if ($id !== null) {
            // A local broadcast is in the Stream only for the recovery of the sender's sockets.
            if (($message['local'] ?? false) === true) {
                return;
            }
            if (($message['recoverable'] ?? false) === true && $this->nsp->server->connectionStateRecovery !== null) {
                $parts = self::withEntryId($parts, $id);
                if ($parts === null) {
                    return;
                }
            }
        }
        $requestId = $message['requestId'] ?? null;
        if ($requestId !== null) {
            if (is_string($requestId)) {
                $this->onBroadcastWithAck($uid, $requestId, $parts, $options);
            }

            return;
        }
        // Kept under the sender's offset, so a client of this process that comes back here recovers it.
        $offset = $message['offset'] ?? null;
        if (is_string($offset) && $this->nsp->server->connectionStateRecovery !== null && ! $options->volatile) {
            $this->keep($offset, $parts, $options);
        }
        $this->deliver($parts, $options, static function () use ($parts): array {
            $packet = self::decode($parts);

            // The decoder gives binary events the plain Event type.
            return $packet?->type === PacketType::Event && is_array($packet->data) ? array_values($packet->data) : [];
        });
    }

    /**
     * Sends another server's broadcast with an acknowledgement to the sockets of this one, under an ack id of this
     * namespace, and reports back to that server: the client count, then each answer's first argument.
     *
     * @param list<string|Binary> $parts
     */
    private function onBroadcastWithAck(string $uid, string $requestId, array $parts, BroadcastOptions $options): void
    {
        $packet = self::decode($parts);
        $timeout = $options->timeout;
        if ($packet?->type !== PacketType::Event || ! is_array($packet->data) || $timeout === null || $timeout <= 0) {
            return;
        }
        $id = $this->nsp->nextAckId();
        $channel = $this->channels->response($uid);
        parent::broadcastWithAck(
            new Packet(PacketType::Event, '/', array_values($packet->data), $id),
            $options,
            function (int $clientCount) use ($channel, $requestId): void {
                $this->publish($channel, MessageType::BroadcastClientCount, [
                    'requestId' => $requestId,
                    'clientCount' => $clientCount,
                ]);
            },
            function (mixed ...$args) use ($channel, $requestId): void {
                $this->publish($channel, MessageType::BroadcastAck, [
                    'requestId' => $requestId,
                    'parts' => Wire::parts($this->encode(new Packet(PacketType::Ack, '/', [$args[0] ?? null], 0))),
                ]);
            },
        );
        // The requester stops listening after the timeout; so do these sockets.
        $this->scheduler->after($timeout, function () use ($id): void {
            foreach ($this->nsp->sockets() as $socket) {
                $socket->forgetAck($id);
            }
        });
    }

    /**
     * @param array<mixed> $message
     */
    private function onBroadcastResponse(MessageType $type, array $message): void
    {
        $requestId = $message['requestId'] ?? null;
        $request = is_string($requestId) ? ($this->ackRequests[$requestId] ?? null) : null;
        if ($request === null) {
            return;
        }
        [$clientCount, $ack] = $request;
        if ($type === MessageType::BroadcastClientCount) {
            $count = $message['clientCount'] ?? null;
            if (is_int($count) && $count >= 0) {
                $clientCount($count);
            }

            return;
        }
        $parts = self::parts($message['parts'] ?? null);
        $packet = $parts === null ? null : self::decode($parts);
        if ($packet?->type === PacketType::Ack && is_array($packet->data)) {
            $ack($packet->data[0] ?? null);
        }
    }

    /**
     * @param array<mixed> $message
     */
    private function onRooms(MessageType $type, array $message): void
    {
        $options = self::options($message['opts'] ?? null);
        $rooms = self::strings($message['rooms'] ?? null);
        if ($options === null || $rooms === null) {
            return;
        }
        if ($type === MessageType::SocketsJoin) {
            parent::addSockets($options, $rooms);
        } else {
            parent::delSockets($options, $rooms);
        }
    }

    /**
     * @param array<mixed> $message
     */
    private function onDisconnectSockets(array $message): void
    {
        $options = self::options($message['opts'] ?? null);
        $close = $message['close'] ?? null;
        if ($options !== null && is_bool($close)) {
            parent::disconnectSockets($options, $close);
        }
    }

    /**
     * Hands another server's event to the namespace's listeners, with an acknowledgement callback last when that
     * server waits for a response; only its first argument is sent, once.
     *
     * @param array<mixed> $message
     */
    private function onServerSideEmit(string $uid, array $message): void
    {
        $parts = self::parts($message['parts'] ?? null);
        $packet = $parts === null ? null : self::decode($parts);
        $args = $packet?->type === PacketType::Event && is_array($packet->data) ? array_values($packet->data) : [];
        $event = $args[0] ?? null;
        $requestId = $message['requestId'] ?? null;
        if (! is_string($event) || in_array($event, self::RESERVED_SERVER_EVENTS, true)) {
            return;
        }
        if (is_string($requestId)) {
            $answered = false;
            $args[] = function (mixed ...$response) use (&$answered, $uid, $requestId): void {
                if ($answered) {
                    return;
                }
                $answered = true;
                $parts = $this->encode(new Packet(PacketType::Ack, '/', [$response[0] ?? null], 0));
                $this->publish($this->channels->response($uid), MessageType::ServerSideEmitResponse, [
                    'requestId' => $requestId,
                    'parts' => Wire::parts($parts),
                ]);
            };
        }
        $this->nsp->receiveServerSideEmit($args);
    }

    /**
     * @param array<mixed> $message
     */
    private function onServerSideEmitResponse(string $uid, array $message): void
    {
        $requestId = $message['requestId'] ?? null;
        $request = is_string($requestId) ? ($this->requests[$requestId] ?? null) : null;
        $parts = self::parts($message['parts'] ?? null);
        $packet = $parts === null ? null : self::decode($parts);
        if ($request !== null && $packet?->type === PacketType::Ack && is_array($packet->data)) {
            $request->respond($uid, $packet->data[0] ?? null);
        }
    }

    /**
     * Answers another server with this process's selected sockets. Their handshake and data cross as JSON, so the
     * message holds only scalars.
     *
     * @param array<mixed> $message
     */
    private function onFetchSockets(string $uid, array $message): void
    {
        $options = self::options($message['opts'] ?? null);
        $requestId = $message['requestId'] ?? null;
        if ($options === null || ! is_string($requestId)) {
            return;
        }
        $this->publish($this->channels->response($uid), MessageType::FetchSocketsResponse, [
            'requestId' => $requestId,
            'sockets' => array_map(static fn (Socket $socket): array => [
                'id' => $socket->id,
                'handshake' => self::json($socket->handshake),
                'rooms' => $socket->rooms,
                'data' => self::json($socket->data),
            ], parent::fetchSockets($options)),
        ]);
    }

    /**
     * @param array<mixed> $message
     */
    private function onFetchSocketsResponse(string $uid, array $message): void
    {
        $requestId = $message['requestId'] ?? null;
        $request = is_string($requestId) ? ($this->requests[$requestId] ?? null) : null;
        $entries = $message['sockets'] ?? null;
        if ($request === null || ! is_array($entries) || ! array_is_list($entries)) {
            return;
        }
        $sockets = [];
        foreach ($entries as $entry) {
            $socket = is_array($entry) ? $this->remoteSocket($entry) : null;
            if ($socket === null) {
                return;
            }
            $sockets[] = $socket;
        }
        $request->respond($uid, $sockets);
    }

    /**
     * @param array<mixed> $entry
     */
    private function remoteSocket(array $entry): ?RemoteSocket
    {
        $id = $entry['id'] ?? null;
        $rooms = self::strings($entry['rooms'] ?? null);
        $handshake = $entry['handshake'] ?? null;
        $data = $entry['data'] ?? null;
        if (! is_string($id) || $rooms === null || ! is_string($handshake) || ! is_string($data)) {
            return null;
        }
        $handshake = json_decode($handshake, true);
        if (! is_array($handshake)) {
            return null;
        }

        return new RemoteSocket($this->nsp, $id, $handshake, $rooms, json_decode($data, true));
    }

    /**
     * JSON for any value: what can't be encoded becomes null, and invalid UTF-8 is replaced.
     */
    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';
    }

    /**
     * The session a server kept in the Stream, and that server's uid.
     *
     * @return array{Session, string}|null
     */
    private static function session(string $kept): ?array
    {
        $value = json_decode($kept, true);
        if (! is_array($value)) {
            return null;
        }
        $sid = $value['sid'] ?? null;
        $pid = $value['pid'] ?? null;
        $rooms = self::strings($value['rooms'] ?? null);
        $data = $value['data'] ?? null;
        $uid = $value['uid'] ?? null;
        if (! is_string($sid) || ! is_string($pid) || $rooms === null || ! is_array($data) || ! is_string($uid)) {
            return null;
        }

        return [new Session($sid, $pid, $rooms, $data), $uid];
    }

    /**
     * The encoded event a recovering socket missed, with its offset, or null when this entry doesn't reach it: as
     * the reference, only recoverable broadcasts to its rooms, and local ones only from the server it was on.
     *
     * @param array<mixed> $message
     * @return list<string|Binary>|null
     */
    private static function missed(array $message, string $id, Session $session, string $uid): ?array
    {
        if (
            ($message['type'] ?? null) !== MessageType::Broadcast->value
            || ($message['recoverable'] ?? false) !== true
            || (($message['local'] ?? false) === true && ($message['uid'] ?? null) !== $uid)
        ) {
            return null;
        }
        $options = self::options($message['opts'] ?? null);
        $parts = self::parts($message['parts'] ?? null);
        if ($options === null || $parts === null) {
            return null;
        }
        if (! self::reaches($session->rooms, $options->rooms, $options->except)) {
            return null;
        }

        return self::withEntryId($parts, $id);
    }

    /**
     * An encoded event with the entry id as its last argument, added to its text: the event's arguments are a JSON
     * array, so it ends with "]".
     *
     * @param list<string|Binary> $parts
     * @return list<string|Binary>|null
     */
    private static function withEntryId(array $parts, string $id): ?array
    {
        $text = $parts[0];
        if (! is_string($text) || ! str_ends_with($text, ']')) {
            return null;
        }
        $parts[0] = substr($text, 0, -1) . ',' . json_encode($id) . ']';

        return $parts;
    }

    private function nodeGone(string $uid): void
    {
        $this->log(sprintf('server %s left; %d servers', $uid, $this->serverCount()));
        foreach ($this->requests as $request) {
            $request->gone($uid);
        }
    }


    private static function options(mixed $value): ?BroadcastOptions
    {
        if (! is_array($value)) {
            return null;
        }
        $rooms = self::strings($value['rooms'] ?? null);
        $except = self::strings($value['except'] ?? null);
        $volatile = $value['volatile'] ?? null;
        $timeout = $value['timeout'] ?? null;
        $compress = $value['compress'] ?? true;
        if ($rooms === null || $except === null || ! is_bool($volatile) || ! is_bool($compress)) {
            return null;
        }
        if ($timeout !== null && ! is_int($timeout)) {
            return null;
        }

        return new BroadcastOptions($rooms, $except, $volatile, $timeout, compress: $compress);
    }


    /**
     * @return list<string|Binary>|null
     */
    private static function parts(mixed $value): ?array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return null;
        }
        $parts = [];
        foreach ($value as $part) {
            $kind = is_array($part) ? ($part[0] ?? null) : null;
            $data = is_array($part) ? ($part[1] ?? null) : null;
            if (! is_string($data) || ($kind !== 'text' && $kind !== 'binary')) {
                return null;
            }
            $parts[] = $kind === 'binary' ? new Binary($data) : $data;
        }

        return $parts;
    }

    /**
     * The packet the parts make, or null when they don't make exactly one.
     *
     * @param list<string|Binary> $parts
     */
    private static function decode(array $parts): ?Packet
    {
        $decoder = new Decoder();
        $packet = null;
        try {
            foreach ($parts as $part) {
                if ($packet !== null) {
                    return null;
                }
                $packet = $decoder->add($part);
            }
        } catch (InvalidPacketException) {
            return null;
        }

        return $packet;
    }

    /**
     * @return list<string>|null
     */
    private static function strings(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                return null;
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    private function log(string $message): void
    {
        $this->nsp->server->logger()->debug('socket.io:adapter', $this->nsp->name . ': ' . $message);
    }
}
