<?php
/**
 * First-run checklist, the optional weekly-tip opt-in, and the sample-store link (P-023 §3).
 *
 * Why this exists: WordPress.org gives us a download count and nothing else — no email, no site, no way to
 * follow anyone up. 258 zip fetches is not 258 people, and even the real installs are anonymous to us. So the
 * only honest place to turn an install into a conversation is inside the plugin, with the owner's consent.
 *
 * Three things, and deliberately no fourth:
 *
 *   1. A checklist on first run: run the audit, fix your top three, look at Impact. It disappears once the
 *      owner has run an audit; it is not a nag and it never reappears once dismissed.
 *   2. An **unticked** checkbox offering a weekly tip and the store's score. Unticked is the whole point:
 *      WordPress.org's guidelines forbid opting anyone in by default, and so does common decency.
 *   3. A link to a sample store on WordPress Playground, for people who want to see the thing work before
 *      pointing it at their own catalogue.
 *
 * Nothing here phones home unless the owner ticks the box and presses the button. The audit itself has always
 * been local.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The first-run checklist and the opt-in.
 */
class LASR_Onboarding {

	const DISMISSED = 'lasr_onboarding_done';
	const SUBSCRIBED = 'lasr_tips_subscribed';
	const API = 'https://leymish-ai.leymish.workers.dev/v1/check/subscribe';
	const SAMPLE = 'https://playground.wordpress.net/?blueprint-url=https://www.leymish.com/woocommerce/downloads/sample-store.json';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_lasr_dismiss_onboarding', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_post_lasr_subscribe', array( __CLASS__, 'handle_subscribe' ) );
	}

	/**
	 * The exact words next to the checkbox. Sent with the consent and kept, so we can always show what was
	 * agreed to. Must match PLUGIN_CONSENT_TEXT in the service (products/006-pro-ai/worker/src/beta.js).
	 *
	 * @return string
	 */
	public static function consent_text() {
		return __( 'Email me a weekly store tip and my AI-readiness score. I can unsubscribe from any email.', 'leymish-ai-shopping-readiness' );
	}

	/**
	 * Show the checklist? Only before the first audit, and only until it is dismissed.
	 *
	 * @param array|null $result Last audit.
	 * @return bool
	 */
	public static function show( $result ) {
		return ! get_option( self::DISMISSED ) && empty( $result );
	}

	/**
	 * The first-run checklist: three steps, in the order that actually helps.
	 *
	 * @param array|null $result Last audit.
	 */
	public static function render_checklist( $result ) {
		if ( ! self::show( $result ) ) {
			return;
		}
		echo '<div class="lasr-onboarding"><h2>' . esc_html__( 'Three steps to start', 'leymish-ai-shopping-readiness' ) . '</h2><ol>';
		echo '<li><strong>' . esc_html__( 'Run the audit.', 'leymish-ai-shopping-readiness' ) . '</strong> ' .
			esc_html__( 'It requests a few of your own pages the way each AI crawler does, and scores what it finds. Everything stays on your server.', 'leymish-ai-shopping-readiness' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Fix your top three.', 'leymish-ai-shopping-readiness' ) . '</strong> ' .
			esc_html__( 'The audit sorts what it found by how much it costs you and how long it takes to fix. The first three are usually an afternoon.', 'leymish-ai-shopping-readiness' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Run it again and open Impact.', 'leymish-ai-shopping-readiness' ) . '</strong> ' .
			esc_html__( 'Impact shows your score over time and exactly which checks changed, so you can see whether the work landed.', 'leymish-ai-shopping-readiness' ) . '</li>';
		echo '</ol>';
		echo '<p><a href="' . esc_url( self::SAMPLE ) . '" target="_blank" rel="noopener">' .
			esc_html__( 'Prefer to try it on a sample store first?', 'leymish-ai-shopping-readiness' ) .
			'</a> <span class="description">' . esc_html__( 'Opens a throwaway WordPress in your browser. Nothing is installed on this site.', 'leymish-ai-shopping-readiness' ) . '</span></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-dismiss">';
		wp_nonce_field( 'lasr_dismiss_onboarding' );
		echo '<input type="hidden" name="action" value="lasr_dismiss_onboarding" />';
		submit_button( __( 'Hide this', 'leymish-ai-shopping-readiness' ), 'link', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * The opt-in box. Unticked, with the consent wording visible, and gone once someone has subscribed.
	 */
	public static function render_optin() {
		if ( get_option( self::SUBSCRIBED ) ) {
			echo '<p class="description lasr-optin-done">' . esc_html__( 'You are on the weekly tip. Every email has an unsubscribe link.', 'leymish-ai-shopping-readiness' ) . '</p>';
			return;
		}
		$admin_email = get_option( 'admin_email' );
		echo '<div class="lasr-optin"><h2>' . esc_html__( 'One tip a week (optional)', 'leymish-ai-shopping-readiness' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lasr_subscribe' );
		echo '<input type="hidden" name="action" value="lasr_subscribe" />';
		echo '<p><label for="lasr-email">' . esc_html__( 'Email', 'leymish-ai-shopping-readiness' ) . '</label><br />';
		echo '<input type="email" id="lasr-email" name="email" class="regular-text" value="' . esc_attr( $admin_email ) . '" required /></p>';
		// Unticked, always. WordPress.org's guidelines and the law both want an affirmative act.
		echo '<p><label><input type="checkbox" name="consent" value="1" /> ' . esc_html( self::consent_text() ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Your email and your store address are sent to LeyMish Labs and kept only to send these emails. We do not sell or share them, and the audit itself never leaves your server. See "External services" in the plugin readme.', 'leymish-ai-shopping-readiness' ) . '</p>';
		submit_button( __( 'Send me the weekly tip', 'leymish-ai-shopping-readiness' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Hide the checklist for good.
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_dismiss_onboarding' );
		update_option( self::DISMISSED, time(), false );
		wp_safe_redirect( admin_url( 'admin.php?page=' . LASR_Admin::SLUG ) );
		exit;
	}

	/**
	 * Record the opt-in with the service. Nothing is sent unless the box was ticked on this submission.
	 */
	public static function handle_subscribe() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_subscribe' );
		$back  = admin_url( 'admin.php?page=' . LASR_Admin::SLUG );
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( empty( $_POST['consent'] ) || ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'lasr_sub', 'need_consent', $back ) );
			exit;
		}
		$r = wp_remote_post(
			self::API,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'email'   => $email,
					'domain'  => wp_parse_url( home_url(), PHP_URL_HOST ),
					'consent' => true,
				) ),
			)
		);
		$ok = ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r );
		if ( $ok ) {
			update_option( self::SUBSCRIBED, time(), false );
		}
		wp_safe_redirect( add_query_arg( 'lasr_sub', $ok ? 'ok' : 'failed', $back ) );
		exit;
	}

	/**
	 * The message after a subscribe attempt.
	 */
	public static function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only key from our own redirect.
		$key = isset( $_GET['lasr_sub'] ) ? sanitize_key( wp_unslash( $_GET['lasr_sub'] ) ) : '';
		$msgs = array(
			'ok'           => __( 'Done. Check your inbox for a confirmation; every email has an unsubscribe link.', 'leymish-ai-shopping-readiness' ),
			'need_consent' => __( 'Nothing was sent: tick the box and enter a valid email address.', 'leymish-ai-shopping-readiness' ),
			'failed'       => __( 'Could not reach LeyMish just now. Nothing was sent; try again later.', 'leymish-ai-shopping-readiness' ),
		);
		if ( isset( $msgs[ $key ] ) ) {
			echo '<div class="notice notice-' . ( 'ok' === $key ? 'success' : 'warning' ) . ' is-dismissible"><p>' . esc_html( $msgs[ $key ] ) . '</p></div>';
		}
	}
}
