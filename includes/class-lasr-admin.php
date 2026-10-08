<?php
/**
 * Admin screen: WooCommerce → AI Readiness.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu page, "Run audit" and CSV export handlers.
 */
class LASR_Admin {

	const SLUG = 'lasr-ai-readiness';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_lasr_run_audit', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_lasr_export_csv', array( __CLASS__, 'handle_csv' ) );
		add_action( 'admin_post_lasr_review', array( __CLASS__, 'handle_review' ) );
		add_action( 'admin_post_lasr_dismiss_panel', array( 'LASR_Dashboard', 'handle_dismiss' ) );
		add_action( 'admin_notices', array( __CLASS__, 'welcome_notice' ) );
		add_action( 'admin_post_lasr_dismiss_welcome', array( __CLASS__, 'handle_dismiss_welcome' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		LASR_Onboarding::init();
		add_filter( 'plugin_action_links_' . plugin_basename( LASR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'LeyMish AI Readiness', 'leymish-ai-shopping-readiness' ),
			__( 'LeyMish AI Readiness', 'leymish-ai-shopping-readiness' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	const WELCOME_OPTION = 'lasr_welcome';

	/**
	 * One welcome notice on the Plugins screen after the first activation (a notice, not a redirect), until the
	 * owner opens the page or dismisses it. Only on the Plugins screen, only for people who can use the plugin.
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
	 * "Open" link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Open dashboard', 'leymish-ai-shopping-readiness' ) . '</a>' );
		return $links;
	}

	/**
	 * Small stylesheet, only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function styles( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'lasr-admin', plugins_url( 'assets/admin.css', LASR_FILE ), array(), LASR_VERSION );
	}

	/**
	 * Run the audit (POST, nonce, capability), then go back to the page.
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
		$rows   = array( array( 'product_id', 'name', 'url', 'completeness_percent', 'missing', 'gtin_problem' ) );
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
	 * The page: Impact (default) and Audit tabs.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$tab    = isset( $_GET['tab'] ) && 'audit' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'audit' : 'impact';
		$result = LASR_Audit::last();
		echo '<div class="wrap lasr-wrap">';
		delete_option( self::WELCOME_OPTION ); // they found the page: the Plugins-screen welcome has done its job
		echo '<h1>' . esc_html__( 'LeyMish AI Readiness', 'leymish-ai-shopping-readiness' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		if ( isset( $_GET['lasr_done'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Audit finished.', 'leymish-ai-shopping-readiness' ) . '</p></div>';
		}
		LASR_Onboarding::notice();
		LASR_Onboarding::render_checklist( $result );
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'AI Readiness sections', 'leymish-ai-shopping-readiness' ) . '">';
		echo '<a href="' . esc_url( $base ) . '" class="nav-tab' . ( 'impact' === $tab ? ' nav-tab-active" aria-current="page' : '' ) . '">' . esc_html__( 'Impact', 'leymish-ai-shopping-readiness' ) . '</a>';
		echo '<a href="' . esc_url( $base . '&tab=audit' ) . '" class="nav-tab' . ( 'audit' === $tab ? ' nav-tab-active" aria-current="page' : '' ) . '">' . esc_html__( 'Audit', 'leymish-ai-shopping-readiness' ) . '</a>';
		echo '</nav>';
		if ( 'impact' === $tab ) {
			self::render_impact( $result );
		} else {
			self::render_audit( $result );
		}
		LASR_Onboarding::render_optin();
		echo '</div>';
	}

	/**
	 * The "Run the audit" form.
	 *
	 * @param bool   $again Whether an audit already exists.
	 * @param string $tab   Tab to come back to.
	 */
	private static function run_form( $again, $tab = 'impact' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-run">';
		wp_nonce_field( 'lasr_run_audit' );
		echo '<input type="hidden" name="action" value="lasr_run_audit" /><input type="hidden" name="lasr_tab" value="' . esc_attr( $tab ) . '" />';
		submit_button( $again ? __( 'Run the audit again', 'leymish-ai-shopping-readiness' ) : __( 'Run the audit', 'leymish-ai-shopping-readiness' ), 'primary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Takes up to a minute: it requests a few of your own pages as each AI crawler would.', 'leymish-ai-shopping-readiness' ) . '</span>';
		echo '</form>';
	}

	/**
	 * Impact tab: score over time, what was fixed, product data then vs now, what to fix next.
	 *
	 * @param array|null $result Last audit.
	 */
	private static function render_impact( $result ) {
		$history  = LASR_Impact::history();
		$baseline = LASR_Impact::baseline();
		if ( ! $result || ! $history || ! $baseline ) {
			echo '<div class="lasr-empty"><h2>' . esc_html__( 'See what improves', 'leymish-ai-shopping-readiness' ) . '</h2>';
			echo '<p>' . esc_html__( 'Run your first audit to set a starting point. After each change you make, run it again: this page shows your score over time, the checks you fixed and how much of your product data is complete. Everything is stored on this site only.', 'leymish-ai-shopping-readiness' ) . '</p>';
			self::run_form( false );
			echo '</div>';
			return;
		}
		$current = end( $history );
		$date    = get_option( 'date_format' );
		$labels  = array();
		foreach ( $result['checks'] as $c ) {
			$labels[ $c['id'] ] = $c['label'];
		}

		$delta = (int) $current['s'] - (int) $baseline['s'];
		if ( count( $history ) > 1 || $baseline['t'] !== $current['t'] ) {
			if ( 0 === $delta ) {
				/* translators: 1: starting score, 2: date of the first audit. */
				$text = sprintf( __( 'Same score as when you started (%1$d on %2$s)', 'leymish-ai-shopping-readiness' ), (int) $baseline['s'], wp_date( $date, (int) $baseline['t'] ) );
			} else {
				/* translators: 1: change in points, e.g. +12, 2: starting score, 3: date of the first audit. */
				$text = sprintf( __( '%1$s points since you started (%2$d on %3$s)', 'leymish-ai-shopping-readiness' ), ( $delta > 0 ? '+' : '' ) . $delta, (int) $baseline['s'], wp_date( $date, (int) $baseline['t'] ) );
			}
		} else {
			$text = __( 'Your starting point. Make a fix, run the audit again, and the change shows here.', 'leymish-ai-shopping-readiness' );
		}
		LASR_Dashboard::hero( $result, $history, $baseline, $text, function () {
			self::run_form( true );
		} );
		LASR_Dashboard::cards( $result['checks'] );
		/**
		 * Lets add-ons (Pro) put their own headline section right under the area cards.
		 *
		 * @param array[] $history  Weekly snapshots, oldest first.
		 * @param array   $baseline Install baseline.
		 * @param array   $result   Last audit.
		 */
		do_action( 'lasr_impact_hero', $history, $baseline, $result );
		if ( self::review_due( $delta ) ) {
			self::review_box();
		}

		$wins = LASR_Dashboard::wins( $result, 3 );
		echo '<h2>' . esc_html__( 'Your biggest wins', 'leymish-ai-shopping-readiness' ) . '</h2>';
		if ( $wins ) {
			LASR_Dashboard::wins_list( $wins );
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=audit' ) ) . '">' . esc_html__( 'See every check and product in the audit', 'leymish-ai-shopping-readiness' ) . '</a></p>';
		} else {
			echo '<p><strong>' . esc_html__( 'Nothing to fix. Nice.', 'leymish-ai-shopping-readiness' ) . '</strong></p>';
		}

		echo '<h2>' . esc_html__( 'Score over time', 'leymish-ai-shopping-readiness' ) . '</h2>';
		self::chart( $history );

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

		LASR_Dashboard::panel( $result );

		/**
		 * Lets add-ons (Pro) add their own Impact sections.
		 *
		 * @param array[] $history  Weekly snapshots, oldest first.
		 * @param array   $baseline Install baseline.
		 * @param array   $result   Last audit.
		 */
		do_action( 'lasr_impact_after', $history, $baseline, $result );
	}

	/**
	 * The one review request: only on our own Impact tab, only after the score improved by 10 or more points, and
	 * gone for good after either answer. No incentive, never a site-wide notice.
	 *
	 * @param int $delta Points gained since the first audit.
	 * @return bool
	 */
	public static function review_due( $delta ) {
		return (int) $delta >= 10 && ! get_option( self::REVIEW_OPTION );
	}

	const REVIEW_OPTION = 'lasr_review_asked';

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
		foreach ( $pts as $i => $p ) {
			echo '<circle class="lasr-dot" cx="' . esc_attr( (string) $p[0] ) . '" cy="' . esc_attr( (string) $p[1] ) . '" r="4" />';
		}
		$first = reset( $history );
		$last  = end( $history );
		echo '<text class="lasr-axis" x="0" y="' . esc_attr( (string) ( $h + 24 ) ) . '">' . esc_html( wp_date( $fmt, (int) $first['t'] ) ) . '</text>';
		if ( count( $history ) > 1 ) {
			echo '<text class="lasr-axis" x="' . esc_attr( (string) $w ) . '" y="' . esc_attr( (string) ( $h + 24 ) ) . '" text-anchor="end">' . esc_html( wp_date( $fmt, (int) $last['t'] ) ) . '</text>';
		}
		echo '</svg><figcaption class="description">' . esc_html__( 'One point per week (the last audit of each week). The starting score above is from your very first audit.', 'leymish-ai-shopping-readiness' ) . '</figcaption></figure>';
		echo '<table class="screen-reader-text"><caption>' . esc_html__( 'Audit score by week', 'leymish-ai-shopping-readiness' ) . '</caption><tbody>';
		foreach ( $history as $s ) {
			echo '<tr><th scope="row">' . esc_html( wp_date( $fmt, (int) $s['t'] ) ) . '</th><td>' . esc_html( (string) (int) $s['s'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Audit tab: the full result (unchanged from 1.0).
	 *
	 * @param array|null $result Last audit.
	 */
	private static function render_audit( $result ) {
		echo '<p class="lasr-lede">' . esc_html__( 'Can ChatGPT, Claude, Perplexity and Google find, read and trust your products? This audit checks your product data and what AI crawlers actually receive from your store. It runs entirely on your site; nothing is sent anywhere.', 'leymish-ai-shopping-readiness' ) . '</p>';
		if ( ! $result ) {
			self::run_form( false, 'audit' );
			return;
		}

		/* translators: %s: date and time of the audit. */
		$when = sprintf( __( 'Last audit: %s', 'leymish-ai-shopping-readiness' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $result['ran_at'] ) );
		LASR_Dashboard::hero( $result, LASR_Impact::history(), LASR_Impact::baseline(), $when, function () {
			self::run_form( true, 'audit' );
		} );
		LASR_Dashboard::cards( $result['checks'] );

		echo '<details class="lasr-share"><summary>' . esc_html__( 'Share your score', 'leymish-ai-shopping-readiness' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Copy this text to share it. The plugin doesn\'t send it anywhere.', 'leymish-ai-shopping-readiness' ) . '</p>';
		echo '<textarea readonly rows="3" class="large-text" aria-label="' . esc_attr__( 'Shareable score text', 'leymish-ai-shopping-readiness' ) . '">' . esc_textarea( LASR_Scoring::share_text( (int) $result['score'], $result['checks'] ) ) . '</textarea></details>';

		$wins = LASR_Dashboard::wins( $result, 0 );
		if ( $wins ) {
			echo '<h2>' . esc_html__( 'Fix these first', 'leymish-ai-shopping-readiness' ) . '</h2>';
			LASR_Dashboard::wins_list( $wins );
		} else {
			echo '<p><strong>' . esc_html__( 'Nothing to fix. Nice.', 'leymish-ai-shopping-readiness' ) . '</strong></p>';
		}

		// Every check, grouped by the same four areas as the cards (anything unmapped goes last).
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
		echo '<table class="widefat striped lasr-table lasr-checks"><thead><tr><th scope="col">' . esc_html__( 'Check', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col" class="lasr-col-area">' . esc_html__( 'Area', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Result', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Points', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Details', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
		foreach ( $checks as $c ) {
			$pts = in_array( $c['status'], array( 'info', 'skip' ), true ) ? '—' : $c['earned'] . ' / ' . $c['points'];
			$a   = isset( $area[ $c['id'] ] ) ? $names[ $area[ $c['id'] ] ] : '';
			echo '<tr><th scope="row">' . esc_html( $c['label'] ) . '</th><td class="lasr-col-area">' . esc_html( $a ) . '</td><td>' . wp_kses_post( self::badge( $c['status'] ) ) . '</td><td>' . esc_html( (string) $pts ) . '</td><td>' . esc_html( $c['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$all = (array) $result['products'];
		if ( $all ) {
			$labels   = LASR_Audit::field_labels();
			$gaps     = array_values( array_filter( $all, function ( $p ) {
				return ! empty( $p['missing'] );
			} ) );
			$complete = count( $all ) - count( $gaps );
			echo '<h2>' . esc_html__( 'Products with the most gaps', 'leymish-ai-shopping-readiness' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-csv">';
			wp_nonce_field( 'lasr_export_csv' );
			echo '<input type="hidden" name="action" value="lasr_export_csv" />';
			/* translators: %d: number of products. */
			submit_button( sprintf( __( 'Download CSV (%d products)', 'leymish-ai-shopping-readiness' ), count( $all ) ), 'secondary', 'submit', false );
			echo '</form>';
			if ( $gaps ) {
				/* translators: %d: number of products with gaps. */
				echo '<details class="lasr-products"><summary>' . esc_html( sprintf( _n( 'See %d product with gaps', 'See products with gaps (%d)', count( $gaps ), 'leymish-ai-shopping-readiness' ), count( $gaps ) ) ) . '</summary>';
				echo '<table class="widefat striped lasr-table"><thead><tr><th scope="col">' . esc_html__( 'Product', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Complete', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Missing', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
				foreach ( array_slice( $gaps, 0, 50 ) as $p ) {
					$missing = array();
					foreach ( $p['missing'] as $f ) {
						$missing[] = isset( $labels[ $f ] ) ? $labels[ $f ] : $f;
					}
					echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( $p['id'] ) ) . '">' . esc_html( $p['name'] ) . '</a></td><td>' . esc_html( (int) round( 100 * $p['score'] ) . '%' ) . '</td><td>' . esc_html( implode( ', ', $missing ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
				if ( count( $gaps ) > 50 ) {
					echo '<p class="description">' . esc_html__( 'Showing the 50 with the most gaps; the CSV has every product.', 'leymish-ai-shopping-readiness' ) . '</p>';
				}
				echo '</details>';
			}
			if ( $complete > 0 ) {
				/* translators: %d: number of complete products. */
				echo '<p class="description">' . esc_html( sprintf( _n( '%d product has nothing missing and is hidden here.', '%d products have nothing missing and are hidden here.', $complete, 'leymish-ai-shopping-readiness' ), $complete ) ) . '</p>';
			}
			if ( $result['summary']['checked'] >= $result['summary']['limit'] ) {
				/* translators: %d: product limit. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Checked the first %d products. Developers can raise this with the lasr_product_limit filter.', 'leymish-ai-shopping-readiness' ), (int) $result['summary']['limit'] ) ) . '</p>';
			}
		}
	}

	/**
	 * Products missing a valid identifier or a brand (what the Pro bulk editor fixes).
	 *
	 * @param array $products Audit product rows.
	 * @return int
	 */
	public static function identifier_gaps( $products ) {
		return LASR_Dashboard::identifier_gaps( $products );
	}
}
