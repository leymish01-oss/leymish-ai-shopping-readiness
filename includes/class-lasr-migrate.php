<?php
/**
 * 1.x → 2.0: Pro and Store Team are now built in.
 *
 * Runs once per upgrade (and again if an old add-on is still active): copies the old add-ons' settings, licence,
 * site token and work log to 2.0's own option names, switches on what the store already used (feeds, llms.txt),
 * deactivates the old plugins, and shows one notice saying they can be deleted. 2.0 uses its own option names and
 * feed folder, so deleting the old plugins (whose uninstallers remove their own options and files) can't take
 * anything away from 2.0.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migration and its notice.
 */
class LASR_Migrate {

	const VERSION_OPT = 'lasr_db_version';
	const NOTICE      = 'lasr_migrated_notice';
	const SEEN        = 'lasr_migrated_seen';
	const OLD         = array(
		'leymish-ai-shopping-readiness-pro.php' => 'LeyMish AI Readiness Pro',
		'leymish-store-team.php'                => 'LeyMish Store Team',
	);

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_run' ), 5 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_lasr_dismiss_migrated', array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * Old add-ons that are still active (basenames).
	 *
	 * @return string[]
	 */
	public static function active_old() {
		$out = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $basename ) {
			if ( isset( self::OLD[ basename( (string) $basename ) ] ) ) {
				$out[] = (string) $basename;
			}
		}
		return $out;
	}

	/**
	 * Copy one option to its new name unless the new one exists (pure mapping lives in map()).
	 *
	 * @return array<string,string> old => new.
	 */
	public static function map() {
		return array(
			'lasr_pro_license'      => 'lasr_license',
			'lasr_pro_ai'           => 'lasr_ai',
			'lasr_pro_llms_enabled' => 'lasr_llms_enabled',
			'lasr_pro_weekly_email' => 'lasr_weekly_email',
			'lasr_pro_worklog'      => 'lasr_worklog',
			'lst_connection'        => 'lasr_team_connection',
		);
	}

	/**
	 * Run when the version changed or an old add-on is active.
	 */
	public static function maybe_run() {
		$old = self::active_old();
		if ( LASR_VERSION === get_option( self::VERSION_OPT ) && ! $old ) {
			return;
		}
		$moved = array();
		foreach ( self::map() as $from => $to ) {
			$v = get_option( $from, null );
			if ( null !== $v && null === get_option( $to, null ) ) {
				if ( 'lasr_pro_ai' === $from && is_array( $v ) ) {
					$v = array( 'consent' => ! empty( $v['consent'] ) ); // the key now lives in the licence
				}
				add_option( $to, $v, '', false );
				$moved[] = $to;
			}
		}
		// A Pro 1.x store already published feeds: keep them published (same URLs), and rebuild them in the new folder.
		if ( false !== get_option( 'lasr_pro_feeds_built', false ) && false === get_option( LASR_Feeds::ENABLED, false ) ) {
			add_option( LASR_Feeds::ENABLED, 'yes', '', false );
		}
		if ( $old ) {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins( $old, true );
			foreach ( array( 'lasr_pro_weekly', 'lasr_pro_license_recheck', 'lasr_pro_rebuild_feeds' ) as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
			$names = array();
			foreach ( $old as $b ) {
				$names[] = self::OLD[ basename( $b ) ];
			}
			update_option( self::NOTICE, $names, false );
			delete_option( self::SEEN );
		}
		update_option( self::VERSION_OPT, LASR_VERSION, false );
		if ( LASR_Feeds::enabled() ) {
			wp_schedule_single_event( time() + 30, 'lasr_rebuild_feeds' );
		}
		if ( class_exists( 'LASR_Team' ) && LASR_Team::connection() && LASR_License::has_licence() ) {
			LASR_Team::sync_licence();
		}
		flush_rewrite_rules( false );
	}

	/**
	 * Old add-ons still installed (active or not), by basename.
	 *
	 * @return string[]
	 */
	public static function installed_old() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( array_keys( (array) get_plugins() ) as $file ) {
			if ( isset( self::OLD[ basename( (string) $file ) ] ) ) {
				$out[] = (string) $file;
			}
		}
		return $out;
	}

	/**
	 * Where the notice may show (pure, 2.0.1): once on Plugins and once on our Overview tab, never on other tabs.
	 *
	 * @param string   $screen_id Current screen.
	 * @param string   $tab       Our tab ('' on other screens).
	 * @param string[] $seen      Places it has already shown.
	 * @return string The place ('plugins' or 'overview'), or '' to stay quiet.
	 */
	public static function place( $screen_id, $tab, array $seen ) {
		$place = '';
		if ( 'plugins' === $screen_id ) {
			$place = 'plugins';
		} elseif ( false !== strpos( (string) $screen_id, LASR_Admin::SLUG ) && 'overview' === $tab ) {
			$place = 'overview';
		}
		return '' !== $place && ! in_array( $place, $seen, true ) ? $place : '';
	}

	/**
	 * One notice: once on the Plugins screen and once on our Overview (2.0.1). Dismissed means gone; deleting the old
	 * plugins removes it too.
	 */
	public static function notice() {
		$names = get_option( self::NOTICE );
		if ( ! $names || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( ! self::installed_old() ) {
			delete_option( self::NOTICE );
			delete_option( self::SEEN );
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$seen   = (array) get_option( self::SEEN, array() );
		$place  = $screen ? self::place( (string) $screen->id, LASR_Admin::tab(), $seen ) : '';
		if ( '' === $place ) {
			return;
		}
		$seen[] = $place;
		update_option( self::SEEN, $seen, false );
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=lasr_dismiss_migrated' ), 'lasr_dismiss_migrated' );
		echo '<div class="notice notice-success lasr-migrated"><p><strong>' . esc_html__( 'Pro and Store Team are now built into LeyMish AI Readiness.', 'leymish-ai-shopping-readiness' ) . '</strong> ';
		/* translators: %s: names of the old plugins. */
		echo esc_html( sprintf( __( 'Your settings, licence and history moved over, and we switched off %s. You can delete the old plugins.', 'leymish-ai-shopping-readiness' ), implode( ' ' . __( 'and', 'leymish-ai-shopping-readiness' ) . ' ', array_map( 'sanitize_text_field', (array) $names ) ) ) ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . LASR_Admin::SLUG ) ) . '">' . esc_html__( 'Open LeyMish', 'leymish-ai-shopping-readiness' ) . '</a> <a class="button-link" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'leymish-ai-shopping-readiness' ) . '</a></p></div>';
	}

	/**
	 * Dismiss the notice.
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_dismiss_migrated' );
		delete_option( self::NOTICE );
		delete_option( self::SEEN );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}
}
