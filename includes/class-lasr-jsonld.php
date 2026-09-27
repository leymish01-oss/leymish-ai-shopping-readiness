<?php
/**
 * Extract and check schema.org Product JSON-LD from a product page's HTML.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helper with no WordPress dependencies.
 */
class LASR_JSONLD {

	/**
	 * Whether a response body starts with a UTF-8 byte-order mark. JSON must not have one (RFC 8259
	 * section 8.1), and strict parsers used by AI agents reject it. A BOM before every response usually
	 * means a PHP file in the theme or a plugin was saved as "UTF-8 with BOM".
	 *
	 * @param string $body Response body.
	 * @return bool
	 */
	public static function has_bom( $body ) {
		return 0 === strncmp( (string) $body, "\xEF\xBB\xBF", 3 );
	}

	/**
	 * Find every Product node in the page's JSON-LD blocks (including inside @graph).
	 *
	 * @param string $html Page HTML.
	 * @return array<int,array> Product nodes.
	 */
	public static function products( $html ) {
		$found = array();
		if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', (string) $html, $m ) ) {
			return $found;
		}
		foreach ( $m[1] as $raw ) {
			$data = json_decode( html_entity_decode( trim( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			self::collect( $data, $found );
		}
		return $found;
	}

	/**
	 * Walk a decoded JSON-LD value and collect Product nodes.
	 *
	 * @param mixed $node  Decoded value.
	 * @param array $found Collected products (by reference).
	 */
	private static function collect( $node, array &$found ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		$type = isset( $node['@type'] ) ? (array) $node['@type'] : array();
		if ( in_array( 'Product', $type, true ) || in_array( 'ProductGroup', $type, true ) ) {
			$found[] = $node;
		}
		foreach ( $node as $key => $child ) {
			if ( is_array( $child ) && '@context' !== $key ) {
				self::collect( $child, $found );
			}
		}
	}

	/**
	 * Check one Product node against what AI shopping agents and Google need.
	 *
	 * @param array $product Product node.
	 * @return array{missing_required:string[],missing_recommended:string[]}
	 */
	public static function check( array $product ) {
		$required    = array();
		$recommended = array();
		foreach ( array( 'name', 'image', 'description' ) as $field ) {
			if ( empty( $product[ $field ] ) ) {
				$required[] = $field;
			}
		}
		$offer = self::first_offer( $product );
		if ( ! $offer ) {
			$required[] = 'offers';
		} else {
			$has_price = isset( $offer['price'] ) && '' !== (string) $offer['price'];
			if ( ! $has_price && isset( $offer['lowPrice'] ) ) {
				$has_price = true; // AggregateOffer.
			}
			if ( ! $has_price && ! empty( $offer['priceSpecification'] ) ) {
				$has_price = true;
			}
			if ( ! $has_price ) {
				$required[] = 'offers.price';
			}
			if ( empty( $offer['priceCurrency'] ) && empty( $offer['priceSpecification'] ) ) {
				$required[] = 'offers.priceCurrency';
			}
			if ( empty( $offer['availability'] ) ) {
				$required[] = 'offers.availability';
			}
		}
		$has_id = false;
		foreach ( array( 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14', 'mpn', 'isbn' ) as $id ) {
			if ( ! empty( $product[ $id ] ) ) {
				$has_id = true;
			}
		}
		if ( ! $has_id ) {
			$recommended[] = 'gtin_or_mpn';
		}
		if ( empty( $product['brand'] ) ) {
			$recommended[] = 'brand';
		}
		if ( empty( $product['sku'] ) ) {
			$recommended[] = 'sku';
		}
		return array(
			'missing_required'    => $required,
			'missing_recommended' => $recommended,
		);
	}

	/**
	 * First offer, whether "offers" is an object or a list.
	 *
	 * @param array $product Product node.
	 * @return array|null
	 */
	private static function first_offer( array $product ) {
		if ( empty( $product['offers'] ) || ! is_array( $product['offers'] ) ) {
			return null;
		}
		$offers = $product['offers'];
		if ( isset( $offers[0] ) && is_array( $offers[0] ) ) {
			return $offers[0];
		}
		return $offers;
	}
}
