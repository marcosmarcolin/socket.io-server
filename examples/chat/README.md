# Chat

A chat room: users pick a name, see who is typing, get each other's messages, and are told when someone joins or leaves. A user whose connection drops for a moment comes back with their name and room, and gets the messages sent meanwhile.

## Run it

```sh
php examples/chat/start.php start
```

Open http://localhost:3001 in two tabs. Socket.IO listens on port 3000.

## Try

- Join with a different name in each tab and type: the other tab shows "is typing".
- Stop the network for a few seconds (the browser's developer tools, *Network* → *Offline*), send a message from the other tab, then go back online: the page says "reconnected, nothing missed", shows the message, and the other tab sees "is back".

## How it works

| Client sends | Server does |
|---|---|
| `add user` (name) | keeps it in `$socket->data`, joins the `chat` room, answers `login` and tells the others `user joined` |
| `new message`, `typing`, `stop typing` | `$socket->broadcast->emit(...)`: everyone but the sender |
| disconnects | `user left`, with the room's size from `fetchSockets()` |

- `connectionStateRecovery` keeps a dropped socket for two minutes: its id, rooms and `data`, and the broadcasts it missed. On the way back `$socket->recovered` is true and the missed events arrive before `connect` on the client.
- The number of users is the size of the `chat` room, so recovered users count without extra bookkeeping.

See [Connection state recovery](../../docs/configuration.md#connection-state-recovery).

## Test

```sh
tests/Integration/run.sh example chat
```

[`chat.test.js`](../../tests/Integration/client/examples/chat.test.js) drives it with the official `socket.io-client`, a dropped connection included.
