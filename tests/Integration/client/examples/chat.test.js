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
