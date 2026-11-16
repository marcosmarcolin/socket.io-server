# Starts a PHP server in Docker, detached, and prints its container id; sourced by the test scripts.
#   php_server [-e NAME=value]... <php arguments>
# Without LOOP, it runs in the php service. With LOOP=select or event, it runs in the image of tests/Load/Dockerfile,
# which has the event extension, on PHP_VERSION (8.1 by default; EVENT_VERSION picks the extension's release); with
# LOOP=swoole, in tests/Load/swoole.Dockerfile. tests/event-loop.php picks the loop. JIT=1, with LOOP, turns on OPcache
# and its tracing JIT on the CLI.
php_server() {
    envs=""
    while [ "${1:-}" = "-e" ]; do
        envs="$envs -e $2"
        shift 2
    done
    jit=""
    if [ "${JIT:-0}" = "1" ]; then
        jit="-d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=128M"
    fi
    root=$(git rev-parse --show-toplevel)
    if [ -z "${LOOP:-}" ]; then
        [ -z "$jit" ] || { echo "php_server: JIT=1 needs LOOP=select, event or swoole" >&2; return 1; }
        # shellcheck disable=SC2086 # one word per option; the values have no spaces
        docker compose run -d --rm $envs php php "$@"
    else
        if [ "$LOOP" = "swoole" ]; then
            image=socket-io-swoole
            dockerfile="$root/tests/Load/swoole.Dockerfile"
            build_args=""
        else
            image="socket-io-load:${PHP_VERSION:-8.1}-event${EVENT_VERSION:-3.1.4}"
            dockerfile="$root/tests/Load/Dockerfile"
            build_args="--build-arg PHP_VERSION=${PHP_VERSION:-8.1} --build-arg EVENT_VERSION=${EVENT_VERSION:-3.1.4}"
        fi
        # Quiet, but the build's log goes to stderr when it fails.
        # shellcheck disable=SC2086
        build_log=$(docker build --progress=plain $build_args -t "$image" -f "$dockerfile" "$root/tests/Load" 2>&1) \
            || { echo "$build_log" | tail -60 >&2; return 1; }
        # shellcheck disable=SC2086
        docker run -d --rm --ulimit nofile=65536:65536 -v "$root:/app" -w /app $envs -e LOOP="$LOOP" "$image" \
            php $jit -d auto_prepend_file=tests/event-loop.php "$@"
    fi
}
