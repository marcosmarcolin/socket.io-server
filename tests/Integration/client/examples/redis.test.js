import { test } from "node:test";
import assert from "node:assert/strict";
import { assertPageServed, client, next, TIMEOUT } from "./helpers.js";

const WEBSOCKET = { transports: ["websocket"] };

test("serves the page", TIMEOUT, assertPageServed);

test("clients on different processes get each other's messages", { timeout: 30_000 }, async () => {
  // Connects until the clients sit on at least two processes.
  const clients = [];
  const processes = new Set();
  for (let i = 0; i < 40 && processes.size < 2; i++) {
    const socket = client("/", WEBSOCKET);
    const [pid] = await next(socket, "process");
    clients.push(socket);
    processes.add(pid);
  }
  assert.ok(processes.size >= 2, "all clients landed on one process");

  // The workers connect to Redis as they start: retry until a message reaches everyone.
  for (let attempt = 0; ; attempt++) {
    const text = `hello ${attempt}`;
    const arrivals = clients.map(
      (socket) =>
        new Promise((resolve) => {
          const listener = (message) => message.text === text && resolve(true);
          socket.on("chat message", listener);
          setTimeout(() => {
            socket.off("chat message", listener);
            resolve(false);
          }, 500);
        }),
    );
    clients[0].emit("chat message", text);
    if ((await Promise.all(arrivals)).every(Boolean)) {
      break;
    }
    assert.ok(attempt < 20, "messages never reached every process");
  }
});
