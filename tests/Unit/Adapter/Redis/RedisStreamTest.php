<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter\Redis;

use Marcolin\SocketIO\Adapter\Redis\RedisPubSub;
use Marcolin\SocketIO\Adapter\Redis\RedisStream;
use Marcolin\SocketIO\Adapter\Redis\RedisStreamOptions;
use Marcolin\SocketIO\Adapter\Redis\RedisUrl;
use Marcolin\SocketIO\Tests\Fake\FakeDialer;
use Marcolin\SocketIO\Tests\Fake\FakeLink;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * RedisStream over fake links: the test plays Redis on the commands connection and on the reader.
 */
final class RedisStreamTest extends TestCase
{
    private FakeDialer $dialer;

    private FakeScheduler $scheduler;

    /** @var list<Throwable> */
    private array $errors = [];

    /** @var list<array{string, array<mixed>, string}> namespace, message and id of what listeners got */
    private array $read = [];

    protected function setUp(): void
    {
        $this->dialer = new FakeDialer();
        $this->scheduler = new FakeScheduler();
    }

    public function testAppendsWithXaddAndGetsTheEntryId(): void
    {
        $stream = $this->stream();
        $ids = [];

        $stream->append('/chat', ['uid' => 'a', 'type' => 3], static function (?string $id) use (&$ids): void {
            $ids[] = $id;
        });
        $this->commands()->receive("\$3\r\n1-0\r\n");

        self::assertSame([[
            'XADD', 'socket.io', 'MAXLEN', '~', '10000', '*',
            'nsp', '/chat', 'message', serialize(['uid' => 'a', 'type' => 3]),
        ]], $this->commands()->commands());
        self::assertSame(['1-0'], $ids);
    }

    public function testAFailedAppendGetsNoId(): void
    {
        $stream = $this->stream();
        $ids = [];
        $stream->append('/', [], static function (?string $id) use (&$ids): void {
            $ids[] = $id;
        });

        $this->commands()->receive("-OOM command not allowed\r\n");

        self::assertSame([null], $ids);
        self::assertSame(['Redis error: OOM command not allowed'], $this->messages());
    }

    public function testReadsOnceSomeoneListensAndTheReaderLoggedIn(): void
    {
        $stream = $this->stream(openReader: false);
        $stream->subscribe('/', $this->listener('/'));
        self::assertSame([], $this->reader()->commands());

        $this->reader()->open();

        self::assertSame([self::xread('$')], $this->reader()->commands());
    }

    public function testNobodyListeningReadsNothing(): void
    {
        $this->stream();

        self::assertSame([], $this->reader()->commands());
    }

    public function testDeliversEachEntryToItsNamespaceAndReadsOnFromTheLast(): void
    {
        $stream = $this->stream();
        $stream->subscribe('/', $this->listener('/'));
        $stream->subscribe('/chat', $this->listener('/chat'));
        $this->reader()->commands();

        $this->reader()->receive(self::resp([['socket.io', [
            self::entry('1-0', '/', ['n' => 1]),
            self::entry('2-0', '/other', ['n' => 2]),
            self::entry('3-0', '/chat', ['n' => 3]),
        ]]]));

        self::assertSame([['/', ['n' => 1], '1-0'], ['/chat', ['n' => 3], '3-0']], $this->read);
        self::assertSame([self::xread('3-0')], $this->reader()->commands());
    }

    public function testATimedOutReadReadsAgainFromTheSameEntry(): void
    {
        $stream = $this->stream();
        $stream->subscribe('/', $this->listener('/'));
        $this->reader()->receive(self::resp([['socket.io', [self::entry('7-1', '/', [])]]]));
        $this->reader()->commands();

        $this->reader()->receive("*-1\r\n");

        self::assertSame([self::xread('7-1')], $this->reader()->commands());
    }

    public function testAfterAnOutageReadsFromTheLastEntry(): void
    {
        $stream = $this->stream();
        $stream->subscribe('/', $this->listener('/'));
        $this->reader()->receive(self::resp([['socket.io', [self::entry('5-0', '/', [])]]]));

        $this->reader()->drop();
        $this->scheduler->advance(1000);
        $this->dialer->last()->open();

        self::assertSame([self::xread('5-0')], $this->dialer->last()->commands());
    }

