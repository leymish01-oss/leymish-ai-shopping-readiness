<?php
/**
 * "Start here" (2.1): the start guide inside the plugin, trimmed to the owner's plan, with a ✓ on each step that is
 * really done. Local only: nothing is loaded from outside. It names what the owner clicks and sees, never how the
 * services work inside.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The guide view, the Overview card and the step checks.
 */
class LASR_Start {

	const DISMISS = 'lasr_start_dismissed';

	/**
	 * Every menu path and button label the guide names. build.py fails the build if one of these is not in the
	 * plugin's own screens, so the guide can't drift from the UI (P-029).
	 *
	 * @var string[]
	 */
	const LABELS = array( 'Run the audit', 'Run the audit again', 'Your next 3 wins', 'See it the way AI sees it', 'Set brand for all',
		'use each SKU as the MPN', 'GTINs from a supplier CSV', 'Weekly email', 'Upgrade: $12/month', 'Connect this store',
		'Edit then approve', 'Approve', 'Reject', 'Check now', 'Save questions' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_lasr_start_dismiss', array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * Step states (✓ only when really done).
	 *
	 * @return array<string,bool>
	 */
	public static function state() {
		$log     = class_exists( 'LASR_Worklog' ) ? LASR_Worklog::worklog() : array( 'counts' => array() );
		$history = class_exists( 'LASR_Impact' ) ? LASR_Impact::history() : array();
		$ucp     = class_exists( 'LASR_Ucp' ) ? LASR_Ucp::settings() : array();
		$events  = class_exists( 'LASR_Worklog' ) ? LASR_Worklog::events() : array();
		return array(
			'audit'      => (bool) LASR_Audit::last(),
			'fix'        => array_sum( array_map( 'intval', (array) $log['counts'] ) ) > 0,
			'feeds'      => class_exists( 'LASR_Feeds' ) && LASR_Feeds::enabled() && isset( $ucp['identifiers'] ) && 'yes' === $ucp['identifiers'],
			'again'      => count( $history ) > 1,
			'email'      => 'no' !== get_option( 'lasr_weekly_email', 'yes' ),
			'approval'   => (bool) array_filter( $events, function ( $e ) {
				return isset( $e['type'] ) && 'approval' === $e['type'];
			} ),
			'visibility' => class_exists( 'LASR_Visibility' ) && (bool) LASR_Visibility::latest(),
		);
	}

	/**
	 * The guide's steps for a plan (pure over a state map). Each: key, title, where, minutes.
	 *
	 * @param bool $pro Pro (or Team) is on.
	 * @return array[]
	 */
	public static function steps( $pro ) {
		$free = array(
			array( 'audit', __( 'Run your first audit', 'leymish-ai-shopping-readiness' ), __( 'LeyMish → Overview → Run the audit', 'leymish-ai-shopping-readiness' ), '1' ),
			array( 'audit', __( 'Read your score and "Your next 3 wins"', 'leymish-ai-shopping-readiness' ), __( 'Each win shows the points it adds and a Fix it button.', 'leymish-ai-shopping-readiness' ), '1' ),
			array( 'fix', __( 'Fix product gaps', 'leymish-ai-shopping-readiness' ), __( 'LeyMish → Products: Set brand for all, use each SKU as the MPN (only if you make the products), GTINs from a supplier CSV. Open See it the way AI sees it.', 'leymish-ai-shopping-readiness' ), '5' ),
			array( 'feeds', __( 'Switch on feeds and schema', 'leymish-ai-shopping-readiness' ), __( 'LeyMish → Feeds: the OpenAI and Google feeds, llms.txt, the UCP profile and product schema; set your real return policy, then Save.', 'leymish-ai-shopping-readiness' ), '3' ),
			array( 'again', __( 'Run the audit again', 'leymish-ai-shopping-readiness' ), __( 'LeyMish → Overview → Run the audit again. "What we fixed" shows your score change.', 'leymish-ai-shopping-readiness' ), '1' ),
			array( 'email', __( 'Keep the weekly email on', 'leymish-ai-shopping-readiness' ), __( 'LeyMish → Plan → Weekly email: your site re-checks itself every week and emails the score and alerts.', 'leymish-ai-shopping-readiness' ), '0.5' ),
		);
		$weekly = array(
			array( 'approval', __( 'Approve your team\'s proposals', 'leymish-ai-shopping-readiness' ), __( 'LeyMish → Team: compare Now with Proposed, then Approve, Edit then approve, or Reject. Nothing changes until you approve.', 'leymish-ai-shopping-readiness' ), '5' ),
			array( 'visibility', __( 'Check your AI visibility', 'leymish-ai-shopping-readiness' ), __( 'LeyMish → AI visibility: Save questions that sound like your shoppers, then Check now. Pro asks them every week.', 'leymish-ai-shopping-readiness' ), '2' ),
		);
		return $pro ? array_merge( $weekly, $free ) : $free;
	}

	/**
	 * Show the Overview card until the first audit and one fix are done (or it is dismissed).
	 *
	 * @return bool
	 */
	public static function card_due() {
		$s = self::state();
		return ! get_option( self::DISMISS ) && ! ( $s['audit'] && $s['fix'] );
	}

	/**
	 * The Overview card.
	 */
	public static function card() {
		if ( ! self::card_due() ) {
			return;
		}
		echo '<div class="lasr-start-card notice notice-info inline"><p><strong>' . esc_html__( 'New here? The 15-minute start.', 'leymish-ai-shopping-readiness' ) . '</strong> ';
		echo esc_html__( 'Seven short steps from install to AI-ready, ticked off as you do them.', 'leymish-ai-shopping-readiness' ) . ' ';
		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=start' ) ) . '">' . esc_html__( 'Start here', 'leymish-ai-shopping-readiness' ) . '</a> ';
		echo '<a class="button-link" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lasr_start_dismiss' ), 'lasr_start_dismiss' ) ) . '">' . esc_html__( 'Hide', 'leymish-ai-shopping-readiness' ) . '</a></p></div>';
	}

	/**
	 * The guide view.
	 */
	public static function render() {
		$pro   = LASR_License::is_pro();
		$state = self::state();
		$steps = self::steps( $pro );
		$done  = count( array_filter( $steps, function ( $s ) use ( $state ) {
			return ! empty( $state[ $s[0] ] );
		} ) );
		echo '<h2>' . esc_html( $pro ? __( 'Your weekly routine, then the basics', 'leymish-ai-shopping-readiness' ) : __( 'Your first 15 minutes', 'leymish-ai-shopping-readiness' ) ) . '</h2>';
		/* translators: 1: steps done, 2: steps. */
		echo '<p class="lasr-lede">' . esc_html( sprintf( __( '%1$d of %2$d done. Everything in these steps runs on your own site.', 'leymish-ai-shopping-readiness' ), $done, count( $steps ) ) ) . '</p>';
		echo '<ol class="lasr-start-steps">';
		foreach ( $steps as $s ) {
			$ok = ! empty( $state[ $s[0] ] );
			echo '<li class="' . ( $ok ? 'is-done' : 'is-todo' ) . '"><span class="lasr-start-mark" aria-hidden="true">' . ( $ok ? '✓' : '' ) . '</span>';
			echo '<span class="screen-reader-text">' . esc_html( $ok ? __( 'Done:', 'leymish-ai-shopping-readiness' ) : __( 'To do:', 'leymish-ai-shopping-readiness' ) ) . '</span> ';
			/* translators: %s: minutes. */
			echo '<strong>' . esc_html( $s[1] ) . '</strong> <span class="description">' . esc_html( sprintf( __( '%s min', 'leymish-ai-shopping-readiness' ), $s[3] ) ) . '</span><br>' . esc_html( $s[2] ) . '</li>';
		}
		echo '</ol>';
		if ( ! $pro ) {
			echo '<div class="lasr-card-box"><h3>' . esc_html__( 'When you want help', 'leymish-ai-shopping-readiness' ) . '</h3><ul class="lasr-list">';
			echo '<li>' . esc_html__( 'Many products? AI fixes drafts missing details from your own product pages; you approve each one (20 free to try).', 'leymish-ai-shopping-readiness' ) . '</li>';
			echo '<li>' . esc_html__( 'Want to know if AI assistants mention you? AI visibility asks your shoppers\' questions (one free check).', 'leymish-ai-shopping-readiness' ) . '</li>';
			echo '<li>' . esc_html__( 'No time? We fix your top 25 products for $49; you approve every change.', 'leymish-ai-shopping-readiness' ) . '</li></ul>';
			echo '<p>' . esc_html__( 'Upgrading takes under a minute: LeyMish → Plan → Upgrade: $12/month. Pro switches on by itself.', 'leymish-ai-shopping-readiness' ) . '</p></div>';
		}
		echo '<p class="description"><a href="https://www.leymish.com/woocommerce/start/" target="_blank" rel="noopener">' . esc_html__( 'The full guide with flowcharts', 'leymish-ai-shopping-readiness' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'leymish-ai-shopping-readiness' ) . '</span></a></p>';
	}

	/**
	 * Hide the Overview card.
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_start_dismiss' );
		update_option( self::DISMISS, 1, false );
		wp_safe_redirect( admin_url( 'admin.php?page=' . LASR_Admin::SLUG ) );
		exit;
	}
}
