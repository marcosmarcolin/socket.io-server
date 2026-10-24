#!/bin/sh
# Shared runner for the official compliance suites; sourced by engine-io.sh and socket-io.sh.
# Everything runs in Docker: the server in the php service, the suite in Node.
#
# The caller sets:
#   SUITE_DIR     the suite's directory in socketio/socket.io
#   SUITE_FILES   lines of "<sha256> <file>"
#   SERVER        the PHP server script, relative to the repository root
#   READY_PATH    a URL path that answers once the server is up
set -eu

# The suites at a fixed commit of socketio/socket.io. Moving to another commit means new checksums.
SUITE_COMMIT="1eaa582d3b453e3e6f522300ed05b10da0a0799b"
NODE_IMAGE="node:24.21.0-alpine"

root=$(git rev-parse --show-toplevel)
suite="$root/.cache/$(basename "$SUITE_DIR")/$SUITE_COMMIT"
url="https://raw.githubusercontent.com/socketio/socket.io/$SUITE_COMMIT/$SUITE_DIR"
mkdir -p "$suite"

echo "$SUITE_FILES" | while read -r sum file; do
    [ -n "$file" ] || continue
    if [ ! -f "$suite/$file" ]; then
        curl -fsSL "$url/$file" -o "$suite/$file"
    fi
    # Fails on a tampered or corrupted download.
    echo "$sum  $suite/$file" | sha256sum -c --quiet - 2>/dev/null \
        || echo "$sum  $suite/$file" | shasum -a 256 -c --quiet - \
        || { echo "compliance: checksum mismatch for $file" >&2; rm -f "$suite/$file"; exit 1; }
done

cd "$root"
server=$(docker compose run -d --rm php php "$SERVER" start)
trap 'docker stop -t 2 "$server" >/dev/null 2>&1 || true' EXIT

# The suites target localhost:3000, so Node shares the server container's network.
docker run --rm --network "container:$server" -v "$suite:/suite" -w /suite -e READY_PATH="$READY_PATH" \
    "$NODE_IMAGE" sh -c '
    ready=""
    for i in $(seq 1 50); do
        if wget -qO /dev/null "http://localhost:3000$READY_PATH"; then ready=1; break; fi
        sleep 0.2
    done
    [ -n "$ready" ] || { echo "compliance: the server did not start" >&2; exit 1; }
    npm ci --no-audit --no-fund --loglevel=error && npm test
' || { docker logs "$server" >&2; exit 1; }
