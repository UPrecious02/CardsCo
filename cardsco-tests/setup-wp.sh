#!/usr/bin/env bash
# Build a real WordPress on SQLite for the suites to run against.
#
# Rebuilt after the container was replaced. Same shape as before: core lives
# in $WORK/core, the plugin is symlinked in rather than copied so edits are
# picked up without re-running this, and the admin user is the one the
# integration suites expect.
set -euo pipefail

WORK="${1:?usage: setup-wp.sh /path/to/workdir}"
PLUGIN=/home/claude/cardsco

rm -rf "$WORK"
mkdir -p "$WORK"
cd "$WORK"

# wordpress.org and the GitHub tarball endpoints are both refused by this
# container's proxy; git over https is not, so both come down as clones.
CACHE=/tmp/claude-0/-home-claude/292333dd-ab9e-5900-870f-ca982382c9d5/scratchpad

echo "fetching WordPress..."
if [ ! -d "$CACHE/wpsrc" ]; then
	git clone --depth 1 -q https://github.com/WordPress/WordPress.git "$CACHE/wpsrc"
fi
cp -a "$CACHE/wpsrc" core
rm -rf core/.git

echo "fetching the SQLite drop-in..."
if [ ! -d "$CACHE/sqlite-db" ]; then
	git clone --depth 1 -q https://github.com/WordPress/sqlite-database-integration.git "$CACHE/sqlite-db"
fi
mkdir -p core/wp-content/plugins
# The repo was restructured: the drop-in now lives under packages/ rather
# than at the root, and that package folder is the whole plugin.
cp -a "$CACHE/sqlite-db/packages/plugin-sqlite-database-integration" \
	core/wp-content/plugins/sqlite-database-integration
# The plugin package is not complete on its own: the repo's own build step
# copies the mysql-on-sqlite source in as wp-includes/database. Without it
# the drop-in fatals on a missing database/version.php.
rm -rf core/wp-content/plugins/sqlite-database-integration/wp-includes/database
cp -R "$CACHE/sqlite-db/packages/mysql-on-sqlite/src" \
	core/wp-content/plugins/sqlite-database-integration/wp-includes/database

cp core/wp-content/plugins/sqlite-database-integration/db.copy core/wp-content/db.php
# db.copy points at the plugin by placeholder; fill those in.
sed -i "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WORK/core/wp-content/plugins/sqlite-database-integration#g" core/wp-content/db.php
sed -i "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" core/wp-content/db.php

cat > core/wp-config.php <<PHP
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

\$table_prefix = 'wp_';

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', '$WORK/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME', 'http://cardsco.test' );
define( 'WP_SITEURL', 'http://cardsco.test' );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );

foreach ( array( 'AUTH', 'SECURE_AUTH', 'LOGGED_IN', 'NONCE' ) as \$k ) {
	define( \$k . '_KEY',  \$k . '-key-for-tests' );
	define( \$k . '_SALT', \$k . '-salt-for-tests' );
}

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP

# The plugin is linked, not copied, so edits take effect immediately.
ln -sfn "$PLUGIN" core/wp-content/plugins/cardsco

echo "installing..."
# WP_INSTALLING must be defined BEFORE wp-load, or wp-load sees an
# uninstalled site, redirects to install.php and exits — taking the rest of
# the script with it. That failure is silent: exit status 0, no output, no
# debug log. An earlier version of this script hit it and still printed
# "ready", so the check at the end is not optional.
cat > "$WORK/_install.php" <<'PHP'
<?php
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST']       = 'cardsco.test';
$_SERVER['REQUEST_URI']     = '/wp-admin/install.php';
$_SERVER['REQUEST_METHOD']  = 'GET';
require __DIR__ . '/core/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if ( ! is_blog_installed() ) {
	wp_install( 'CardsCo Test', 'admin', 'admin@cardsco.test', true, '', 'password' );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$r = activate_plugin( 'cardsco/cardsco.php' );
file_put_contents(
	__DIR__ . '/_install.log',
	is_wp_error( $r ) ? 'activate failed: ' . $r->get_error_message() : 'ok'
);
PHP
php "$WORK/_install.php" > /dev/null 2>&1 || true

# Prove it, rather than trust it.
cat > "$WORK/_check.php" <<'PHP'
<?php
$_SERVER['HTTP_HOST']      = 'cardsco.test';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
require __DIR__ . '/core/wp-load.php';
global $wpdb;
$tables = $wpdb->get_col( 'SELECT name FROM sqlite_master WHERE type="table" AND name LIKE "wp_cardsco_%"' );
file_put_contents(
	__DIR__ . '/_check.log',
	get_bloginfo( 'version' ) . '|'
	. ( in_array( 'cardsco/cardsco.php', (array) get_option( 'active_plugins' ), true ) ? 'active' : 'inactive' ) . '|'
	. count( $tables )
);
PHP
rm -f "$WORK/_check.log"
php "$WORK/_check.php" > /dev/null 2>&1 || true

if [ ! -s "$WORK/_check.log" ]; then
	echo "setup failed: WordPress does not boot (see $WORK/debug.log)" >&2
	exit 1
fi

# `|| true` because the file has no trailing newline, so read reports failure
# even though it filled the variables, and set -e would kill the script here.
IFS='|' read -r VER STATE TABLES < "$WORK/_check.log" || true

if [ "$STATE" != "active" ] || [ "${TABLES:-0}" -lt 16 ]; then
	echo "setup failed: plugin $STATE, $TABLES tables (expected 16)" >&2
	cat "$WORK/_install.log" 2>/dev/null >&2
	exit 1
fi

echo "ready: WP $VER on sqlite, plugin active, $TABLES tables"
