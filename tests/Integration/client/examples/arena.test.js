import { test } from "node:test";
import assert from "node:assert/strict";
import { assertPageServed, client, connected, next, TIMEOUT } from "./helpers.js";

const GAME = { timeout: 30_000 };

test("serves the page", TIMEOUT, assertPageServed);

// The next state frame that passes the check.
function frame(socket, check) {
  return new Promise((resolve) => {
    const listener = (state) => {
      if (check(state)) {
        socket.off("state", listener);
        resolve(state);
      }
    };
    socket.on("state", listener);
  });
}

const shipOf = (state, id) => state.ships.find((ship) => ship.id === id);

test("a pilot joins, steers, fires, and meets the bots", GAME, async () => {
  const ana = await connected();
  const scores = next(ana, "scores");
  const joined = await ana.emitWithAck("join", "ana");
  assert.equal(joined.id, ana.id);
  assert.deepEqual(joined.world, { width: 1200, height: 800, radius: 14 });
  assert.ok((await scores)[0].some((score) => score.name === "ana"));

  // Alone, the pilot gets bots to play with.
  const first = await frame(ana, (state) => shipOf(state, ana.id) && state.ships.some((ship) => ship.id.startsWith("bot:")));
  assert.equal(shipOf(first, ana.id).name, "ana");

  // Thrust moves the ship; a held fire button sends bullets.
  ana.emit("steer", 0, true, true);
  await frame(ana, (state) => {
    const ship = shipOf(state, ana.id);
    return ship && Math.hypot(ship.x - shipOf(first, ana.id).x, ship.y - shipOf(first, ana.id).y) > 30;
  });
  await frame(ana, (state) => state.bullets.length > 0);
  ana.emit("steer", 0, false, false);
});

test("leaving on purpose takes the ship out at once", GAME, async () => {
  const bia = await connected();
  const carla = await connected();
  await bia.emitWithAck("join", "bia");
  await carla.emitWithAck("join", "carla");
  const biaId = bia.id;
  await frame(carla, (state) => shipOf(state, biaId));

  bia.disconnect();

  await frame(carla, (state) => !shipOf(state, biaId));
});

test("a client that never joins gets no frames", TIMEOUT, async () => {
  const watcher = client();
  await next(watcher, "connect");
  let frames = 0;
  watcher.on("state", () => frames++);
  await new Promise((resolve) => setTimeout(resolve, 500));
  assert.equal(frames, 0);
});
