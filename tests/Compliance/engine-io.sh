#!/bin/sh
# Runs the official Engine.IO v4 test suite against tests/Compliance/engine-io-server.php.
set -eu

SUITE_DIR="docs/engine.io-protocol/v4-test-suite"
SUITE_FILES="
b58b920155ab14c14c78594f457cc8bc8e201f4e7c4400a53d78d483541cfbf7 node-imports.js
cd1619a120a3a169a3b1bf9cc1109d1d2632019f385f4cea89f49c5e36a6dbf3 package-lock.json
0b6cdf44127a89eaa5a315da7fd4f89871a118f1ea15b1546c00ad285a049254 package.json
3f4b32add9837659dcf48af0bb5b8b203b636784d856134046d85f391b15bc88 test-suite.js
"
SERVER="tests/Compliance/engine-io-server.php"
READY_PATH="/engine.io/?EIO=4&transport=polling"

. "$(dirname "$0")/suite.sh"
