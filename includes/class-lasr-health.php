<?php
/**
 * Store health (2.1, free, local): can the store take an order, and do its own pages link to its products?
 *
 * Checkout health: a payment method is enabled, the cart and checkout pages exist, shipping can be quoted when the
 * store sells physical products, no payment method is left in test mode, and two card gateways don't compete at
 * checkout. Internal links: products no post or page links to (with the posts that already name them, so the owner
 * can add a link), and links to products that no longer exist. Everything runs on this site; nothing is changed.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout health and internal links.
 */
class LASR_Health {

	const LINKS = 'lasr_links';

	/**
	 * Checkout findings, worst first. Each: level (fail|warn), title, fix, url.
	 *
	 * @return array[]
	 */
	public static function checkout() {
		$out = array();
		$add = function ( $level, $title, $fix, $url ) use ( &$out ) {
			$out[] = compact( 'level', 'title', 'fix', 'url' );
		};
		$pay = admin_url( 'admin.php?page=wc-settings&tab=checkout' );
		$gw  = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$on  = array_filter( $gw, function ( $g ) {
			return isset( $g->enabled ) && 'yes' === $g->enabled;
		} );
		if ( ! $on ) {
			$add( 'fail', __( 'No payment method is switched on, so nobody can pay.', 'leymish-ai-shopping-readiness' ), __( 'Switch one on in WooCommerce → Settings → Payments.', 'leymish-ai-shopping-readiness' ), $pay );
		}
		foreach ( array( 'cart' => __( 'cart', 'leymish-ai-shopping-readiness' ), 'checkout' => __( 'checkout', 'leymish-ai-shopping-readiness' ) ) as $page => $name ) {
			$id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( $page ) : 0;
			if ( $id <= 0 || 'publish' !== get_post_status( $id ) ) {
				/* translators: %s: cart or checkout. */
				$add( 'fail', sprintf( __( 'The %s page is missing or not published.', 'leymish-ai-shopping-readiness' ), $name ), __( 'Set it in WooCommerce → Settings → Advanced → Page setup.', 'leymish-ai-shopping-readiness' ), admin_url( 'admin.php?page=wc-settings&tab=advanced' ) );
			}
		}
		if ( self::sells_physical() && ! self::has_shipping() ) {
			$add( 'fail', __( 'No shipping method is set up, so physical products can\'t be checked out.', 'leymish-ai-shopping-readiness' ), __( 'Add a zone and a method in WooCommerce → Settings → Shipping.', 'leymish-ai-shopping-readiness' ), admin_url( 'admin.php?page=wc-settings&tab=shipping' ) );
		}
		$test = array();
		foreach ( $on as $id => $g ) {
			if ( self::in_test_mode( (string) $id, isset( $g->settings ) ? (array) $g->settings : array() ) ) {
				$test[] = method_exists( $g, 'get_title' ) ? wp_strip_all_tags( (string) $g->get_title() ) : $id;
			}
		}
		if ( $test ) {
			/* translators: %s: payment method names. */
			$add( 'warn', sprintf( __( 'Test mode is on for %s: real cards won\'t be charged.', 'leymish-ai-shopping-readiness' ), implode( ', ', $test ) ), __( 'Switch test mode off in that payment method\'s settings once you\'re ready to sell.', 'leymish-ai-shopping-readiness' ), $pay );
		}
		$cards = self::competing_card_gateways( array_keys( $on ) );
		if ( $cards ) {
			/* translators: %s: gateway ids. */
			$add( 'warn', sprintf( __( 'Two card payment methods are on at once (%s), so shoppers see two card forms.', 'leymish-ai-shopping-readiness' ), implode( ' and ', $cards ) ), __( 'Keep the one you use and switch the other off in WooCommerce → Settings → Payments.', 'leymish-ai-shopping-readiness' ), $pay );
		}
		usort( $out, function ( $a, $b ) {
			return ( 'fail' === $a['level'] ? 0 : 1 ) - ( 'fail' === $b['level'] ? 0 : 1 );
		} );
		return $out;
	}

	/**
	 * A gateway's test mode from its settings (pure). Stripe, WooPayments, PayPal and Square use different keys.
	 *
	 * @param string $id       Gateway id.
	 * @param array  $settings Gateway settings.
	 * @return bool
	 */
	public static function in_test_mode( $id, array $settings ) {
		foreach ( array( 'testmode', 'test_mode', 'sandbox', 'sandbox_mode', 'environment' ) as $k ) {
			if ( isset( $settings[ $k ] ) && in_array( strtolower( (string) $settings[ $k ] ), array( 'yes', '1', 'true', 'sandbox', 'test' ), true ) ) {
				return true;
			}
		}
		if ( 'woocommerce_payments' === $id ) {
			$wcpay = get_option( 'woocommerce_woocommerce_payments_settings' );
			return is_array( $wcpay ) && isset( $wcpay['test_mode'] ) && 'yes' === $wcpay['test_mode'];
		}
		return false;
	}

