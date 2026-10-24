#!/bin/sh
# Runs the official Socket.IO v5 test suite against tests/Compliance/socket-io-server.php.
set -eu

SUITE_DIR="docs/socket.io-protocol/v5-test-suite"
SUITE_FILES="
b58b920155ab14c14c78594f457cc8bc8e201f4e7c4400a53d78d483541cfbf7 node-imports.js
33973e91c076ef721dd52822fd401263cbe7b238dc99d54feca5eb6e77f91ff3 package-lock.json
0f30c1f5661c80f2ed251a360a59a34b1cf5c376b49558de22fe4a200b306614 package.json
79d6cebaec1e367da5c441e67d4364686bc26937698f644194ddc9e34456dd11 test-suite.js
"
SERVER="tests/Compliance/socket-io-server.php"
READY_PATH="/socket.io/?EIO=4&transport=polling"

. "$(dirname "$0")/suite.sh"
