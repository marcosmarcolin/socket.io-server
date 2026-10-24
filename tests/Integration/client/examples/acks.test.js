import { test } from "node:test";
import assert from "node:assert/strict";
import { assertPageServed, connected, next, TIMEOUT } from "./helpers.js";

test("serves the page", TIMEOUT, assertPageServed);

test("the client asks the server", TIMEOUT, async () => {
  const socket = await connected();

  assert.equal(await socket.emitWithAck("sum", [1, 2, 3.5, "x"]), 6.5);
});

test("the server asks the client", TIMEOUT, async () => {
  const socket = await connected();
  socket.on("question", (question, ack) => ack(`answer to: ${question}`));

  socket.emit("ask me", 2000);

  assert.deepEqual((await next(socket, "result"))[0], { answer: "answer to: What is your favourite colour?" });
});

test("the server gives up when the client doesn't answer", TIMEOUT, async () => {
  const socket = await connected();
  socket.on("question", () => {});

  socket.emit("ask me", 200);

  assert.deepEqual((await next(socket, "result"))[0], { error: "operation has timed out" });
});
