# The image with the event extension, which Workerman uses instead of select() and its limit of 1024 connections per
# process. tests/php-server.sh builds it when a test script runs with LOOP=select or LOOP=event, on PHP_VERSION.
ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-cli

ARG EVENT_VERSION=3.1.4

RUN apt-get update && apt-get install -y libevent-dev libssl-dev \
    && docker-php-ext-install pcntl posix sockets \
    && pecl install event-${EVENT_VERSION} \
    # After sockets, which event needs loaded first.
    && echo "extension=event.so" > /usr/local/etc/php/conf.d/zz-event.ini \
    # Loaded but off on the CLI (opcache.enable_cli=0) until JIT=1; PHP 8.5 builds it in.
    && if [ -f "$(php-config --extension-dir)/opcache.so" ]; then docker-php-ext-enable opcache; fi \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
