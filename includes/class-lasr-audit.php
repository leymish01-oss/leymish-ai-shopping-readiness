<?php
/**
 * The audit: catalog data completeness plus live checks against the store's own URLs.
 *
 * Everything runs on this site. The only HTTP requests go to this store's own URLs (home_url()).
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs all checks and stores the result.
 */
class LASR_Audit {

	const OPTION = 'lasr_last_audit';

	/**
	 * Points per check. Catalog completeness is worth 42, the site checks 58.
	 *
	 * @var array<string,int>
	 */
	private static $points = array(
		'catalog'   => 40,
		'public'    => 5,
		'robots'    => 12,
		'bot_block' => 12,
		'jsonld'    => 12,
		'no_js'     => 5,
		'store_api' => 5,
		'llms_txt'  => 4,
		'guest'     => 3,
		'ucp'       => 2,
		'returns'   => 2,
		'checkout'  => 3,
	);

	/**
	 * Run every check, save and return the result.
	 *
	 * @return array{score:int,band:string,ran_at:int,checks:array,products:array,summary:array}
	 */
	public static function run() {
		$catalog = self::catalog();
		$sample  = self::sample_product_urls( 3 );
		$pages   = self::fetch_pages( $sample );
		if ( class_exists( 'LASR_Ucp' ) ) {
			LASR_Ucp::detect( $pages ); // 2.1: does the theme or another plugin already say this? Then we don't repeat it.
		}

		$checks = array(
			$catalog['check'],
			self::check_public(),
			self::check_robots( $sample ),
			self::check_bot_blocks( $sample ),
			self::check_jsonld( $pages ),
			self::check_no_js( $pages ),
			self::check_store_api(),
			self::check_llms_txt(),
			self::check_guest_checkout(),
			self::check_returns_page(),
			self::check_checkout(),
			self::check_links(),
			self::check_ucp(),
			self::check_acp(),
			self::check_mcp(),
		);

		$score  = LASR_Scoring::score( $checks );
		$prev   = self::last();
		$result = array(
			'score'    => $score,
			'band'     => LASR_Scoring::band( $score ),
			'ran_at'   => time(),
			'checks'   => $checks,
			'products' => $catalog['products'],
			'summary'  => $catalog['summary'],
		);
		update_option( self::OPTION, $result, false );
		if ( $prev && isset( $prev['score'] ) && (int) $prev['score'] !== $score && class_exists( 'LASR_Worklog' ) ) {
			/* translators: 1: previous score, 2: new score. */
			LASR_Worklog::event( 'audit', sprintf( __( 'Audit score %1$d → %2$d', 'leymish-ai-shopping-readiness' ), (int) $prev['score'], $score ) );
		}
		/**
		 * Fires after an audit finishes (the Pro add-on records score history here).
		 *
		 * @param array $result The audit result.
		 */
		do_action( 'lasr_audit_completed', $result );
		return $result;
	}

	/**
	 * Last saved result, or null.
	 *
	 * @return array|null
	 */
	public static function last() {
		$r = get_option( self::OPTION );
		return is_array( $r ) ? $r : null;
	}

	/* ---------------------------------------------------------------- catalog */

