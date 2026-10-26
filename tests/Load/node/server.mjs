// The reference Node.js server with the handlers of tests/Load/server.php, for a comparison on the same machine.
// Run by tests/Load/run.sh with SERVER=node: node --expose-gc server.mjs
import { Server } from "socket.io";

const io = new Server(3000, { pingInterval: 25000, pingTimeout: 20000 });

io.on("connection", (socket) => {
  socket.on("echo", (data, ack) => ack(data));
  socket.on("stats", (ack) => {
    globalThis.gc();
    const memory = process.memoryUsage();
    ack({ heap: memory.heapUsed + memory.external, rss: memory.rss, sockets: io.of("/").sockets.size, loop: "Node.js" });
  });
  // Broadcasts numbered messages to every socket, then says how long the emits took.
  socket.on("flood", (count, size, ack) => {
    const payload = "x".repeat(size);
    const start = process.hrtime.bigint();
    for (let i = 0; i < count; i++) {
      io.emit("flood", i, payload);
    }
    ack(Number((process.hrtime.bigint() - start) / 1000n));
  });
});
