// Integration tests with the official socket.io-client, against tests/Integration/server.php.
import { after, describe, test } from "node:test";
import assert from "node:assert/strict";
import { io, Manager } from "socket.io-client";

const URL = "http://localhost:3000";
const TIMEOUT = { timeout: 10_000 };

// Every scenario runs with each way of connecting.
const MODES = {
  polling: { transports: ["polling"] },
  websocket: { transports: ["websocket"] },
  upgrade: { transports: ["polling", "websocket"] },
};

const open = new Set();

function client(mode, namespace = "/", options = {}) {
  const socket = io(URL + namespace, {
    forceNew: true,
    reconnection: false,
    ...MODES[mode],
    ...options,
  });
  open.add(socket);
  return socket;
}

function next(emitter, event) {
  return new Promise((resolve) => emitter.once(event, (...args) => resolve(args)));
}

async function connected(mode, namespace = "/", options = {}) {
  const socket = client(mode, namespace, options);
  await next(socket, "connect");
  return socket;
}

// Collects an event, to check later that it did or didn't arrive.
function record(socket, event) {
  const received = [];
  socket.on(event, (...args) => received.push(args));
  return received;
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

after(() => {
  for (const socket of open) {
    socket.disconnect();
  }
});

for (const mode of Object.keys(MODES)) {
  describe(`${mode}`, () => {
    test("connects with a socket id distinct from the Engine.IO id", TIMEOUT, async () => {
      const socket = await connected(mode);

      assert.match(socket.id, /^[0-9a-f]{24}$/);
      assert.notEqual(socket.id, socket.io.engine.id);
    });

    if (mode === "upgrade") {
      test("upgrades from HTTP long-polling to WebSocket", TIMEOUT, async () => {
        const socket = client(mode);
        assert.equal(socket.io.engine.transport.name, "polling");

        await next(socket.io.engine, "upgrade");

        assert.equal(socket.io.engine.transport.name, "websocket");
        assert.deepEqual(await socket.emitWithAck("echo", "after upgrade"), "after upgrade");
      });
    }

    test("receives a middleware refusal with its data", TIMEOUT, async () => {
      const socket = client(mode, "/", { auth: { token: "refuse" } });

      const [error] = await next(socket, "connect_error");

      assert.equal(error.message, "refused");
      assert.deepEqual(error.data, { reason: "bad token" });
    });

    test("exchanges events with nested JSON", TIMEOUT, async () => {
      const socket = await connected(mode);
      const payload = { a: [1, "2", { b: true, c: null }], d: "é /  " };

      socket.emit("echo", payload, 42);
      const args = await next(socket, "echo");

      assert.deepEqual(args, [payload, 42]);
    });

    test("gets acknowledgements from the server", TIMEOUT, async () => {
      const socket = await connected(mode);

      assert.deepEqual(await socket.emitWithAck("echo", "x", { y: 1 }), "x");
    });

    test("answers acknowledgements asked by the server", TIMEOUT, async () => {
      const socket = await connected(mode);
      socket.on("question", (question, ack) => ack(`answer to: ${question}`));

      socket.emit("ask-me");
      const [answer] = await next(socket, "answer-received");

      assert.equal(answer, "answer to: what is the answer?");
    });

    test("lets the server time out when it gets no acknowledgement", TIMEOUT, async () => {
      const socket = await connected(mode);
      socket.on("silent", () => {});

      socket.emit("ask-me-and-wait");
      const [message] = await next(socket, "timed-out");

      assert.equal(message, "operation has timed out");
    });

    test("times out on the client side", TIMEOUT, async () => {
      const socket = await connected(mode);

      await assert.rejects(socket.timeout(200).emitWithAck("never-answer"), /operation has timed out/);
    });

    test("sends and receives binary data", TIMEOUT, async () => {
      const socket = await connected(mode);
      const bytes = Buffer.from([0, 1, 2, 255]);

      const echoed = await socket.emitWithAck("echo", { file: bytes, list: [bytes, "text"] });

      assert.ok(Buffer.isBuffer(echoed.file));
      assert.deepEqual(echoed.file, bytes);
      assert.deepEqual(echoed.list[0], bytes);
      assert.equal(echoed.list[1], "text");

      socket.emit("get-binary");
      const [message] = await next(socket, "binary");
      assert.deepEqual(message.file, Buffer.from([0, 1, 2, 255]));
      assert.equal(message.name, "bytes.bin");
    });

    test("multiplexes namespaces on one connection", TIMEOUT, async () => {
      const manager = new Manager(URL, { reconnection: false, ...MODES[mode] });
      const main = manager.socket("/");
      const admin = manager.socket("/admin");
      open.add(main);
      open.add(admin);

      const [[name]] = await Promise.all([next(admin, "admin-welcome"), next(main, "connect")]);

      assert.equal(name, "/admin");
      assert.ok(main.connected && admin.connected);
      assert.notEqual(main.id, admin.id);
      assert.equal(Object.keys(manager.nsps).length, 2);
    });

    test("refuses an unknown namespace", TIMEOUT, async () => {
      const socket = client(mode, "/unknown");

      const [error] = await next(socket, "connect_error");

      assert.equal(error.message, "Invalid namespace");
    });

    test("broadcasts to rooms, to everyone else, and except rooms", TIMEOUT, async () => {
      const room = `room-${mode}`;
      const a = await connected(mode);
      const b = await connected(mode);
      assert.deepEqual(await a.emitWithAck("join", room), [a.id, room]);
      const aRoom = record(a, "room-message");
      const bRoom = record(b, "room-message");
      const aBroadcast = record(a, "broadcast-message");
      const aExcept = record(a, "except-message");

      b.emit("to-room", room, "to the room");
      a.emit("broadcast", "to everyone else");
      const [[toB]] = await Promise.all([next(b, "broadcast-message"), next(a, "room-message")]);
      b.emit("everyone-except", room, "not to the room");
      await next(b, "except-message");
      await sleep(200);

      assert.deepEqual(aRoom, [["to the room"]]);
      assert.deepEqual(bRoom, []);
      assert.equal(toB, "to everyone else");
      assert.deepEqual(aBroadcast, []);
      assert.deepEqual(aExcept, []);
    });

    test("is disconnected by the server", TIMEOUT, async () => {
      const socket = await connected(mode);

      socket.emit("disconnect-me");
      const [reason] = await next(socket, "disconnect");

      assert.equal(reason, "io server disconnect");
    });

    test("disconnects, and the server sees why", TIMEOUT, async () => {
      const watcher = await connected(mode);
      const socket = await connected(mode);
      const id = socket.id;
      const left = new Promise((resolve) => {
        watcher.on("left", (leftId, reason) => leftId === id && resolve(reason));
      });

      socket.disconnect();

      assert.equal(await left, "client namespace disconnect");
    });
  });
}
