<?php
/**
 * LeyMish Pro licence (2.0). Pro is a service: the licence only unlocks calls to LeyMish's service (AI visibility,
 * AI fixes, outside monitoring, Store Team agents). Everything that runs on this site works without it.
 *
 * The key is checked by LeyMish's service, never by this plugin calling a payment provider. A founding Store Team
 * beta store (and our partner store) gets Pro included while that lasts; the service says so.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * State: key, status (valid|invalid|unreachable|none), plan, checked_at, valid_at, message.
 */
class LASR_License {

	const OPTION     = 'lasr_license';
	const STATUS_KEY = 'lasr_pro_status';
	const GRACE      = 1209600; // 14 days of Pro when the service can't be reached after a good check.

	/**
	 * Weekly re-check.
	 */
	public static function init() {
		add_action( 'lasr_license_recheck', array( __CLASS__, 'recheck' ) );
		if ( ! wp_next_scheduled( 'lasr_license_recheck' ) && '' !== self::key() ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', 'lasr_license_recheck' );
		}
	}

	/**
	 * Saved state.
	 *
	 * @return array
	 */
	public static function state() {
		$s = get_option( self::OPTION );
		return is_array( $s ) ? $s + array(
			'plan'    => '',
			'message' => '',
		) : array(
			'key'        => '',
			'status'     => 'none',
			'plan'       => '',
			'checked_at' => 0,
			'valid_at'   => 0,
			'message'    => '',
		);
	}

	/**
	 * The saved key ('' if none).
	 *
	 * @return string
	 */
	public static function key() {
		$s = self::state();
		return isset( $s['key'] ) ? (string) $s['key'] : '';
	}

	/**
	 * A valid licence, or a recent valid check while the service is unreachable.
	 *
	 * @return bool
	 */
	public static function has_licence() {
		$s = self::state();
		if ( 'valid' === $s['status'] ) {
			return true;
		}
		return 'unreachable' === $s['status'] && ( time() - (int) $s['valid_at'] ) < self::GRACE;
	}

	/**
	 * Pro is on: a licence, or Pro included for a founding beta or partner store (asked only when connected).
	 *
	 * @return bool
	 */
	public static function is_pro() {
		return self::has_licence() || 'free' !== self::included()['source'];
	}

	/**
	 * Pro included through Store Team (founding beta, our partner store), cached for 12 hours. Only a connected
	 * store asks; an unconnected one is simply free.
	 *
	 * @return array{source:string,ends_at:int|null}
	 */
	public static function included() {
		$none = array(
			'source'  => 'free',
			'ends_at' => null,
		);
		if ( ! class_exists( 'LASR_Team' ) || ! LASR_Team::connection() ) {
			return $none;
		}
		$c = get_transient( self::STATUS_KEY );
		if ( is_array( $c ) ) {
			return $c;
		}
		$conn = LASR_Team::connection();
		$r    = LASR_Service::post( '/v1/pro/status', array( 'site' => LASR_Service::site() ), $conn['site_token'] );
		$out  = 200 === $r['status'] && ! empty( $r['data']['pro'] ) ? array(
			'source'  => sanitize_key( (string) $r['data']['source'] ),
			'ends_at' => isset( $r['data']['ends_at'] ) ? (int) $r['data']['ends_at'] : null,
		) : $none;
		set_transient( self::STATUS_KEY, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Activate a key on this site.
	 *
	 * @param string $key Licence key.
	 * @return array New state.
	 */
	public static function activate( $key ) {
		return self::verify( sanitize_text_field( (string) $key ) );
	}

	/**
	 * Weekly re-check.
	 */
	public static function recheck() {
		if ( '' !== self::key() ) {
			self::verify( self::key() );
		}
	}

	/**
	 * Remove the key from this site.
	 */
	public static function deactivate() {
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( 'lasr_license_recheck' );
		if ( class_exists( 'LASR_Team' ) ) {
			LASR_Team::sync_licence();
		}
	}

	/**
	 * Ask LeyMish's service whether the key is active for this site, and save the answer.
	 *
	 * @param string $key Licence key.
	 * @return array New state.
	 */
	private static function verify( $key ) {
		$prev  = self::state();
		$state = array(
			'key'        => $key,
			'status'     => 'invalid',
			'plan'       => '',
			'checked_at' => time(),
			'valid_at'   => (int) $prev['valid_at'],
			'message'    => '',
		);
		if ( '' === $key ) {
			$state['message'] = __( 'Enter the licence key from your receipt.', 'leymish-ai-shopping-readiness' );
			return $state;
		}
		$r = LASR_Service::post(
			'/v1/license/check',
			array(
				'site'        => LASR_Service::site(),
				'license_key' => $key,
			)
		);
		if ( 0 === $r['status'] || $r['status'] >= 500 ) {
			$state['status']  = in_array( $prev['status'], array( 'valid', 'unreachable' ), true ) ? 'unreachable' : 'invalid';
			$state['message'] = __( 'Could not reach LeyMish to check the licence. Pro stays on for up to 14 days after the last good check.', 'leymish-ai-shopping-readiness' );
		} elseif ( ! empty( $r['data']['active'] ) ) {
			$state['status']   = 'valid';
			$state['plan']     = sanitize_key( (string) $r['data']['plan'] );
			$state['valid_at'] = time();
			$state['message']  = __( 'LeyMish Pro is active on this store.', 'leymish-ai-shopping-readiness' );
		} else {
			$state['message'] = isset( $r['data']['error'] ) ? sanitize_text_field( (string) $r['data']['error'] ) : __( 'That key is not an active LeyMish Pro licence.', 'leymish-ai-shopping-readiness' );
		}
		update_option( self::OPTION, $state, false );
		if ( class_exists( 'LASR_Team' ) ) {
			LASR_Team::sync_licence();
		}
		return $state;
	}

	/**
	 * The plan name in plain words.
	 *
	 * @return string
	 */
	public static function plan_label() {
		$s = self::state();
		if ( self::has_licence() ) {
			return in_array( $s['plan'], array( 'agency', 'pro_ai_agency' ), true ) ? __( 'LeyMish Pro Agency', 'leymish-ai-shopping-readiness' ) : __( 'LeyMish Pro', 'leymish-ai-shopping-readiness' );
		}
		$inc = self::included();
		if ( 'beta' === $inc['source'] ) {
			return __( 'LeyMish Pro (included with the founding beta)', 'leymish-ai-shopping-readiness' );
		}
		if ( 'partner' === $inc['source'] ) {
			return __( 'LeyMish Pro (partner store)', 'leymish-ai-shopping-readiness' );
		}
		return __( 'Free', 'leymish-ai-shopping-readiness' );
	}
}
