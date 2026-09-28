#!/usr/bin/env bash
#
# Spin up a throw-away WordPress site (SQLite, PHP built-in web server) with this
# plugin symlinked and activated. Used by the integration tests and CI.
#
# Usage: tests/bin/setup-wordpress.sh [wp-version]   (default: latest release)
#
# Environment:
#   WP_TEST_DIR   Where to install (default: <repo>/.wp-test)
#   WP_PORT       Port for the web server (default: 8889)
#
set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_DIR="${WP_TEST_DIR:-$REPO_DIR/.wp-test}"
PORT="${WP_PORT:-8889}"
WP_URL="http://127.0.0.1:$PORT"
WP_VERSION="${1:-latest}"
SQLITE_VERSION="${SQLITE_VERSION:-v3.0.2}"

mkdir -p "$TEST_DIR"
cd "$TEST_DIR"

if [ "$WP_VERSION" = "latest" ]; then
	WP_VERSION="$(git ls-remote --tags https://github.com/WordPress/WordPress.git \
		| awk -F/ '{print $3}' | grep -E '^[0-9]+\.[0-9]+(\.[0-9]+)?$' | sort -V | tail -1)"
fi
echo "Installing WordPress $WP_VERSION into $TEST_DIR/wordpress"

if [ ! -f wp-cli.phar ]; then
	curl -fsSL -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi
WP="php $TEST_DIR/wp-cli.phar --path=$TEST_DIR/wordpress --allow-root"

rm -rf wordpress sqlite
git -c advice.detachedHead=false clone -q --depth 1 --branch "$WP_VERSION" https://github.com/WordPress/WordPress.git wordpress
git -c advice.detachedHead=false clone -q --depth 1 --branch "$SQLITE_VERSION" https://github.com/WordPress/sqlite-database-integration.git sqlite

# SQLite database drop-in (the plugin's database code is a symlink in the monorepo, so copy dereferenced).
SQLITE_PLUGIN_DIR=wordpress/wp-content/plugins/sqlite-database-integration
cp -RL sqlite/packages/plugin-sqlite-database-integration "$SQLITE_PLUGIN_DIR"
if [ -d sqlite/packages/mysql-on-sqlite/src ] && [ ! -e "$SQLITE_PLUGIN_DIR/wp-includes/database/load.php" ]; then
	rm -rf "$SQLITE_PLUGIN_DIR/wp-includes/database"
	cp -R sqlite/packages/mysql-on-sqlite/src "$SQLITE_PLUGIN_DIR/wp-includes/database"
fi
sed -e "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__.'/plugins/sqlite-database-integration'#g" \
	-e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
	"$SQLITE_PLUGIN_DIR/db.copy" > wordpress/wp-content/db.php

ln -sfn "$REPO_DIR" wordpress/wp-content/plugins/node-red-wp

$WP config create --dbname=wp --dbuser=wp --dbpass=wp --skip-check --quiet --extra-php <<PHP
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '$TEST_DIR/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
PHP

$WP core install --url="$WP_URL" --title="Node-RED WP Test" --admin_user=admin --admin_password=password \
	--admin_email=admin@example.com --skip-email --quiet
$WP option update home "$WP_URL" --quiet
$WP option update siteurl "$WP_URL" --quiet
$WP rewrite structure '/%postname%/' --quiet
$WP plugin activate sqlite-database-integration node-red-wp

# Application Password for the REST tests (works over plain HTTP on "local" sites).
$WP user application-password create admin tests --porcelain > app-password.txt
echo "$WP_URL" > url.txt

echo "Starting PHP web server on $WP_URL"
if [ -f server.pid ]; then kill -- -"$(cat server.pid)" 2>/dev/null || kill "$(cat server.pid)" 2>/dev/null || true; fi
# Own session (so it can be stopped with `kill -- -<pid>`), detached from our stdio.
# Single worker by default: PHP 8.4.26's experimental multi-worker mode (PHP_CLI_SERVER_WORKERS)
# dropped responses to wp-admin/post-new.php in CI. Set PHP_CLI_SERVER_WORKERS to opt back in.
(
	cd wordpress
	PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-1}" setsid php -S "127.0.0.1:$PORT" < /dev/null > "$TEST_DIR/server.log" 2>&1 &
	echo $! > "$TEST_DIR/server.pid"
)
for _ in $(seq 1 30); do
	curl -fs -o /dev/null "$WP_URL/wp-json/" 2>/dev/null && break
	sleep 0.5
done
curl -fsS -o /dev/null "$WP_URL/wp-json/"
echo "WordPress is ready at $WP_URL (admin / password). Stop it with: kill -- -$(cat "$TEST_DIR/server.pid")"
