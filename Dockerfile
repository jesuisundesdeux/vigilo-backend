FROM php:8.3-apache-bookworm

LABEL org.opencontainers.image.title="vigilo-backend" \
      org.opencontainers.image.source="https://github.com/jesuisundesdeux/vigilo-backend" \
      org.opencontainers.image.licenses="GPL-3.0"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        unzip \
        default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd mysqli exif zip

RUN a2enmod remoteip rewrite headers

# Logs with the real client IP behind a reverse proxy
COPY config/remoteip.conf /etc/apache2/conf-enabled

# Default Apache conf
COPY config/000-default.conf /etc/apache2/sites-enabled/000-default.conf

# Production settings: errors are logged, never displayed
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { echo 'expose_php = Off'; \
         echo 'upload_max_filesize = 20M'; \
         echo 'post_max_size = 25M'; \
         echo 'session.cookie_httponly = 1'; \
         echo 'session.use_strict_mode = 1'; } > "$PHP_INI_DIR/conf.d/vigilo.ini"

COPY vigilo-entrypoint /usr/local/bin/vigilo-entrypoint

COPY app /var/www/html
COPY install_app /tmp/install_app

COPY config/config.php.docker /var/www/html/config/config.php

COPY scripts/vigilo-migrate.php /usr/local/bin/vigilo-migrate.php

# Version of the image, shown in the admin (Mises à jour) to compare with the releases
ARG VIGILO_IMAGE_VERSION=dev
ENV VIGILO_RUNTIME=docker \
    VIGILO_IMAGE_VERSION=$VIGILO_IMAGE_VERSION \
    AUTOUPDATE=true

ENTRYPOINT ["vigilo-entrypoint"]

CMD ["apache2-foreground"]
