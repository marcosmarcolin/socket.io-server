# Acknowledgements

Request and response over Socket.IO, both ways: the client asks the server and gets an answer, and the server asks the client and gives up after a timeout.

## Run it

```sh
php examples/acks/start.php start
```

Open http://localhost:3001. Socket.IO listens on port 3000.

## Try

- Add up a few numbers: the server answers through the acknowledgement.
- Let the server ask you: the page answers "blue" at once. Untick *answer the server* and ask again: after 2 seconds the server gives up with "operation has timed out".

## How it works

| Direction | Code |
|---|---|
| client asks | the client calls `socket.emitWithAck("sum", numbers)`; the server's listener gets a `Closure $ack` last and calls it once |
| server asks | `$socket->timeout($ms)->emit('question', $text, function (?Throwable $error, mixed $answer) {...})`; the callback gets a `TimeoutException` if the client doesn't answer in time |

See [Acknowledgements](../../docs/api.md#acknowledgements).

## Test

```sh
tests/Integration/run.sh example acks
```

[`acks.test.js`](../../tests/Integration/client/examples/acks.test.js)
