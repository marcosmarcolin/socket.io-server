<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Admin;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Marcolin\SocketIO\Engine\CloseReason;
use Marcolin\SocketIO\Engine\Packet;
use Marcolin\SocketIO\Engine\Socket as EngineSocket;
use Marcolin\SocketIO\Engine\Transport\Transport;
use Marcolin\SocketIO\Engine\Workerman\EventLoop;
use Marcolin\SocketIO\Engine\Workerman\WorkermanScheduler;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\RemoteSocket;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use stdClass;
use Throwable;

/**
 * The server side of the official Socket.IO Admin UI (https://admin.socket.io), as the reference's
 * `instrument()` from @socket.io/admin-ui 0.5: an admin namespace that sends the UI the servers, sockets, rooms and
 * events, and runs its actions. The UI itself is the official one, hosted or self-hosted.
 */
final class AdminUi
{
    private const STATS_INTERVAL = 2000;

    private readonly Nsp $admin;

    private readonly EventBuffer $events;

    /** @var list<Feature> */
    private readonly array $features;

    private readonly float $startedAt;

    private int $pollingClients = 0;

    /** @var array<string, string> the session id each admin socket gets once connected, by socket id */
    private array $sessions = [];

    /**
     * @param false|array{username: string, password: string} $auth
     */
    private function __construct(
        private readonly SocketIO $io,
        string $namespaceName,
        private readonly false|array $auth,
        bool $readonly,
        private readonly ?string $serverId,
        private readonly Store $store,
        private readonly bool $development,
    ) {
        $this->admin = $io->of($namespaceName);
        $this->events = new EventBuffer();
        $this->startedAt = microtime(true);
        $features = $readonly ? [] : [
            Feature::Emit,
            Feature::Join,
            Feature::Leave,
            Feature::Disconnect,
            Feature::MJoin,
            Feature::MLeave,
            Feature::MDisconnect,
        ];
        $features[] = Feature::AggregatedEvents;
        if ($development) {
            $features[] = Feature::AllEvents;
        }
        $this->features = $features;
    }

