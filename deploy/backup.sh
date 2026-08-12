#!/bin/sh
# Back up everything that cannot be rebuilt.
#
# Two things, and both are needed: the database holds who did what, and
# moodledata holds the evidence files those rows point at. A backup with only
# one of them restores to a system that says a snapshot exists and cannot show
# it.
#
#   sh deploy/backup.sh /var/backups/kaiproctor
#
# Run it from the repository root. Restoring is deploy/restore.sh.
#
# Both archives are written inside the container and copied out, rather than
# piped through this shell. The first version piped, and on Windows the shell
# corrupted the tar in transit — 945,459 bytes out for 945,229 in, and `tar -t`
# listed nothing. It looked like a backup, it was the right sort of size, and
# it contained no files. Never pipe binary through the host shell.
set -eu

DEST="${1:?usage: backup.sh <destination directory>}"
COMPOSE="docker compose -f deploy/docker-compose.prod.yml --env-file deploy/.env"
STAMP=$(date +%Y%m%d-%H%M%S)
OUT="${DEST}/${STAMP}"

DBUSER=$(grep '^MOODLE_DB_USER=' deploy/.env | cut -d= -f2)
DBNAME=$(grep '^MOODLE_DB_NAME=' deploy/.env | cut -d= -f2)

mkdir -p "$OUT"

echo "database..."
# --clean --if-exists so the dump loads over an existing database without a
# manual drop, which is the state a restore is usually done in.
$COMPOSE exec -T db sh -c \
    "pg_dump --username '${DBUSER}' --dbname '${DBNAME}' --clean --if-exists \
     | gzip > /tmp/database.sql.gz"
$COMPOSE cp db:/tmp/database.sql.gz "${OUT}/database.sql.gz"
$COMPOSE exec -T db rm -f /tmp/database.sql.gz

echo "moodledata (evidence, uploaded files)..."
# Caches are excluded: large, self-rebuilding, and including them is how
# backups become slow enough that people stop taking them.
$COMPOSE exec -T moodle sh -c \
    "tar --exclude=./cache --exclude=./localcache --exclude=./temp \
         --exclude=./trashdir --exclude=./sessions \
         -czf /tmp/moodledata.tar.gz -C /var/moodledata ."
$COMPOSE cp moodle:/tmp/moodledata.tar.gz "${OUT}/moodledata.tar.gz"
$COMPOSE exec -T moodle rm -f /tmp/moodledata.tar.gz

echo "settings, so a restore knows what it was..."
cp deploy/.env "${OUT}/env.txt"
chmod 600 "${OUT}/env.txt"

echo "checking what was written..."
# Verified rather than assumed. An unreadable archive of the right size is the
# failure this whole script is arranged to avoid, so it is checked here and not
# discovered during an incident.
gzip -t "${OUT}/database.sql.gz" || { echo "the database dump is unreadable"; exit 1; }
gzip -t "${OUT}/moodledata.tar.gz" || { echo "the moodledata archive is unreadable"; exit 1; }

FILES=$(tar -tzf "${OUT}/moodledata.tar.gz" | grep -c '^\./filedir/' || true)
if [ "$FILES" -eq 0 ]; then
    echo "the archive contains no stored files — evidence would not survive this"
    exit 1
fi

echo
echo "backup written to ${OUT}"
echo "  stored files in the archive: ${FILES}"
du -sh "${OUT}"/* 2>/dev/null || true
echo
echo "A backup nobody has restored is a hope. deploy/restore.sh takes this"
echo "directory; try it on a spare host before you need it."
