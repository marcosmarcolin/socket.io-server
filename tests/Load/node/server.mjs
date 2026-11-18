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
  socket.on("join", (room, ack) => {
    socket.join(room);
    ack(true);
  });
  // One message to the members of a room, with its send time, for the latency of a room broadcast.
  socket.on("room", (room, i, size, at) => {
    io.to(room).emit("room", i, at, "x".repeat(size));
  });
  socket.on("size", (room, ack) => {
    ack({ sockets: io.of("/").sockets.size, room: io.of("/").adapter.rooms.get(room)?.size ?? 0 });
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
