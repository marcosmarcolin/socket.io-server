# Session authentication

Logging in with an ordinary PHP session cookie: the page logs in over HTTP, and a Socket.IO middleware lets in only the sockets whose cookie belongs to a logged-in session.

## Run it

```sh
php examples/session-auth/start.php start
```

Open http://localhost:3001; log in with one name, and with another in a private window.

## Try

- Before logging in, the socket is refused with "not logged in".
- Log in: the socket connects, `whoami` answers your name, and chat messages carry it.
- Log out: the next connection is refused again.

## How it works

- The page's worker (port 3001) is a plain Workerman HTTP app with `$request->session()`: `POST /login` stores the name, `POST /logout` flushes it.
- `$io->use()` reads the cookie from `$socket->handshake['headers']['cookie']`, opens the same session, and refuses with a `ConnectionError` when no user is in it; otherwise it keeps the name in `$socket->data`.
- The page is on another port than Socket.IO, so the browser sends the cookie only with `withCredentials` on the client and `cors.credentials` on the server; the `origin` `Closure` allows only pages on the same host.
- In your app, the middleware reads the store your PHP-FPM code writes (files, Redis...).

See [Cookies across origins](../../docs/configuration.md#cookies-across-origins).

## Test

```sh
tests/Integration/run.sh example session-auth
```

[`session-auth.test.js`](../../tests/Integration/client/examples/session-auth.test.js)
