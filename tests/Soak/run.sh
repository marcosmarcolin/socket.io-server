#!/bin/sh
# Runs the soak test in Docker: tests/Soak/server.php on the event extension, and tests/Soak/soak.mjs with the
# official client, for DURATION seconds (300 by default). Then every client leaves and the server's last sample
# shows what it still holds.
#   DURATION=2400 CLIENTS=2000 tests/Soak/run.sh
set -eu

# Keep in sync with tests/Integration/run.sh.
NODE_IMAGE="node:24.21.0-alpine"

root=$(git rev-parse --show-toplevel)
cd "$root"
. "$root/tests/php-server.sh"
server=$(LOOP="${LOOP:-event}" php_server -e RECOVERY="${RECOVERY:-1}" -e TICKS="${TICKS:-1}" -d memory_limit=1G tests/Soak/server.php start)
trap 'docker stop -t 2 "$server" >/dev/null 2>&1 || true' EXIT

status=0
docker run --rm --network "container:$server" --ulimit nofile=65536:65536 \
    -v "$root/tests/Integration/client:/client:ro" -v "$root/tests/Soak:/soak:ro" \
    -e CLIENTS="${CLIENTS:-2000}" -e DURATION="${DURATION:-300}" "$NODE_IMAGE" sh -c '
    for i in $(seq 1 150); do
        wget -qO /dev/null "http://127.0.0.1:3000/socket.io/?EIO=4&transport=polling" && break
        sleep 0.2
    done
    cp -r /client /work && cp /soak/soak.mjs /work/ && cd /work
    npm ci --ignore-scripts --no-audit --no-fund --loglevel=error >/dev/null && node soak.mjs
    code=$?
    # The clients are gone: the last sample shows what the server still holds.
    sleep 5
    node -e "const s = require(\"socket.io-client\").io(\"http://127.0.0.1:3000\", { transports: [\"websocket\"] });
        s.emitWithAck(\"sample\").then(() => { s.disconnect(); process.exit(0); });"
    exit $code
' || status=$?

# One line per sample: memory, sessions, sockets, rooms, recovery state and timers over time.
docker logs "$server" 2>&1 | grep '^SOAK ' | sed 's/^SOAK //'
exit $status
