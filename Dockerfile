FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev ca-certificates \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

COPY public/ /var/www/html/
COPY src/ /var/www/src/
COPY config/ /var/www/config/

EXPOSE 80
