<?php
/**
 * Plugin Name:          LeyMish AI Shopping Readiness
 * Plugin URI:           https://www.leymish.com/woocommerce/
 * Description:          Checks whether AI shopping agents (ChatGPT, Claude, Perplexity, Google) can find, read and trust your WooCommerce products. A 0–100 score, a prioritised fix list and a CSV export. Runs entirely on your site.
 * Version:              1.0.2
 * Requires at least:    6.4
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               LeyMish Labs
 * Author URI:           https://www.leymish.com/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          leymish-ai-shopping-readiness
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.1
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LASR_VERSION', '1.0.2' );
define( 'LASR_FILE', __FILE__ );
define( 'LASR_DIR', plugin_dir_path( __FILE__ ) );

require_once LASR_DIR . 'includes/class-lasr-gtin.php';
require_once LASR_DIR . 'includes/class-lasr-robots.php';
require_once LASR_DIR . 'includes/class-lasr-jsonld.php';
require_once LASR_DIR . 'includes/class-lasr-scoring.php';

// Declare compatibility with WooCommerce's order tables (HPOS); the plugin doesn't touch orders.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', 'lasr_boot' );

/**
 * Load the WooCommerce-dependent parts once WooCommerce is available.
 */
function lasr_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'lasr_notice_needs_woocommerce' );
		return;
	}
	require_once LASR_DIR . 'includes/class-lasr-audit.php';
	if ( is_admin() ) {
		require_once LASR_DIR . 'includes/class-lasr-admin.php';
		LASR_Admin::init();
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once LASR_DIR . 'includes/class-lasr-cli.php';
		WP_CLI::add_command( 'lasr', 'LASR_CLI' );
	}
}

/**
 * Shown only if WooCommerce isn't active.
 */
function lasr_notice_needs_woocommerce() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'LeyMish AI Shopping Readiness needs WooCommerce to be active.', 'leymish-ai-shopping-readiness' ) . '</p></div>';
}
