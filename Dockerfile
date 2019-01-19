# WonderCMS in Docker
# See README for instructions on running this image
FROM php:7.2-apache-stretch

ENV WCMS_VERSION 2.6.0

LABEL org.label-schema.name="wondercms" \
    org.label-schema.description="Run wondercms in docker" \
    org.label-schema.url="https://www.wondercms.com/" \
    org.label-schema.vcs-url="https://github.com/robiso/wondercms" \
    org.label-schema.version=$WCMS_VERSION \
    org.label-schema.maintainer="@wondercms on twitter" \
    org.label-schema.schema-version="1.0"

RUN DEBIAN_FRONTEND=noninteractive apt-get update \
    && apt-get install -y libzip-dev zip \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && mv $PHP_INI_DIR/php.ini-development $PHP_INI_DIR/php.ini

RUN docker-php-ext-configure zip --with-libzip && \
    docker-php-ext-install -j$(nproc) zip

COPY . /var/www/html/

VOLUME /var/www/html

EXPOSE 80
