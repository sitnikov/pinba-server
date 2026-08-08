FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pcntl sockets

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/pinba-server

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress

COPY Pinba/ Pinba/
COPY GPBMetadata/ GPBMetadata/
COPY tools/ tools/
COPY workerman_clickhouse.php workerman_loki.php ./

ENV PINBA_CONFIG=/etc/pinba-server/config.json
COPY docker/config.json /etc/pinba-server/config.json

CMD ["php", "workerman_clickhouse.php", "start"]
