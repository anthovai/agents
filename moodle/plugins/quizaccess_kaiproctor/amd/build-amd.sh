#!/bin/sh
# Moodle loads amd/build/<name>.min.js; it never reads amd/src at runtime.
#
# The official way to produce those files is `grunt amd` from the Moodle root,
# which needs a node toolchain this image does not carry. Because the sources
# here are written in plain AMD (define(...), no ES6 modules, no JSX), no
# transpilation is required and a copy is a correct build — just not a minified
# one. Switch to grunt as soon as node is available; delete this script then.
set -e
cd "$(dirname "$0")"

mkdir -p build
for source in src/*.js; do
    name=$(basename "$source" .js)
    cp "$source" "build/${name}.min.js"
    echo "built build/${name}.min.js"
done
