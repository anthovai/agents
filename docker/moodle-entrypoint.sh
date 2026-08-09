#!/bin/sh
# Wait for PostgreSQL, then run Moodle's CLI installer the first time only.
#
# Only the container with MOODLE_ROLE=web installs. The cron container shares
# this image, and two installers racing on the same moodledata volume corrupts
# it — one creates moodledata/cache between the other's is_dir() and mkdir().
set -e

# Since Moodle 5.0 the web root is public/, but the real config.php stays at
# the project root — public/config.php is a shim that Moodle ships, so testing
# for that one would report "installed" on a completely empty site.
CONFIG=/var/www/html/config.php
ROLE="${MOODLE_ROLE:-web}"

echo "waiting for postgres at ${MOODLE_DB_HOST}..."
until php -r 'exit(@pg_connect(sprintf("host=%s dbname=%s user=%s password=%s",
      getenv("MOODLE_DB_HOST"), getenv("MOODLE_DB_NAME"),
      getenv("MOODLE_DB_USER"), getenv("MOODLE_DB_PASS"))) ? 0 : 1);' 2>/dev/null; do
    sleep 2
done
echo "postgres is up"

if [ "$ROLE" != "web" ]; then
    echo "role=${ROLE}: waiting for the web container to finish installing"
    until [ -f "$CONFIG" ]; do
        sleep 3
    done
    echo "config.php present — starting ${ROLE}"
    exec "$@"
fi

if [ ! -f "$CONFIG" ]; then
    echo "installing Moodle (first run)..."
    # Install in English. Asking the installer for Thai makes it fetch the
    # language pack mid-install, and a download failure there aborts the whole
    # installation — the pack is added separately below where it can fail safely.
    php admin/cli/install.php \
        --non-interactive --agree-license --skip-database \
        --lang=en \
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

    # Thai language pack. Moodle 5.1 ships no CLI for this, so tool_langimport
    # is driven directly. It needs to reach download.moodle.org; if it cannot,
    # the site stays in English and an admin can add the pack from the UI.
    echo "setting up the Thai language pack..."
    php -r '
        define("CLI_SCRIPT", true);
        require("/var/www/html/config.php");
        $controller = new \tool_langimport\controller();
        $controller->install_languagepacks("th");
        foreach ($controller->info as $line) { echo "  $line\n"; }
        foreach ($controller->errors as $line) { echo "  ERROR: $line\n"; }
        if (array_key_exists("th", get_string_manager()->get_list_of_translations())) {
            set_config("lang", "th");
            echo "  site language set to th\n";
        } else {
            echo "  WARNING: Thai unavailable — site stays in English\n";
        }
    ' || echo "WARNING: language setup failed — site stays in English"

    chown -R www-data:www-data /var/www/html /var/moodledata
else
    echo "config.php present — upgrading if needed"
    php admin/cli/upgrade.php --non-interactive --allow-unstable || true
fi

exec "$@"
