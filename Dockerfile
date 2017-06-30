FROM php:7.1
ENV DEBIAN_FRONTEND noninteractive

RUN apt-get update -q \
  && apt-get install unzip git libxml2-dev gnupg libgpgme11 libgpgme11-dev -y --no-install-recommends \
  && rm -rf /var/lib/apt/lists/*
RUN pecl install gnupg && docker-php-ext-enable gnupg

WORKDIR /root

RUN curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer

COPY . /code

WORKDIR /code

RUN composer install --prefer-dist --no-interaction

CMD php ./src/app.php run /data