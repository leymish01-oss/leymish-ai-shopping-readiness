<?php
/**
 * Plugin Name:          LeyMish AI Readiness
 * Plugin URI:           https://www.leymish.com/woocommerce/
 * Description:          Can ChatGPT, Google and Perplexity find, read and trust your WooCommerce products? A 0–100 audit, a products editor with one-click fixes, OpenAI and Google feeds, llms.txt, a UCP profile and richer product schema, all running on your site, free. Optional LeyMish Pro services: AI visibility checks, AI fixes you approve, outside monitoring and the Store Team agents.
 * Version:              2.0.1
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

define( 'LASR_VERSION', '2.0.1' );
define( 'LASR_FILE', __FILE__ );
define( 'LASR_DIR', plugin_dir_path( __FILE__ ) );

register_activation_hook( __FILE__, 'lasr_activate' );
register_deactivation_hook( __FILE__, 'lasr_deactivate' );

/**
 * First activation only (no audit yet): one welcome notice on the Plugins screen. Feed, llms.txt and UCP addresses
 * are registered on the next request, when their rules are added.
 */
function lasr_activate() {
	if ( ! get_option( 'lasr_last_audit' ) ) {
		add_option( 'lasr_welcome', 1, '', false );
	}
	delete_option( 'lasr_db_version' ); // lets the migration and the rewrite flush run once on the next request
}

/**
 * Remove scheduled jobs and our addresses.
 */
function lasr_deactivate() {
	foreach ( array( 'lasr_weekly', 'lasr_license_recheck', 'lasr_rebuild_feeds', 'lasr_visibility_weekly' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
	flush_rewrite_rules();
}

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
	foreach ( array( 'service', 'audit', 'impact', 'license', 'feeds', 'llms', 'ucp', 'worklog', 'schedule', 'visibility', 'team', 'migrate' ) as $part ) {
		require_once LASR_DIR . 'includes/class-lasr-' . $part . '.php';
	}
	LASR_Impact::init(); // records a snapshot after every audit, including WP-CLI and the weekly run
	LASR_License::init();
	LASR_Feeds::init();
	LASR_Llms::init();
	LASR_Ucp::init();
	LASR_Schedule::init();
	LASR_Visibility::init();
	LASR_Migrate::init();
	if ( is_admin() ) {
		foreach ( array( 'admin', 'dashboard', 'onboarding', 'products', 'ai-logic', 'ai', 'plan' ) as $part ) {
			require_once LASR_DIR . 'includes/class-lasr-' . $part . '.php';
		}
		LASR_Admin::init();
		LASR_Products::init();
		LASR_AI::init();
		LASR_Team::init();
		LASR_Plan::init();
		LASR_Worklog::init();
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
	echo '<div class="notice notice-warning"><p>' . esc_html__( 'LeyMish AI Readiness needs WooCommerce to be active.', 'leymish-ai-shopping-readiness' ) . '</p></div>';
}
