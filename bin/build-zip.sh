#!/usr/bin/env bash
#
# Build an installable plugin zip (node-red-wp.zip) from the committed files,
# leaving out everything marked export-ignore in .gitattributes.
#
set -euo pipefail
cd "$(dirname "$0")/.."
git archive --format=zip --prefix=node-red-wp/ -o node-red-wp.zip HEAD
echo "Built $(pwd)/node-red-wp.zip"
