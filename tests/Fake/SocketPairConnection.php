<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Tests\Fake;

use RuntimeException;
use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Worker;

/**
 * A real Workerman TcpConnection over a local socket pair, driven by hand: no event loop runs.
 *
 * write() feeds bytes from the client side and lets the connection read them, read() collects what it sent.
 */
final class SocketPairConnection
{
    public readonly TcpConnection $connection;

    /** @var resource */
    private $serverSocket;

    /** @var resource */
    private $clientSocket;

    public function __construct(Worker $worker)
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('Could not create a socket pair');
        }
        [$this->serverSocket, $this->clientSocket] = $pair;
        stream_set_blocking($this->clientSocket, false);

        // The same wiring as Worker::acceptTcpConnection().
        $this->connection = new TcpConnection(new Select(), $this->serverSocket, '127.0.0.1:50000');
        $this->connection->worker = $worker;
        $protocol = $worker->protocol;
        $this->connection->protocol = $protocol !== null && class_exists($protocol) ? $protocol : null;
        $this->connection->onMessage = $worker->onMessage;
        $this->connection->onClose = $worker->onClose;
        if ($worker->onConnect !== null) {
            ($worker->onConnect)($this->connection);
        }
    }

    public function write(string $bytes): void
    {
        fwrite($this->clientSocket, $bytes);
        $this->connection->baseRead($this->serverSocket);
    }

    /**
     * Everything the connection sent since the last read.
     */
    public function read(): string
    {
        $data = '';
        while (is_string($chunk = fread($this->clientSocket, 65536)) && $chunk !== '') {
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * The client closes its side; the connection notices on its next read.
     */
    public function closeClient(): void
    {
        fclose($this->clientSocket);
        $this->connection->baseRead($this->serverSocket);
    }

    public function isClosed(): bool
    {
        return $this->connection->getStatus() === TcpConnection::STATUS_CLOSED;
    }
}
