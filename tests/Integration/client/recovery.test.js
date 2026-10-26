// Connection state recovery with the official socket.io-client, against tests/Integration/recovery-server.php.
import { after, describe, test } from "node:test";
import assert from "node:assert/strict";
import { io } from "socket.io-client";

const URL = "http://localhost:3000";
const TIMEOUT = { timeout: 15_000 };

// Every scenario runs with each way of connecting.
const MODES = {
  polling: { transports: ["polling"] },
  websocket: { transports: ["websocket"] },
  upgrade: { transports: ["polling", "websocket"] },
};

const open = new Set();

function next(emitter, event) {
  return new Promise((resolve) => emitter.once(event, (...args) => resolve(args)));
}

async function connected(mode, options = {}) {
  const socket = io(URL, { forceNew: true, reconnectionDelay: 100, reconnectionDelayMax: 100, ...MODES[mode], ...options });
  open.add(socket);
  await next(socket, "connect");
  return socket;
}

// The messages a socket receives, without the offset the server adds as the last argument.
function messages(socket) {
  const received = [];
  socket.on("message", (message) => received.push(message));
  return received;
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function until(check) {
  for (let i = 0; i < 100; i++) {
    if (await check()) return;
    await sleep(50);
  }
  throw new Error("timed out waiting");
}

after(() => {
  for (const socket of open) {
    socket.disconnect();
  }
});

for (const mode of Object.keys(MODES)) {
  describe(`connection state recovery over ${mode}`, () => {
    test("a client whose transport dropped gets its socket and the missed messages back", TIMEOUT, async () => {
      const a = await connected(mode);
      const b = await connected(mode);
      const received = messages(a);
      await a.emitWithAck("join", "room");
      await a.emitWithAck("set-data", { user: "ana" });
      const id = a.id;
      // A first message, so the client has an offset to come back with.
      await b.emitWithAck("to-room", "room", "before");
      await until(() => received.length === 1);

      a.io.engine.close();
      await until(async () => !(await b.emitWithAck("is-connected", id)));
      await b.emitWithAck("to-room", "room", "missed 1");
      await b.emitWithAck("to-room", "room", "missed 2");
      await next(a, "connect");

      assert.equal(a.recovered, true);
      assert.equal(a.id, id);
      await until(() => received.length === 3);
      assert.deepEqual(received, ["before", "missed 1", "missed 2"]);
      const state = await a.emitWithAck("state");
      assert.equal(state.recovered, true);
      assert.ok(state.rooms.includes("room"));
      assert.deepEqual(state.data, { user: "ana" });
      // skipMiddlewares, the reference's default: the middleware ran on the first connection only.
      assert.equal(state.checks, 1);

      // The recovered socket is still in its room.
      await b.emitWithAck("to-room", "room", "after");
      await until(() => received.length === 4);
      assert.equal(received[3], "after");
    });

    test("a client that disconnected on purpose starts over", TIMEOUT, async () => {
      const a = await connected(mode);
      const b = await connected(mode);
      const received = messages(a);
      await a.emitWithAck("join", "room");
      await b.emitWithAck("to-room", "room", "before");
      await until(() => received.length === 1);
      const id = a.id;

      a.disconnect();
      a.connect();
      await next(a, "connect");

      assert.equal(a.recovered, false);
      assert.notEqual(a.id, id);
      const state = await a.emitWithAck("state");
      assert.deepEqual(state.rooms, [a.id]);
    });
  });
}
