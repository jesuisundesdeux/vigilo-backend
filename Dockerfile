FROM php:7.3.32-apache

MAINTAINER Vigilo Team <velocite34@gmail.com>

RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    default-mysql-client \
    python3 \
    python3-docopt \
    python3-natsort && rm -rf /var/lib/apt/lists/*

# Activate php extensions
RUN docker-php-ext-configure gd --with-freetype-dir=/usr/include/ --with-jpeg-dir=/usr/include/ \
    && docker-php-ext-install -j$(nproc) gd mysqli exif

# Activate phpunit
RUN curl -L https://phar.phpunit.de/phpunit-8.phar > /usr/local/bin/phpunit \
    && chmod +x /usr/local/bin/phpunit

# Enable Remote IP
RUN a2enmod remoteip

# Enable Rewrite
RUN a2enmod rewrite

# Add logs with good ip
COPY config/remoteip.conf /etc/apache2/conf-enabled

# Add default Apache conf
COPY config/000-default.conf /etc/apache2/sites-enabled/000-default.conf

# Activate php log
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY vigilo-entrypoint /usr/local/bin/vigilo-entrypoint

COPY app /var/www/html
COPY install_app /tmp/install_app

COPY config/config.php.docker /var/www/html/config/config.php

COPY mysql/ /tmp/mysql/

COPY scripts/migrateDatabase.py /usr/local/bin

ENV AUTOUPDATE false
ENV VIGILO_VERSION 0.0.20

VOLUME /var/www/html/files
VOLUME /var/www/html/maps
VOLUME /var/www/html/caches

RUN chmod -R 777 /var/www/html/images /var/www/html/caches /var/www/html/maps

ENTRYPOINT ["vigilo-entrypoint"]
#ENTRYPOINT ["docker-php-entrypoint"]

CMD ["apache2-foreground"]

