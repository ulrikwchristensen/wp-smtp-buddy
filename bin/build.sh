#!/usr/bin/env bash
# Builds the release zip in dist/ from the files not listed in .distignore.
# Usage: bin/build.sh   (run from the plugin folder; needs rsync and zip)
set -euo pipefail

cd "$(dirname "$0")/.."
slug="$(basename "$PWD")"
version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$slug.php" | head -1)"
build="dist/build/$slug"

rm -rf dist/build && mkdir -p "$build"
rsync -a --exclude-from=.distignore ./ "$build/"

( cd dist/build && zip -qr "../$slug-$version.zip" "$slug" )
rm -rf dist/build

echo "dist/$slug-$version.zip"
