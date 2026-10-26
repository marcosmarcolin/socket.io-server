#!/bin/sh
# Runs tests written with the official socket.io-client (tests/Integration/client) in Docker: the server in the
# php service, the client in Node.
#   tests/Integration/run.sh                  one process: tests/Integration/server.php
#   tests/Integration/run.sh multi-process    two processes and a channel server: multi-process-server.php
#   tests/Integration/run.sh multi-process-redis  the same two processes, joined by a Redis server over TLS
#   tests/Integration/run.sh example <name>   the example in examples/<name>
#   tests/Integration/run.sh examples         every example, one after the other
# LOOP=select|event|swoole runs the server on that Workerman event loop (tests/php-server.sh).
set -eu

if [ "${1:-}" = "examples" ]; then
    for dir in "$(git rev-parse --show-toplevel)"/examples/*/; do
        "$0" example "$(basename "$dir")"
    done
    exit 0
fi

redis=""
tls=""
case "${1:-single}" in
    single) server_script="tests/Integration/server.php"; test_file="integration.test.js" ;;
    multi-process) server_script="tests/Integration/multi-process-server.php"; test_file="multi-process.test.js" ;;
    multi-process-redis)
        server_script="tests/Integration/multi-process-server.php"; test_file="multi-process.test.js"; redis=1; tls=1 ;;
    example)
        [ -n "${2:-}" ] || { echo "usage: $0 example <name>" >&2; exit 2; }
        server_script="examples/$2/start.php"; test_file="examples/$2.test.js"
        case "$2" in redis|emitter) redis=1 ;; esac ;;
    *) echo "usage: $0 [single|multi-process|multi-process-redis|example <name>|examples]" >&2; exit 2 ;;
esac
echo "integration: $server_script${redis:+ with Redis}"

# Keep in sync with tests/Compliance/suite.sh.
NODE_IMAGE="node:24.21.0-alpine"
REDIS_IMAGE="redis:7.4.2-alpine"
# A password, so the tests cover the login too.
REDIS_PASSWORD="integration"

root=$(git rev-parse --show-toplevel)
cd "$root"
. "$root/tests/php-server.sh"
redis_url="redis://:$REDIS_PASSWORD@127.0.0.1:6379"
# RedisAdapter refuses rediss:// on Swoole's loop, where Workerman's TLS client connections hang.
if [ "${LOOP:-}" = "swoole" ]; then
    tls=""
fi
if [ -n "$tls" ]; then
    # A TLS-only Redis, with a certificate from a throwaway authority that the clients verify.
    docker compose run --rm php php tests/Integration/redis-tls.php .cache/redis-tls 2>/dev/null
    redis_url="rediss://:$REDIS_PASSWORD@127.0.0.1:6379"
fi
server=$(php_server -e ADAPTER="${redis:+redis}" -e REDIS_URL="$redis_url" \
    -e REDIS_TLS_CA="${tls:+/app/.cache/redis-tls/ca.crt}" "$server_script" start)
redis_server=""
trap 'docker stop -t 2 "$server" $redis_server >/dev/null 2>&1 || true' EXIT
if [ -n "$redis" ]; then
    # Shares the server's network, as the client does; the adapter reconnects until Redis is up.
    if [ -n "$tls" ]; then
        redis_server=$(docker run -d --rm --network "container:$server" -v "$root/.cache/redis-tls:/tls:ro" \
            "$REDIS_IMAGE" redis-server --requirepass "$REDIS_PASSWORD" --save "" --appendonly no \
            --port 0 --tls-port 6379 --tls-cert-file /tls/redis.crt --tls-key-file /tls/redis.key \
            --tls-ca-cert-file /tls/ca.crt --tls-auth-clients no)
    else
        redis_server=$(docker run -d --rm --network "container:$server" "$REDIS_IMAGE" \
            redis-server --requirepass "$REDIS_PASSWORD" --save "" --appendonly no)
    fi
fi

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
