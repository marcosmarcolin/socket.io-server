// Connection state recovery across two processes, one per port, as behind a proxy with sticky sessions, against
// tests/Integration/recovery-processes-server.php.
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
  describe(`recovery across processes over ${mode}`, () => {
    test("a client back on its process gets what the other process sent meanwhile", TIMEOUT, async () => {
      const a = await connected(3000, mode);
      const b = await connected(3001, mode);
      // On A's process, to see when it has dropped A's socket.
      const watcher = await connected(3000, mode);
      // A room of its own: the clients of the other modes stay connected, in their rooms.
      const room = `room-${mode}`;
      const received = [];
      a.on("message", (message) => received.push(message));
      await a.emitWithAck("join", room);
      const id = a.id;
      // Until the processes hear each other through the channel server, a broadcast may not cross.
      await until(async () => {
        await b.emitWithAck("to-room", room, "before");
        return received.length > 0;
      });

      // Listened to first: the client may reconnect before the messages below are acknowledged.
      const reconnected = next(a, "connect");
      a.io.engine.close();
      // Once A's process dropped the socket, it has no member of the room left and left the room's channel.
      await until(async () => !(await watcher.emitWithAck("is-connected", id)));
      await b.emitWithAck("to-room", room, "missed 1");
      await b.emitWithAck("to-room", room, "missed 2");
      await reconnected;

      assert.equal(a.recovered, true);
      assert.equal(a.id, id);
      // The warm-up above may have sent "before" more than once, so only the missed messages are counted.
      await until(() => received.includes("missed 2"));
      assert.deepEqual(received.filter((message) => message.startsWith("missed")), ["missed 1", "missed 2"]);
      const state = await a.emitWithAck("state");
      assert.equal(state.port, 3000);
      assert.ok(state.rooms.includes(room));
    });
  });
}
