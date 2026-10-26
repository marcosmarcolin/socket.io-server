<?php

declare(strict_types=1);

namespace Examples\Pong;

/**
 * One match of Pong, computed on the server, which has the last word: the clients only send which way they move
 * and draw the state they get.
 */
final class Game
{
    public const WIDTH = 640;
    public const HEIGHT = 400;
    public const PADDLE_WIDTH = 10;
    public const PADDLE_HEIGHT = 80;
    public const PADDLE_MARGIN = 20;
    public const BALL_SIZE = 10;
    public const POINTS_TO_WIN = 5;

    private const PADDLE_SPEED = 360;
    private const BALL_SPEED = 300;
    private const SPEED_UP = 1.06;

    /** @var array{left: float, right: float} the top of each paddle */
    private array $paddles;

    /** @var array{left: int, right: int} -1 up, 0 still, 1 down */
    private array $moves = ['left' => 0, 'right' => 0];

    /** @var array{left: int, right: int} */
    private array $score = ['left' => 0, 'right' => 0];

    private float $ballX;

    private float $ballY;

    private float $ballVx;

    private float $ballVy;

    /**
     * @param array{left: string, right: string} $players the socket id on each side
     * @param array{left: string, right: string} $names
     */
    public function __construct(
        public readonly string $room,
        public readonly array $players,
        public readonly array $names,
    ) {
        $middle = (self::HEIGHT - self::PADDLE_HEIGHT) / 2;
        $this->paddles = ['left' => $middle, 'right' => $middle];
        $this->serve(mt_rand(0, 1) === 0 ? -1 : 1);
    }

    /**
     * The field and the sizes the clients draw with.
     *
     * @return array<string, int>
     */
    public static function fieldSize(): array
    {
        return [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'paddleWidth' => self::PADDLE_WIDTH,
            'paddleHeight' => self::PADDLE_HEIGHT,
            'paddleMargin' => self::PADDLE_MARGIN,
            'ball' => self::BALL_SIZE,
            'pointsToWin' => self::POINTS_TO_WIN,
        ];
    }

    /**
     * The side of a player of this match, or null.
     *
     * @return 'left'|'right'|null
     */
    public function sideOf(string $socketId): ?string
    {
        return match ($socketId) {
            $this->players['left'] => 'left',
            $this->players['right'] => 'right',
            default => null,
        };
    }

    /**
     * @param 'left'|'right' $side
     */
    public function move(string $side, int $direction): void
    {
        $this->moves[$side] = max(-1, min(1, $direction));
    }

    /**
     * Moves everything by $seconds.
     *
     * @return 'left'|'right'|null the side that scored, if one did
     */
    public function step(float $seconds): ?string
    {
        foreach (['left', 'right'] as $side) {
            $top = $this->paddles[$side] + $this->moves[$side] * self::PADDLE_SPEED * $seconds;
            $this->paddles[$side] = max(0.0, min(self::HEIGHT - self::PADDLE_HEIGHT, $top));
        }

        $this->ballX += $this->ballVx * $seconds;
        $this->ballY += $this->ballVy * $seconds;
        // The top and bottom walls.
        if ($this->ballY < 0 || $this->ballY > self::HEIGHT - self::BALL_SIZE) {
            $this->ballY = max(0.0, min(self::HEIGHT - self::BALL_SIZE, $this->ballY));
            $this->ballVy = -$this->ballVy;
        }

        $leftFace = self::PADDLE_MARGIN + self::PADDLE_WIDTH;
        $rightFace = self::WIDTH - self::PADDLE_MARGIN - self::PADDLE_WIDTH - self::BALL_SIZE;
        // Only from the front, so a ball already past a paddle goes on to the goal.
        $atLeft = $this->ballX <= $leftFace && $this->ballX >= self::PADDLE_MARGIN - self::BALL_SIZE;
        $atRight = $this->ballX >= $rightFace && $this->ballX <= self::WIDTH - self::PADDLE_MARGIN;
        if ($this->ballVx < 0 && $atLeft) {
            $this->bounce('left', $leftFace);
        } elseif ($this->ballVx > 0 && $atRight) {
            $this->bounce('right', $rightFace);
        }

        $scorer = match (true) {
            $this->ballX < -self::BALL_SIZE => 'right',
            $this->ballX > self::WIDTH => 'left',
            default => null,
        };
        if ($scorer !== null) {
            ++$this->score[$scorer];
            // Served toward the side that lost the point.
            $this->serve($scorer === 'left' ? 1 : -1);
        }

        return $scorer;
    }

    /**
     * @return 'left'|'right'|null
     */
    public function winner(): ?string
    {
        foreach (['left', 'right'] as $side) {
            if ($this->score[$side] >= self::POINTS_TO_WIN) {
                return $side;
            }
        }

        return null;
    }

    /**
     * @return array{left: int, right: int}
     */
    public function score(): array
    {
        return $this->score;
    }

    /**
     * What the clients draw, rounded: it goes out 30 times a second.
     *
     * @return array{ball: array{x: int, y: int}, paddles: array{left: int, right: int}}
     */
    public function state(): array
    {
        return [
            'ball' => ['x' => (int) round($this->ballX), 'y' => (int) round($this->ballY)],
            'paddles' => [
                'left' => (int) round($this->paddles['left']),
                'right' => (int) round($this->paddles['right']),
            ],
        ];
    }

    /**
     * @param 'left'|'right' $side
     */
    private function bounce(string $side, float $face): void
    {
        $paddleTop = $this->paddles[$side];
        $ballCenter = $this->ballY + self::BALL_SIZE / 2;
        if ($ballCenter < $paddleTop || $ballCenter > $paddleTop + self::PADDLE_HEIGHT) {
            return;
        }
        $this->ballX = $face;
        // Where it hits the paddle sets the angle: the edges send it steeper.
        $offset = ($ballCenter - $paddleTop - self::PADDLE_HEIGHT / 2) / (self::PADDLE_HEIGHT / 2);
        $speed = hypot($this->ballVx, $this->ballVy) * self::SPEED_UP;
        $angle = $offset * M_PI / 4;
        $this->ballVx = ($side === 'left' ? 1 : -1) * $speed * cos($angle);
        $this->ballVy = $speed * sin($angle);
    }

    private function serve(int $direction): void
    {
        $this->ballX = (self::WIDTH - self::BALL_SIZE) / 2;
        $this->ballY = (self::HEIGHT - self::BALL_SIZE) / 2;
        $angle = (mt_rand(-30, 30) / 180) * M_PI;
        $this->ballVx = $direction * self::BALL_SPEED * cos($angle);
        $this->ballVy = self::BALL_SPEED * sin($angle);
    }
}
