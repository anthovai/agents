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

# config.php lives in the image, not in a volume, so recreating the container
# loses it. Rather than run the installer again — which then fails on a
# database that is already populated and leaves the container dead — it is
# written from the environment every start. Everything in it is determined by
# env anyway, so this makes the container properly disposable.
if [ ! -f "$CONFIG" ]; then
    echo "writing config.php from the environment"
    cat > "$CONFIG" <<PHPCONFIG
<?php
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();

\$CFG->dbtype    = 'pgsql';
\$CFG->dblibrary = 'native';
\$CFG->dbhost    = '${MOODLE_DB_HOST}';
\$CFG->dbname    = '${MOODLE_DB_NAME}';
\$CFG->dbuser    = '${MOODLE_DB_USER}';
\$CFG->dbpass    = '${MOODLE_DB_PASS}';
\$CFG->prefix    = 'mdl_';
\$CFG->dboptions = ['dbpersist' => 0, 'dbport' => '', 'dbsocket' => ''];

\$CFG->wwwroot   = '${MOODLE_WWWROOT}';
\$CFG->dataroot  = '/var/moodledata';
\$CFG->admin     = 'admin';
\$CFG->directorypermissions = 0777;

require_once(__DIR__ . '/lib/setup.php');
PHPCONFIG
    chown www-data:www-data "$CONFIG"
fi

# Has the database been populated yet? An empty database needs the installer;
# a populated one only ever needs the upgrade step.
INSTALLED=$(php -r '
    $c = @pg_connect(sprintf("host=%s dbname=%s user=%s password=%s",
        getenv("MOODLE_DB_HOST"), getenv("MOODLE_DB_NAME"),
        getenv("MOODLE_DB_USER"), getenv("MOODLE_DB_PASS")));
    $r = $c ? @pg_query($c, "SELECT 1 FROM mdl_config LIMIT 1") : false;
    echo $r ? "yes" : "no";
')

if [ "$INSTALLED" = "no" ]; then
    echo "populating database (first run)..."
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
    echo "database already populated — upgrading if needed"
    php admin/cli/upgrade.php --non-interactive --allow-unstable || true
fi

exec "$@"
