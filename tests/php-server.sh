# Starts a PHP server in Docker, detached, and prints its container id; sourced by the test scripts.
#   php_server [-e NAME=value]... <php arguments>
# Without LOOP, it runs in the php service. With LOOP=select or event, it runs in the image of tests/Load/Dockerfile,
# which has the event extension; with LOOP=swoole, in tests/Load/swoole.Dockerfile. tests/event-loop.php picks the loop.
php_server() {
    envs=""
    while [ "${1:-}" = "-e" ]; do
        envs="$envs -e $2"
        shift 2
    done
    root=$(git rev-parse --show-toplevel)
    if [ -z "${LOOP:-}" ]; then
        # shellcheck disable=SC2086 # one word per option; the values have no spaces
        docker compose run -d --rm $envs php php "$@"
    else
        if [ "$LOOP" = "swoole" ]; then
            image=socket-io-swoole
            dockerfile="$root/tests/Load/swoole.Dockerfile"
        else
            image=socket-io-load
            dockerfile="$root/tests/Load/Dockerfile"
        fi
        # Quiet, but the build's log goes to stderr when it fails.
        build_log=$(docker build --progress=plain -t "$image" -f "$dockerfile" "$root/tests/Load" 2>&1) \
            || { echo "$build_log" | tail -60 >&2; return 1; }
        # shellcheck disable=SC2086
        docker run -d --rm --ulimit nofile=65536:65536 -v "$root:/app" -w /app $envs -e LOOP="$LOOP" "$image" \
            php -d auto_prepend_file=tests/event-loop.php "$@"
    fi
}
