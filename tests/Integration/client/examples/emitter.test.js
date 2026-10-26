import { test } from "node:test";
import assert from "node:assert/strict";
import { assertPageServed, client, next, PAGE, TIMEOUT } from "./helpers.js";

test("serves the page", TIMEOUT, assertPageServed);

test("a POST to the PHP app reaches every client through the Emitter", { timeout: 30_000 }, async () => {
  const sockets = [client(), client()];
  await Promise.all(sockets.map((socket) => next(socket, "connect")));

  // The Socket.IO server subscribes to Redis as it starts: retry until a notification arrives.
  for (let attempt = 0; ; attempt++) {
    const text = `hello ${attempt}`;
    const arrivals = sockets.map(
      (socket) =>
        new Promise((resolve) => {
          const listener = (notification) => notification.text === text && resolve(notification);
          socket.on("notification", listener);
          setTimeout(() => {
            socket.off("notification", listener);
            resolve(null);
          }, 500);
        }),
    );
    const response = await fetch(new URL("notify", PAGE), { method: "POST", body: new URLSearchParams({ text }) });
    assert.equal(response.status, 204);
    const notifications = await Promise.all(arrivals);
    if (notifications.every(Boolean)) {
      assert.match(notifications[0].at, /^\d\d:\d\d:\d\d$/);
      break;
    }
    assert.ok(attempt < 20, "notifications never arrived");
  }
});

test("the PHP app refuses an empty notification", TIMEOUT, async () => {
  const response = await fetch(new URL("notify", PAGE), { method: "POST", body: new URLSearchParams({ text: " " }) });

  assert.equal(response.status, 400);
});
