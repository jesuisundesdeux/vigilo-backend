FROM php:7.3.32-apache

MAINTAINER Vigilo Team <velocite34@gmail.com>

# Debian bullseye (base of PHP 7.3) is end of life and its packages are leaving the
# regular mirrors: when the install fails, retry from archive.debian.org.
RUN PACKAGES="libfreetype6-dev libjpeg62-turbo-dev libpng-dev default-mysql-client" \
    && (apt-get update && apt-get install -y $PACKAGES) \
    || (printf '%s\n' \
          'deb http://archive.debian.org/debian bullseye main' \
          'deb http://archive.debian.org/debian-security bullseye-security main' \
          > /etc/apt/sources.list \
        && rm -rf /var/lib/apt/lists/* \
        && apt-get -o Acquire::Check-Valid-Until=false update \
        && apt-get install -y $PACKAGES) \
    && rm -rf /var/lib/apt/lists/*

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

# Production settings: errors are logged, never displayed
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY vigilo-entrypoint /usr/local/bin/vigilo-entrypoint

COPY app /var/www/html
COPY install_app /tmp/install_app

COPY config/config.php.docker /var/www/html/config/config.php

COPY scripts/vigilo-migrate.php /usr/local/bin/vigilo-migrate.php

# The version is read from app/includes/version.php at startup, never hardcoded here.
# Migrations run at startup unless AUTOUPDATE=false.
ENV AUTOUPDATE true

ENTRYPOINT ["vigilo-entrypoint"]
#ENTRYPOINT ["docker-php-entrypoint"]

CMD ["apache2-foreground"]

