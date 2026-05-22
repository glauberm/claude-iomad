#!/usr/bin/env bash
set -euo pipefail

php /var/www/html/admin/cli/install.php \
    --lang=en \
    --wwwroot="${MOODLE_WWWROOT}" \
    --dataroot="${MOODLE_DATAROOT}" \
    --dbtype=mysqli \
    --dbhost="${MOODLE_DB_HOST}" \
    --dbname="${MOODLE_DB_NAME}" \
    --dbuser="${MOODLE_DB_USER}" \
    --dbpass="${MOODLE_DB_PASS}" \
    --dbport="${MOODLE_DB_PORT:-3306}" \
    --prefix=mdl_ \
    --fullname="Claude IOMAD" \
    --shortname="claude-iomad" \
    --adminuser="${MOODLE_ADMIN_USER}" \
    --adminpass="${MOODLE_ADMIN_PASS}" \
    --adminemail="${MOODLE_ADMIN_EMAIL}" \
    --non-interactive \
    --agree-license

php /var/www/html/admin/cli/cfg.php --name=noemailever --set=1
php /var/www/html/admin/cli/cfg.php --name=smtphosts --set=''

# Enable SSL proxy mode when running behind a reverse proxy (Railway, etc.)
if [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ]; then
    php /var/www/html/admin/cli/cfg.php --name=sslproxy --set=1
    php /var/www/html/admin/cli/cfg.php --name=debugdisplay --set=0
    php /var/www/html/admin/cli/cfg.php --name=debug --set=0
else
    php /var/www/html/admin/cli/cfg.php --name=debugdisplay --set=1
    php /var/www/html/admin/cli/cfg.php --name=debug --set=32767
fi

# Set Anthropic API key if provided via environment
if [ -n "${ANTHROPIC_API_KEY:-}" ]; then
    php /var/www/html/admin/cli/cfg.php --component=block_iomad_claude --name=apikey --set="${ANTHROPIC_API_KEY}"
    php /var/www/html/admin/cli/cfg.php --component=block_iomad_claude --name=model --set="${CLAUDE_MODEL:-claude-sonnet-4-6}"
fi

# Run upgrade to complete IOMAD plugin installs (creates company, company_users, etc.)
php /var/www/html/admin/cli/upgrade.php --non-interactive
