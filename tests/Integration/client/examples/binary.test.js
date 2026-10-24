import { test } from "node:test";
import assert from "node:assert/strict";
import { createHash, randomBytes } from "node:crypto";
import { assertPageServed, connected, TIMEOUT } from "./helpers.js";

test("serves the page", TIMEOUT, assertPageServed);

test("uploads a file and gets its size and hash", TIMEOUT, async () => {
  const socket = await connected();
  const bytes = randomBytes(300_000);

  const result = await socket.emitWithAck("upload", { name: "photo.jpg", data: bytes });

  assert.deepEqual(result, {
    name: "photo.jpg",
    size: bytes.length,
    sha256: createHash("sha256").update(bytes).digest("hex"),
  });
});

test("downloads bytes made by the server", TIMEOUT, async () => {
  const socket = await connected();

  const { name, data } = await socket.emitWithAck("download", 2048);

  assert.equal(name, "random.bin");
  assert.ok(Buffer.isBuffer(data));
  assert.equal(data.length, 2048);
});
