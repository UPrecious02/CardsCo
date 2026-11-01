<?php
/**
 * Plugin Name:       CardsCo
 * Plugin URI:        https://cardsco.uk
 * Description:       Corporate greeting card platform. Recipient management, occasion scheduling, campaigns and fulfilment.
 * Version:           0.9.1
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            PT Digital Studio
 * Author URI:        https://portfolio.precioustriumph.com
 * License:           GPL-2.0-or-later
 * Text Domain:       cardsco
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'CARDSCO_VERSION', '0.9.1' );
define( 'CARDSCO_FILE', __FILE__ );
define( 'CARDSCO_PATH', plugin_dir_path( __FILE__ ) );
define( 'CARDSCO_URL', plugin_dir_url( __FILE__ ) );
define( 'CARDSCO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Top level admin menu slug.
 *
 * Defined here rather than on the admin class because the post types hang
 * their menus off it and they register on `init`, which also runs on the
 * front end where the admin class is never loaded.
 */
define( 'CARDSCO_MENU_SLUG', 'cardsco' );

require_once CARDSCO_PATH . 'includes/functions.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-schema.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-roles.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-settings.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-validator.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-company.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-recipient.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-segment.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-occasion-type.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-occasion.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-csv.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-date-parser.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-import.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-merge.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-card.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-message.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-category.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-programme.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-programme-import.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-queue.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-box.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-scheduler.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-activator.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-deactivator.php';
require_once CARDSCO_PATH . 'includes/class-cardsco-plugin.php';

register_activation_hook( __FILE__, array( 'CardsCo_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CardsCo_Deactivator', 'deactivate' ) );

/**
 * Main plugin instance.
 *
 * @return CardsCo_Plugin
 */
function cardsco() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new CardsCo_Plugin();
	}

	return $instance;
}

cardsco()->init();
