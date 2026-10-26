// The load test: many WebSocket clients against tests/Load/server.php, measuring memory per connection, the time
// to connect, acknowledgement round trips and broadcast throughput. Run by tests/Load/run.sh.
import { io } from "socket.io-client";

const URL = "http://127.0.0.1:3000";
const CLIENTS = Number(process.env.CLIENTS ?? 500);
const ROUNDS = Number(process.env.ROUNDS ?? 20);
const MESSAGES = Number(process.env.MESSAGES ?? 50);
const SIZE = Number(process.env.SIZE ?? 100);
const OPTIONS = { transports: ["websocket"], forceNew: true, reconnection: false };

const next = (emitter, event) => new Promise((resolve) => emitter.once(event, (...args) => resolve(args)));
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const percentile = (sorted, p) => sorted[Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))];

async function connect() {
  const socket = io(URL, OPTIONS);
  const [error] = await Promise.race([next(socket, "connect").then(() => [null]), next(socket, "connect_error")]);
  if (error) {
    throw new Error(`a client could not connect: ${error.message}`);
  }
  return socket;
}

const control = await connect();
await sleep(500);
const before = await control.emitWithAck("stats");

// Connects in batches, as many clients arriving at once.
const clients = [];
const connectStart = performance.now();
for (let i = 0; i < CLIENTS; i += 100) {
  const batch = Math.min(100, CLIENTS - i);
  clients.push(...(await Promise.all(Array.from({ length: batch }, () => connect()))));
}
const connectMs = performance.now() - connectStart;
await sleep(1000);
const after = await control.emitWithAck("stats");

// Every client acknowledges ROUNDS echoes, one after the other, all clients at once.
const latencies = [];
const echoStart = performance.now();
await Promise.all(
  clients.map(async (socket) => {
    for (let round = 0; round < ROUNDS; round++) {
      const start = performance.now();
      await socket.emitWithAck("echo", round);
      latencies.push(performance.now() - start);
    }
  }),
);
const echoMs = performance.now() - echoStart;
latencies.sort((a, b) => a - b);

// One broadcast burst: MESSAGES events to every client.
let received = 0;
const expected = CLIENTS * MESSAGES;
const allReceived = new Promise((resolve) => {
  for (const socket of clients) {
    socket.on("flood", () => {
      if (++received === expected) {
        resolve();
      }
    });
  }
});
const floodStart = performance.now();
const emitMicros = await control.emitWithAck("flood", MESSAGES, SIZE);
await allReceived;
const floodMs = performance.now() - floodStart;

const result = {
  loop: after.loop,
  clients: CLIENTS,
  heapPerConnection: Math.round((after.heap - before.heap) / CLIENTS),
  rssPerConnection: Math.round((after.rss - before.rss) / CLIENTS),
  rssTotalMb: +(after.rss / 1048576).toFixed(1),
  connectMs: Math.round(connectMs),
  echo: {
    rounds: ROUNDS,
    perSecond: Math.round(latencies.length / (echoMs / 1000)),
    p50: +percentile(latencies, 50).toFixed(2),
    p99: +percentile(latencies, 99).toFixed(2),
  },
  broadcast: {
    messages: MESSAGES,
    size: SIZE,
    deliveries: expected,
    serverEmitMs: Math.round(emitMicros / 1000),
    allDeliveredMs: Math.round(floodMs),
    deliveriesPerSecond: Math.round(expected / (floodMs / 1000)),
  },
};
console.log(JSON.stringify(result));
console.log(
  `${CLIENTS} clients on ${result.loop}: ${result.heapPerConnection} B of PHP heap and ` +
    `${result.rssPerConnection} B of RSS per connection (${result.rssTotalMb} MB total), connected in ` +
    `${result.connectMs} ms; ${result.echo.perSecond} echoes/s, p50 ${result.echo.p50} ms, p99 ${result.echo.p99} ms; ` +
    `${expected} deliveries in ${result.broadcast.allDeliveredMs} ms (${result.broadcast.deliveriesPerSecond}/s)`,
);
for (const socket of [control, ...clients]) {
  socket.disconnect();
}
process.exit(0);
