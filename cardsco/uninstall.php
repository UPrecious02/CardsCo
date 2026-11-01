<?php
/**
 * Uninstall.
 *
 * Deleting the plugin does NOT delete recipient or card data by default.
 * Dropping tables is deliberately opt-in: add
 *
 *     define( 'CARDSCO_REMOVE_DATA_ON_UNINSTALL', true );
 *
 * to wp-config.php, or set the cardsco_remove_data_on_uninstall option to 1,
 * and only then does removing the plugin clear everything.
 *
 * @package CardsCo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$cardsco_path = plugin_dir_path( __FILE__ );

require_once $cardsco_path . 'includes/class-cardsco-schema.php';
require_once $cardsco_path . 'includes/functions.php';
require_once $cardsco_path . 'includes/class-cardsco-roles.php';

$cardsco_remove = ( defined( 'CARDSCO_REMOVE_DATA_ON_UNINSTALL' ) && CARDSCO_REMOVE_DATA_ON_UNINSTALL )
	|| get_option( 'cardsco_remove_data_on_uninstall' );

if ( ! $cardsco_remove ) {
	return;
}

CardsCo_Schema::drop();
CardsCo_Roles::remove();

delete_option( 'cardsco_version' );
delete_option( 'cardsco_installed_at' );
delete_option( 'cardsco_remove_data_on_uninstall' );
