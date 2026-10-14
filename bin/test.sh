#!/usr/bin/env sh
# Runs the PHP unit tests. Fetches PHPUnit as a phar on first run (not committed).
set -e
cd "$(dirname "$0")/.."
mkdir -p dev
[ -f dev/phpunit.phar ] || curl -sSL -o dev/phpunit.phar https://phar.phpunit.de/phpunit-11.5.phar
php dev/phpunit.phar "$@"
