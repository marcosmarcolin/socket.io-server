<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit;

use Marcolin\SocketIO\AckCollector;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\TimeoutException;
use PHPUnit\Framework\TestCase;

/**
 * The contract adapters rely on: answers may arrive before the count, as they would from other processes.
 */
final class AckCollectorTest extends TestCase
{
    private FakeScheduler $scheduler;

    /** @var list<array{?TimeoutException, list<mixed>}> */
    private array $calls = [];

    private int $timeouts = 0;

    protected function setUp(): void
    {
        $this->scheduler = new FakeScheduler();
    }

    public function testWaitsForTheCountBeforeCompleting(): void
    {
        $collector = $this->collector();

        $collector->answer('a');
        $before = $this->calls;
        $collector->expect(1);

        self::assertSame([], $before);
        self::assertSame([[null, ['a']]], $this->calls);
        self::assertSame(0, $this->scheduler->pending());
    }

    public function testNoClientCompletesAtOnce(): void
    {
        $this->collector()->expect(0);

        self::assertSame([[null, []]], $this->calls);
    }

    public function testTimesOutOnceAndIgnoresLaterAnswers(): void
    {
        $collector = $this->collector();
        $collector->expect(2);
        $collector->answer('a');

        $this->scheduler->advance(1000);
        $collector->answer('b');

        self::assertEquals([[new TimeoutException('operation has timed out'), ['a']]], $this->calls);
        self::assertSame(1, $this->timeouts);
    }

    private function collector(): AckCollector
    {
        return new AckCollector(
            function (?TimeoutException $error, array $responses): void {
                $this->calls[] = [$error, $responses];
            },
            $this->scheduler,
            1000,
            function (): void {
                ++$this->timeouts;
            },
        );
    }
}
