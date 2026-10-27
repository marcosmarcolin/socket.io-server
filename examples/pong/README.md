# Pong

Multiplayer Pong: players wait in a queue, play in pairs, and the server runs the game.

## Run it

```sh
php examples/pong/start.php start
```

Open http://localhost:3001 in two tabs, or on two machines on the network, and press *Play* in both. Socket.IO listens on port 3000.

## Try

- Move with the arrow keys or W/S; the first to 5 points wins.
- Close one tab mid-match: the other player wins.
- Open a third tab and press *Play*: it waits for the next player.

## How it works

- [`Lobby.php`](Lobby.php) keeps the queue and the matches. Two waiting players get a room of their own, `start` with their side, and a [`Game`](Game.php).
- The client only sends `move` (-1, 0 or 1) when a key goes down or up. The server is the authority: it moves the paddles and the ball.
- A Workerman timer, started in `workerStart`, runs every match 30 times a second and sends its `state` with `$io->to($room)->volatile->emit()`: a slow player skips frames instead of lagging behind. `score` and `game over` aren't volatile, so they always arrive.
- One process, so the lobby is a plain object in memory.

## Test

```sh
tests/Integration/run.sh example pong
```

[`pong.test.js`](../../tests/Integration/client/examples/pong.test.js)
