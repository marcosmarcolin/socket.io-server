<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;
use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\InMemoryAdapter;
use Marcolin\SocketIO\Engine\CloseReason;
use Marcolin\SocketIO\Engine\RandomSessionIdGenerator;
use Marcolin\SocketIO\Engine\Request as EngineRequest;
use Marcolin\SocketIO\Engine\Scheduler;
use Marcolin\SocketIO\Engine\Server;
use Marcolin\SocketIO\Engine\ServerOptions;
use Marcolin\SocketIO\Engine\SessionIdGenerator;
use Marcolin\SocketIO\Engine\Socket as EngineSocket;
use Marcolin\SocketIO\Engine\Workerman\ServerBinding;
use Marcolin\SocketIO\Engine\Workerman\WorkermanScheduler;
use Marcolin\SocketIO\Event\Emitter;
use Marcolin\SocketIO\Parser\Encoder;
use WeakMap;
use Workerman\Worker;

/**
 * The Socket.IO server.
 *
 * Listeners belong to the main namespace, as $io->sockets->on() would, and so do the broadcast methods and
 * serverSideEmit(); only the server's own events, `workerStart` and `workerStop` (Worker), stay here. As in the
 * reference, the main namespace emits `new_namespace` (Nsp) when another namespace is created.
 *
 * @property-read BroadcastOperator $volatile broadcasts that skip sockets whose transport is busy
 * @property-read BroadcastOperator $local broadcasts and socket helpers on the main namespace, for this process only
 * @property-read Worker|null $worker the Workerman worker serving it, once attached
 */
final class SocketIO extends Emitter
{
    private const DEFAULT_CONNECT_TIMEOUT = 45_000;

    public readonly Server $engine;

    /** The main namespace, "/". */
    public readonly Nsp $sockets;

    /** Milliseconds a connection may wait before connecting to a namespace. */
    public readonly int $connectTimeout;

    /** The connectionStateRecovery option, or null when it's off. */
    public readonly ?ConnectionStateRecovery $connectionStateRecovery;

    private readonly Logger $logger;

    private readonly Encoder $encoder;

    /** @var array<string, Nsp> */
    private array $namespaces = [];

    /** @var Closure(Nsp): Adapter */
    private Closure $adapterFactory;

    /** @var WeakMap<EngineSocket, Client> */
    private WeakMap $clients;

    private ?Worker $attachedWorker = null;

    /**
     * @param int|null $port listens on this port, on all IPv4 interfaces; without it, call attach() with a worker
     * @param array<string, mixed> $options connectTimeout, connectionStateRecovery (true, or an array of
     *        maxDisconnectionDuration and skipMiddlewares), logger (a Closure(string $scope, string $message)), plus
     *        the Engine.IO options of ServerOptions::fromArray()
     */
    public function __construct(
        ?int $port = null,
        array $options = [],
        private readonly Scheduler $scheduler = new WorkermanScheduler(),
        ?SessionIdGenerator $sessionIds = null,
        private readonly SocketIdGenerator $socketIds = new RandomSocketIdGenerator(),
    ) {
        $connectTimeout = $options['connectTimeout'] ?? self::DEFAULT_CONNECT_TIMEOUT;
        if (! is_int($connectTimeout) || $connectTimeout <= 0) {
            throw new InvalidArgumentException('The "connectTimeout" option must be a positive integer');
        }
        unset($options['connectTimeout']);
        $this->connectTimeout = $connectTimeout;
        $recovery = $options['connectionStateRecovery'] ?? null;
        $this->connectionStateRecovery = ConnectionStateRecovery::fromOption($recovery);
        unset($options['connectionStateRecovery']);
        $logger = $options['logger'] ?? null;
        if ($logger !== null && ! $logger instanceof Closure) {
            throw new InvalidArgumentException('The "logger" option must be a Closure(string $scope, string $message)');
        }
        unset($options['logger']);
        $this->logger = new Logger($logger);

        $this->engine = new Server(
            ServerOptions::fromArray($options),
            $scheduler,
            $sessionIds ?? new RandomSessionIdGenerator(),
        );
        $this->encoder = new Encoder();
        $this->adapterFactory = static fn (Nsp $nsp): Adapter => new InMemoryAdapter($nsp);
        $this->clients = new WeakMap();
        $this->sockets = $this->of('/');

        $this->engine->on('connection', function (EngineSocket $connection): void {
            $this->clients[$connection] = new Client($this, $connection);
        });
        if ($this->logger->enabled()) {
            $this->logEngine();
        }

        if ($port !== null) {
            $this->attach(new Worker('tcp://0.0.0.0:' . $port));
        }
    }

