import { test } from "node:test";
import assert from "node:assert/strict";
import { assertPageServed, client, connected, next, record, sleep, TIMEOUT } from "./helpers.js";

test("serves the page", TIMEOUT, assertPageServed);

test("/admin refuses a wrong token, with the hint", TIMEOUT, async () => {
  const admin = client("/admin", { auth: { token: "wrong" } });

  const [error] = await next(admin, "connect_error");

  assert.equal(error.message, "forbidden");
  assert.deepEqual(error.data, { hint: 'the token is "secret"' });
});

test("/admin announces to /public, and /public messages stay there", TIMEOUT, async () => {
  const visitor = await connected("/public");
  const admin = await connected("/admin", { auth: { token: "secret" } });
  const adminMessages = record(admin, "message");

  const announcement = next(visitor, "announcement");
  assert.equal(await admin.emitWithAck("announce", "maintenance at 22h"), 1);
  assert.deepEqual(await announcement, ["maintenance at 22h"]);

  const message = next(visitor, "message");
  visitor.emit("message", "hello");
  assert.deepEqual(await message, ["hello"]);
  await sleep(200);
  assert.deepEqual(adminMessages, []);
});