    public function testAnErrorReplyReadsAgainAfterADelay(): void
    {
        $stream = $this->stream();
        $stream->subscribe('/', $this->listener('/'));
        $this->reader()->commands();

        $this->reader()->receive("-WRONGTYPE Operation against a key holding the wrong kind of value\r\n");
        self::assertSame([], $this->reader()->commands());
        $this->scheduler->advance(RedisStream::RETRY_DELAY);

        self::assertSame([self::xread('$')], $this->reader()->commands());
    }

    public function testAListenerExceptionIsReportedAndReadingGoesOn(): void
    {
        $stream = $this->stream();
        $stream->subscribe('/', static function (): void {
            throw new RuntimeException('listener failed');
        });
        $this->reader()->commands();

        $this->reader()->receive(self::resp([['socket.io', [self::entry('1-0', '/', [])]]]));

        self::assertSame(['listener failed'], $this->messages());
        self::assertSame([self::xread('1-0')], $this->reader()->commands());
    }

    public function testSkipsMalformedEntriesAndReportsAMalformedReply(): void
    {
        $stream = $this->stream();
        $stream->subscribe('/', $this->listener('/'));
        $this->reader()->commands();

        $this->reader()->receive(self::resp([['socket.io', [
            ['1-0', ['nsp', '/', 'message', 'not serialized']],
            ['2-0', ['message', serialize(['n' => 2])]],
            self::entry('3-0', '/', ['n' => 3]),
        ]]]));
        $this->reader()->receive(self::resp([['socket.io', ['not an entry']]]));

        self::assertSame([['/', ['n' => 3], '3-0']], $this->read);
        self::assertSame(['Malformed XREAD reply from Redis'], $this->messages());
    }

    public function testStopsReadingWhenNobodyListens(): void
    {
        $stream = $this->stream();
        $stream->subscribe('/', $this->listener('/'));
        $this->reader()->commands();

        $stream->unsubscribe('/');
        $this->reader()->receive("*-1\r\n");

        self::assertSame([], $this->reader()->commands());
    }

    public function testSavesASessionThatExpires(): void
    {
        $this->stream()->saveSession('pid1', '{"sid":"x"}', 120000);

        self::assertSame(
            [['SET', 'sio:session:pid1', '{"sid":"x"}', 'PX', '120000']],
            $this->commands()->commands(),
        );
    }

    public function testRestoresASessionWithTheNamespacesEntriesAfterTheOffset(): void
    {
        $stream = $this->stream(['readCount' => 2]);
        $result = null;
        $stream->restoreSession('/', 'pid1', '4-0', $this->into($result));

        self::assertSame([
            ['GETDEL', 'sio:session:pid1'],
            ['XRANGE', 'socket.io', '4-0', '+', 'COUNT', '1'],
        ], $this->commands()->commands());
        $this->commands()->receive("\$7\r\nsession\r\n" . self::resp([self::entry('4-0', '/', [])]));
        self::assertSame([['XRANGE', 'socket.io', '(4-0', '+', 'COUNT', '2']], $this->commands()->commands());

        // A full batch asks for the next one.
        $this->commands()->receive(self::resp([self::entry('5-0', '/', ['n' => 5]), self::entry('6-0', '/x', [])]));
        self::assertSame([['XRANGE', 'socket.io', '(6-0', '+', 'COUNT', '2']], $this->commands()->commands());
        $this->commands()->receive(self::resp([self::entry('7-0', '/', ['n' => 7])]));

        self::assertSame(['session', [[['n' => 5], '5-0'], [['n' => 7], '7-0']]], $result);
        self::assertSame([], $this->commands()->commands());
    }

    public function testAnOffsetTrimmedFromTheStreamRestoresNothing(): void
    {
        $result = $this->restore('4-0', "\$7\r\nsession\r\n" . self::resp([self::entry('9-0', '/', [])]));

        self::assertSame([null, []], $result);
    }

