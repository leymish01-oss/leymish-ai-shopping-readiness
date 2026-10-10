<?php
/**
 * AI checkout readiness, generated on this site (2.0, free): a UCP business profile at /.well-known/ucp and richer
 * Product schema (brand, GTIN, MPN, return policy, shipping) built only from the store's real settings.
 *
 * Honesty rules: the UCP profile declares no services unless a checkout integration adds one through the
 * `lasr_ucp_profile` filter (WooCommerce core has no UCP checkout, so we never claim one). The return policy is
 * only what the owner states in the Feeds tab. Shipping rates are only WooCommerce zones with a plain flat rate or
 * free shipping; anything with a formula is left out rather than guessed.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profile server, schema filter and settings.
 */
class LASR_Ucp {

	const OPTION      = 'lasr_checkout';
	const UCP_VERSION = '2026-08-25'; // ucp.dev specification the profile follows (read 2026-10-08)

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical_redirect' ) );
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'schema' ), 20, 2 );
	}

	/**
	 * Settings with defaults (everything off until the owner says).
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'ucp'          => 'no',
				'identifiers'  => 'no',
				'returns'      => 'no',
				'return_days'  => 30,
				'return_fees'  => 'free',
				'return_how'   => 'mail',
				'shipping'     => 'no',
				'handling_max' => '',
				'transit_max'  => '',
			)
		);
	}

	/**
	 * Save settings from the Feeds tab form (already nonce- and capability-checked by the caller).
	 *
	 * @param array $in Raw POST.
	 */
	public static function save( array $in ) {
		$days = isset( $in['return_days'] ) ? absint( $in['return_days'] ) : 30;
		update_option(
			self::OPTION,
			array(
				'ucp'          => empty( $in['ucp'] ) ? 'no' : 'yes',
				'identifiers'  => empty( $in['identifiers'] ) ? 'no' : 'yes',
				'returns'      => empty( $in['returns'] ) ? 'no' : 'yes',
				'return_days'  => max( 0, min( 365, $days ) ),
				'return_fees'  => isset( $in['return_fees'] ) && 'customer' === $in['return_fees'] ? 'customer' : 'free',
				'return_how'   => isset( $in['return_how'] ) && 'store' === $in['return_how'] ? 'store' : 'mail',
				'shipping'     => empty( $in['shipping'] ) ? 'no' : 'yes',
				'handling_max' => isset( $in['handling_max'] ) && '' !== $in['handling_max'] ? (string) min( 30, absint( $in['handling_max'] ) ) : '',
				'transit_max'  => isset( $in['transit_max'] ) && '' !== $in['transit_max'] ? (string) min( 60, absint( $in['transit_max'] ) ) : '',
			),
			false
		);
		flush_rewrite_rules();
	}

	/**
	 * Whether the profile is served.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return 'yes' === self::settings()['ucp'];
	}

	/**
	 * Route /.well-known/ucp to WordPress (a real file on the server still wins).
	 */
	public static function rewrite_rules() {
		add_rewrite_rule( '^\.well-known/ucp/?$', 'index.php?lasr_ucp=1', 'top' );
	}

	/**
	 * Query var.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'lasr_ucp';
		return $vars;
	}

	/**
	 * Serve the exact URL without a trailing-slash redirect.
	 *
	 * @param string|false $redirect Redirect.
	 * @return string|false
	 */
	public static function no_canonical_redirect( $redirect ) {
		return get_query_var( 'lasr_ucp' ) ? false : $redirect;
	}

	/**
	 * The profile (pure; filterable so a real UCP checkout integration can add its services and handlers).
	 *
	 * @return array
	 */
	public static function profile() {
		$profile = array(
			'ucp' => array(
				'version'          => self::UCP_VERSION,
				'services'         => new stdClass(),
				'payment_handlers' => new stdClass(),
			),
		);
		/**
		 * Lets a checkout integration that implements UCP declare its services, capabilities and payment handlers.
		 *
		 * @param array $profile The business profile.
		 */
		return apply_filters( 'lasr_ucp_profile', $profile );
	}

	/**
	 * Send the profile.
	 */
	public static function serve() {
		if ( ! get_query_var( 'lasr_ucp' ) ) {
			return;
		}
		if ( ! self::enabled() ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( self::profile(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document.
		exit;
	}

	/**
	 * Which product-schema parts the theme or another plugin already outputs (2.1).
	 *
	 * @return array{returns:bool,shipping:bool}
	 */
	public static function provided() {
		$p = get_option( 'lasr_schema_provided' );
		return array(
			'returns'  => is_array( $p ) && ! empty( $p['returns'] ),
			'shipping' => is_array( $p ) && ! empty( $p['shipping'] ),
		);
	}

	/**
	 * How many times a page's JSON-LD states a return policy and shipping details (pure).
	 *
	 * @param string $html Page HTML.
	 * @return array{returns:int,shipping:int}
	 */
	public static function count_props( $html ) {
		$n = array( 'returns' => 0, 'shipping' => 0 );
		if ( preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', (string) $html, $m ) ) {
			foreach ( $m[1] as $block ) {
				$n['returns']  += substr_count( $block, '"hasMerchantReturnPolicy"' );
				$n['shipping'] += substr_count( $block, '"shippingDetails"' );
			}
		}
		return $n;
	}

	/**
	 * Decide from the audit's product pages whether someone else already provides a part (pure core in decide()).
	 *
	 * @param array $pages URL => response (body under 'body').
	 */
	public static function detect( array $pages ) {
		$max = array( 'returns' => 0, 'shipping' => 0 );
		foreach ( $pages as $res ) {
			$c = self::count_props( is_array( $res ) && isset( $res['body'] ) ? $res['body'] : '' );
			foreach ( $max as $k => $v ) {
				$max[ $k ] = max( $v, $c[ $k ] );
			}
		}
		if ( ! $pages ) {
			return;
		}
		update_option( 'lasr_schema_provided', self::decide( $max, self::settings(), self::provided() ) + array( 't' => time() ), false );
	}

	/**
	 * Others provide a part when the page states it more often than we add it (pure).
	 *
	 * @param array $counts   Max counts per page (returns, shipping).
	 * @param array $settings Our settings.
	 * @param array $before   What we decided last time (when we already stepped aside, every copy is theirs).
	 * @return array{returns:bool,shipping:bool}
	 */
	public static function decide( array $counts, array $settings, array $before ) {
		$out = array();
		foreach ( array( 'returns', 'shipping' ) as $k ) {
			$ours      = 'yes' === ( isset( $settings[ $k ] ) ? $settings[ $k ] : 'no' ) && empty( $before[ $k ] ) ? 1 : 0;
			$out[ $k ] = ( (int) $counts[ $k ] - $ours ) > 0;
		}
		return $out;
	}

	/**
	 * Add what's true and missing to WooCommerce's Product JSON-LD.
	 *
	 * @param array      $markup  Product markup.
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function schema( $markup, $product ) {
		if ( ! is_array( $markup ) || ! $product ) {
			return $markup;
		}
		$s = self::settings();
		if ( 'yes' === $s['identifiers'] && class_exists( 'LASR_Audit' ) ) {
			$markup = self::add_identifiers( $markup, LASR_Audit::brand( $product ), LASR_Audit::gtin( $product ), LASR_Audit::mpn( $product ) );
		}
		$extra    = array();
		$provided = self::provided(); // 2.1: never a second copy of what the theme already says
		if ( 'yes' === $s['returns'] && empty( $provided['returns'] ) ) {
			$extra['hasMerchantReturnPolicy'] = self::return_policy( $s, self::base_country() );
		}
		if ( 'yes' === $s['shipping'] && empty( $provided['shipping'] ) ) {
			$ship = self::shipping_details( self::zone_rates(), $s, get_woocommerce_currency() );
			if ( $ship ) {
				$extra['shippingDetails'] = $ship;
			}
		}
		if ( $extra && isset( $markup['offers'] ) && is_array( $markup['offers'] ) ) {
			if ( isset( $markup['offers']['@type'] ) ) {
				$markup['offers'] += $extra;
			} else {
				foreach ( $markup['offers'] as $i => $offer ) {
					if ( is_array( $offer ) ) {
						$markup['offers'][ $i ] = $offer + $extra;
					}
				}
			}
		}
		return $markup;
	}

	/**
	 * Brand, GTIN and MPN when the product has them and the markup doesn't (pure).
	 *
	 * @param array  $markup Markup.
	 * @param string $brand  Brand.
	 * @param string $gtin   GTIN (only a valid one is added).
	 * @param string $mpn    MPN.
	 * @return array
	 */
	public static function add_identifiers( array $markup, $brand, $gtin, $mpn ) {
		if ( '' !== (string) $brand && empty( $markup['brand'] ) ) {
			$markup['brand'] = array(
				'@type' => 'Brand',
				'name'  => (string) $brand,
			);
		}
		$gtin = LASR_GTIN::normalize( (string) $gtin );
		$has  = false;
		foreach ( array( 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14' ) as $k ) {
			$has = $has || ! empty( $markup[ $k ] );
		}
		if ( '' !== $gtin && LASR_GTIN::is_valid( $gtin ) && ! $has ) {
			$markup['gtin'] = $gtin;
		}
		if ( '' !== (string) $mpn && empty( $markup['mpn'] ) ) {
			$markup['mpn'] = (string) $mpn;
		}
		return $markup;
	}

	/**
	 * MerchantReturnPolicy from the owner's own answers (pure).
	 *
	 * @param array  $s       Settings.
	 * @param string $country ISO country.
	 * @return array
	 */
	public static function return_policy( array $s, $country ) {
		$days = (int) $s['return_days'];
		$p    = array(
			'@type'                => 'MerchantReturnPolicy',
			'applicableCountry'    => $country,
			'returnPolicyCategory' => $days > 0 ? 'https://schema.org/MerchantReturnFiniteReturnWindow' : 'https://schema.org/MerchantReturnNotPermitted',
		);
		if ( $days > 0 ) {
			$p['merchantReturnDays'] = $days;
			$p['returnMethod']       = 'store' === $s['return_how'] ? 'https://schema.org/ReturnInStore' : 'https://schema.org/ReturnByMail';
			$p['returnFees']         = 'customer' === $s['return_fees'] ? 'https://schema.org/ReturnFeesCustomerResponsibility' : 'https://schema.org/FreeReturn';
		}
		return $p;
	}

	/**
	 * OfferShippingDetails, one per country with a plain rate (pure). At most 10.
	 *
	 * @param array  $rates    country => amount (float).
	 * @param array  $s        Settings (handling_max, transit_max in days).
	 * @param string $currency Currency.
	 * @return array[]
	 */
	public static function shipping_details( array $rates, array $s, $currency ) {
		$out = array();
		foreach ( array_slice( $rates, 0, 10, true ) as $country => $amount ) {
			$d = array(
				'@type'               => 'OfferShippingDetails',
				'shippingRate'        => array(
					'@type'    => 'MonetaryAmount',
					'value'    => round( (float) $amount, 2 ),
					'currency' => $currency,
				),
				'shippingDestination' => array(
					'@type'          => 'DefinedRegion',
					'addressCountry' => (string) $country,
				),
			);
			if ( '' !== (string) $s['handling_max'] && '' !== (string) $s['transit_max'] ) {
				$d['deliveryTime'] = array(
					'@type'        => 'ShippingDeliveryTime',
					'handlingTime' => array(
						'@type'    => 'QuantitativeValue',
						'minValue' => 0,
						'maxValue' => (int) $s['handling_max'],
						'unitCode' => 'DAY',
					),
					'transitTime'  => array(
						'@type'    => 'QuantitativeValue',
						'minValue' => 0,
						'maxValue' => (int) $s['transit_max'],
						'unitCode' => 'DAY',
					),
				);
			}
			$out[] = $d;
		}
		return $out;
	}

	/**
	 * Cheapest plain rate per country from WooCommerce shipping zones: flat rates with a numeric cost, and free
	 * shipping without a minimum. Zones by postcode or state, and costs with formulas, are left out.
	 *
	 * @return array country => amount
	 */
	public static function zone_rates() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}
		$rates = array();
		foreach ( WC_Shipping_Zones::get_zones() as $z ) {
			$zone      = new WC_Shipping_Zone( $z['id'] );
			$countries = array();
			foreach ( $zone->get_zone_locations() as $loc ) {
				if ( 'country' === $loc->type ) {
					$countries[] = $loc->code;
				}
			}
			if ( ! $countries ) {
				continue;
			}
			$best = null;
			foreach ( $zone->get_shipping_methods( true ) as $m ) {
				if ( 'free_shipping' === $m->id && '' === (string) $m->get_option( 'requires', '' ) ) {
					$best = 0.0;
				} elseif ( 'flat_rate' === $m->id && is_numeric( $m->get_option( 'cost', '' ) ) ) {
					$cost = (float) $m->get_option( 'cost' );
					$best = null === $best ? $cost : min( $best, $cost );
				}
			}
			if ( null !== $best ) {
				foreach ( $countries as $c ) {
					$rates[ $c ] = isset( $rates[ $c ] ) ? min( $rates[ $c ], $best ) : $best;
				}
			}
		}
		return $rates;
	}

	/**
	 * Store country.
	 *
	 * @return string
	 */
	public static function base_country() {
		return function_exists( 'WC' ) && WC()->countries ? (string) WC()->countries->get_base_country() : '';
	}
}