	/**
	 * Card gateways that compete when both are on (pure).
	 *
	 * @param string[] $enabled Enabled gateway ids.
	 * @return string[]
	 */
	public static function competing_card_gateways( array $enabled ) {
		$cards = array_values( array_intersect( array( 'stripe', 'woocommerce_payments', 'square_credit_card', 'ppcp-credit-card-gateway', 'braintree_credit_card' ), $enabled ) );
		return count( $cards ) > 1 ? $cards : array();
	}

	/**
	 * Does the store sell anything that ships?
	 *
	 * @return bool
	 */
	private static function sells_physical() {
		$ids = wc_get_products( array( 'status' => 'publish', 'limit' => 50, 'return' => 'ids', 'virtual' => false ) );
		return (bool) $ids;
	}

	/**
	 * At least one enabled shipping method in any zone (including "Rest of the world").
	 *
	 * @return bool
	 */
	private static function has_shipping() {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return true;
		}
		$zones   = WC_Shipping_Zones::get_zones();
		$zones[] = array( 'shipping_methods' => ( new WC_Shipping_Zone( 0 ) )->get_shipping_methods( true ) );
		foreach ( $zones as $z ) {
			foreach ( (array) $z['shipping_methods'] as $m ) {
				if ( isset( $m->enabled ) && 'yes' === $m->enabled ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Internal links from published posts and pages to products (pure over plain data).
	 *
	 * @param array[] $posts    Each: id, title, content.
	 * @param array[] $products Each: id, name, slug, status ('publish' or other).
	 * @param int[]   $live     Ids of every published post, page and product (a ?p= link to one of these is fine).
	 * @return array{orphans:array[],broken:array[],linked:int}
	 */
	public static function link_report( array $posts, array $products, array $live = array() ) {
		$live = array_flip( array_map( 'intval', $live ) );
		$by_id   = array();
		$by_slug = array();
		foreach ( $products as $p ) {
			$by_id[ (int) $p['id'] ]           = $p;
			$by_slug[ strtolower( $p['slug'] ) ] = $p;
		}
		$linked = array();
		$broken = array();
		$named  = array();
		foreach ( $posts as $post ) {
			$c = (string) $post['content'];
			if ( preg_match_all( '/href="[^"]*[?&](?:p|product_id)=(\d+)[^"]*"/', $c, $m ) ) {
				foreach ( $m[1] as $id ) {
					$id = (int) $id;
					if ( isset( $by_id[ $id ] ) && 'publish' === $by_id[ $id ]['status'] ) {
						$linked[ $id ] = true;
					} elseif ( isset( $by_id[ $id ] ) || ! isset( $live[ $id ] ) ) { // an unpublished product, or nothing at all
						$broken[] = array( 'post' => $post['id'], 'title' => $post['title'], 'target' => '?p=' . $id );
					}
				}
			}
			if ( preg_match_all( '#href="[^"]*/product/([a-z0-9%_-]+)/?[^"]*"#i', $c, $m ) ) {
				foreach ( $m[1] as $slug ) {
					$slug = strtolower( rawurldecode( $slug ) );
					if ( isset( $by_slug[ $slug ] ) && 'publish' === $by_slug[ $slug ]['status'] ) {
						$linked[ (int) $by_slug[ $slug ]['id'] ] = true;
					} else {
						$broken[] = array( 'post' => $post['id'], 'title' => $post['title'], 'target' => '/product/' . $slug . '/' );
					}
				}
			}
			$plain = strtolower( wp_strip_all_tags( $c ) );
			foreach ( $products as $p ) {
				if ( 'publish' === $p['status'] && strlen( $p['name'] ) >= 6 && false !== strpos( $plain, strtolower( $p['name'] ) ) ) {
					$named[ (int) $p['id'] ][] = array( 'post' => $post['id'], 'title' => $post['title'] );
				}
			}
		}
		$orphans = array();
		foreach ( $products as $p ) {
			if ( 'publish' === $p['status'] && empty( $linked[ (int) $p['id'] ] ) ) {
				$orphans[] = array(
					'id'       => (int) $p['id'],
					'name'     => $p['name'],
					'mentions' => array_slice( isset( $named[ (int) $p['id'] ] ) ? $named[ (int) $p['id'] ] : array(), 0, 3 ),
				);
			}
		}
		usort( $orphans, function ( $a, $b ) {
			return count( $b['mentions'] ) - count( $a['mentions'] ); // easiest wins first: posts that already name it
		} );
		return array( 'orphans' => $orphans, 'broken' => $broken, 'linked' => count( $linked ) );
	}

	/**
	 * Run the link report on this store and keep it for the Audit tab.
	 *
	 * @return array
	 */
	public static function links() {
		$posts = array();
		foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'numberposts' => 300 ) ) as $p ) {
			$posts[] = array( 'id' => $p->ID, 'title' => get_the_title( $p ), 'content' => $p->post_content );
		}
		$products = array();
		foreach ( wc_get_products( array( 'status' => array( 'publish', 'draft', 'private', 'trash' ), 'limit' => 500, 'return' => 'objects' ) ) as $p ) {
			$products[] = array( 'id' => $p->get_id(), 'name' => wp_specialchars_decode( $p->get_name(), ENT_QUOTES ), 'slug' => $p->get_slug(), 'status' => $p->get_status() );
		}
		$live = get_posts( array( 'post_type' => 'any', 'post_status' => 'publish', 'numberposts' => 2000, 'fields' => 'ids' ) );
		$r    = self::link_report( $posts, $products, $live ) + array( 'posts' => count( $posts ), 't' => time() );
		update_option( self::LINKS, $r, false );
		return $r;
	}

