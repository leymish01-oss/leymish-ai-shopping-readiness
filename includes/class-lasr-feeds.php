<?php
/**
 * OpenAI (JSONL) and Google Merchant Center (TSV) product feeds at stable URLs on the store's domain:
 *   /lasr-feed/openai.jsonl   /lasr-feed/google.tsv
 * Free and local since 2.0 (Pro adds weekly validation from outside). The URLs are unchanged from Pro 1.x so feeds
 * already given to OpenAI and Google keep working. Files are cached in uploads/leymish-feeds/ (not the old
 * lasr-feeds/ folder, which the old Pro add-on deletes when it is uninstalled) and rebuilt a minute after any
 * product is saved.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed builder and server.
 */
class LASR_Feeds {

	const BUILT   = 'lasr_feeds_built';
	const ENABLED = 'lasr_feeds_enabled'; // off until the owner switches feeds on (Pro 1.x stores are switched on by the migration)

	/**
	 * Whether the owner switched the feeds on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return 'yes' === get_option( self::ENABLED, 'no' );
	}

	const FILES = array(
		'openai.jsonl' => 'openai',
		'google.tsv'   => 'google',
	);

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical_redirect' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue_rebuild' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue_rebuild' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'queue_rebuild' ) );
		add_action( 'lasr_rebuild_feeds', array( __CLASS__, 'rebuild' ) );
	}

	/**
	 * Pretty URLs for the feeds.
	 */
	public static function rewrite_rules() {
		add_rewrite_rule( '^lasr-feed/(openai\.jsonl|google\.tsv)$', 'index.php?lasr_feed=$matches[1]', 'top' );
	}

	/**
	 * Serve the file at its exact URL. With permalinks like /%postname%/, WordPress would otherwise
	 * redirect it to a trailing-slash URL, and feed fetchers and crawlers shouldn't have to follow that
	 * (core does the same for robots.txt).
	 *
	 * @param string|false $redirect Redirect target.
	 * @return string|false
	 */
	public static function no_canonical_redirect( $redirect ) {
		return get_query_var( 'lasr_feed' ) ? false : $redirect;
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'lasr_feed';
		return $vars;
	}

	/**
	 * Public URL of a feed.
	 *
	 * @param string $file openai.jsonl or google.tsv.
	 * @return string
	 */
	public static function url( $file ) {
		return get_option( 'permalink_structure' ) ? home_url( '/lasr-feed/' . $file ) : add_query_arg( 'lasr_feed', $file, home_url( '/' ) );
	}

	/**
	 * Rebuild shortly after products change (batches bulk edits into one rebuild).
	 */
	public static function queue_rebuild() {
		if ( self::enabled() && ! wp_next_scheduled( 'lasr_rebuild_feeds' ) ) {
			wp_schedule_single_event( time() + 60, 'lasr_rebuild_feeds' );
		}
	}

	/**
	 * Cache directory.
	 *
	 * @return string
	 */
	private static function dir() {
		$up = wp_upload_dir();
		return trailingslashit( $up['basedir'] ) . 'leymish-feeds/';
	}

