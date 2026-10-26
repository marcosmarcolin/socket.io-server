<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter;

use Closure;
use LogicException;
use Marcolin\SocketIO\Adapter\Cluster\MessageType;
use Marcolin\SocketIO\Adapter\Cluster\Nodes;
use Marcolin\SocketIO\Adapter\Cluster\Request;
use Marcolin\SocketIO\Binary;
use Marcolin\SocketIO\Engine\Scheduler;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Parser\Decoder;
use Marcolin\SocketIO\Parser\InvalidPacketException;
use Marcolin\SocketIO\Parser\Packet;
use Marcolin\SocketIO\Parser\PacketType;
use ReflectionClass;

/**
 * Runs a namespace over several servers through publish and subscribe, with the reference's cluster protocol: the
 * base of ChannelAdapter and RedisAdapter, and of adapters on other brokers, which only need a PubSub.
 *
 * - Broadcasts reach the sockets of this process at once, then are published already encoded, so every other
 *   process writes them to its own sockets without encoding them again.
 * - socketsJoin, socketsLeave and disconnectSockets apply on every server.
 * - serverSideEmit reaches the namespace on the other servers, and may wait for one response from each.
 * - Each server publishes a heartbeat when it has published nothing for HEARTBEAT_INTERVAL, so the others know how
 *   many servers there are; one silent for HEARTBEAT_TIMEOUT, or that closed its adapter, is gone.
 *
 * Channels, per namespace, with the key "socket.io" by default:
 * - "<key>#<nsp>#", which every server of the namespace subscribes to;
 * - "<key>#<nsp>#<room>#", which a server subscribes to while one of its sockets is in the room;
 * - "<key>-response#<nsp>#<uid>#", where a server gets the responses to its requests.
 *
 * A socket's own room (24 hex characters) gets no channel: it goes through the namespace channel.
 *
 * Messages are arrays of scalars and lists: "uid" (the sending adapter), "type" (a MessageType) and the type's
 * fields. Packets travel as their encoded parts. Malformed messages are ignored.
 */
abstract class PubSubAdapter extends InMemoryAdapter
{
    public const HEARTBEAT_INTERVAL = 5000;

    public const HEARTBEAT_TIMEOUT = 10000;

    public const REQUEST_TIMEOUT = 5000;

    // Event names the namespace keeps for itself, as in the reference.
    private const RESERVED_SERVER_EVENTS = ['connect', 'connection', 'new_namespace'];

    private readonly string $prefix;

    private readonly string $responseChannel;

    // Tells this adapter's messages from the others', since a publisher receives its own.
    private readonly string $uid;

    private readonly Scheduler $scheduler;

    private readonly Nodes $nodes;

    private ?int $heartbeat = null;

    private bool $closed = false;

    /** @var array<string, Request> requests waiting for responses, by id */
    private array $requests = [];

    /**
     * @param string $key the first part of every channel name, which keeps apps sharing a broker apart
     */
    public function __construct(Nsp $nsp, private readonly PubSub $pubSub, private readonly string $key = 'socket.io')
    {
        parent::__construct($nsp);
        $this->uid = bin2hex(random_bytes(8));
        $this->prefix = $key . '#' . $nsp->name . '#';
        $this->responseChannel = $this->responseChannel($this->uid);
        $this->scheduler = $nsp->server->scheduler();
        $this->nodes = new Nodes($this->scheduler, self::HEARTBEAT_TIMEOUT, $this->nodeGone(...));
        $this->listen($this->prefix);
        $this->listen($this->responseChannel);
        // The others answer with a heartbeat, so this server learns about them at once.
        $this->publish($this->prefix, MessageType::InitialHeartbeat);
    }

    /**
     * Not supported across processes yet: the answers of other processes' sockets wouldn't come back.
     */
    final public function broadcastWithAck(
        Packet $packet,
        BroadcastOptions $options,
        Closure $clientCount,
        Closure $ack,
    ): void {
        $adapter = (new ReflectionClass($this))->getShortName();
        throw new LogicException($adapter . ' doesn\'t support broadcasting with an acknowledgement yet');
    }

    final public function broadcast(Packet $packet, BroadcastOptions $options): void
    {
        $parts = $this->encode($packet);
        $this->deliver(
            $parts,
            $options,
            static fn (): array => is_array($packet->data) ? array_values($packet->data) : [],
        );
        $this->publish($this->channelFor($options->rooms), MessageType::Broadcast, [
            'opts' => self::wireOptions($options),
            'parts' => self::wireParts($parts),
        ]);
    }

    final public function addSockets(BroadcastOptions $options, array $rooms): void
    {
        $this->publish($this->channelFor($options->rooms), MessageType::SocketsJoin, [
            'opts' => self::wireOptions($options),
            'rooms' => $rooms,
        ]);
        parent::addSockets($options, $rooms);
    }

    final public function delSockets(BroadcastOptions $options, array $rooms): void
    {
        $this->publish($this->channelFor($options->rooms), MessageType::SocketsLeave, [
            'opts' => self::wireOptions($options),
            'rooms' => $rooms,
        ]);
        parent::delSockets($options, $rooms);
    }