	/**
	 * The Audit tab's details for the two health checks.
	 */
	public static function render() {
		$items = self::checkout();
		$r     = get_option( self::LINKS );
		echo '<section class="lasr-card-box" aria-labelledby="lasr-health-h"><h2 id="lasr-health-h">' . esc_html__( 'Store health', 'leymish-ai-shopping-readiness' ) . '</h2>';
		echo '<h3>' . esc_html__( 'Can the store take an order?', 'leymish-ai-shopping-readiness' ) . '</h3>';
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'Yes: a payment method is on, the cart and checkout pages are published, and shipping can be quoted.', 'leymish-ai-shopping-readiness' ) . '</p>';
		} else {
			echo '<ul class="lasr-list">';
			foreach ( $items as $i ) {
				echo '<li><span class="lasr-badge lasr-' . esc_attr( $i['level'] ) . '">' . esc_html( 'fail' === $i['level'] ? __( 'Fix first', 'leymish-ai-shopping-readiness' ) : __( 'Needs work', 'leymish-ai-shopping-readiness' ) ) . '</span> ' . esc_html( $i['title'] ) . ' <a href="' . esc_url( $i['url'] ) . '">' . esc_html( $i['fix'] ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '<h3>' . esc_html__( 'Internal links to your products', 'leymish-ai-shopping-readiness' ) . '</h3>';
		if ( ! is_array( $r ) ) {
			echo '<p>' . esc_html__( 'Shown after the next audit.', 'leymish-ai-shopping-readiness' ) . '</p></section>';
			return;
		}
		foreach ( array_slice( $r['broken'], 0, 10 ) as $b ) {
			/* translators: 1: post title, 2: link target. */
			echo '<p class="lasr-broken">' . esc_html( sprintf( __( '"%1$s" links to %2$s, which isn\'t a published product.', 'leymish-ai-shopping-readiness' ), $b['title'], $b['target'] ) ) . ' <a href="' . esc_url( (string) get_edit_post_link( $b['post'] ) ) . '">' . esc_html__( 'Edit the post', 'leymish-ai-shopping-readiness' ) . '</a></p>';
		}
		/* translators: 1: products with no links, 2: posts and pages checked. */
		echo '<p>' . esc_html( sprintf( _n( '%1$d product has no link from your %2$d posts and pages.', '%1$d products have no link from your %2$d posts and pages.', count( $r['orphans'] ), 'leymish-ai-shopping-readiness' ), count( $r['orphans'] ), $r['posts'] ) ) . '</p>';
		$with = array_filter( $r['orphans'], function ( $o ) {
			return ! empty( $o['mentions'] );
		} );
		if ( $with ) {
			echo '<p>' . esc_html__( 'Easiest first: these posts already name the product, so one link is enough.', 'leymish-ai-shopping-readiness' ) . '</p><ul class="lasr-list">';
			foreach ( array_slice( $with, 0, 10 ) as $o ) {
				$m = $o['mentions'][0];
				/* translators: 1: product, 2: post title. */
				echo '<li>' . esc_html( sprintf( __( '%1$s: link it from "%2$s"', 'leymish-ai-shopping-readiness' ), $o['name'], $m['title'] ) ) . ' <a href="' . esc_url( (string) get_edit_post_link( $m['post'] ) ) . '">' . esc_html__( 'Edit the post', 'leymish-ai-shopping-readiness' ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '</section>';
	}
}
