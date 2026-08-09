#!/bin/sh
# Run the end-to-end suite and collect every artefact into reports/.
#
# The stack must already be up:  docker compose up -d
#
# KP_SLOW_MO controls how far the browser is slowed between actions. The
# default is high on purpose — these recordings exist to be watched by someone
# checking the system behaves, and a video nobody can follow proves nothing.
set -e
cd "$(dirname "$0")"

PYTHON=.venv/Scripts/python.exe
[ -x "$PYTHON" ] || PYTHON=.venv/bin/python

echo "checking the stack is up..."
docker compose ps --status running --format '{{.Service}}' | tr -d '\r' | sort > /tmp/kp-running.txt
for service in db face-service moodle; do
    grep -qx "$service" /tmp/kp-running.txt || {
        echo "ERROR: service '$service' is not running — start it with: docker compose up -d" >&2
        exit 1
    }
done

mkdir -p reports

echo "running the suite (slow_mo=${KP_SLOW_MO:-700}ms)..."
set +e
"$PYTHON" -m pytest 2>&1 | tee reports/pytest-output.txt
status=$?
set -e

echo
"$PYTHON" tests/make_report.py || true

echo
echo "artefacts:"
echo "  reports/REPORT.md"
echo "  reports/junit.xml"
echo "  reports/video/          $(ls reports/video 2>/dev/null | wc -l) recordings"
echo "  reports/screenshots/    $(ls reports/screenshots 2>/dev/null | wc -l) screenshots"
echo "  reports/eventlog/       $(ls reports/eventlog 2>/dev/null | wc -l) logs"

exit $status
