# Namespaces

Two namespaces on one connection: an open `/public`, and an `/admin` that needs a token and can make announcements to `/public`.

## Run it

```sh
php examples/namespaces/start.php start
```

Open http://localhost:3001. Socket.IO listens on port 3000; the admin token is `secret`.

## Try

- Send messages on `/public`.
- Connect to `/admin` with a wrong token: the page shows the refusal and its hint. With `secret`, announce something: `/public` gets it, and the acknowledgement says how many sockets it reached.

## How it works

- `$io->of('/public')` and `$io->of('/admin')` are separate namespaces, each with its own `connection` listeners, sockets and rooms.
- `$admin->use()` runs before each connection to `/admin`; `$next(new ConnectionError('forbidden', [...]))` refuses it, and the client's `connect_error` gets the message and the `data`.
- Code in one namespace can emit to another: the `/admin` handler calls `$public->emit()`.

See [Namespaces](../../docs/api.md#namespaces-nsp) and [Errors](../../docs/api.md#errors).

## Test

```sh
tests/Integration/run.sh example namespaces
```

[`namespaces.test.js`](../../tests/Integration/client/examples/namespaces.test.js)
