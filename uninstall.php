<?php
/**
 * Remove the plugin's saved audit and Impact history when it is deleted.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'lasr_last_audit' );
delete_option( 'lasr_history' );
delete_option( 'lasr_baseline' );
delete_option( 'lasr_review_asked' );
delete_option( 'lasr_onboarding_done' );   // first-run checklist (1.3.0)
delete_option( 'lasr_tips_subscribed' );   // weekly-tip opt-in (1.3.0)
delete_metadata( 'user', 0, 'lasr_pro_panel_dismissed', '', true ); // "Free vs Pro" panel hidden for 30 days (1.4.0)
