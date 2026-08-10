#!/bin/sh
# Work out the face-matching thresholds from real photographs.
#
#   1. put photos in face-service/tests/faces/  (see the layout below)
#   2. sh calibrate.sh
#   3. copy the two values it prints into .env and into the Moodle plugin
#      settings, then: docker compose up -d face-service
#
# Layout — either of these works:
#
#   face-service/tests/faces/somchai_1.jpg
#   face-service/tests/faces/somchai_2.jpg
#   face-service/tests/faces/nid_1.jpg
#
#   face-service/tests/faces/somchai/enrolment.jpg
#   face-service/tests/faces/somchai/webcam-1.jpg
#   face-service/tests/faces/nid/enrolment.jpg
#
# It runs inside the face-service container on purpose: the models and the
# OpenCV build there are the ones production uses, and a threshold measured
# against a different build is not a threshold for this system.
set -e
cd "$(dirname "$0")"

FACES=face-service/tests/faces

if [ ! -d "$FACES" ] || [ -z "$(ls -A "$FACES" 2>/dev/null)" ]; then
    echo "No photographs found in $FACES" >&2
    echo >&2
    echo "Put at least 3 people in there, 3 photographs each, taken with the" >&2
    echo "same kind of camera and lighting the learners will actually use." >&2
    echo "See the comments at the top of this script for the layout." >&2
    exit 2
fi

mkdir -p reports

# The mounts live in docker-compose.yml rather than being passed with -v here:
# a -v path written on Git Bash arrives at the Docker daemon mangled, and the
# report silently lands inside the container instead of in reports/.
set +e
docker compose run --rm calibrate
status=$?
set -e

echo
if [ -f reports/CALIBRATION.md ]; then
    echo "report: reports/CALIBRATION.md"
else
    echo "no report was written" >&2
fi
exit $status
