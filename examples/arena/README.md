# Arena

A multiplayer space arena: everyone flies in one arena, shoots the others and climbs the scoreboard. Bots join while few people play. Works with a keyboard, and with touch buttons on phones.

## Run it

```sh
php examples/arena/start.php start
```

Open http://localhost:3001 in a few tabs, or on phones on the same network (http://<your machine's address>:3001). Socket.IO listens on port 3000.

## Try

- Arrows or WASD steer and thrust, space fires. A new ship has a shield for a moment.
- Play alone against the bots, then open more tabs: each person who joins takes a bot's place.
- Turn the network off for a few seconds (the developer tools, *Network* → *Offline*) and back on: your ship drifted, faded, and is yours again. Away longer than ten seconds, it's gone.

## How it works

| Client sends | Server sends |
|---|---|
| `join` (name, ack): the ack gets the ship's id and the arena's size | `state` 30 times a second, `volatile`: ships and bullets |
| `steer` (turn, thrust, fire), only when a key changes | `kill` (killer, victim) and `scores` when they change, not volatile |

- [`Arena.php`](Arena.php) is the game, with no Socket.IO in it: movement with drag and wrap-around edges, bullets, hits, respawn with a shield, and the bots' pilot. [`Ship.php`](Ship.php) is one ship.
- The server is the authority: a Workerman timer in `workerStart` steps the arena and broadcasts to the `arena` room. Clients send intent, never positions, so nobody can teleport.
- `volatile` drops a frame for a player whose connection is busy, instead of queueing old frames; the next one replaces it anyway.
- `connectionStateRecovery` with ten seconds: on a drop the `disconnect` reason is recoverable, so the arena keeps the ship (`drop()`); a client back in time gets the same socket id, `$socket->recovered` is true, and `back()` gives the ship back. Leaving on purpose (`client namespace disconnect`) removes it at once.

## Test

```sh
tests/Integration/run.sh example arena
```

[`arena.test.js`](../../tests/Integration/client/examples/arena.test.js) joins, meets the bots, thrusts, fires, and checks that leaving removes the ship.
