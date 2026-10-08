<?php
/**
 * The AI fixes tab (a LeyMish service): drafts for brand-free product data (attributes, a spec-first description,
 * a Google category, image alt text) written only from the product's own page, shown as Now vs Proposed, and saved
 * only when the owner approves. Sends only the product the owner clicks, only after the consent box is ticked.
 * Never writes a GTIN, EAN, UPC, MPN or ISBN. Free stores get 20 fixes to try; LeyMish Pro gets 500 a month.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI fixes: settings, AJAX routes and the tab.
 */
class LASR_AI {

	const OPTION = 'lasr_ai';
	const NONCE  = 'lasr_ai';

	/**
	 * Hooks (admin only).
	 */
	public static function init() {
		add_action( 'admin_post_lasr_ai_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'wp_ajax_lasr_ai_suggest', array( __CLASS__, 'ajax_suggest' ) );
		add_action( 'wp_ajax_lasr_ai_apply', array( __CLASS__, 'ajax_apply' ) );
	}

	/**
	 * Consent (stored on this site).
	 *
	 * @return bool
	 */
	public static function consent() {
		$s = get_option( self::OPTION, array() );
		return ! empty( $s['consent'] );
	}

	/**
	 * Strings for the script.
	 *
	 * @return array
	 */
	public static function script_data() {
		return array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE ),
			'i18n'  => array(
				'working' => __( 'Drafting from the product\'s own page…', 'leymish-ai-shopping-readiness' ),
				'apply'   => __( 'Approve and save', 'leymish-ai-shopping-readiness' ),
				'cancel'  => __( 'Discard', 'leymish-ai-shopping-readiness' ),
				'saved'   => __( 'Saved. Check the product page.', 'leymish-ai-shopping-readiness' ),
				'current' => __( 'Now', 'leymish-ai-shopping-readiness' ),
				'suggest' => __( 'Proposed', 'leymish-ai-shopping-readiness' ),
				'none'    => __( 'No proposal: nothing on the product\'s page supports one.', 'leymish-ai-shopping-readiness' ),
			),
		);
	}

	/**
	 * Save the consent box.
	 */
	public static function handle_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_ai_settings' );
		update_option( self::OPTION, array( 'consent' => ! empty( $_POST['lasr_ai_consent'] ) ), false );
		delete_transient( 'lasr_ai_usage' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=fixes&lasr_msg=saved' ) );
		exit;
	}

	/**
	 * Fixes used and left (cached 5 minutes). Asked only after consent.
	 *
	 * @return array
	 */
	public static function usage() {
		$u = get_transient( 'lasr_ai_usage' );
		if ( is_array( $u ) ) {
			return $u;
		}
		$body = array( 'site' => LASR_Service::site() );
		if ( LASR_License::has_licence() ) {
			$body['license_key'] = LASR_License::key();
		}
		$r = LASR_Service::post( '/v1/usage', $body );
		$u = 200 === $r['status'] ? $r['data'] : array( 'error' => isset( $r['data']['error'] ) ? (string) $r['data']['error'] : '' );
		set_transient( 'lasr_ai_usage', $u, 5 * MINUTE_IN_SECONDS );
		return $u;
	}

	/**
	 * The product fields the service may receive, for one product.
	 *
	 * @param WC_Product $p Product.
	 * @return array
	 */
	private static function product_data( $p ) {
		$attrs = array();
		foreach ( $p->get_attributes() as $a ) {
			$name           = $a->is_taxonomy() ? wc_attribute_label( $a->get_name() ) : $a->get_name();
			$values         = $a->is_taxonomy() ? wc_get_product_terms( $p->get_id(), $a->get_name(), array( 'fields' => 'names' ) ) : $a->get_options();
			$attrs[ $name ] = implode( ', ', (array) $values );
		}
		$cats = wc_get_product_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$img  = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'large' ) : '';
		return array(
			'name'              => $p->get_name(),
			'description'       => $p->get_description(),
			'short_description' => $p->get_short_description(),
			'attributes'        => $attrs,
			'categories'        => is_array( $cats ) ? $cats : array(),
			'image_url'         => $img ? $img : '',
			'image_alt'         => $p->get_image_id() ? (string) get_post_meta( $p->get_image_id(), '_wp_attachment_image_alt', true ) : '',
			'google_category'   => (string) $p->get_meta( '_lasr_google_product_category' ),
		);
	}

	/**
	 * Common AJAX guard; returns the product.
	 *
	 * @return WC_Product
	 */
	private static function ajax_guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) ), 403 );
		}
		$p = wc_get_product( isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0 );
		if ( ! $p ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'leymish-ai-shopping-readiness' ) ), 404 );
		}
		return $p;
	}

	/**
	 * Ask for one proposal. Nothing is saved here.
	 */
	public static function ajax_suggest() {
		$p    = self::ajax_guard();
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		if ( ! self::consent() || ! in_array( $kind, LASR_AI_Logic::KINDS, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Tick the consent box above first.', 'leymish-ai-shopping-readiness' ) ), 400 );
		}
		$data = self::product_data( $p );
		$key  = LASR_License::has_licence() ? LASR_License::key() : '';
		$r    = LASR_Service::post( '/v1/fix', LASR_AI_Logic::payload( $kind, $data, LASR_Service::site(), $key, LASR_VERSION ), '', 45 );
		delete_transient( 'lasr_ai_usage' );
		if ( 200 !== $r['status'] || empty( $r['data']['ok'] ) ) {
			$msg = isset( $r['data']['error'] ) ? (string) $r['data']['error'] : __( 'No proposal this time.', 'leymish-ai-shopping-readiness' );
			wp_send_json_error(
				array(
					'message'   => $msg,
					'remaining' => isset( $r['data']['remaining'] ) ? (int) $r['data']['remaining'] : null,
				),
				$r['status'] ? $r['status'] : 502
			);
		}
		$current = array(
			'attributes'  => array( 'attributes' => $data['attributes'] ),
			'description' => array(
				'description'       => $data['description'],
				'short_description' => $data['short_description'],
			),
			'category'    => array(
				'categories' => $data['categories'],
				'google'     => $data['google_category'],
			),
			'alt_text'    => array(
				'alt_text' => $data['image_alt'],
				'image'    => $data['image_url'],
			),
		);
		wp_send_json_success(
			array(
				'kind'       => $kind,
				'current'    => $current[ $kind ],
				'suggestion' => LASR_AI_Logic::clean( $kind, isset( $r['data']['suggestion'] ) ? $r['data']['suggestion'] : array() ),
				'remaining'  => isset( $r['data']['remaining'] ) ? (int) $r['data']['remaining'] : null,
			)
		);
	}

	/**
	 * Save what the owner approved.
	 */
	public static function ajax_apply() {
		$p        = self::ajax_guard();
		$kind     = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$accepted = isset( $_POST['accepted'] ) ? json_decode( wp_unslash( $_POST['accepted'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by LASR_AI_Logic::clean().
		$clean    = LASR_AI_Logic::clean( $kind, $accepted );
		switch ( $kind ) {
			case 'attributes':
				$attrs = $p->get_attributes();
				$have  = array();
				foreach ( $attrs as $a ) {
					$have[] = strtolower( $a->is_taxonomy() ? wc_attribute_label( $a->get_name() ) : $a->get_name() );
				}
				foreach ( $clean['attributes'] as $row ) {
					if ( in_array( strtolower( $row['name'] ), $have, true ) ) {
						continue; // never overwrite an attribute the owner already set
					}
					$a = new WC_Product_Attribute();
					$a->set_name( $row['name'] );
					$a->set_options( array( $row['value'] ) );
					$a->set_visible( true );
					$a->set_variation( false );
					$attrs[] = $a;
				}
				$p->set_attributes( $attrs );
				break;
			case 'description':
				if ( '' !== $clean['description'] ) {
					$p->set_description( wp_kses_post( $clean['description'] ) );
				}
				if ( '' !== $clean['short_description'] ) {
					$p->set_short_description( wp_kses_post( $clean['short_description'] ) );
				}
				break;
			case 'category':
				if ( ! empty( $clean['categories'][0]['path'] ) ) {
					$p->update_meta_data( '_lasr_google_product_category', $clean['categories'][0]['path'] );
				}
				break;
			case 'alt_text':
				if ( $p->get_image_id() && '' !== $clean['alt_text'] ) {
					update_post_meta( $p->get_image_id(), '_wp_attachment_image_alt', $clean['alt_text'] );
				}
				break;
			default:
				wp_send_json_error( array( 'message' => 'kind' ), 400 );
		}
		$p->save();
		LASR_Worklog::log( $kind, $p->get_id() );
		wp_send_json_success( array( 'saved' => true ) );
	}

	/**
	 * Counts for this store (local, for the preview): products whose text could use each kind of fix.
	 *
	 * @param array|null $result Last audit.
	 * @return array{alt:int,attributes:int,description:int,total:int}
	 */
	public static function local_counts( $result ) {
		$out = array(
			'alt'         => 0,
			'attributes'  => 0,
			'description' => 0,
			'total'       => 0,
		);
		foreach ( (array) ( $result ? $result['products'] : array() ) as $r ) {
			$m = (array) $r['missing'];
			$p = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $r['id'] ) : null;
			if ( $p && $p->get_image_id() && '' === trim( (string) get_post_meta( $p->get_image_id(), '_wp_attachment_image_alt', true ) ) ) {
				$m[] = 'alt';
			}
			$out['alt']         += in_array( 'alt', $m, true ) ? 1 : 0;
			$out['attributes']  += in_array( 'attributes', $m, true ) ? 1 : 0;
			$out['description'] += in_array( 'description', $m, true ) ? 1 : 0;
			$out['total']       += array_intersect( array( 'alt', 'attributes', 'description' ), $m ) ? 1 : 0;
		}
		return $out;
	}

	/**
	 * The tab.
	 *
	 * @param array|null $result Last audit.
	 */
	public static function render( $result ) {
		$counts  = self::local_counts( $result );
		$consent = self::consent();
		$log     = LASR_Worklog::worklog();
		$applied = 0;
		foreach ( array( 'attributes', 'description', 'category', 'alt_text' ) as $k ) {
			$applied += isset( $log['counts'][ $k ] ) ? (int) $log['counts'][ $k ] : 0;
		}
		LASR_Admin::three(
			/* translators: %d: products that could use an AI fix. */
			$counts['total'] ? sprintf( _n( '%d product is missing alt text, attributes or a clear description.', '%d products are missing alt text, attributes or a clear description.', $counts['total'], 'leymish-ai-shopping-readiness' ), $counts['total'] ) : __( 'No product is missing alt text, attributes or a description.', 'leymish-ai-shopping-readiness' ),
			/* translators: %d: AI fixes applied. */
			$applied ? sprintf( _n( '%d AI fix approved and saved.', '%d AI fixes approved and saved.', $applied, 'leymish-ai-shopping-readiness' ), $applied ) : __( 'No AI fixes applied yet.', 'leymish-ai-shopping-readiness' ),
			$consent ? __( 'Pick a product below and draft a fix.', 'leymish-ai-shopping-readiness' ) : __( 'Tick the consent box to start.', 'leymish-ai-shopping-readiness' ),
			null
		);
		echo '<p class="lasr-lede">' . esc_html__( 'AI fixes draft missing attributes, image alt text, a Google product category and a clearer description, using only what your product\'s own page says. You see Now and Proposed side by side, and nothing is saved until you approve. They never create barcodes or part numbers.', 'leymish-ai-shopping-readiness' ) . '</p>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['lasr_msg'] ) ) {
			echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html__( 'Settings saved.', 'leymish-ai-shopping-readiness' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-card-box">';
		wp_nonce_field( 'lasr_ai_settings' );
		echo '<input type="hidden" name="action" value="lasr_ai_settings" /><p><label><input type="checkbox" name="lasr_ai_consent" value="1" ' . checked( $consent, true, false ) . ' /> ';
		echo esc_html__( 'Send the product I click (name, descriptions, attributes, categories and main image address) to LeyMish to draft a fix.', 'leymish-ai-shopping-readiness' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Only the product you click is sent, and only when you click. No customer, order or personal data is ever sent. LeyMish keeps counters, not your product text, and the AI provider does not train on it.', 'leymish-ai-shopping-readiness' ) . '</p>';
		submit_button( __( 'Save', 'leymish-ai-shopping-readiness' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( ! $consent ) {
			self::preview( $counts );
			return;
		}
		$u = self::usage();
		if ( isset( $u['remaining'] ) ) {
			$pct = (int) $u['limit'] > 0 ? (int) min( 100, round( 100 * (int) $u['used'] / (int) $u['limit'] ) ) : 0;
			echo '<div class="lasr-meter-wrap"><div class="lasr-bar lasr-meter" role="meter" aria-label="' . esc_attr__( 'AI fixes used', 'leymish-ai-shopping-readiness' ) . '" aria-valuemin="0" aria-valuemax="' . esc_attr( (string) (int) $u['limit'] ) . '" aria-valuenow="' . esc_attr( (string) (int) $u['used'] ) . '"><span style="width:' . esc_attr( (string) $pct ) . '%"></span></div>';
			/* translators: 1: fixes left, 2: fixes allowed. */
			echo '<p><strong>' . esc_html( sprintf( __( '%1$d of %2$d fixes left.', 'leymish-ai-shopping-readiness' ), (int) $u['remaining'], (int) $u['limit'] ) ) . '</strong></p></div>';
			$note = LASR_AI_Logic::upgrade_note( (int) $u['used'], (int) $u['limit'], $counts['total'] );
			if ( $note && 'free' === ( isset( $u['plan'] ) ? $u['plan'] : '' ) ) {
				echo '<p class="lasr-upsell">' . esc_html( $note ) . ' ';
				LASR_Plan::button( 'ai-fixes' );
				echo '</p>';
			}
		} elseif ( ! empty( $u['error'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( (string) $u['error'] ) . '</p></div>';
		}
		$rows = $result ? array_slice( (array) $result['products'], 0, 50 ) : array();
		if ( ! $rows ) {
			echo '<div class="lasr-empty-state"><p>' . esc_html__( 'Run the audit first (Overview tab): the products with the most gaps appear here.', 'leymish-ai-shopping-readiness' ) . '</p></div>';
			return;
		}
		$labels = array(
			'attributes'  => __( 'Attributes', 'leymish-ai-shopping-readiness' ),
			'description' => __( 'Description', 'leymish-ai-shopping-readiness' ),
			'category'    => __( 'Google category', 'leymish-ai-shopping-readiness' ),
			'alt_text'    => __( 'Alt text', 'leymish-ai-shopping-readiness' ),
		);
		echo '<div class="lasr-scroll"><table class="widefat lasr-table lasr-ai"><thead><tr><th scope="col">' . esc_html__( 'Product', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Draft a fix', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><th scope="row"><a href="' . esc_url( (string) get_edit_post_link( $r['id'] ) ) . '">' . esc_html( $r['name'] ) . '</a></th><td>';
			foreach ( $labels as $kind => $label ) {
				echo '<button type="button" class="button lasr-ai-suggest" data-product="' . esc_attr( (string) (int) $r['id'] ) . '" data-kind="' . esc_attr( $kind ) . '">' . esc_html( $label ) . '</button> ';
			}
			echo '<div class="lasr-ai-panel" id="lasr-ai-' . esc_attr( (string) (int) $r['id'] ) . '"></div></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * What AI fixes would do for this store, before consent: real counts, no invented results.
	 *
	 * @param array $counts Local counts.
	 */
	private static function preview( array $counts ) {
		echo '<section class="lasr-pro-preview lasr-card-box" aria-label="' . esc_attr__( 'What AI fixes would do here', 'leymish-ai-shopping-readiness' ) . '"><h3>' . esc_html__( 'For your store', 'leymish-ai-shopping-readiness' ) . '</h3><ul class="lasr-list">';
		/* translators: %d: products. */
		echo '<li>' . esc_html( sprintf( _n( '%d product without image alt text', '%d products without image alt text', $counts['alt'], 'leymish-ai-shopping-readiness' ), $counts['alt'] ) ) . '</li>';
		/* translators: %d: products. */
		echo '<li>' . esc_html( sprintf( _n( '%d product with too few attributes', '%d products with too few attributes', $counts['attributes'], 'leymish-ai-shopping-readiness' ), $counts['attributes'] ) ) . '</li>';
		/* translators: %d: products. */
		echo '<li>' . esc_html( sprintf( _n( '%d product with a thin description', '%d products with a thin description', $counts['description'], 'leymish-ai-shopping-readiness' ), $counts['description'] ) ) . '</li></ul>';
		echo '<p>' . esc_html__( 'Try 20 fixes free. LeyMish Pro includes 500 a month.', 'leymish-ai-shopping-readiness' ) . '</p>';
		LASR_Plan::button( 'ai-fixes-preview' );
		echo '</section>';
	}
}
