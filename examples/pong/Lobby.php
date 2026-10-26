<?php

declare(strict_types=1);

namespace Examples\Pong;

use Marcolin\SocketIO\Socket;
use Marcolin\SocketIO\SocketIO;

/**
 * The players waiting for an opponent and the matches in progress, in one process: one room per match.
 */
final class Lobby
{
    private ?Socket $waiting = null;

    /** @var array<string, Game> by room */
    private array $games = [];

    /** @var array<string, string> each player's room, by socket id */
    private array $playing = [];

    private int $nextMatch = 1;

    public function __construct(private readonly SocketIO $io)
    {
    }

    /**
     * Pairs the player with the one waiting, or makes them wait.
     */
    public function play(Socket $socket, string $name): void
    {
        if (isset($this->playing[$socket->id]) || $this->waiting === $socket) {
            return;
        }
        $socket->data['name'] = mb_substr(trim($name), 0, 20) ?: 'Player';
        $opponent = $this->waiting;
        if ($opponent === null || ! $opponent->connected) {
            $this->waiting = $socket;
            $socket->emit('waiting');

            return;
        }

        $this->waiting = null;
        $room = 'match:' . $this->nextMatch++;
        $names = ['left' => self::name($opponent), 'right' => self::name($socket)];
        $game = new Game($room, ['left' => $opponent->id, 'right' => $socket->id], $names);
        $this->games[$room] = $game;
        $this->playing[$opponent->id] = $room;
        $this->playing[$socket->id] = $room;
        $opponent->join($room);
        $socket->join($room);
        $opponent->emit('start', ['side' => 'left', 'names' => $names, 'field' => Game::fieldSize()]);
        $socket->emit('start', ['side' => 'right', 'names' => $names, 'field' => Game::fieldSize()]);
    }

    /**
     * Which way the player's paddle moves: -1 up, 0 still, 1 down.
     */
    public function move(Socket $socket, int $direction): void
    {
        $game = $this->gameOf($socket);
        $side = $game?->sideOf($socket->id);
        if ($game !== null && $side !== null) {
            $game->move($side, $direction);
        }
    }

    /**
     * A player left: out of the queue, and the match goes to the one who stayed.
     */
    public function leave(Socket $socket): void
    {
        if ($this->waiting === $socket) {
            $this->waiting = null;
        }
        $game = $this->gameOf($socket);
        if ($game !== null) {
            $this->end($game, $game->sideOf($socket->id) === 'left' ? 'right' : 'left', 'opponent left');
        }
    }

    /**
     * One frame of every match: the state goes out volatile, so a slow player skips frames instead of lagging
     * behind; the score and the end of a match don't.
     */
    public function tick(float $seconds): void
    {
        foreach ($this->games as $game) {
            if ($game->step($seconds) !== null) {
                $this->io->to($game->room)->emit('score', $game->score());
                $winner = $game->winner();
                if ($winner !== null) {
                    $this->end($game, $winner, 'won');
                    continue;
                }
            }
            $this->io->to($game->room)->volatile->emit('state', $game->state());
        }
    }

    private static function name(Socket $socket): string
    {
        $name = $socket->data['name'] ?? null;

        return is_string($name) ? $name : 'Player';
    }

    private function gameOf(Socket $socket): ?Game
    {
        return $this->games[$this->playing[$socket->id] ?? ''] ?? null;
    }

    /**
     * Both players leave the match's room and can play again.
     *
     * @param 'left'|'right' $winner
     */
    private function end(Game $game, string $winner, string $reason): void
    {
        $this->io->to($game->room)->emit('game over', [
            'winner' => $winner,
            'reason' => $reason,
            'score' => $game->score(),
        ]);
        $this->io->in($game->room)->socketsLeave($game->room);
        unset($this->games[$game->room]);
        unset($this->playing[$game->players['left']], $this->playing[$game->players['right']]);
    }
}
