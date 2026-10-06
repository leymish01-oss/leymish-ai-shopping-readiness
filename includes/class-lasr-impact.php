<?php
/**
 * Impact: what changed since the plugin was installed. Weekly score snapshots and a baseline, stored in this site's
 * options only. No external requests.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Snapshot recording (on every audit) and the pure maths behind the Impact tab.
 */
class LASR_Impact {

	const HISTORY  = 'lasr_history';
	const BASELINE = 'lasr_baseline';
	const MAX      = 104; // two years of weekly snapshots

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'lasr_audit_completed', array( __CLASS__, 'record' ) );
	}

	/**
	 * Save this audit as the week's snapshot; the first one ever becomes the baseline.
	 *
	 * @param array $result Audit result.
	 */
	public static function record( $result ) {
		$snap = self::snapshot( $result );
		if ( ! get_option( self::BASELINE ) ) {
			update_option( self::BASELINE, $snap, false );
		}
		update_option( self::HISTORY, self::merge( self::history(), $snap ), false );
	}

	/**
	 * Saved weekly snapshots, oldest first. Sites upgrading from 1.0.x start from their last audit.
	 *
	 * @return array[]
	 */
	public static function history() {
		$h = get_option( self::HISTORY );
		if ( is_array( $h ) ) {
			return $h;
		}
		$last = class_exists( 'LASR_Audit' ) ? LASR_Audit::last() : null;
		if ( ! $last ) {
			return array();
		}
		$snap = self::snapshot( $last );
		update_option( self::HISTORY, array( $snap ), false );
		if ( ! get_option( self::BASELINE ) ) {
			update_option( self::BASELINE, $snap, false );
		}
		return array( $snap );
	}

	/**
	 * The install baseline, or null.
	 *
	 * @return array|null
	 */
	public static function baseline() {
		$b = get_option( self::BASELINE );
		return is_array( $b ) ? $b : null;
	}

	/**
	 * A compact snapshot of one audit.
	 *
	 * @param array $result Audit result.
	 * @return array{t:int,week:string,s:int,pass:string[],open:string[],n:int,identifier:int,brand:int,alt:int|null}
	 */
	public static function snapshot( array $result ) {
		$pass = array();
		$open = array();
		foreach ( (array) $result['checks'] as $c ) {
			if ( 'pass' === $c['status'] ) {
				$pass[] = $c['id'];
			} elseif ( in_array( $c['status'], array( 'fail', 'warn' ), true ) ) {
				$open[] = $c['id'];
			}
		}
		$summary = isset( $result['summary'] ) ? (array) $result['summary'] : array();
		$n       = isset( $summary['checked'] ) ? (int) $summary['checked'] : count( (array) $result['products'] );
		$missing = isset( $summary['missing'] ) ? (array) $summary['missing'] : array();
		$t       = (int) $result['ran_at'];
		return array(
			't'          => $t,
			'week'       => gmdate( 'o-\WW', $t ),
			's'          => (int) $result['score'],
			'pass'       => $pass,
			'open'       => $open,
			'n'          => $n,
			'identifier' => max( 0, $n - (int) ( isset( $missing['identifier'] ) ? $missing['identifier'] : 0 ) ),
			'brand'      => max( 0, $n - (int) ( isset( $missing['brand'] ) ? $missing['brand'] : 0 ) ),
			'alt'        => isset( $summary['with_alt'] ) ? (int) $summary['with_alt'] : null,
		);
	}

	/**
	 * One snapshot per ISO week: a later audit in the same week replaces that week's snapshot.
	 *
	 * @param array[] $history Snapshots, oldest first.
	 * @param array   $snap    New snapshot.
	 * @return array[]
	 */
	public static function merge( array $history, array $snap ) {
		$last = end( $history );
		if ( $last && $last['week'] === $snap['week'] ) {
			array_pop( $history );
		}
		$history[] = $snap;
		return array_slice( $history, -self::MAX );
	}

	/**
	 * Checks fixed and newly broken since the baseline.
	 *
	 * @param array $baseline Baseline snapshot.
	 * @param array $current  Latest snapshot.
	 * @return array{fixed:string[],broken:string[]}
	 */
	public static function changes( array $baseline, array $current ) {
		return array(
			'fixed'  => array_values( array_intersect( $baseline['open'], $current['pass'] ) ),
			'broken' => array_values( array_intersect( $baseline['pass'], $current['open'] ) ),
		);
	}

	/**
	 * Product-data rows: at install vs now, as counts and percentages.
	 *
	 * @param array $baseline Baseline snapshot.
	 * @param array $current  Latest snapshot.
	 * @return array[] Each: key, then (count|null), now (count|null), then_pct, now_pct.
	 */
	public static function product_rows( array $baseline, array $current ) {
		$rows = array();
		foreach ( array( 'identifier', 'brand', 'alt' ) as $k ) {
			$then   = isset( $baseline[ $k ] ) ? $baseline[ $k ] : null;
			$now    = isset( $current[ $k ] ) ? $current[ $k ] : null;
			$rows[] = array(
				'key'      => $k,
				'then'     => $then,
				'now'      => $now,
				'then_pct' => null === $then || ! $baseline['n'] ? null : (int) round( 100 * $then / $baseline['n'] ),
				'now_pct'  => null === $now || ! $current['n'] ? null : (int) round( 100 * $now / $current['n'] ),
			);
		}
		return $rows;
	}

	/**
	 * Points for an SVG line chart of the score (0–100) across the snapshots.
	 *
	 * @param array[] $history Snapshots, oldest first.
	 * @param int     $width   Plot width.
	 * @param int     $height  Plot height.
	 * @return array<int,array{0:float,1:float}>
	 */
	public static function points( array $history, $width = 600, $height = 160 ) {
		$n   = count( $history );
		$out = array();
		foreach ( array_values( $history ) as $i => $h ) {
			$x     = 1 === $n ? $width / 2 : $i * $width / ( $n - 1 );
			$y     = $height - ( max( 0, min( 100, (int) $h['s'] ) ) / 100 ) * $height;
			$out[] = array( round( $x, 1 ), round( $y, 1 ) );
		}
		return $out;
	}
}
