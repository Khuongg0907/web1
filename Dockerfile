# One Dockerfile, three independently built images (targets: html, css, php).
ARG NGINX_IMAGE=nginx:stable-alpine
ARG PHP_IMAGE=php:8.4-apache-bookworm

FROM ${NGINX_IMAGE} AS css
COPY docker/css.conf /etc/nginx/conf.d/default.conf
COPY style.css /usr/share/nginx/html/style.css
HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=5 \
    CMD wget -q -O /dev/null http://127.0.0.1/healthz || exit 1

FROM ${PHP_IMAGE} AS php
# The official PHP image already includes mbstring and PDO SQLite.
# Fail at build time if the selected base image lacks either extension.
RUN php -r 'foreach (["mbstring", "pdo_sqlite"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing extension: ".$ext."\n"); exit(1); } }' \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && a2enmod rewrite \
    && mkdir -p /var/lib/mono \
    && chown www-data:www-data /var/lib/mono \
    && chmod 700 /var/lib/mono
COPY docker/php-apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/mono.ini
COPY database.php /var/www/html/database.php
ENV MONO_DATA_DIR=/var/lib/mono
HEALTHCHECK --interval=10s --timeout=5s --start-period=10s --retries=5 \
    CMD php -r '$data = json_decode(@file_get_contents("http://127.0.0.1/api/bootstrap"), true); exit(isset($data["csrf"], $data["products"]) ? 0 : 1);'

FROM ${NGINX_IMAGE} AS html
COPY docker/html.conf /etc/nginx/conf.d/default.conf
COPY index.html /usr/share/nginx/html/index.html
HEALTHCHECK --interval=10s --timeout=3s --start-period=5s --retries=5 \
    CMD wget -q -O /dev/null http://127.0.0.1/healthz || exit 1
