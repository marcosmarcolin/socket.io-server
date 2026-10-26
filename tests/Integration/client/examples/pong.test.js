import { test } from "node:test";
import assert from "node:assert/strict";
import { assertPageServed, client, next, TIMEOUT } from "./helpers.js";

const GAME = { timeout: 30_000 };

test("serves the page", TIMEOUT, assertPageServed);

// Two players start a match: the first waits, the second is paired with them.
async function match() {
  const ana = client();
  const bia = client();
  const waiting = next(ana, "waiting");
  ana.emit("play", "ana");
  await waiting;
  const anaStart = next(ana, "start");
  const biaStart = next(bia, "start");
  bia.emit("play", "bia");
  return { ana, bia, starts: await Promise.all([anaStart, biaStart]) };
}

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

test("pairs two players and runs the match on the server", GAME, async () => {
  const { ana, bia, starts } = await match();
  const [[anaStart], [biaStart]] = starts;
  assert.equal(anaStart.side, "left");
  assert.equal(biaStart.side, "right");
  assert.deepEqual(anaStart.names, { left: "ana", right: "bia" });
  assert.equal(anaStart.field.pointsToWin, 5);

  // The paddle goes down while the player holds the key.
  const first = await frame(ana, () => true);
  ana.emit("move", 1);
  await frame(ana, (state) => state.paddles.left >= first.paddles.left + 40);
  ana.emit("move", 0);

  // With both paddles out of the way, the ball gets through and someone scores.
  ana.emit("move", -1);
  bia.emit("move", -1);
  const [score] = await next(bia, "score");
  assert.equal(score.left + score.right, 1);

  // A third player waits for the next free opponent.
  const carla = client();
  const waiting = next(carla, "waiting");
  carla.emit("play", "carla");
  await waiting;

  // Leaving gives the match to the other player.
  const over = next(ana, "game over");
  bia.disconnect();
  const [result] = await over;
  assert.equal(result.winner, "left");
  assert.equal(result.reason, "opponent left");
});
