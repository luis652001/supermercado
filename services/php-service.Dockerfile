# Imagen común para los microservicios PHP. Uso:
#   build: { context: ./services, dockerfile: php-service.Dockerfile, args: { SERVICE: auth-service, PORT: "8081" } }
FROM php:8.2-apache

ARG SERVICE
ARG PORT=8080

RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf "expose_php=Off\ndisplay_errors=Off\nlog_errors=On\nerror_log=/dev/stderr\n" > "$PHP_INI_DIR/conf.d/zz-seguridad.ini" \
    && sed -ri "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf \
    && sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf \
    && echo 'FallbackResource /index.php' > /etc/apache2/conf-available/fallback.conf \
    && a2enconf fallback

COPY --chown=root:root shared/ /var/www/shared/
COPY --chown=root:root ${SERVICE}/app/ /var/www/app/
COPY --chown=root:root ${SERVICE}/public/ /var/www/html/

# Usuario no privilegiado (por eso Apache escucha en un puerto > 1024).
USER www-data

ENV APP_PORT=${PORT}
EXPOSE ${PORT}

HEALTHCHECK --interval=15s --timeout=3s --retries=3 CMD curl -fs "http://localhost:${APP_PORT}/health" || exit 1

CMD ["sh", "-c", "if [ -f /var/www/app/bin/startup.php ]; then php /var/www/app/bin/startup.php; fi; exec apache2-foreground"]
