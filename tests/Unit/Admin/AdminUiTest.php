<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Admin;

use Closure;
use InvalidArgumentException;
use Marcolin\SocketIO\Adapter\Adapter;
use Marcolin\SocketIO\Adapter\InMemoryAdapter;
use Marcolin\SocketIO\Admin\AdminUi;
use Marcolin\SocketIO\Admin\InMemoryStore;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeHttpExchange;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use Marcolin\SocketIO\Tests\Fake\SequentialSessionIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SequentialSocketIdGenerator;
use Marcolin\SocketIO\Tests\Fake\SocketIOClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminUiTest extends TestCase
{
    private FakeScheduler $scheduler;

    private SocketIO $io;

    private string $hash;

    protected function setUp(): void
    {
        $this->scheduler = new FakeScheduler();
        $this->io = new SocketIO(
            options: ['pingInterval' => 100_000, 'pingTimeout' => 100_000],
            scheduler: $this->scheduler,
            sessionIds: new SequentialSessionIdGenerator(),
            socketIds: new SequentialSocketIdGenerator(),
        );
        $this->hash = password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]);
    }

    // Options

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'no auth' => [[], 'must be given'];
        yield 'another auth type' => [['auth' => ['type' => 'token']], '"auth" must be false'];
        yield 'a plain password' => [
            ['auth' => ['type' => 'basic', 'username' => 'admin', 'password' => 'secret']],
            'bcrypt hash',
        ];
        yield 'no username' => [['auth' => ['type' => 'basic', 'password' => 'x']], 'username'];
        yield 'an unknown option' => [['auth' => false, 'namespace' => '/admin'], 'Unknown Admin UI option'];
        yield 'another mode' => [['auth' => false, 'mode' => 'test'], '"mode"'];
        yield 'a namespace without a slash' => [['auth' => false, 'namespaceName' => 'admin'], 'namespaceName'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidOptions')]
    public function testRefusesInvalidOptions(array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        AdminUi::instrument($this->io, $options);
    }

    // Authentication

    public function testWrongCredentialsAreRefused(): void
    {
        $this->instrument();
        $admin = new SocketIOClient($this->io);

        $admin->send('0/admin,' . json_encode(['username' => 'admin', 'password' => 'wrong']));

        self::assertSame(['4/admin,{"message":"invalid credentials"}'], $admin->received());
    }

    public function testValidCredentialsGetASessionTheUiCanReconnectWith(): void
    {
        $this->instrument();
        $admin = new SocketIOClient($this->io);
        $admin->send('0/admin,' . json_encode(['username' => 'admin', 'password' => 'secret']));

        $session = self::events($admin->received())['session'][0] ?? null;
        self::assertIsString($session);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $session);

        $again = new SocketIOClient($this->io);
        $again->send('0/admin,' . json_encode(['sessionId' => $session]));
        self::assertArrayHasKey('config', self::events($again->received()));
    }

    public function testASharedStoreKeepsTheSessions(): void
    {
        $store = new InMemoryStore();
        $store->saveSession('from-another-server');
        AdminUi::instrument($this->io, ['auth' => $this->basicAuth(), 'store' => $store]);
        $admin = new SocketIOClient($this->io);

        $admin->send('0/admin,' . json_encode(['sessionId' => 'from-another-server']));

        self::assertArrayHasKey('config', self::events($admin->received()));
    }

    public function testWithoutAuthAnyoneConnects(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);

        $events = self::events($this->connectAdmin()->received());

        self::assertArrayNotHasKey('session', $events);
        self::assertArrayHasKey('config', $events);
    }

    // What the UI gets on connecting

    public function testTheConfigListsEveryFeatureInDevelopment(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);

        $config = self::events($this->connectAdmin()->received())['config'][0] ?? null;

        self::assertSame(['supportedFeatures' => [
            'EMIT', 'JOIN', 'LEAVE', 'DISCONNECT', 'MJOIN', 'MLEAVE', 'MDISCONNECT', 'AGGREGATED_EVENTS', 'ALL_EVENTS',
        ]], $config);
    }

    public function testReadonlyAllowsNoAction(): void
    {
        AdminUi::instrument($this->io, ['auth' => false, 'readonly' => true]);
        [, $user] = $this->connectUser();
        $admin = $this->connectAdmin();

        $config = self::events($admin->received())['config'][0] ?? null;
        $admin->send('2/admin,' . json_encode(['join', '/', 'room']));

        self::assertSame(['supportedFeatures' => ['AGGREGATED_EVENTS', 'ALL_EVENTS']], $config);
        self::assertNotContains('room', $user->rooms);
    }

    public function testAllSocketsDescribesEverySocketWithoutItsAuth(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);
        [, $user] = $this->connectUser(['token' => 'secret']);
        $user->join('room');
        $user->data['user'] = 'ana';

        $all = self::events($this->connectAdmin()->received())['all_sockets'][0] ?? [];
        self::assertIsArray($all);
        $sockets = array_values(array_filter($all, static fn (mixed $socket): bool => is_array($socket)
            && ($socket['nsp'] ?? null) === '/'));

        self::assertCount(1, $sockets);
        $socket = $sockets[0];
        self::assertSame($user->id, $socket['id']);
        self::assertSame('sid1', $socket['clientId']);
        self::assertSame('websocket', $socket['transport']);
        self::assertSame(['user' => 'ana'], $socket['data']);
        self::assertEqualsCanonicalizing([$user->id, 'room'], $socket['rooms']);
        self::assertIsArray($socket['handshake']);
        self::assertArrayNotHasKey('auth', $socket['handshake']);
        self::assertSame('10.0.0.1', $socket['handshake']['address']);
    }

    public function testTheAddressComesFromTheProxyHeaders(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);
        $client = new SocketIOClient($this->io, ['X-Forwarded-For' => '203.0.113.7']);
        $client->send('0');

        $all = self::events($this->connectAdmin()->received())['all_sockets'][0] ?? [];
        self::assertIsArray($all);
        $first = $all[0] ?? null;
        self::assertIsArray($first);
        self::assertIsArray($first['handshake']);

        self::assertSame('203.0.113.7', $first['handshake']['address']);
    }

    public function testProductionModeSendsNoSocketDetails(): void
    {
        AdminUi::instrument($this->io, ['auth' => false, 'mode' => 'production']);
        $admin = $this->connectAdmin();
        $events = self::events($admin->received());

        $this->connectUser();

        self::assertSame(['supportedFeatures' => [
            'EMIT', 'JOIN', 'LEAVE', 'DISCONNECT', 'MJOIN', 'MLEAVE', 'MDISCONNECT', 'AGGREGATED_EVENTS',
        ]], $events['config'][0] ?? null);
        self::assertArrayNotHasKey('all_sockets', $events);
        self::assertArrayNotHasKey('socket_connected', self::events($admin->received()));
    }

    // Live events, in development

    public function testReportsWhatHappensToTheSockets(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);
        $admin = $this->connectAdmin();
        $admin->received();

        [$client, $user] = $this->connectUser();
        $user->join('room');
        $client->send('20["hello","world"]');
        $user->emit('welcome', 1);
        $user->leave('room');
        $this->scheduler->advance(0);
        $client->send('1');

        $events = self::events($admin->received());
        $connected = $events['socket_connected'][0] ?? null;
        self::assertIsArray($connected);
        self::assertSame($user->id, $connected['id']);
        // A Date, as JavaScript serializes it.
        $timestamp = $events['socket_connected'][1] ?? null;
        self::assertIsString($timestamp);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $timestamp);
        self::assertSame(['/', 'room', $user->id], array_slice($events['room_joined'] ?? [], 0, 3));
        // The acknowledgement is left out, as in the reference.
        self::assertSame(['/', $user->id, ['hello', 'world']], array_slice($events['event_received'] ?? [], 0, 3));
        self::assertSame(['/', $user->id, ['welcome', 1]], array_slice($events['event_sent'] ?? [], 0, 3));
        self::assertSame(['/', 'room', $user->id], array_slice($events['room_left'] ?? [], 0, 3));
        self::assertSame(
            ['/', $user->id, 'client namespace disconnect'],
            array_slice($events['socket_disconnected'] ?? [], 0, 3),
        );
    }

    public function testTheSocketKeepsTheAdminDataTheUiReads(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);

        [, $user] = $this->connectUser();

        self::assertSame(['clientId' => 'sid1', 'transport' => 'websocket'], $user->data['_admin'] ?? null);
    }

    public function testWatchesNamespacesCreatedLater(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);
        $admin = $this->connectAdmin();
        $admin->received();
        $this->io->of('/chat');

        $client = new SocketIOClient($this->io);
        $client->send('0/chat,');

        $connected = self::events($admin->received())['socket_connected'][0] ?? null;
        self::assertIsArray($connected);
        self::assertSame('/chat', $connected['nsp']);
    }

    public function testRoomEventsSurviveAnAdapterSetLater(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);
        $this->io->adapter(static fn (Nsp $nsp): Adapter => new InMemoryAdapter($nsp));
        $admin = $this->connectAdmin();
        $admin->received();

        [, $user] = $this->connectUser();
        $user->join('room');

        $joined = self::events($admin->received())['room_joined'] ?? [];
        self::assertSame(['/', 'room', $user->id], array_slice($joined, 0, 3));
    }

    // Stats

    public function testSendsTheServerStatsEveryTwoSeconds(): void
    {
        AdminUi::instrument($this->io, ['auth' => false, 'serverId' => 'web-1']);
        $admin = $this->connectAdmin();
        $admin->received();
        $this->connectUser();
        $this->io->engine->handleRequest(FakeHttpExchange::fromUrl('GET', '/socket.io/?EIO=4&transport=polling'));

        $this->scheduler->advance(1999);
        self::assertArrayNotHasKey('server_stats', self::events($admin->received()));
        $this->scheduler->advance(1);
        $stats = self::events($admin->received())['server_stats'][0] ?? null;

        self::assertIsArray($stats);
        self::assertSame('web-1', $stats['serverId']);
        self::assertSame(getmypid(), $stats['pid']);
        self::assertSame(3, $stats['clientsCount']);
        self::assertSame(1, $stats['pollingClientsCount']);
        self::assertSame(
            [['name' => '/', 'socketsCount' => 1], ['name' => '/admin', 'socketsCount' => 1]],
            $stats['namespaces'],
        );
        self::assertIsArray($stats['aggregatedEvents']);
        $types = array_column($stats['aggregatedEvents'], 'type');
        self::assertContains('rawConnection', $types);
        self::assertContains('packetsIn', $types);
        self::assertContains('bytesOut', $types);
    }

    public function testTheServerIdDefaultsToTheHostAndPid(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);
        $admin = $this->connectAdmin();
        $admin->received();

        $this->scheduler->advance(2000);
        $stats = self::events($admin->received())['server_stats'][0] ?? null;

        self::assertIsArray($stats);
        self::assertSame(gethostname() . '#' . getmypid(), $stats['serverId']);
    }

    // Actions

    public function testEmitsToANamespaceOrARoom(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);
        [$inRoom, $user] = $this->connectUser();
        $user->join('room');
        [$other] = $this->connectUser();
        $admin = $this->connectAdmin();

        $admin->send('2/admin,' . json_encode(['emit', '/', 'room', 'alert', 1]));
        $admin->send('2/admin,' . json_encode(['emit', '/', null, 'news', 2]));

        self::assertSame(['2["alert",1]', '2["news",2]'], $inRoom->received());
        self::assertSame(['2["news",2]'], $other->received());
    }

    public function testJoinsLeavesAndDisconnects(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);
        [$client, $user] = $this->connectUser();
        $admin = $this->connectAdmin();

        $admin->send('2/admin,' . json_encode(['join', '/', 'vip']));
        self::assertContains('vip', $user->rooms);
        $admin->send('2/admin,' . json_encode(['leave', '/', 'vip', $user->id]));
        self::assertNotContains('vip', $user->rooms);
        $admin->send('2/admin,' . json_encode(['_disconnect', '/', false, $user->id]));

        self::assertFalse($user->connected);
        self::assertContains('1', $client->received());
    }

    public function testActionsOnAnUnknownNamespaceDoNothing(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);
        $admin = $this->connectAdmin();

        $admin->send('2/admin,' . json_encode(['join', '/nowhere', 'room']));
        $admin->send('2/admin,' . json_encode(['emit', '/nowhere', null, 'news']));

        self::assertFalse($this->io->hasNamespace('/nowhere'));
    }

    public function testAReservedEventNameIsIgnored(): void
    {
        AdminUi::instrument($this->io, ['auth' => false]);
        [$client] = $this->connectUser();
        $admin = $this->connectAdmin();

        $admin->send('2/admin,' . json_encode(['emit', '/', null, 'disconnect']));

        self::assertSame([], $client->received());
        self::assertFalse($admin->isClosed());
    }

    private function instrument(): void
    {
        AdminUi::instrument($this->io, ['auth' => $this->basicAuth()]);
    }

    /**
     * @return array{type: string, username: string, password: string}
     */
    private function basicAuth(): array
    {
        return ['type' => 'basic', 'username' => 'admin', 'password' => $this->hash];
    }

    private function connectAdmin(): SocketIOClient
    {
        $admin = new SocketIOClient($this->io);
        $admin->send('0/admin,');

        return $admin;
    }

    /**
     * @param array<string, mixed> $auth
     * @return array{SocketIOClient, Socket}
     */
    private function connectUser(array $auth = []): array
    {
        $client = new SocketIOClient($this->io);
        $client->send($auth === [] ? '0' : '0' . json_encode($auth));
        $client->received();
        $sockets = $this->io->sockets->sockets();

        return [$client, $sockets[array_key_last($sockets)]];
    }

    /**
     * The events sent to the admin namespace, by name: the arguments of the last one of each.
     *
     * @param list<string|object> $messages
     * @return array<string, list<mixed>>
     */
    private static function events(array $messages): array
    {
        $events = [];
        foreach ($messages as $message) {
            if (! is_string($message) || ! str_starts_with($message, '2/admin,')) {
                continue;
            }
            $data = json_decode(substr($message, strlen('2/admin,')), true);
            if (is_array($data) && is_string($data[0] ?? null)) {
                $events[$data[0]] = array_values(array_slice($data, 1));
            }
        }

        return $events;
    }
}
