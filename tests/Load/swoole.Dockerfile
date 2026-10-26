# The image for LOOP=swoole: the Swoole project's PHP 8.1 image, built already (6.1 is the last line for PHP 8.1),
# plus pcntl, which Workerman needs. tests/php-server.sh builds it.
FROM phpswoole/swoole:6.1.10-php8.1

RUN docker-php-ext-install pcntl

WORKDIR /app