    /**
     * Serves this server on a worker, which must use the tcp or ssl transport. The worker's protocol and connection
     * callbacks are taken over; onWorkerStart and onWorkerStop are kept, and run before the server's events.
     * Call it before Worker::runAll().
     */
    public function attach(Worker $worker): static
    {
        if ($this->attachedWorker !== null) {
            throw new LogicException('The server is already attached to a worker');
        }
        if ($worker->transport !== 'tcp' && $worker->transport !== 'ssl') {
            throw new InvalidArgumentException(sprintf('The worker must use tcp or ssl, not %s', $worker->transport));
        }
        $this->attachedWorker = $worker;
        (new ServerBinding($this->engine))->bind($worker);

        $onStart = $worker->onWorkerStart;
        $worker->onWorkerStart = function (Worker $worker) use ($onStart): void {
            if (is_callable($onStart)) {
                $onStart($worker);
            }
            $this->dispatch('workerStart', $worker);
        };
        $onStop = $worker->onWorkerStop;
        $worker->onWorkerStop = function (Worker $worker) use ($onStop): void {
            if (is_callable($onStop)) {
                $onStop($worker);
            }
            $this->dispatch('workerStop', $worker);
        };

        return $this;
    }

    /**
     * Shuts the server down, as the reference does: every connected socket leaves with "server shutting down",
     * the adapters close, every Engine.IO session closes at once, and the worker stops accepting connections in
     * this process.
     */
    public function close(): void
    {
        foreach ($this->namespaces as $namespace) {
            foreach ($namespace->sockets() as $socket) {
                $socket->onClose(DisconnectReason::ServerShuttingDown);
            }
            $namespace->adapter()->close();
        }
        $this->engine->close();
        $this->attachedWorker?->unlisten();
    }

    /**
     * The namespace with this name, created on first use. A missing leading slash is added.
     */
    public function of(string $name): Nsp
    {
        if (! str_starts_with($name, '/')) {
            $name = '/' . $name;
        }
        if (str_contains($name, ',')) {
            throw new InvalidArgumentException(sprintf('A namespace name can\'t contain a comma, got "%s"', $name));
        }

        $nsp = $this->namespaces[$name] ?? null;
        if ($nsp === null) {
            $nsp = $this->namespaces[$name] = new Nsp($this, $name);
            // On the main namespace, as the reference does, so $io->on('new_namespace') gets it.
            if ($name !== '/') {
                $this->sockets->announce($nsp);
            }
        }

        return $nsp;
    }

    /**
     * The server's own events, which stay here; every other listener belongs to the main namespace.
     */
    private static function isOwnEvent(string $event): bool
    {
        return $event === 'workerStart' || $event === 'workerStop';
    }

    public function on(string $event, Closure $listener): static
    {
        if (self::isOwnEvent($event)) {
            return parent::on($event, $listener);
        }
        $this->sockets->on($event, $listener);

        return $this;
    }

    public function once(string $event, Closure $listener): static
    {
        if (self::isOwnEvent($event)) {
            return parent::once($event, $listener);
        }
        $this->sockets->once($event, $listener);

        return $this;
    }

    public function off(string $event, ?Closure $listener = null): static
    {
        if (self::isOwnEvent($event)) {
            return parent::off($event, $listener);
        }
        $this->sockets->off($event, $listener);

        return $this;
    }

    public function listeners(string $event): array
    {
        return self::isOwnEvent($event) ? parent::listeners($event) : $this->sockets->listeners($event);
    }

    /**
     * The server's own events with listeners, then the main namespace's.
     */
    public function eventNames(): array
    {
        return [...parent::eventNames(), ...$this->sockets->eventNames()];
    }

    /**
     * Without an event, removes the server's own listeners and the main namespace's.
     */
    public function removeAllListeners(?string $event = null): static
    {
        if ($event === null) {
            $this->sockets->removeAllListeners();

            return parent::removeAllListeners();
        }

        return $this->off($event);
    }

    /**
     * @internal
     */
    public function logger(): Logger
    {
        return $this->logger;
    }

    /**
     * @param string|list<string> $rooms
     */
    public function to(string|array $rooms): BroadcastOperator
    {
        return $this->sockets->to($rooms);
    }

    /**
     * @param string|list<string> $rooms
     */
    public function in(string|array $rooms): BroadcastOperator
    {
        return $this->sockets->in($rooms);
    }

    /**
     * @param string|list<string> $rooms
     */
    public function except(string|array $rooms): BroadcastOperator
    {
        return $this->sockets->except($rooms);
    }

    /**
     * With false, the broadcast isn't compressed; see BroadcastOperator::compress().
     */
    public function compress(bool $compress): BroadcastOperator
    {
        return $this->sockets->compress($compress);
    }

