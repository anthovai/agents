#!/bin/sh
# Fetch the third-party Moodle plugins this project builds on.
#
# They are downloaded rather than vendored: mod_interactivevideo alone is 450
# files and 4MB, and copying somebody else's GPL plugin into our tree only
# makes it look like ours and go stale.
#
# Pinned to a commit, not a branch — "latest main" is not a dependency, it is a
# moving target that turns a reproducible stack into a lottery.
set -e
cd "$(dirname "$0")"

# mod_interactivevideo 1.9.3 — GPL-3.0, https://github.com/sokunthearithmakara/moodle-mod_interactivevideo
IV_REF=94f4d0304ac86d7e06c30b6b781ddff3b1539800
IV_DIR=mod_interactivevideo

if [ -f "$IV_DIR/version.php" ]; then
    echo "have $IV_DIR"
else
    echo "fetching $IV_DIR at ${IV_REF}..."
    rm -rf "$IV_DIR" .iv-tmp
    mkdir -p .iv-tmp
    curl -fsSL "https://github.com/sokunthearithmakara/moodle-mod_interactivevideo/archive/${IV_REF}.zip" \
        -o .iv-tmp/iv.zip
    unzip -q .iv-tmp/iv.zip -d .iv-tmp
    mv ".iv-tmp/moodle-mod_interactivevideo-${IV_REF}" "$IV_DIR"
    rm -rf .iv-tmp
fi

grep -E '^\$plugin->(component|release|version)' "$IV_DIR/version.php" | sed 's/^/  /'
echo
echo "third-party plugins ready. Licences:"
echo "  mod_interactivevideo  GPL-3.0-or-later  (see $IV_DIR/LICENSE)"