    final public function disconnectSockets(BroadcastOptions $options, bool $close): void
    {
        $this->publish($this->channelFor($options->rooms), MessageType::DisconnectSockets, [
            'opts' => self::wireOptions($options),
            'close' => $close,
        ]);
        parent::disconnectSockets($options, $close);
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
        $fields = ['parts' => self::wireParts($this->encode(new Packet(PacketType::Event, '/', $packet)))];
        if ($ack === null) {
            $this->publish($this->prefix, MessageType::ServerSideEmit, $fields);

            return;
        }

        $uids = $this->nodes->uids();
        if ($uids === []) {
            $ack(null, []);

            return;
        }
        $requestId = bin2hex(random_bytes(8));
        $this->requests[$requestId] = new Request(
            $uids,
            $this->scheduler,
            self::REQUEST_TIMEOUT,
            $ack,
            function () use ($requestId): void {
                unset($this->requests[$requestId]);
            },
        );
        $this->publish($this->prefix, MessageType::ServerSideEmit, $fields + ['requestId' => $requestId]);
    }

    /**
     * Tells the other servers this one left, and stops listening.
     */
    final public function close(): void
    {
        $this->publish($this->prefix, MessageType::AdapterClose);
        $this->closed = true;
        if ($this->heartbeat !== null) {
            $this->scheduler->cancel($this->heartbeat);
        }
        $this->nodes->clear();
        foreach (array_keys($this->rooms) as $room) {
            $this->roomDeleted((string) $room);
        }
        $this->pubSub->unsubscribe($this->prefix);
        $this->pubSub->unsubscribe($this->responseChannel);
    }

    final protected function roomCreated(string $room): void
    {
        if (! self::isSocketRoom($room)) {
            $this->listen($this->prefix . $room . '#');
        }
    }

    final protected function roomDeleted(string $room): void
    {
        if (! self::isSocketRoom($room)) {
            $this->pubSub->unsubscribe($this->prefix . $room . '#');
        }
    }

    /**
     * The one channel a message about these rooms goes to.
     *
     * A single room has its own channel, which only the servers with members of it subscribe to. Anything else
     * goes once to the namespace channel, and each server filters by the rooms itself: publishing to each room's
     * channel would reach a server twice when it has members in two of the rooms.
     *
     * @param list<string> $rooms
     */
    private function channelFor(array $rooms): string
    {
        if (count($rooms) === 1 && ! self::isSocketRoom($rooms[0])) {
            return $this->prefix . $rooms[0] . '#';
        }

        return $this->prefix;
    }

    private function responseChannel(string $uid): string
    {
        return $this->key . '-response#' . $this->nsp->name . '#' . $uid . '#';
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
        $this->pubSub->publish($channel, ['uid' => $this->uid, 'type' => $type->value] + $fields);
        if ($this->closed) {
            return;
        }
        if ($this->heartbeat !== null) {
            $this->scheduler->cancel($this->heartbeat);
        }
        $this->heartbeat = $this->scheduler->after(self::HEARTBEAT_INTERVAL, function (): void {
            $this->heartbeat = null;
            $this->publish($this->prefix, MessageType::Heartbeat);
        });
    }

    private function onMessage(mixed $message): void
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
        $this->nodes->seen($uid);

        match ($type) {
            MessageType::InitialHeartbeat => $this->publish($this->prefix, MessageType::Heartbeat),
            MessageType::Broadcast => $this->onBroadcast($message),
            MessageType::SocketsJoin, MessageType::SocketsLeave => $this->onRooms($type, $message),
            MessageType::DisconnectSockets => $this->onDisconnectSockets($message),
            MessageType::ServerSideEmit => $this->onServerSideEmit($uid, $message),
            MessageType::ServerSideEmitResponse => $this->onServerSideEmitResponse($uid, $message),
            default => null,
        };
    }

    /**
     * Delivers another server's broadcast to the sockets of this one.
     *
     * @param array<mixed> $message
     */
    private function onBroadcast(array $message): void
    {
        $options = self::options($message['opts'] ?? null);
        $parts = self::parts($message['parts'] ?? null);
        if ($options === null || $parts === null) {
            return;
        }
        $this->deliver($parts, $options, static function () use ($parts): array {
            $packet = self::decode($parts);

            // The decoder gives binary events the plain Event type.
            return $packet?->type === PacketType::Event && is_array($packet->data) ? array_values($packet->data) : [];
        });
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
                $this->publish($this->responseChannel($uid), MessageType::ServerSideEmitResponse, [
                    'requestId' => $requestId,
                    'parts' => self::wireParts($parts),
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

    private function nodeGone(string $uid): void
    {
        foreach ($this->requests as $request) {
            $request->gone($uid);
        }
    }

    /**
     * @return array{rooms: list<string>, except: list<string>, volatile: bool}
     */
    private static function wireOptions(BroadcastOptions $options): array
    {
        return ['rooms' => $options->rooms, 'except' => $options->except, 'volatile' => $options->volatile];
    }

    private static function options(mixed $value): ?BroadcastOptions
    {
        if (! is_array($value)) {
            return null;
        }
        $rooms = self::strings($value['rooms'] ?? null);
        $except = self::strings($value['except'] ?? null);
        $volatile = $value['volatile'] ?? null;
        if ($rooms === null || $except === null || ! is_bool($volatile)) {
            return null;
        }

        return new BroadcastOptions($rooms, $except, $volatile);
    }

    /**
     * @param list<string|Binary> $parts
     * @return list<array{string, string}>
     */
    private static function wireParts(array $parts): array
    {
        return array_map(
            static fn (string|Binary $part): array => $part instanceof Binary
                ? ['binary', $part->data]
                : ['text', $part],
            $parts,
        );
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

    /**
     * A socket's own room: socket ids are 24 lowercase hex characters.
     */
    private static function isSocketRoom(string $room): bool
    {
        return preg_match('/^[0-9a-f]{24}$/', $room) === 1;
    }
}
