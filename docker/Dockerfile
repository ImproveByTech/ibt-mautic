FROM php:8.2-apache

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# System packages + PHP extensions required by Mautic
#
# Note: the `imap` PHP extension is intentionally NOT built here. Debian
# trixie (used by this base image) dropped the `libc-client-dev` package it
# depends on, and this deployment handles bounces/complaints via the
# AmazonSesBundle plugin + SNS callbacks instead of a monitored IMAP mailbox.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip curl gnupg \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libxml2-dev libonig-dev \
        gettext-base \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd intl zip pdo_mysql mysqli bcmath exif opcache soap \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && a2enmod rewrite headers expires \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && SUPERCRONIC_VERSION=v0.2.29 \
    && SUPERCRONIC_ARCH="$(dpkg --print-architecture)" \
    && curl -fsSL -o /usr/local/bin/supercronic \
        "https://github.com/aptible/supercronic/releases/download/${SUPERCRONIC_VERSION}/supercronic-linux-${SUPERCRONIC_ARCH}" \
    && chmod +x /usr/local/bin/supercronic \
    && test -x /usr/local/bin/supercronic \
    && rm -rf /var/lib/apt/lists/*

# Mautic relies on .htaccess for URL rewriting; the base image ships with
# AllowOverride None by default.
RUN { \
        echo '<Directory /var/www/html>'; \
        echo '    AllowOverride All'; \
        echo '</Directory>'; \
    } >> /etc/apache2/apache2.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Installed early so it's already in effect for the composer install step
# below (mautic:assets:generate needs more than PHP's 128M CLI default to
# dump the compiled Symfony container).
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-mautic.ini

WORKDIR /var/www/html

# Copy app source
COPY . .

# No config/local.php exists at this point on purpose: AppKernel treats a
# missing local.php as "not installed yet" and hardcodes a DB server_version
# constant so Doctrine skips live-connecting to a database that doesn't
# exist during the build. Runs composer.json's own post-install-cmd chain:
# npm ci (webpack build via package.json's postinstall), patch-package, the
# GrapesJS builder plugin build, and mautic:assets:generate/assets:install.
# node_modules is kept in the final image on purpose: Mautic's
# AssetGenerationHelper regenerates media/js/libraries.js and
# media/css/libraries.css by re-reading raw source files straight out of
# node_modules whenever it decides they're stale (which happens at runtime,
# not just at build time - a fresh config/local.php on every boot is enough
# to trip it). Deleting node_modules after the build makes that regeneration
# throw and blows up every page that loads it, including the login page.
RUN composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist \
        --ignore-platform-req=ext-imap \
    && npm cache clean --force \
    && rm -rf plugins/GrapesJsBuilderBundle/node_modules /root/.npm

# Runtime writable paths
#
# translations/ is included because language packs other than the bundled
# en_US and the committed translations/pt_BR are downloaded and extracted
# here on-demand at runtime by www-data (UI or install-language-pack.php).
RUN mkdir -p var/cache var/logs var/spool var/tmp media/files media/images media/assets translations \
    && chown -R www-data:www-data /var/www/html/var /var/www/html/media /var/www/html/config /var/www/html/translations \
    && chmod -R ug+rwX /var/www/html/var /var/www/html/media /var/www/html/config /var/www/html/translations

COPY docker/crontab /etc/mautic-crontab
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/worker-consume.sh /usr/local/bin/worker-consume.sh
COPY docker/render-local-config.php /var/www/html/docker/render-local-config.php
COPY docker/is-schema-installed.php /var/www/html/docker/is-schema-installed.php
COPY docker/install-language-pack.php /var/www/html/docker/install-language-pack.php
COPY docker/wait-for-db.sh /usr/local/bin/wait-for-db.sh

RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/wait-for-db.sh /usr/local/bin/worker-consume.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["web"]
