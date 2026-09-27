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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LASR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Submenu under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'AI Shopping Readiness', 'leymish-ai-shopping-readiness' ),
			__( 'AI Readiness', 'leymish-ai-shopping-readiness' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * "Open" link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Run audit', 'leymish-ai-shopping-readiness' ) . '</a>' );
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
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&lasr_done=1' ) );
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
	 * The page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$result = LASR_Audit::last();
		echo '<div class="wrap lasr-wrap">';
		echo '<h1>' . esc_html__( 'AI Shopping Readiness', 'leymish-ai-shopping-readiness' ) . '</h1>';
		echo '<p class="lasr-lede">' . esc_html__( 'Can ChatGPT, Claude, Perplexity and Google find, read and trust your products? This audit checks your product data and what AI crawlers actually receive from your store. It runs entirely on your site; nothing is sent anywhere.', 'leymish-ai-shopping-readiness' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-run">';
		wp_nonce_field( 'lasr_run_audit' );
		echo '<input type="hidden" name="action" value="lasr_run_audit" />';
		submit_button( $result ? __( 'Run the audit again', 'leymish-ai-shopping-readiness' ) : __( 'Run the audit', 'leymish-ai-shopping-readiness' ), 'primary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Takes up to a minute: it requests a few of your own pages as each AI crawler would.', 'leymish-ai-shopping-readiness' ) . '</span>';
		echo '</form>';

		if ( ! $result ) {
			echo '</div>';
			return;
		}

		$fixes = LASR_Scoring::fix_list( $result['checks'] );
		echo '<div class="lasr-score lasr-band-' . esc_attr( $result['band'] ) . '">';
		echo '<div class="lasr-number">' . esc_html( (string) $result['score'] ) . '<span>/100</span></div>';
		/* translators: %s: date and time of the audit. */
		echo '<div class="lasr-when">' . esc_html( sprintf( __( 'Last audit: %s', 'leymish-ai-shopping-readiness' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $result['ran_at'] ) ) ) . '</div>';
		echo '</div>';

		echo '<details class="lasr-share"><summary>' . esc_html__( 'Share your score', 'leymish-ai-shopping-readiness' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Copy this text to share it. The plugin doesn\'t send it anywhere.', 'leymish-ai-shopping-readiness' ) . '</p>';
		echo '<textarea readonly rows="3" class="large-text" aria-label="' . esc_attr__( 'Shareable score text', 'leymish-ai-shopping-readiness' ) . '">' . esc_textarea( LASR_Scoring::share_text( (int) $result['score'], $result['checks'] ) ) . '</textarea></details>';

		if ( $fixes ) {
			echo '<h2>' . esc_html__( 'Fix these first', 'leymish-ai-shopping-readiness' ) . '</h2><ol class="lasr-fixes">';
			foreach ( $fixes as $f ) {
				/* translators: %s: points that fixing this would add. */
				echo '<li><strong>' . esc_html( $f['label'] ) . '</strong> <span class="lasr-gain">' . esc_html( sprintf( __( '+%s points', 'leymish-ai-shopping-readiness' ), $f['lost'] ) ) . '</span><br />';
				echo '<span class="lasr-detail">' . esc_html( $f['detail'] ) . '</span><br />' . esc_html( $f['fix'] );
				if ( ! defined( 'LASR_PRO_VERSION' ) && LASR_Scoring::pro_helps( $f['id'] ) ) {
					echo ' <a class="lasr-pro-link" href="https://www.leymish.com/woocommerce/pro.html" target="_blank" rel="noopener">' . esc_html__( 'Fix it faster with Pro', 'leymish-ai-shopping-readiness' ) . '</a>';
				}
				echo '</li>';
			}
			echo '</ol>';
		} else {
			echo '<p><strong>' . esc_html__( 'Nothing to fix. Nice.', 'leymish-ai-shopping-readiness' ) . '</strong></p>';
		}

		echo '<h2>' . esc_html__( 'All checks', 'leymish-ai-shopping-readiness' ) . '</h2>';
		echo '<table class="widefat striped lasr-table"><thead><tr><th>' . esc_html__( 'Check', 'leymish-ai-shopping-readiness' ) . '</th><th>' . esc_html__( 'Result', 'leymish-ai-shopping-readiness' ) . '</th><th>' . esc_html__( 'Points', 'leymish-ai-shopping-readiness' ) . '</th><th>' . esc_html__( 'Details', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
		foreach ( $result['checks'] as $c ) {
			$pts = in_array( $c['status'], array( 'info', 'skip' ), true ) ? '—' : $c['earned'] . ' / ' . $c['points'];
			echo '<tr><td>' . esc_html( $c['label'] ) . '</td><td>' . wp_kses_post( self::badge( $c['status'] ) ) . '</td><td>' . esc_html( (string) $pts ) . '</td><td>' . esc_html( $c['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$products = array_slice( $result['products'], 0, 50 );
		if ( $products ) {
			$labels = LASR_Audit::field_labels();
			echo '<h2>' . esc_html__( 'Products with the most gaps', 'leymish-ai-shopping-readiness' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'lasr_export_csv' );
			echo '<input type="hidden" name="action" value="lasr_export_csv" />';
			/* translators: %d: number of products. */
			submit_button( sprintf( __( 'Download CSV (%d products)', 'leymish-ai-shopping-readiness' ), count( $result['products'] ) ), 'secondary', 'submit', false );
			echo '</form>';
			echo '<table class="widefat striped lasr-table"><thead><tr><th>' . esc_html__( 'Product', 'leymish-ai-shopping-readiness' ) . '</th><th>' . esc_html__( 'Complete', 'leymish-ai-shopping-readiness' ) . '</th><th>' . esc_html__( 'Missing', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
			foreach ( $products as $p ) {
				$missing = array();
				foreach ( $p['missing'] as $f ) {
					$missing[] = isset( $labels[ $f ] ) ? $labels[ $f ] : $f;
				}
				echo '<tr><td><a href="' . esc_url( (string) get_edit_post_link( $p['id'] ) ) . '">' . esc_html( $p['name'] ) . '</a></td><td>' . esc_html( (int) round( 100 * $p['score'] ) . '%' ) . '</td><td>' . esc_html( $missing ? implode( ', ', $missing ) : __( 'nothing', 'leymish-ai-shopping-readiness' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
			if ( $result['summary']['checked'] >= $result['summary']['limit'] ) {
				/* translators: %d: product limit. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Checked the first %d products. Developers can raise this with the lasr_product_limit filter.', 'leymish-ai-shopping-readiness' ), (int) $result['summary']['limit'] ) ) . '</p>';
			}
		}

		if ( ! defined( 'LASR_PRO_VERSION' ) ) {
			echo '<p class="lasr-pro">' . wp_kses_post(
				sprintf(
					/* translators: %s: link to the Pro add-on page. */
					__( 'Want to fix gaps faster? The optional Pro add-on adds a bulk editor for GTIN, brand and MPN, OpenAI and Google product feeds on your own domain, an llms.txt generator, a weekly re-audit email and score history. %s', 'leymish-ai-shopping-readiness' ),
					'<a href="https://www.leymish.com/woocommerce/pro.html" target="_blank" rel="noopener">' . esc_html__( 'About Pro', 'leymish-ai-shopping-readiness' ) . '</a>'
				)
			) . '</p>';
		}
		echo '</div>';
	}
}
