# Admin UI

The official [Socket.IO Admin UI](https://socket.io/docs/v4/admin-ui/) works with this server: a dashboard of the servers and clients connected, the details of each socket and room, a live log of the events, and actions to make sockets join or leave rooms, disconnect them or emit to them.

The UI is the official one. This package provides its server side, as the reference's `instrument()` from `@socket.io/admin-ui` 0.5: an admin namespace that sends the UI what it shows and runs its actions.

## Setting it up

```php
use Marcolin\SocketIO\Admin\AdminUi;
use Marcolin\SocketIO\SocketIO;
use Workerman\Worker;

$io = new SocketIO(3000, [
    // The UI connects from its own origin and logs in with credentials.
    'cors' => ['origin' => ['https://admin.socket.io'], 'credentials' => true],
]);

AdminUi::instrument($io, [
    'auth' => [
        'type' => 'basic',
        'username' => 'admin',
        'password' => '$2y$10$...',                   // password_hash('your password', PASSWORD_BCRYPT)
    ],
]);

Worker::runAll();
```

Then open the UI, enter the server URL (`http://localhost:3000`, say) and the credentials. Its advanced options keep their defaults: the path `/socket.io` and the namespace `/admin`.

Call `instrument()` once the server has its worker, such as right after `new SocketIO($port)` or `attach()`: the stats start with each worker.

## Opening the UI

The UI is a static page that runs in the admin's browser and connects straight to your server. Either:

- use **https://admin.socket.io**, hosted by the Socket.IO project, which only serves the page: the data goes from your server to your browser;
- or **host it yourself**: the files are in the `ui/dist` folder of the `@socket.io/admin-ui` npm package, and any static web server can serve them, such as nginx next to your app. Allow its origin in `cors`.

Either way the server's `cors` option must allow the UI's origin with `credentials`, and with several processes on one port the UI connects over WebSocket only (its "WebSocket only?" option), as any client.

## Options

| Option | Default | Meaning |
|---|---|---|
| `auth` | required | `false` to let anyone in (use with care), or `['type' => 'basic', 'username' => ..., 'password' => ...]` with a bcrypt hash from `password_hash()`, never the password itself. |
| `namespaceName` | `'/admin'` | The admin namespace; the UI's "namespace" option must match. |
| `readonly` | `false` | With `true`, the UI can look but not act: no emit, join, leave or disconnect. |
| `serverId` | `hostname#pid` | How the server appears in the UI. The pid tells apart the workers of one host. |
| `store` | `new InMemoryStore()` | Where login sessions are kept, so a reconnecting UI doesn't ask for the password again. With several servers, implement `Admin\Store` on a shared database so a session opened on one works on the others. |
| `mode` | `'development'` | `'production'` sends only the server stats and the aggregated counts, not each socket and event, which costs memory and traffic on a busy server. |

## What it shows

- **Server stats**, every 2 seconds from each server: its id, host, pid, uptime, sessions (and how many on polling), the sockets of each namespace, and per second the connections, disconnections by reason, packets and bytes in and out.
- **In development mode**, also every socket (transport, handshake without the `auth` payload, rooms, `data`), and live: connections, disconnections with their reason, rooms joined and left, and every event received and sent.
- **Actions** (unless `readonly`): emit to a namespace or a room, make sockets join or leave a room, disconnect them, on every server the adapter reaches. They act on namespaces the server already has.

With `ChannelAdapter`, `RedisAdapter` or `RedisStreamsAdapter`, the admin namespace reaches every process like any other, so one UI sees the whole cluster.

## Things to know

- In development mode, each socket's `$socket->data['_admin']` holds what the UI reads (its session id prefix and transport), as in the reference. Replacing `$socket->data` as a whole drops it, and the UI then shows less about that socket.
- The UI is told about a socket's `data` when it connects. The reference also reports later changes, through a JavaScript `Proxy` PHP has no equivalent of; the UI shows them the next time it loads the sockets.
- Every event of every socket also goes to the admins in development mode: use `production` on busy servers.

See the [admin-ui example](../examples/admin-ui): a chat instrumented for the UI.
