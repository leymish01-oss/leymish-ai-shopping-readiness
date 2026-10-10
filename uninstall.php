<?php
/**
 * Remove everything this plugin stored when it is deleted: audits and history, settings, the licence, the Store Team
 * connection and the cached feeds. (If you connected Store Team, also revoke its key in WooCommerce → Settings →
 * Advanced → REST API; disconnecting first deletes LeyMish's copy.)
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array(
	'lasr_last_audit',
	'lasr_history',
	'lasr_baseline',
	'lasr_review_asked',
	'lasr_onboarding_done',
	'lasr_tips_subscribed',
	'lasr_welcome',
	'lasr_db_version',
	'lasr_migrated_notice',
	'lasr_license',
	'lasr_ai',
	'lasr_llms_enabled',
	'lasr_weekly_email',
	'lasr_worklog',
	'lasr_feeds_enabled',
	'lasr_feeds_built',
	'lasr_checkout',
	'lasr_alerts',
	'lasr_outside',
	'lasr_undo',
	'lasr_vis_questions',
	'lasr_vis_history',
	'lasr_vis_free_used',
	'lasr_team_connection',
	'lasr_claim',
	'lasr_events',
	'lasr_migrated_seen',
	'lasr_pro_status_last',
	'lasr_earlier_last',
	'lasr_visits',
	'lasr_visits_salt',
	'lasr_team_report',
	'lasr_visitors_enabled',
	'lasr_schema_provided',
	'lasr_links',
	'lasr_start_dismissed',
) as $lasr_option ) {
	delete_option( $lasr_option );
}
foreach ( array( 'lasr_waiting_count', 'lasr_pro_status', 'lasr_earlier', 'lasr_ai_usage' ) as $lasr_transient ) {
	delete_transient( $lasr_transient );
}
delete_metadata( 'user', 0, 'lasr_pro_panel_dismissed', '', true ); // 1.4's "Free vs Pro" panel
$lasr_uploads = wp_upload_dir();
$lasr_dir     = trailingslashit( $lasr_uploads['basedir'] ) . 'leymish-feeds/';
foreach ( array( 'openai.jsonl', 'google.tsv' ) as $lasr_file ) {
	if ( file_exists( $lasr_dir . $lasr_file ) ) {
		wp_delete_file( $lasr_dir . $lasr_file );
	}
}
