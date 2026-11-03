#!/bin/bash
set -euo pipefail

cd /var/www/html

: "${MAUTIC_MAILER_DSN:?MAUTIC_MAILER_DSN is required, e.g. smtp://user:pass@host:587}"
: "${MAUTIC_SITE_URL:?MAUTIC_SITE_URL is required, e.g. https://mautic.example.com}"
: "${MAUTIC_DB_HOST:?MAUTIC_DB_HOST is required}"
: "${MAUTIC_DB_NAME:?MAUTIC_DB_NAME is required}"
: "${MAUTIC_DB_USER:?MAUTIC_DB_USER is required}"
: "${MAUTIC_DB_PASSWORD:?MAUTIC_DB_PASSWORD is required}"
export MAUTIC_DB_PORT="${MAUTIC_DB_PORT:-3306}"
export MAUTIC_DB_DRIVER="${MAUTIC_DB_DRIVER:-pdo_mysql}"
export MAUTIC_MESSENGER_DSN_EMAIL="${MAUTIC_MESSENGER_DSN_EMAIL:-sync://}"
export MAUTIC_MESSENGER_DSN_HIT="${MAUTIC_MESSENGER_DSN_HIT:-sync://}"

as_www_data() {
    su www-data -s /bin/bash -c "$*"
}

render_local_config() {
    echo "Rendering config/local.php from environment..."
    as_www_data "php docker/render-local-config.php"
    # en_US/pt_BR are in the image; any other MAUTIC_LOCALE has its pack
    # re-downloaded on every boot (translations/ isn't persisted). Never fatal.
    as_www_data "php docker/install-language-pack.php" || true
}

# Keep PHP's own upload limit in line with MAUTIC_ASSET_MAX_SIZE, otherwise
# anything above php.ini's 32M fails no matter what Mautic is set to.
apply_upload_limit() {
    local max="${MAUTIC_ASSET_MAX_SIZE:-}"
    if [[ "${max}" =~ ^[0-9]+$ ]] && [ "${max}" -gt 32 ]; then
        printf 'upload_max_filesize = %sM\npost_max_size = %sM\n' "$((max + 1))" "$((max + 2))" \
            > /usr/local/etc/php/conf.d/zzz-mautic-upload.ini
        echo "PHP upload limit raised to $((max + 1))M for MAUTIC_ASSET_MAX_SIZE=${max}."
    fi
}

wait-for-db.sh

ROLE="${1:-web}"

case "${ROLE}" in
    web)
        # mautic:install refuses to run ("Mautic already installed") as soon
        # as config/local.php has db_driver/site_url set - which is always,
        # since we regenerate that file from env vars. So: check the real
        # schema first (independent of local.php), and if it's missing, wipe
        # any stale local.php and let the installer create it fresh, passing
        # DB/admin credentials as CLI options instead of relying on the
        # file. Either way we re-render local.php afterwards so it always
        # ends up with our full parameter set.
        if [ "$(php docker/is-schema-installed.php)" = "yes" ]; then
            echo "Mautic schema already present, skipping mautic:install."
        else
            echo "No Mautic schema found, running first-time install..."
            rm -f config/local.php
            as_www_data "php bin/console mautic:install '${MAUTIC_SITE_URL}' \
                --db_driver='${MAUTIC_DB_DRIVER}' \
                --db_host='${MAUTIC_DB_HOST}' \
                --db_port='${MAUTIC_DB_PORT}' \
                --db_name='${MAUTIC_DB_NAME}' \
                --db_user='${MAUTIC_DB_USER}' \
                --db_password='${MAUTIC_DB_PASSWORD}' \
                --admin_email='${MAUTIC_ADMIN_EMAIL:-}' \
                --admin_password='${MAUTIC_ADMIN_PASSWORD:-}' \
                --force -n --env=prod --no-debug"

            # mautic:install's final step tries to mark all migrations as
            # applied (doctrine:migrations:version --add --all) without the
            # migrations metadata table existing yet on a truly fresh
            # database, which makes that specific step fail (logged, but
            # non-fatal to the install). Fix it up so future
            # mautic:update:apply runs have accurate migration state.
            as_www_data "php bin/console doctrine:migrations:sync-metadata-storage --env=prod --no-debug"
            as_www_data "php bin/console doctrine:migrations:version --add --all --no-interaction --env=prod --no-debug"
        fi

        render_local_config
        apply_upload_limit

        echo "Reloading plugins (installs/updates plugins found in plugins/, e.g. AmazonSesBundle)..."
        as_www_data "php bin/console mautic:plugins:reload --env=prod --no-debug" || true

        echo "Warming cache..."
        as_www_data "php bin/console cache:warmup --env=prod --no-debug" || true

        echo "Starting Apache..."
        exec apache2-foreground
        ;;

    cron)
        render_local_config
        echo "Starting supercronic as www-data..."
        exec su www-data -s /bin/bash -c "supercronic -passthrough-logs /etc/mautic-crontab"
        ;;

    worker)
        render_local_config
        exec su www-data -s /bin/bash -c "/usr/local/bin/worker-consume.sh"
        ;;

    *)
        echo "Unknown role '${ROLE}'. Use one of: web, cron, worker." >&2
        exit 1
        ;;
esac
