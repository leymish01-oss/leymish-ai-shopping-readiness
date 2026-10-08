<?php
/**
 * AI fixes: pure logic (no WordPress calls), so it can be unit-tested.
 * Builds the request for the LeyMish AI proxy and cleans what comes back. Never produces a GTIN.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request building and response cleaning for AI fixes.
 */
class LASR_AI_Logic {

	const KINDS     = array( 'attributes', 'description', 'category', 'alt_text' );
	const MAX_TEXT  = 2000;
	const ID_NAMES  = '/gtin|ean|upc|mpn|isbn|barcode|jan\b/i';
	const ID_VALUES = '/\b\d{8,14}\b/';

	/**
	 * The JSON body for POST /v1/fix. Only the product fields listed here leave the store.
	 *
	 * @param string $kind     One of KINDS.
	 * @param array  $product  name, description, short_description, attributes (name => value), categories, image_url.
	 * @param string $site     Store home URL.
	 * @param string $key      LeyMish Pro licence key, or '' for the free fixes.
	 * @param string $version  Plugin version.
	 * @return array
	 */
	public static function payload( $kind, $product, $site, $key, $version ) {
		$clip  = function ( $s ) {
			return mb_substr( trim( wp_strip_all_tags( (string) $s ) ), 0, self::MAX_TEXT );
		};
		$attrs = array();
		foreach ( (array) ( isset( $product['attributes'] ) ? $product['attributes'] : array() ) as $name => $value ) {
			$attrs[ $clip( $name ) ] = $clip( $value );
		}
		$body = array(
			'site'           => (string) $site,
			'kind'           => in_array( $kind, self::KINDS, true ) ? $kind : 'attributes',
			'plugin_version' => (string) $version,
			'product'        => array(
				'title'             => $clip( isset( $product['name'] ) ? $product['name'] : '' ),
				'description'       => $clip( isset( $product['description'] ) ? $product['description'] : '' ),
				'short_description' => $clip( isset( $product['short_description'] ) ? $product['short_description'] : '' ),
				'attributes'        => $attrs,
				'categories'        => array_map( $clip, (array) ( isset( $product['categories'] ) ? $product['categories'] : array() ) ),
			),
		);
		if ( 'alt_text' === $kind && ! empty( $product['image_url'] ) && 0 === strpos( (string) $product['image_url'], 'https://' ) ) {
			$body['product']['image_url'] = (string) $product['image_url'];
		}
		if ( '' !== (string) $key ) {
			$body['license_key'] = (string) $key;
		}
		return $body;
	}

	/**
	 * Clean a suggestion before it's shown or saved. Unknown kinds or shapes give an empty array.
	 *
	 * @param string $kind       One of KINDS.
	 * @param mixed  $suggestion Decoded JSON from the proxy (or from the review form).
	 * @return array
	 */
	public static function clean( $kind, $suggestion ) {
		if ( ! is_array( $suggestion ) ) {
			return array();
		}
		$text = function ( $s, $max ) {
			return mb_substr( trim( wp_strip_all_tags( (string) $s ) ), 0, $max );
		};
		switch ( $kind ) {
			case 'attributes':
				$out = array();
				foreach ( (array) ( isset( $suggestion['attributes'] ) ? $suggestion['attributes'] : array() ) as $a ) {
					if ( ! is_array( $a ) || empty( $a['name'] ) || ! isset( $a['value'] ) || '' === trim( (string) $a['value'] ) ) {
						continue;
					}
					$name  = $text( $a['name'], 60 );
					$value = $text( $a['value'], 200 );
					// We never write identifiers from a model: no GTIN/EAN/UPC/MPN/ISBN names, no 8–14 digit numbers.
					if ( preg_match( self::ID_NAMES, $name ) || preg_match( self::ID_VALUES, $value ) ) {
						continue;
					}
					$out[] = array(
						'name'     => $name,
						'value'    => $value,
						'evidence' => $text( isset( $a['evidence'] ) ? $a['evidence'] : '', 200 ),
					);
				}
				return array( 'attributes' => array_slice( $out, 0, 12 ) );
			case 'description':
				return array(
					'description'       => mb_substr( trim( (string) ( isset( $suggestion['description'] ) ? $suggestion['description'] : '' ) ), 0, 6000 ),
					'short_description' => $text( isset( $suggestion['short_description'] ) ? $suggestion['short_description'] : '', 600 ),
				);
			case 'category':
				$out = array();
				foreach ( (array) ( isset( $suggestion['categories'] ) ? $suggestion['categories'] : array() ) as $c ) {
					if ( is_array( $c ) && ! empty( $c['path'] ) ) {
						$out[] = array(
							'path'   => $text( $c['path'], 250 ),
							'reason' => $text( isset( $c['reason'] ) ? $c['reason'] : '', 200 ),
						);
					}
				}
				return array( 'categories' => array_slice( $out, 0, 3 ) );
			case 'alt_text':
				return array( 'alt_text' => $text( isset( $suggestion['alt_text'] ) ? $suggestion['alt_text'] : '', 125 ) );
		}
		return array();
	}

	/**
	 * Upgrade note when the free fixes run out (buyer-psychology skill: specific, honest, no pressure).
	 *
	 * @param int $used    Fixes used.
	 * @param int $limit   Fixes allowed.
	 * @param int $gaps    Products that still have gaps.
	 * @return string Empty when fixes remain.
	 */
	public static function upgrade_note( $used, $limit, $gaps ) {
		if ( $used < $limit ) {
			return '';
		}
		/* translators: 1: fixes used, 2: fixes allowed, 3: products with gaps. */
		return sprintf( __( 'You have used %1$d of %2$d free AI fixes, and %3$d products still have gaps. LeyMish Pro gives you 500 fixes a month, a weekly AI visibility check, monitoring and the Store Team agents for $12 a month or $99 a year. Cancel any time; everything that runs on your site stays free.', 'leymish-ai-shopping-readiness' ), (int) $used, (int) $limit, (int) $gaps );
	}
}
