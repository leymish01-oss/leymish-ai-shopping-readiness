<?php
/**
 * Turn check results into a 0–100 score and a prioritised fix list.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helper with no WordPress dependencies.
 *
 * A check is an array with: id, label, status (pass|warn|fail|info|skip), points (max), earned,
 * detail, fix and effort (1 easy – 3 hard). "info" and "skip" checks don't count toward the score.
 */
class LASR_Scoring {

	/**
	 * Score out of 100 from the scored checks. Skipped checks (couldn't be tested) are left out of
	 * the denominator instead of counting as failures.
	 *
	 * @param array $checks Check results.
	 * @return int
	 */
	public static function score( array $checks ) {
		$max    = 0;
		$earned = 0.0;
		foreach ( $checks as $c ) {
			if ( in_array( $c['status'], array( 'info', 'skip' ), true ) || empty( $c['points'] ) ) {
				continue;
			}
			$max    += (int) $c['points'];
			$earned += max( 0.0, min( (float) $c['earned'], (float) $c['points'] ) );
		}
		if ( 0 === $max ) {
			return 0;
		}
		return (int) round( 100 * $earned / $max );
	}

	/**
	 * Checks that lost points, ordered by points lost (most first), then by effort (easiest first).
	 *
	 * @param array $checks Check results.
	 * @return array
	 */
	public static function fix_list( array $checks ) {
		$fixes = array();
		foreach ( $checks as $c ) {
			if ( in_array( $c['status'], array( 'info', 'skip', 'pass' ), true ) ) {
				continue;
			}
			$lost = (float) $c['points'] - (float) $c['earned'];
			if ( $lost <= 0 ) {
				continue;
			}
			$c['lost'] = round( $lost, 1 );
			$fixes[]   = $c;
		}
		usort(
			$fixes,
			function ( $a, $b ) {
				if ( $a['lost'] !== $b['lost'] ) {
					return ( $a['lost'] < $b['lost'] ) ? 1 : -1;
				}
				return (int) $a['effort'] - (int) $b['effort'];
			}
		);
		return $fixes;
	}

	/**
	 * Status from a fraction earned.
	 *
	 * @param float $fraction 0..1.
	 * @return string
	 */
	public static function status_for( $fraction ) {
		if ( $fraction >= 0.999 ) {
			return 'pass';
		}
		return ( $fraction >= 0.5 ) ? 'warn' : 'fail';
	}

	/**
	 * Letter-style band for the headline number.
	 *
	 * @param int $score 0..100.
	 * @return string good|fair|poor
	 */
	public static function band( $score ) {
		if ( $score >= 80 ) {
			return 'good';
		}
		return ( $score >= 50 ) ? 'fair' : 'poor';
	}
}
