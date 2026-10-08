<?php
/**
 * The one way this plugin talks to LeyMish's service (2.0). Every outside call goes through here, to one host, and
 * only after the store owner acts: clicking a button, connecting the store, or entering a licence. Nothing is sent
 * on a plain page view. Each call is listed under "External services" in readme.txt.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small JSON client for https://leymish-ai.leymish.workers.dev.
 */
class LASR_Service {

	const BASE = 'https://leymish-ai.leymish.workers.dev';

	/**
	 * Base URL (filterable so tests and staging can point elsewhere).
	 *
	 * @return string
	 */
	public static function base() {
		return untrailingslashit( (string) apply_filters( 'lasr_service_base', self::BASE ) );
	}

	/**
	 * POST JSON.
	 *
	 * @param string $path    Path such as /v1/visibility/check.
	 * @param array  $body    JSON body.
	 * @param string $token   Optional Store Team site token (Authorization: Bearer).
	 * @param int    $timeout Seconds.
	 * @return array{status:int,data:array}
	 */
	public static function post( $path, array $body = array(), $token = '', $timeout = 20 ) {
		$args = array(
			'timeout' => $timeout,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		);
		if ( '' !== (string) $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}
		return self::decode( wp_remote_post( self::base() . $path, $args ) );
	}

	/**
	 * GET JSON (public data only, such as the live demo or the beta seat count).
	 *
	 * @param string $path Path.
	 * @return array{status:int,data:array}
	 */
	public static function get( $path ) {
		return self::decode( wp_remote_get( self::base() . $path, array( 'timeout' => 10 ) ) );
	}

	/**
	 * Status and decoded JSON body.
	 *
	 * @param array|WP_Error $r Response.
	 * @return array{status:int,data:array}
	 */
	private static function decode( $r ) {
		if ( is_wp_error( $r ) ) {
			return array(
				'status' => 0,
				'data'   => array( 'error' => $r->get_error_message() ),
			);
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $r ),
			'data'   => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * The store's address as the service knows it.
	 *
	 * @return string
	 */
	public static function site() {
		return home_url( '/' );
	}
}
