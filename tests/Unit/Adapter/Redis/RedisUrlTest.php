<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter\Redis;

use InvalidArgumentException;
use Marcolin\SocketIO\Adapter\Redis\RedisUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisUrlTest extends TestCase
{
    public function testTheDefaultIsTheLocalServer(): void
    {
        $url = RedisUrl::parse(RedisUrl::DEFAULT);

        self::assertSame(['127.0.0.1', 6379, null], [$url->host, $url->port, $url->auth()]);
    }

    public function testThePortDefaultsTo6379(): void
    {
        $url = RedisUrl::parse('redis://cache.internal');

        self::assertSame(['cache.internal', 6379], [$url->host, $url->port]);
    }

    public function testReadsTheHostAndPort(): void
    {
        $url = RedisUrl::parse('redis://10.0.0.5:6380');

        self::assertSame(['10.0.0.5', 6380], [$url->host, $url->port]);
    }

    public function testKeepsTheBracketsOfAnIpv6Host(): void
    {
        $url = RedisUrl::parse('redis://[::1]:6390');

        self::assertSame(['[::1]', 6390], [$url->host, $url->port]);
    }

    public function testAPasswordAloneLogsInWithIt(): void
    {
        self::assertSame(['AUTH', 's3cret'], RedisUrl::parse('redis://:s3cret@localhost')->auth());
    }

    public function testAUserAndPasswordLogInWithBoth(): void
    {
        $url = RedisUrl::parse('redis://app:p%40ss%3Aw%2Frd@localhost');

        self::assertSame(['AUTH', 'app', 'p@ss:w/rd'], $url->auth());
    }

    public function testIgnoresTheDatabaseNumber(): void
    {
        $url = RedisUrl::parse('redis://localhost:6379/2');

        self::assertSame(['localhost', 6379], [$url->host, $url->port]);
    }

    public function testAPlainUrlHasNoSslContext(): void
    {
        $url = RedisUrl::parse('redis://localhost');

        self::assertFalse($url->tls);
        self::assertNull($url->sslContext());
    }

    public function testRedissUsesTlsVerifyingTheHost(): void
    {
        $url = RedisUrl::parse('rediss://:s3cret@cache.internal:6380');

        self::assertTrue($url->tls);
        self::assertSame(['cache.internal', 6380, ['AUTH', 's3cret']], [$url->host, $url->port, $url->auth()]);
        self::assertSame(['peer_name' => 'cache.internal'], $url->sslContext());
    }

    public function testThePeerNameOfAnIpv6HostHasNoBrackets(): void
    {
        self::assertSame(['peer_name' => '::1'], RedisUrl::parse('rediss://[::1]')->sslContext());
    }

    public function testTlsOptionsGoToTheSslContext(): void
    {
        $url = RedisUrl::parse('rediss://cache.internal')->withTlsOptions(['cafile' => '/certs/ca.pem']);

        self::assertSame(['cafile' => '/certs/ca.pem', 'peer_name' => 'cache.internal'], $url->sslContext());
    }

    public function testTlsOptionsMayReplaceThePeerName(): void
    {
        $url = RedisUrl::parse('rediss://10.0.0.5')->withTlsOptions(['peer_name' => 'redis.internal']);

        self::assertSame(['peer_name' => 'redis.internal'], $url->sslContext());
    }

    public function testTlsOptionsNeedRediss(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('TLS options need a rediss:// URL'));

        RedisUrl::parse('redis://localhost')->withTlsOptions(['cafile' => '/certs/ca.pem']);
    }

    public function testNoTlsOptionsKeepAPlainUrl(): void
    {
        self::assertNull(RedisUrl::parse('redis://localhost')->withTlsOptions([])->sslContext());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'no scheme' => ['127.0.0.1:6379'];
        yield 'another scheme' => ['tcp://127.0.0.1:6379'];
        yield 'TLS without a host' => ['rediss://'];
        yield 'no host' => ['redis://'];
        yield 'a path that is not a database' => ['redis://localhost/cache'];
        yield 'a query' => ['redis://localhost?timeout=1'];
        yield 'a user without a password' => ['redis://app@localhost'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidUrls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        RedisUrl::parse($url);
    }

    public function testTheErrorDoesNotRevealThePassword(): void
    {
        try {
            RedisUrl::parse('redis://:s3cret@localhost/cache');
            self::fail('The URL was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('s3cret', $error->getMessage());
        }
    }
}
