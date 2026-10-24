<?php

declare(strict_types=1);

namespace Marcolin\SocketIO;

use Closure;
use InvalidArgumentException;
use LogicException;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\InMemoryAdapter;
use Marcolin\SocketIO\Engine\RandomSessionIdGenerator;
use Marcolin\SocketIO\Engine\Scheduler;
use Marcolin\SocketIO\Engine\Server;
use Marcolin\SocketIO\Engine\ServerOptions;
use Marcolin\SocketIO\Engine\SessionIdGenerator;
use Marcolin\SocketIO\Engine\Socket as EngineSocket;
use Marcolin\SocketIO\Engine\Workerman\WorkermanScheduler;
use Marcolin\SocketIO\Event\Emitter;
use Marcolin\SocketIO\Parser\Encoder;
use WeakMap;

/**
 * The Socket.IO server.
 *
 * `connection` and `connect` listeners belong to the main namespace, as $io->sockets->on() would, and so do
 * the broadcast methods.
 *
 * @property-read BroadcastOperator $volatile broadcasts that skip sockets whose transport is busy
 */
final class SocketIO extends Emitter
{
    private const DEFAULT_CONNECT_TIMEOUT = 45_000;

    public readonly Server $engine;

    /** The main namespace, "/". */
    public readonly Nsp $sockets;

    /** Milliseconds a connection may wait before connecting to a namespace. */
    public readonly int $connectTimeout;

    private readonly Encoder $encoder;

    /** @var array<string, Nsp> */
    private array $namespaces = [];

    /** @var Closure(Nsp): Adapter */
    private Closure $adapterFactory;

    /** @var WeakMap<EngineSocket, Client> */
    private WeakMap $clients;

    /**
     * @param array<string, mixed> $options connectTimeout, plus the Engine.IO options of ServerOptions::fromArray()
     */
    public function __construct(
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

        return $this->namespaces[$name] ??= new Nsp($this, $name);
    }

    public function on(string $event, Closure $listener): static
    {
        if ($event === 'connection' || $event === 'connect') {
            $this->sockets->on($event, $listener);

            return $this;
        }

        return parent::on($event, $listener);
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
     * Kept for API compatibility: the server doesn't compress, so this has no effect.
     */
    public function compress(bool $compress): BroadcastOperator
    {
        return $this->sockets->compress($compress);
    }

    /**
     * Sends an event to every socket of the main namespace, in this process.
     */
    public function emit(string $event, mixed ...$args): static
    {
        $this->sockets->emit($event, ...$args);

        return $this;
    }

    /**
     * Sends a `message` event to every socket of the main namespace.
     */
    public function send(mixed ...$args): static
    {
        return $this->emit('message', ...$args);
    }

    public function __get(string $name): BroadcastOperator
    {
        if ($name !== 'volatile') {
            throw new LogicException(sprintf('Undefined property %s::$%s', self::class, $name));
        }

        return $this->sockets->volatile;
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
}
