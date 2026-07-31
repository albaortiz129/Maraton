FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && apt-get purge -y --auto-remove libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod headers rewrite \
    && mkdir -p /var/lib/maraton \
    && chown -R www-data:www-data /var/lib/maraton

COPY docker/apache-maraton.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/maraton-entrypoint
COPY docker/backup.php /usr/local/bin/maraton-backup.php
RUN sed -i 's/\r$//' /usr/local/bin/maraton-entrypoint \
    && chmod +x /usr/local/bin/maraton-entrypoint

WORKDIR /var/www/html
COPY api.php app.js icon.svg index.html manifest.webmanifest styles.css sw.js ./

ENV MARATON_DATA_DIR=/var/lib/maraton \
    MARATON_HTTPS=0

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r '$c=@file_get_contents("http://127.0.0.1/api.php?action=health"); exit($c===false?1:0);'

ENTRYPOINT ["maraton-entrypoint"]
CMD ["apache2-foreground"]
