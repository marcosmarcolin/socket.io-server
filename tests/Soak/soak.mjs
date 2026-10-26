// The soak test: clients that come and go for DURATION seconds against tests/Soak/server.php, doing what real ones
// do, while each checks the numbered broadcast for lost, repeated or reordered messages. Run by tests/Soak/run.sh.
import { io } from "socket.io-client";

const URL = "http://127.0.0.1:3000";
const CLIENTS = Number(process.env.CLIENTS ?? 2000);
const DURATION = Number(process.env.DURATION ?? 300) * 1000;
const ROOMS = 50;

const counts = {
  connects: 0, cleanLeaves: 0, drops: 0, recovered: 0, notRecovered: 0, ticks: 0,
  lost: 0, repeated: 0, reordered: 0, acks: 0, badEchoes: 0, ackTimeouts: 0, binaries: 0, badBinaries: 0, connectErrors: 0,
};
const sockets = new Set();
const random = (min, max) => min + Math.floor(Math.random() * (max - min + 1));
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const started = Date.now();
const running = () => Date.now() - started < DURATION;

// One client's lives, one after another, until the run ends.
async function client() {
  while (running()) {
    await life();
    await sleep(random(1000, 5000));
  }
}

function life() {
  return new Promise((resolve) => {
    const roll = Math.random();
    const transports = roll < 0.7 ? ["websocket"] : roll < 0.85 ? ["polling"] : ["polling", "websocket"];
    const socket = io(URL, { forceNew: true, transports, reconnectionDelay: 200, reconnectionDelayMax: 1000 });
    sockets.add(socket);
    let last = null;
    let dropped = false;
    let timers = [];

    socket.on("tick", (sequence) => {
      counts.ticks++;
      if (last !== null) {
        if (sequence === last) counts.repeated++;
        else if (sequence < last) counts.reordered++;
        else if (sequence > last + 1) counts.lost += sequence - last - 1;
      }
      last = sequence;
    });
    socket.on("connect", () => {
      counts.connects++;
      if (dropped) {
        // Recovered: the missed ticks came before this event, so the count goes on. Otherwise it starts over.
        socket.recovered ? counts.recovered++ : counts.notRecovered++;
        dropped = false;
      }
      if (!socket.recovered) {
        last = null;
        for (let i = 0; i < random(1, 3); i++) socket.emit("join", `room-${random(1, ROOMS)}`);
      }
    });
    socket.on("connect_error", () => counts.connectErrors++);

    const act = async () => {
      if (!running() || !socket.active) return;
      const action = Math.random();
      try {
        if (action < 0.5) {
          const payload = { n: random(1, 1e6), text: "x".repeat(random(1, 500)) };
          const answer = await socket.timeout(10_000).emitWithAck("echo", payload);
          answer.n === payload.n ? counts.acks++ : counts.badEchoes++;
        } else if (action < 0.7) {
          socket.emit("join", `room-${random(1, ROOMS)}`);
        } else if (action < 0.85) {
          socket.emit("leave", `room-${random(1, ROOMS)}`);
        } else {
          const bytes = Buffer.from(Array.from({ length: random(1, 2000) }, () => random(0, 255)));
          const answer = await socket.timeout(10_000).emitWithAck("binary", bytes);
          Buffer.from(answer).equals(Buffer.from(bytes).reverse()) ? counts.binaries++ : counts.badBinaries++;
        }
      } catch {
        counts.ackTimeouts++;
      }
      timers.push(setTimeout(act, random(1000, 4000)));
    };
    timers.push(setTimeout(act, random(500, 2000)));

    // Some lives drop their connection on the way, to recover it; then each ends, cleanly.
    if (Math.random() < 0.3) {
      timers.push(setTimeout(() => {
        if (socket.connected) {
          dropped = true;
          counts.drops++;
          socket.io.engine.close();
        }
      }, random(5_000, 20_000)));
    }
    timers.push(setTimeout(() => {
      timers.forEach(clearTimeout);
      counts.cleanLeaves++;
      socket.disconnect();
      sockets.delete(socket);
      resolve();
    }, Math.min(random(10_000, 60_000), Math.max(0, DURATION - (Date.now() - started)))));
  });
}

const report = setInterval(() => {
  console.log(`soak ${Math.round((Date.now() - started) / 1000)}s ${JSON.stringify({ open: sockets.size, ...counts })}`);
}, 60_000);

// Clients arrive over the first ten seconds.
await Promise.all(Array.from({ length: CLIENTS }, async () => {
  await sleep(random(0, 10_000));
  return client();
}));
clearInterval(report);
for (const socket of sockets) socket.disconnect();

const failed = counts.lost + counts.repeated + counts.reordered + counts.badEchoes + counts.badBinaries > 0;
console.log(`SOAK RESULT ${JSON.stringify({ ...counts, failed })}`);
process.exit(failed ? 1 : 0);
