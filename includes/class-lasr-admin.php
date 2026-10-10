<?php
/**
 * The one LeyMish screen (2.0): a top-level "LeyMish" menu right after WooCommerce, no submenus, and eight tabs:
 * Overview, Audit, Products, Feeds, AI visibility, AI fixes, Team, Plan. Every tab opens with the same three answers:
 * what's wrong, what we fixed, what to do next.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu, tabs, assets, and the Overview, Audit and Feeds tabs (the others live in their own classes).
 */
class LASR_Admin {

	const SLUG           = 'lasr-ai-readiness'; // unchanged from 1.x, so old links and bookmarks still open the screen
	const WELCOME_OPTION = 'lasr_welcome';
	const REVIEW_OPTION  = 'lasr_review_asked';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', array( __CLASS__, 'menu_order' ), 100 ); // after WooCommerce's own ordering (it puts Products next to itself)
		add_action( 'admin_init', array( __CLASS__, 'legacy_redirect' ) );
		add_action( 'admin_page_access_denied', array( __CLASS__, 'legacy_redirect' ) ); // an unknown page is refused before admin_init
		add_action( 'admin_post_lasr_run_audit', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_lasr_export_csv', array( __CLASS__, 'handle_csv' ) );
		add_action( 'admin_post_lasr_review', array( __CLASS__, 'handle_review' ) );
		add_action( 'admin_post_lasr_feeds_settings', array( __CLASS__, 'handle_feeds' ) );
		add_action( 'admin_post_lasr_rebuild', array( __CLASS__, 'handle_rebuild' ) );
		add_action( 'admin_notices', array( __CLASS__, 'welcome_notice' ) );
		add_action( 'admin_post_lasr_dismiss_welcome', array( __CLASS__, 'handle_dismiss_welcome' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		LASR_Onboarding::init();
		add_filter( 'plugin_action_links_' . plugin_basename( LASR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * The tabs, in order.
	 *
	 * @return array<string,string>
	 */
	public static function tabs() {
		return array(
			'overview'   => __( 'Overview', 'leymish-ai-shopping-readiness' ),
			'audit'      => __( 'Audit', 'leymish-ai-shopping-readiness' ),
			'products'   => __( 'Products', 'leymish-ai-shopping-readiness' ),
			'feeds'      => __( 'Feeds', 'leymish-ai-shopping-readiness' ),
			'visibility' => __( 'AI visibility', 'leymish-ai-shopping-readiness' ),
			'fixes'      => __( 'AI fixes', 'leymish-ai-shopping-readiness' ),
			'team'       => __( 'Team', 'leymish-ai-shopping-readiness' ),
			'plan'       => __( 'Plan', 'leymish-ai-shopping-readiness' ),
		);
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	public static function tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$t = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		if ( 'impact' === $t ) {
			$t = 'overview'; // 1.x name
		}
		if ( 'start' === $t ) {
			return 'start'; // the Start here guide: reachable from the header, not a tab of its own
		}
		return isset( self::tabs()[ $t ] ) ? $t : 'overview';
	}

	/**
	 * Our menu icon: the LeyMish mark as an SVG data URI (WordPress colours it to match the admin scheme).
	 *
	 * @return string
	 */
	public static function icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M3 2h3.2v12.6H17V18H3z"/><circle fill="black" cx="14.6" cy="6.2" r="3.2"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a data URI for our own menu icon.
	}

	/**
	 * One top-level menu, right after WooCommerce, with a count of approvals waiting.
	 */
	public static function menu() {
		$n     = class_exists( 'LASR_Team' ) ? LASR_Team::waiting_count() : 0;
		$label = esc_html__( 'LeyMish', 'leymish-ai-shopping-readiness' );
		if ( $n > 0 ) {
			/* translators: %d: approvals waiting. */
			$label .= ' <span class="awaiting-mod count-' . (int) $n . '"><span class="pending-count" aria-hidden="true">' . esc_html( number_format_i18n( $n ) ) . '</span><span class="screen-reader-text">' . esc_html( sprintf( _n( '%d approval waiting', '%d approvals waiting', $n, 'leymish-ai-shopping-readiness' ), $n ) ) . '</span></span>';
		}
		$hook = add_menu_page(
			__( 'LeyMish AI Readiness', 'leymish-ai-shopping-readiness' ),
			$label,
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' ),
			self::icon(),
			'55.6'
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'warm' ) );
		}
	}

	/**
	 * Before any HTML is sent (2.0.1): fetch what the page needs from LeyMish (Pro status, earlier work), each with a
	 * short timeout and its own cache. On a slow host the browser then keeps showing the previous page until ours is
	 * ready, instead of a half-drawn admin screen with only "Skip to main content".
	 */
	public static function warm() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		LASR_License::is_pro();
		LASR_Worklog::earlier();
	}

	/**
	 * Our menu sits directly after WooCommerce (above WooCommerce's own Products menu).
	 *
	 * @param string[] $order Menu slugs in order.
	 * @return string[]
	 */
	public static function menu_order( $order ) {
		$order = array_values( array_diff( (array) $order, array( self::SLUG ) ) );
		$at    = array_search( 'woocommerce', $order, true );
		if ( false === $at ) {
			$order[] = self::SLUG;
			return $order;
		}
		array_splice( $order, $at + 1, 0, array( self::SLUG ) );
		return $order;
	}

	/**
	 * Pages from 1.x add-ons and submenus open the right tab now.
	 */
	public static function legacy_redirect() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect of an old page address.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$map  = array(
			'lasr-ai-readiness-pro'        => 'plan',
			'lasr-ai-readiness-data'       => 'products',
			'lasr-pro-ai'                  => 'fixes',
			'leymish-store-team'           => 'team',
			'leymish-store-team-approvals' => 'team',
		);
		if ( isset( $map[ $page ] ) && ! LASR_Migrate::active_old() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $map[ $page ] ) );
			exit;
		}
	}

	/**
	 * One welcome notice on the Plugins screen after the first activation (a notice, not a redirect), until the
	 * owner opens the page or dismisses it.
	 */
	public static function welcome_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! get_option( self::WELCOME_OPTION ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=lasr_dismiss_welcome' ), 'lasr_dismiss_welcome' );
		echo '<div class="notice notice-info lasr-welcome"><p><strong>' . esc_html__( 'LeyMish AI Readiness is active.', 'leymish-ai-shopping-readiness' ) . '</strong> ' . esc_html__( 'Run your first audit to see whether AI shopping agents can read your store. It takes about a minute and runs on your site.', 'leymish-ai-shopping-readiness' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open LeyMish AI Readiness', 'leymish-ai-shopping-readiness' ) . '</a> <a class="button-link" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'leymish-ai-shopping-readiness' ) . '</a></p></div>';
	}

	/**
	 * Dismiss the welcome notice for good.
	 */
	public static function handle_dismiss_welcome() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_dismiss_welcome' );
		delete_option( self::WELCOME_OPTION );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * "Open dashboard" first on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open dashboard', 'leymish-ai-shopping-readiness' ) . '</a>' );
		return $links;
	}

	/**
	 * CSS and the scripts each tab needs, only on our screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'lasr-admin', plugins_url( 'assets/admin.css', LASR_FILE ), array(), LASR_VERSION );
		wp_enqueue_script( 'lasr-admin', plugins_url( 'assets/admin.js', LASR_FILE ), array(), LASR_VERSION, true );
		wp_localize_script(
			'lasr-admin',
			'lasrAdmin',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'pollNonce' => wp_create_nonce( 'lasr_upgrade_poll' ),
				'planUrl'   => admin_url( 'admin.php?page=' . self::SLUG . '&tab=plan&lasr_msg=valid' ),
				'failed'    => __( 'The purchase arrived but the licence did not activate. Paste the key from your receipt below.', 'leymish-ai-shopping-readiness' ),
			)
		);
		$tab = self::tab();
		if ( 'products' === $tab ) {
			wp_enqueue_script( 'lasr-editor', plugins_url( 'assets/editor.js', LASR_FILE ), array(), LASR_VERSION, true );
			wp_localize_script(
				'lasr-editor',
				'lasrEditor',
				array(
					'invalid' => __( 'Not a valid GTIN: it needs 8, 12, 13 or 14 digits and a correct check digit.', 'leymish-ai-shopping-readiness' ),
					'ok'      => __( 'Valid GTIN.', 'leymish-ai-shopping-readiness' ),
				)
			);
		} elseif ( 'fixes' === $tab ) {
			wp_enqueue_script( 'lasr-ai', plugins_url( 'assets/ai.js', LASR_FILE ), array(), LASR_VERSION, true );
			wp_localize_script( 'lasr-ai', 'lasrAI', LASR_AI::script_data() );
		} elseif ( 'team' === $tab ) {
			wp_enqueue_script( 'lasr-team', plugins_url( 'assets/team.js', LASR_FILE ), array(), LASR_VERSION, true );
			if ( LASR_Team::connection() ) {
				wp_enqueue_script( 'lasr-approvals', plugins_url( 'assets/approvals.js', LASR_FILE ), array(), LASR_VERSION, true );
				wp_localize_script( 'lasr-approvals', 'LASR_APPROVALS', LASR_Team::approvals_data() );
			}
		}
	}

	/**
	 * The three answers at the top of every tab.
	 *
	 * @param string     $wrong What's wrong.
	 * @param string     $fixed What we fixed.
	 * @param string     $next  What to do next.
	 * @param array|null $cta   [href, label] for the one button, or null.
	 */
	public static function three( $wrong, $fixed, $next, $cta ) {
		echo '<div class="lasr-three" role="region" aria-label="' . esc_attr__( 'Summary', 'leymish-ai-shopping-readiness' ) . '">';
		echo '<div class="lasr-three-item lasr-three-wrong"><span class="lasr-three-k">' . esc_html__( 'What\'s wrong', 'leymish-ai-shopping-readiness' ) . '</span><p>' . esc_html( $wrong ) . '</p></div>';
		echo '<div class="lasr-three-item lasr-three-fixed"><span class="lasr-three-k">' . esc_html__( 'What we fixed', 'leymish-ai-shopping-readiness' ) . '</span><p>' . esc_html( $fixed ) . '</p></div>';
		echo '<div class="lasr-three-item lasr-three-next"><span class="lasr-three-k">' . esc_html__( 'What to do next', 'leymish-ai-shopping-readiness' ) . '</span><p>' . esc_html( $next ) . '</p>';
		if ( $cta ) {
			echo '<a class="button button-primary" href="' . esc_url( $cta[0] ) . '">' . esc_html( $cta[1] ) . '</a>';
		}
		echo '</div></div>';
	}

	/**
	 * Run the audit (POST, nonce, capability), then go back.
	 */
	public static function handle_run() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the audit.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_run_audit' );
		LASR_Audit::run();
		$tab = isset( $_POST['lasr_tab'] ) && 'audit' === sanitize_key( wp_unslash( $_POST['lasr_tab'] ) ) ? '&tab=audit' : '';
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . $tab . '&lasr_done=1' ) );
		exit;
	}

	/**
	 * CSV of per-product gaps from the last audit.
	 */
	public static function handle_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_export_csv' );
		$result = LASR_Audit::last();
		if ( ! $result ) {
			wp_die( esc_html__( 'Run the audit first.', 'leymish-ai-shopping-readiness' ) );
		}
		$labels = LASR_Audit::field_labels();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ai-readiness-' . gmdate( 'Y-m-d' ) . '.csv' );
		$rows = array( array( 'product_id', 'name', 'url', 'completeness_percent', 'missing', 'gtin_problem' ) );
		foreach ( $result['products'] as $p ) {
			$missing = array();
			foreach ( $p['missing'] as $f ) {
				$missing[] = isset( $labels[ $f ] ) ? $labels[ $f ] : $f;
			}
			$rows[] = array( $p['id'], $p['name'], $p['url'], (int) round( 100 * $p['score'] ), implode( '; ', $missing ), $p['gtin_problem'] );
		}
		echo self::to_csv( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download, cells are quoted by to_csv().
		exit;
	}

	/**
	 * Build CSV text without opening a file handle. Cells starting with = + - @ are prefixed with an
	 * apostrophe so spreadsheet apps don't run them as formulas.
	 *
	 * @param array $rows Rows of cells.
	 * @return string
	 */
	public static function to_csv( array $rows ) {
		$out = '';
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $cell ) {
				$cell = (string) $cell;
				if ( '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@' ), true ) ) {
					$cell = "'" . $cell;
				}
				$cells[] = '"' . str_replace( '"', '""', $cell ) . '"';
			}
			$out .= implode( ',', $cells ) . "\r\n";
		}
		return $out;
	}

	/**
	 * Status badge.
	 *
	 * @param string $status Check status.
	 * @return string HTML.
	 */
	private static function badge( $status ) {
		$labels = array(
			'pass' => __( 'Pass', 'leymish-ai-shopping-readiness' ),
			'warn' => __( 'Needs work', 'leymish-ai-shopping-readiness' ),
			'fail' => __( 'Fail', 'leymish-ai-shopping-readiness' ),
			'info' => __( 'Info', 'leymish-ai-shopping-readiness' ),
			'skip' => __( 'Not tested', 'leymish-ai-shopping-readiness' ),
		);
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		return '<span class="lasr-badge lasr-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * The page: header, tabs, the current tab.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab    = self::tab();
		$result = LASR_Audit::last();
		delete_option( self::WELCOME_OPTION ); // they found the page: the Plugins-screen welcome has done its job
		echo '<div class="wrap lasr-wrap">';
		echo '<header class="lasr-head"><span class="lasr-mark" aria-hidden="true"><svg viewBox="0 0 20 20" focusable="false"><path d="M3 2h3.2v12.6H17V18H3z"/><circle cx="14.6" cy="6.2" r="3.2"/></svg></span>';
		echo '<h1 class="lasr-title"><span class="lasr-wordmark">LeyMish</span> <span class="lasr-sub">' . esc_html__( 'AI Readiness', 'leymish-ai-shopping-readiness' ) . '</span></h1>';
		echo '<a class="lasr-start-link" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=start' ) ) . '">' . esc_html__( 'Start here', 'leymish-ai-shopping-readiness' ) . '</a>';
		echo '<span class="lasr-plan-pill">' . esc_html( LASR_License::plan_label() ) . '</span></header>';
		echo '<hr class="wp-header-end">';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		if ( isset( $_GET['lasr_done'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Audit finished.', 'leymish-ai-shopping-readiness' ) . '</p></div>';
		}
		LASR_Onboarding::notice();
		$base    = admin_url( 'admin.php?page=' . self::SLUG );
		$waiting = LASR_Team::waiting_count();
		echo '<nav class="nav-tab-wrapper lasr-tabs" aria-label="' . esc_attr__( 'LeyMish sections', 'leymish-ai-shopping-readiness' ) . '">';
		foreach ( self::tabs() as $key => $label ) {
			$url   = 'overview' === $key ? $base : $base . '&tab=' . $key;
			$extra = 'team' === $key && $waiting ? ' <span class="lasr-tab-count">' . esc_html( (string) $waiting ) . '</span>' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $tab === $key ? ' nav-tab-active" aria-current="page' : '' ) . '">' . esc_html( $label ) . $extra . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $extra is escaped above.
		}
		echo '</nav><div class="lasr-tab-body">';
		switch ( $tab ) {
			case 'audit':
				self::render_audit( $result );
				break;
			case 'products':
				LASR_Products::render( $result );
				break;
			case 'feeds':
				self::render_feeds();
				break;
			case 'visibility':
				LASR_Visibility::render( $result );
				break;
			case 'fixes':
				LASR_AI::render( $result );
				break;
			case 'team':
				LASR_Team::render();
				break;
			case 'plan':
				LASR_Plan::render();
				break;
			case 'start':
				LASR_Start::render();
				break;
			default:
				self::render_overview( $result );
		}
		echo '</div></div>';
	}

	/**
	 * The "Run the audit" form.
	 *
	 * @param bool   $again Whether an audit already exists.
	 * @param string $tab   Tab to come back to.
	 */
	private static function run_form( $again, $tab = 'overview' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-run">';
		wp_nonce_field( 'lasr_run_audit' );
		echo '<input type="hidden" name="action" value="lasr_run_audit" /><input type="hidden" name="lasr_tab" value="' . esc_attr( $tab ) . '" />';
		submit_button( $again ? __( 'Run the audit again', 'leymish-ai-shopping-readiness' ) : __( 'Run the audit', 'leymish-ai-shopping-readiness' ), 'primary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Takes up to a minute: it requests a few of your own pages as each AI crawler would.', 'leymish-ai-shopping-readiness' ) . '</span>';
		echo '</form>';
	}

	/**
	 * Overview: score, areas, next wins, what changed this week, the trend, product data and the report.
	 *
	 * @param array|null $result Last audit.
	 */
	private static function render_overview( $result ) {
		LASR_Start::card();
		$history  = LASR_Impact::history();
		$baseline = LASR_Impact::baseline();
		if ( ! $result || ! $history || ! $baseline ) {
			self::three(
				__( 'You don\'t know yet whether AI shopping agents can read your store.', 'leymish-ai-shopping-readiness' ),
				__( 'Nothing yet.', 'leymish-ai-shopping-readiness' ),
				__( 'Run your first audit. It takes about a minute and nothing leaves your site.', 'leymish-ai-shopping-readiness' ),
				null
			);
			LASR_Onboarding::render_checklist( $result );
			echo '<div class="lasr-empty"><h2>' . esc_html__( 'See what improves', 'leymish-ai-shopping-readiness' ) . '</h2>';
			echo '<p>' . esc_html__( 'Your first audit sets a starting point. After each change, run it again: this page shows your score over time, the checks you fixed and how much of your product data is complete. Everything is stored on this site only.', 'leymish-ai-shopping-readiness' ) . '</p>';
			self::run_form( false );
			echo '</div>';
			LASR_Onboarding::render_optin();
			return;
		}
		$current = end( $history );
		$date    = get_option( 'date_format' );
		$labels  = array();
		foreach ( $result['checks'] as $c ) {
			$labels[ $c['id'] ] = $c['label'];
		}
		$delta   = (int) $current['s'] - (int) $baseline['s'];
		$earlier = LASR_Worklog::earlier();
		$since   = self::since_text( LASR_Worklog::start_point( $earlier, $baseline ), $baseline, $current );
		$wins    = LASR_Dashboard::wins( $result, 3 );
		$log   = LASR_Worklog::worklog();
		$week  = LASR_Worklog::since( $log, time() - WEEK_IN_SECONDS );
		$total = 0;
		foreach ( (array) $log['counts'] as $n ) {
			$total += (int) $n;
		}
		$watch = LASR_Dashboard::watch_text( $result['checks'] );
		self::three(
			LASR_Dashboard::verdict( $result ),
			$total
				/* translators: 1: changes made, 2: e.g. "+22 since 27 Sep (77 → 99)". */
				? sprintf( _n( '%1$d change made here. Score %2$s.', '%1$d changes made here. Score %2$s.', $total, 'leymish-ai-shopping-readiness' ), $total, $since )
				/* translators: %s: e.g. "+22 since 27 Sep (77 → 99): LeyMish agents +19, this month +3". */
				: sprintf( __( 'Score %s.', 'leymish-ai-shopping-readiness' ), $since ),
			$wins ? $wins[0]['fix'] : ( '' !== $watch ? $watch : __( 'Nothing to fix. Keep an eye on it with the weekly re-audit.', 'leymish-ai-shopping-readiness' ) ),
			$wins ? array( LASR_Dashboard::fix_url( $wins[0]['id'] ), __( 'Fix it', 'leymish-ai-shopping-readiness' ) ) : null
		);

		$alerts = LASR_Schedule::alerts();
		if ( $alerts ) {
			echo '<section class="lasr-alerts" role="alert"><h2>' . esc_html__( 'Needs your attention', 'leymish-ai-shopping-readiness' ) . '</h2><ul>';
			foreach ( $alerts['items'] as $a ) {
				echo '<li>' . esc_html( $a ) . '</li>';
			}
			echo '</ul><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'lasr_dismiss_alerts' );
			echo '<input type="hidden" name="action" value="lasr_dismiss_alerts" /><button type="submit" class="button-link">' . esc_html__( 'Dismiss', 'leymish-ai-shopping-readiness' ) . '</button></form></section>';
		}

		if ( count( $earlier ) >= 1 && LASR_Worklog::start_point( $earlier, $baseline )['t'] < (int) $baseline['t'] ) {
			$text = $since;
		} elseif ( count( $history ) > 1 || $baseline['t'] !== $current['t'] ) {
			$text = 0 === $delta
				/* translators: 1: starting score, 2: date of the first audit. */
				? sprintf( __( 'Same score as when you started (%1$d on %2$s)', 'leymish-ai-shopping-readiness' ), (int) $baseline['s'], wp_date( $date, (int) $baseline['t'] ) )
				/* translators: 1: change in points, e.g. +12, 2: starting score, 3: date of the first audit. */
				: sprintf( __( '%1$s points since you started (%2$d on %3$s)', 'leymish-ai-shopping-readiness' ), ( $delta > 0 ? '+' : '' ) . $delta, (int) $baseline['s'], wp_date( $date, (int) $baseline['t'] ) );
		} else {
			$text = __( 'Your starting point. Make a fix, run the audit again, and the change shows here.', 'leymish-ai-shopping-readiness' );
		}
		LASR_Dashboard::hero( $result, $history, $baseline, $text, function () {
			self::run_form( true );
		} );
		LASR_Dashboard::cards( $result['checks'], isset( $result['products'] ) ? (array) $result['products'] : array() );
		LASR_Visitors::card();
		if ( self::review_due( $delta ) ) {
			self::review_box();
		}

		echo '<div class="lasr-two"><section><h2>' . esc_html__( 'Your next 3 wins', 'leymish-ai-shopping-readiness' ) . '</h2>';
		if ( $wins ) {
			LASR_Dashboard::wins_list( $wins );
		} else {
			echo '<p><strong>' . esc_html__( 'Nothing to fix. Nice.', 'leymish-ai-shopping-readiness' ) . '</strong></p>';
		}
		echo '</section><section><h2>' . esc_html__( 'What changed this week', 'leymish-ai-shopping-readiness' ) . '</h2>';
		self::this_week( $week, $history );
		echo '</section></div>';

		self::earlier_work( $history );

		echo '<h2>' . esc_html__( 'Score over time', 'leymish-ai-shopping-readiness' ) . '</h2>';
		self::chart( LASR_Worklog::chart_history( $history, $earlier ) );

		$changes = LASR_Impact::changes( $baseline, $current );
		echo '<h2>' . esc_html__( 'Fixed since you started', 'leymish-ai-shopping-readiness' ) . '</h2>';
		if ( $changes['fixed'] ) {
			echo '<ul class="lasr-fixed">';
			foreach ( $changes['fixed'] as $id ) {
				echo '<li>' . esc_html( isset( $labels[ $id ] ) ? $labels[ $id ] : $id ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'No checks have moved from "needs work" to "pass" yet.', 'leymish-ai-shopping-readiness' );
			if ( $delta > 0 ) {
				echo ' ' . esc_html__( 'Your score still rose because checks that score in steps, such as product data completeness, improved.', 'leymish-ai-shopping-readiness' );
			}
			echo '</p>';
		}
		if ( $changes['broken'] ) {
			echo '<p class="lasr-broken"><strong>' . esc_html__( 'Passed before, needs work now:', 'leymish-ai-shopping-readiness' ) . '</strong> ' . esc_html( implode( ', ', array_map( function ( $id ) use ( $labels ) {
				return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
			}, $changes['broken'] ) ) ) . '</p>';
		}

		echo '<h2>' . esc_html__( 'Product data', 'leymish-ai-shopping-readiness' ) . '</h2>';
		LASR_Worklog::before_after( $baseline, $current );
		$names = array(
			'identifier' => __( 'Valid GTIN or MPN', 'leymish-ai-shopping-readiness' ),
			'brand'      => __( 'Brand', 'leymish-ai-shopping-readiness' ),
			'alt'        => __( 'Main image has alt text', 'leymish-ai-shopping-readiness' ),
		);
		echo '<table class="widefat striped lasr-table lasr-then-now"><thead><tr><th scope="col">' . esc_html__( 'Products with…', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'When you started', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Now', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
		foreach ( LASR_Impact::product_rows( $baseline, $current ) as $r ) {
			$cell = function ( $count, $pct, $n ) {
				/* translators: 1: products with the field, 2: products checked, 3: percentage. */
				return null === $count ? __( 'not recorded', 'leymish-ai-shopping-readiness' ) : sprintf( __( '%1$d of %2$d (%3$d%%)', 'leymish-ai-shopping-readiness' ), $count, $n, $pct );
			};
			echo '<tr><th scope="row">' . esc_html( $names[ $r['key'] ] ) . '</th><td>' . esc_html( $cell( $r['then'], $r['then_pct'], (int) $baseline['n'] ) ) . '</td><td>' . esc_html( $cell( $r['now'], $r['now_pct'], (int) $current['n'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Before and after report', 'leymish-ai-shopping-readiness' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-report">';
		wp_nonce_field( 'lasr_report' );
		echo '<input type="hidden" name="action" value="lasr_report" />';
		submit_button( __( 'Download report (HTML)', 'leymish-ai-shopping-readiness' ), 'secondary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'One page with real dates you can print, save as PDF or send to a client.', 'leymish-ai-shopping-readiness' ) . '</span></form>';
		LASR_Onboarding::render_optin();
	}

	/**
	 * The score line (2.0.1), from the earliest real score: "+22 since 27 Sep (77 → 99): LeyMish agents +19, this
	 * month +3". Without earlier work by LeyMish agents: "+3 since 6 Oct (96 → 99)".
	 *
	 * @param array $start    From LASR_Worklog::start_point().
	 * @param array $baseline This plugin's first audit.
	 * @param array $current  Latest snapshot.
	 * @return string
	 */
	public static function since_text( array $start, array $baseline, array $current ) {
		$sign  = function ( $n ) {
			return ( $n >= 0 ? '+' : '' ) . (int) $n;
		};
		$now   = (int) $current['s'];
		$total = $now - (int) $start['s'];
		/* translators: 1: signed change, 2: date, 3: start score, 4: score now. */
		$line = sprintf( __( '%1$s since %2$s (%3$d → %4$d)', 'leymish-ai-shopping-readiness' ), $sign( $total ), wp_date( 'j M', (int) $start['t'] ), (int) $start['s'], $now );
		if ( null === $start['agents'] ) {
			return $line;
		}
		$here    = $now - (int) $baseline['s'];
		$same_mo = wp_date( 'Y-m', (int) $baseline['t'] ) === wp_date( 'Y-m' );
		$period  = $same_mo
			? __( 'this month', 'leymish-ai-shopping-readiness' )
			/* translators: %s: date of this plugin's first audit. */
			: sprintf( __( 'since %s', 'leymish-ai-shopping-readiness' ), wp_date( 'j M', (int) $baseline['t'] ) );
		/* translators: 1: e.g. "+22 since 27 Sep (77 → 99)", 2: LeyMish agents' gain, 3: "this month" or "since 6 Oct", 4: gain in that period. */
		return sprintf( __( '%1$s: LeyMish agents %2$s, %3$s %4$s', 'leymish-ai-shopping-readiness' ), $line, $sign( $start['agents'] ), $period, $sign( $here ) );
	}

	/**
	 * "What changed this week": this site's own changes, the score over the week, the team and AI visibility.
	 *
	 * @param array<string,int> $week    Work counted in the last 7 days.
	 * @param array[]           $history Snapshots.
	 */
	private static function this_week( array $week, array $history ) {
		$items = array();
		$fmt   = 'j M';
		foreach ( array_slice( LASR_Worklog::events_since( LASR_Worklog::events(), time() - WEEK_IN_SECONDS ), 0, 8 ) as $e ) {
			$items[] = wp_date( $fmt, (int) $e['t'] ) . ': ' . $e['text'];
		}
		$kinds = LASR_Worklog::kinds();
		foreach ( $week as $k => $n ) {
			if ( isset( $kinds[ $k ] ) ) {
				$items[] = $kinds[ $k ] . ': ' . (int) $n;
			}
		}
		if ( count( $history ) >= 2 && ! LASR_Worklog::events_since( LASR_Worklog::events(), time() - WEEK_IN_SECONDS ) ) {
			$last = end( $history );
			$prev = prev( $history );
			$d    = (int) $last['s'] - (int) $prev['s'];
			/* translators: 1: signed change, 2: score. */
			$items[] = sprintf( __( 'Score %1$s on the week before (now %2$d).', 'leymish-ai-shopping-readiness' ), ( $d >= 0 ? '+' : '' ) . $d, (int) $last['s'] );
		}
		$vis = LASR_Visibility::latest();
		if ( $vis && (int) $vis['t'] >= time() - WEEK_IN_SECONDS ) {
			$s = LASR_Visibility::share( $vis );
			/* translators: 1: answers citing the store, 2: questions answered. */
			$items[] = sprintf( __( 'AI visibility: cited in %1$d of %2$d answers.', 'leymish-ai-shopping-readiness' ), $s['cited'], $s['answered'] );
		}
		if ( ! $items ) {
			echo '<p class="lasr-empty-state">' . esc_html__( 'Nothing changed this week yet. Fix one of your next wins, or let your team propose changes on Monday.', 'leymish-ai-shopping-readiness' ) . '</p>';
			return;
		}
		echo '<ul class="lasr-list">';
		foreach ( $items as $i ) {
			echo '<li>' . esc_html( $i ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Earlier work by LeyMish agents (from Store Team), labelled as such; and Pro this month when Pro is on.
	 *
	 * @param array[] $history Snapshots.
	 */
	private static function earlier_work( array $history ) {
		$earlier = LASR_Worklog::earlier();
		$pro     = LASR_License::is_pro();
		if ( count( $earlier ) < 2 && ! $pro ) {
			return;
		}
		echo '<section class="lasr-card-box lasr-history">';
		if ( count( $earlier ) >= 2 ) {
			$first = reset( $earlier );
			$last  = end( $earlier );
			echo '<h2>' . esc_html__( 'Fixed by LeyMish agents', 'leymish-ai-shopping-readiness' ) . '</h2>';
			echo '<p class="lasr-then-now-score"><span class="lasr-big">' . esc_html( $first['score'] . ' → ' . $last['score'] ) . '</span> <span class="description">';
			/* translators: 1: first date, 2: last date. */
			echo esc_html( sprintf( __( 'audit score, %1$s to %2$s, before this plugin\'s Pro features were on', 'leymish-ai-shopping-readiness' ), $first['date'], $last['date'] ) ) . '</span></p><ul class="lasr-list">';
			foreach ( $earlier as $e ) {
				if ( '' !== $e['label'] ) {
					echo '<li>' . esc_html( $e['date'] . ': ' . $e['label'] ) . '</li>';
				}
			}
			echo '</ul>';
		}
		if ( $pro ) {
			$ucp   = LASR_Ucp::settings();
			$items = array(
				array( 'yes' === $ucp['ucp'], __( 'UCP business profile published at /.well-known/ucp', 'leymish-ai-shopping-readiness' ) ),
				array( 'yes' === $ucp['identifiers'] || 'yes' === $ucp['returns'] || 'yes' === $ucp['shipping'], __( 'Product schema enriched from your store settings', 'leymish-ai-shopping-readiness' ) ),
				array( (bool) LASR_Visibility::latest(), __( 'AI visibility baseline taken', 'leymish-ai-shopping-readiness' ) ),
				array( (bool) get_option( LASR_Schedule::OUTSIDE ), __( 'Outside monitoring checked your store', 'leymish-ai-shopping-readiness' ) ),
			);
			echo '<h2>' . esc_html__( 'Pro this month', 'leymish-ai-shopping-readiness' ) . '</h2><ul class="lasr-checklist">';
			foreach ( $items as $i ) {
				echo '<li class="' . ( $i[0] ? 'is-done' : 'is-todo' ) . '"><span class="screen-reader-text">' . esc_html( $i[0] ? __( 'Done:', 'leymish-ai-shopping-readiness' ) : __( 'Not yet:', 'leymish-ai-shopping-readiness' ) ) . '</span> ' . esc_html( $i[1] ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</section>';
	}

	/**
	 * The one review request: only on our Overview tab, only after the score improved by 10 or more points, and gone
	 * for good after either answer. No incentive, never a site-wide notice.
	 *
	 * @param int $delta Points gained since the first audit.
	 * @return bool
	 */
	public static function review_due( $delta ) {
		return (int) $delta >= 10 && ! get_option( self::REVIEW_OPTION );
	}

	/**
	 * The box itself.
	 */
	private static function review_box() {
		echo '<div class="lasr-review" role="region" aria-label="' . esc_attr__( 'Review request', 'leymish-ai-shopping-readiness' ) . '">';
		echo '<p><strong>' . esc_html__( 'Your score went up by 10 points or more.', 'leymish-ai-shopping-readiness' ) . '</strong> ' . esc_html__( 'If the plugin helped, a short review on WordPress.org helps other store owners find it. We only ask once.', 'leymish-ai-shopping-readiness' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lasr_review' );
		echo '<input type="hidden" name="action" value="lasr_review" />';
		echo '<button type="submit" name="answer" value="review" class="button button-primary">' . esc_html__( 'Leave a review', 'leymish-ai-shopping-readiness' ) . '</button> ';
		echo '<button type="submit" name="answer" value="no" class="button">' . esc_html__( 'No thanks', 'leymish-ai-shopping-readiness' ) . '</button>';
		echo '</form></div>';
	}

	/**
	 * Either answer hides the request for good; "Leave a review" then opens the WordPress.org review form.
	 */
	public static function handle_review() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_review' );
		update_option( self::REVIEW_OPTION, time(), false );
		$answer = isset( $_POST['answer'] ) ? sanitize_key( wp_unslash( $_POST['answer'] ) ) : '';
		if ( 'review' === $answer ) {
			wp_redirect( 'https://wordpress.org/support/plugin/leymish-ai-shopping-readiness/reviews/#new-post' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed WordPress.org URL.
			exit;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Accessible inline SVG line chart of the score, with the numbers as a table for screen readers.
	 *
	 * @param array[] $history Snapshots, oldest first.
	 */
	public static function chart( array $history ) {
		$w   = 600;
		$h   = 160;
		$pts = LASR_Impact::points( $history, $w, $h );
		$fmt = get_option( 'date_format' );
		$sum = array();
		foreach ( $history as $s ) {
			$sum[] = wp_date( $fmt, (int) $s['t'] ) . ': ' . (int) $s['s'];
		}
		echo '<figure class="lasr-chart"><svg viewBox="-44 -24 ' . esc_attr( (string) ( $w + 64 ) ) . ' ' . esc_attr( (string) ( $h + 60 ) ) . '" role="img" aria-labelledby="lasr-chart-t lasr-chart-d" focusable="false">';
		echo '<title id="lasr-chart-t">' . esc_html__( 'Audit score over time', 'leymish-ai-shopping-readiness' ) . '</title>';
		echo '<desc id="lasr-chart-d">' . esc_html( implode( '; ', $sum ) ) . '</desc>';
		foreach ( array( 0, 50, 100 ) as $g ) {
			$y = $h - $g / 100 * $h;
			echo '<line class="lasr-grid" x1="0" x2="' . esc_attr( (string) $w ) . '" y1="' . esc_attr( (string) $y ) . '" y2="' . esc_attr( (string) $y ) . '" />';
			echo '<text class="lasr-axis" x="-8" y="' . esc_attr( (string) ( $y + 4 ) ) . '" text-anchor="end">' . esc_html( (string) $g ) . '</text>';
		}
		if ( count( $pts ) > 1 ) {
			echo '<polyline class="lasr-line" points="' . esc_attr( implode( ' ', array_map( function ( $p ) {
				return $p[0] . ',' . $p[1];
			}, $pts ) ) ) . '" />';
		}
		$hist = array_values( $history );
		foreach ( $pts as $i => $p ) {
			$imported = ! empty( $hist[ $i ]['imported'] );
			echo '<circle class="lasr-dot' . ( $imported ? ' lasr-dot-imported' : '' ) . '" cx="' . esc_attr( (string) $p[0] ) . '" cy="' . esc_attr( (string) $p[1] ) . '" r="4" />';
		}
		$first = reset( $history );
		$last  = end( $history );
		echo '<text class="lasr-axis" x="0" y="' . esc_attr( (string) ( $h + 24 ) ) . '">' . esc_html( wp_date( $fmt, (int) $first['t'] ) ) . '</text>';
		if ( count( $history ) > 1 ) {
			echo '<text class="lasr-axis" x="' . esc_attr( (string) $w ) . '" y="' . esc_attr( (string) ( $h + 24 ) ) . '" text-anchor="end">' . esc_html( wp_date( $fmt, (int) $last['t'] ) ) . '</text>';
		}
		$imported = array_filter(
			$history,
			function ( $s ) {
				return ! empty( $s['imported'] );
			}
		);
		$caption  = $imported
			? __( 'Hollow points are audits by LeyMish agents before this plugin was installed (same audit, imported from Store Team). Then one point per week: the last audit of each week.', 'leymish-ai-shopping-readiness' )
			: __( 'One point per week (the last audit of each week). The starting score above is from your very first audit.', 'leymish-ai-shopping-readiness' );
		echo '</svg><figcaption class="description">' . esc_html( $caption ) . '</figcaption></figure>';
		echo '<table class="screen-reader-text"><caption>' . esc_html__( 'Audit score by week', 'leymish-ai-shopping-readiness' ) . '</caption><tbody>';
		foreach ( $history as $s ) {
			echo '<tr><th scope="row">' . esc_html( wp_date( $fmt, (int) $s['t'] ) ) . '</th><td>' . esc_html( (string) (int) $s['s'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Audit tab: every check with plain-words fixes.
	 *
	 * @param array|null $result Last audit.
	 */
	private static function render_audit( $result ) {
		if ( ! $result ) {
			self::three(
				__( 'No audit yet.', 'leymish-ai-shopping-readiness' ),
				__( 'Nothing yet.', 'leymish-ai-shopping-readiness' ),
				__( 'Run the audit: it checks your product data and what AI crawlers actually receive.', 'leymish-ai-shopping-readiness' ),
				null
			);
			echo '<p class="lasr-lede">' . esc_html__( 'Can ChatGPT, Claude, Perplexity and Google find, read and trust your products? This audit checks your product data and what AI crawlers actually receive from your store. It runs entirely on your site; nothing is sent anywhere.', 'leymish-ai-shopping-readiness' ) . '</p>';
			self::run_form( false, 'audit' );
			return;
		}
		$fixes = LASR_Scoring::fix_list( $result['checks'] );
		$pass  = 0;
		foreach ( $result['checks'] as $c ) {
			$pass += 'pass' === $c['status'] ? 1 : 0;
		}
		$hist    = LASR_Impact::history();
		$changes = LASR_Impact::baseline() && $hist ? LASR_Impact::changes( LASR_Impact::baseline(), end( $hist ) ) : array( 'fixed' => array() );
		self::three(
			/* translators: %d: checks that need work. */
			$fixes ? sprintf( _n( '%d check needs work.', '%d checks need work.', count( $fixes ), 'leymish-ai-shopping-readiness' ), count( $fixes ) ) : __( 'Every check passes.', 'leymish-ai-shopping-readiness' ),
			/* translators: 1: checks passing, 2: checks fixed since the first audit. */
			sprintf( __( '%1$d checks pass; %2$d fixed since your first audit.', 'leymish-ai-shopping-readiness' ), $pass, count( $changes['fixed'] ) ),
			$fixes ? $fixes[0]['fix'] : __( 'Run it again after changes to your store.', 'leymish-ai-shopping-readiness' ),
			$fixes ? array( LASR_Dashboard::fix_url( $fixes[0]['id'] ), __( 'Fix it', 'leymish-ai-shopping-readiness' ) ) : null
		);
		/* translators: %s: date and time of the audit. */
		$when = sprintf( __( 'Last audit: %s', 'leymish-ai-shopping-readiness' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $result['ran_at'] ) );
		LASR_Dashboard::hero( $result, LASR_Impact::history(), LASR_Impact::baseline(), $when, function () {
			self::run_form( true, 'audit' );
		} );

		echo '<details class="lasr-share"><summary>' . esc_html__( 'Share your score', 'leymish-ai-shopping-readiness' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Copy this text to share it. The plugin doesn\'t send it anywhere.', 'leymish-ai-shopping-readiness' ) . '</p>';
		echo '<textarea readonly rows="3" class="large-text" aria-label="' . esc_attr__( 'Shareable score text', 'leymish-ai-shopping-readiness' ) . '">' . esc_textarea( LASR_Scoring::share_text( (int) $result['score'], $result['checks'] ) ) . '</textarea></details>';

		$wins = LASR_Dashboard::wins( $result, 0 );
		if ( $wins ) {
			echo '<h2>' . esc_html__( 'Fix these first', 'leymish-ai-shopping-readiness' ) . '</h2>';
			LASR_Dashboard::wins_list( $wins );
		}

		$area  = array();
		$names = array();
		foreach ( LASR_Dashboard::category_map() as $key => $def ) {
			$names[ $key ] = $def[0];
			foreach ( $def[2] as $id ) {
				$area[ $id ] = $key;
			}
		}
		$order  = array_flip( array_keys( $names ) );
		$checks = $result['checks'];
		usort( $checks, function ( $a, $b ) use ( $area, $order ) {
			$x = isset( $area[ $a['id'] ] ) ? $order[ $area[ $a['id'] ] ] : 99;
			$y = isset( $area[ $b['id'] ] ) ? $order[ $area[ $b['id'] ] ] : 99;
			return $x - $y;
		} );
		echo '<h2>' . esc_html__( 'All checks', 'leymish-ai-shopping-readiness' ) . '</h2>';
		echo '<div class="lasr-scroll"><table class="widefat striped lasr-table lasr-checks"><thead><tr><th scope="col">' . esc_html__( 'Check', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col" class="lasr-col-area">' . esc_html__( 'Area', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Result', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Points', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Details and fix', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
		foreach ( $checks as $c ) {
			$pts = in_array( $c['status'], array( 'info', 'skip' ), true ) ? '—' : $c['earned'] . ' / ' . $c['points'];
			$a   = isset( $area[ $c['id'] ] ) ? $names[ $area[ $c['id'] ] ] : '';
			$fix = ( 'pass' !== $c['status'] && ! empty( $c['fix'] ) ) ? '<p class="lasr-fix-text">' . esc_html( $c['fix'] ) . '</p>' : '';
			echo '<tr><th scope="row">' . esc_html( $c['label'] ) . '</th><td class="lasr-col-area">' . esc_html( $a ) . '</td><td>' . wp_kses_post( self::badge( $c['status'] ) ) . '</td><td>' . esc_html( (string) $pts ) . '</td><td>' . esc_html( $c['detail'] ) . $fix . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $fix is escaped above.
		}
		echo '</tbody></table></div>';
		LASR_Health::render();

		$all = (array) $result['products'];
		if ( $all ) {
			echo '<h2>' . esc_html__( 'Products', 'leymish-ai-shopping-readiness' ) . '</h2><p>';
			/* translators: %d: products with gaps. */
			echo esc_html( sprintf( _n( '%d published product has at least one gap.', '%d published products have at least one gap.', LASR_Dashboard::products_with_gaps( $all ), 'leymish-ai-shopping-readiness' ), LASR_Dashboard::products_with_gaps( $all ) ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=products' ) ) . '">' . esc_html__( 'Fix them in the Products tab', 'leymish-ai-shopping-readiness' ) . '</a></p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-csv">';
			wp_nonce_field( 'lasr_export_csv' );
			echo '<input type="hidden" name="action" value="lasr_export_csv" />';
			/* translators: %d: number of products. */
			submit_button( sprintf( __( 'Download CSV (%d products)', 'leymish-ai-shopping-readiness' ), count( $all ) ), 'secondary', 'submit', false );
			echo '</form>';
			if ( $result['summary']['checked'] >= $result['summary']['limit'] ) {
				/* translators: %d: product limit. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Checked the first %d products. Developers can raise this with the lasr_product_limit filter.', 'leymish-ai-shopping-readiness' ), (int) $result['summary']['limit'] ) ) . '</p>';
			}
		}
	}

	/**
	 * Save the Feeds tab switches.
	 */
	public static function handle_feeds() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_feeds_settings' );
		$before = self::switches();
		$feeds  = empty( $_POST['feeds'] ) ? 'no' : 'yes';
		update_option( LASR_Feeds::ENABLED, $feeds, false );
		update_option( LASR_Llms::OPTION, empty( $_POST['llms'] ) ? 'no' : 'yes', false );
		LASR_Ucp::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitised in LASR_Ucp::save().
		$names = array(
			'feeds'       => __( 'OpenAI and Google product feeds', 'leymish-ai-shopping-readiness' ),
			'llms'        => __( 'llms.txt', 'leymish-ai-shopping-readiness' ),
			'ucp'         => __( 'UCP business profile', 'leymish-ai-shopping-readiness' ),
			'identifiers' => __( 'brand, GTIN and MPN in product schema', 'leymish-ai-shopping-readiness' ),
			'returns'     => __( 'return policy in product schema', 'leymish-ai-shopping-readiness' ),
			'shipping'    => __( 'shipping details in product schema', 'leymish-ai-shopping-readiness' ),
		);
		foreach ( self::switches() as $k => $on ) {
			if ( isset( $before[ $k ] ) && $before[ $k ] !== $on ) {
				/* translators: %s: the setting. */
				LASR_Worklog::event( 'setting', sprintf( $on ? __( 'Switched on: %s', 'leymish-ai-shopping-readiness' ) : __( 'Switched off: %s', 'leymish-ai-shopping-readiness' ), $names[ $k ] ) );
			}
		}
		if ( 'yes' === $feeds ) {
			LASR_Feeds::rebuild();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=feeds&lasr_msg=saved' ) );
		exit;
	}

	/**
	 * The Feeds tab's switches, on or off (for "what changed this week").
	 *
	 * @return array<string,bool>
	 */
	private static function switches() {
		$ucp = LASR_Ucp::settings();
		return array(
			'feeds'       => LASR_Feeds::enabled(),
			'llms'        => LASR_Llms::enabled(),
			'ucp'         => 'yes' === $ucp['ucp'],
			'identifiers' => 'yes' === $ucp['identifiers'],
			'returns'     => 'yes' === $ucp['returns'],
			'shipping'    => 'yes' === $ucp['shipping'],
		);
	}

	/**
	 * Rebuild the feeds now.
	 */
	public static function handle_rebuild() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_rebuild' );
		LASR_Feeds::rebuild();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=feeds&lasr_msg=rebuilt' ) );
		exit;
	}

	/**
	 * Feeds tab: OpenAI JSONL, Google TSV, llms.txt and the UCP profile, plus the schema settings.
	 */
	private static function render_feeds() {
		$built   = get_option( LASR_Feeds::BUILT );
		$on      = LASR_Feeds::enabled();
		$ucp     = LASR_Ucp::settings();
		$outside = get_option( LASR_Schedule::OUTSIDE );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only code from our own redirect.
		$msg    = isset( $_GET['lasr_msg'] ) ? sanitize_key( wp_unslash( $_GET['lasr_msg'] ) ) : '';
		$issues = is_array( $built ) && isset( $built['issues'] ) ? $built['issues'] : null;
		$wrong  = array();
		if ( ! $on ) {
			$wrong[] = __( 'Your product feeds are off', 'leymish-ai-shopping-readiness' );
		} elseif ( $issues && ( $issues['missing_required'] || $issues['no_identifier'] || $issues['no_brand'] ) ) {
			/* translators: %d: feed items with gaps. */
			$wrong[] = sprintf( __( '%d feed items are missing an identifier or brand', 'leymish-ai-shopping-readiness' ), max( (int) $issues['no_identifier'], (int) $issues['no_brand'], (int) $issues['missing_required'] ) );
		}
		if ( ! LASR_Llms::enabled() ) {
			$wrong[] = __( 'no llms.txt', 'leymish-ai-shopping-readiness' );
		}
		if ( 'yes' !== $ucp['ucp'] ) {
			$wrong[] = __( 'no UCP profile', 'leymish-ai-shopping-readiness' );
		}
		$done = array_filter(
			array(
				$on ? __( 'feeds on', 'leymish-ai-shopping-readiness' ) : '',
				LASR_Llms::enabled() ? __( 'llms.txt published', 'leymish-ai-shopping-readiness' ) : '',
				'yes' === $ucp['ucp'] ? __( 'UCP profile published', 'leymish-ai-shopping-readiness' ) : '',
				'yes' === $ucp['returns'] ? __( 'return policy in your schema', 'leymish-ai-shopping-readiness' ) : '',
			)
		);
		self::three(
			$wrong ? ucfirst( implode( ', ', $wrong ) ) . '.' : __( 'Your feeds, llms.txt and UCP profile are all published.', 'leymish-ai-shopping-readiness' ),
			$done ? ucfirst( implode( ', ', $done ) ) . '.' : __( 'Nothing published yet.', 'leymish-ai-shopping-readiness' ),
			$wrong ? __( 'Switch on what you want below and save. Everything here runs on your site, free.', 'leymish-ai-shopping-readiness' ) : __( 'Give the feed URLs to OpenAI and Google Merchant Center.', 'leymish-ai-shopping-readiness' ),
			$wrong ? array( '#lasr-feeds-form', __( 'Switch them on', 'leymish-ai-shopping-readiness' ) ) : null
		);
		if ( $msg ) {
			echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html( 'rebuilt' === $msg ? __( 'Feeds rebuilt.', 'leymish-ai-shopping-readiness' ) : __( 'Saved.', 'leymish-ai-shopping-readiness' ) ) . '</p></div>';
		}

		$fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$cards = array(
			array( 'openai.jsonl', __( 'OpenAI product feed (JSONL)', 'leymish-ai-shopping-readiness' ), LASR_Feeds::url( 'openai.jsonl' ), $on ),
			array( 'google.tsv', __( 'Google Merchant Center feed (TSV)', 'leymish-ai-shopping-readiness' ), LASR_Feeds::url( 'google.tsv' ), $on ),
			array( 'llms', 'llms.txt', home_url( '/llms.txt' ), LASR_Llms::enabled() ),
			array( 'ucp', __( 'UCP business profile', 'leymish-ai-shopping-readiness' ), home_url( '/.well-known/ucp' ), 'yes' === $ucp['ucp'] ),
		);
		echo '<ul class="lasr-cards lasr-feed-cards">';
		foreach ( $cards as $c ) {
			$status = $c[3] ? 'pass' : 'skip';
			$word   = $c[3] ? __( 'Published', 'leymish-ai-shopping-readiness' ) : __( 'Off', 'leymish-ai-shopping-readiness' );
			$about  = '';
			if ( $c[3] && in_array( $c[0], array( 'openai.jsonl', 'google.tsv' ), true ) && is_array( $built ) ) {
				if ( isset( $built['written'][ $c[0] ] ) && ! $built['written'][ $c[0] ] ) {
					$status = 'fail';
					$word   = __( 'Could not write the file', 'leymish-ai-shopping-readiness' );
				} elseif ( $issues && $issues['missing_required'] ) {
					$status = 'warn';
					$word   = __( 'Needs work', 'leymish-ai-shopping-readiness' );
				}
				/* translators: 1: number of items, 2: date and time. */
				$about = sprintf( __( '%1$d items, built %2$s', 'leymish-ai-shopping-readiness' ), (int) $built['rows'], wp_date( $fmt, (int) $built['at'] ) );
				$key   = 'openai.jsonl' === $c[0] ? 'openai' : 'google';
				if ( is_array( $outside ) && isset( $outside['feeds'][ $key ]['ok'] ) ) {
					$about .= '. ' . ( $outside['feeds'][ $key ]['ok'] ? __( 'Checked from outside: OK.', 'leymish-ai-shopping-readiness' ) : __( 'Checked from outside: errors found.', 'leymish-ai-shopping-readiness' ) );
				}
			} elseif ( 'ucp' === $c[0] && $c[3] ) {
				$about = __( 'Valid profile, no checkout services declared (WooCommerce core has no UCP checkout yet).', 'leymish-ai-shopping-readiness' );
			}
			echo '<li class="lasr-card lasr-st-' . esc_attr( $status ) . '"><h3>' . esc_html( $c[1] ) . '</h3><span class="lasr-badge lasr-' . esc_attr( $status ) . '">' . esc_html( $word ) . '</span>';
			if ( '' !== $about ) {
				echo '<p class="lasr-card-num">' . esc_html( $about ) . '</p>';
			}
			if ( $c[3] ) {
				echo '<p class="lasr-feed-url"><a href="' . esc_url( $c[2] ) . '" target="_blank" rel="noopener">' . esc_html( $c[2] ) . '</a></p>';
			}
			echo '</li>';
		}
		echo '</ul>';
		if ( $issues && $on ) {
			$lines = array();
			if ( $issues['no_identifier'] ) {
				/* translators: %d: number of feed rows. */
				$lines[] = sprintf( _n( '%d item has no GTIN or MPN', '%d items have no GTIN or MPN', $issues['no_identifier'], 'leymish-ai-shopping-readiness' ), $issues['no_identifier'] );
			}
			if ( $issues['no_brand'] ) {
				/* translators: %d: number of feed rows. */
				$lines[] = sprintf( _n( '%d item has no brand', '%d items have no brand', $issues['no_brand'], 'leymish-ai-shopping-readiness' ), $issues['no_brand'] );
			}
			if ( $lines ) {
				echo '<p>' . esc_html( implode( '; ', $lines ) . '.' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=products' ) ) . '">' . esc_html__( 'Fix them in Products', 'leymish-ai-shopping-readiness' ) . '</a></p>';
			}
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="lasr-feeds-form" class="lasr-card-box lasr-settings">';
		wp_nonce_field( 'lasr_feeds_settings' );
		echo '<input type="hidden" name="action" value="lasr_feeds_settings" /><h2>' . esc_html__( 'What to publish', 'leymish-ai-shopping-readiness' ) . '</h2>';
		echo '<p><label><input type="checkbox" name="feeds" value="1" ' . checked( $on, true, false ) . ' /> ' . esc_html__( 'Product feeds for OpenAI and Google at stable addresses on your domain, rebuilt a minute after you save a product.', 'leymish-ai-shopping-readiness' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="llms" value="1" ' . checked( LASR_Llms::enabled(), true, false ) . ' /> ' . esc_html__( 'A generated /llms.txt (your shop, top categories, policies and feed links). A real llms.txt file on your server always wins.', 'leymish-ai-shopping-readiness' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="ucp" value="1" ' . checked( 'yes', $ucp['ucp'], false ) . ' /> ' . esc_html__( 'A UCP business profile at /.well-known/ucp. It says your store speaks the Universal Commerce Protocol; it declares no checkout service until a checkout integration adds one.', 'leymish-ai-shopping-readiness' ) . '</label></p>';
		echo '<h2>' . esc_html__( 'Product schema', 'leymish-ai-shopping-readiness' ) . '</h2><p class="description">' . esc_html__( 'Added to the structured data on each product page, only from your own settings. Nothing is guessed.', 'leymish-ai-shopping-readiness' ) . '</p>';
		echo '<p><label><input type="checkbox" name="identifiers" value="1" ' . checked( 'yes', $ucp['identifiers'], false ) . ' /> ' . esc_html__( 'Brand, GTIN and MPN (where a product has them and the page doesn\'t say yet).', 'leymish-ai-shopping-readiness' ) . '</label></p>';
		echo '<fieldset><legend><label><input type="checkbox" name="returns" value="1" ' . checked( 'yes', $ucp['returns'], false ) . ' /> ' . esc_html__( 'Return policy', 'leymish-ai-shopping-readiness' ) . '</label></legend>';
		echo '<p class="lasr-inline"><label>' . esc_html__( 'Days to return', 'leymish-ai-shopping-readiness' ) . ' <input type="number" min="0" max="365" name="return_days" value="' . esc_attr( (string) $ucp['return_days'] ) . '" class="small-text" /></label> ';
		echo '<label>' . esc_html__( 'Return shipping', 'leymish-ai-shopping-readiness' ) . ' <select name="return_fees"><option value="free"' . selected( 'free', $ucp['return_fees'], false ) . '>' . esc_html__( 'free for the customer', 'leymish-ai-shopping-readiness' ) . '</option><option value="customer"' . selected( 'customer', $ucp['return_fees'], false ) . '>' . esc_html__( 'paid by the customer', 'leymish-ai-shopping-readiness' ) . '</option></select></label> ';
		echo '<label>' . esc_html__( 'How', 'leymish-ai-shopping-readiness' ) . ' <select name="return_how"><option value="mail"' . selected( 'mail', $ucp['return_how'], false ) . '>' . esc_html__( 'by mail', 'leymish-ai-shopping-readiness' ) . '</option><option value="store"' . selected( 'store', $ucp['return_how'], false ) . '>' . esc_html__( 'in store', 'leymish-ai-shopping-readiness' ) . '</option></select></label></p>';
		echo '<p class="description">' . esc_html__( '0 days means returns are not accepted. Match what your refund and returns page says.', 'leymish-ai-shopping-readiness' ) . '</p>';
		$provided = LASR_Ucp::provided();
		if ( ! empty( $provided['returns'] ) ) {
			echo '<p class="lasr-note">' . esc_html__( 'Already provided by your theme or another plugin, so we don\'t add a second one. Check that it matches your real policy.', 'leymish-ai-shopping-readiness' ) . '</p>';
		}
		echo '</fieldset>';
		$rates = LASR_Ucp::zone_rates();
		echo '<fieldset><legend><label><input type="checkbox" name="shipping" value="1" ' . checked( 'yes', $ucp['shipping'], false ) . ' /> ' . esc_html__( 'Shipping cost, from your WooCommerce shipping zones', 'leymish-ai-shopping-readiness' ) . '</label></legend>';
		if ( $rates ) {
			$parts = array();
			foreach ( $rates as $country => $amount ) {
				$parts[] = $country . ': ' . wp_strip_all_tags( wc_price( $amount ) );
			}
			/* translators: %s: countries and rates. */
			echo '<p class="description">' . esc_html( sprintf( __( 'We\'d publish: %s. Only plain flat rates and free shipping without a minimum are used.', 'leymish-ai-shopping-readiness' ), implode( ', ', array_slice( $parts, 0, 10 ) ) ) ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'No zone with a plain flat rate or free shipping was found, so nothing would be published.', 'leymish-ai-shopping-readiness' ) . '</p>';
		}
		echo '<p class="lasr-inline"><label>' . esc_html__( 'Handling, up to (days)', 'leymish-ai-shopping-readiness' ) . ' <input type="number" min="0" max="30" name="handling_max" value="' . esc_attr( (string) $ucp['handling_max'] ) . '" class="small-text" /></label> ';
		echo '<label>' . esc_html__( 'Delivery, up to (days)', 'leymish-ai-shopping-readiness' ) . ' <input type="number" min="0" max="60" name="transit_max" value="' . esc_attr( (string) $ucp['transit_max'] ) . '" class="small-text" /></label></p></fieldset>';
		submit_button( __( 'Save', 'leymish-ai-shopping-readiness' ) );
		echo '</form>';
		if ( $on ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'lasr_rebuild' );
			echo '<input type="hidden" name="action" value="lasr_rebuild" />';
			submit_button( __( 'Rebuild feeds now', 'leymish-ai-shopping-readiness' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		if ( ! LASR_License::is_pro() ) {
			echo '<section class="lasr-pro-preview lasr-card-box"><h3>' . esc_html__( 'Weekly validation with LeyMish Pro', 'leymish-ai-shopping-readiness' ) . '</h3><p>' . esc_html__( 'Every week Pro fetches your feeds and profile from outside, as OpenAI and Google do, and alerts you if they break or if your firewall blocks AI crawlers.', 'leymish-ai-shopping-readiness' ) . '</p>';
			LASR_Plan::button( 'feeds' );
			echo '</section>';
		}
	}
}
