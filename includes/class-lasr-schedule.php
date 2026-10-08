<?php
/**
 * Weekly monitoring (2.0). Free, on this site: re-run the audit, compare with last week, raise alerts (products that
 * lost identifiers, AI crawlers newly blocked in robots.txt, feeds that failed to build) and email the summary.
 * LeyMish Pro adds the outside check from LeyMish's service: AI crawlers blocked by a firewall or CDN (which a store
 * can't see from inside), and the feeds as OpenAI and Google would fetch them.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekly job, alerts and the email.
 */
class LASR_Schedule {

	const EMAIL_OPT = 'lasr_weekly_email';
	const ALERTS    = 'lasr_alerts';
	const OUTSIDE   = 'lasr_outside';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'lasr_weekly', array( __CLASS__, 'weekly' ) );
		add_action( 'admin_post_lasr_dismiss_alerts', array( __CLASS__, 'handle_dismiss' ) );
		if ( ! wp_next_scheduled( 'lasr_weekly' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'lasr_weekly' );
		}
	}

	/**
	 * Remove the scheduled jobs (deactivation).
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( 'lasr_weekly' );
		wp_clear_scheduled_hook( 'lasr_license_recheck' );
		wp_clear_scheduled_hook( 'lasr_rebuild_feeds' );
		wp_clear_scheduled_hook( 'lasr_visibility_weekly' );
	}

	/**
	 * The weekly run.
	 */
	public static function weekly() {
		$before = LASR_Audit::last();
		$result = LASR_Audit::run();
		$alerts = self::compare( $before, $result, get_option( LASR_Feeds::BUILT ) );
		if ( LASR_License::is_pro() ) {
			$outside = self::outside();
			if ( $outside ) {
				$alerts = array_merge( $alerts, self::outside_alerts( $outside ) );
			}
		}
		if ( $alerts ) {
			update_option( self::ALERTS, array( 't' => time(), 'items' => $alerts ), false );
		}
		if ( 'yes' === get_option( self::EMAIL_OPT, 'yes' ) ) {
			wp_mail( get_option( 'admin_email' ), self::subject( $result, $before ), self::body( $result, $before, $alerts ) );
		}
	}

	/**
	 * Pro: the outside check from LeyMish's service (once a day at most; the service enforces it too).
	 *
	 * @return array|null
	 */
	public static function outside() {
		$body = array( 'site' => LASR_Service::site() );
		$key  = LASR_License::key();
		if ( '' !== $key && LASR_License::has_licence() ) {
			$body['license_key'] = $key;
		}
		$conn = class_exists( 'LASR_Team' ) ? LASR_Team::connection() : null;
		$r    = LASR_Service::post( '/v1/monitor', $body, $conn ? $conn['site_token'] : '', 45 );
		if ( 200 !== $r['status'] || empty( $r['data']['ok'] ) ) {
			return null;
		}
		$keep = array(
			't'        => time(),
			'firewall' => isset( $r['data']['firewall'] ) ? $r['data']['firewall'] : array(),
			'feeds'    => isset( $r['data']['feeds'] ) ? $r['data']['feeds'] : array(),
			'score'    => isset( $r['data']['outside']['score'] ) ? (int) $r['data']['outside']['score'] : null,
		);
		update_option( self::OUTSIDE, $keep, false );
		return $keep;
	}

	/**
	 * What got worse since last week, from this site's own audits (pure).
	 *
	 * @param array|null  $before Previous audit.
	 * @param array       $after  This audit.
	 * @param array|false $built  Feed build record.
	 * @return string[] Alerts in plain words.
	 */
	public static function compare( $before, array $after, $built ) {
		$out = array();
		if ( is_array( $before ) ) {
			$had = self::with_identifier( $before );
			$has = self::with_identifier( $after );
			if ( $has < $had ) {
				/* translators: %d: number of products. */
				$out[] = sprintf( _n( '%d product lost its GTIN, MPN or brand since last week.', '%d products lost their GTIN, MPN or brand since last week.', $had - $has, 'leymish-ai-shopping-readiness' ), $had - $has );
			}
			$was = self::statuses( $before );
			foreach ( self::statuses( $after ) as $id => $status ) {
				if ( in_array( $id, array( 'robots', 'bot_block', 'public' ), true ) && 'fail' === $status && isset( $was[ $id ] ) && 'fail' !== $was[ $id ] ) {
					/* translators: %s: check name. */
					$out[] = sprintf( __( 'AI crawlers are blocked now: "%s" passed last week and fails today.', 'leymish-ai-shopping-readiness' ), self::label( $after, $id ) );
				}
			}
		}
		if ( is_array( $built ) && isset( $built['written'] ) && in_array( false, (array) $built['written'], true ) ) {
			$out[] = __( 'A product feed could not be written. Check that your uploads folder is writable.', 'leymish-ai-shopping-readiness' );
		}
		return $out;
	}

	/**
	 * Alerts from Pro's outside check (pure).
	 *
	 * @param array $o Outside result.
	 * @return string[]
	 */
	public static function outside_alerts( array $o ) {
		$out = array();
		if ( ! empty( $o['firewall']['blocked'] ) ) {
			/* translators: %s: crawler names. */
			$out[] = sprintf( __( 'Your firewall or CDN blocks these AI crawlers, although robots.txt lets them in: %s. Ask your host or CDN to allow them.', 'leymish-ai-shopping-readiness' ), implode( ', ', array_map( 'sanitize_text_field', (array) $o['firewall']['blocked'] ) ) );
		}
		foreach ( array( 'openai' => 'OpenAI', 'google' => 'Google' ) as $k => $name ) {
			if ( isset( $o['feeds'][ $k ]['status'] ) && 200 === (int) $o['feeds'][ $k ]['status'] && empty( $o['feeds'][ $k ]['ok'] ) ) {
				/* translators: %s: OpenAI or Google. */
				$out[] = sprintf( __( 'Your %s feed answers from outside but has errors. Open the Feeds tab.', 'leymish-ai-shopping-readiness' ), $name );
			}
		}
		return $out;
	}

	/**
	 * Products with a valid identifier and a brand.
	 *
	 * @param array $result Audit.
	 * @return int
	 */
	private static function with_identifier( array $result ) {
		$n = 0;
		foreach ( (array) ( isset( $result['products'] ) ? $result['products'] : array() ) as $p ) {
			if ( ! array_intersect( array( 'identifier', 'brand' ), (array) $p['missing'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Check id => status.
	 *
	 * @param array $result Audit.
	 * @return array
	 */
	private static function statuses( array $result ) {
		$out = array();
		foreach ( (array) $result['checks'] as $c ) {
			$out[ $c['id'] ] = $c['status'];
		}
		return $out;
	}

	/**
	 * A check's label.
	 *
	 * @param array  $result Audit.
	 * @param string $id     Check id.
	 * @return string
	 */
	private static function label( array $result, $id ) {
		foreach ( (array) $result['checks'] as $c ) {
			if ( $c['id'] === $id ) {
				return $c['label'];
			}
		}
		return $id;
	}

	/**
	 * Current alerts (empty when none or dismissed).
	 *
	 * @return array{t:int,items:string[]}|null
	 */
	public static function alerts() {
		$a = get_option( self::ALERTS );
		return is_array( $a ) && ! empty( $a['items'] ) ? $a : null;
	}

	/**
	 * Dismiss the alerts.
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_dismiss_alerts' );
		delete_option( self::ALERTS );
		wp_safe_redirect( admin_url( 'admin.php?page=' . LASR_Admin::SLUG ) );
		exit;
	}

	/**
	 * Email subject.
	 *
	 * @param array      $result Audit.
	 * @param array|null $prev   Previous audit.
	 * @return string
	 */
	public static function subject( array $result, $prev ) {
		/* translators: 1: store name, 2: score. */
		$subject = sprintf( __( '%1$s: AI shopping readiness %2$d/100', 'leymish-ai-shopping-readiness' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), (int) $result['score'] );
		if ( is_array( $prev ) ) {
			$delta = (int) $result['score'] - (int) $prev['score'];
			/* translators: %s: signed change since the last audit, e.g. +4. */
			$subject .= ' ' . sprintf( __( '(%s since last time)', 'leymish-ai-shopping-readiness' ), ( $delta >= 0 ? '+' : '' ) . $delta );
		}
		return $subject;
	}

	/**
	 * Plain-text email: score, alerts, the top three fixes.
	 *
	 * @param array      $result Audit.
	 * @param array|null $prev   Previous audit.
	 * @param string[]   $alerts Alerts.
	 * @return string
	 */
	public static function body( array $result, $prev, array $alerts = array() ) {
		/* translators: %d: score. */
		$lines = array( sprintf( __( 'This week\'s score: %d/100.', 'leymish-ai-shopping-readiness' ), (int) $result['score'] ) );
		if ( is_array( $prev ) ) {
			/* translators: %d: previous score. */
			$lines[] = sprintf( __( 'Last time: %d/100.', 'leymish-ai-shopping-readiness' ), (int) $prev['score'] );
		}
		if ( $alerts ) {
			$lines[] = '';
			$lines[] = __( 'Needs your attention:', 'leymish-ai-shopping-readiness' );
			foreach ( $alerts as $a ) {
				$lines[] = '- ' . $a;
			}
		}
		$fixes = array_slice( LASR_Scoring::fix_list( $result['checks'] ), 0, 3 );
		if ( $fixes ) {
			$lines[] = '';
			$lines[] = __( 'Fix these first:', 'leymish-ai-shopping-readiness' );
			foreach ( $fixes as $i => $f ) {
				/* translators: 1: position, 2: check name, 3: points gained, 4: what was found. */
				$lines[] = sprintf( __( '%1$d. %2$s (+%3$s points): %4$s', 'leymish-ai-shopping-readiness' ), $i + 1, $f['label'], $f['lost'], $f['detail'] );
			}
		}
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=lasr-ai-readiness' ); // literal: this runs from WP-Cron, where the admin classes aren't loaded
		$lines[] = '';
		$lines[] = __( 'Turn this email off in LeyMish → Plan.', 'leymish-ai-shopping-readiness' );
		return implode( "\n", $lines );
	}
}