	/**
	 * Build both feeds and write them to the cache.
	 *
	 * @return array<string,int> Rows per file.
	 */
	public static function rebuild() {
		$rows = self::rows();
		wp_mkdir_p( self::dir() );
		$counts  = array();
		$written = array();
		foreach ( self::FILES as $file => $format ) {
			$body             = 'openai' === $format ? self::to_jsonl( $rows ) : self::to_tsv( $rows );
			$written[ $file ] = false !== file_put_contents( self::dir() . $file, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- cache file in uploads.
			$counts[ $file ]  = count( $rows );
		}
		update_option(
			self::BUILT,
			array(
				'at'      => time(),
				'rows'    => count( $rows ),
				'written' => $written,
				'issues'  => self::issues( $rows ),
			),
			false
		);
		return $counts;
	}

	/**
	 * What would make a destination reject or down-rank rows: a missing required field (title, description, link,
	 * image or price), and the softer gaps (no GTIN/MPN, no brand) that keep products from being matched.
	 *
	 * @param array[] $rows Rows from rows().
	 * @return array{missing_required:int,no_identifier:int,no_brand:int}
	 */
	public static function issues( array $rows ) {
		$out = array(
			'missing_required' => 0,
			'no_identifier'    => 0,
			'no_brand'         => 0,
		);
		foreach ( $rows as $r ) {
			foreach ( array( 'title', 'description', 'url', 'image', 'price' ) as $k ) {
				if ( empty( $r[ $k ] ) ) {
					$out['missing_required']++;
					break;
				}
			}
			if ( empty( $r['gtin'] ) && empty( $r['mpn'] ) ) {
				$out['no_identifier']++;
			}
			if ( empty( $r['brand'] ) ) {
				$out['no_brand']++;
			}
		}
		return $out;
	}

	/**
	 * Serve a feed at its URL.
	 */
	public static function serve() {
		$file = get_query_var( 'lasr_feed' );
		if ( ! $file || ! isset( self::FILES[ $file ] ) ) {
			return;
		}
		if ( ! self::enabled() ) {
			status_header( 404 );
			exit;
		}
		$path = self::dir() . $file;
		if ( ! file_exists( $path ) ) {
			self::rebuild();
		}
		nocache_headers();
		header( 'Content-Type: ' . ( 'openai.jsonl' === $file ? 'application/jsonl' : 'text/tab-separated-values' ) . '; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams our own cache file.
		exit;
	}

	/**
	 * One row per purchasable item: simple products and each variation of variable products.
	 *
	 * @return array<int,array>
	 */
	public static function rows() {
		$ids    = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => -1,
				'return' => 'ids',
				'type'   => array( 'simple', 'variable', 'external' ),
			)
		);
		$seller = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$curr   = get_woocommerce_currency();
		$rows   = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || 'hidden' === $p->get_catalog_visibility() ) {
				continue;
			}
			if ( $p->is_type( 'variable' ) ) {
				foreach ( $p->get_children() as $vid ) {
					$v = wc_get_product( $vid );
					if ( $v && $v->is_purchasable() ) {
						$rows[] = self::row( $v, $p, $seller, $curr );
					}
				}
			} else {
				$rows[] = self::row( $p, null, $seller, $curr );
			}
		}
		return $rows;
	}

	/**
	 * Neutral row used by both writers.
	 *
	 * @param WC_Product      $p      Product or variation.
	 * @param WC_Product|null $parent Parent for variations.
	 * @param string          $seller Store name.
	 * @param string          $curr   Currency code.
	 * @return array
	 */
	private static function row( $p, $parent, $seller, $curr ) {
		$source  = $parent ? $parent : $p;
		$gtin    = LASR_Audit::gtin( $p );
		$gtin    = ( '' !== $gtin && LASR_GTIN::is_valid( $gtin ) ) ? LASR_GTIN::normalize( $gtin ) : '';
		$mpn     = LASR_Audit::mpn( $p );
		$img     = $p->get_image_id() ? $p->get_image_id() : $source->get_image_id();
		$desc    = $p->get_description() ? $p->get_description() : ( $source->get_description() ? $source->get_description() : $source->get_short_description() ); // the short description when there is no long one
		$regular = '' !== (string) $p->get_regular_price() ? $p->get_regular_price() : $p->get_price();
		$sale    = $p->is_on_sale() ? $p->get_sale_price() : '';
		return array(
			'id'           => '' !== $p->get_sku() ? $p->get_sku() : 'wc-' . $p->get_id(),
			'group_id'     => $parent ? ( '' !== $parent->get_sku() ? $parent->get_sku() . '-group' : 'wc-group-' . $parent->get_id() ) : '',
			'title'        => wp_specialchars_decode( $p->get_name(), ENT_QUOTES ),
			'description'  => self::clean( $desc ),
			'url'          => $p->get_permalink(),
			'image'        => $img ? (string) wp_get_attachment_image_url( $img, 'full' ) : '',
			'brand'        => LASR_Audit::brand( $p ),
			'seller'       => $seller,
			'availability' => self::availability( $p->get_stock_status() ),
			'price'        => '' !== (string) $regular ? self::money( $regular, $curr ) : '',
			'sale_price'   => ( '' !== (string) $sale && (float) $sale < (float) $regular ) ? self::money( $sale, $curr ) : '',
			'gtin'         => $gtin,
			'mpn'          => $mpn,
		);
	}

	/**
	 * Map WooCommerce stock status to the feed values.
	 *
	 * @param string $status instock|outofstock|onbackorder.
	 * @return string
	 */
	public static function availability( $status ) {
		$map = array(
			'instock'     => 'in_stock',
			'outofstock'  => 'out_of_stock',
			'onbackorder' => 'backorder',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : 'unknown';
	}

	/**
	 * "79.99 USD".
	 *
	 * @param string|float $amount Amount.
	 * @param string       $curr   ISO 4217 code.
	 * @return string
	 */
	public static function money( $amount, $curr ) {
		return number_format( (float) $amount, 2, '.', '' ) . ' ' . $curr;
	}

	/**
	 * Plain text, single-spaced, at most 5000 characters.
	 *
	 * @param string $html Description HTML.
	 * @return string
	 */
	public static function clean( $html ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 5000 ) : substr( $text, 0, 5000 );
	}

	/**
	 * OpenAI product feed: one JSON object per line.
	 *
	 * @param array $rows Neutral rows.
	 * @return string
	 */
	public static function to_jsonl( array $rows ) {
		$out = '';
		foreach ( $rows as $r ) {
			$item = array(
				'item_id'            => $r['id'],
				'title'              => $r['title'],
				'description'        => $r['description'],
				'url'                => $r['url'],
				'brand'              => $r['brand'],
				'seller_name'        => $r['seller'],
				'image_url'          => $r['image'],
				'availability'       => $r['availability'],
				'price'              => $r['price'],
				'condition'          => 'new',
				'is_eligible_search' => true,
			);
			foreach ( array(
				'sale_price' => 'sale_price',
				'gtin'       => 'gtin',
				'mpn'        => 'mpn',
				'group_id'   => 'group_id',
			) as $src => $dst ) {
				if ( '' !== $r[ $src ] ) {
					$item[ $dst ] = $r[ $src ];
				}
			}
			$out .= wp_json_encode( $item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		}
		return $out;
	}

	/**
	 * Google Merchant Center feed, tab-separated with a header row.
	 *
	 * @param array $rows Neutral rows.
	 * @return string
	 */
	public static function to_tsv( array $rows ) {
		$cols  = array( 'id', 'item_group_id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'sale_price', 'brand', 'gtin', 'mpn', 'identifier_exists', 'condition' );
		$lines = array( implode( "\t", $cols ) );
		foreach ( $rows as $r ) {
			$has_id  = '' !== $r['gtin'] || ( '' !== $r['mpn'] && '' !== $r['brand'] );
			$avail   = 'pre_order' === $r['availability'] ? 'preorder' : $r['availability'];
			$values  = array( $r['id'], $r['group_id'], $r['title'], $r['description'], $r['url'], $r['image'], 'unknown' === $avail ? 'in_stock' : $avail, $r['price'], $r['sale_price'], $r['brand'], $r['gtin'], $r['mpn'], $has_id ? 'yes' : '', 'new' ); // Blank, not "no", when data is just missing: "no" claims the product has no identifier.
			$lines[] = implode(
				"\t",
				array_map(
					function ( $v ) {
						return str_replace( array( "\t", "\r", "\n" ), ' ', (string) $v );
					},
					$values
				)
			);
		}
		return implode( "\n", $lines ) . "\n";
	}
}
