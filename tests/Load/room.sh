#!/bin/sh
# The latency of broadcasts to a room, with the clients spread over WORKERS containers (tests/Load/room.mjs):
#   CLIENTS=10000 ROOM=1000 WORKERS=10 LOOP=event tests/Load/room.sh
#   SERVER=node CLIENTS=10000 ROOM=1000 WORKERS=10 tests/Load/room.sh
# ROOM_MESSAGES messages of SIZE bytes go to the room, one every ROOM_INTERVAL ms.
set -eu

# Keep in sync with tests/Integration/run.sh.
NODE_IMAGE="node:24.21.0-alpine"

CLIENTS="${CLIENTS:-10000}"
ROOM="${ROOM:-1000}"
WORKERS="${WORKERS:-10}"
ROOM_MESSAGES="${ROOM_MESSAGES:-100}"

root=$(git rev-parse --show-toplevel)
cd "$root"
if [ "${SERVER:-php}" = "node" ]; then
    server=$(docker run -d --rm --ulimit nofile=65536:65536 -v "$root/tests/Load/node:/reference:ro" "$NODE_IMAGE" \
        sh -c 'cp -r /reference /server && cd /server && npm ci --ignore-scripts --no-audit --no-fund --loglevel=error \
            && exec node --expose-gc server.mjs')
else
    . "$root/tests/php-server.sh"
    server=$(php_server -d memory_limit=1G tests/Load/server.php start)
fi
workers=""
deps=$(mktemp -d)
out=$(mktemp)
trap 'docker rm -f "$server" $workers >/dev/null 2>&1 || true; rm -rf "$deps" "$out"' EXIT

# One copy of the client's dependencies, installed once and mounted read-only by every container.
docker run --rm -v "$root/tests/Integration/client:/client:ro" -v "$deps:/work" "$NODE_IMAGE" sh -c '
    cp /client/package.json /client/package-lock.json /work/ && cd /work
    npm ci --ignore-scripts --no-audit --no-fund --loglevel=error' >/dev/null
cp tests/Load/room.mjs "$deps/"

client() {
    docker run "$@" --network "container:$server" --ulimit nofile=65536:65536 -v "$deps:/work:ro" -w /work \
        -e CLIENTS=$((CLIENTS / WORKERS)) -e ROOM=$((ROOM / WORKERS)) -e TOTAL="$CLIENTS" -e TOTAL_ROOM="$ROOM" \
        -e ROOM_MESSAGES="$ROOM_MESSAGES" -e ROOM_INTERVAL="${ROOM_INTERVAL:-50}" -e SIZE="${SIZE:-100}" \
        "$NODE_IMAGE" node room.mjs
}

ready=""
for i in $(seq 1 150); do
    if \
        docker run --rm --network "container:$server" "$NODE_IMAGE" \
            wget -qO /dev/null "http://127.0.0.1:3000/socket.io/?EIO=4&transport=polling" 2>/dev/null; then
        ready=1; break
    fi
    sleep 0.2
done
[ -n "$ready" ] || { echo "room: the server did not start" >&2; docker logs "$server" >&2; exit 1; }

for i in $(seq 1 "$WORKERS"); do
    workers="$workers $(client -d -e ROLE=worker)"
done
client --rm -e ROLE=coordinator

for worker in $workers; do
    [ "$(docker wait "$worker")" = 0 ] || { docker logs "$worker" >&2; exit 1; }
    docker logs "$worker" >> "$out"
    docker rm "$worker" >/dev/null
done
workers=""
docker run --rm -v "$out:/latencies:ro" "$NODE_IMAGE" node -e '
    const all = require("fs").readFileSync("/latencies", "utf8").trim().split("\n").flatMap((line) => JSON.parse(line));
    all.sort((a, b) => a - b);
    const p = (q) => all[Math.min(all.length - 1, Math.floor((q / 100) * all.length))].toFixed(2);
    console.log(`${all.length} deliveries: p50 ${p(50)} ms, p90 ${p(90)} ms, p99 ${p(99)} ms, max ${all[all.length - 1].toFixed(2)} ms`);
'
