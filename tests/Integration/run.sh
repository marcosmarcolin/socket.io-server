#!/bin/sh
# Runs the integration tests of tests/Integration/client (the official socket.io-client) against
# tests/Integration/server.php. Everything runs in Docker: the server in the php service, the client in Node.
set -eu

# Keep in sync with tests/Compliance/suite.sh.
NODE_IMAGE="node:24.21.0-alpine"

root=$(git rev-parse --show-toplevel)
cd "$root"
server=$(docker compose run -d --rm php php tests/Integration/server.php start)
trap 'docker stop -t 2 "$server" >/dev/null 2>&1 || true' EXIT

# The client is mounted read-only and installed in a copy, so node_modules never lands in the repository.
docker run --rm --network "container:$server" -v "$root/tests/Integration/client:/client:ro" "$NODE_IMAGE" sh -c '
    ready=""
    for i in $(seq 1 50); do
        if wget -qO /dev/null "http://localhost:3000/socket.io/?EIO=4&transport=polling"; then ready=1; break; fi
        sleep 0.2
    done
    [ -n "$ready" ] || { echo "integration: the server did not start" >&2; exit 1; }
    cp -r /client /work && cd /work
    npm ci --ignore-scripts --no-audit --no-fund --loglevel=error && npm test
' || { docker logs "$server" >&2; exit 1; }
