<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine;

use InvalidArgumentException;
use Marcolin\SocketIO\Engine\ServerOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerOptionsTest extends TestCase
{
    public function testDefaultsMatchTheReferenceImplementation(): void
    {
        $options = new ServerOptions();

        self::assertSame(25_000, $options->pingInterval);
        self::assertSame(20_000, $options->pingTimeout);
        self::assertSame(1_000_000, $options->maxPayload);
        self::assertSame(10_000, $options->upgradeTimeout);
    }

    /**
     * @return iterable<string, array{array<string, int>}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'pingInterval' => [['pingInterval' => 0]];
        yield 'pingTimeout' => [['pingTimeout' => -1]];
        yield 'maxPayload' => [['maxPayload' => 0]];
        yield 'upgradeTimeout' => [['upgradeTimeout' => 0]];
    }

    /**
     * @param array<string, int> $options
     */
    #[DataProvider('invalidOptions')]
    public function testRejectsNonPositiveValues(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('"%s"', array_key_first($options)));

        new ServerOptions(...$options);
    }
}
