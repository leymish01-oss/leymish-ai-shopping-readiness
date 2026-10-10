<?php
/**
 * The AI visibility tab (a LeyMish service): are AI answer engines citing this store when shoppers ask?
 *
 * The questions are this store's own: written here from its product categories, edited by the owner. LeyMish's
 * service asks a search-grounded AI model and reports, per question, whether the store is cited, which other sites
 * are cited instead, and a short excerpt. Free: one check of 3 questions. LeyMish Pro: 10 questions every week, and a
 * citation-share trend. Results are kept on this site; the service keeps counters only.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Questions, runs, history and the tab.
 */
class LASR_Visibility {

	const QUESTIONS = 'lasr_vis_questions';
	const HISTORY   = 'lasr_vis_history';
	const FREE      = 3;
	const PRO       = 10;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_lasr_vis_questions', array( __CLASS__, 'handle_questions' ) );
		add_action( 'admin_post_lasr_vis_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'lasr_visibility_weekly', array( __CLASS__, 'weekly' ) );
		if ( LASR_License::has_licence() && ! wp_next_scheduled( 'lasr_visibility_weekly' ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'weekly', 'lasr_visibility_weekly' );
		}
	}

	/**
	 * Decode HTML entities exactly once (pure): "serums &amp;amp; boosters" stored by some imports becomes
	 * "serums &amp; boosters" -> "serums & boosters" on screen, never "&amp;amp;".
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function decode( $s ) {
		$s = (string) $s;
		for ( $i = 0; $i < 2 && false !== strpos( $s, '&' ); $i++ ) {
			$d = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $d === $s ) {
				break;
			}
			$s = $d;
		}
		return $s;
	}

	/**
	 * What shoppers would search for, from product names and their key-ingredient attributes (pure, 2.0.1):
	 * "Exosome &amp; Niacinamide Serum" -> "exosome and niacinamide serum"; a "Key ingredient: Retinol" attribute on a
	 * "… Night Cream" adds "retinol night cream". Sets, boxes and bundles are left out (nobody asks an AI for them).
	 *
	 * @param array[] $products Each: name, ingredients (string[]).
	 * @param int     $max      How many topics.
	 * @return string[]
	 */
	public static function product_topics( array $products, $max = 8 ) {
		$out = array();
		foreach ( $products as $p ) {
			$name = strtolower( self::decode( isset( $p['name'] ) ? $p['name'] : '' ) );
			$name = preg_replace( '/\([^)]*\)|\b\d+(\.\d+)?\s*(ml|g|oz|fl oz|mg|caps|capsules|ct|pcs)\b/', ' ', $name );
			$name = str_replace( array( '&', '+', '/' ), array( ' and ', ' and ', ' ' ), $name );
			$name = trim( preg_replace( '/[^a-z0-9\- ]+/', ' ', $name ) );
			$name = trim( preg_replace( '/\s+/', ' ', $name ) );
			if ( '' === $name || preg_match( '/\b(box|set|duo|trio|bundle|kit|collection|gift|sample|routine)\b/', $name ) ) {
				continue;
			}
			$words = explode( ' ', $name );
			if ( count( $words ) > 6 ) {
				$name = implode( ' ', array_slice( $words, -6 ) );
			}
			$out[] = $name;
			$type  = end( $words );
			foreach ( (array) ( isset( $p['ingredients'] ) ? $p['ingredients'] : array() ) as $ing ) {
				$ing = strtolower( trim( preg_replace( '/[^a-z0-9\- ]+/i', ' ', self::decode( $ing ) ) ) );
				if ( '' !== $ing && false === strpos( $name, $ing ) && strlen( $ing ) <= 30 ) {
					$out[] = $ing . ' ' . $type;
				}
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, $max );
	}

	/**
	 * Draft questions (pure, 2.0.1). Topics are product types and key ingredients (or category names when there are
	 * no products), asked two ways in turn: "What is the best X?" and "Where can I buy X online?". The store's top
	 * Search Console queries go first when an integration supplies them, and one brand question goes last.
	 *
	 * @param string[] $topics  Topics, most important first.
	 * @param string   $store   Store name.
	 * @param int      $max     How many.
	 * @param string[] $queries Search Console queries (optional).
	 * @param string   $domain  The store's domain (optional).
	 * @return string[]
	 */
	public static function draft( array $topics, $store, $max = 10, array $queries = array(), $domain = '' ) {
		$topics = array_values(
			array_filter(
				array_map(
					function ( $c ) {
						return strtolower( trim( wp_strip_all_tags( self::decode( $c ) ) ) );
					},
					$topics
				),
				function ( $c ) {
					return '' !== $c && 'uncategorized' !== $c && 'uncategorised' !== $c;
				}
			)
		);
		if ( ! $topics ) {
			$topics = array( 'products like ours' );
		}
		$store = trim( self::decode( $store ) );
		$out   = array();
		foreach ( array_slice( $queries, 0, 3 ) as $q ) {
			$q = trim( wp_strip_all_tags( self::decode( $q ) ) );
			if ( strlen( $q ) >= 8 ) {
				$out[] = ucfirst( rtrim( $q, '?' ) ) . '?';
			}
		}
		$room = max( 1, $max - ( '' !== $store ? 1 : 0 ) );
		foreach ( array( 0, 1, 2 ) as $round ) {
			foreach ( $topics as $i => $c ) {
				if ( 2 === $round ) {
					$out[] = sprintf( 'Which online shop has good %s?', $c );
					continue;
				}
				$best  = 0 === ( $i + $round ) % 2;
				$out[] = $best ? sprintf( 'What is the best %s?', $c ) : sprintf( 'Where can I buy %s online?', $c );
			}
		}
		$out = array_slice( array_values( array_unique( $out ) ), 0, $room );
		if ( '' !== $store ) {
			$out[] = '' !== $domain
				? sprintf( 'Is %1$s (%2$s) a good place to buy %3$s?', $store, $domain, $topics[0] )
				: sprintf( 'Is %1$s a good place to buy %2$s?', $store, $topics[0] );
		}
		return array_slice( $out, 0, $max );
	}

	/**
	 * The owner's questions (or the drafted ones until they save their own).
	 *
	 * @return string[]
	 */
	public static function questions() {
		$q = get_option( self::QUESTIONS );
		if ( is_array( $q ) && $q ) {
			return array_slice( array_map( array( __CLASS__, 'decode' ), $q ), 0, self::PRO );
		}
		$products = array();
		$ids      = function_exists( 'wc_get_products' ) ? wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => 12,
				'orderby' => 'popularity',
				'return'  => 'ids',
			)
		) : array();
		foreach ( (array) $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$ing = array();
			foreach ( $p->get_attributes() as $a ) {
				if ( is_object( $a ) && preg_match( '/ingredient/i', wc_attribute_label( $a->get_name() ) ) ) {
					$ing = array_merge( $ing, array_slice( (array) $a->get_options(), 0, 2 ) );
				}
			}
			$products[] = array(
				'name'        => $p->get_name(),
				'ingredients' => array_filter( $ing, 'is_string' ),
			);
		}
		$topics = self::product_topics( $products, 8 );
		if ( count( $topics ) < 3 ) {
			$cats   = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => 4,
					'fields'     => 'names',
				)
			);
			$topics = array_merge( $topics, is_array( $cats ) ? $cats : array() );
		}
		/**
		 * The store's top Search Console queries, for integrations that have them (none are fetched by this plugin).
		 *
		 * @param string[] $queries Queries, most clicks first.
		 */
		$queries = (array) apply_filters( 'lasr_visibility_queries', array() );
		return self::draft( $topics, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), self::PRO, $queries, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Save the owner's edited questions (one per line).
	 */
	public static function handle_questions() {
		self::guard( 'lasr_vis_questions' );
		$raw = isset( $_POST['questions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['questions'] ) ) : '';
		$q   = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( strlen( $line ) >= 8 ) {
				$q[] = mb_substr( $line, 0, 160 );
			}
		}
		update_option( self::QUESTIONS, array_slice( $q, 0, self::PRO ), false );
		self::back( 'saved' );
	}

	/**
	 * Run a check now (the free one, or this week's Pro questions).
	 */
	public static function handle_run() {
		self::guard( 'lasr_vis_run' );
		if ( empty( $_POST['consent'] ) ) {
			self::back( 'consent' );
		}
		$r = self::run();
		if ( ! $r['ok'] && false !== stripos( $r['error'], 'questions are used' ) ) {
			self::back( 'allowance' ); // P-029: a used weekly allowance is news, not an error
		}
		self::back( $r['ok'] ? 'done' : 'failed', $r['ok'] ? '' : $r['error'] );
	}

	/**
	 * Pro's weekly run.
	 */
	public static function weekly() {
		if ( LASR_License::is_pro() ) {
			self::run();
		}
	}

	/**
	 * Ask LeyMish's service. Free sends 3 questions, Pro up to 10. The result is kept here.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public static function run() {
		$pro  = LASR_License::is_pro();
		$body = array(
			'site'       => LASR_Service::site(),
			'store_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'questions'  => array_slice( self::questions(), 0, $pro ? self::PRO : self::FREE ),
		);
		if ( LASR_License::has_licence() ) {
			$body['license_key'] = LASR_License::key();
		}
		$conn = class_exists( 'LASR_Team' ) ? LASR_Team::connection() : null;
		$r    = LASR_Service::post( '/v1/visibility/check', $body, $conn ? $conn['site_token'] : '', 90 );
		if ( 200 !== $r['status'] || empty( $r['data']['ok'] ) ) {
			if ( 402 === $r['status'] ) {
				update_option( 'lasr_vis_free_used', 1, false );
			}
			return array(
				'ok'    => false,
				'error' => isset( $r['data']['error'] ) ? (string) $r['data']['error'] : __( 'Could not reach LeyMish just now. Nothing was counted.', 'leymish-ai-shopping-readiness' ),
			);
		}
		$results = array();
		foreach ( (array) $r['data']['results'] as $x ) {
			if ( ! is_array( $x ) || empty( $x['question'] ) ) {
				continue;
			}
			$results[] = array(
				'question'    => sanitize_text_field( (string) $x['question'] ),
				'error'       => isset( $x['error'] ) ? sanitize_text_field( (string) $x['error'] ) : null,
				'cited'       => ! empty( $x['cited'] ),
				'mentioned'   => ! empty( $x['mentioned'] ),
				'competitors' => array_map( 'sanitize_text_field', array_slice( (array) ( isset( $x['competitors'] ) ? $x['competitors'] : array() ), 0, 6 ) ),
				'excerpt'     => sanitize_text_field( isset( $x['excerpt'] ) ? (string) $x['excerpt'] : '' ),
			);
		}
		$history   = self::history();
		$history[] = array(
			't'       => time(),
			'plan'    => sanitize_key( (string) $r['data']['plan'] ),
			'results' => $results,
		);
		update_option( self::HISTORY, array_slice( $history, -26 ), false );
		if ( ! $pro ) {
			update_option( 'lasr_vis_free_used', 1, false );
		}
		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	/**
	 * Runs, oldest first.
	 *
	 * @return array[]
	 */
	public static function history() {
		$h = get_option( self::HISTORY );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * The latest run, or null.
	 *
	 * @return array|null
	 */
	public static function latest() {
		$h = self::history();
		return $h ? end( $h ) : null;
	}

	/**
	 * Citation share of a run: cited answers out of answered questions (pure).
	 *
	 * @param array $run Run.
	 * @return array{cited:int,answered:int,pct:int}
	 */
	public static function share( array $run ) {
		$answered = 0;
		$cited    = 0;
		foreach ( (array) $run['results'] as $r ) {
			if ( empty( $r['error'] ) ) {
				++$answered;
				$cited += ! empty( $r['cited'] ) ? 1 : 0;
			}
		}
		return array(
			'cited'    => $cited,
			'answered' => $answered,
			'pct'      => $answered ? (int) round( 100 * $cited / $answered ) : 0,
		);
	}

	/**
	 * Sites cited instead of this store in a run, most often first (pure).
	 *
	 * @param array $run Run.
	 * @return array<string,int>
	 */
	public static function competitors( array $run ) {
		$n = array();
		foreach ( (array) $run['results'] as $r ) {
			foreach ( (array) ( isset( $r['competitors'] ) ? $r['competitors'] : array() ) as $h ) {
				$n[ $h ] = ( isset( $n[ $h ] ) ? $n[ $h ] : 0 ) + 1;
			}
		}
		arsort( $n );
		return array_slice( $n, 0, 8, true );
	}

	/**
	 * Guard.
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
	 * Back to the tab.
	 *
	 * @param string $msg Code.
	 * @param string $err Error text.
	 */
	private static function back( $msg, $err = '' ) {
		if ( '' !== $err ) {
			set_transient( 'lasr_vis_error_' . get_current_user_id(), $err, 300 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=visibility&lasr_msg=' . rawurlencode( $msg ) ) );
		exit;
	}

	/**
	 * Citation share over time (inline SVG bars + a screen-reader table).
	 *
	 * @param array[] $history Runs.
	 */
	private static function trend( array $history ) {
		if ( count( $history ) < 2 ) {
			return;
		}
		$runs = array_slice( $history, -12 );
		$w    = 36;
		$h    = 120;
		$fmt  = get_option( 'date_format' );
		echo '<figure class="lasr-trend-chart"><svg viewBox="0 0 ' . esc_attr( (string) ( count( $runs ) * $w + 30 ) ) . ' ' . esc_attr( (string) ( $h + 24 ) ) . '" aria-hidden="true" focusable="false">';
		foreach ( array( 0, 50, 100 ) as $g ) {
			$y = $h - $g / 100 * $h;
			echo '<line class="lasr-grid" x1="28" x2="' . esc_attr( (string) ( count( $runs ) * $w + 30 ) ) . '" y1="' . esc_attr( (string) $y ) . '" y2="' . esc_attr( (string) $y ) . '" /><text class="lasr-axis" x="24" y="' . esc_attr( (string) ( $y + 4 ) ) . '" text-anchor="end">' . esc_html( $g . '%' ) . '</text>';
		}
		foreach ( $runs as $i => $run ) {
			$s  = self::share( $run );
			$bh = max( 2, round( $h * $s['pct'] / 100 ) );
			echo '<rect class="lasr-trend-bar" x="' . esc_attr( (string) ( 34 + $i * $w ) ) . '" y="' . esc_attr( (string) ( $h - $bh ) ) . '" width="' . esc_attr( (string) ( $w - 10 ) ) . '" height="' . esc_attr( (string) $bh ) . '" rx="3" />';
		}
		echo '</svg><figcaption class="description">' . esc_html__( 'Share of answers that cite your store, per check.', 'leymish-ai-shopping-readiness' ) . '</figcaption></figure>';
		echo '<table class="screen-reader-text"><caption>' . esc_html__( 'Citation share by check', 'leymish-ai-shopping-readiness' ) . '</caption><tbody>';
		foreach ( $runs as $run ) {
			$s = self::share( $run );
			echo '<tr><th scope="row">' . esc_html( wp_date( $fmt, (int) $run['t'] ) ) . '</th><td>' . esc_html( $s['cited'] . ' / ' . $s['answered'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The tab.
	 *
	 * @param array|null $result Last audit.
	 */
	public static function render( $result ) {
		$pro       = LASR_License::is_pro();
		$latest    = self::latest();
		$free_used = (bool) get_option( 'lasr_vis_free_used' );
		$share     = $latest ? self::share( $latest ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only code from our own redirect.
		$msg = isset( $_GET['lasr_msg'] ) ? sanitize_key( wp_unslash( $_GET['lasr_msg'] ) ) : '';

		LASR_Admin::three(
			$share
				/* translators: 1: answers citing the store, 2: questions answered. */
				? sprintf( __( '%1$d of %2$d AI answers cited your store.', 'leymish-ai-shopping-readiness' ), $share['cited'], $share['answered'] )
				: __( 'You don\'t know yet whether ChatGPT, Perplexity and Google cite your store.', 'leymish-ai-shopping-readiness' ),
			$pro ? __( 'LeyMish Pro asks your questions every week.', 'leymish-ai-shopping-readiness' ) : ( $free_used ? __( 'Your free check is done.', 'leymish-ai-shopping-readiness' ) : __( 'One free check of 3 questions is waiting.', 'leymish-ai-shopping-readiness' ) ),
			$share && $share['cited'] < $share['answered'] ? __( 'Fix what AI agents read (Products and Feeds), then check again.', 'leymish-ai-shopping-readiness' ) : __( 'Edit the questions so they sound like your shoppers.', 'leymish-ai-shopping-readiness' ),
			( ! $free_used || $pro ) ? array( '#lasr-vis-run', $pro ? __( 'Check now', 'leymish-ai-shopping-readiness' ) : __( 'Run my free check', 'leymish-ai-shopping-readiness' ) ) : null
		);
		$texts = array(
			'saved'   => array( 'success', __( 'Questions saved.', 'leymish-ai-shopping-readiness' ) ),
			'done'    => array( 'success', __( 'Check finished.', 'leymish-ai-shopping-readiness' ) ),
			'allowance' => array( 'info', __( 'This week\'s 10 questions are used; they reset on Monday.', 'leymish-ai-shopping-readiness' ) ),
			'consent' => array( 'warning', __( 'Tick the box first: the questions and your store\'s address are sent to LeyMish for this check.', 'leymish-ai-shopping-readiness' ) ),
		);
		if ( 'failed' === $msg ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( (string) get_transient( 'lasr_vis_error_' . get_current_user_id() ) ) . '</p></div>';
		} elseif ( isset( $texts[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $texts[ $msg ][0] ) . ' inline"><p>' . esc_html( $texts[ $msg ][1] ) . '</p></div>';
		}
		echo '<p class="lasr-lede">' . esc_html__( 'Shoppers now ask AI assistants what to buy. We ask your shoppers\' questions to a search-grounded AI model and show whether the answer cites your store, and who it cites instead.', 'leymish-ai-shopping-readiness' ) . '</p>';

		if ( $latest ) {
			self::results( $latest );
			self::trend( self::history() );
			self::what_to_change( $latest, $result );
		}

		$q = self::questions();
		echo '<div class="lasr-two"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-card-box">';
		wp_nonce_field( 'lasr_vis_questions' );
		echo '<input type="hidden" name="action" value="lasr_vis_questions" /><h3><label for="lasr-vis-q">' . esc_html__( 'Your shoppers\' questions', 'leymish-ai-shopping-readiness' ) . '</label></h3><p class="description">' . esc_html__( 'Drafted from your products (types and key ingredients) plus one brand question. Edit them so they sound like your shoppers. One per line, up to 10. The free check uses the first 3.', 'leymish-ai-shopping-readiness' ) . '</p>';
		echo '<textarea id="lasr-vis-q" name="questions" rows="8" class="large-text">' . esc_textarea( implode( "\n", $q ) ) . '</textarea>';
		submit_button( __( 'Save questions', 'leymish-ai-shopping-readiness' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-card-box" id="lasr-vis-run">';
		wp_nonce_field( 'lasr_vis_run' );
		echo '<input type="hidden" name="action" value="lasr_vis_run" />';
		if ( $pro ) {
			echo '<h3>' . esc_html__( 'Weekly check', 'leymish-ai-shopping-readiness' ) . '</h3><p>' . esc_html__( 'Your 10 questions are asked every week. You can run this week\'s check now.', 'leymish-ai-shopping-readiness' ) . '</p>';
		} elseif ( ! $free_used ) {
			echo '<h3>' . esc_html__( 'Your free check', 'leymish-ai-shopping-readiness' ) . '</h3><p>' . esc_html__( 'Asks your first 3 questions once. Takes about a minute.', 'leymish-ai-shopping-readiness' ) . '</p>';
		} else {
			echo '<h3>' . esc_html__( 'Every week with LeyMish Pro', 'leymish-ai-shopping-readiness' ) . '</h3><p>' . esc_html__( 'Pro asks all of your questions above every week and shows the trend, so you see whether your fixes made AI assistants cite you.', 'leymish-ai-shopping-readiness' ) . '</p>';
			LASR_Plan::button( 'visibility' );
			echo '</form></div>';
			return;
		}
		echo '<p><label><input type="checkbox" name="consent" value="1" /> ' . esc_html__( 'Send these questions and my store\'s address to LeyMish for this check.', 'leymish-ai-shopping-readiness' ) . '</label></p>';
		submit_button( $pro ? __( 'Check now', 'leymish-ai-shopping-readiness' ) : __( 'Run my free check', 'leymish-ai-shopping-readiness' ), 'primary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * One card per question.
	 *
	 * @param array $run Run.
	 */
	private static function results( array $run ) {
		$s = self::share( $run );
		/* translators: %s: date of the check. */
		echo '<h2>' . esc_html( sprintf( __( 'Last check: %s', 'leymish-ai-shopping-readiness' ), wp_date( get_option( 'date_format' ), (int) $run['t'] ) ) ) . '</h2>';
		echo '<div class="lasr-share-big"><span class="lasr-big">' . esc_html( $s['pct'] . '%' ) . '</span> <span>' . esc_html__( 'of answers cite your store', 'leymish-ai-shopping-readiness' ) . '</span></div>';
		echo '<ul class="lasr-vis-list">';
		foreach ( $run['results'] as $r ) {
			$state = ! empty( $r['error'] ) ? 'skip' : ( ! empty( $r['cited'] ) ? 'pass' : 'fail' );
			$word  = ! empty( $r['error'] ) ? __( 'No answer', 'leymish-ai-shopping-readiness' ) : ( ! empty( $r['cited'] ) ? __( 'Cited', 'leymish-ai-shopping-readiness' ) : ( ! empty( $r['mentioned'] ) ? __( 'Named, not linked', 'leymish-ai-shopping-readiness' ) : __( 'Not cited', 'leymish-ai-shopping-readiness' ) ) );
			echo '<li class="lasr-vis-item lasr-st-' . esc_attr( $state ) . '"><span class="lasr-badge lasr-' . esc_attr( $state ) . '">' . esc_html( $word ) . '</span> <strong>' . esc_html( $r['question'] ) . '</strong>';
			if ( ! empty( $r['competitors'] ) ) {
				echo '<p class="lasr-vis-others">' . esc_html( ! empty( $r['cited'] ) ? __( 'Also cited:', 'leymish-ai-shopping-readiness' ) : __( 'Cited instead:', 'leymish-ai-shopping-readiness' ) ) . ' ' . esc_html( implode( ', ', $r['competitors'] ) ) . '</p>';
			}
			if ( ! empty( $r['excerpt'] ) ) {
				echo '<details><summary>' . esc_html__( 'The answer', 'leymish-ai-shopping-readiness' ) . '</summary><p class="lasr-vis-excerpt">' . esc_html( $r['excerpt'] ) . '…</p></details>';
			}
			echo '</li>';
		}
		echo '</ul>';
		$comp = self::competitors( $run );
		if ( $comp ) {
			echo '<h3>' . esc_html__( 'Sites AI cites most for your questions', 'leymish-ai-shopping-readiness' ) . '</h3><ol class="lasr-list">';
			foreach ( $comp as $host => $n ) {
				/* translators: 1: site, 2: number of answers. */
				echo '<li>' . esc_html( sprintf( _n( '%1$s (%2$d answer)', '%1$s (%2$d answers)', $n, 'leymish-ai-shopping-readiness' ), $host, $n ) ) . '</li>';
			}
			echo '</ol>';
		}
	}

	/**
	 * What to change, linked to the fixes (from this store's own audit, not generic advice).
	 *
	 * @param array      $run    Run.
	 * @param array|null $result Last audit.
	 */
	private static function what_to_change( array $run, $result ) {
		$s = self::share( $run );
		if ( ! $s['answered'] || $s['cited'] === $s['answered'] ) {
			return;
		}
		$items = array();
		$base  = admin_url( 'admin.php?page=' . LASR_Admin::SLUG );
		$gaps  = $result ? LASR_Dashboard::identifier_gaps( $result['products'] ) : 0;
		if ( $gaps ) {
			/* translators: %d: products without identifiers. */
			$items[] = array( $base . '&tab=products', sprintf( _n( 'Give %d product a barcode or part number and a brand', 'Give %d products a barcode or part number and a brand', $gaps, 'leymish-ai-shopping-readiness' ), $gaps ) );
		}
		if ( ! LASR_Feeds::enabled() ) {
			$items[] = array( $base . '&tab=feeds', __( 'Switch on your OpenAI and Google product feeds', 'leymish-ai-shopping-readiness' ) );
		}
		if ( ! LASR_Llms::enabled() ) {
			$items[] = array( $base . '&tab=feeds', __( 'Publish an llms.txt so AI assistants find your shop, categories and policies', 'leymish-ai-shopping-readiness' ) );
		}
		if ( $result ) {
			foreach ( (array) $result['checks'] as $c ) {
				if ( in_array( $c['id'], array( 'robots', 'bot_block' ), true ) && 'fail' === $c['status'] ) {
					$items[] = array( $base . '&tab=audit', $c['label'] );
				}
			}
		}
		if ( ! $items ) {
			return;
		}
		echo '<h3>' . esc_html__( 'What to change', 'leymish-ai-shopping-readiness' ) . '</h3><ol class="lasr-list">';
		foreach ( $items as $i ) {
			echo '<li><a href="' . esc_url( $i[0] ) . '">' . esc_html( $i[1] ) . '</a></li>';
		}
		echo '</ol><p class="description">' . esc_html__( 'Answer engines change their sources often; these fixes make your products easier to read and quote, but no one can promise a citation.', 'leymish-ai-shopping-readiness' ) . '</p>';
	}
}
