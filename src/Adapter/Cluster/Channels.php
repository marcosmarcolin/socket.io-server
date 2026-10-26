<?php

declare(strict_types=1);

namespace Marcolin\SocketIO\Adapter\Cluster;

/**
 * The channel names of one namespace, shared by PubSubAdapter and the Emitter so both always agree:
 * - "<key>#<nsp>#", which every server of the namespace subscribes to;
 * - "<key>#<nsp>#<room>#", which a server subscribes to while one of its sockets is in the room;
 * - "<key>-response#<nsp>#<uid>#", where a server gets the responses to its requests.
 *
 * @internal
 */
final class Channels
{
    public readonly string $namespace;

    private readonly string $responsePrefix;

    public function __construct(string $key, string $nsp)
    {
        $this->namespace = $key . '#' . $nsp . '#';
        $this->responsePrefix = $key . '-response#' . $nsp . '#';
    }

    public function room(string $room): string
    {
        return $this->namespace . $room . '#';
    }

    public function response(string $uid): string
    {
        return $this->responsePrefix . $uid . '#';
    }

    /**
     * The one channel a message about these rooms goes to.
     *
     * A single room has its own channel, which only the servers with members of it subscribe to. Anything else
     * goes once to the namespace channel, and each server filters by the rooms itself: publishing to each room's
     * channel would reach a server twice when it has members in two of the rooms.
     *
     * @param list<string> $rooms
     */
    public function forRooms(array $rooms): string
    {
        if (count($rooms) === 1 && ! self::isSocketRoom($rooms[0])) {
            return $this->room($rooms[0]);
        }

        return $this->namespace;
    }

    /**
     * A socket's own room, which gets no channel: socket ids are 24 lowercase hex characters.
     */
    public static function isSocketRoom(string $room): bool
    {
        return preg_match('/^[0-9a-f]{24}$/', $room) === 1;
    }
}
