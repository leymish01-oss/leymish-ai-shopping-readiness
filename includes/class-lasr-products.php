<?php
/**
 * The Products tab (2.0, free: it all runs on this site). Explains, then fixes:
 *
 *  - published products by default (the audit scores published products only); drafts and private behind a filter;
 *  - a status pill and "what's missing" chips per row, and an inline editor for GTIN, brand and MPN;
 *  - one-click fixes, each with a preview and an undo: "Set brand for all", "I make these products (private label):
 *    use each SKU as the MPN" (explicit tick), and GTINs from a supplier CSV (check digits validated). A GTIN is never
 *    invented: it only ever comes from the owner or their supplier;
 *  - "See it the way AI sees it": what an AI shopping agent can read for one product, from its JSON-LD and the
 *    Store API fields, missing in red and present in green.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Products tab.
 */
class LASR_Products {

	const PER_PAGE = 50;
	const UNDO     = 'lasr_undo';
	const FIXED    = 'lasr_just_fixed_';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_lasr_bulk_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_lasr_fix_preview', array( __CLASS__, 'handle_preview' ) );
		add_action( 'admin_post_lasr_fix_apply', array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_lasr_fix_undo', array( __CLASS__, 'handle_undo' ) );
	}

	/* ------------------------------------------------------------------ pure helpers (unit-tested) */

	/**
	 * What a product or variation is missing for AI matching: identifier (valid GTIN or an MPN), brand (not for
	 * variations: brand belongs to the parent), alt (main image without alt text).
	 *
	 * @param string $gtin   GTIN.
	 * @param string $mpn    MPN.
	 * @param string $brand  Brand.
	 * @param bool   $is_var Variation.
	 * @param string $alt    Main image alt text, or null when there is no image.
	 * @return string[] Missing keys.
	 */
	public static function missing( $gtin, $mpn, $brand, $is_var, $alt = '' ) {
		$out  = array();
		$gtin = LASR_GTIN::normalize( (string) $gtin );
		if ( ! ( ( '' !== $gtin && LASR_GTIN::is_valid( $gtin ) ) || '' !== trim( (string) $mpn ) ) ) {
			$out[] = 'identifier';
		}
		if ( ! $is_var && '' === trim( (string) $brand ) ) {
			$out[] = 'brand';
		}
		if ( null !== $alt && '' === trim( (string) $alt ) ) {
			$out[] = 'alt';
		}
		return $out;
	}

	/**
	 * The status pill for a row: complete, one gap, or more.
	 *
	 * @param string[] $missing Missing keys (alt text counts as a soft gap).
	 * @return string complete|partial|missing
	 */
	public static function pill( array $missing ) {
		$hard = array_diff( $missing, array( 'alt' ) );
		if ( ! $hard ) {
			return $missing ? 'partial' : 'complete';
		}
		return count( $hard ) > 1 ? 'missing' : 'partial';
	}

