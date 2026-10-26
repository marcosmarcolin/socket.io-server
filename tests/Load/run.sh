#!/bin/sh
# Runs the load test in Docker: tests/Load/server.php in PHP, tests/Load/load.mjs with the official client in Node.
#   tests/Load/run.sh                      500 clients on Workerman's select() loop, the default image
#   LOOP=event CLIENTS=5000 tests/Load/run.sh   the event extension, past select()'s 1024 connections
#   SERVER=node CLIENTS=5000 tests/Load/run.sh  the reference Node.js server (tests/Load/node), for comparison
# CLIENTS, ROUNDS (echoes per client), MESSAGES (broadcasts) and SIZE (bytes per broadcast) tune it.
set -eu

# Keep in sync with tests/Integration/run.sh.
NODE_IMAGE="node:24.21.0-alpine"

root=$(git rev-parse --show-toplevel)
cd "$root"
if [ "${SERVER:-php}" = "node" ]; then
    # The reference server, from its locked dependencies, mounted read-only and installed in a copy.
    server=$(docker run -d --rm --ulimit nofile=65536:65536 -v "$root/tests/Load/node:/reference:ro" "$NODE_IMAGE" \
        sh -c 'cp -r /reference /server && cd /server && npm ci --ignore-scripts --no-audit --no-fund --loglevel=error \
            && exec node --expose-gc server.mjs')
elif [ "${LOOP:-select}" = "event" ]; then
    image=socket-io-load-event
    docker build -q -t "$image" tests/Load >/dev/null
    server=$(docker run -d --rm --ulimit nofile=65536:65536 -v "$root:/app" -w /app "$image" \
        php -d memory_limit=1G tests/Load/server.php start)
else
    server=$(docker compose run -d --rm php php -d memory_limit=1G tests/Load/server.php start)
fi
trap 'docker stop -t 2 "$server" >/dev/null 2>&1 || true' EXIT

docker run --rm --network "container:$server" --ulimit nofile=65536:65536 \
    -v "$root/tests/Integration/client:/client:ro" -v "$root/tests/Load:/load:ro" \
    -e CLIENTS="${CLIENTS:-500}" -e ROUNDS="${ROUNDS:-20}" -e MESSAGES="${MESSAGES:-50}" -e SIZE="${SIZE:-100}" \
    "$NODE_IMAGE" sh -c '
    ready=""
    for i in $(seq 1 150); do
        if wget -qO /dev/null "http://127.0.0.1:3000/socket.io/?EIO=4&transport=polling"; then ready=1; break; fi
        sleep 0.2
    done
    [ -n "$ready" ] || { echo "load: the server did not start" >&2; exit 1; }
    cp -r /client /work && cp /load/load.mjs /work/ && cd /work
    npm ci --ignore-scripts --no-audit --no-fund --loglevel=error && node load.mjs
' || { docker logs "$server" >&2; exit 1; }