	/**
	 * Per-product completeness across published products.
	 *
	 * @return array{check:array,products:array,summary:array}
	 */
	private static function catalog() {
		$limit = (int) apply_filters( 'lasr_product_limit', 500 );
		$ids   = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => $limit,
				'return' => 'ids',
				'type'   => array( 'simple', 'variable', 'external', 'grouped' ),
			)
		);
		$fields   = array( 'identifier', 'brand', 'price', 'stock', 'image', 'description', 'attributes', 'variations' );
		$missing  = array_fill_keys( $fields, 0 );
		$products = array();
		$total    = 0.0;
		$with_alt = 0; // main image has alt text (shown on the Impact tab; not scored)
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$image = $product->get_image_id();
			if ( $image && '' !== trim( (string) get_post_meta( $image, '_wp_attachment_image_alt', true ) ) ) {
				++$with_alt;
			}
			$row        = self::product_row( $product );
			$products[] = $row;
			$total     += $row['score'];
			foreach ( $row['missing'] as $f ) {
				if ( isset( $missing[ $f ] ) ) {
					++$missing[ $f ];
				}
			}
		}
		$n       = count( $products );
		$avg     = $n ? $total / $n : 0.0;
		$max     = self::$points['catalog'];
		$labels  = self::field_labels();
		$details = array();
		foreach ( $missing as $f => $count ) {
			if ( $count > 0 ) {
				/* translators: 1: number of products, 2: total products, 3: what is missing. */
				$details[] = sprintf( __( '%1$d of %2$d products: %3$s', 'leymish-ai-shopping-readiness' ), $count, $n, $labels[ $f ] );
			}
		}
		usort(
			$products,
			function ( $a, $b ) {
				return $a['score'] <=> $b['score'];
			}
		);
		$check = array(
			'id'     => 'catalog',
			'label'  => __( 'Product data completeness', 'leymish-ai-shopping-readiness' ),
			'status' => $n ? LASR_Scoring::status_for( $avg ) : 'skip',
			'points' => $max,
			'earned' => round( $max * $avg, 1 ),
			'detail' => $n
				/* translators: 1: number of products checked, 2: average completeness percentage. */
				? sprintf( __( '%1$d products checked, %2$d%% complete on average.', 'leymish-ai-shopping-readiness' ), $n, (int) round( 100 * $avg ) ) . ( $details ? ' ' . implode( '; ', $details ) . '.' : '' )
				: __( 'No published products found.', 'leymish-ai-shopping-readiness' ),
			'fix'    => __( 'Add a GTIN (or MPN) and a brand to every product, plus a real description, a main image and key attributes such as size, colour or material. Every variation needs its own price and attribute values. AI shopping agents and Google skip products without identifiers.', 'leymish-ai-shopping-readiness' ),
			'effort' => 3,
		);
		return array(
			'check'    => $check,
			'products' => $products,
			'summary'  => array(
				'checked'  => $n,
				'limit'    => $limit,
				'missing'  => $missing,
				'with_alt' => $with_alt,
			),
		);
	}

	/**
	 * Human labels for product fields.
	 *
	 * @return array<string,string>
	 */
	public static function field_labels() {
		return array(
			'identifier' => __( 'no valid GTIN or MPN', 'leymish-ai-shopping-readiness' ),
			'brand'      => __( 'no brand', 'leymish-ai-shopping-readiness' ),
			'price'      => __( 'no price', 'leymish-ai-shopping-readiness' ),
			'stock'      => __( 'no stock status', 'leymish-ai-shopping-readiness' ),
			'image'      => __( 'no main image', 'leymish-ai-shopping-readiness' ),
			'description' => __( 'description under 150 characters', 'leymish-ai-shopping-readiness' ),
			'attributes' => __( 'no attributes (size, colour, material…)', 'leymish-ai-shopping-readiness' ),
			'variations' => __( 'incomplete variations', 'leymish-ai-shopping-readiness' ),
		);
	}

	/**
	 * Completeness for one product.
	 *
	 * @param WC_Product $product Product.
	 * @return array{id:int,name:string,url:string,score:float,missing:string[],gtin_problem:string}
	 */
	public static function product_row( $product ) {
		$missing    = array();
		$applicable = 7;
		$gtin       = self::gtin( $product );
		$gtin_issue = '' === $gtin ? '' : LASR_GTIN::problem( $gtin );
		if ( ! self::has_identifier( $product ) ) {
			$missing[] = 'identifier';
		}
		if ( '' === self::brand( $product ) ) {
			$missing[] = 'brand';
		}
		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices();
			if ( empty( $prices['price'] ) ) {
				$missing[] = 'price';
			}
		} elseif ( ! $product->is_type( 'grouped' ) && '' === (string) $product->get_price() ) {
			$missing[] = 'price';
		}
		if ( '' === (string) $product->get_stock_status() ) {
			$missing[] = 'stock';
		}
		if ( ! $product->get_image_id() ) {
			$missing[] = 'image';
		}
		$text = wp_strip_all_tags( $product->get_description() . ' ' . $product->get_short_description() );
		if ( strlen( trim( $text ) ) < 150 ) {
			$missing[] = 'description';
		}
		if ( ! $product->get_attributes() ) {
			$missing[] = 'attributes';
		}
		if ( $product->is_type( 'variable' ) ) {
			++$applicable;
			if ( ! self::variations_complete( $product ) ) {
				$missing[] = 'variations';
			}
		}
		return array(
			'id'           => $product->get_id(),
			'name'         => $product->get_name(),
			'url'          => get_permalink( $product->get_id() ),
			'score'        => max( 0, ( $applicable - count( $missing ) ) / $applicable ),
			'missing'      => $missing,
			'gtin_problem' => $gtin_issue,
		);
	}

	/**
	 * A valid GTIN or an MPN. For variable products, identifiers usually live on the variations, so
	 * every variation needs one (a parent-level identifier alone doesn't identify what's sold).
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private static function has_identifier( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$children = $product->get_children();
			if ( ! $children ) {
				return false;
			}
			foreach ( $children as $child_id ) {
				$v = wc_get_product( $child_id );
				if ( $v && ! self::has_identifier( $v ) ) {
					return false;
				}
			}
			return true;
		}
		$gtin = self::gtin( $product );
		return ( '' !== $gtin && LASR_GTIN::is_valid( $gtin ) ) || '' !== self::mpn( $product );
	}

	/**
	 * Every variation has a price, a stock status and a value for every variation attribute.
	 *
	 * @param WC_Product_Variable $product Variable product.
	 * @return bool
	 */
	private static function variations_complete( $product ) {
		$children = $product->get_children();
		if ( ! $children ) {
			return false;
		}
		foreach ( $children as $child_id ) {
			$v = wc_get_product( $child_id );
			if ( ! $v ) {
				continue;
			}
			if ( '' === (string) $v->get_price() || '' === (string) $v->get_stock_status() ) {
				return false;
			}
			foreach ( $v->get_variation_attributes() as $value ) {
				if ( '' === (string) $value ) {
					return false; // "Any …" leaves the attribute empty; agents can't tell what they'd get.
				}
			}
		}
		return true;
	}

	/**
	 * GTIN from WooCommerce's own field (9.2+) or common plugin meta keys.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function gtin( $product ) {
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$v = (string) $product->get_global_unique_id();
			if ( '' !== $v ) {
				return $v;
			}
		}
		$keys = apply_filters( 'lasr_gtin_meta_keys', array( '_global_unique_id', '_lasr_gtin', '_gtin', '_wpm_gtin_code', 'hwp_product_gtin', '_barcode', '_ean' ) );
		foreach ( $keys as $key ) {
			if ( '_global_unique_id' === $key && method_exists( $product, 'get_global_unique_id' ) ) {
				continue; // WooCommerce 9.2+ owns this key (read above); reading it as meta is flagged as doing it wrong
			}
			$v = (string) $product->get_meta( $key, true );
			if ( '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	/**
	 * MPN from meta or an "MPN" attribute.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function mpn( $product ) {
		foreach ( apply_filters( 'lasr_mpn_meta_keys', array( '_lasr_mpn', '_mpn', '_wpm_mpn' ) ) as $key ) {
			$v = (string) $product->get_meta( $key, true );
			if ( '' !== $v ) {
				return $v;
			}
		}
		return (string) $product->get_attribute( 'mpn' );
	}

	/**
	 * Brand from WooCommerce Brands (9.6+ core), common brand plugins, a "Brand" attribute or meta.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function brand( $product ) {
		$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		foreach ( apply_filters( 'lasr_brand_taxonomies', array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$terms = get_the_terms( $id, $tax );
				if ( is_array( $terms ) && $terms ) {
					return $terms[0]->name;
				}
			}
		}
		$attr = (string) $product->get_attribute( 'brand' );
		if ( '' !== $attr ) {
			return $attr;
		}
		return (string) $product->get_meta( '_lasr_brand', true );
	}

	/* ----------------------------------------------------------- live checks */

	/**
	 * URLs of a few published products to test against.
	 *
	 * @param int $n How many.
	 * @return string[]
	 */
	private static function sample_product_urls( $n ) {
		$ids = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => $n,
				'return'  => 'ids',
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		return array_values( array_filter( array_map( 'get_permalink', $ids ) ) );
	}

	/**
	 * GET one of this store's own URLs. The URL passes through the "lasr_request_url" filter so test
	 * environments can map it (for example a container port).
	 *
	 * @param string $url        Absolute URL on this site.
	 * @param string $user_agent User agent to send.
	 * @return array{ok:bool,code:int,body:string,error:string}
	 */
	public static function get( $url, $user_agent = '' ) {
		$args = array(
			'timeout'     => 10,
			'redirection' => 3,
			'sslverify'   => (bool) apply_filters( 'lasr_sslverify', true ),
			'headers'     => array( 'Accept' => 'text/html,application/json;q=0.9,*/*;q=0.8' ),
		);
		if ( '' !== $user_agent ) {
			$args['user-agent'] = $user_agent;
		}
		$res = wp_remote_get( apply_filters( 'lasr_request_url', $url ), $args );
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'body'  => '',
				'error' => $res->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return array(
			'ok'    => $code >= 200 && $code < 300,
			'code'  => $code,
			'body'  => (string) wp_remote_retrieve_body( $res ),
			'error' => '',
		);
	}

	/**
	 * Fetch product pages once with a normal browser user agent (reused by several checks).
	 *
	 * @param string[] $urls Product URLs.
	 * @return array<string,array>
	 */
	private static function fetch_pages( array $urls ) {
		$pages = array();
		foreach ( $urls as $url ) {
			$pages[ $url ] = self::get( $url, self::browser_ua() );
		}
		return $pages;
	}

	/**
	 * A normal desktop browser user agent for the baseline request.
	 *
	 * @return string
	 */
	private static function browser_ua() {
		return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';
	}

	/**
	 * WooCommerce's sample "Refund and Returns Policy" text (pure): two or more of its sentences. WooCommerce creates
	 * that page as a draft; some stores publish it by accident, and then Google and AI agents can read a 30-day
	 * policy that isn't the store's.
	 *
	 * @param string $content Page content.
	 * @return bool
	 */
	public static function is_sample_returns( $content ) {
		$text = strtolower( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $content ) ) );
		$hits = 0;
		foreach ( array( 'our refund and returns policy lasts 30 days', 'if 30 days have passed since your purchase', 'to complete your return, we require a receipt or proof of purchase', 'please do not send your purchase back to the manufacturer', 'there are certain situations where only partial refunds are granted' ) as $s ) {
			$hits += false !== strpos( $text, $s ) ? 1 : 0;
		}
		return $hits >= 2;
	}

	/**
	 * The published page that still carries WooCommerce's sample returns text, or 0.
	 *
	 * @return int
	 */
	public static function sample_returns_page() {
		$ids  = array( (int) get_option( 'woocommerce_refund_returns_page_id' ) );
		$page = get_page_by_path( 'refund_returns' );
		if ( $page ) {
			$ids[] = (int) $page->ID;
		}
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			$post = get_post( $id );
			if ( $post && 'page' === $post->post_type && 'publish' === $post->post_status && self::is_sample_returns( $post->post_content ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Another published page that looks like the store's real returns policy (title mentions refund or return).
	 *
	 * @param int $except The sample page.
	 * @return WP_Post|null
	 */
	public static function real_returns_page( $except ) {
		foreach ( get_pages( array( 'post_status' => 'publish', 'number' => 200 ) ) as $p ) {
			if ( (int) $p->ID !== (int) $except && preg_match( '/refund|return/i', $p->post_title . ' ' . $p->post_name ) && ! self::is_sample_returns( $p->post_content ) ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Free check (2.0.1): WooCommerce's sample returns page is not published. Local only; nothing leaves the site.
	 *
	 * @return array
	 */
	private static function check_returns_page() {
		$label  = __( 'No WooCommerce sample returns page published', 'leymish-ai-shopping-readiness' );
		$sample = self::sample_returns_page();
		if ( ! $sample ) {
			return self::check( 'returns', $label, 'pass', 1, __( 'WooCommerce\'s sample "Refund and Returns Policy" page is not published.', 'leymish-ai-shopping-readiness' ), '', 1 );
		}
		$real = self::real_returns_page( $sample );
		$url  = (string) get_permalink( $sample );
		return self::check(
			'returns',
			$label,
			'fail',
			0,
			/* translators: %s: address of the sample page. */
			sprintf( __( 'WooCommerce\'s sample "Refund and Returns Policy" is published at %s. It promises 30-day returns, which may not be your policy, and Google and AI shopping agents can read it.', 'leymish-ai-shopping-readiness' ), $url ),
			$real
				/* translators: 1: the real page's title, 2: its address. */
				? sprintf( __( 'Set the sample page to draft (Pages → edit it → Switch to draft). Then point WooCommerce\'s refund page setting and your footer or menu links at your real policy, "%1$s" (%2$s).', 'leymish-ai-shopping-readiness' ), wp_specialchars_decode( $real->post_title, ENT_QUOTES ), get_permalink( $real ) )
				: __( 'Replace the sample text with your real returns policy, or set the page to draft (Pages → edit it → Switch to draft).', 'leymish-ai-shopping-readiness' ),
			1
		);
	}

	/**
	 * Free check (2.1): the store can take an order (payment method, cart and checkout pages, shipping, no test mode,
	 * no competing card forms). Local only.
	 *
	 * @return array
	 */
	private static function check_checkout() {
		$label = __( 'The store can take an order', 'leymish-ai-shopping-readiness' );
		$items = class_exists( 'LASR_Health' ) ? LASR_Health::checkout() : array();
		if ( ! $items ) {
			return self::check( 'checkout', $label, 'pass', 1, __( 'A payment method is on, the cart and checkout pages are published, and shipping can be quoted.', 'leymish-ai-shopping-readiness' ), '', 1 );
		}
		$fail = 'fail' === $items[0]['level'];
		return self::check( 'checkout', $label, $fail ? 'fail' : 'warn', $fail ? 0 : 0.5, implode( ' ', wp_list_pluck( $items, 'title' ) ), implode( ' ', wp_list_pluck( $items, 'fix' ) ), 1 );
	}

	/**
	 * Information (2.1, not scored): products no post or page links to, and links to products that are gone.
	 *
	 * @return array
	 */
	private static function check_links() {
		$r = class_exists( 'LASR_Health' ) ? LASR_Health::links() : array( 'orphans' => array(), 'broken' => array(), 'posts' => 0 );
		return array(
			'id'     => 'links',
			'label'  => __( 'Internal links to your products', 'leymish-ai-shopping-readiness' ),
			'status' => 'info',
			'points' => 0,
			'earned' => 0,
			/* translators: 1: products without links, 2: broken links, 3: posts and pages checked. */
			'detail' => sprintf( __( '%1$d products have no link from your posts and pages; %2$d links point at products that are gone (%3$d posts and pages checked).', 'leymish-ai-shopping-readiness' ), count( $r['orphans'] ), count( $r['broken'] ), (int) $r['posts'] ),
			'fix'    => __( 'See "Store health" below the checks: the posts that already name a product are listed first.', 'leymish-ai-shopping-readiness' ),
			'effort' => 1,
		);
	}

	/**
	 * Build a check array.
	 *
	 * @param string $id       Check ID.
	 * @param string $label    Label.
	 * @param string $status   Status.
	 * @param float  $fraction Fraction of points earned.
	 * @param string $detail   What was found.
	 * @param string $fix      How to fix.
	 * @param int    $effort   1 easy … 3 hard.
	 * @return array
	 */
	private static function check( $id, $label, $status, $fraction, $detail, $fix, $effort ) {
		$points = isset( self::$points[ $id ] ) ? self::$points[ $id ] : 0;
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'points' => $points,
			'earned' => round( $points * max( 0, min( 1, $fraction ) ), 1 ),
			'detail' => $detail,
			'fix'    => $fix,
			'effort' => $effort,
		);
	}

	/**
	 * Can't test: excluded from the score, shown with the reason.
	 *
	 * @param string $id     Check ID.
	 * @param string $label  Label.
	 * @param string $reason Reason.
	 * @return array
	 */
	private static function skipped( $id, $label, $reason ) {
		return self::check(
			$id,
			$label,
			'skip',
			0,
			/* translators: %s: reason the check could not run. */
			sprintf( __( 'Could not test: %s', 'leymish-ai-shopping-readiness' ), $reason ),
			__( 'Your server could not request its own pages (a "loopback" request). Site Health (Tools → Site Health) shows whether loopback requests work.', 'leymish-ai-shopping-readiness' ),
			1
		);
	}

	/**
	 * The store is live to the public: not in WooCommerce's "coming soon" mode, and WordPress isn't set to
	 * discourage search engines. Either one hides products from every crawler, AI or not.
	 *
	 * @return array
	 */
	private static function check_public() {
		$coming_soon = 'yes' === get_option( 'woocommerce_coming_soon', 'no' );
		$discourage  = '0' === (string) get_option( 'blog_public', '1' );
		$problems    = array();
		if ( $coming_soon ) {
			$problems[] = __( 'WooCommerce "coming soon" mode is on, so visitors and crawlers see a placeholder page instead of your products', 'leymish-ai-shopping-readiness' );
		}
		if ( $discourage ) {
			$problems[] = __( 'Settings → Reading asks search engines not to index the site (noindex), which AI crawlers respect too', 'leymish-ai-shopping-readiness' );
		}
		return self::check(
			'public',
			__( 'Store is live to the public', 'leymish-ai-shopping-readiness' ),
			$problems ? 'fail' : 'pass',
			$problems ? 0 : 1,
			$problems ? implode( '; ', $problems ) . '.' : __( 'The store is live and open to search engines.', 'leymish-ai-shopping-readiness' ),
			__( 'Launch the store in WooCommerce → Settings → Site visibility (choose "Live"), and untick "Discourage search engines from indexing this site" in Settings → Reading. Several other checks can only pass once the store is live.', 'leymish-ai-shopping-readiness' ),
			1
		);
	}

	/**
	 * Robots.txt rules for the AI crawlers.
	 *
	 * @param string[] $sample Product URLs.
	 * @return array
	 */
	private static function check_robots( array $sample ) {
		$label = __( 'robots.txt allows AI shopping crawlers', 'leymish-ai-shopping-readiness' );
		$res   = self::get( home_url( '/robots.txt' ) );
		if ( 0 === $res['code'] ) {
			return self::skipped( 'robots', $label, $res['error'] );
		}
		$robots  = $res['ok'] ? $res['body'] : '';
		$path    = $sample ? (string) wp_parse_url( $sample[0], PHP_URL_PATH ) : '/';
		$blocked = array();
		$bots    = array_keys( LASR_Robots::ai_bots() );
		foreach ( $bots as $bot ) {
			if ( ! LASR_Robots::is_allowed( $robots, $bot, '' === $path ? '/' : $path ) ) {
				$blocked[] = $bot;
			}
		}
		$fraction = ( count( $bots ) - count( $blocked ) ) / count( $bots );
		$detail   = $blocked
			/* translators: %s: comma-separated crawler names. */
			? sprintf( __( 'Blocked for product pages: %s.', 'leymish-ai-shopping-readiness' ), implode( ', ', $blocked ) )
			/* translators: %d: number of AI crawlers checked. */
			: ( $res['ok'] ? sprintf( __( 'All %d AI crawlers may fetch product pages.', 'leymish-ai-shopping-readiness' ), count( $bots ) ) : __( 'No robots.txt, so nothing is blocked.', 'leymish-ai-shopping-readiness' ) );
		return self::check(
			'robots',
			$label,
			LASR_Scoring::status_for( $fraction ),
			$fraction,
			$detail,
			__( 'Remove the Disallow rules for these user agents in robots.txt (or in your SEO plugin\'s robots.txt editor). GPTBot, ClaudeBot and Google-Extended are about model training; OAI-SearchBot, Claude-SearchBot and PerplexityBot index pages for AI search answers, which is where shopping recommendations come from, so blocking those is what costs visibility. ChatGPT-User, Claude-User and Perplexity-User fetch a page when a person asks; the user-triggered ones may ignore robots.txt, but a firewall block still stops them (see the next check).', 'leymish-ai-shopping-readiness' ),
			1
		);
	}

	/**
	 * Request a product page with each AI bot's user agent and compare with a browser request.
	 *
	 * @param string[] $sample Product URLs.
	 * @return array
	 */
	private static function check_bot_blocks( array $sample ) {
		$label = __( 'AI bots are not blocked by a firewall, CDN or security plugin', 'leymish-ai-shopping-readiness' );
		if ( ! $sample ) {
			return self::skipped( 'bot_block', $label, __( 'no published product to test', 'leymish-ai-shopping-readiness' ) );
		}
		$url  = $sample[0];
		$base = self::get( $url, self::browser_ua() );
		if ( ! $base['ok'] ) {
			/* translators: %d: HTTP status code. */
			return self::skipped( 'bot_block', $label, $base['error'] ? $base['error'] : sprintf( __( 'the product page returned HTTP %d to a normal browser request', 'leymish-ai-shopping-readiness' ), $base['code'] ) );
		}
		$blocked = array();
		$tested  = 0;
		foreach ( LASR_Robots::ai_bots() as $bot => $ua ) {
			if ( '' === $ua ) {
				continue;
			}
			++$tested;
			$r = self::get( $url, $ua );
			if ( ! $r['ok'] || self::looks_like_challenge( $r['body'] ) ) {
				/* translators: 1: crawler name, 2: HTTP status or "challenge page". */
				$blocked[] = sprintf( __( '%1$s (%2$s)', 'leymish-ai-shopping-readiness' ), $bot, $r['code'] ? 'HTTP ' . $r['code'] : $r['error'] );
			}
		}
		$fraction = $tested ? ( $tested - count( $blocked ) ) / $tested : 1;
		$detail   = $blocked
			/* translators: %s: list of crawlers with their HTTP status. */
			? sprintf( __( 'Possible block: a normal browser gets the page, but these user agents did not: %s.', 'leymish-ai-shopping-readiness' ), implode( ', ', $blocked ) )
			/* translators: %d: number of crawler user agents tested. */
			: sprintf( __( 'All %d AI crawler user agents received the product page.', 'leymish-ai-shopping-readiness' ), $tested );
		return self::check(
			'bot_block',
			$label,
			LASR_Scoring::status_for( $fraction ),
			$fraction,
			$detail,
			__( 'Check your security plugin (bot blocking, "block AI crawlers" settings) and your CDN or firewall (for example Cloudflare\'s AI bot or bot-fight settings). Note: this test comes from your own server, so a CDN that verifies bots by IP address may block the test while still allowing the real crawler. Confirm in your CDN\'s logs or settings before changing anything.', 'leymish-ai-shopping-readiness' ),
			2
		);
	}

	/**
	 * Common bot-challenge page markers (Cloudflare and similar).
	 *
	 * @param string $body HTML.
	 * @return bool
	 */
	private static function looks_like_challenge( $body ) {
		foreach ( array( 'cf-chl-', 'challenge-platform', 'Just a moment...', 'Attention Required! | Cloudflare' ) as $needle ) {
			if ( false !== strpos( $body, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Product JSON-LD on real product pages.
	 *
	 * @param array $pages Fetched pages.
	 * @return array
	 */
	private static function check_jsonld( array $pages ) {
		$label = __( 'Product structured data (JSON-LD) on product pages', 'leymish-ai-shopping-readiness' );
		$ok    = array_filter(
			$pages,
			function ( $p ) {
				return $p['ok'];
			}
		);
		if ( ! $ok ) {
			return self::skipped( 'jsonld', $label, __( 'no product page could be fetched', 'leymish-ai-shopping-readiness' ) );
		}
		$total    = 0.0;
		$problems = array();
		foreach ( $ok as $url => $page ) {
			$nodes = LASR_JSONLD::products( $page['body'] );
			$slug  = basename( untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			if ( ! $nodes ) {
				/* translators: %s: product URL slug. */
				$problems[] = sprintf( __( '%s: no Product JSON-LD', 'leymish-ai-shopping-readiness' ), $slug );
				continue;
			}
			$c = LASR_JSONLD::check( $nodes[0] );
			if ( $c['missing_required'] ) {
				/* translators: 1: product URL slug, 2: missing fields. */
				$problems[] = sprintf( __( '%1$s: missing %2$s', 'leymish-ai-shopping-readiness' ), $slug, implode( ', ', $c['missing_required'] ) );
				$total     += 0.25;
			} elseif ( $c['missing_recommended'] ) {
				/* translators: 1: product URL slug, 2: missing recommended fields. */
				$problems[] = sprintf( __( '%1$s: recommended fields missing: %2$s', 'leymish-ai-shopping-readiness' ), $slug, implode( ', ', $c['missing_recommended'] ) );
				$total     += 0.75;
			} else {
				$total += 1;
			}
		}
		$fraction = $total / count( $ok );
		return self::check(
			'jsonld',
			$label,
			LASR_Scoring::status_for( $fraction ),
			$fraction,
			$problems
				? implode( '; ', $problems ) . '.'
				/* translators: %d: number of pages checked. */
				: sprintf( __( 'Valid on all %d product pages checked, including an identifier and brand.', 'leymish-ai-shopping-readiness' ), count( $ok ) ),
			__( 'WooCommerce outputs Product JSON-LD by default; a theme or plugin may have removed it. GTIN/MPN and brand appear only when the product has them, so filling product data fixes most warnings. Check a page with Google\'s Rich Results Test.', 'leymish-ai-shopping-readiness' ),
			2
		);
	}

	/**
	 * Product name and price appear in the HTML without running JavaScript.
	 *
	 * @param array $pages Fetched pages.
	 * @return array
	 */
	private static function check_no_js( array $pages ) {
		$label = __( 'Product name and price readable without JavaScript', 'leymish-ai-shopping-readiness' );
		$ok    = array_filter(
			$pages,
			function ( $p ) {
				return $p['ok'];
			}
		);
		if ( ! $ok ) {
			return self::skipped( 'no_js', $label, __( 'no product page could be fetched', 'leymish-ai-shopping-readiness' ) );
		}
		$good   = 0;
		$failed = array();
		foreach ( $ok as $url => $page ) {
			$post_id = url_to_postid( $url );
			$product = $post_id ? wc_get_product( $post_id ) : null;
			if ( ! $product ) {
				continue;
			}
			$text      = html_entity_decode( wp_strip_all_tags( preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $page['body'] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$has_name  = false !== stripos( $text, html_entity_decode( $product->get_name(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$price     = (string) $product->get_price();
			$has_price = '' === $price || false !== strpos( str_replace( array( ',', ' ' ), '', $text ), (string) (float) $price )
				|| false !== strpos( $text, wc_format_decimal( $price, wc_get_price_decimals() ) );
			if ( $has_name && $has_price ) {
				++$good;
			} else {
				$failed[] = $product->get_name();
			}
		}
		$n        = count( $ok );
		$fraction = $n ? $good / $n : 0;
		return self::check(
			'no_js',
			$label,
			LASR_Scoring::status_for( $fraction ),
			$fraction,
			$failed
				/* translators: %s: product names. */
				? sprintf( __( 'Name or price only appears after JavaScript runs on: %s.', 'leymish-ai-shopping-readiness' ), implode( ', ', $failed ) )
				/* translators: %d: number of pages. */
				: sprintf( __( 'Name and price are in the page HTML on all %d pages checked.', 'leymish-ai-shopping-readiness' ), $n ),
			__( 'Most AI crawlers do not run JavaScript. If your theme or page builder loads the title or price with JavaScript, switch that block to server-rendered output.', 'leymish-ai-shopping-readiness' ),
			3
		);
	}

	/**
	 * WooCommerce Store API responds (agents and headless tools use it to read products).
	 *
	 * @return array
	 */
	private static function check_store_api() {
		$label = __( 'WooCommerce Store API reachable', 'leymish-ai-shopping-readiness' );
		$res   = self::get( rest_url( 'wc/store/v1/products?per_page=1' ) );
		if ( 0 === $res['code'] ) {
			return self::skipped( 'store_api', $label, $res['error'] );
		}
		$bom  = LASR_JSONLD::has_bom( $res['body'] );
		$data = json_decode( $bom ? substr( $res['body'], 3 ) : $res['body'], true );
		$good = $res['ok'] && is_array( $data );
		if ( $good && $bom ) {
			return self::check(
				'store_api',
				$label,
				'warn',
				0.5,
				__( 'The Store API answers, but every response starts with an invisible byte-order mark (BOM). Browsers ignore it; strict JSON parsers, which many AI agents and feed tools use, reject the response.', 'leymish-ai-shopping-readiness' ),
				__( 'A PHP file in your theme or a plugin was saved as "UTF-8 with BOM". Re-save it as UTF-8 without BOM. To find it, open each custom PHP file\'s URL directly: files that stop early print nothing, so the one with the BOM returns exactly 3 bytes.', 'leymish-ai-shopping-readiness' ),
				2
			);
		}
		if ( $res['ok'] && ! $good ) {
			return self::check(
				'store_api',
				$label,
				'fail',
				0,
				/* translators: %d: HTTP status code. */
				sprintf( __( 'The Store API answered HTTP %d, but not with JSON.', 'leymish-ai-shopping-readiness' ), $res['code'] ),
				__( 'Something is adding output to REST responses (a PHP notice, whitespace or HTML from a theme or plugin). Turn off display_errors on the live site and check recently changed plugins.', 'leymish-ai-shopping-readiness' ),
				2
			);
		}
		return self::check(
			'store_api',
			$label,
			$good ? 'pass' : 'fail',
			$good ? 1 : 0,
			/* translators: %d: HTTP status code. */
			$good ? __( 'Public product endpoint answers with JSON.', 'leymish-ai-shopping-readiness' ) : sprintf( __( 'The Store API returned HTTP %d.', 'leymish-ai-shopping-readiness' ), $res['code'] ),
			__( 'A security plugin or custom code is blocking /wp-json/wc/store/. Allow public GET requests to the Store API products endpoints.', 'leymish-ai-shopping-readiness' ),
			2
		);
	}

	/**
	 * An llms.txt file at the site root (llmstxt.org).
	 *
	 * @return array
	 */
	private static function check_llms_txt() {
		$label = __( 'llms.txt present', 'leymish-ai-shopping-readiness' );
		$res   = self::get( home_url( '/llms.txt' ) );
		if ( 0 === $res['code'] ) {
			return self::skipped( 'llms_txt', $label, $res['error'] );
		}
		$body   = ltrim( $res['body'] );
		$is_txt = $res['ok'] && '' !== $body && 0 === strpos( $body, '#' ) && false === stripos( $body, '<html' );
		return self::check(
			'llms_txt',
			$label,
			$is_txt ? 'pass' : 'fail',
			$is_txt ? 1 : 0,
			$is_txt ? __( '/llms.txt is served as Markdown.', 'leymish-ai-shopping-readiness' ) : __( 'No /llms.txt (or it returns an HTML page instead of Markdown).', 'leymish-ai-shopping-readiness' ),
			__( 'Add a Markdown file at /llms.txt that starts with "# Your store name", a one-line summary, and links to your main category and policy pages (see llmstxt.org). It is a new, lightly used convention, so it carries few points.', 'leymish-ai-shopping-readiness' ),
			1
		);
	}

	/**
	 * Guest checkout on (agents usually can't create accounts).
	 *
	 * @return array
	 */
	private static function check_guest_checkout() {
		$on = 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'yes' );
		return self::check(
			'guest',
			__( 'Guest checkout enabled', 'leymish-ai-shopping-readiness' ),
			$on ? 'pass' : 'fail',
			$on ? 1 : 0,
			$on ? __( 'Shoppers can check out without an account.', 'leymish-ai-shopping-readiness' ) : __( 'Checkout requires an account.', 'leymish-ai-shopping-readiness' ),
			__( 'WooCommerce → Settings → Accounts & Privacy → "Allow customers to place orders without an account". Buying on a shopper\'s behalf is much harder when an account is required.', 'leymish-ai-shopping-readiness' ),
			1
		);
	}

	/**
	 * Agentic-commerce protocol versions we check against (P-023 §4.1).
	 *
	 * Both specs are Apache-2.0 and both move. Pinning the version we checked means a store owner can tell
	 * whether a pass is still current, and we can tell which spec a past audit was judged by.
	 *
	 * - UCP, Universal Commerce Protocol (Google and others): https://ucp.dev — profile at /.well-known/ucp.
	 * - ACP, Agentic Commerce Protocol (OpenAI and Stripe): https://agenticcommerce.dev — merchant REST API.
	 *
	 * @return array{ucp:string,acp:string,checked:string}
	 */
	private static function protocol_versions() {
		return array(
			'ucp'     => '2026-08-25',  // ucp.dev latest specification at the time of writing (re-read 2026-10-08)
			'acp'     => '2026-04-17',  // latest stable spec folder in the ACP repository
			'checked' => '2026-10-08',  // when we last read both specs
		);
	}

	/**
	 * A UCP business profile at /.well-known/ucp (ucp.dev).
	 *
	 * The spec requires a JSON object with a `ucp` member holding `version`, `services` and `payment_handlers`
	 * — the last two must be present even when empty. `capabilities` is optional. Capability and service names
	 * use reverse-domain naming (`dev.ucp.shopping.checkout`), each capability declares a `schema`, and an
	 * `endpoint` must be https without a trailing slash. We check the shape, not the behaviour: a profile that
	 * parses and carries the required members is the part a store owner controls.
	 *
	 * @return array
	 */
	private static function check_ucp() {
		$v     = self::protocol_versions();
		$label = __( 'UCP business profile at /.well-known/ucp', 'leymish-ai-shopping-readiness' );
		$res   = self::get( home_url( '/.well-known/ucp' ) );
		if ( 0 === $res['code'] ) {
			return self::skipped( 'ucp', $label, $res['error'] );
		}
		$data     = json_decode( $res['body'], true );
		$ucp      = is_array( $data ) && isset( $data['ucp'] ) && is_array( $data['ucp'] ) ? $data['ucp'] : null;
		$problems = array();

		if ( ! $res['ok'] || null === $ucp ) {
			$problems[] = __( 'no profile that parses as JSON with a "ucp" member', 'leymish-ai-shopping-readiness' );
		} else {
			foreach ( array( 'version', 'services', 'payment_handlers' ) as $required ) {
				if ( ! isset( $ucp[ $required ] ) ) {
					/* translators: %s: the missing field name. */
					$problems[] = sprintf( __( 'ucp.%s is missing (it is required even when empty)', 'leymish-ai-shopping-readiness' ), $required );
				}
			}
			foreach ( array_keys( isset( $ucp['capabilities'] ) && is_array( $ucp['capabilities'] ) ? $ucp['capabilities'] : array() ) as $name ) {
				if ( ! preg_match( '/^[a-z0-9-]+(\.[a-z0-9_-]+){2,}$/', (string) $name ) ) {
					/* translators: %s: the capability name that is not reverse-domain. */
					$problems[] = sprintf( __( 'capability "%s" is not a reverse-domain name like dev.ucp.shopping.checkout', 'leymish-ai-shopping-readiness' ), sanitize_text_field( (string) $name ) );
				}
			}
			foreach ( ( isset( $ucp['services'] ) && is_array( $ucp['services'] ) ? $ucp['services'] : array() ) as $service ) {
				$endpoint = is_array( $service ) && isset( $service['endpoint'] ) ? (string) $service['endpoint'] : '';
				if ( '' !== $endpoint && 0 !== strpos( $endpoint, 'https://' ) ) {
					$problems[] = __( 'a service endpoint is not https', 'leymish-ai-shopping-readiness' );
				} elseif ( '' !== $endpoint && '/' === substr( $endpoint, -1 ) ) {
					$problems[] = __( 'a service endpoint ends in a slash (the spec says it should not)', 'leymish-ai-shopping-readiness' );
				}
			}
		}

		$valid   = empty( $problems );
		$version = $valid && isset( $ucp['version'] ) ? sanitize_text_field( (string) $ucp['version'] ) : '';
		// 2.0: a valid profile that declares no services is honest (no UCP checkout yet) but only half the way there.
		$empty = $valid && empty( $ucp['services'] );
		if ( $empty ) {
			return self::check(
				'ucp',
				$label,
				'warn',
				0.5,
				/* translators: 1: the store's UCP version, 2: the spec version we checked against. */
				sprintf( __( 'Valid UCP profile (version %1$s) that declares no checkout services yet, so agents can find you but not check out through UCP. Checked against the UCP specification of %2$s.', 'leymish-ai-shopping-readiness' ), $version, $v['ucp'] ),
				__( 'Your profile is in place. The other half needs a checkout integration that implements UCP and adds its service to the profile. WooCommerce core does not have one yet, so this is worth watching, not urgent.', 'leymish-ai-shopping-readiness' ),
				3
			) + array( 'watch' => true );
		}
		return self::check(
			'ucp',
			$label,
			$valid ? 'pass' : ( $res['ok'] ? 'warn' : 'fail' ),
			$valid ? 1 : 0,
			$valid
				/* translators: 1: the store's UCP version, 2: the spec version we checked against. */
				? sprintf( __( 'Valid UCP profile (version %1$s). Checked against the UCP specification of %2$s.', 'leymish-ai-shopping-readiness' ), $version, $v['ucp'] )
				/* translators: 1: what is wrong, 2: the spec version we checked against. */
				: sprintf( __( '%1$s. Checked against the UCP specification of %2$s. Few WooCommerce stores have a profile yet.', 'leymish-ai-shopping-readiness' ), implode( '; ', array_slice( $problems, 0, 3 ) ), $v['ucp'] ),
			__( 'The Universal Commerce Protocol (ucp.dev, Apache-2.0) lets an AI agent discover what your checkout can do. The profile is a JSON file at /.well-known/ucp whose "ucp" member has version, services and payment_handlers. WooCommerce core does not publish one yet, so today it needs a checkout integration that implements UCP. Worth watching, not urgent.', 'leymish-ai-shopping-readiness' ),
			3
		);
	}

	/**
	 * Agentic Commerce Protocol readiness: information only, doesn't affect the score.
	 *
	 * ACP (OpenAI and Stripe, Apache-2.0) is a merchant REST API: POST /checkout_sessions and friends, every
	 * request authenticated and carrying an `API-Version` header. We deliberately do **not** probe it from
	 * outside — it is authenticated, and firing unauthenticated POSTs at a stranger's checkout is not a thing
	 * an audit should do. What we can honestly report from inside WordPress is whether anything on this site
	 * has registered an ACP-shaped route, and what the current spec version is.
	 *
	 * @return array
	 */
	private static function check_acp() {
		$v     = self::protocol_versions();
		$found = array();
		if ( function_exists( 'rest_get_server' ) ) {
			foreach ( array_keys( (array) rest_get_server()->get_routes() ) as $route ) {
				if ( false !== strpos( (string) $route, 'checkout_sessions' ) || false !== strpos( (string) $route, 'checkout-sessions' ) ) {
					$found[] = (string) $route;
				}
			}
		}
		return array(
			'id'     => 'acp',
			'label'  => __( 'ACP checkout (ChatGPT Instant Checkout)', 'leymish-ai-shopping-readiness' ),
			'status' => 'info',
			'points' => 0,
			'earned' => 0,
			'detail' => $found
				/* translators: 1: the route found, 2: the ACP spec version. */
				? sprintf( __( 'A checkout-session route is registered (%1$s). ACP specification of %2$s. We do not test it: it is authenticated, so only you can.', 'leymish-ai-shopping-readiness' ), sanitize_text_field( $found[0] ), $v['acp'] )
				/* translators: %s: the ACP spec version. */
				: sprintf( __( 'No ACP checkout route on this site. The Agentic Commerce Protocol (specification of %s) is how ChatGPT completes a purchase on your store; today it comes from your payment provider, not from WooCommerce core.', 'leymish-ai-shopping-readiness' ), $v['acp'] ),
			'fix'    => '',
			'effort' => 3,
		);
	}

	/**
	 * WooCommerce MCP availability: information only, doesn't affect the score.
	 *
	 * @return array
	 */
	private static function check_mcp() {
		$version   = defined( 'WC_VERSION' ) ? WC_VERSION : '0';
		$available = version_compare( $version, '10.3', '>=' );
		return array(
			'id'     => 'mcp',
			'label'  => __( 'WooCommerce MCP (store-management AI assistants)', 'leymish-ai-shopping-readiness' ),
			'status' => 'info',
			'points' => 0,
			'earned' => 0,
			'detail' => $available
				/* translators: %s: WooCommerce version. */
				? sprintf( __( 'WooCommerce %s includes MCP (developer preview). It lets AI assistants manage your store; it does not make products visible to shoppers, so it is not scored.', 'leymish-ai-shopping-readiness' ), $version )
				/* translators: %s: WooCommerce version. */
				: sprintf( __( 'WooCommerce %s: MCP arrives in 10.3. It lets AI assistants manage your store; it does not affect shopper visibility, so it is not scored.', 'leymish-ai-shopping-readiness' ), $version ),
			'fix'    => '',
			'effort' => 1,
		);
	}
}
