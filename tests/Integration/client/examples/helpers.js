// Shared by the example tests: each example serves Socket.IO on port 3000 and its page on port 3001.
import { after } from "node:test";
import assert from "node:assert/strict";
import { io } from "socket.io-client";

// The examples listen on 0.0.0.0, so IPv4 it is.
export const URL = "http://127.0.0.1:3000";
export const PAGE = "http://127.0.0.1:3001/";
export const TIMEOUT = { timeout: 15_000 };

const open = new Set();

after(() => {
  for (const socket of open) {
    socket.disconnect();
  }
});

export function client(namespace = "/", options = {}) {
  const socket = io(URL + namespace, { forceNew: true, reconnection: false, ...options });
  open.add(socket);
  return socket;
}

export function next(emitter, event) {
  return new Promise((resolve) => emitter.once(event, (...args) => resolve(args)));
}

export async function connected(namespace = "/", options = {}) {
  const socket = client(namespace, options);
  await next(socket, "connect");
  return socket;
}

export function record(socket, event) {
  const received = [];
  socket.on(event, (...args) => received.push(args));
  return received;
}

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// The page loads the pinned client with its integrity hash.
export async function assertPageServed() {
  const response = await fetch(PAGE);
  assert.equal(response.status, 200);
  const html = await response.text();
  assert.match(html, /cdn\.socket\.io\/4\.8\.4\/socket\.io\.min\.js/);
  assert.match(html, /integrity="sha384-/);
}