	/**
	 * Parse a supplier CSV (header row with a SKU or ID column and a GTIN/EAN/UPC/barcode column) into rows.
	 * Invalid GTINs are kept with an error so the preview can show them; they are never saved.
	 *
	 * @param string $csv CSV text.
	 * @return array{rows:array[],error:string}
	 */
	public static function parse_gtin_csv( $csv ) {
		$lines = preg_split( '/\r\n|\r|\n/', trim( (string) $csv ) );
		if ( ! $lines || count( $lines ) < 2 ) {
			return array(
				'rows'  => array(),
				'error' => __( 'The file needs a header row and at least one product row.', 'leymish-ai-shopping-readiness' ),
			);
		}
		$sep    = substr_count( $lines[0], ';' ) > substr_count( $lines[0], ',' ) ? ';' : ( false !== strpos( $lines[0], "\t" ) ? "\t" : ',' );
		$header = array_map( 'strtolower', array_map( 'trim', str_getcsv( $lines[0], $sep ) ) );
		$key    = null;
		$code   = null;
		foreach ( $header as $i => $h ) {
			$h = trim( $h, " \t\"'\xEF\xBB\xBF" );
			if ( null === $key && in_array( $h, array( 'sku', 'id', 'product id', 'product_id' ), true ) ) {
				$key = $i;
			}
			if ( null === $code && in_array( $h, array( 'gtin', 'ean', 'upc', 'barcode', 'gtin13', 'gtin12', 'ean13', 'isbn' ), true ) ) {
				$code = $i;
			}
		}
		if ( null === $key || null === $code ) {
			return array(
				'rows'  => array(),
				'error' => __( 'We need a "SKU" (or "ID") column and a "GTIN" (or EAN, UPC, barcode) column in the first row.', 'leymish-ai-shopping-readiness' ),
			);
		}
		$rows = array();
		foreach ( array_slice( $lines, 1, 5000 ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells  = str_getcsv( $line, $sep );
			$ref    = isset( $cells[ $key ] ) ? trim( $cells[ $key ] ) : '';
			$gtin   = LASR_GTIN::normalize( isset( $cells[ $code ] ) ? (string) $cells[ $code ] : '' );
			$rows[] = array(
				'ref'   => $ref,
				'by'    => 'id' === trim( $header[ $key ] ) || 'product id' === trim( $header[ $key ] ) || 'product_id' === trim( $header[ $key ] ) ? 'id' : 'sku',
				'gtin'  => $gtin,
				'valid' => '' !== $gtin && LASR_GTIN::is_valid( $gtin ),
			);
		}
		return array(
			'rows'  => $rows,
			'error' => '',
		);
	}

	/**
	 * Rows for the AI view card: each field with its value in the product's JSON-LD and in the Store API (pure).
	 *
	 * @param array $ld  Product JSON-LD (as WooCommerce outputs it, after our schema filter).
	 * @param array $api Store API-like fields (name, price, in_stock, image_alt, brand, sku, categories).
	 * @return array[] field, label, ld (value or ''), api (value, '' or null when the API has no such field).
	 */
	public static function ai_view_rows( array $ld, array $api ) {
		$offer = array();
		if ( isset( $ld['offers'] ) && is_array( $ld['offers'] ) ) {
			$offer = isset( $ld['offers']['@type'] ) ? $ld['offers'] : ( isset( $ld['offers'][0] ) && is_array( $ld['offers'][0] ) ? $ld['offers'][0] : array() );
		}
		$gtin = '';
		foreach ( array( 'gtin', 'gtin13', 'gtin12', 'gtin14', 'gtin8' ) as $k ) {
			if ( '' === $gtin && ! empty( $ld[ $k ] ) ) {
				$gtin = (string) $ld[ $k ];
			}
		}
		$brand = '';
		if ( ! empty( $ld['brand'] ) ) {
			$brand = is_array( $ld['brand'] ) ? (string) ( isset( $ld['brand']['name'] ) ? $ld['brand']['name'] : '' ) : (string) $ld['brand'];
		}
		$price = '';
		if ( isset( $offer['price'] ) ) {
			$price = (string) $offer['price'];
		} elseif ( isset( $offer['priceSpecification'][0]['price'] ) ) {
			$price = (string) $offer['priceSpecification'][0]['price'];
		} elseif ( isset( $offer['lowPrice'] ) ) {
			$price = (string) $offer['lowPrice'];
		}
		$avail = isset( $offer['availability'] ) ? preg_replace( '#^https?://schema\.org/#', '', (string) $offer['availability'] ) : '';
		$desc  = isset( $ld['description'] ) ? wp_strip_all_tags( (string) $ld['description'] ) : '';
		$val   = function ( $arr, $k ) {
			return isset( $arr[ $k ] ) && '' !== (string) $arr[ $k ] ? (string) $arr[ $k ] : '';
		};
		return array(
			array( 'field' => 'name', 'label' => __( 'Name', 'leymish-ai-shopping-readiness' ), 'ld' => $val( $ld, 'name' ), 'api' => $val( $api, 'name' ) ),
			array( 'field' => 'price', 'label' => __( 'Price', 'leymish-ai-shopping-readiness' ), 'ld' => $price, 'api' => $val( $api, 'price' ) ),
			array( 'field' => 'availability', 'label' => __( 'In stock', 'leymish-ai-shopping-readiness' ), 'ld' => $avail, 'api' => $val( $api, 'in_stock' ) ),
			array( 'field' => 'brand', 'label' => __( 'Brand', 'leymish-ai-shopping-readiness' ), 'ld' => $brand, 'api' => array_key_exists( 'brand', $api ) ? $val( $api, 'brand' ) : null ),
			array( 'field' => 'gtin', 'label' => __( 'Barcode (GTIN)', 'leymish-ai-shopping-readiness' ), 'ld' => $gtin, 'api' => null ),
			array( 'field' => 'mpn', 'label' => __( 'Part number (MPN)', 'leymish-ai-shopping-readiness' ), 'ld' => $val( $ld, 'mpn' ), 'api' => null ),
			array( 'field' => 'sku', 'label' => __( 'SKU', 'leymish-ai-shopping-readiness' ), 'ld' => $val( $ld, 'sku' ), 'api' => $val( $api, 'sku' ) ),
			array( 'field' => 'description', 'label' => __( 'Description', 'leymish-ai-shopping-readiness' ), 'ld' => mb_substr( $desc, 0, 90 ), 'api' => null ),
			array( 'field' => 'image_alt', 'label' => __( 'Image alt text', 'leymish-ai-shopping-readiness' ), 'ld' => null, 'api' => $val( $api, 'image_alt' ) ),
			array( 'field' => 'returns', 'label' => __( 'Return policy', 'leymish-ai-shopping-readiness' ), 'ld' => isset( $offer['hasMerchantReturnPolicy'] ) ? __( 'stated', 'leymish-ai-shopping-readiness' ) : '', 'api' => null ),
			array( 'field' => 'shipping', 'label' => __( 'Shipping cost', 'leymish-ai-shopping-readiness' ), 'ld' => isset( $offer['shippingDetails'] ) ? __( 'stated', 'leymish-ai-shopping-readiness' ) : '', 'api' => null ),
		);
	}

	/* ------------------------------------------------------------------ data */

	/**
	 * Products (and variations) for a view: published (default) or drafts and private.
	 *
	 * @param string $view    published|drafts.
	 * @param bool   $all     Include complete products.
	 * @return WC_Product[]
	 */
	public static function items( $view = 'published', $all = false ) {
		$ids   = wc_get_products(
			array(
				'status' => 'drafts' === $view ? array( 'draft', 'private', 'pending' ) : array( 'publish' ),
				'limit'  => -1,
				'return' => 'ids',
				'type'   => array( 'simple', 'variable', 'external' ),
			)
		);
		$items = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$list = array( $p );
			if ( $p->is_type( 'variable' ) ) {
				foreach ( $p->get_children() as $vid ) {
					$v = wc_get_product( $vid );
					if ( $v ) {
						$list[] = $v;
					}
				}
			}
			foreach ( $list as $item ) {
				if ( $all || self::missing_for( $item, false ) ) {
					$items[] = $item;
				}
			}
		}
		return $items;
	}

	/**
	 * Count of drafts and private products (parents only).
	 *
	 * @return int
	 */
	public static function draft_count() {
		return count(
			wc_get_products(
				array(
					'status' => array( 'draft', 'private', 'pending' ),
					'limit'  => -1,
					'return' => 'ids',
				)
			)
		);
	}

	/**
	 * Missing keys for a product.
	 *
	 * @param WC_Product $p        Product or variation.
	 * @param bool       $with_alt Include the alt-text gap.
	 * @return string[]
	 */
	public static function missing_for( $p, $with_alt = true ) {
		$alt = null;
		if ( $with_alt && ! $p->is_type( 'variation' ) ) {
			$alt = $p->get_image_id() ? (string) get_post_meta( $p->get_image_id(), '_wp_attachment_image_alt', true ) : null;
		}
		return self::missing( LASR_Audit::gtin( $p ), LASR_Audit::mpn( $p ), LASR_Audit::brand( $p ), $p->is_type( 'variation' ), $alt );
	}

	/**
	 * The brand to suggest for "Set brand for all": the only WooCommerce Brands term if there is exactly one,
	 * otherwise the store name.
	 *
	 * @return string
	 */
	public static function suggested_brand() {
		if ( taxonomy_exists( 'product_brand' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_brand',
					'hide_empty' => false,
					'number'     => 2,
				)
			);
			if ( is_array( $terms ) && 1 === count( $terms ) ) {
				return $terms[0]->name;
			}
		}
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/* ------------------------------------------------------------------ saving */

