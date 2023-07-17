FROM php:7.4
ENV DEBIAN_FRONTEND noninteractive

RUN apt-get update -q \
  && apt-get install zip unzip git libxml2-dev gnupg libgpgme11 libgpgme11-dev -y --no-install-recommends \
  && rm -rf /var/lib/apt/lists/*
RUN pecl install gnupg && docker-php-ext-enable gnupg

WORKDIR /root

## install RapidSSL cert
RUN curl https://cacerts.digicert.com/RapidSSLRSACA2018.crt.pem -o RapidSSL_RSA_CA_2018.pem
RUN openssl x509 -in RapidSSL_RSA_CA_2018.pem -inform PEM -out /usr/local/share/ca-certificates/RapidSSL_RSA_CA_2018.crt
RUN rm RapidSSL_RSA_CA_2018.pem
RUN update-ca-certificates

RUN curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer

COPY . /code

WORKDIR /code

RUN composer install --prefer-dist --no-interaction

CMD php ./src/run.php run /data
