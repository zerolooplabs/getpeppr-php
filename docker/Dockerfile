# Development and CI image. PHP and Composer are pinned to exact releases.
ARG PHP_VERSION=8.2.35
FROM composer:2.10.3 AS composer
FROM php:${PHP_VERSION}-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_CACHE_DIR=/tmp/composer-cache
WORKDIR /app
