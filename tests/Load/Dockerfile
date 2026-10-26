# The image with the event and swoole extensions, the event loops Workerman can use instead of select() and its limit
# of 1024 connections per process. tests/php-server.sh builds it when a test script runs with LOOP.
FROM php:8.1-cli

RUN apt-get update && apt-get install -y libevent-dev libssl-dev libbrotli-dev zlib1g-dev \
    && docker-php-ext-install pcntl posix sockets \
    # Swoole 6.1 is the last line that supports PHP 8.1.
    && pecl install event-3.1.4 swoole-6.1.11 \
    # After sockets, which event needs loaded first.
    && echo "extension=event.so" > /usr/local/etc/php/conf.d/zz-event.ini \
    && docker-php-ext-enable swoole \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
