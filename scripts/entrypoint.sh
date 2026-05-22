#!/usr/bin/env bash
set -euo pipefail

# On Railway, derive wwwroot from the public domain if not explicitly set.
if [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ] && [ "${MOODLE_WWWROOT:-}" = "" ]; then
    export MOODLE_WWWROOT="https://${RAILWAY_PUBLIC_DOMAIN}"
fi

echo "[entrypoint] Waiting for DB at ${MOODLE_DB_HOST}..."
until mysqladmin ping -h "${MOODLE_DB_HOST}" -u"${MOODLE_DB_USER}" -p"${MOODLE_DB_PASS}" --ssl=FALSE --silent 2>/dev/null; do
    echo "[entrypoint] DB not ready, retrying in 3s..."
    sleep 3
done
echo "[entrypoint] DB is up."

# Restore config.php from moodledata if present (persists across deploys/restarts).
CONFIG_BACKUP="/var/moodledata/config.php"
CONFIG_TARGET="/var/www/html/config.php"
if [ -f "${CONFIG_BACKUP}" ] && [ ! -f "${CONFIG_TARGET}" ]; then
    echo "[entrypoint] Restoring config.php from moodledata..."
    cp "${CONFIG_BACKUP}" "${CONFIG_TARGET}"
fi

if [ ! -f "${CONFIG_TARGET}" ]; then
    echo "[entrypoint] Running IOMAD installer..."
    /bin/bash /scripts/install-iomad.sh
    cp "${CONFIG_TARGET}" "${CONFIG_BACKUP}"
    echo "[entrypoint] Installation complete."
else
    echo "[entrypoint] IOMAD already installed, skipping."
fi

chown www-data:www-data "${CONFIG_TARGET}"
chmod 0644 "${CONFIG_TARGET}"

SEED_FLAG="/var/moodledata/.seeded"
if [ ! -f "${SEED_FLAG}" ]; then
    echo "[entrypoint] Running seed script..."
    php /seed/seed.php && touch "${SEED_FLAG}"
    echo "[entrypoint] Seed complete."
else
    echo "[entrypoint] Already seeded, skipping."
fi

echo "[entrypoint] Configuring Apache MPM..."
a2dismod mpm_event mpm_worker 2>/dev/null; a2enmod mpm_prefork

echo "[entrypoint] Starting Apache..."
exec apache2-foreground
