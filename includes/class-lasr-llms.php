<?php
/**
 * Serve a generated /llms.txt (llmstxt.org) when enabled and no physical file exists.
 *
 * Free since 2.0 (it runs on this site). Off until the owner switches it on in the Feeds tab.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generator and server.
 */
class LASR_Llms {

	const OPTION = 'lasr_llms_enabled';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical_redirect' ) );
	}

	/**
	 * Route /llms.txt to WordPress (a real file at the web root still wins, as it should).
	 */
	public static function rewrite_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?lasr_llms=1', 'top' );
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
		return get_query_var( 'lasr_llms' ) ? false : $redirect;
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'lasr_llms';
		return $vars;
	}

	/**
	 * Whether the generated file is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return 'yes' === get_option( self::OPTION, 'no' );
	}

	/**
	 * Send the file.
	 */
	public static function serve() {
		if ( ! get_query_var( 'lasr_llms' ) || ! self::enabled() ) {
			return;
		}
		header( 'Content-Type: text/markdown; charset=utf-8' );
		echo self::content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text Markdown document, values sanitised in content().
		exit;
	}

	/**
	 * Build the Markdown: store name, summary, shop and category links, policies, feeds.
	 *
	 * @return string
	 */
	public static function content() {
		$name  = wp_strip_all_tags( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$about = wp_strip_all_tags( wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ) );
		$lines = array( '# ' . $name, '' );
		if ( '' !== $about ) {
			$lines[] = '> ' . $about;
			$lines[] = '';
		}
		$shop_id = wc_get_page_id( 'shop' );
		$lines[] = '## Shop';
		$lines[] = '';
		if ( $shop_id > 0 ) {
			$lines[] = '- [All products](' . esc_url_raw( get_permalink( $shop_id ) ) . ')';
		}
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 15,
			)
		);
		if ( is_array( $cats ) ) {
			foreach ( $cats as $cat ) {
				$link = get_term_link( $cat );
				if ( ! is_wp_error( $link ) ) {
					/* translators: %d: number of products. */
					$lines[] = '- [' . wp_strip_all_tags( $cat->name ) . '](' . esc_url_raw( $link ) . '): ' . sprintf( _n( '%d product', '%d products', (int) $cat->count, 'leymish-ai-shopping-readiness' ), (int) $cat->count );
				}
			}
		}
		$policies = array(
			__( 'Privacy policy', 'leymish-ai-shopping-readiness' ) => (int) get_option( 'wp_page_for_privacy_policy' ),
			__( 'Terms and conditions', 'leymish-ai-shopping-readiness' ) => wc_terms_and_conditions_page_id(),
			__( 'Refund and returns policy', 'leymish-ai-shopping-readiness' ) => (int) get_option( 'woocommerce_refund_returns_page_id' ),
		);
		$policy_lines = array();
		foreach ( $policies as $label => $id ) {
			if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
				$policy_lines[] = '- [' . $label . '](' . esc_url_raw( get_permalink( $id ) ) . ')';
			}
		}
		if ( $policy_lines ) {
			$lines[] = '';
			$lines[] = '## Policies';
			$lines[] = '';
			$lines   = array_merge( $lines, $policy_lines );
		}
		$lines[] = '';
		$lines[] = '## Machine-readable product data';
		$lines[] = '';
		$lines[] = '- [OpenAI product feed (JSONL)](' . esc_url_raw( LASR_Feeds::url( 'openai.jsonl' ) ) . ')';
		$lines[] = '- [Google Merchant Center feed (TSV)](' . esc_url_raw( LASR_Feeds::url( 'google.tsv' ) ) . ')';
		$lines[] = '- [WooCommerce Store API products](' . esc_url_raw( rest_url( 'wc/store/v1/products' ) ) . ')';
		if ( class_exists( 'LASR_Ucp' ) && LASR_Ucp::enabled() ) {
			$lines[] = '- [UCP business profile](' . esc_url_raw( home_url( '/.well-known/ucp' ) ) . ')';
		}
		return implode( "\n", $lines ) . "\n";
	}
}
