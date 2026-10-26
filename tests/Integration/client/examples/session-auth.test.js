import { test } from "node:test";
import assert from "node:assert/strict";
import { assertPageServed, client, next, PAGE, TIMEOUT } from "./helpers.js";

test("serves the page", TIMEOUT, assertPageServed);

// Logs in on the page and returns the session cookie, as a browser would keep it.
async function login(name) {
  const response = await fetch(new URL("login", PAGE), { method: "POST", body: new URLSearchParams({ name }) });
  assert.equal(response.status, 204);
  const [cookie] = response.headers.getSetCookie();
  return cookie.split(";")[0];
}

test("a socket without a session is refused", TIMEOUT, async () => {
  const socket = client();

  const [error] = await next(socket, "connect_error");

  assert.equal(error.message, "not logged in");
});

test("a socket with a logged-in session gets in as its user", TIMEOUT, async () => {
  const cookie = await login("ana");
  const socket = client("/", { extraHeaders: { cookie } });
  await next(socket, "connect");

  assert.equal(await socket.emitWithAck("whoami"), "ana");

  const chat = next(socket, "chat");
  socket.emit("chat", "hello");
  assert.deepEqual(await chat, [{ from: "ana", text: "hello" }]);
});

test("a forged or logged-out session is refused", TIMEOUT, async () => {
  const forged = client("/", { extraHeaders: { cookie: "PHPSID=aaaaaaaaaaaaaaaaaaaaaaaa" } });
  assert.equal((await next(forged, "connect_error"))[0].message, "not logged in");

  const cookie = await login("bia");
  const response = await fetch(new URL("logout", PAGE), { method: "POST", headers: { cookie } });
  assert.equal(response.status, 204);

  const loggedOut = client("/", { extraHeaders: { cookie } });
  assert.equal((await next(loggedOut, "connect_error"))[0].message, "not logged in");
});
