<?php
/**
 * The Plan tab and the one-click upgrade (2.0).
 *
 * "Upgrade: $12/month" opens checkout in a new tab with a one-time claim ID from LeyMish's service. When the sale
 * completes, the payment provider's notification carries that claim ID to the service, which checks the new licence
 * and holds it for this site. This tab shows "Waiting for your purchase…" and activates by itself. Pasting the key
 * from the receipt always works too.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plan, licence and upgrade.
 */
class LASR_Plan {

	const CLAIM    = 'lasr_claim';
	const PRICING  = 'https://www.leymish.com/woocommerce/pro.html';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_lasr_upgrade', array( __CLASS__, 'handle_upgrade' ) );
		add_action( 'wp_ajax_lasr_upgrade_poll', array( __CLASS__, 'ajax_poll' ) );
		add_action( 'admin_post_lasr_license', array( __CLASS__, 'handle_license' ) );
		add_action( 'admin_post_lasr_plan_settings', array( __CLASS__, 'handle_settings' ) );
	}

	/**
	 * The one upgrade button (Plan tab and every Pro preview). Hidden when Pro is already on.
	 *
	 * @param string $from Where the click came from (counted on our side as a campaign tag, nothing personal).
	 */
	public static function button( $from ) {
		if ( LASR_License::is_pro() ) {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" target="_blank" class="lasr-upgrade-form" data-lasr-upgrade="1">';
		wp_nonce_field( 'lasr_upgrade' );
		echo '<input type="hidden" name="action" value="lasr_upgrade" /><input type="hidden" name="from" value="' . esc_attr( sanitize_key( $from ) ) . '" />';
		echo '<button type="submit" class="button button-primary lasr-upgrade">' . esc_html__( 'Upgrade: $12/month', 'leymish-ai-shopping-readiness' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens checkout in a new tab)', 'leymish-ai-shopping-readiness' ) . '</span></button>';
		echo '<span class="lasr-upgrade-wait" role="status" aria-live="polite" hidden>' . esc_html__( 'Waiting for your purchase… This page switches Pro on by itself.', 'leymish-ai-shopping-readiness' ) . '</span></form>';
	}

	/**
	 * Start a claim and send this (new) tab to checkout.
	 */
	public static function handle_upgrade() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_upgrade' );
		$r = LASR_Service::post( '/v1/claim/start', array( 'site' => LASR_Service::site() ) );
		if ( 200 !== $r['status'] || empty( $r['data']['claim'] ) || empty( $r['data']['checkout_url'] ) || 0 !== strpos( (string) $r['data']['checkout_url'], 'https://' ) ) {
			wp_redirect( self::PRICING . '?utm_source=plugin&utm_medium=plan&utm_campaign=upgrade-fallback' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- our own pricing page.
			exit;
		}
		update_option(
			self::CLAIM,
			array(
				'id' => sanitize_key( (string) $r['data']['claim'] ),
				't'  => time(),
			),
			false
		);
		wp_redirect( esc_url_raw( (string) $r['data']['checkout_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- checkout URL from LeyMish's service (https checked above).
		exit;
	}

	/**
	 * Poll: has the purchase arrived? If so, activate it here and say so.
	 */
	public static function ajax_poll() {
		check_ajax_referer( 'lasr_upgrade_poll' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'status' => 'forbidden' ), 403 );
		}
		if ( LASR_License::has_licence() ) {
			wp_send_json_success( array( 'status' => 'active' ) );
		}
		$c = get_option( self::CLAIM );
		if ( ! is_array( $c ) || empty( $c['id'] ) || time() - (int) $c['t'] > 2 * DAY_IN_SECONDS ) {
			wp_send_json_success( array( 'status' => 'none' ) );
		}
		$r = LASR_Service::post(
			'/v1/claim/poll',
			array(
				'claim' => $c['id'],
				'site'  => LASR_Service::site(),
			)
		);
		if ( 200 === $r['status'] && isset( $r['data']['status'] ) && 'ready' === $r['data']['status'] && ! empty( $r['data']['license_key'] ) ) {
			$state = LASR_License::activate( (string) $r['data']['license_key'] );
			delete_option( self::CLAIM );
			wp_send_json_success( array( 'status' => 'valid' === $state['status'] ? 'active' : 'failed' ) );
		}
		wp_send_json_success( array( 'status' => 404 === $r['status'] ? 'none' : 'waiting' ) );
	}

	/**
	 * Activate or remove a pasted key.
	 */
	public static function handle_license() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_license' );
		if ( isset( $_POST['remove'] ) ) {
			LASR_License::deactivate();
			$msg = 'removed';
		} else {
			$state = LASR_License::activate( isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '' );
			$msg   = $state['status'];
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=plan&lasr_msg=' . rawurlencode( $msg ) ) );
		exit;
	}

	/**
	 * Weekly email switch.
	 */
	public static function handle_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_plan_settings' );
		update_option( LASR_Schedule::EMAIL_OPT, empty( $_POST['weekly_email'] ) ? 'no' : 'yes', false );
		wp_safe_redirect( admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=plan&lasr_msg=saved' ) );
		exit;
	}

	/**
	 * What each plan includes (the honest split: everything on your site is free; Pro is services).
	 *
	 * @return array[] what, free, pro.
	 */
	public static function rows() {
		return array(
			array( __( 'Audit, score, fix list, CSV', 'leymish-ai-shopping-readiness' ), __( 'Yes', 'leymish-ai-shopping-readiness' ), __( 'Yes', 'leymish-ai-shopping-readiness' ) ),
			array( __( 'Products editor and one-click fixes', 'leymish-ai-shopping-readiness' ), __( 'Yes', 'leymish-ai-shopping-readiness' ), __( 'Yes', 'leymish-ai-shopping-readiness' ) ),
			array( __( 'OpenAI and Google feeds, llms.txt, UCP profile, schema', 'leymish-ai-shopping-readiness' ), __( 'Yes', 'leymish-ai-shopping-readiness' ), __( 'Yes, plus weekly validation from outside', 'leymish-ai-shopping-readiness' ) ),
			array( __( 'Weekly re-audit, alerts and email', 'leymish-ai-shopping-readiness' ), __( 'Yes', 'leymish-ai-shopping-readiness' ), __( 'Yes, plus AI crawlers blocked by your firewall or CDN', 'leymish-ai-shopping-readiness' ) ),
			array( __( 'Before/after report', 'leymish-ai-shopping-readiness' ), __( 'Yes', 'leymish-ai-shopping-readiness' ), __( 'Yes, with AI visibility', 'leymish-ai-shopping-readiness' ) ),
			array( __( 'AI visibility: do ChatGPT, Perplexity and Google cite you?', 'leymish-ai-shopping-readiness' ), __( 'One check of 3 questions', 'leymish-ai-shopping-readiness' ), __( '10 questions every week, with the trend', 'leymish-ai-shopping-readiness' ) ),
			array( __( 'AI fixes (you approve each one)', 'leymish-ai-shopping-readiness' ), __( '20 to try', 'leymish-ai-shopping-readiness' ), __( '500 a month', 'leymish-ai-shopping-readiness' ) ),
			array( __( 'Store Team', 'leymish-ai-shopping-readiness' ), __( 'The weekly plan', 'leymish-ai-shopping-readiness' ), __( 'Agents do the plan\'s work; you approve each change', 'leymish-ai-shopping-readiness' ) ),
		);
	}

	/**
	 * The tab.
	 */
	public static function render() {
		$state = LASR_License::state();
		$lic   = LASR_License::has_licence();
		$pro   = LASR_License::is_pro();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only code from our own redirect.
		$msg = isset( $_GET['lasr_msg'] ) ? sanitize_key( wp_unslash( $_GET['lasr_msg'] ) ) : '';
		LASR_Admin::three(
			/* translators: %s: plan name. */
			sprintf( __( 'Nothing is locked. You\'re on %s.', 'leymish-ai-shopping-readiness' ), LASR_License::plan_label() ),
			__( 'Everything that runs on your site is free and stays free.', 'leymish-ai-shopping-readiness' ),
			$pro ? __( 'Pro is on: AI visibility, AI fixes, monitoring and your agents are working.', 'leymish-ai-shopping-readiness' ) : __( 'Upgrade for the services: AI visibility, AI fixes, monitoring and agents.', 'leymish-ai-shopping-readiness' ),
			null
		);
		if ( $msg ) {
			$ok = in_array( $msg, array( 'valid', 'saved', 'removed' ), true );
			$tx = 'saved' === $msg ? __( 'Saved.', 'leymish-ai-shopping-readiness' ) : ( 'removed' === $msg ? __( 'Licence removed from this store.', 'leymish-ai-shopping-readiness' ) : (string) $state['message'] );
			echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' is-dismissible inline"><p>' . esc_html( $tx ) . '</p></div>';
		}
		echo '<div class="lasr-plan-grid"><section class="lasr-card-box lasr-plan-card"><h3>' . esc_html__( 'LeyMish Pro', 'leymish-ai-shopping-readiness' ) . '</h3>';
		echo '<p class="lasr-price-big">$12<span>' . esc_html__( '/month', 'leymish-ai-shopping-readiness' ) . '</span></p><p class="description">' . esc_html__( 'or $99 a year, per store. Agencies: $39 a month for up to 10 stores. Cancel any time; the free plugin keeps working.', 'leymish-ai-shopping-readiness' ) . '</p>';
		if ( $pro ) {
			/* translators: %s: plan name. */
			echo '<p class="lasr-pro-on"><span class="lasr-badge lasr-pass">' . esc_html__( 'Active', 'leymish-ai-shopping-readiness' ) . '</span> ' . esc_html( LASR_License::plan_label() ) . '</p>';
			$inc = LASR_License::included();
			if ( ! $lic && ! empty( $inc['ends_at'] ) ) {
				/* translators: %s: date. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Included until %s.', 'leymish-ai-shopping-readiness' ), wp_date( get_option( 'date_format' ), (int) $inc['ends_at'] ) ) ) . '</p>';
			}
		} else {
			self::button( 'plan' );
		}
		echo '</section><section class="lasr-card-box"><table class="widefat lasr-table lasr-compare"><thead><tr><th scope="col"><span class="screen-reader-text">' . esc_html__( 'What', 'leymish-ai-shopping-readiness' ) . '</span></th><th scope="col">' . esc_html__( 'Free', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Pro', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
		foreach ( self::rows() as $r ) {
			echo '<tr><th scope="row">' . esc_html( $r[0] ) . '</th><td>' . esc_html( $r[1] ) . '</td><td>' . esc_html( $r[2] ) . '</td></tr>';
		}
		echo '</tbody></table></section></div>';

		// P-031 §3: the weekly loop, in the start guide's words. Measured results only; no promise of sales.
		$loop = array(
			array( __( 'Measure', 'leymish-ai-shopping-readiness' ), __( 'Score, product gaps, AI citations, visitors, Google clicks, orders.', 'leymish-ai-shopping-readiness' ) ),
			array( __( 'Plan', 'leymish-ai-shopping-readiness' ), __( 'The CEO agent picks this week\'s three biggest gaps against your sales goal.', 'leymish-ai-shopping-readiness' ) ),
			array( __( 'Draft', 'leymish-ai-shopping-readiness' ), __( 'Product data, titles and FAQs from real searches, cross-sells, links, review requests.', 'leymish-ai-shopping-readiness' ) ),
			array( __( 'Approve', 'leymish-ai-shopping-readiness' ), __( 'You approve, edit or reject each change in LeyMish → Team.', 'leymish-ai-shopping-readiness' ) ),
			array( __( 'Apply', 'leymish-ai-shopping-readiness' ), __( 'Approved changes go live, each with an undo.', 'leymish-ai-shopping-readiness' ) ),
			array( __( 'Report', 'leymish-ai-shopping-readiness' ), __( 'Visitors, clicks, AI citations, orders and what changed, by email. Then again.', 'leymish-ai-shopping-readiness' ) ),
		);
		echo '<h2>' . esc_html__( 'One weekly loop: more stores find you, more visitors buy', 'leymish-ai-shopping-readiness' ) . '</h2><ol class="lasr-loop">';
		foreach ( $loop as $step ) {
			echo '<li><strong>' . esc_html( $step[0] ) . '</strong><span>' . esc_html( $step[1] ) . '</span></li>';
		}
		echo '</ol><p class="description">' . esc_html__( 'Free measures; Pro and Store Team work the loop every week. We don\'t promise more sales: every number in the report is measured on your store.', 'leymish-ai-shopping-readiness' ) . '</p>';

		echo '<h2>' . esc_html__( 'Licence', 'leymish-ai-shopping-readiness' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-card-box">';
		wp_nonce_field( 'lasr_license' );
		echo '<input type="hidden" name="action" value="lasr_license" />';
		if ( $lic ) {
			$key    = (string) $state['key'];
			$masked = str_repeat( '•', max( 0, strlen( $key ) - 4 ) ) . substr( $key, -4 );
			/* translators: %s: masked licence key. */
			echo '<p>' . esc_html( sprintf( __( 'Active on this store: %s', 'leymish-ai-shopping-readiness' ), $masked ) ) . '</p>';
			submit_button( __( 'Remove licence from this store', 'leymish-ai-shopping-readiness' ), 'secondary', 'remove', false );
		} else {
			echo '<p><label for="lasr-key">' . esc_html__( 'Already bought? Paste the key from your receipt:', 'leymish-ai-shopping-readiness' ) . '</label><br><input type="text" id="lasr-key" name="license_key" class="regular-text" autocomplete="off" placeholder="XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX" /> ';
			submit_button( __( 'Activate', 'leymish-ai-shopping-readiness' ), 'secondary', 'submit', false );
			echo '</p><p class="description">' . esc_html__( 'Activating sends the key and your store\'s address to LeyMish to check it. Nothing else leaves your site.', 'leymish-ai-shopping-readiness' ) . '</p>';
		}
		echo '</form>';

		echo '<h2>' . esc_html__( 'Weekly email', 'leymish-ai-shopping-readiness' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-card-box">';
		wp_nonce_field( 'lasr_plan_settings' );
		echo '<input type="hidden" name="action" value="lasr_plan_settings" /><p><label><input type="checkbox" name="weekly_email" value="1" ' . checked( 'yes', get_option( LASR_Schedule::EMAIL_OPT, 'yes' ), false ) . ' /> ' . esc_html__( 'Re-run the audit every week and email the score, any alerts and the top fixes to the site admin address (sent by your own site).', 'leymish-ai-shopping-readiness' ) . '</label></p>';
		submit_button( __( 'Save', 'leymish-ai-shopping-readiness' ), 'secondary', 'submit', false );
		echo '</form>';
	}
}
