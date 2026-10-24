<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Unit\Adapter;

use ArrayObject;
use Marcolin\SocketIO\Adapter\InMemoryAdapter;
use Marcolin\SocketIO\Nsp;
use Marcolin\SocketIO\SocketIO;
use Marcolin\SocketIO\Tests\Fake\FakeScheduler;
use PHPUnit\Framework\TestCase;

final class InMemoryAdapterTest extends TestCase
{
    private InMemoryAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new InMemoryAdapter((new SocketIO(scheduler: new FakeScheduler()))->sockets);
    }

    public function testTracksTheRoomsOfEachSocket(): void
    {
        $this->adapter->addAll('s1', ['a', 'b']);
        $this->adapter->addAll('s2', ['b']);
        $this->adapter->addAll('s1', ['a']);

        self::assertSame(['a', 'b'], $this->adapter->socketRooms('s1'));
        self::assertSame(['b'], $this->adapter->socketRooms('s2'));
        self::assertSame([], $this->adapter->socketRooms('unknown'));
    }

    public function testLeavesRooms(): void
    {
        $this->adapter->addAll('s1', ['a', 'b']);

        $this->adapter->del('s1', 'a');
        $this->adapter->del('s1', 'never-joined');

        self::assertSame(['b'], $this->adapter->socketRooms('s1'));
    }

    public function testLeavesAllRooms(): void
    {
        $this->adapter->addAll('s1', ['a', 'b']);
        $this->adapter->addAll('s2', ['a']);

        $this->adapter->delAll('s1');
        $this->adapter->delAll('unknown');

        self::assertSame([], $this->adapter->socketRooms('s1'));
        self::assertSame(['a'], $this->adapter->socketRooms('s2'));
    }

    public function testKeepsNumericRoomNamesAsStrings(): void
    {
        $this->adapter->addAll('123', ['456', 'a']);

        self::assertSame(['456', 'a'], $this->adapter->socketRooms('123'));

        $this->adapter->delAll('123');
        self::assertSame([], $this->adapter->socketRooms('123'));
    }

    public function testReportsRoomsCreatedAndDeleted(): void
    {
        /** @var ArrayObject<int, string> $events */
        $events = new ArrayObject();
        $nsp = (new SocketIO(scheduler: new FakeScheduler()))->sockets;
        $adapter = new class ($nsp, $events) extends InMemoryAdapter {
            /**
             * @param ArrayObject<int, string> $events
             */
            public function __construct(Nsp $nsp, private readonly ArrayObject $events)
            {
                parent::__construct($nsp);
            }

            protected function roomCreated(string $room): void
            {
                $this->events->append('+' . $room);
            }

            protected function roomDeleted(string $room): void
            {
                $this->events->append('-' . $room);
            }
        };

        $adapter->addAll('s1', ['a', 'b']);
        $adapter->addAll('s2', ['a']);
        $adapter->del('s1', 'a');
        $adapter->del('s1', 'never-joined');
        $adapter->delAll('s1');
        $adapter->delAll('s2');

        self::assertSame(['+a', '+b', '-b', '-a'], $events->getArrayCopy());
    }
}