	/**
	 * Save one product's identifiers. Returns an error string, or '' on success.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param string     $gtin    GTIN.
	 * @param string     $brand   Brand (ignored for variations).
	 * @param string     $mpn     MPN.
	 * @return string
	 */
	public static function save_product( $product, $gtin, $brand, $mpn ) {
		$before = array( LASR_Audit::gtin( $product ), LASR_Audit::mpn( $product ), LASR_Audit::brand( $product ) );
		$gtin   = LASR_GTIN::normalize( $gtin );
		if ( '' !== $gtin && ! LASR_GTIN::is_valid( $gtin ) ) {
			/* translators: 1: product name, 2: the GTIN entered. */
			return sprintf( __( '%1$s: "%2$s" is not a valid GTIN (8, 12, 13 or 14 digits with a correct check digit). Not saved.', 'leymish-ai-shopping-readiness' ), $product->get_name(), $gtin );
		}
		$err = self::set_gtin( $product, $gtin );
		if ( '' !== $err ) {
			return $err;
		}
		$product->update_meta_data( '_lasr_mpn', sanitize_text_field( $mpn ) );
		if ( ! $product->is_type( 'variation' ) ) {
			self::set_brand( $product, sanitize_text_field( $brand ) );
		}
		$product->save();
		$after = array( LASR_Audit::gtin( $product ), LASR_Audit::mpn( $product ), LASR_Audit::brand( $product ) );
		if ( $after !== $before && '' !== implode( '', $after ) ) { // only real changes that add data are counted
			LASR_Worklog::log( 'identifiers', $product->get_id() );
			self::mark_fixed( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
		}
		return '';
	}

	/**
	 * GTIN into WooCommerce's own field (9.2+) or our meta.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $gtin    Normalised GTIN.
	 * @return string Error or ''.
	 */
	private static function set_gtin( $product, $gtin ) {
		if ( method_exists( $product, 'set_global_unique_id' ) ) {
			try {
				$product->set_global_unique_id( $gtin );
			} catch ( Exception $e ) {
				/* translators: 1: product name, 2: error message from WooCommerce (e.g. duplicate GTIN). */
				return sprintf( __( '%1$s: %2$s', 'leymish-ai-shopping-readiness' ), $product->get_name(), $e->getMessage() );
			}
		} else {
			$product->update_meta_data( '_lasr_gtin', $gtin );
		}
		return '';
	}

	/**
	 * Brand into the WooCommerce Brands taxonomy (9.6+) or our meta.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $brand   Brand.
	 */
	private static function set_brand( $product, $brand ) {
		if ( taxonomy_exists( 'product_brand' ) ) {
			wp_set_object_terms( $product->get_id(), '' === $brand ? array() : array( $brand ), 'product_brand' );
		} else {
			$product->update_meta_data( '_lasr_brand', $brand );
		}
	}

	/**
	 * Remember which product was just fixed, so its AI view card animates to green once.
	 *
	 * @param int $id Product ID.
	 */
	private static function mark_fixed( $id ) {
		set_transient( self::FIXED . get_current_user_id(), (int) $id, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Guard for every form.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the Products tab.
	 *
	 * @param string $extra Query string.
	 */
	private static function back( $extra = '' ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=products' . $extra ) );
		exit;
	}

	/**
	 * Save the inline editor rows.
	 */
	public static function handle_save() {
		self::guard( 'lasr_bulk_save' );
		$rows   = isset( $_POST['lasr'] ) && is_array( $_POST['lasr'] ) ? wp_unslash( $_POST['lasr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitised in save_product().
		$errors = array();
		$saved  = 0;
		$last   = 0;
		foreach ( $rows as $id => $fields ) {
			$product = wc_get_product( absint( $id ) );
			if ( ! $product || ! is_array( $fields ) ) {
				continue;
			}
			$err = self::save_product(
				$product,
				isset( $fields['gtin'] ) ? (string) $fields['gtin'] : '',
				isset( $fields['brand'] ) ? (string) $fields['brand'] : '',
				isset( $fields['mpn'] ) ? (string) $fields['mpn'] : ''
			);
			if ( '' === $err ) {
				++$saved;
				$last = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			} else {
				$errors[] = $err;
			}
		}
		set_transient( 'lasr_bulk_result_' . get_current_user_id(), compact( 'saved', 'errors' ), 120 );
		$view = isset( $_POST['view'] ) && 'drafts' === sanitize_key( wp_unslash( $_POST['view'] ) ) ? '&view=drafts' : '';
		$page = isset( $_POST['paged'] ) ? '&paged=' . absint( $_POST['paged'] ) : '';
		$fixed = (int) get_transient( self::FIXED . get_current_user_id() );
		self::back( $view . $page . ( $fixed ? '&card=' . $fixed . '#lasr-aiview' : ( $last ? '&card=' . $last : '' ) ) );
	}

	/**
	 * Build a one-click fix preview (nothing changes yet) and keep it for 15 minutes.
	 */
	public static function handle_preview() {
		self::guard( 'lasr_fix_preview' );
		$kind    = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$changes = array();
		$note    = '';
		if ( 'brand' === $kind ) {
			$brand = isset( $_POST['brand'] ) ? sanitize_text_field( wp_unslash( $_POST['brand'] ) ) : '';
			if ( '' === $brand ) {
				self::back( '&lasr_msg=nobrand' );
			}
			foreach ( self::items( 'published', true ) as $p ) {
				if ( ! $p->is_type( 'variation' ) && '' === LASR_Audit::brand( $p ) ) {
					$changes[] = array( 'id' => $p->get_id(), 'name' => $p->get_name(), 'field' => 'brand', 'before' => '', 'after' => $brand );
				}
			}
		} elseif ( 'mpn' === $kind ) {
			if ( empty( $_POST['private_label'] ) ) {
				self::back( '&lasr_msg=tick' );
			}
			foreach ( self::items( 'published', true ) as $p ) {
				$sku = (string) $p->get_sku();
				if ( '' !== $sku && '' === LASR_Audit::mpn( $p ) ) {
					$changes[] = array( 'id' => $p->get_id(), 'name' => $p->is_type( 'variation' ) ? $p->get_name() : $p->get_name(), 'field' => 'mpn', 'before' => '', 'after' => $sku );
				}
			}
		} elseif ( 'gtin_csv' === $kind ) {
			$file = isset( $_FILES['csv']['tmp_name'] ) ? (string) $_FILES['csv']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path of the upload, read below and never stored.
			if ( '' === $file || ! is_uploaded_file( $file ) || filesize( $file ) > 2 * MB_IN_BYTES ) {
				self::back( '&lasr_msg=nofile' );
			}
			$parsed = self::parse_gtin_csv( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the uploaded CSV.
			if ( '' !== $parsed['error'] ) {
				set_transient( 'lasr_fix_error_' . get_current_user_id(), $parsed['error'], 300 );
				self::back( '&lasr_msg=csv' );
			}
			$skipped = 0;
			foreach ( $parsed['rows'] as $r ) {
				$id = 'id' === $r['by'] ? absint( $r['ref'] ) : (int) wc_get_product_id_by_sku( $r['ref'] );
				$p  = $id ? wc_get_product( $id ) : null;
				if ( ! $p || ! $r['valid'] ) {
					++$skipped;
					continue;
				}
				$changes[] = array( 'id' => $p->get_id(), 'name' => $p->get_name(), 'field' => 'gtin', 'before' => LASR_Audit::gtin( $p ), 'after' => $r['gtin'] );
			}
			if ( $skipped ) {
				/* translators: %d: rows skipped. */
				$note = sprintf( _n( '%d row was skipped: no matching product, or the GTIN failed the check-digit test.', '%d rows were skipped: no matching product, or the GTIN failed the check-digit test.', $skipped, 'leymish-ai-shopping-readiness' ), $skipped );
			}
		} else {
			self::back();
		}
		set_transient(
			'lasr_fix_preview_' . get_current_user_id(),
			array(
				'kind'    => $kind,
				'changes' => array_slice( $changes, 0, 2000 ),
				'note'    => $note,
			),
			15 * MINUTE_IN_SECONDS
		);
		self::back( '&preview=1' );
	}

	/**
	 * Apply the previewed fix and keep an undo record.
	 */
	public static function handle_apply() {
		self::guard( 'lasr_fix_apply' );
		$pv = get_transient( 'lasr_fix_preview_' . get_current_user_id() );
		delete_transient( 'lasr_fix_preview_' . get_current_user_id() );
		if ( ! is_array( $pv ) || empty( $pv['changes'] ) ) {
			self::back( '&lasr_msg=expired' );
		}
		$done = array();
		foreach ( $pv['changes'] as $c ) {
			$p = wc_get_product( (int) $c['id'] );
			if ( ! $p ) {
				continue;
			}
			if ( 'brand' === $c['field'] ) {
				self::set_brand( $p, $c['after'] );
			} elseif ( 'mpn' === $c['field'] ) {
				$p->update_meta_data( '_lasr_mpn', $c['after'] );
			} elseif ( 'gtin' === $c['field'] && '' !== self::set_gtin( $p, $c['after'] ) ) {
				continue;
			}
			$p->save();
			$done[] = $c;
		}
		update_option(
			self::UNDO,
			array(
				'kind'    => $pv['kind'],
				't'       => time(),
				'changes' => $done,
			),
			false
		);
		$kind_log = array(
			'brand'    => 'brand_all',
			'mpn'      => 'mpn_sku',
			'gtin_csv' => 'gtin_csv',
		);
		if ( $done ) {
			LASR_Worklog::log( $kind_log[ $pv['kind'] ], 0, count( $done ) );
			self::mark_fixed( (int) $done[0]['id'] );
		}
		self::back( '&lasr_msg=applied&n=' . count( $done ) );
	}

	/**
	 * Undo the last one-click fix.
	 */
	public static function handle_undo() {
		self::guard( 'lasr_fix_undo' );
		$u = get_option( self::UNDO );
		delete_option( self::UNDO );
		if ( ! is_array( $u ) || empty( $u['changes'] ) ) {
			self::back();
		}
		foreach ( $u['changes'] as $c ) {
			$p = wc_get_product( (int) $c['id'] );
			if ( ! $p ) {
				continue;
			}
			if ( 'brand' === $c['field'] ) {
				self::set_brand( $p, (string) $c['before'] );
			} elseif ( 'mpn' === $c['field'] ) {
				$p->update_meta_data( '_lasr_mpn', (string) $c['before'] );
			} elseif ( 'gtin' === $c['field'] ) {
				self::set_gtin( $p, (string) $c['before'] );
			}
			$p->save();
		}
		self::back( '&lasr_msg=undone&n=' . count( $u['changes'] ) );
	}

	/* ------------------------------------------------------------------ AI view */

	/**
	 * This product's JSON-LD as WooCommerce prints it on the product page (with our schema filter applied).
	 *
	 * @param WC_Product $p Product.
	 * @return array
	 */
	public static function jsonld_for( $p ) {
		try {
			if ( ! class_exists( 'WC_Structured_Data' ) && function_exists( 'WC' ) ) {
				include_once WC()->plugin_path() . '/includes/class-wc-structured-data.php';
			}
			if ( ! class_exists( 'WC_Structured_Data' ) ) {
				return array();
			}
			$sd = new WC_Structured_Data();
			$sd->generate_product_data( $p );
			foreach ( array_reverse( (array) $sd->get_data() ) as $d ) {
				if ( is_array( $d ) && isset( $d['@type'] ) && in_array( 'Product', (array) $d['@type'], true ) ) {
					return $d;
				}
			}
		} catch ( Throwable $e ) {
			return array();
		}
		return array();
	}

	/**
	 * The fields the WooCommerce Store API gives an agent for this product.
	 *
	 * @param WC_Product $p Product.
	 * @return array
	 */
	public static function store_api_for( $p ) {
		$out = array(
			'name'      => $p->get_name(),
			'price'     => (string) $p->get_price(),
			'in_stock'  => $p->is_in_stock() ? 'yes' : '',
			'sku'       => (string) $p->get_sku(),
			'image_alt' => $p->get_image_id() ? (string) get_post_meta( $p->get_image_id(), '_wp_attachment_image_alt', true ) : '',
		);
		if ( taxonomy_exists( 'product_brand' ) ) { // WooCommerce 9.6+ adds brands to the Store API
			$terms        = get_the_terms( $p->get_id(), 'product_brand' );
			$out['brand'] = is_array( $terms ) && $terms ? $terms[0]->name : '';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ the tab */

	/**
	 * Render the Products tab.
	 *
	 * @param array|null $result Last audit.
	 */
	public static function render( $result ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$view  = isset( $_GET['view'] ) && 'drafts' === sanitize_key( wp_unslash( $_GET['view'] ) ) ? 'drafts' : 'published';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$all   = isset( $_GET['all'] );
		$msg   = isset( $_GET['lasr_msg'] ) ? sanitize_key( wp_unslash( $_GET['lasr_msg'] ) ) : '';
		$n     = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
		$card  = isset( $_GET['card'] ) ? absint( $_GET['card'] ) : 0;
		$show  = isset( $_GET['preview'] );
		// phpcs:enable
		$base       = admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=products' );
		$published  = self::items( 'published', true );
		$incomplete = array_values(
			array_filter(
				$published,
				function ( $p ) {
					return (bool) array_diff( self::missing_for( $p ), array( 'alt' ) );
				}
			)
		);
		$parents    = array_filter(
			$published,
			function ( $p ) {
				return ! $p->is_type( 'variation' );
			}
		);
		$no_brand   = count(
			array_filter(
				$parents,
				function ( $p ) {
					return '' === LASR_Audit::brand( $p );
				}
			)
		);
		$no_id      = count(
			array_filter(
				$published,
				function ( $p ) {
					return in_array( 'identifier', self::missing_for( $p, false ), true );
				}
			)
		);

		LASR_Admin::three(
			$incomplete
				/* translators: 1: products and variations missing data, 2: all published products and variations. */
				? sprintf( __( '%1$d of %2$d published products and variations are missing a barcode/part number or a brand.', 'leymish-ai-shopping-readiness' ), count( $incomplete ), count( $published ) )
				: __( 'Every published product has a barcode or part number and a brand.', 'leymish-ai-shopping-readiness' ),
			self::fixed_line(),
			$incomplete ? __( 'Use a one-click fix below, or fill the table.', 'leymish-ai-shopping-readiness' ) : __( 'Keep it that way: new products appear here when they miss something.', 'leymish-ai-shopping-readiness' ),
			$incomplete ? array( '#lasr-fixes', __( 'Fix them', 'leymish-ai-shopping-readiness' ) ) : null
		);

		echo '<p class="lasr-lede">' . esc_html__( 'ChatGPT, Google and Perplexity match products by barcode (GTIN), brand and maker\'s part number (MPN). Products without them are often left out.', 'leymish-ai-shopping-readiness' ) . '</p>';
		self::messages( $msg, $n );

		if ( $show ) {
			self::preview_box();
		}
		$undo = get_option( self::UNDO );
		if ( is_array( $undo ) && ! empty( $undo['changes'] ) && ! $show ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-undo">';
			wp_nonce_field( 'lasr_fix_undo' );
			/* translators: %d: products changed by the last one-click fix. */
			echo '<input type="hidden" name="action" value="lasr_fix_undo" /><span>' . esc_html( sprintf( _n( 'Your last one-click fix changed %d product.', 'Your last one-click fix changed %d products.', count( $undo['changes'] ), 'leymish-ai-shopping-readiness' ), count( $undo['changes'] ) ) ) . '</span> ';
			submit_button( __( 'Undo it', 'leymish-ai-shopping-readiness' ), 'secondary small', 'submit', false );
			echo '</form>';
		}

		self::ai_view( $published, $card );
		self::fixes( $no_brand, $no_id );

		// The table.
		$drafts = self::draft_count();
		echo '<h2 id="lasr-table">' . esc_html__( 'Fill in what\'s missing', 'leymish-ai-shopping-readiness' ) . '</h2>';
		echo '<p class="lasr-filters">';
		echo '<a href="' . esc_url( $base ) . '"' . ( 'published' === $view ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'Published', 'leymish-ai-shopping-readiness' ) . '</a> | ';
		/* translators: %d: number of draft and private products. */
		echo '<a href="' . esc_url( $base . '&view=drafts' ) . '"' . ( 'drafts' === $view ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( sprintf( __( 'Drafts and private (%d)', 'leymish-ai-shopping-readiness' ), $drafts ) ) . '</a> | ';
		echo '<a href="' . esc_url( $base . ( 'drafts' === $view ? '&view=drafts' : '' ) . ( $all ? '' : '&all=1' ) ) . '">' . esc_html( $all ? __( 'Only products missing data', 'leymish-ai-shopping-readiness' ) : __( 'All products', 'leymish-ai-shopping-readiness' ) ) . '</a></p>';
		if ( 'drafts' === $view ) {
			echo '<p class="description lasr-note">' . esc_html__( 'Not visible to shoppers or AI agents, and not scored by the audit. Fix them before you publish.', 'leymish-ai-shopping-readiness' ) . '</p>';
		}
		$items = 'drafts' === $view ? self::items( 'drafts', $all ) : ( $all ? $published : $incomplete );
		$total = count( $items );
		$items = array_slice( $items, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		if ( ! $items ) {
			echo '<div class="lasr-empty-state"><p>' . esc_html( 'drafts' === $view ? __( 'No drafts or private products are missing data.', 'leymish-ai-shopping-readiness' ) : __( 'Every published product has a barcode or part number and a brand. AI shopping agents can match all of them.', 'leymish-ai-shopping-readiness' ) ) . '</p></div>';
			return;
		}
		$labels = array(
			'identifier' => __( 'GTIN or MPN', 'leymish-ai-shopping-readiness' ),
			'brand'      => __( 'Brand', 'leymish-ai-shopping-readiness' ),
			'alt'        => __( 'Image alt text', 'leymish-ai-shopping-readiness' ),
		);
		$pills  = array(
			'complete' => __( 'Complete', 'leymish-ai-shopping-readiness' ),
			'partial'  => __( 'Needs one thing', 'leymish-ai-shopping-readiness' ),
			'missing'  => __( 'Missing data', 'leymish-ai-shopping-readiness' ),
		);
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lasr_bulk_save' );
		echo '<input type="hidden" name="action" value="lasr_bulk_save" /><input type="hidden" name="paged" value="' . esc_attr( (string) $paged ) . '" /><input type="hidden" name="view" value="' . esc_attr( $view ) . '" />';
		echo '<div class="lasr-scroll"><table class="widefat lasr-table lasr-editor"><thead><tr><th scope="col">' . esc_html__( 'Product', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Status', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'GTIN / UPC / EAN', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Brand', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'MPN', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $p ) {
			$id      = $p->get_id();
			$is_var  = $p->is_type( 'variation' );
			$name    = $is_var ? '— ' . $p->get_name() : $p->get_name(); // a variation's name includes its product's ("Rain Jacket - M")
			$missing = self::missing_for( $p );
			$pill    = self::pill( $missing );
			echo '<tr><th scope="row"><a href="' . esc_url( (string) get_edit_post_link( $is_var ? $p->get_parent_id() : $id ) ) . '">' . esc_html( $name ) . '</a>';
			echo ' <a class="lasr-see" href="' . esc_url( $base . '&card=' . ( $is_var ? $p->get_parent_id() : $id ) . '#lasr-aiview' ) . '">' . esc_html__( 'See it as AI does', 'leymish-ai-shopping-readiness' ) . '</a></th>';
			echo '<td><span class="lasr-pill lasr-pill-' . esc_attr( $pill ) . '">' . esc_html( $pills[ $pill ] ) . '</span>';
			foreach ( $missing as $m ) {
				echo ' <span class="lasr-chip">' . esc_html( $labels[ $m ] ) . '</span>';
			}
			echo '</td>';
			echo '<td><input type="text" name="lasr[' . esc_attr( (string) $id ) . '][gtin]" value="' . esc_attr( LASR_Audit::gtin( $p ) ) . '" inputmode="numeric" class="lasr-gtin" aria-label="' . esc_attr( sprintf( /* translators: %s: product name. */ __( 'GTIN for %s', 'leymish-ai-shopping-readiness' ), $name ) ) . '" aria-describedby="lasr-gtin-msg-' . esc_attr( (string) $id ) . '" /><span class="lasr-gtin-msg" id="lasr-gtin-msg-' . esc_attr( (string) $id ) . '" aria-live="polite"></span></td>';
			echo '<td>' . ( $is_var ? '<span class="description">' . esc_html__( 'set on parent', 'leymish-ai-shopping-readiness' ) . '</span>' : '<input type="text" name="lasr[' . esc_attr( (string) $id ) . '][brand]" value="' . esc_attr( LASR_Audit::brand( $p ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: product name. */ __( 'Brand for %s', 'leymish-ai-shopping-readiness' ), $name ) ) . '" />' ) . '</td>';
			echo '<td><input type="text" name="lasr[' . esc_attr( (string) $id ) . '][mpn]" value="' . esc_attr( LASR_Audit::mpn( $p ) ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: product name. */ __( 'MPN for %s', 'leymish-ai-shopping-readiness' ), $name ) ) . '" /></td></tr>';
		}
		echo '</tbody></table></div>';
		submit_button( __( 'Save all rows on this page', 'leymish-ai-shopping-readiness' ) );
		echo '</form>';
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p class="lasr-pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = $base . '&paged=' . $i . ( 'drafts' === $view ? '&view=drafts' : '' ) . ( $all ? '&all=1' : '' );
				echo $i === $paged ? '<strong>' . esc_html( (string) $i ) . '</strong> ' : '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
			}
			echo '</p>';
		}
	}

	/**
	 * "What we fixed" line from the work log.
	 *
	 * @return string
	 */
	private static function fixed_line() {
		$log = LASR_Worklog::worklog();
		$n   = 0;
		foreach ( array( 'identifiers', 'brand_all', 'mpn_sku', 'gtin_csv' ) as $k ) {
			$n += isset( $log['counts'][ $k ] ) ? (int) $log['counts'][ $k ] : 0;
		}
		/* translators: %d: products fixed. */
		return $n ? sprintf( _n( '%d product fixed here so far.', '%d products fixed here so far.', $n, 'leymish-ai-shopping-readiness' ), $n ) : __( 'Nothing fixed here yet.', 'leymish-ai-shopping-readiness' );
	}

	/**
	 * Result notices.
	 *
	 * @param string $msg Code.
	 * @param int    $n   Count.
	 */
	private static function messages( $msg, $n ) {
		$result = get_transient( 'lasr_bulk_result_' . get_current_user_id() );
		if ( is_array( $result ) ) {
			delete_transient( 'lasr_bulk_result_' . get_current_user_id() );
			/* translators: %d: number of products saved. */
			echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html( sprintf( _n( '%d product saved.', '%d products saved.', (int) $result['saved'], 'leymish-ai-shopping-readiness' ), (int) $result['saved'] ) ) . '</p></div>';
			foreach ( $result['errors'] as $e ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $e ) . '</p></div>';
			}
		}
		$texts = array(
			/* translators: %d: products changed. */
			'applied' => sprintf( _n( 'Done: %d product changed. You can undo it below.', 'Done: %d products changed. You can undo it below.', $n, 'leymish-ai-shopping-readiness' ), $n ),
			/* translators: %d: products restored. */
			'undone'  => sprintf( _n( 'Undone: %d product is back as it was.', 'Undone: %d products are back as they were.', $n, 'leymish-ai-shopping-readiness' ), $n ),
			'tick'    => __( 'Tick the box first: only use your SKU as the MPN if you make these products yourself.', 'leymish-ai-shopping-readiness' ),
			'nobrand' => __( 'Type the brand name first.', 'leymish-ai-shopping-readiness' ),
			'nofile'  => __( 'Choose a CSV file (2 MB at most).', 'leymish-ai-shopping-readiness' ),
			'expired' => __( 'That preview expired. Nothing changed; make it again.', 'leymish-ai-shopping-readiness' ),
		);
		if ( 'csv' === $msg ) {
			$err = get_transient( 'lasr_fix_error_' . get_current_user_id() );
			echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $err ) . '</p></div>';
		} elseif ( isset( $texts[ $msg ] ) ) {
			echo '<div class="notice ' . ( in_array( $msg, array( 'applied', 'undone' ), true ) ? 'notice-success' : 'notice-warning' ) . ' inline"><p>' . esc_html( $texts[ $msg ] ) . '</p></div>';
		}
	}

	/**
	 * The preview of a one-click fix, with Apply.
	 */
	private static function preview_box() {
		$pv = get_transient( 'lasr_fix_preview_' . get_current_user_id() );
		if ( ! is_array( $pv ) ) {
			return;
		}
		echo '<section class="lasr-preview lasr-card-box" aria-labelledby="lasr-pv-h"><h2 id="lasr-pv-h">' . esc_html__( 'Preview: nothing has changed yet', 'leymish-ai-shopping-readiness' ) . '</h2>';
		if ( ! $pv['changes'] ) {
			echo '<p>' . esc_html__( 'No products need this fix.', 'leymish-ai-shopping-readiness' ) . '</p>';
			if ( $pv['note'] ) {
				echo '<p class="description">' . esc_html( $pv['note'] ) . '</p>';
			}
			echo '</section>';
			return;
		}
		$field = array(
			'brand' => __( 'Brand', 'leymish-ai-shopping-readiness' ),
			'mpn'   => __( 'MPN', 'leymish-ai-shopping-readiness' ),
			'gtin'  => __( 'GTIN', 'leymish-ai-shopping-readiness' ),
		);
		/* translators: %d: products that will change. */
		echo '<p>' . esc_html( sprintf( _n( '%d product will change:', '%d products will change:', count( $pv['changes'] ), 'leymish-ai-shopping-readiness' ), count( $pv['changes'] ) ) ) . '</p>';
		echo '<div class="lasr-scroll"><table class="widefat lasr-table"><thead><tr><th scope="col">' . esc_html__( 'Product', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Field', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Now', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'After', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $pv['changes'], 0, 100 ) as $c ) {
			echo '<tr><th scope="row">' . esc_html( $c['name'] ) . '</th><td>' . esc_html( $field[ $c['field'] ] ) . '</td><td>' . esc_html( '' === (string) $c['before'] ? '—' : (string) $c['before'] ) . '</td><td><strong>' . esc_html( (string) $c['after'] ) . '</strong></td></tr>';
		}
		echo '</tbody></table></div>';
		if ( count( $pv['changes'] ) > 100 ) {
			/* translators: %d: more products. */
			echo '<p class="description">' . esc_html( sprintf( __( 'And %d more.', 'leymish-ai-shopping-readiness' ), count( $pv['changes'] ) - 100 ) ) . '</p>';
		}
		if ( $pv['note'] ) {
			echo '<p class="description">' . esc_html( $pv['note'] ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lasr_fix_apply' );
		echo '<input type="hidden" name="action" value="lasr_fix_apply" />';
		submit_button( __( 'Apply to these products', 'leymish-ai-shopping-readiness' ), 'primary', 'submit', false );
		echo ' <a class="button-link" href="' . esc_url( admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=products' ) ) . '">' . esc_html__( 'Cancel', 'leymish-ai-shopping-readiness' ) . '</a> <span class="description">' . esc_html__( 'You can undo it afterwards.', 'leymish-ai-shopping-readiness' ) . '</span></form></section>';
	}

	/**
	 * The three one-click fixes and the GTIN help.
	 *
	 * @param int $no_brand Products without a brand.
	 * @param int $no_id    Products and variations without a GTIN or MPN.
	 */
	private static function fixes( $no_brand, $no_id ) {
		$post = esc_url( admin_url( 'admin-post.php' ) );
		echo '<h2 id="lasr-fixes">' . esc_html__( 'One-click fixes', 'leymish-ai-shopping-readiness' ) . '</h2><p class="description">' . esc_html__( 'Each one shows you a preview first, and can be undone.', 'leymish-ai-shopping-readiness' ) . '</p><div class="lasr-fix-grid">';

		echo '<form method="post" action="' . $post . '" class="lasr-card-box lasr-fix">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		wp_nonce_field( 'lasr_fix_preview' );
		/* translators: %d: products without a brand. */
		echo '<input type="hidden" name="action" value="lasr_fix_preview" /><input type="hidden" name="kind" value="brand" /><h3>' . esc_html__( 'Set brand for all', 'leymish-ai-shopping-readiness' ) . '</h3><p>' . esc_html( sprintf( _n( '%d published product has no brand.', '%d published products have no brand.', $no_brand, 'leymish-ai-shopping-readiness' ), $no_brand ) ) . ' ' . esc_html__( 'If you sell one brand, set it on all of them at once. Products that already have a brand are left alone.', 'leymish-ai-shopping-readiness' ) . '</p>';
		echo '<p><label for="lasr-brand-all">' . esc_html__( 'Brand name', 'leymish-ai-shopping-readiness' ) . '</label><br><input type="text" id="lasr-brand-all" name="brand" class="regular-text" value="' . esc_attr( self::suggested_brand() ) . '" /></p>';
		submit_button( __( 'Preview', 'leymish-ai-shopping-readiness' ), 'secondary', 'submit', false, $no_brand ? array() : array( 'disabled' => 'disabled' ) );
		echo '</form>';

		echo '<form method="post" action="' . $post . '" class="lasr-card-box lasr-fix">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		wp_nonce_field( 'lasr_fix_preview' );
		echo '<input type="hidden" name="action" value="lasr_fix_preview" /><input type="hidden" name="kind" value="mpn" /><h3>' . esc_html__( 'I make these products: use each SKU as the MPN', 'leymish-ai-shopping-readiness' ) . '</h3>';
		echo '<p>' . esc_html__( 'The MPN is the maker\'s own part number. If you make or private-label these products, your SKU is that number. If you resell other brands, don\'t: use the maker\'s MPN or the barcode on the packaging instead.', 'leymish-ai-shopping-readiness' ) . '</p>';
		echo '<p><label><input type="checkbox" name="private_label" value="1" /> ' . esc_html__( 'Yes, I make or private-label these products, so my SKUs are the part numbers.', 'leymish-ai-shopping-readiness' ) . '</label></p>';
		submit_button( __( 'Preview', 'leymish-ai-shopping-readiness' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . $post . '" enctype="multipart/form-data" class="lasr-card-box lasr-fix">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		wp_nonce_field( 'lasr_fix_preview' );
		/* translators: %d: products without a GTIN or MPN. */
		echo '<input type="hidden" name="action" value="lasr_fix_preview" /><input type="hidden" name="kind" value="gtin_csv" /><h3>' . esc_html__( 'GTINs from your supplier', 'leymish-ai-shopping-readiness' ) . '</h3><p>' . esc_html( sprintf( _n( '%d product or variation has no GTIN or MPN.', '%d products and variations have no GTIN or MPN.', $no_id, 'leymish-ai-shopping-readiness' ), $no_id ) ) . '</p>';
		echo '<details><summary>' . esc_html__( 'Where do I find a GTIN?', 'leymish-ai-shopping-readiness' ) . '</summary><ul class="lasr-list"><li>' . esc_html__( 'Your supplier\'s product file or price list (look for EAN, UPC or barcode).', 'leymish-ai-shopping-readiness' ) . '</li><li>' . esc_html__( 'The barcode printed on the packaging: the 8, 12, 13 or 14 digits under the bars.', 'leymish-ai-shopping-readiness' ) . '</li><li>' . esc_html__( 'If you make the product, your own GS1 membership (gs1.org) issues them. Never make one up: a fake GTIN gets products rejected.', 'leymish-ai-shopping-readiness' ) . '</li></ul></details>';
		echo '<p><label for="lasr-csv">' . esc_html__( 'CSV with a SKU (or ID) column and a GTIN column', 'leymish-ai-shopping-readiness' ) . '</label><br><input type="file" id="lasr-csv" name="csv" accept=".csv,text/csv,.txt" /></p>';
		submit_button( __( 'Preview', 'leymish-ai-shopping-readiness' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * "See it the way AI sees it" for one product.
	 *
	 * @param WC_Product[] $published Published products.
	 * @param int          $card      Product to show (0 = the first with gaps, else the first).
	 */
	private static function ai_view( array $published, $card ) {
		$parents = array_values(
			array_filter(
				$published,
				function ( $p ) {
					return ! $p->is_type( 'variation' );
				}
			)
		);
		if ( ! $parents ) {
			return;
		}
		$pick = null;
		foreach ( $parents as $p ) {
			if ( $card && $p->get_id() === $card ) {
				$pick = $p;
			}
		}
		if ( ! $pick ) {
			foreach ( $parents as $p ) {
				if ( ! $pick && array_diff( self::missing_for( $p ), array( 'alt' ) ) ) {
					$pick = $p;
				}
			}
		}
		$pick  = $pick ? $pick : $parents[0];
		$rows  = self::ai_view_rows( self::jsonld_for( $pick ), self::store_api_for( $pick ) );
		$fixed = (int) get_transient( self::FIXED . get_current_user_id() ) === $pick->get_id();
		if ( $fixed ) {
			delete_transient( self::FIXED . get_current_user_id() );
		}
		echo '<section id="lasr-aiview" class="lasr-aiview' . ( $fixed ? ' lasr-just-fixed' : '' ) . '" aria-labelledby="lasr-aiview-h"><div class="lasr-aiview-head"><h2 id="lasr-aiview-h">' . esc_html__( 'See it the way AI sees it', 'leymish-ai-shopping-readiness' ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( LASR_Admin::SLUG ) . '" /><input type="hidden" name="tab" value="products" /><label for="lasr-card-pick" class="screen-reader-text">' . esc_html__( 'Product', 'leymish-ai-shopping-readiness' ) . '</label><select id="lasr-card-pick" name="card">';
		foreach ( array_slice( $parents, 0, 200 ) as $p ) {
			echo '<option value="' . esc_attr( (string) $p->get_id() ) . '"' . selected( $p->get_id(), $pick->get_id(), false ) . '>' . esc_html( $p->get_name() ) . '</option>';
		}
		echo '</select> <button type="submit" class="button">' . esc_html__( 'Show', 'leymish-ai-shopping-readiness' ) . '</button></form></div>';
		echo '<p class="description">' . esc_html__( 'What an AI shopping agent can read about this product: from the structured data on its page (JSON-LD) and from your store\'s product API. Red is missing, green is there.', 'leymish-ai-shopping-readiness' ) . '</p>';
		$ok = 0;
		$of = 0;
		echo '<div class="lasr-scroll"><table class="lasr-aiview-table"><thead><tr><th scope="col">' . esc_html__( 'What the agent looks for', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Product page (JSON-LD)', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Store API', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><th scope="row">' . esc_html( $r['label'] ) . '</th>';
			foreach ( array( 'ld', 'api' ) as $col ) {
				$v = $r[ $col ];
				if ( null === $v ) {
					echo '<td class="lasr-na"><span aria-hidden="true">·</span><span class="screen-reader-text">' . esc_html__( 'not part of this source', 'leymish-ai-shopping-readiness' ) . '</span></td>';
					continue;
				}
				++$of;
				if ( '' === $v ) {
					echo '<td class="lasr-miss"><span class="lasr-tick" aria-hidden="true">✕</span> ' . esc_html__( 'Missing', 'leymish-ai-shopping-readiness' ) . '</td>';
				} else {
					++$ok;
					echo '<td class="lasr-have"><span class="lasr-tick" aria-hidden="true">✓</span> <span class="lasr-val">' . esc_html( $v ) . '</span></td>';
				}
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		/* translators: 1: fields present, 2: fields checked, 3: product name. */
		echo '<p class="lasr-aiview-sum"><strong>' . esc_html( sprintf( __( '%1$d of %2$d fields readable for %3$s.', 'leymish-ai-shopping-readiness' ), $ok, $of, $pick->get_name() ) ) . '</strong> <a href="' . esc_url( (string) get_edit_post_link( $pick->get_id() ) ) . '">' . esc_html__( 'Edit the product', 'leymish-ai-shopping-readiness' ) . '</a></p></section>';
	}
}
