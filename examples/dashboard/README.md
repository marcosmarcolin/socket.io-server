# Dashboard

Live dashboards: a server timer pushes fresh numbers every few seconds to the pages watching each dashboard, and to nobody else.

## Run it

```sh
php examples/dashboard/start.php start
```

Open http://localhost:3001 in a few tabs and pick a dashboard. `DASHBOARD_INTERVAL` sets the seconds between updates (2 by default).

## Try

- Watch `server` in one tab and `sales` in another: each gets only its own updates.
- Open more tabs on `server`: its connection count goes up.

## How it works

- Each dashboard is a room, `dashboard:<name>`. `watch` (name, ack) leaves the other rooms, joins this one, and answers with the numbers right away instead of waiting for the next tick.
- A Workerman timer, started in `workerStart`, reads only the dashboards someone watches (`$io->in($room)->allSockets() !== []`) and sends `update` with `volatile`: a page that can't keep up skips one instead of queueing old numbers.
- `sales` is made up, as a database query would return it; `server` reads this process.

## Test

```sh
tests/Integration/run.sh example dashboard
```

[`dashboard.test.js`](../../tests/Integration/client/examples/dashboard.test.js)
