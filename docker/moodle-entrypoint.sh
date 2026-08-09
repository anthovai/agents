#!/bin/sh
# Wait for PostgreSQL, then run Moodle's CLI installer the first time only.
# Re-running the container against an installed database is a no-op.
set -e

CONFIG=/var/www/html/config.php

echo "waiting for postgres at ${MOODLE_DB_HOST}..."
until php -r 'exit(@pg_connect(sprintf("host=%s dbname=%s user=%s password=%s",
      getenv("MOODLE_DB_HOST"), getenv("MOODLE_DB_NAME"),
      getenv("MOODLE_DB_USER"), getenv("MOODLE_DB_PASS"))) ? 0 : 1);' 2>/dev/null; do
    sleep 2
done
echo "postgres is up"

if [ ! -f "$CONFIG" ]; then
    echo "installing Moodle (first run)..."
    php admin/cli/install.php \
        --non-interactive --agree-license --skip-database \
        --lang=th \
        --wwwroot="${MOODLE_WWWROOT}" \
        --dataroot=/var/moodledata \
        --dbtype=pgsql \
        --dbhost="${MOODLE_DB_HOST}" \
        --dbname="${MOODLE_DB_NAME}" \
        --dbuser="${MOODLE_DB_USER}" \
        --dbpass="${MOODLE_DB_PASS}" \
        --fullname="KAISER Proctor" \
        --shortname="KAIPROCTOR" \
        --adminuser="${MOODLE_ADMIN_USER}" \
        --adminpass="${MOODLE_ADMIN_PASS}" \
        --adminemail="${MOODLE_ADMIN_EMAIL}"

    echo "populating database..."
    php admin/cli/install_database.php --agree-license \
        --adminpass="${MOODLE_ADMIN_PASS}" \
        --adminemail="${MOODLE_ADMIN_EMAIL}" \
        --fullname="KAISER Proctor" --shortname="KAIPROCTOR"

    chown -R www-data:www-data /var/www/html /var/moodledata
else
    echo "config.php present — upgrading if needed"
    php admin/cli/upgrade.php --non-interactive --allow-unstable || true
fi

exec "$@"
