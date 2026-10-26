// Multi-process tests with the official socket.io-client, against tests/Integration/multi-process-server.php:
// two worker processes on one port, joined by ChannelAdapter over workerman/channel, or by RedisAdapter.
import { after, before, describe, test } from "node:test";
import assert from "node:assert/strict";
import { io } from "socket.io-client";

const URL = "http://localhost:3000";
const TIMEOUT = { timeout: 30_000 };
// Polling would need sticky sessions across processes, so every client uses WebSocket only.
const OPTIONS = { transports: ["websocket"], forceNew: true, reconnection: false };

const open = new Set();
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function next(emitter, event) {
  return new Promise((resolve) => emitter.once(event, (...args) => resolve(args)));
}

async function connect() {
  const socket = io(URL, OPTIONS);
  open.add(socket);
  await next(socket, "connect");
  socket.pid = await socket.emitWithAck("whoami");
  // Every client answers the server's questions with the pid of its process.
  socket.on("question", (ack) => ack(socket.pid));
  return socket;
}

function record(socket, event) {
  const received = [];
  socket.on(event, (...args) => received.push(args));
  return received;
}

// Two clients on each of the two processes.
const byProcess = new Map();

async function clientsOnEveryProcess(perProcess) {
  for (let attempt = 0; attempt < 200; attempt++) {
    if (byProcess.size >= 2 && [...byProcess.values()].every((list) => list.length >= perProcess)) {
      return;
    }
    const socket = await connect();
    const list = byProcess.get(socket.pid) ?? [];
    if (list.length < perProcess) {
      list.push(socket);
      byProcess.set(socket.pid, list);
    }
  }
  throw new Error(`clients reached only processes ${[...byProcess.keys()]}`);
}

before(async () => {
  await clientsOnEveryProcess(2);

  // The workers connect to the broker as they start: retry until a broadcast crosses.
  const [[sender], [receiver]] = [...byProcess.values()];
  for (let attempt = 0; ; attempt++) {
    const token = `warmup-${attempt}`;
    const arrived = new Promise((resolve) => {
      const listener = (message) => message === token && resolve(true);
      receiver.on("everyone", listener);
      setTimeout(() => {
        receiver.off("everyone", listener);
        resolve(false);
      }, 300);
    });
    sender.emit("everyone", token);
    if (await arrived) {
      break;
    }
    assert.ok(attempt < 30, "broadcasts never crossed processes");
  }
});

after(() => {
  for (const socket of open) {
    socket.disconnect();
  }
});

