# Admin UI

The chat, instrumented for the official [Socket.IO Admin UI](https://socket.io/docs/v4/admin-ui/): watch its sockets, rooms and events live, and act on them.

## Run it

```sh
php examples/admin-ui/start.php start
```

- Open http://localhost:3001 in two tabs to chat.
- Open https://admin.socket.io with the server URL `http://localhost:3000`, the username `admin` and the password `admin` (or `ADMIN_PASSWORD`).

`DEBUG=1 php examples/admin-ui/start.php start` logs each connection's life to the console.

## Try

- Join the chat: the socket shows up in *Sockets*, with its name in `data`, and the room count moves.
- Send messages: *Events* lists them.
- From the UI, disconnect a socket, make it join a room, or emit to it.

## How it works

- `AdminUi::instrument($io, ['auth' => [...]])` adds the `/admin` namespace the UI talks to. The password is stored only as a bcrypt hash (`password_hash()`).
- `cors` allows the chat page and the UI's origins with `credentials`.
- In development mode (the default) it reports every socket, room and event; `mode => 'production'` sends only the aggregated numbers.

See [Admin UI](../../docs/admin-ui.md).

## Test

```sh
tests/Integration/run.sh example admin-ui
```

[`admin-ui.test.js`](../../tests/Integration/client/examples/admin-ui.test.js)
