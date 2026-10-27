<?php

declare(strict_types=1);

namespace Examples\Arena;

/**
 * One ship in the arena: a player's, or a bot's. The arena moves it; the player only says how to steer.
 */
final class Ship
{
    public float $x = 0;
    public float $y = 0;
    public float $vx = 0;
    public float $vy = 0;
    public float $angle = 0;

    /** -1 turns left, 1 right, 0 holds the course. */
    public int $turn = 0;
    public bool $thrust = false;
    public bool $fire = false;

    public bool $alive = false;
    /** Seconds until it spawns again, while dead. */
    public float $respawnIn = 0;
    /** Seconds it can't be hit, after spawning. */
    public float $shieldFor = 0;
    /** Seconds until it can fire again. */
    public float $reload = 0;
    /** When its player's connection dropped, in seconds of arena time; null while connected. */
    public ?float $goneSince = null;

    public int $kills = 0;
    public int $deaths = 0;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $color,
        public readonly bool $bot = false,
    ) {
    }
}
