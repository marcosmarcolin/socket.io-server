import { test } from "node:test";
import assert from "node:assert/strict";
import { assertPageServed, client, next, record, sleep, TIMEOUT } from "./helpers.js";

// The example's timer ticks every 2 seconds.
const TICK = 2_000;

test("serves the page", TIMEOUT, assertPageServed);

test("lists the dashboards and answers a watch with its numbers at once", TIMEOUT, async () => {
  const socket = client();
  const [names] = await next(socket, "dashboards");
  assert.deepEqual(names, ["server", "sales"]);

  const snapshot = await socket.emitWithAck("watch", "server");
  assert.equal(snapshot.dashboard, "server");
  assert.deepEqual(Object.keys(snapshot.metrics), ["connections", "memoryMb", "uptimeSeconds"]);
  assert.ok(snapshot.metrics.connections >= 1);
  assert.equal(snapshot.chart.metric, "memoryMb");
  assert.ok(snapshot.chart.points.length >= 1);

  assert.deepEqual(await socket.emitWithAck("watch", "nope"), { error: "unknown dashboard" });
});

test("the timer pushes updates to the viewers of each dashboard only", TIMEOUT, async () => {
  const server = client();
  const sales = client();
  const idle = client();
  await Promise.all([next(server, "dashboards"), next(sales, "dashboards"), next(idle, "dashboards")]);
  await server.emitWithAck("watch", "server");
  const first = await sales.emitWithAck("watch", "sales");
  const toServer = record(server, "update");
  const toSales = record(sales, "update");
  const toIdle = record(idle, "update");

  await sleep(TICK * 2 + 500);

  assert.ok(toServer.length >= 2, `server viewer got ${toServer.length} updates`);
  assert.ok(toSales.length >= 2, `sales viewer got ${toSales.length} updates`);
  assert.ok(toServer.every(([update]) => update.dashboard === "server"));
  assert.ok(toSales.every(([update]) => update.dashboard === "sales"));
  assert.deepEqual(toIdle, []);

  // Each update is newer and adds a point to the chart.
  const [last] = toSales.at(-1);
  assert.ok(last.time > first.time);
  assert.ok(last.chart.points.length > first.chart.points.length);
  assert.ok(last.metrics.ordersPerMinute >= 20 && last.metrics.ordersPerMinute <= 60);
});

test("switching dashboards stops the old one's updates", TIMEOUT, async () => {
  const socket = client();
  await next(socket, "dashboards");
  await socket.emitWithAck("watch", "server");
  await socket.emitWithAck("watch", "sales");
  const updates = record(socket, "update");

  await sleep(TICK + 500);

  assert.ok(updates.length >= 1);
  assert.ok(updates.every(([update]) => update.dashboard === "sales"));
});
