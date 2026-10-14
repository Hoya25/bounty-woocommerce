#!/usr/bin/env sh
# Builds the installable plugin zip: build/open-bounty-<version>.zip
# Contains only what WordPress runs. Tests, dev tooling and screenshots stay out.
set -e
cd "$(dirname "$0")/.."

VERSION=$(sed -n 's/^ \* Version: *//p' open-bounty.php | tr -d '[:space:]')
STAGE=build/stage/open-bounty
rm -rf build && mkdir -p "$STAGE"
cp -R open-bounty.php uninstall.php readme.txt LICENSE includes assets "$STAGE/"
(cd build/stage && zip -qr "../open-bounty-$VERSION.zip" open-bounty -x '*.DS_Store')
rm -rf build/stage
echo "build/open-bounty-$VERSION.zip"
