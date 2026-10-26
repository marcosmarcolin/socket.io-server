# The image with the event extension, which Workerman uses instead of select() and its limit of 1024 connections per
# process. tests/php-server.sh builds it when a test script runs with LOOP=select or LOOP=event.
FROM php:8.1-cli

RUN apt-get update && apt-get install -y libevent-dev libssl-dev \
    && docker-php-ext-install pcntl posix sockets \
    && pecl install event-3.1.4 \
    # After sockets, which event needs loaded first.
    && echo "extension=event.so" > /usr/local/etc/php/conf.d/zz-event.ini \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
