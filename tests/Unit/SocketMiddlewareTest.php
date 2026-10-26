<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use Closure;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Middlewares for the events a socket receives, as the reference's socket.use().
 */
final class SocketMiddlewareTest extends TestCase
{
    private SocketIOClient $client;

    private Socket $socket;

    /** @var list<mixed> */
    private array $log = [];

    protected function setUp(): void
    {
        $io = new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: new FakeScheduler(),
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
        $this->client = new SocketIOClient($io);
        $this->client->send('0');
        $this->client->received();
        $sockets = $io->sockets->sockets();
        $this->socket = $sockets[array_key_first($sockets)];
        $this->socket->on('chat', function (mixed ...$args): void {
            $this->log[] = ['chat', ...$args];
        });
        $this->socket->on('error', function (Throwable $error): void {
            $this->log[] = ['error', $error->getMessage()];
        });
    }

    public function testMiddlewaresRunInOrderBeforeTheListeners(): void
    {
        $this->socket->use(function (array $event, Closure $next): void {
            $this->log[] = ['first', ...$event];
            $next();
        });
        $this->socket->use(function (array $event, Closure $next): void {
            $this->log[] = ['second', $event[0]];
            $next();
        });

        $this->client->send('2["chat","hi",1]');

        self::assertSame([['first', 'chat', 'hi', 1], ['second', 'chat'], ['chat', 'hi', 1]], $this->log);
    }

    public function testOnAnyListenersRunBeforeTheMiddlewares(): void
    {
        $this->socket->onAny(function (string $event): void {
            $this->log[] = ['any', $event];
        });
        $this->socket->use(function (array $event, Closure $next): void {
            $this->log[] = ['middleware', $event[0]];
            $next();
        });

        $this->client->send('2["chat"]');

        self::assertSame([['any', 'chat'], ['middleware', 'chat'], ['chat']], $this->log);
    }

    public function testAMiddlewareGetsTheAcknowledgementLast(): void
    {
        $this->socket->use(static function (array $event, Closure $next): void {
            $ack = end($event);
            if ($ack instanceof Closure) {
                $ack('from middleware');
            }
            $next(new RuntimeException('answered already'));
        });

        $this->client->send('25["chat","hi"]');

        self::assertSame(['35["from middleware"]'], $this->client->received());
    }

    public function testAnErrorStopsTheEventAndEmitsError(): void
    {
        $this->socket->use(static function (array $event, Closure $next): void {
            $next($event[0] === 'chat' ? new RuntimeException('unauthorized event') : null);
        });
        $this->socket->use(function (array $event, Closure $next): void {
            $this->log[] = ['second'];
            $next();
        });

        $this->client->send('2["chat","hi"]');

        self::assertSame([['error', 'unauthorized event']], $this->log);
        // The socket stays connected; the app decides, as in the reference.
        self::assertTrue($this->socket->connected);
    }

    public function testAnExceptionBeforeNextStopsTheEvent(): void
    {
        $this->socket->use(static function (): void {
            throw new RuntimeException('broken middleware');
        });

        $this->client->send('2["chat"]');

        self::assertSame([['error', 'broken middleware']], $this->log);
    }

    public function testAnExceptionFromAListenerAfterNextPropagates(): void
    {
        $this->socket->use(static function (array $event, Closure $next): void {
            $next();
        });
        $this->socket->on('boom', static function (): void {
            throw new RuntimeException('listener failed');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('listener failed');

        $this->client->send('2["boom"]');
    }

    public function testNextMayBeCalledLaterAndOnlyTheFirstCallCounts(): void
    {
        $pending = null;
        $this->socket->use(static function (array $event, Closure $next) use (&$pending): void {
            $pending = $next;
        });

        $this->client->send('2["chat","later"]');
        self::assertSame([], $this->log);
        self::assertInstanceOf(Closure::class, $pending);

        $pending();
        $pending();
        $pending(new RuntimeException('too late'));

        self::assertSame([['chat', 'later']], $this->log);
    }

    public function testAnEventLetThroughAfterTheSocketLeftIsDropped(): void
    {
        $pending = null;
        $this->socket->use(static function (array $event, Closure $next) use (&$pending): void {
            $pending = $next;
        });
        $this->client->send('2["chat"]');

        $this->socket->disconnect();
        self::assertInstanceOf(Closure::class, $pending);
        $pending();

        self::assertSame([], $this->log);
    }

    public function testAMiddlewareTakingTheEventByReferenceChangesIt(): void
    {
        $this->socket->use(static function (array &$event, Closure $next): void {
            $event[1] = is_string($event[1] ?? null) ? strtoupper($event[1]) : null;
            $event[] = 'added';
            $next();
        });
        $this->socket->use(function (array $event, Closure $next): void {
            $this->log[] = ['seen', ...$event];
            $next();
        });

        $this->client->send('2["chat","hi"]');

        self::assertSame([['seen', 'chat', 'HI', 'added'], ['chat', 'HI', 'added']], $this->log);
    }

    public function testMiddlewaresAddedWhileAnEventRunsApplyFromTheNextOne(): void
    {
        $this->socket->use(function (array $event, Closure $next): void {
            $this->socket->use(function (array $event, Closure $next): void {
                $this->log[] = ['late', $event[0]];
                $next();
            });
            $next();
        });

        $this->client->send('2["chat","1"]');
        self::assertSame([['chat', '1']], $this->log);
    }

    public function testWithoutMiddlewaresEventsGoStraightToTheListeners(): void
    {
        $this->client->send('2["chat","hi"]');

        self::assertSame([['chat', 'hi']], $this->log);
    }
}
