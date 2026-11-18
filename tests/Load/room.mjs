// The latency of broadcasts to a room, with the clients spread over several containers so that no single client
// process limits the measure. Run by tests/Load/room.sh, as WORKERS workers and one coordinator (ROLE).
//   worker:      connects CLIENTS clients, of which ROOM join the room, then prints each delivery's latency
//   coordinator: waits until the server holds every client, sends ROOM_MESSAGES messages, one every ROOM_INTERVAL ms
// Every container shares the Docker VM's clock, so a message carries its send time and each member measures from it.
import { io } from "socket.io-client";

const URL = "http://127.0.0.1:3000";
const ROLE = process.env.ROLE;
const CLIENTS = Number(process.env.CLIENTS ?? 1000);
const ROOM = Number(process.env.ROOM ?? 100);
const TOTAL = Number(process.env.TOTAL ?? CLIENTS);
const TOTAL_ROOM = Number(process.env.TOTAL_ROOM ?? ROOM);
const ROOM_MESSAGES = Number(process.env.ROOM_MESSAGES ?? 100);
const ROOM_INTERVAL = Number(process.env.ROOM_INTERVAL ?? 50);
const SIZE = Number(process.env.SIZE ?? 100);
const OPTIONS = { transports: ["websocket"], forceNew: true, reconnection: false };

const next = (emitter, event) => new Promise((resolve) => emitter.once(event, (...args) => resolve(args)));
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const now = () => performance.timeOrigin + performance.now();

async function connect() {
  const socket = io(URL, OPTIONS);
  const [error] = await Promise.race([next(socket, "connect").then(() => [null]), next(socket, "connect_error")]);
  if (error) {
    throw new Error(`a client could not connect: ${error.message}`);
  }
  return socket;
}

if (ROLE === "worker") {
  const clients = [];
  for (let i = 0; i < CLIENTS; i += 100) {
    clients.push(...(await Promise.all(Array.from({ length: Math.min(100, CLIENTS - i) }, () => connect()))));
  }
  const members = clients.slice(0, ROOM);
  const latencies = [];
  const done = new Promise((resolve) => {
    for (const socket of members) {
      socket.on("room", (i, at) => {
        latencies.push(+(now() - at).toFixed(3));
        if (latencies.length === ROOM * ROOM_MESSAGES) {
          resolve();
        }
      });
    }
  });
  await Promise.all(members.map((socket) => socket.emitWithAck("join", "load-room")));
  await done;
  console.log(JSON.stringify(latencies));
  for (const socket of clients) {
    socket.disconnect();
  }
  process.exit(0);
}

const control = await connect();
for (;;) {
  const size = await control.emitWithAck("size", "load-room");
  if (size.sockets >= TOTAL + 1 && size.room >= TOTAL_ROOM) {
    break;
  }
  await sleep(500);
}
await sleep(1000);
for (let i = 0; i < ROOM_MESSAGES; i++) {
  control.emit("room", "load-room", i, SIZE, now());
  await sleep(ROOM_INTERVAL);
}
control.disconnect();
process.exit(0);
