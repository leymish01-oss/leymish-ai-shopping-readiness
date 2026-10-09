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
			if ( in_array( $c['status'], array( 'info', 'skip', 'pass' ), true ) || self::is_watch( $c ) ) {
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
	 * A check whose remaining points need something no WooCommerce store can switch on yet (2.0.1): today, the
	 * checkout half of UCP when the profile itself is in place. Never a "win" or "what's wrong"; it is shown as
	 * "next to watch". Audits saved by 2.0.0 have no flag, so a half-earned UCP check counts too.
	 *
	 * @param array $c Check.
	 * @return bool
	 */
	public static function is_watch( array $c ) {
		if ( ! empty( $c['watch'] ) ) {
			return true;
		}
		return isset( $c['id'] ) && 'ucp' === $c['id'] && 'warn' === $c['status'] && (float) $c['earned'] > 0;
	}

	/**
	 * Checks to watch (see is_watch()).
	 *
	 * @param array $checks Check results.
	 * @return array
	 */
	public static function watch_list( array $checks ) {
		return array_values( array_filter( $checks, array( __CLASS__, 'is_watch' ) ) );
	}

	/**
	 * The plain name of something to watch.
	 *
	 * @param array $c Check.
	 * @return string
	 */
	public static function watch_label( array $c ) {
		if ( isset( $c['id'] ) && 'ucp' === $c['id'] ) {
			return function_exists( '__' ) ? __( 'UCP checkout (not available for WooCommerce yet)', 'leymish-ai-shopping-readiness' ) : 'UCP checkout (not available for WooCommerce yet)';
		}
		return (string) $c['label'];
	}

	/**
	 * Whether the optional Pro add-on has a tool for this check: the bulk GTIN/brand/MPN editor (product data and
	 * the identifiers in structured data) and the llms.txt generator. Nothing else, so the link only appears
	 * where Pro really helps.
	 *
	 * @param string $id Check id.
	 * @return bool
	 */
	public static function pro_helps( $id ) {
		return in_array( $id, array( 'catalog', 'jsonld', 'llms_txt' ), true );
	}

	/**
	 * Plain-text summary a store owner can copy and share. Nothing is sent anywhere.
	 *
	 * @param int   $score  Score out of 100.
	 * @param array $checks Check results.
	 * @return string
	 */
	public static function share_text( $score, array $checks ) {
		$top = array_slice( self::fix_list( $checks ), 0, 2 );
		$gap = array();
		foreach ( $top as $f ) {
			$gap[] = $f['label'];
		}
		$text = sprintf( 'Our WooCommerce store scored %d/100 for AI shopping readiness', (int) $score );
		$text .= $gap ? '. Biggest gaps: ' . implode( '; ', $gap ) . '.' : ', with nothing left to fix.';
		return $text . ' Free check: https://www.leymish.com/woocommerce/';
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
