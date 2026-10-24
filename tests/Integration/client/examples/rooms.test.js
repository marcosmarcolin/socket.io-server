import { test } from "node:test";
import assert from "node:assert/strict";
import { assertPageServed, connected, next, record, sleep, TIMEOUT } from "./helpers.js";

test("serves the page", TIMEOUT, assertPageServed);

test("messages reach the other members of the room only", TIMEOUT, async () => {
  const a = await connected();
  const b = await connected();
  const c = await connected();

  // The member list follows each join's acknowledgement, so wait for it from before the join.
  let members = next(a, "members");
  assert.deepEqual(await a.emitWithAck("join room", "php"), [a.id, "php"]);
  assert.deepEqual((await members)[0], { room: "php", members: [a.id] });

  members = next(a, "members");
  await b.emitWithAck("join room", "php");
  assert.deepEqual((await members)[0], { room: "php", members: [a.id, b.id] });
  await c.emitWithAck("join room", "js");

  const toA = record(a, "room message");
  const toC = record(c, "room message");
  const toB = next(b, "room message");
  a.emit("room message", "php", "hello php");
  assert.deepEqual((await toB)[0], { room: "php", from: a.id, text: "hello php" });
  await sleep(200);
  assert.deepEqual(toA, []);
  assert.deepEqual(toC, []);

  members = next(a, "members");
  assert.deepEqual(await b.emitWithAck("leave room", "php"), [b.id]);
  assert.deepEqual((await members)[0], { room: "php", members: [a.id] });

  members = next(a, "members");
  await b.emitWithAck("join room", "php");
  await members;
  members = next(a, "members");
  b.disconnect();
  assert.deepEqual((await members)[0], { room: "php", members: [a.id] });
});