    /**
     * Instruments the server for the Admin UI. Call it once the server is attached to its worker, such as right
     * after new SocketIO($port): its stats start with each worker.
     *
     * @param array<string, mixed> $options auth (required: false, or ['type' => 'basic', 'username' => ...,
     *        'password' => a bcrypt hash]), namespaceName ('/admin'), readonly (false), serverId (hostname#pid),
     *        store (an InMemoryStore), mode ('development' or 'production')
     * @throws InvalidArgumentException for a missing or invalid option
     */
    public static function instrument(SocketIO $io, array $options): self
    {
        $unknown = array_diff(
            array_keys($options),
            ['auth', 'namespaceName', 'readonly', 'serverId', 'store', 'mode'],
        );
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unknown Admin UI option: ' . implode(', ', array_map('strval', $unknown)),
            );
        }
        $namespaceName = $options['namespaceName'] ?? '/admin';
        $readonly = $options['readonly'] ?? false;
        $serverId = $options['serverId'] ?? null;
        $store = $options['store'] ?? new InMemoryStore();
        $mode = $options['mode'] ?? 'development';
        if (! is_string($namespaceName) || ! str_starts_with($namespaceName, '/')) {
            throw new InvalidArgumentException('"namespaceName" must be a namespace name, such as "/admin"');
        }
        if (! is_bool($readonly)) {
            throw new InvalidArgumentException('"readonly" must be a boolean');
        }
        if ($serverId !== null && (! is_string($serverId) || $serverId === '')) {
            throw new InvalidArgumentException('"serverId" must be a non-empty string');
        }
        if (! $store instanceof Store) {
            throw new InvalidArgumentException('"store" must be a ' . Store::class);
        }
        if ($mode !== 'development' && $mode !== 'production') {
            throw new InvalidArgumentException('"mode" must be "development" or "production"');
        }

        $admin = new self(
            $io,
            $namespaceName,
            self::auth($options),
            $readonly,
            $serverId,
            $store,
            $mode === 'development',
        );
        $admin->start();

        return $admin;
    }

    /**
     * @param array<string, mixed> $options
     * @return false|array{username: string, password: string}
     */
    private static function auth(array $options): false|array
    {
        if (! array_key_exists('auth', $options)) {
            throw new InvalidArgumentException('The "auth" option must be given, or set to false explicitly');
        }
        $auth = $options['auth'];
        if ($auth === false) {
            return false;
        }
        if (! is_array($auth) || ($auth['type'] ?? null) !== 'basic') {
            throw new InvalidArgumentException('"auth" must be false, or ["type" => "basic", "username", "password"]');
        }
        $username = $auth['username'] ?? null;
        $password = $auth['password'] ?? null;
        if (! is_string($username) || $username === '') {
            throw new InvalidArgumentException('"auth" needs a username');
        }
        // A bcrypt hash, as password_hash() makes, so the password itself is never in the code.
        if (! is_string($password) || preg_match('#^\$2[aby]\$\d{2}\$[./A-Za-z0-9]{53}$#', $password) !== 1) {
            throw new InvalidArgumentException('The "password" of "auth" must be a bcrypt hash, from password_hash()');
        }

        return ['username' => $username, 'password' => $password];
    }

    private function start(): void
    {
        if ($this->auth !== false) {
            $this->admin->use($this->authenticate(...));
        }
        $this->admin->on('connection', $this->onAdminConnection(...));
        $this->io->engine->on('connection', $this->onEngineConnection(...));
        if ($this->development) {
            foreach ($this->io->namespaces() as $nsp) {
                $this->watch($nsp);
            }
            $this->io->on('new_namespace', $this->watch(...));
        }
        // Workerman's timers need its event loop, which each worker starts.
        if (EventLoop::runs() || ! $this->io->scheduler() instanceof WorkermanScheduler) {
            $this->emitStats();
        } else {
            $this->io->on('workerStart', function (): void {
                $this->emitStats();
            });
        }
    }

    /**
     * @param Closure(?Throwable=): void $next
     */
    private function authenticate(Socket $socket, Closure $next): void
    {
        $auth = $socket->handshake['auth'];
        $sessionId = $auth['sessionId'] ?? null;
        if (is_string($sessionId) && $this->store->doesSessionExist($sessionId)) {
            $next();

            return;
        }
        $username = $auth['username'] ?? null;
        $password = $auth['password'] ?? null;
        if (
            $this->auth !== false
            && $username === $this->auth['username']
            && is_string($password)
            && password_verify($password, $this->auth['password'])
        ) {
            $sessionId = bin2hex(random_bytes(8));
            $this->store->saveSession($sessionId);
            // Sent once connected: the reference sends it before, which a socket here can't.
            $this->sessions[$socket->id] = $sessionId;
            $next();

            return;
        }
        $next(new InvalidArgumentException('invalid credentials'));
    }

    private function onAdminConnection(Socket $socket): void
    {
        $this->handleActions($socket);
        $sessionId = $this->sessions[$socket->id] ?? null;
        unset($this->sessions[$socket->id]);
        if ($sessionId !== null) {
            $socket->emit('session', $sessionId);
        }
        $socket->emit('config', ['supportedFeatures' => array_map(
            static fn (Feature $feature): string => $feature->value,
            $this->features,
        )]);
        if ($this->development) {
            $this->allSockets(static function (array $sockets) use ($socket): void {
                $socket->emit('all_sockets', $sockets);
            });
        }
    }

    /**
     * The actions the UI asks for, as the reference's feature handlers, on namespaces that exist.
     */
    private function handleActions(Socket $socket): void
    {
        if (! in_array(Feature::Emit, $this->features, true)) {
            return;
        }
        $socket->on('emit', function (mixed ...$args): void {
            if ((end($args) ?: null) instanceof Closure) {
                array_pop($args);
            }
            [$name, $filter, $event] = $args + [null, null, null];
            $nsp = $this->namespace($name);
            if ($nsp === null || ! is_string($event)) {
                return;
            }
            try {
                is_string($filter) && $filter !== ''
                    ? $nsp->in($filter)->emit($event, ...array_slice($args, 3))
                    : $nsp->emit($event, ...array_slice($args, 3));
            } catch (InvalidArgumentException) {
                // A reserved name, or data that can't be sent: there is no one to tell but the UI's operator.
            }
        });
        $socket->on('join', function (mixed $name, mixed $room, mixed $filter = null): void {
            $nsp = $this->namespace($name);
            if ($nsp !== null && is_string($room)) {
                is_string($filter) && $filter !== '' ? $nsp->in($filter)->socketsJoin($room) : $nsp->socketsJoin($room);
            }
        });
        $socket->on('leave', function (mixed $name, mixed $room, mixed $filter = null): void {
            $nsp = $this->namespace($name);
            if ($nsp !== null && is_string($room)) {
                is_string($filter) && $filter !== ''
                    ? $nsp->in($filter)->socketsLeave($room)
                    : $nsp->socketsLeave($room);
            }
        });
        $socket->on('_disconnect', function (mixed $name, mixed $close = false, mixed $filter = null): void {
            $nsp = $this->namespace($name);
            if ($nsp !== null) {
                is_string($filter) && $filter !== ''
                    ? $nsp->in($filter)->disconnectSockets($close === true)
                    : $nsp->disconnectSockets($close === true);
            }
        });
    }

    /**
     * A namespace the server already has: the UI can't create one, unlike the reference's io.of().
     */
    private function namespace(mixed $name): ?Nsp
    {
        return is_string($name) && $this->io->hasNamespace($name) ? $this->io->of($name) : null;
    }

    /**
     * The sockets of every namespace, on every server the adapters reach.
     *
     * @param Closure(list<array<string, mixed>>): void $callback
     */
    private function allSockets(Closure $callback): void
    {
        $namespaces = $this->io->namespaces();
        $pending = count($namespaces);
        $all = [];
        foreach ($namespaces as $nsp) {
            $name = $nsp->name;
            $collect = static function (
                ?Throwable $error,
                array $sockets,
            ) use (
                $name,
                &$pending,
                &$all,
                $callback,
            ): void {
                foreach ($sockets as $socket) {
                    if ($socket instanceof Socket || $socket instanceof RemoteSocket) {
                        $all[] = self::serialize($socket, $name);
                    }
                }
                if (--$pending === 0) {
                    $callback($all);
                }
            };
            $nsp->fetchSockets($collect);
        }
    }

    /**
     * Reports what happens in a namespace, as the reference's verbose listeners in development mode.
     */
    private function watch(Nsp $nsp): void
    {
        $nsp->on('connection', function (Socket $socket) use ($nsp): void {
            // As the reference, where the UI finds them, also on sockets of other servers.
            $socket->data = ['_admin' => [
                'clientId' => substr($socket->conn->id, 0, 12),
                'transport' => $socket->conn->transport()->name(),
            ]] + $socket->data;
            $this->admin->emit('socket_connected', self::serialize($socket, $nsp->name), self::now());

            $socket->conn->on('upgrade', function (Transport $transport) use ($socket, $nsp): void {
                self::setTransport($socket, $transport->name());
                $this->admin->emit('socket_updated', [
                    'id' => $socket->id,
                    'nsp' => $nsp->name,
                    'transport' => $transport->name(),
                ]);
            });
            if ($nsp !== $this->admin) {
                $socket->onAny(function (string $event, mixed ...$args) use ($socket, $nsp): void {
                    if ((end($args) ?: null) instanceof Closure) {
                        array_pop($args);
                    }
                    $this->admin->emit('event_received', $nsp->name, $socket->id, [$event, ...$args], self::now());
                });
                $socket->onAnyOutgoing(function (string $event, mixed ...$args) use ($socket, $nsp): void {
                    $this->admin->emit('event_sent', $nsp->name, $socket->id, [$event, ...$args], self::now());
                });
            }
            $socket->on('disconnect', function (string $reason) use ($socket, $nsp): void {
                $this->admin->emit('socket_disconnected', $nsp->name, $socket->id, $reason, self::now());
            });
        });
        $adapter = $nsp->adapter();
        $adapter->on('join-room', function (string $room, string $id) use ($nsp): void {
            $this->admin->emit('room_joined', $nsp->name, $room, $id, self::now());
        });
        $adapter->on('leave-room', function (string $room, string $id) use ($nsp): void {
            // After the disconnection it may come with, as the reference's process.nextTick().
            $this->io->scheduler()->after(0, function () use ($nsp, $room, $id): void {
                $this->admin->emit('room_left', $nsp->name, $room, $id, self::now());
            });
        });
    }

    /**
     * Counts the Engine.IO sessions for the stats, as the reference's engine listeners.
     */
    private function onEngineConnection(EngineSocket $session): void
    {
        $this->events->push(self::millis(), 'rawConnection');
        if ($session->transport()->name() === 'polling') {
            ++$this->pollingClients;
            $counted = true;
            $uncount = function () use (&$counted): void {
                if ($counted) {
                    $counted = false;
                    --$this->pollingClients;
                }
            };
            $session->once('upgrade', $uncount);
            $session->once('close', $uncount);
        }
        $session->on('packetCreate', function (Packet $packet): void {
            if ($packet->data !== '') {
                $this->events->push(self::millis(), 'packetsOut');
                $this->events->push(self::millis(), 'bytesOut', null, strlen($packet->data));
            }
        });
        $session->on('packet', function (Packet $packet): void {
            if ($packet->data !== '') {
                $this->events->push(self::millis(), 'packetsIn');
                $this->events->push(self::millis(), 'bytesIn', null, strlen($packet->data));
            }
        });
        $session->on('close', function (CloseReason $reason): void {
            $this->events->push(self::millis(), 'rawDisconnection', $reason->value);
        });
    }

    /**
     * Sends this server's stats to the admins, every 2 seconds, as the reference does.
     */
    private function emitStats(): void
    {
        $hostname = (string) gethostname();
        $pid = (int) getmypid();
        $namespaces = [];
        foreach ($this->io->namespaces() as $nsp) {
            $namespaces[] = ['name' => $nsp->name, 'socketsCount' => count($nsp->sockets())];
        }
        $this->admin->emit('server_stats', [
            // Several workers share a host, so the pid tells them apart.
            'serverId' => $this->serverId ?? $hostname . '#' . $pid,
            'hostname' => $hostname,
            'pid' => $pid,
            'uptime' => microtime(true) - $this->startedAt,
            'clientsCount' => $this->io->engine->clientsCount(),
            'pollingClientsCount' => $this->pollingClients,
            'aggregatedEvents' => $this->events->takeAll(),
            'namespaces' => $namespaces,
        ]);
        $this->io->scheduler()->after(self::STATS_INTERVAL, function (): void {
            $this->emitStats();
        });
    }

    /**
     * A socket as the UI shows it, as the reference's serialize(): auth is left out.
     *
     * @return array<string, mixed>
     */
    private static function serialize(Socket|RemoteSocket $socket, string $nsp): array
    {
        $data = is_array($socket->data) ? $socket->data : [];
        $admin = is_array($data['_admin'] ?? null) ? $data['_admin'] : [];
        unset($data['_admin']);
        $handshake = $socket->handshake;
        $headers = is_array($handshake['headers'] ?? null) ? $handshake['headers'] : [];

        return [
            'id' => $socket->id,
            'clientId' => $admin['clientId'] ?? null,
            'transport' => $admin['transport'] ?? null,
            'nsp' => $nsp,
            'data' => $data === [] ? new stdClass() : $data,
            'handshake' => [
                'address' => $headers['cf-connecting-ip']
                    ?? $headers['x-forwarded-for']
                    ?? $handshake['address']
                    ?? null,
                'headers' => $headers === [] ? new stdClass() : $headers,
                'query' => ($handshake['query'] ?? []) === [] ? new stdClass() : $handshake['query'],
                'issued' => $handshake['issued'] ?? null,
                'secure' => $handshake['secure'] ?? null,
                'time' => $handshake['time'] ?? null,
                'url' => $handshake['url'] ?? null,
                'xdomain' => $handshake['xdomain'] ?? null,
            ],
            'rooms' => $socket->rooms,
        ];
    }

    /**
     * Keeps the transport the UI reads in the socket's data up to date, unless the application replaced the data.
     */
    private static function setTransport(Socket $socket, string $transport): void
    {
        $admin = $socket->data['_admin'] ?? null;
        if (is_array($admin)) {
            $admin['transport'] = $transport;
            $socket->data['_admin'] = $admin;
        }
    }

    /**
     * Now, as JavaScript serializes a Date.
     */
    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function millis(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
