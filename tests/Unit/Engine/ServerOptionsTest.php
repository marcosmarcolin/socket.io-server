<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Engine;

use InvalidArgumentException;
use Marcolin\SocketIO\Engine\Cors;
use Marcolin\SocketIO\Engine\Request;
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
        self::assertSame('/socket.io/', $options->path);
        self::assertSame(['polling', 'websocket'], $options->transports);
        self::assertEquals(new Cors('*'), $options->cors);
    }

    public function testNormalizesThePathWithATrailingSlash(): void
    {
        self::assertSame('/engine.io/', (new ServerOptions(path: '/engine.io'))->path);
        self::assertSame('/engine.io/', (new ServerOptions(path: '/engine.io/'))->path);
        self::assertSame('/', (new ServerOptions(path: '/'))->path);
    }

    public function testRejectsARelativePath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerOptions(path: 'engine.io');
    }

    public function testBuildsFromTheArrayForm(): void
    {
        $options = ServerOptions::fromArray([
            'pingInterval' => 300,
            'pingTimeout' => 200,
            'maxPayload' => 1000,
            'upgradeTimeout' => 5000,
            'path' => '/engine.io',
            'cors' => ['origin' => ['https://a.test']],
        ]);

        self::assertSame(300, $options->pingInterval);
        self::assertSame(200, $options->pingTimeout);
        self::assertSame(1000, $options->maxPayload);
        self::assertSame(5000, $options->upgradeTimeout);
        self::assertSame('/engine.io/', $options->path);
        self::assertEquals(new Cors(['https://a.test']), $options->cors);
    }

    public function testArrayFormDefaults(): void
    {
        self::assertEquals(new ServerOptions(), ServerOptions::fromArray([]));
        self::assertEquals(new Cors('*'), ServerOptions::fromArray(['cors' => []])->cors);
        self::assertEquals(new Cors('*'), ServerOptions::fromArray(['cors' => ['origin' => '*']])->cors);
        self::assertNull(ServerOptions::fromArray(['cors' => ['origin' => null]])->cors);
    }

    public function testArrayFormTakesTheTransports(): void
    {
        self::assertSame(['websocket'], ServerOptions::fromArray(['transports' => ['websocket']])->transports);
        self::assertSame(['polling'], ServerOptions::fromArray(['transports' => ['polling']])->transports);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidTransports(): iterable
    {
        yield 'none' => [[]];
        yield 'unknown' => [['webtransport']];
        yield 'misspelled' => [['WebSocket']];
        yield 'repeated' => [['websocket', 'websocket']];
    }

    /**
     * @param list<string> $transports
     */
    #[DataProvider('invalidTransports')]
    public function testRejectsInvalidTransports(array $transports): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"transports" option');

        new ServerOptions(transports: $transports);
    }

    public function testArrayFormTakesCredentialsAndAClosure(): void
    {
        $sameHost = static fn (string $origin, Request $request): bool => true;

        $withList = ServerOptions::fromArray(['cors' => ['origin' => ['https://a.test'], 'credentials' => true]])->cors;
        $withClosure = ServerOptions::fromArray(['cors' => ['origin' => $sameHost, 'credentials' => true]])->cors;

        self::assertEquals(new Cors(['https://a.test'], true), $withList);
        self::assertEquals(new Cors($sameHost, true), $withClosure);
        self::assertFalse(ServerOptions::fromArray([])->cors?->credentials);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidArrays(): iterable
    {
        yield 'unknown key' => [['pingIntervall' => 1], 'Unknown Engine.IO option "pingIntervall"'];
        yield 'string int' => [['pingInterval' => '300'], '"pingInterval" option must be an integer'];
        yield 'path type' => [['path' => 1], '"path" option must be a string'];
        yield 'cors type' => [['cors' => '*'], '"cors" option must be an array'];
        yield 'transports not a list' => [['transports' => 'websocket'], '"transports" option must be a list'];
        yield 'transport not a string' => [['transports' => [1]], 'Each transport must be'];
        yield 'cors key' => [['cors' => ['methods' => ['GET']]], 'Unknown CORS option "methods"'];
        $starError = 'reject the CORS origin "*" with credentials';
        yield 'credentials not a bool' => [
            ['cors' => ['origin' => ['https://a.test'], 'credentials' => 'yes']],
            '"credentials" option must be a boolean',
        ];
        yield 'credentials with any origin' => [['cors' => ['credentials' => true]], $starError];
        yield 'credentials with star' => [['cors' => ['origin' => '*', 'credentials' => true]], $starError];
        yield 'origin string' => [['cors' => ['origin' => 'https://a.test']], 'CORS origin must be'];
        yield 'origin empty list' => [['cors' => ['origin' => []]], 'CORS origin must be'];
        yield 'origin map' => [['cors' => ['origin' => ['a' => 'https://a.test']]], 'CORS origin must be'];
        yield 'origin item' => [['cors' => ['origin' => ['https://a.test', 1]]], 'Each CORS origin'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidArrays')]
    public function testRejectsInvalidArrays(array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ServerOptions::fromArray($options);
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
