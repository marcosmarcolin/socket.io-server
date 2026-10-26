import { test } from "node:test";
import assert from "node:assert/strict";
import { assertPageServed, client, connected, next, TIMEOUT, URL } from "./helpers.js";

test("serves the page", TIMEOUT, assertPageServed);

test("lets the chat page and the Admin UI in from their origins", TIMEOUT, async () => {
  // Node's client ignores CORS, so the headers a browser needs are checked here.
  for (const origin of ["http://localhost:3001", "https://admin.socket.io", "http://localhost:3002"]) {
    const response = await fetch(`${URL}/socket.io/?EIO=4&transport=polling`, { headers: { Origin: origin } });
    assert.equal(response.headers.get("access-control-allow-origin"), origin);
    assert.equal(response.headers.get("access-control-allow-credentials"), "true");
  }
});

test("the chat works, and the Admin UI logs in and sees it", TIMEOUT, async () => {
  const refused = client("/admin", { auth: { username: "admin", password: "wrong" } });
  assert.equal((await next(refused, "connect_error"))[0].message, "invalid credentials");

  const admin = client("/admin", { auth: { username: "admin", password: "admin" } });
  const config = next(admin, "config");
  await next(admin, "connect");
  assert.ok((await config)[0].supportedFeatures.includes("ALL_EVENTS"));

  const ana = await connected();
  const received = new Promise((resolve) => {
    admin.on("event_received", (nsp, id, args) => {
      if (id === ana.id && args[0] === "add user") resolve(args);
    });
  });
  ana.emit("add user", "ana");
  assert.deepEqual((await next(ana, "login"))[0], { numUsers: 1 });
  assert.deepEqual(await received, ["add user", "ana"]);

  const [stats] = await next(admin, "server_stats");
  assert.ok(stats.namespaces.some((nsp) => nsp.name === "/" && nsp.socketsCount === 1));
});
