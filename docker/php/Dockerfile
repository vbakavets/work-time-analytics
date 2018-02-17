FROM php:7.1-fpm

RUN apt-get update && apt-get install -y \
    git \
    libmcrypt-dev \
    zlib1g-dev \
    libssl-dev

RUN docker-php-ext-install \
    mcrypt \
    zip

#xdebug
RUN pecl install xdebug
RUN docker-php-ext-enable xdebug
RUN sed -i '1 a xdebug.remote_enable=1' /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN sed -i '1 a xdebug.remote_port=9000' /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
RUN sed -i '1 a xdebug.remote_connect_back=1' /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

#oauth
RUN apt-get install -y libpcre3 libpcre3-dev
RUN pecl install oauth-2.0.2 \
    && docker-php-ext-enable oauth

WORKDIR /app
