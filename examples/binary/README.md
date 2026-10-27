# Binary

Files over Socket.IO: the page uploads a file and gets its size and SHA-256 back, and downloads bytes the server makes.

## Run it

```sh
php examples/binary/start.php start
```

Open http://localhost:3001. Socket.IO listens on port 3000.

## Try

- Upload a file of up to a few megabytes, and compare the hash with `shasum -a 256 <file>`.
- Download some random bytes.

## How it works

- Binary values arrive as `Marcolin\SocketIO\Binary`, wherever they are in the data (`$file['data']->data` holds the bytes).
- A `Binary` anywhere in what the server sends goes as a binary attachment, and the client gets an `ArrayBuffer`.
- `maxPayload` is raised to 5 MB, since a file arrives in one message.

See [Data: JSON and binary](../../docs/api.md#data-json-and-binary).

## Test

```sh
tests/Integration/run.sh example binary
```

[`binary.test.js`](../../tests/Integration/client/examples/binary.test.js)
