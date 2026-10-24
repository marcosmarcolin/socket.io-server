#!/bin/sh
# Runs the official Engine.IO v4 test suite against tests/Compliance/engine-io-server.php.
# Everything runs in Docker: the server in the php service, the suite in Node.
set -eu

# The suite at a fixed commit of socketio/socket.io, with the checksum of each file.
SUITE_COMMIT="1eaa582d3b453e3e6f522300ed05b10da0a0799b"
SUITE_URL="https://raw.githubusercontent.com/socketio/socket.io/$SUITE_COMMIT/docs/engine.io-protocol/v4-test-suite"
SUITE_FILES="
b58b920155ab14c14c78594f457cc8bc8e201f4e7c4400a53d78d483541cfbf7 node-imports.js
cd1619a120a3a169a3b1bf9cc1109d1d2632019f385f4cea89f49c5e36a6dbf3 package-lock.json
0b6cdf44127a89eaa5a315da7fd4f89871a118f1ea15b1546c00ad285a049254 package.json
3f4b32add9837659dcf48af0bb5b8b203b636784d856134046d85f391b15bc88 test-suite.js
"
NODE_IMAGE="node:24.21.0-alpine"

root=$(git rev-parse --show-toplevel)
suite="$root/.cache/engine.io-v4-suite/$SUITE_COMMIT"
mkdir -p "$suite"

echo "$SUITE_FILES" | while read -r sum file; do
    [ -n "$file" ] || continue
    if [ ! -f "$suite/$file" ]; then
        curl -fsSL "$SUITE_URL/$file" -o "$suite/$file"
    fi
    # Fails on a tampered or corrupted download.
    echo "$sum  $suite/$file" | sha256sum -c --quiet - 2>/dev/null \
        || echo "$sum  $suite/$file" | shasum -a 256 -c --quiet - \
        || { echo "engine-io.sh: checksum mismatch for $file" >&2; rm -f "$suite/$file"; exit 1; }
done

cd "$root"
server=$(docker compose run -d --rm php php tests/Compliance/engine-io-server.php start)
trap 'docker stop -t 2 "$server" >/dev/null 2>&1 || true' EXIT

# The suite targets localhost:3000, so Node shares the server container's network.
docker run --rm --network "container:$server" -v "$suite:/suite" -w /suite "$NODE_IMAGE" sh -c '
    ready=""
    for i in $(seq 1 50); do
        if wget -qO /dev/null "http://localhost:3000/engine.io/?EIO=4&transport=polling"; then ready=1; break; fi
        sleep 0.2
    done
    [ -n "$ready" ] || { echo "engine-io.sh: the server did not start" >&2; exit 1; }
    npm ci --no-audit --no-fund --loglevel=error && npm test
' || { docker logs "$server" >&2; exit 1; }
