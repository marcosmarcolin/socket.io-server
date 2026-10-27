import { test } from "node:test";
import assert from "node:assert/strict";
import { assertPageServed, connected, next, TIMEOUT } from "./helpers.js";

test("serves the page", TIMEOUT, assertPageServed);

test("users join, chat, type and leave", TIMEOUT, async () => {
  const ana = await connected();
  const bia = await connected();

  ana.emit("add user", "ana");
  assert.deepEqual((await next(ana, "login"))[0], { numUsers: 1 });

  const joined = next(ana, "user joined");
  bia.emit("add user", "bia");
  assert.deepEqual((await next(bia, "login"))[0], { numUsers: 2 });
  assert.deepEqual((await joined)[0], { username: "bia", numUsers: 2 });

  const message = next(bia, "new message");
  ana.emit("new message", "hi!");
  assert.deepEqual((await message)[0], { username: "ana", message: "hi!" });

  const typing = next(ana, "typing");
  bia.emit("typing");
  assert.deepEqual((await typing)[0], { username: "bia" });

  const left = next(ana, "user left");
  bia.disconnect();
  assert.deepEqual((await left)[0], { username: "bia", numUsers: 1 });
});

test("a user whose connection drops comes back with what they missed", TIMEOUT, async () => {
  // Reconnection on, with a fixed delay long enough to send while the user is away.
  const ana = await connected("/", { reconnection: true, reconnectionDelay: 500, randomizationFactor: 0 });
  const bia = await connected();
  ana.emit("add user", "ana");
  await next(ana, "login");
  bia.emit("add user", "bia");
  await next(bia, "login");

  const reconnected = next(ana, "connect");
  const back = next(bia, "user joined");
  ana.io.engine.close();
  await next(bia, "user left");
  bia.emit("new message", "while you were away");
  const missed = next(ana, "new message");
  await reconnected;

  assert.equal(ana.recovered, true);
  assert.deepEqual((await missed)[0], { username: "bia", message: "while you were away" });
  // The users of the test above are still connected, so the count isn't checked here.
  const [announcement] = await back;
  assert.equal(announcement.username, "ana");
  assert.equal(announcement.back, true);
});
