<?php
/**
 * Plugin Name:       Open Bounty
 * Plugin URI:        https://github.com/Hoya25/bounty-woocommerce
 * Description:       Tell AI shopping agents what a purchase at your store pays the shopper. Publishes an Open Bounty Schema object at /.well-known/bounty.json and on every product page.
 * Version:           1.7.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            NCTR Alliance
 * Author URI:        https://themall.nctr.live
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       open-bounty
 *
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 */

defined( 'ABSPATH' ) || exit;

define( 'OPEN_BOUNTY_VERSION', '1.7.1' );
define( 'OPEN_BOUNTY_FILE', __FILE__ );
define( 'OPEN_BOUNTY_DIR', __DIR__ );

require_once __DIR__ . '/includes/class-gate.php';
require_once __DIR__ . '/includes/class-schema.php';
require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-rewards.php';
require_once __DIR__ . '/includes/class-smile.php';
require_once __DIR__ . '/includes/class-yotpo.php';
require_once __DIR__ . '/includes/class-loyaltylion.php';
require_once __DIR__ . '/includes/class-klaviyo.php';
require_once __DIR__ . '/includes/class-render.php';
require_once __DIR__ . '/includes/class-well-known.php';
require_once __DIR__ . '/includes/class-product.php';
require_once __DIR__ . '/includes/class-settings.php';

Open_Bounty_Well_Known::init();
Open_Bounty_Product::init();
Open_Bounty_Settings::init();

register_activation_hook( __FILE__, array( 'Open_Bounty_Well_Known', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Open_Bounty_Well_Known', 'deactivate' ) );

// Declare compatibility with WooCommerce's order tables. This plugin never
// reads or writes orders, so there is nothing to be incompatible with.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
