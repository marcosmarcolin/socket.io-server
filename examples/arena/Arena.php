<?php

declare(strict_types=1);

namespace Examples\Arena;

/**
 * A space arena computed on the server, which has the last word: players send how they steer, and get back what
 * happened. Ships wrap around the edges, bullets fly for a moment, a hit destroys a ship, and it spawns again with
 * a shield. Bots fill the arena while few people play.
 */
final class Arena
{
    public const WIDTH = 1200;
    public const HEIGHT = 800;
    public const SHIP_RADIUS = 14;

    private const TURN_SPEED = 4.0;
    private const THRUST = 380.0;
    private const MAX_SPEED = 360.0;
    // The share of its speed a ship keeps after a second without thrust.
    private const DRAG = 0.55;
    private const BULLET_SPEED = 620.0;
    private const BULLET_LIFE = 1.1;
    private const RELOAD = 0.28;
    private const RESPAWN = 2.0;
    private const SHIELD = 1.5;
    // How long a ship whose player dropped waits for them to come back.
    private const GRACE = 10.0;
    // Bots keep the arena at this many ships while few people play.
    private const MIN_SHIPS = 4;
    private const COLORS = ['#ff6b6b', '#4dabf7', '#51cf66', '#fcc419', '#cc5de8', '#ff922b', '#20c997', '#f06595'];

    /** @var array<string, Ship> by id */
    private array $ships = [];

    /** @var list<array{x: float, y: float, vx: float, vy: float, life: float, owner: string}> */
    private array $bullets = [];

    private float $time = 0;

    private int $nextBot = 1;

    private int $nextColor = 0;

    /**
     * A new player's ship, which spawns at once.
     *
     * @return array{id: string, world: array<string, int>}
     */
    public function join(string $id, string $name): array
    {
        $ship = new Ship($id, $name, self::COLORS[$this->nextColor++ % count(self::COLORS)]);
        $this->spawn($ship);
        $this->ships[$id] = $ship;

        return [
            'id' => $id,
            'world' => ['width' => self::WIDTH, 'height' => self::HEIGHT, 'radius' => self::SHIP_RADIUS],
        ];
    }

    public function has(string $id): bool
    {
        return isset($this->ships[$id]);
    }

    /**
     * How the player steers, until they say otherwise.
     */
    public function steer(string $id, int $turn, bool $thrust, bool $fire): void
    {
        $ship = $this->ships[$id] ?? null;
        if ($ship !== null) {
            $ship->turn = max(-1, min(1, $turn));
            $ship->thrust = $thrust;
            $ship->fire = $fire;
        }
    }

    /**
     * The player's connection dropped: the ship drifts, without controls, waiting for them for a few seconds.
     */
    public function drop(string $id): void
    {
        $ship = $this->ships[$id] ?? null;
        if ($ship !== null) {
            $ship->goneSince = $this->time;
            $this->steer($id, 0, false, false);
        }
    }

    /**
     * The player came back in time: the ship is theirs again.
     */
    public function back(string $id): bool
    {
        $ship = $this->ships[$id] ?? null;
        if ($ship === null) {
            return false;
        }
        $ship->goneSince = null;

        return true;
    }

    public function remove(string $id): void
    {
        unset($this->ships[$id]);
    }

    /**
     * Moves everything by $seconds.
     *
     * @return list<array{killer: string, victim: string}> the ships destroyed in this step, by name
     */
    public function step(float $seconds): array
    {
        $this->time += $seconds;
        $this->balanceBots();
        foreach ($this->ships as $id => $ship) {
            if ($ship->goneSince !== null && $this->time - $ship->goneSince > self::GRACE) {
                unset($this->ships[$id]);
                continue;
            }
            if ($ship->bot) {
                $this->pilot($ship);
            }
            $this->move($ship, $seconds);
        }
        $this->moveBullets($seconds);

        return $this->hits();
    }

    /**
     * What the players draw, rounded: it goes out 30 times a second.
     *
     * @return array{ships: list<array<string, mixed>>, bullets: list<array{int, int}>}
     */
    public function state(): array
    {
        $ships = [];
        foreach ($this->ships as $ship) {
            $ships[] = [
                'id' => $ship->id,
                'name' => $ship->name,
                'color' => $ship->color,
                'x' => (int) round($ship->x),
                'y' => (int) round($ship->y),
                'angle' => round($ship->angle, 2),
                'alive' => $ship->alive,
                'shield' => $ship->shieldFor > 0,
                'thrust' => $ship->thrust && $ship->alive,
                'away' => $ship->goneSince !== null,
            ];
        }
        $bullets = array_map(
            static fn (array $bullet): array => [(int) round($bullet['x']), (int) round($bullet['y'])],
            $this->bullets,
        );

        return ['ships' => $ships, 'bullets' => $bullets];
    }

    /**
     * The scoreboard, best first.
     *
     * @return list<array{name: string, color: string, kills: int, deaths: int, bot: bool}>
     */
    public function scores(): array
    {
        $scores = array_map(static fn (Ship $ship): array => [
            'name' => $ship->name,
            'color' => $ship->color,
            'kills' => $ship->kills,
            'deaths' => $ship->deaths,
            'bot' => $ship->bot,
        ], array_values($this->ships));
        // Most kills first, then fewest deaths.
        usort(
            $scores,
            static fn (array $a, array $b): int => [$b['kills'], $a['deaths']] <=> [$a['kills'], $b['deaths']],
        );

        return $scores;
    }