    /**
     * Lets emit() take an acknowledgement callback; see BroadcastOperator::timeout().
     */
    public function timeout(int $milliseconds): BroadcastOperator
    {
        return $this->sockets->timeout($milliseconds);
    }

    /**
     * Sends an event to every socket of the main namespace, on every server the adapter reaches.
     */
    public function emit(string $event, mixed ...$args): static
    {
        $this->sockets->emit($event, ...$args);

        return $this;
    }

    /**
     * Sends an event to the main namespace on the other servers; see Nsp::serverSideEmit().
     */
    public function serverSideEmit(string $event, mixed ...$args): void
    {
        $this->sockets->serverSideEmit($event, ...$args);
    }

    /**
     * The sockets of the main namespace; see BroadcastOperator::fetchSockets() for the callback, which reaches
     * every server.
     *
     * @param (Closure(?\Throwable, list<Socket|RemoteSocket>): void)|null $callback
     * @return ($callback is null ? list<Socket> : null)
     */
    public function fetchSockets(?Closure $callback = null): ?array
    {
        return $this->sockets->fetchSockets($callback);
    }

    /**
     * Makes every socket of the main namespace join the rooms; use to() first to pick some.
     *
     * @param string|list<string> $rooms
     */
    public function socketsJoin(string|array $rooms): void
    {
        $this->sockets->socketsJoin($rooms);
    }

    /**
     * @param string|list<string> $rooms
     */
    public function socketsLeave(string|array $rooms): void
    {
        $this->sockets->socketsLeave($rooms);
    }

    /**
     * Disconnects every socket of the main namespace; with $close, closes their connections.
     */
    public function disconnectSockets(bool $close = false): void
    {
        $this->sockets->disconnectSockets($close);
    }

    /**
     * Sends a `message` event to every socket of the main namespace.
     */
    public function send(mixed ...$args): static
    {
        return $this->emit('message', ...$args);
    }

    public function __get(string $name): BroadcastOperator|Worker|null
    {
        return match ($name) {
            'volatile' => $this->sockets->volatile,
            'local' => $this->sockets->local,
            'worker' => $this->attachedWorker,
            default => throw new LogicException(sprintf('Undefined property %s::$%s', self::class, $name)),
        };
    }

    /**
     * Adds a middleware to the main namespace.
     *
     * @param Closure(Socket, Closure(?\Throwable=): void): void $middleware
     */
    public function use(Closure $middleware): static
    {
        $this->sockets->use($middleware);

        return $this;
    }

    /**
     * Sets how each namespace creates its adapter; existing namespaces get a new one.
     *
     * @param Closure(Nsp): Adapter $factory
     */
    public function adapter(Closure $factory): static
    {
        $this->adapterFactory = $factory;
        foreach ($this->namespaces as $namespace) {
            $namespace->initAdapter();
        }

        return $this;
    }

    /**
     * @internal
     */
    public function hasNamespace(string $name): bool
    {
        return isset($this->namespaces[$name]);
    }

    /**
     * @internal
     */
    public function createAdapter(Nsp $namespace): Adapter
    {
        return ($this->adapterFactory)($namespace);
    }

    /**
     * @internal
     */
    public function scheduler(): Scheduler
    {
        return $this->scheduler;
    }

    /**
     * @internal
     */
    public function encoder(): Encoder
    {
        return $this->encoder;
    }

    /**
     * @internal
     */
    public function newSocketId(): string
    {
        return $this->socketIds->generate();
    }

    /**
     * A private session id for connection state recovery: secret, so 15 random bytes in base64url, as the
     * reference's base64id.
     *
     * @internal
     */
    public function newPrivateSessionId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
    }

    /**
     * Logs the Engine.IO sessions from their events: opened, refused, upgraded and closed.
     */
    private function logEngine(): void
    {
        $this->engine->on('connection_error', function (EngineRequest $request, int $code, string $message): void {
            $this->logger->debug('engine', sprintf('refused %s: code %d, %s', $request->uri, $code, $message));
        });
        $this->engine->on('connection', function (EngineSocket $connection): void {
            $this->logger->debug('engine', sprintf(
                'session %s opened over %s from %s',
                $connection->id,
                $connection->transport()->name(),
                $connection->request->remoteAddress,
            ));
            $connection->on('upgrade', function () use ($connection): void {
                $this->logger->debug('engine:socket', sprintf(
                    'session %s upgraded to %s',
                    $connection->id,
                    $connection->transport()->name(),
                ));
            });
            $connection->on('close', function (CloseReason $reason, ?\Throwable $error) use ($connection): void {
                $this->logger->debug('engine:socket', sprintf(
                    'session %s closed: %s%s',
                    $connection->id,
                    $reason->value,
                    $error === null ? '' : ' (' . $error->getMessage() . ')',
                ));
            });
        });
    }
}
