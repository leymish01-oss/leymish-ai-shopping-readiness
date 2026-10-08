<?php
/**
 * The work log (counts only, stored on this site), the before/after chart and the before/after report. Free since
 * 2.0: it all runs on this site. Earlier work by LeyMish agents (from Store Team) is shown as such, never as this
 * plugin's work, and never as a gain that didn't happen.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Work log, before/after chart, earlier work and the report.
 */
class LASR_Worklog {

	const WORKLOG = 'lasr_worklog';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_lasr_report', array( __CLASS__, 'handle_report' ) );
	}

	/**
	 * Labels for each kind of work.
	 *
	 * @return array<string,string>
	 */
	public static function kinds() {
		return array(
			'identifiers' => __( 'Products given a GTIN, MPN or brand in the Products tab', 'leymish-ai-shopping-readiness' ),
			'brand_all'   => __( 'Products given your brand with "Set brand for all"', 'leymish-ai-shopping-readiness' ),
			'mpn_sku'     => __( 'Products given their SKU as the MPN (private label)', 'leymish-ai-shopping-readiness' ),
			'gtin_csv'    => __( 'GTINs imported from a supplier file', 'leymish-ai-shopping-readiness' ),
			'attributes'  => __( 'AI attribute suggestions you applied', 'leymish-ai-shopping-readiness' ),
			'description' => __( 'AI descriptions you applied', 'leymish-ai-shopping-readiness' ),
			'category'    => __( 'AI Google categories you applied', 'leymish-ai-shopping-readiness' ),
			'alt_text'    => __( 'AI image alt texts you applied', 'leymish-ai-shopping-readiness' ),
		);
	}

	/**
	 * The log: totals per kind, the 50 most recent entries, and when logging started.
	 *
	 * @return array{counts:array<string,int>,recent:array,since:int}
	 */
	public static function worklog() {
		$w = get_option( self::WORKLOG );
		return is_array( $w ) ? $w : array(
			'counts' => array(),
			'recent' => array(),
			'since'  => 0,
		);
	}

	/**
	 * Add one entry (pure; log() saves it).
	 *
	 * @param array  $log        Current log.
	 * @param string $kind       One of kinds().
	 * @param int    $product_id Product ID.
	 * @param int    $now        Timestamp (tests pass one).
	 * @param int    $n          How many products (bulk fixes count each product).
	 * @return array The new log.
	 */
	public static function add( array $log, $kind, $product_id, $now, $n = 1 ) {
		if ( ! $log['since'] ) {
			$log['since'] = $now;
		}
		$log['counts'][ $kind ] = ( isset( $log['counts'][ $kind ] ) ? (int) $log['counts'][ $kind ] : 0 ) + max( 1, (int) $n );
		$log['recent'][]        = array(
			't'    => $now,
			'kind' => $kind,
			'id'   => (int) $product_id,
			'n'    => max( 1, (int) $n ),
		);
		$log['recent']          = array_slice( $log['recent'], -50 );
		return $log;
	}

	/**
	 * Record one piece of work.
	 *
	 * @param string $kind       One of kinds().
	 * @param int    $product_id Product ID (0 for a bulk fix).
	 * @param int    $n          Products changed.
	 */
	public static function log( $kind, $product_id, $n = 1 ) {
		update_option( self::WORKLOG, self::add( self::worklog(), $kind, $product_id, time(), $n ), false );
	}

	/**
	 * Work counted since the given time (for "what changed this week").
	 *
	 * @param array $log   Log.
	 * @param int   $since Timestamp.
	 * @return array<string,int> kind => products.
	 */
	public static function since( array $log, $since ) {
		$out = array();
		foreach ( (array) $log['recent'] as $e ) {
			if ( (int) $e['t'] >= (int) $since ) {
				$out[ $e['kind'] ] = ( isset( $out[ $e['kind'] ] ) ? $out[ $e['kind'] ] : 0 ) + ( isset( $e['n'] ) ? (int) $e['n'] : 1 );
			}
		}
		return $out;
	}

	/**
	 * Paired bars per product-data row (start vs now), with the same numbers in a screen-reader table.
	 *
	 * @param array $baseline Baseline snapshot.
	 * @param array $current  Latest snapshot.
	 */
	public static function before_after( array $baseline, array $current ) {
		$names = array(
			'identifier' => __( 'Valid GTIN or MPN', 'leymish-ai-shopping-readiness' ),
			'brand'      => __( 'Brand', 'leymish-ai-shopping-readiness' ),
			'alt'        => __( 'Main image has alt text', 'leymish-ai-shopping-readiness' ),
		);
		$rows  = array();
		foreach ( LASR_Impact::product_rows( $baseline, $current ) as $r ) {
			if ( null !== $r['then'] && null !== $r['now'] ) {
				$rows[] = $r;
			}
		}
		if ( ! $rows ) {
			return;
		}
		$h = 30 + 46 * count( $rows );
		echo '<figure class="lasr-ba"><svg viewBox="0 0 640 ' . esc_attr( (string) $h ) . '" aria-hidden="true" focusable="false">';
		echo '<rect class="lasr-ba-then" x="190" y="4" width="12" height="12" rx="2" /><text class="lasr-axis" x="208" y="15">' . esc_html__( 'At the start', 'leymish-ai-shopping-readiness' ) . '</text>';
		echo '<rect class="lasr-ba-now" x="320" y="4" width="12" height="12" rx="2" /><text class="lasr-axis" x="338" y="15">' . esc_html__( 'Now', 'leymish-ai-shopping-readiness' ) . '</text>';
		$y = 30;
		foreach ( $rows as $r ) {
			$w1 = round( 380 * $r['then_pct'] / 100, 1 );
			$w2 = round( 380 * $r['now_pct'] / 100, 1 );
			echo '<text class="lasr-axis" x="0" y="' . esc_attr( (string) ( $y + 16 ) ) . '">' . esc_html( $names[ $r['key'] ] ) . '</text>';
			echo '<rect class="lasr-ba-then" x="190" y="' . esc_attr( (string) $y ) . '" width="' . esc_attr( (string) max( 1, $w1 ) ) . '" height="14" rx="3" />';
			echo '<text class="lasr-axis" x="' . esc_attr( (string) ( 196 + $w1 ) ) . '" y="' . esc_attr( (string) ( $y + 12 ) ) . '">' . esc_html( $r['then_pct'] . '%' ) . '</text>';
			echo '<rect class="lasr-ba-now" x="190" y="' . esc_attr( (string) ( $y + 18 ) ) . '" width="' . esc_attr( (string) max( 1, $w2 ) ) . '" height="14" rx="3" />';
			echo '<text class="lasr-axis" x="' . esc_attr( (string) ( 196 + $w2 ) ) . '" y="' . esc_attr( (string) ( $y + 30 ) ) . '">' . esc_html( $r['now_pct'] . '%' ) . '</text>';
			$y += 46;
		}
		echo '</svg></figure>';
		echo '<table class="screen-reader-text"><caption>' . esc_html__( 'Product data at the start and now', 'leymish-ai-shopping-readiness' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Products with…', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'At the start', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Now', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><th scope="row">' . esc_html( $names[ $r['key'] ] ) . '</th><td>' . esc_html( $r['then_pct'] . '%' ) . '</td><td>' . esc_html( $r['now_pct'] . '%' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Earlier work by LeyMish agents for this store, from Store Team (connected stores only; cached 12 hours).
	 * Each row: date (Y-m-d), score, label.
	 *
	 * @return array[]
	 */
	public static function earlier() {
		if ( ! class_exists( 'LASR_Team' ) || ! LASR_Team::connection() ) {
			return array();
		}
		$c = get_transient( 'lasr_earlier' );
		if ( is_array( $c ) ) {
			return $c;
		}
		$conn = LASR_Team::connection();
		$r    = LASR_Service::post( '/v1/team/history', array(), $conn['site_token'] );
		$rows = self::clean_events( 200 === $r['status'] && isset( $r['data']['events'] ) ? $r['data']['events'] : array() );
		set_transient( 'lasr_earlier', $rows, 12 * HOUR_IN_SECONDS );
		return $rows;
	}

	/**
	 * Keep well-formed events only (pure).
	 *
	 * @param mixed $events Events from the service.
	 * @return array[]
	 */
	public static function clean_events( $events ) {
		$rows = array();
		foreach ( array_slice( is_array( $events ) ? $events : array(), 0, 20 ) as $e ) {
			if ( is_array( $e ) && isset( $e['date'], $e['score'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $e['date'] ) ) {
				$rows[] = array(
					'date'  => (string) $e['date'],
					'score' => max( 0, min( 100, (int) $e['score'] ) ),
					'label' => sanitize_text_field( isset( $e['label'] ) ? (string) $e['label'] : '' ),
				);
			}
		}
		return $rows;
	}

	/**
	 * Download the report (free: it's built on this site from this site's own data).
	 */
	public static function handle_report() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_report' );
		$result   = LASR_Audit::last();
		$history  = LASR_Impact::history();
		$baseline = LASR_Impact::baseline();
		if ( ! $result || ! $history || ! $baseline ) {
			wp_die( esc_html__( 'Run the audit first.', 'leymish-ai-shopping-readiness' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ai-readiness-report-' . gmdate( 'Y-m-d' ) . '.html' );
		echo self::report_html( $history, $baseline, $result, self::worklog(), self::earlier(), class_exists( 'LASR_Visibility' ) ? LASR_Visibility::latest() : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in report_html().
		exit;
	}

	/**
	 * The report as one self-contained HTML page.
	 *
	 * @param array[]    $history  Snapshots.
	 * @param array      $baseline Baseline.
	 * @param array      $result   Last audit.
	 * @param array      $log      Work log.
	 * @param array[]    $earlier  Earlier work by LeyMish agents.
	 * @param array|null $vis      Latest AI visibility run.
	 * @return string
	 */
	public static function report_html( array $history, array $baseline, array $result, array $log, array $earlier = array(), $vis = null ) {
		$current = end( $history );
		$date    = get_option( 'date_format' );
		$labels  = array();
		foreach ( $result['checks'] as $c ) {
			$labels[ $c['id'] ] = $c['label'];
		}
		$changes = LASR_Impact::changes( $baseline, $current );
		ob_start();
		LASR_Admin::chart( $history );
		$chart = ob_get_clean();
		$names = array(
			'identifier' => __( 'Valid GTIN or MPN', 'leymish-ai-shopping-readiness' ),
			'brand'      => __( 'Brand', 'leymish-ai-shopping-readiness' ),
			'alt'        => __( 'Main image has alt text', 'leymish-ai-shopping-readiness' ),
		);
		$rows  = '';
		foreach ( LASR_Impact::product_rows( $baseline, $current ) as $r ) {
			$then  = null === $r['then'] ? '—' : $r['then'] . ' (' . $r['then_pct'] . '%)';
			$now   = null === $r['now'] ? '—' : $r['now'] . ' (' . $r['now_pct'] . '%)';
			$rows .= '<tr><th scope="row">' . esc_html( $names[ $r['key'] ] ) . '</th><td>' . esc_html( $then ) . '</td><td>' . esc_html( $now ) . '</td></tr>';
		}
		$fixed = '';
		foreach ( $changes['fixed'] as $id ) {
			$fixed .= '<li>' . esc_html( isset( $labels[ $id ] ) ? $labels[ $id ] : $id ) . '</li>';
		}
		$work = '';
		foreach ( self::kinds() as $k => $label ) {
			if ( ! empty( $log['counts'][ $k ] ) ) {
				$work .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) (int) $log['counts'][ $k ] ) . '</td></tr>';
			}
		}
		$next = '';
		foreach ( array_slice( LASR_Scoring::fix_list( $result['checks'] ), 0, 3 ) as $f ) {
			$next .= '<li><strong>' . esc_html( $f['label'] ) . '</strong>: ' . esc_html( $f['fix'] ) . '</li>';
		}
		$title = sprintf(
			/* translators: %s: site name. */
			__( 'AI shopping readiness: %s', 'leymish-ai-shopping-readiness' ),
			get_bloginfo( 'name' )
		);
		$css = 'body{font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327;max-width:760px;margin:32px auto;padding:0 16px}'
			. 'h1{font-size:24px}h2{font-size:18px;margin-top:28px}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #dcdcde}'
			. '.big{font-size:40px;font-weight:700}.muted{color:#50575e}.screen-reader-text{position:absolute;left:-9999px}'
			. 'svg{width:100%;height:auto;border:1px solid #dcdcde;border-radius:8px}.lasr-grid{stroke:#dcdcde}.lasr-axis{fill:#50575e;font-size:12px}.lasr-line{fill:none;stroke:#2271b1;stroke-width:2.5}.lasr-dot{fill:#2271b1}'
			. '@media print{body{margin:0}}';
		return '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $title ) . '</title><style>' . $css . '</style></head><body>'
			. '<h1>' . esc_html( $title ) . '</h1>'
			/* translators: 1: first audit date, 2: latest audit date. */
			. '<p class="muted">' . esc_html( sprintf( __( 'From %1$s to %2$s. Made by LeyMish AI Readiness on this site.', 'leymish-ai-shopping-readiness' ), wp_date( $date, (int) $baseline['t'] ), wp_date( $date, (int) $current['t'] ) ) ) . '</p>'
			. '<p><span class="big">' . esc_html( (int) $baseline['s'] . ' → ' . (int) $current['s'] ) . '</span> <span class="muted">' . esc_html__( 'audit score out of 100', 'leymish-ai-shopping-readiness' ) . '</span></p>'
			. $chart
			. self::earlier_html( $earlier )
			. '<h2>' . esc_html__( 'Checks fixed', 'leymish-ai-shopping-readiness' ) . '</h2>' . ( $fixed ? '<ul>' . $fixed . '</ul>' : '<p>' . esc_html__( 'None yet.', 'leymish-ai-shopping-readiness' ) . '</p>' )
			. '<h2>' . esc_html__( 'Product data', 'leymish-ai-shopping-readiness' ) . '</h2><table><thead><tr><th scope="col">' . esc_html__( 'Products with…', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'At the start', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Now', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
			. ( $work ? '<h2>' . esc_html__( 'Work done on this site', 'leymish-ai-shopping-readiness' ) . '</h2><table><tbody>' . $work . '</tbody></table>' : '' )
			. self::visibility_html( $vis )
			. ( $next ? '<h2>' . esc_html__( 'Next', 'leymish-ai-shopping-readiness' ) . '</h2><ol>' . $next . '</ol>' : '' )
			. '</body></html>';
	}

	/**
	 * The earlier-work section of the report.
	 *
	 * @param array[] $earlier Rows.
	 * @return string
	 */
	private static function earlier_html( array $earlier ) {
		if ( count( $earlier ) < 2 ) {
			return '';
		}
		$first = reset( $earlier );
		$last  = end( $earlier );
		/* translators: 1: first score, 2: date, 3: later score, 4: date. */
		return '<h2>' . esc_html__( 'Fixed by LeyMish agents before this report', 'leymish-ai-shopping-readiness' ) . '</h2><p>' . esc_html( sprintf( __( '%1$d on %2$s to %3$d on %4$s, measured with the same audit.', 'leymish-ai-shopping-readiness' ), $first['score'], $first['date'], $last['score'], $last['date'] ) ) . '</p>';
	}

	/**
	 * The AI visibility section of the report.
	 *
	 * @param array|null $vis Latest run.
	 * @return string
	 */
	private static function visibility_html( $vis ) {
		if ( ! is_array( $vis ) || empty( $vis['results'] ) ) {
			return '';
		}
		$rows = '';
		foreach ( $vis['results'] as $r ) {
			if ( isset( $r['error'] ) ) {
				continue;
			}
			$rows .= '<tr><th scope="row">' . esc_html( $r['question'] ) . '</th><td>' . esc_html( ! empty( $r['cited'] ) ? __( 'Cited', 'leymish-ai-shopping-readiness' ) : __( 'Not cited', 'leymish-ai-shopping-readiness' ) ) . '</td></tr>';
		}
		/* translators: %s: date of the check. */
		return '<h2>' . esc_html__( 'AI visibility', 'leymish-ai-shopping-readiness' ) . '</h2><p class="muted">' . esc_html( sprintf( __( 'Asked on %s.', 'leymish-ai-shopping-readiness' ), wp_date( get_option( 'date_format' ), (int) $vis['t'] ) ) ) . '</p><table><tbody>' . $rows . '</tbody></table>';
	}
}
