import { test } from "node:test";
import assert from "node:assert/strict";
import { assertPageServed, client, next, record, sleep, TIMEOUT } from "./helpers.js";

test("serves the page", TIMEOUT, assertPageServed);

// Connects a tab, as a new user or back into a session, and returns it with its session.
async function tab(auth) {
  const socket = client("/", { auth });
  // Both arrive together on connect, so both are awaited from the start.
  const [[session], [users]] = await Promise.all([next(socket, "session"), next(socket, "users")]);
  return { socket, session, users };
}

test("a username is required", TIMEOUT, async () => {
  const [error] = await next(client("/", { auth: {} }), "connect_error");

  assert.equal(error.message, "a username is required");
});

test("a message reaches every tab of the recipient and the sender's other tabs", TIMEOUT, async () => {
  const ana1 = await tab({ username: "ana" });
  const ana2 = await tab({ sessionId: ana1.session.sessionId });
  const bia1 = await tab({ username: "bia" });
  const bia2 = await tab({ sessionId: bia1.session.sessionId });
  const caio = await tab({ username: "caio" });
  assert.equal(ana2.session.userId, ana1.session.userId, "the session keeps the user");

  const got = [ana1, ana2, bia1, bia2, caio].map(({ socket }) => record(socket, "private message"));
  bia1.socket.emit("private message", ana1.session.userId, "hi ana");
  await sleep(300);

  const message = { content: "hi ana", from: bia1.session.userId, to: ana1.session.userId };
  assert.deepEqual(got[0], [[message]]);
  assert.deepEqual(got[1], [[message]]);
  assert.deepEqual(got[2], [], "the sending tab shows its own message");
  assert.deepEqual(got[3], [[message]]);
  assert.deepEqual(got[4], []);
});

test("users come and go with their first and last tab", TIMEOUT, async () => {
  const watcher = await tab({ username: "watcher" });
  const connected = record(watcher.socket, "user connected");
  const disconnected = record(watcher.socket, "user disconnected");

  const dora1 = await tab({ username: "dora" });
  const dora2 = await tab({ sessionId: dora1.session.sessionId });
  await sleep(200);
  assert.deepEqual(connected, [[{ userId: dora1.session.userId, username: "dora" }]]);

  const listed = dora2.users.find((user) => user.userId === dora1.session.userId);
  assert.equal(listed.connected, true);

  dora1.socket.disconnect();
  await sleep(200);
  assert.deepEqual(disconnected, []);

  dora2.socket.disconnect();
  await sleep(200);
  assert.deepEqual(disconnected, [[dora1.session.userId]]);
});
