#!/bin/sh
# Runs tests written with the official socket.io-client (tests/Integration/client) in Docker: the server in the
# php service, the client in Node.
#   tests/Integration/run.sh                  one process: tests/Integration/server.php
#   tests/Integration/run.sh multi-process    two processes and a channel server: multi-process-server.php
#   tests/Integration/run.sh example <name>   the example in examples/<name>
#   tests/Integration/run.sh examples         every example, one after the other
set -eu

if [ "${1:-}" = "examples" ]; then
    for dir in "$(git rev-parse --show-toplevel)"/examples/*/; do
        "$0" example "$(basename "$dir")"
    done
    exit 0
fi

case "${1:-single}" in
    single) server_script="tests/Integration/server.php"; test_file="integration.test.js" ;;
    multi-process) server_script="tests/Integration/multi-process-server.php"; test_file="multi-process.test.js" ;;
    example)
        [ -n "${2:-}" ] || { echo "usage: $0 example <name>" >&2; exit 2; }
        server_script="examples/$2/start.php"; test_file="examples/$2.test.js" ;;
    *) echo "usage: $0 [single|multi-process|example <name>|examples]" >&2; exit 2 ;;
esac
echo "integration: $server_script"

# Keep in sync with tests/Compliance/suite.sh.
NODE_IMAGE="node:24.21.0-alpine"

root=$(git rev-parse --show-toplevel)
cd "$root"
server=$(docker compose run -d --rm php php "$server_script" start)
trap 'docker stop -t 2 "$server" >/dev/null 2>&1 || true' EXIT

# The client is mounted read-only and installed in a copy, so node_modules never lands in the repository.
docker run --rm --network "container:$server" -v "$root/tests/Integration/client:/client:ro" \
    -e TEST_FILE="$test_file" "$NODE_IMAGE" sh -c '
    ready=""
    for i in $(seq 1 50); do
        if wget -qO /dev/null "http://127.0.0.1:3000/socket.io/?EIO=4&transport=polling"; then ready=1; break; fi
        sleep 0.2
    done
    [ -n "$ready" ] || { echo "integration: the server did not start" >&2; exit 1; }
    cp -r /client /work && cd /work
    npm ci --ignore-scripts --no-audit --no-fund --loglevel=error && node --test --test-concurrency=1 "$TEST_FILE"
' || { docker logs "$server" >&2; exit 1; }