    public function testAnUnknownSessionRestoresNothing(): void
    {
        $result = $this->restore('4-0', "\$-1\r\n" . self::resp([self::entry('4-0', '/', [])]));

        self::assertSame([null, []], $result);
    }

    public function testAnOffsetThatIsNoEntryIdAsksNothing(): void
    {
        $stream = $this->stream();
        $result = null;

        $stream->restoreSession('/', 'pid1', 'abc.1', $this->into($result));

        self::assertSame([null, []], $result);
        self::assertSame([], $this->commands()->commands());
    }

    public function testALostConnectionRestoresNothing(): void
    {
        $result = $this->restore('4-0', null);

        self::assertSame([null, []], $result);
    }

    public function testTheOptionsNameTheStreamAndKeys(): void
    {
        $stream = $this->stream(['streamName' => 'chat', 'maxLen' => 50, 'sessionKeyPrefix' => 'chat:s:']);

        $stream->append('/', [], static function (): void {
        });
        $stream->saveSession('p', 's', 1);

        $commands = $this->commands()->commands();
        self::assertSame(['XADD', 'chat', 'MAXLEN', '~', '50'], array_slice($commands[0], 0, 5));
        self::assertSame('chat:s:p', $commands[1][1]);
    }

    /**
     * Restores with these replies to the first two commands; null drops the connection instead.
     *
     * @return array{?string, list<mixed>}|null
     */
    private function restore(string $offset, ?string $replies): ?array
    {
        $stream = $this->stream();
        $result = null;
        $stream->restoreSession('/', 'pid1', $offset, $this->into($result));
        if ($replies === null) {
            $this->commands()->drop();
        } else {
            $this->commands()->receive($replies);
        }

        return $result;
    }

    /**
     * A callback that stores what a restore got.
     *
     * @param array{?string, list<mixed>}|null $result
     * @return \Closure(?string, list<mixed>): void
     */
    private function into(?array &$result): \Closure
    {
        return static function (?string $session, array $missed) use (&$result): void {
            $result = [$session, $missed];
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function stream(array $options = [], bool $openReader = true): RedisStream
    {
        $url = RedisUrl::parse(RedisUrl::DEFAULT);
        $onError = function (Throwable $error): void {
            $this->errors[] = $error;
        };
        $commands = RedisPubSub::connection($url, $this->dialer, $this->scheduler, $onError);
        $commands->open();
        $this->commands()->open();
        $stream = new RedisStream(
            $commands,
            $url,
            $this->dialer,
            $this->scheduler,
            $onError,
            RedisStreamOptions::fromArray($options),
        );
        if ($openReader) {
            $this->reader()->open();
        }

        return $stream;
    }

    private function commands(): FakeLink
    {
        return $this->dialer->links[0];
    }

    private function reader(): FakeLink
    {
        return $this->dialer->links[1];
    }

    /**
     * @return \Closure(array<mixed>, string): void
     */
    private function listener(string $nsp): \Closure
    {
        return function (array $message, string $id) use ($nsp): void {
            $this->read[] = [$nsp, $message, $id];
        };
    }

    /**
     * @return list<string>
     */
    private static function xread(string $from): array
    {
        return ['XREAD', 'COUNT', '100', 'BLOCK', '5000', 'STREAMS', 'socket.io', $from];
    }

    /**
     * @param array<mixed> $message
     * @return array{string, list<string>}
     */
    private static function entry(string $id, string $nsp, array $message): array
    {
        return [$id, ['nsp', $nsp, 'message', serialize($message)]];
    }

    /**
     * A RESP2 reply: strings as bulk strings, lists as arrays.
     */
    private static function resp(mixed $value): string
    {
        if (is_array($value)) {
            return '*' . count($value) . "\r\n" . implode('', array_map(self::resp(...), $value));
        }
        $value = (string) (is_scalar($value) ? $value : '');

        return '$' . strlen($value) . "\r\n" . $value . "\r\n";
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        return array_map(static fn (Throwable $error): string => $error->getMessage(), $this->errors);
    }
}
