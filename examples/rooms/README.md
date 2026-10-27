# Rooms

Join and leave rooms, message the other members of a room, and watch two live lists: the members of each room, and the rooms that exist.

## Run it

```sh
php examples/rooms/start.php start
```

Open http://localhost:3001 in two tabs. Socket.IO listens on port 3000.

## Try

- Join `php` in both tabs and send a message: only the other member gets it.
- Join a new room in one tab: the room list of every tab shows it. Leave it: it disappears with its last member.
- Close a tab: the member lists of its rooms drop it.

## How it works

| Client sends | Server does |
|---|---|
| `join room` (room, ack) | `$socket->join()`, answers with `$socket->rooms`, sends `members` to the room |
| `leave room` (room, ack) | `$socket->leave()`, the same |
| `room message` (room, text) | `$socket->to($room)->emit(...)`: the room's members, not the sender |

- The member list comes from `$io->in($room)->fetchSockets()`.
- The room list comes from the adapter's room events: `create-room` and `delete-room` fire when a room gets its first member and loses its last. Each socket's own room, named after its id, is left out.
- `disconnecting` still sees the socket's rooms; by `disconnect` it has left them.

See [Broadcasting](../../docs/api.md#broadcasting-broadcastoperator) and [Adapter events](../../docs/api.md#adapter-events).

## Test

```sh
tests/Integration/run.sh example rooms
```

[`rooms.test.js`](../../tests/Integration/client/examples/rooms.test.js)
