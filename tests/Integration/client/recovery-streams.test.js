// Connection state recovery on any process, without sticky sessions: two processes, one per port, joined by a Redis
// stream, against tests/Integration/recovery-processes-server.php with ADAPTER=redis-streams.
import { after, describe, test } from "node:test";
import assert from "node:assert/strict";
import { io } from "socket.io-client";

const TIMEOUT = { timeout: 20_000 };

const MODES = {
  polling: { transports: ["polling"] },
  websocket: { transports: ["websocket"] },
  upgrade: { transports: ["polling", "websocket"] },
};

const open = new Set();

function next(emitter, event) {
  return new Promise((resolve) => emitter.once(event, (...args) => resolve(args)));
}

async function connected(port, mode) {
  const socket = io(`http://localhost:${port}`, {
    forceNew: true,
    // A fixed delay, long enough to send while the client is away.
    reconnectionDelay: 500,
    reconnectionDelayMax: 500,
    randomizationFactor: 0,
    ...MODES[mode],
  });
  open.add(socket);
  await next(socket, "connect");
  return socket;
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
  describe(`recovery through a Redis stream over ${mode}`, () => {
    test("a client back on the other process gets its socket and what it missed", TIMEOUT, async () => {
      const a = await connected(3000, mode);
      const b = await connected(3001, mode);
      const watcher = await connected(3000, mode);
      const room = `room-${mode}`;
      const received = [];
      a.on("message", (message) => received.push(message));
      await a.emitWithAck("join", room);
      const id = a.id;
      // Until both processes read the stream, a broadcast may not cross.
      await until(async () => {
        await b.emitWithAck("to-room", room, "before");
        return received.length > 0;
      });

      // The next connection goes to the other process, as a load balancer without sticky sessions may send it.
      a.io.uri = "http://localhost:3001";
      const reconnected = next(a, "connect");
      a.io.engine.close();
      await until(async () => !(await watcher.emitWithAck("is-connected", id)));
      await b.emitWithAck("to-room", room, "missed 1");
      await watcher.emitWithAck("to-room", room, "missed 2");
      await reconnected;

      assert.equal(a.recovered, true);
      assert.equal(a.id, id);
      await until(() => received.includes("missed 2"));
      assert.deepEqual(received.filter((message) => message.startsWith("missed")), ["missed 1", "missed 2"]);
      const state = await a.emitWithAck("state");
      assert.equal(state.port, 3001);
      assert.ok(state.rooms.includes(room));
      // The room still reaches it there.
      await watcher.emitWithAck("to-room", room, "after");
      await until(() => received.includes("after"));
    });
  });
}
