<?php
/**
 * GTIN validation (GTIN-8, UPC-A/GTIN-12, EAN-13/GTIN-13, GTIN-14) with the GS1 check digit.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helper with no WordPress dependencies, so it can be unit tested on its own.
 */
class LASR_GTIN {

	/**
	 * Strip spaces and hyphens that merchants often paste in.
	 *
	 * @param string $value Raw value.
	 * @return string Digits and anything else left over.
	 */
	public static function normalize( $value ) {
		return preg_replace( '/[\s\-]+/', '', (string) $value );
	}

	/**
	 * Whether a value is a valid GTIN: 8, 12, 13 or 14 digits with a correct check digit.
	 * Leading zeros are significant and kept.
	 *
	 * @param string $value Raw value.
	 * @return bool
	 */
	public static function is_valid( $value ) {
		$gtin = self::normalize( $value );
		if ( ! preg_match( '/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $gtin ) ) {
			return false;
		}
		if ( preg_match( '/^0+$/', $gtin ) ) {
			return false;
		}
		$digits = array_map( 'intval', str_split( $gtin ) );
		$check  = array_pop( $digits );
		$sum    = 0;
		$digits = array_reverse( $digits );
		foreach ( $digits as $i => $d ) {
			$sum += ( 0 === $i % 2 ) ? $d * 3 : $d;
		}
		return ( ( 10 - ( $sum % 10 ) ) % 10 ) === $check;
	}

	/**
	 * Describe why a value isn't a valid GTIN (for the fix list).
	 *
	 * @param string $value Raw value.
	 * @return string Empty string when valid.
	 */
	public static function problem( $value ) {
		$gtin = self::normalize( $value );
		if ( '' === $gtin ) {
			return 'missing';
		}
		if ( ! ctype_digit( $gtin ) ) {
			return 'not_digits';
		}
		if ( ! in_array( strlen( $gtin ), array( 8, 12, 13, 14 ), true ) ) {
			return 'wrong_length';
		}
		return self::is_valid( $gtin ) ? '' : 'bad_check_digit';
	}
}
