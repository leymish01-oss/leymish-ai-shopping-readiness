<?php
/**
 * Visitors (2.1, free): first-party, cookieless counts of this store's visitors, kept on this site.
 *
 * A tiny script on public pages sends one request per page view to this site's own REST API (navigator.sendBeacon, so
 * it works with page caching). Stored: counts per day only (page views, unique visitors, where they came from: AI
 * assistants, search, direct, other) and the top landing pages. A unique visitor is counted with a hash of the IP
 * address and browser name plus a random salt that changes every day; the hash is kept only for today and yesterday
 * and the IP address and browser name are never stored. Logged-in staff and known bots are not counted. Nothing leaves
 * the site unless the owner connects Store Team, which then reads these counts.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counting, storage, the read endpoint and the Overview card.
 */
class LASR_Visitors {

	const OPTION = 'lasr_visits';
	const SALT   = 'lasr_visits_salt';
	const ON     = 'lasr_visitors_enabled';
	const KEEP   = 35;
	const AI     = array( 'chatgpt.com', 'chat.openai.com', 'openai.com', 'perplexity.ai', 'copilot.microsoft.com', 'gemini.google.com', 'claude.ai', 'you.com', 'meta.ai' );
	const SEARCH = array( 'google.', 'bing.com', 'duckduckgo.com', 'search.yahoo.', 'yandex.', 'baidu.com', 'ecosia.org', 'search.brave.com', 'startpage.com' );
	const BOTS   = '/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|uptime|monitor|python|curl|wget|java\/|go-http|okhttp|facebookexternalhit|chatgpt-user|gptbot|perplexity|claude|bytespider|semrush|ahrefs/i';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'beacon' ) );
		add_action( 'admin_post_lasr_visitors_toggle', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_text' ) );
		add_action( 'lasr_visitors_push', array( __CLASS__, 'push' ) );
		if ( ! wp_next_scheduled( 'lasr_visitors_push' ) ) {
			wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'daily', 'lasr_visitors_push' );
		}
	}

	/**
	 * Once a day, when Store Team is connected and counting is on, send the counts (never hashes or addresses) so the
	 * store report can include them.
	 */
	public static function push() {
		if ( ! self::enabled() || ! class_exists( 'LASR_Team' ) || ! LASR_Team::connection() ) {
			return;
		}
		$data = get_option( self::OPTION );
		$body = array( 'summary' => self::summary( is_array( $data ) ? $data : array(), wp_date( 'Y-m-d' ) ) );
		// The start guide's first step not done yet, so the store report can point to it (P-029).
		if ( ! class_exists( 'LASR_Start' ) ) {
			require_once LASR_DIR . 'includes/class-lasr-start.php';
		}
		$state = LASR_Start::state();
		foreach ( LASR_Start::steps( true ) as $step ) {
			if ( empty( $state[ $step[0] ] ) ) {
				$body['next_step'] = array( 'title' => $step[1], 'where' => $step[2] );
				break;
			}
		}
		LASR_Team::call( 'visitors', $body, 10 );
	}

	/**
	 * On unless the owner turned it off.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return 'no' !== get_option( self::ON, 'yes' );
	}

	/**
	 * The beacon on public pages (not for logged-in staff, not in feeds or previews).
	 */
	public static function beacon() {
		if ( ! self::enabled() || is_admin() || is_feed() || is_preview() || ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) ) {
			return;
		}
		wp_register_script( 'lasr-visit', false, array(), LASR_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_enqueue_script( 'lasr-visit' );
		$url = esc_url_raw( rest_url( 'lasr/v1/hit' ) );
		wp_add_inline_script(
			'lasr-visit',
			'(function(){try{var q=new URLSearchParams(location.search),d={p:location.pathname,r:document.referrer,u:q.get("utm_source")||""};'
			. 'if(navigator.sendBeacon){navigator.sendBeacon(' . wp_json_encode( $url ) . ',new Blob([JSON.stringify(d)],{type:"text/plain"}));}}catch(e){}})();'
		);
	}

	/**
	 * REST routes: the public counter and the read endpoint for the owner or the connected Store Team.
	 */
	public static function routes() {
		register_rest_route(
			'lasr/v1',
			'/hit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'hit' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'lasr/v1',
			'/visitors',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'read' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
	}

	/**
	 * The owner (manage_woocommerce) or the connected Store Team (its site token in X-LeyMish-Token).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_read( $request ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		$conn  = get_option( 'lasr_team_connection' );
		$token = (string) $request->get_header( 'x-leymish-token' );
		return is_array( $conn ) && ! empty( $conn['site_token'] ) && '' !== $token && hash_equals( (string) $conn['site_token'], $token );
	}

	/**
	 * Where a visit came from (pure): ai, search, direct or other.
	 *
	 * @param string $referrer   Referrer URL.
	 * @param string $utm_source utm_source value.
	 * @param string $own_host   This site's host (internal clicks count as direct).
	 * @return string
	 */
	public static function source( $referrer, $utm_source, $own_host = '' ) {
		$utm = strtolower( trim( (string) $utm_source ) );
		foreach ( self::AI as $ai ) {
			if ( '' !== $utm && false !== strpos( $ai, $utm ) ) {
				return 'ai';
			}
		}
		if ( in_array( $utm, array( 'chatgpt', 'chatgpt.com', 'perplexity', 'copilot', 'gemini', 'claude' ), true ) ) {
			return 'ai';
		}
		$host = strtolower( (string) wp_parse_url( (string) $referrer, PHP_URL_HOST ) );
		if ( '' === $host || ( '' !== $own_host && ( $host === $own_host || substr( $host, -strlen( '.' . $own_host ) ) === '.' . $own_host ) ) ) {
			return 'direct';
		}
		foreach ( self::AI as $ai ) {
			if ( $host === $ai || substr( $host, -strlen( '.' . $ai ) ) === '.' . $ai ) {
				return 'ai';
			}
		}
		foreach ( self::SEARCH as $s ) {
			if ( false !== strpos( $host, $s ) ) {
				return 'search';
			}
		}
		return 'other';
	}

	/**
	 * Add one page view to a day's counts (pure).
	 *
	 * @param array  $data   Stored data.
	 * @param string $day    Y-m-d.
	 * @param string $path   Landing path.
	 * @param string $source ai|search|direct|other.
	 * @param string $hash   Today's visitor hash (first view of the day counts as a unique visitor).
	 * @return array
	 */
	public static function add( array $data, $day, $path, $source, $hash ) {
		$d = isset( $data['days'][ $day ] ) ? $data['days'][ $day ] : array(
			'views'   => 0,
			'uniques' => 0,
			'ai'      => 0,
			'search'  => 0,
			'direct'  => 0,
			'other'   => 0,
			'pages'   => array(),
		);
		++$d['views'];
		$seen = isset( $data['seen'][ $day ] ) ? $data['seen'][ $day ] : array();
		if ( ! isset( $seen[ $hash ] ) ) {
			$seen[ $hash ] = 1;
			++$d['uniques'];
			++$d[ $source ]; // sources count visitors, by where their first page view of the day came from
			$d['pages'][ $path ] = ( isset( $d['pages'][ $path ] ) ? $d['pages'][ $path ] : 0 ) + 1; // landing pages
			arsort( $d['pages'] );
			$d['pages'] = array_slice( $d['pages'], 0, 25, true );
		}
		$data['days'][ $day ] = $d;
		// Visitor hashes: today's and yesterday's only, capped; anything older is dropped.
		$yday         = gmdate( 'Y-m-d', strtotime( $day . ' -1 day' ) );
		$old          = isset( $data['seen'][ $yday ] ) ? $data['seen'][ $yday ] : array();
		$data['seen'] = array( $day => count( $seen ) > 20000 ? array_slice( $seen, -20000, null, true ) : $seen );
		if ( $old ) {
			$data['seen'][ $yday ] = $old;
		}
		ksort( $data['days'] );
		$data['days'] = array_slice( $data['days'], -self::KEEP, null, true );
		return $data;
	}

	/**
	 * Count one page view.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function hit( $request ) {
		$ok = new WP_REST_Response( null, 204 );
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( ! self::enabled() || '' === $ua || preg_match( self::BOTS, $ua ) ) {
			return $ok;
		}
		$body = json_decode( (string) $request->get_body(), true );
		$path = is_array( $body ) && isset( $body['p'] ) ? substr( sanitize_text_field( (string) $body['p'] ), 0, 200 ) : '';
		if ( '' === $path || '/' !== $path[0] || 0 === strpos( $path, '/wp-' ) ) {
			return $ok;
		}
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$day  = wp_date( 'Y-m-d' );
		$hash = substr( hash( 'sha256', $ip . '|' . $ua . '|' . self::salt( $day ) ), 0, 16 );
		$rate = 'lasr_vr_' . substr( $hash, 0, 12 );
		$n    = (int) get_transient( $rate );
		if ( $n >= 60 ) { // one visitor can't add more than 60 views a minute
			return $ok;
		}
		set_transient( $rate, $n + 1, MINUTE_IN_SECONDS );
		$own    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$source = self::source( isset( $body['r'] ) ? (string) $body['r'] : '', isset( $body['u'] ) ? (string) $body['u'] : '', strtolower( $own ) );
		$data   = get_option( self::OPTION );
		update_option( self::OPTION, self::add( is_array( $data ) ? $data : array(), $day, $path, $source, $hash ), false );
		return $ok;
	}

	/**
	 * Today's salt; a new random one each day, so yesterday's hashes can't be matched to today's.
	 *
	 * @param string $day Y-m-d.
	 * @return string
	 */
	private static function salt( $day ) {
		$s = get_option( self::SALT );
		if ( ! is_array( $s ) || ! isset( $s['day'] ) || $s['day'] !== $day ) {
			$s = array(
				'day'  => $day,
				'salt' => wp_generate_password( 32, true, true ),
			);
			update_option( self::SALT, $s, false );
		}
		return $s['salt'];
	}

	/**
	 * Summary for the card, the email and the read endpoint (pure over stored days).
	 *
	 * @param array  $data  Stored data.
	 * @param string $today Y-m-d.
	 * @return array
	 */
	public static function summary( array $data, $today ) {
		$days = isset( $data['days'] ) ? $data['days'] : array();
		$get  = function ( $offset ) use ( $days, $today ) {
			$d = gmdate( 'Y-m-d', strtotime( $today . ' -' . $offset . ' day' ) );
			return isset( $days[ $d ] ) ? $days[ $d ] : null;
		};
		$sum = function ( $from, $to, $key ) use ( $get ) {
			$n = 0;
			for ( $i = $from; $i <= $to; $i++ ) {
				$d  = $get( $i );
				$n += $d ? (int) $d[ $key ] : 0;
			}
			return $n;
		};
		$pages = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$d = $get( $i );
			foreach ( $d ? (array) $d['pages'] : array() as $p => $n ) {
				$pages[ $p ] = ( isset( $pages[ $p ] ) ? $pages[ $p ] : 0 ) + (int) $n;
			}
		}
		arsort( $pages );
		$first = $days ? array_key_first( $days ) : '';
		return array(
			'since'          => $first,
			'today'          => $sum( 0, 0, 'uniques' ),
			'yesterday'      => $sum( 1, 1, 'uniques' ),
			'day_before'     => $sum( 2, 2, 'uniques' ),
			'week'           => $sum( 0, 6, 'uniques' ),
			'prev_week'      => $sum( 7, 13, 'uniques' ),
			'views_week'     => $sum( 0, 6, 'views' ),
			'ai_week'        => $sum( 0, 6, 'ai' ),
			'search_week'    => $sum( 0, 6, 'search' ),
			'direct_week'    => $sum( 0, 6, 'direct' ),
			'other_week'     => $sum( 0, 6, 'other' ),
			'top_pages'      => array_slice( $pages, 0, 5, true ),
			'full_day_ready' => '' !== $first && $first < $today,
		);
	}

	/**
	 * Read endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function read() {
		$data = get_option( self::OPTION );
		return new WP_REST_Response(
			array(
				'enabled' => self::enabled(),
				'summary' => self::summary( is_array( $data ) ? $data : array(), wp_date( 'Y-m-d' ) ),
				'days'    => is_array( $data ) && isset( $data['days'] ) ? $data['days'] : array(),
			),
			200
		);
	}

	/**
	 * The Overview card.
	 */
	public static function card() {
		$data  = get_option( self::OPTION );
		$s     = self::summary( is_array( $data ) ? $data : array(), wp_date( 'Y-m-d' ) );
		$delta = function ( $now, $before ) {
			$d = (int) $now - (int) $before;
			return 0 === $d ? __( 'same as the day before', 'leymish-ai-shopping-readiness' ) : sprintf( /* translators: %s: signed change. */ __( '%s on the day before', 'leymish-ai-shopping-readiness' ), ( $d > 0 ? '+' : '' ) . $d );
		};
		echo '<section class="lasr-card-box lasr-visitors" aria-labelledby="lasr-visitors-h"><h2 id="lasr-visitors-h">' . esc_html__( 'Visitors', 'leymish-ai-shopping-readiness' ) . '</h2>';
		if ( ! self::enabled() ) {
			echo '<p>' . esc_html__( 'Visitor counting is off.', 'leymish-ai-shopping-readiness' ) . '</p>';
		} elseif ( ! $s['full_day_ready'] ) {
			/* translators: %s: date counting started. */
			echo '<p>' . esc_html( $s['since'] ? sprintf( __( 'Counting started %s. The first full day shows here tomorrow.', 'leymish-ai-shopping-readiness' ), $s['since'] ) : __( 'Counting starts with the next visit to your store.', 'leymish-ai-shopping-readiness' ) ) . '</p>';
		} else {
			echo '<ul class="lasr-kpis">';
			/* translators: %s: change on the day before. */
			echo '<li><span class="lasr-big">' . esc_html( (string) $s['yesterday'] ) . '</span> ' . esc_html__( 'visitors yesterday', 'leymish-ai-shopping-readiness' ) . ' <span class="description">(' . esc_html( $delta( $s['yesterday'], $s['day_before'] ) ) . ')</span></li>';
			echo '<li><span class="lasr-big">' . esc_html( (string) $s['week'] ) . '</span> ' . esc_html__( 'in the last 7 days', 'leymish-ai-shopping-readiness' ) . '</li>';
			echo '<li><span class="lasr-big">' . esc_html( (string) $s['ai_week'] ) . '</span> ' . esc_html__( 'from AI assistants (7 days)', 'leymish-ai-shopping-readiness' ) . '</li></ul>';
			/* translators: 1: search, 2: direct, 3: other. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Also in 7 days: %1$d from search, %2$d direct, %3$d from other sites.', 'leymish-ai-shopping-readiness' ), $s['search_week'], $s['direct_week'], $s['other_week'] ) ) . '</p>';
			if ( $s['top_pages'] ) {
				echo '<h3>' . esc_html__( 'Top landing pages (7 days)', 'leymish-ai-shopping-readiness' ) . '</h3><ol class="lasr-list">';
				foreach ( $s['top_pages'] as $p => $n ) {
					echo '<li><code>' . esc_html( $p ) . '</code> ' . esc_html( (string) $n ) . '</li>';
				}
				echo '</ol>';
			}
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lasr_visitors_toggle' );
		echo '<input type="hidden" name="action" value="lasr_visitors_toggle" /><p class="description">' . esc_html__( 'Cookieless and first-party: counts only, kept on this site; staff and bots are not counted.', 'leymish-ai-shopping-readiness' ) . ' ';
		echo '<button type="submit" class="button-link">' . esc_html( self::enabled() ? __( 'Turn visitor counting off', 'leymish-ai-shopping-readiness' ) : __( 'Turn visitor counting on', 'leymish-ai-shopping-readiness' ) ) . '</button></p></form></section>';
	}

	/**
	 * On/off.
	 */
	public static function handle_toggle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_visitors_toggle' );
		update_option( self::ON, self::enabled() ? 'no' : 'yes', false );
		wp_safe_redirect( admin_url( 'admin.php?page=lasr-ai-readiness' ) );
		exit;
	}

	/**
	 * Suggested text for the store's privacy policy (Settings → Privacy).
	 */
	public static function privacy_text() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'LeyMish AI Readiness',
				'<p>' . esc_html__( 'This site counts visits with LeyMish AI Readiness, without cookies. For each page view it records the page, and whether the visit came from an AI assistant, a search engine, another site or directly. To count unique visitors it keeps a one-way hash of your IP address and browser name, made with a random value that changes every day, for at most two days. Your IP address and browser name are not stored, and the counts stay on this site.', 'leymish-ai-shopping-readiness' ) . '</p>'
			);
		}
	}
}
