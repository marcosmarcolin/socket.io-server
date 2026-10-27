# Private messaging

Private messages between users, to every tab of the recipient and of the sender, with who is online. A session id keeps a user across reconnections and reloads.

## Run it

```sh
php examples/private-messaging/start.php start
```

Open http://localhost:3001 in a few tabs and private windows, with different names.

## Try

- Open two tabs as the same user: a message sent from one shows in both, and in every tab of the recipient.
- Reload a tab: it comes back as the same user, without asking the name.
- Close every tab of a user: the others see them go offline; one tab closing isn't enough.

## How it works

- A namespace middleware (`$io->use()`) reads `auth`: a known `sessionId` restores its user, otherwise a `username` starts a new session. The client keeps the session it gets in `localStorage`.
- Each user has a room, `user:<id>`, joined by every tab. `$socket->to('user:' . $to)->to('user:' . $me)->emit(...)` reaches every tab of both users, each once.
- "Online" means the user's room isn't empty: `$io->in($room)->fetchSockets()`. The first tab announces `user connected`, the last one's `disconnect` sends `user disconnected`.
- The users live in the process's memory. With several processes, keep them in Redis or a database, and count the tabs with `fetchSockets()` with a callback.

See [Broadcasting](../../docs/api.md#broadcasting-broadcastoperator).

## Test

```sh
tests/Integration/run.sh example private-messaging
```

[`private-messaging.test.js`](../../tests/Integration/client/examples/private-messaging.test.js)
