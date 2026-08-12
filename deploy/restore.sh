#!/bin/sh
# Put back what backup.sh took.
#
#   sh deploy/restore.sh /var/backups/kaiproctor/20260812-031500
#
# Destructive on purpose: it drops and recreates the schema. Run it against the
# stack you intend to overwrite, and read which one that is before you do.
set -eu

SRC="${1:?usage: restore.sh <backup directory>}"
COMPOSE="docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env"

test -f "${SRC}/database.sql.gz" || { echo "no database.sql.gz in ${SRC}"; exit 1; }
test -f "${SRC}/moodledata.tar.gz" || { echo "no moodledata.tar.gz in ${SRC}"; exit 1; }

DBUSER=$(grep '^MOODLE_DB_USER=' deploy/.env | cut -d= -f2)
DBNAME=$(grep '^MOODLE_DB_NAME=' deploy/.env | cut -d= -f2)

echo "This will overwrite the database and moodledata of the stack in"
echo "deploy/.env — ${DBNAME} as ${DBUSER}."
printf 'Type the database name to continue: '
read -r CONFIRM
[ "$CONFIRM" = "$DBNAME" ] || { echo "aborted"; exit 1; }

# Web and cron down first: restoring under a running Moodle leaves it holding
# rows that no longer exist.
$COMPOSE stop moodle cron

echo "database..."
gunzip -c "${SRC}/database.sql.gz" | $COMPOSE exec -T db psql -U "$DBUSER" -d "$DBNAME"

echo "moodledata..."
$COMPOSE run --rm -T --entrypoint sh moodle -c \
    'rm -rf /var/moodledata/* && tar -xzf - -C /var/moodledata' < "${SRC}/moodledata.tar.gz"

$COMPOSE start moodle cron

echo
echo "restored. Check that an evidence image renders on a report page: that is"
echo "the one thing a database-only restore gets wrong and nothing else notices."