describe("multiple processes", () => {
  test("clients are spread over both processes", () => {
    assert.equal(byProcess.size, 2);
  });

  test("an emit reaches every client of every process once", TIMEOUT, async () => {
    const clients = [...byProcess.values()].flat();
    const received = clients.map((socket) => record(socket, "everyone"));

    clients[0].emit("everyone", "hello all");
    await sleep(300);

    for (const messages of received) {
      assert.deepEqual(messages.filter(([m]) => m === "hello all"), [["hello all"]]);
    }
  });

  test("rooms span processes", TIMEOUT, async () => {
    const [[a1, a2], [b1, b2]] = [...byProcess.values()];
    await a1.emitWithAck("join", "spanning");
    await b1.emitWithAck("join", "spanning");
    const inRoom = [record(a1, "room-message"), record(b1, "room-message")];
    const outside = [record(a2, "room-message"), record(b2, "room-message")];

    b2.emit("to-rooms", ["spanning"], "room only");
    await sleep(300);

    assert.deepEqual(inRoom, [[["room only"]], [["room only"]]]);
    assert.deepEqual(outside, [[], []]);
  });

  test("a socket in several targeted rooms gets the event once", TIMEOUT, async () => {
    const [[a1], [b1]] = [...byProcess.values()];
    await b1.emitWithAck("join", "r1");
    await b1.emitWithAck("join", "r2");
    const received = record(b1, "room-message");

    a1.emit("to-rooms", ["r1", "r2"], "once");
    await sleep(300);

    assert.deepEqual(received.filter(([m]) => m === "once"), [["once"]]);
  });

  test("except applies on every process", TIMEOUT, async () => {
    const [[a1, a2], [b1, b2]] = [...byProcess.values()];
    await a2.emitWithAck("join", "quiet");
    await b2.emitWithAck("join", "quiet");
    const got = [a1, a2, b1, b2].map((socket) => record(socket, "except-message"));

    a1.emit("everyone-except", "quiet", "not for quiet");
    await sleep(300);

    assert.deepEqual(got.map((messages) => messages.length), [1, 0, 1, 0]);
  });

  test("broadcast leaves only the sender out, on every process", TIMEOUT, async () => {
    const clients = [...byProcess.values()].flat();
    const got = clients.map((socket) => record(socket, "broadcast-message"));

    clients[0].emit("broadcast", "to the others");
    await sleep(300);

    assert.deepEqual(got.map((messages) => messages.length), [0, 1, 1, 1]);
  });

  test("socketsJoin and socketsLeave apply on every process", TIMEOUT, async () => {
    const clients = [...byProcess.values()].flat();
    const got = clients.map((socket) => record(socket, "room-message"));

    await clients[0].emitWithAck("join-all", "joined-by-server");
    await sleep(300);
    clients[3].emit("to-rooms", ["joined-by-server"], "after join");
    await sleep(300);
    await clients[3].emitWithAck("leave-all", "joined-by-server");
    await sleep(300);
    clients[0].emit("to-rooms", ["joined-by-server"], "after leave");
    await sleep(300);

    for (const messages of got) {
      assert.deepEqual(messages.filter(([m]) => m.startsWith("after ")), [["after join"]]);
    }
  });

  test("disconnectSockets reaches a socket on another process", TIMEOUT, async () => {
    const [[a1]] = [...byProcess.values()];
    let victim = await connect();
    for (let attempt = 0; victim.pid === a1.pid; attempt++) {
      assert.ok(attempt < 50, "no client landed on the other process");
      victim = await connect();
    }
    const disconnected = next(victim, "disconnect");

    a1.emit("kick", victim.id);

    assert.deepEqual(await disconnected, ["io server disconnect", undefined]);
  });

  test("serverSideEmit gets an answer from the other process", TIMEOUT, async () => {
    const [[a1], [b1]] = [...byProcess.values()];

    assert.deepEqual(await a1.emitWithAck("servers"), { error: null, pids: [b1.pid] });
    assert.deepEqual(await b1.emitWithAck("servers"), { error: null, pids: [a1.pid] });
  });

  test("fetchSockets with a callback finds the sockets of every process", TIMEOUT, async () => {
    const [[a1], [b1]] = [...byProcess.values()];
    await a1.emitWithAck("set-name", "ana");
    await b1.emitWithAck("set-name", "bia");
    await a1.emitWithAck("join", "fetchable");
    await b1.emitWithAck("join", "fetchable");

    const { error, sockets } = await a1.emitWithAck("fetch", "fetchable");

    assert.equal(error, null);
    assert.deepEqual(sockets, [
      { id: a1.id, name: "ana", remote: false },
      { id: b1.id, name: "bia", remote: true },
    ]);

    const poked = [next(a1, "poked"), next(b1, "poked")];
    b1.emit("poke", "fetchable");
    assert.deepEqual(await Promise.all(poked), [[b1.pid], [b1.pid]]);
  });

  test("a broadcast with an acknowledgement gets the answers of every process", TIMEOUT, async () => {
    const [[a1], [b1]] = [...byProcess.values()];
    const connected = [...open].filter((socket) => socket.connected);

    const { error, answers } = await a1.emitWithAck("ask-all");

    assert.equal(error, null);
    assert.equal(answers.length, connected.length);
    assert.deepEqual(new Set(answers), new Set([a1.pid, b1.pid]));
  });

  test("a RemoteSocket gets an answer from its process", TIMEOUT, async () => {
    const [[a1], [b1]] = [...byProcess.values()];

    assert.deepEqual(await a1.emitWithAck("ask-one", b1.id), { error: null, answer: b1.pid });
  });

  test("scale: 200 clients get 20 broadcasts each, once and in order", { timeout: 60_000 }, async () => {
    const CLIENTS = 200;
    const MESSAGES = 20;
    const clients = await Promise.all(Array.from({ length: CLIENTS }, () => connect()));
    const perProcess = new Map();
    for (const socket of clients) {
      perProcess.set(socket.pid, (perProcess.get(socket.pid) ?? 0) + 1);
    }
    const received = clients.map((socket) => record(socket, "flood"));
    const done = Promise.all(
      clients.map(
        (socket, index) =>
          new Promise((resolve) => {
            socket.on("flood", () => received[index].length >= MESSAGES && resolve());
          }),
      ),
    );

    const started = performance.now();
    clients[0].emit("flood", MESSAGES);
    await done;
    const elapsed = performance.now() - started;
    await sleep(300);

    const expected = Array.from({ length: MESSAGES }, (_, i) => [i]);
    for (const messages of received) {
      assert.deepEqual(messages, expected);
    }
    assert.ok(perProcess.size === 2, `clients reached processes ${[...perProcess.keys()]}`);
    console.log(
      `scale: ${CLIENTS * MESSAGES} deliveries to ${CLIENTS} clients ` +
        `(${[...perProcess.values()].join(" + ")} per process) in ${elapsed.toFixed(0)} ms`,
    );
  });
});
