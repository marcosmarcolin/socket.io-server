// The Admin UI's protocol, with the official client in the UI's place, against tests/Integration/admin-server.php.
import { after, describe, test } from "node:test";
import assert from "node:assert/strict";
import { io } from "socket.io-client";

const URL = "http://localhost:3000";
const TIMEOUT = { timeout: 15_000 };
const LOGIN = { username: "admin", password: "secret" };

const MODES = {
  polling: { transports: ["polling"] },
  websocket: { transports: ["websocket"] },
  upgrade: { transports: ["polling", "websocket"] },
};

const open = new Set();

function client(path, mode, options = {}) {
  const socket = io(URL + path, { forceNew: true, reconnection: false, ...MODES[mode], ...options });
  open.add(socket);
  return socket;
}

function next(emitter, event) {
  return new Promise((resolve) => emitter.once(event, (...args) => resolve(args)));
}

// Waits for an event whose arguments pass the check.
function nextMatching(emitter, event, check) {
  return new Promise((resolve) => {
    const listener = (...args) => {
      if (check(...args)) {
        emitter.off(event, listener);
        resolve(args);
      }
    };
    emitter.on(event, listener);
  });
}

after(() => {
  for (const socket of open) {
    socket.disconnect();
  }
});

for (const mode of Object.keys(MODES)) {
  describe(`admin ui over ${mode}`, () => {
    test("refuses wrong credentials", TIMEOUT, async () => {
      const admin = client("/admin", mode, { auth: { username: "admin", password: "wrong" } });

      const [error] = await next(admin, "connect_error");

      assert.equal(error.message, "invalid credentials");
    });

    test("logs in, gets a session, the config and every socket", TIMEOUT, async () => {
      const user = client("/", mode);
      await next(user, "connect");
      await user.emitWithAck("join", "room");

      const admin = client("/admin", mode, { auth: LOGIN });
      const session = next(admin, "session");
      const config = next(admin, "config");
      const allSockets = next(admin, "all_sockets");

      const [sessionId] = await session;
      assert.match(sessionId, /^[0-9a-f]{16}$/);
      const [{ supportedFeatures }] = await config;
      assert.ok(supportedFeatures.includes("EMIT"));
      assert.ok(supportedFeatures.includes("ALL_EVENTS"));
      const [sockets] = await allSockets;
      const described = sockets.find((socket) => socket.id === user.id);
      assert.ok(described, "the user's socket is listed");
      assert.equal(described.nsp, "/");
      assert.ok(described.rooms.includes("room"));
      assert.equal(typeof described.clientId, "string");
      assert.ok(["polling", "websocket"].includes(described.transport));
      assert.equal(described.handshake.auth, undefined);

      // The session lets the UI back in without the password.
      const again = client("/admin", mode, { auth: { sessionId } });
      await next(again, "config");
    });

    test("sees sockets connect, send, receive, join and leave", TIMEOUT, async () => {
      const admin = client("/admin", mode, { auth: LOGIN });
      await next(admin, "config");

      const user = client("/", mode);
      // Both listened to first: either may come first.
      const userConnected = next(user, "connect");
      const seen = nextMatching(admin, "socket_connected", (socket) => socket.nsp === "/");
      await userConnected;
      const [connected] = await seen;
      assert.equal(connected.id, user.id);

      const joined = nextMatching(admin, "room_joined", (nsp, room, id) => room === "room" && id === user.id);
      await user.emitWithAck("join", "room");
      await joined;

      const received = nextMatching(admin, "event_received", (nsp, id, args) => args[0] === "ping-me");
      const sent = nextMatching(admin, "event_sent", (nsp, id, args) => args[0] === "pong-you");
      user.emit("ping-me");
      const [, receivedBy] = await received;
      const [, , sentArgs] = await sent;
      assert.equal(receivedBy, user.id);
      assert.deepEqual(sentArgs, ["pong-you", "hi"]);

      // The client forgets its id on disconnecting.
      const userId = user.id;
      const disconnected = nextMatching(admin, "socket_disconnected", (nsp, id) => id === userId);
      user.disconnect();
      const [, , reason, timestamp] = await disconnected;
      assert.equal(reason, "client namespace disconnect");
      assert.ok(!Number.isNaN(Date.parse(timestamp)));
    });

    test("gets the server stats", TIMEOUT, async () => {
      const admin = client("/admin", mode, { auth: LOGIN });

      const [stats] = await next(admin, "server_stats");

      assert.equal(typeof stats.serverId, "string");
      assert.equal(typeof stats.uptime, "number");
      assert.ok(stats.clientsCount >= 1);
      assert.ok(stats.namespaces.some((nsp) => nsp.name === "/admin"));
      assert.ok(Array.isArray(stats.aggregatedEvents));
    });

    test("emits, joins and disconnects from the UI", TIMEOUT, async () => {
      const admin = client("/admin", mode, { auth: LOGIN });
      await next(admin, "config");
      const user = client("/", mode);
      await next(user, "connect");

      const alert = next(user, "alert");
      admin.emit("emit", "/", user.id, "alert", "from the admin");
      assert.deepEqual(await alert, ["from the admin"]);

      const joined = nextMatching(admin, "room_joined", (nsp, room, id) => room === "vip" && id === user.id);
      admin.emit("join", "/", "vip", user.id);
      await joined;

      const gone = next(user, "disconnect");
      admin.emit("_disconnect", "/", false, user.id);
      const [reason] = await gone;
      assert.equal(reason, "io server disconnect");
    });
  });
}