    private function move(Ship $ship, float $seconds): void
    {
        if (! $ship->alive) {
            $ship->respawnIn -= $seconds;
            if ($ship->respawnIn <= 0) {
                $this->spawn($ship);
            }

            return;
        }
        $ship->shieldFor = max(0, $ship->shieldFor - $seconds);
        $ship->reload = max(0, $ship->reload - $seconds);
        $ship->angle = fmod($ship->angle + $ship->turn * self::TURN_SPEED * $seconds, 2 * M_PI);
        if ($ship->thrust) {
            $ship->vx += cos($ship->angle) * self::THRUST * $seconds;
            $ship->vy += sin($ship->angle) * self::THRUST * $seconds;
        }
        $drag = self::DRAG ** $seconds;
        $ship->vx *= $drag;
        $ship->vy *= $drag;
        $speed = hypot($ship->vx, $ship->vy);
        if ($speed > self::MAX_SPEED) {
            $ship->vx *= self::MAX_SPEED / $speed;
            $ship->vy *= self::MAX_SPEED / $speed;
        }
        $ship->x = self::wrap($ship->x + $ship->vx * $seconds, self::WIDTH);
        $ship->y = self::wrap($ship->y + $ship->vy * $seconds, self::HEIGHT);

        if ($ship->fire && $ship->reload <= 0) {
            $ship->reload = self::RELOAD;
            $this->bullets[] = [
                'x' => $ship->x + cos($ship->angle) * self::SHIP_RADIUS,
                'y' => $ship->y + sin($ship->angle) * self::SHIP_RADIUS,
                'vx' => $ship->vx + cos($ship->angle) * self::BULLET_SPEED,
                'vy' => $ship->vy + sin($ship->angle) * self::BULLET_SPEED,
                'life' => self::BULLET_LIFE,
                'owner' => $ship->id,
            ];
        }
    }

    private function moveBullets(float $seconds): void
    {
        $bullets = [];
        foreach ($this->bullets as $bullet) {
            $bullet['life'] -= $seconds;
            if ($bullet['life'] <= 0) {
                continue;
            }
            $bullet['x'] = self::wrap($bullet['x'] + $bullet['vx'] * $seconds, self::WIDTH);
            $bullet['y'] = self::wrap($bullet['y'] + $bullet['vy'] * $seconds, self::HEIGHT);
            $bullets[] = $bullet;
        }
        $this->bullets = $bullets;
    }

    /**
     * Bullets that hit a ship destroy it, unless it is the shooter's or shielded.
     *
     * @return list<array{killer: string, victim: string}>
     */
    private function hits(): array
    {
        $kills = [];
        $bullets = [];
        foreach ($this->bullets as $bullet) {
            $target = null;
            foreach ($this->ships as $ship) {
                if (
                    $ship->alive
                    && $ship->shieldFor <= 0
                    && $ship->id !== $bullet['owner']
                    && hypot($ship->x - $bullet['x'], $ship->y - $bullet['y']) < self::SHIP_RADIUS
                ) {
                    $target = $ship;
                    break;
                }
            }
            if ($target === null) {
                $bullets[] = $bullet;
                continue;
            }
            $target->alive = false;
            $target->respawnIn = self::RESPAWN;
            ++$target->deaths;
            $killer = $this->ships[$bullet['owner']] ?? null;
            if ($killer !== null) {
                ++$killer->kills;
            }
            $kills[] = ['killer' => $killer !== null ? $killer->name : 'someone who left', 'victim' => $target->name];
        }
        $this->bullets = $bullets;

        return $kills;
    }

    private function spawn(Ship $ship): void
    {
        $ship->x = mt_rand(50, self::WIDTH - 50);
        $ship->y = mt_rand(50, self::HEIGHT - 50);
        $ship->vx = 0;
        $ship->vy = 0;
        $ship->angle = mt_rand(0, 628) / 100;
        $ship->alive = true;
        $ship->shieldFor = self::SHIELD;
    }

    /**
     * Adds bots while there are fewer ships than MIN_SHIPS, and takes them out as people join.
     */
    private function balanceBots(): void
    {
        $bots = array_filter($this->ships, static fn (Ship $ship): bool => $ship->bot);
        $people = count($this->ships) - count($bots);
        $wanted = $people === 0 ? 0 : max(0, self::MIN_SHIPS - $people);
        while (count($bots) < $wanted) {
            $bot = new Ship('bot:' . $this->nextBot, 'bot ' . $this->nextBot++, '#868e96', true);
            $this->spawn($bot);
            $this->ships[$bot->id] = $bot;
            $bots[$bot->id] = $bot;
        }
        foreach (array_slice(array_keys($bots), $wanted) as $id) {
            unset($this->ships[$id]);
        }
    }

    /**
     * A bot turns toward the nearest ship, closes in, and fires when it points at it.
     */
    private function pilot(Ship $bot): void
    {
        $nearest = null;
        $distance = PHP_FLOAT_MAX;
        foreach ($this->ships as $ship) {
            $d = hypot($ship->x - $bot->x, $ship->y - $bot->y);
            if ($ship !== $bot && $ship->alive && $d < $distance) {
                $nearest = $ship;
                $distance = $d;
            }
        }
        if ($nearest === null) {
            $bot->turn = 1;
            $bot->thrust = false;
            $bot->fire = false;

            return;
        }
        $aim = atan2($nearest->y - $bot->y, $nearest->x - $bot->x);
        $off = atan2(sin($aim - $bot->angle), cos($aim - $bot->angle));
        $bot->turn = $off > 0.1 ? 1 : ($off < -0.1 ? -1 : 0);
        $bot->thrust = $distance > 220;
        // Bots aim worse than people: they fire only roughly in line, and not always.
        $bot->fire = abs($off) < 0.25 && $distance < 450 && mt_rand(0, 2) === 0;
    }

    private static function wrap(float $value, int $size): float
    {
        return fmod(fmod($value, $size) + $size, $size);
    }
}
