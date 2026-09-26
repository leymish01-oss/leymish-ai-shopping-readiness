<?php
/**
 * WP-CLI: wp lasr audit [--format=table|json]
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run the AI shopping readiness audit from the command line.
 */
class LASR_CLI {

	/**
	 * Run the audit and print the score and each check.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp lasr audit
	 *     wp lasr audit --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function audit( $args, $assoc_args ) {
		$result = LASR_Audit::run();
		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			WP_CLI::line( (string) wp_json_encode( $result ) );
			return;
		}
		WP_CLI::line( sprintf( 'Score: %d/100 (%s)', $result['score'], $result['band'] ) );
		$rows = array();
		foreach ( $result['checks'] as $c ) {
			$rows[] = array(
				'check'  => $c['id'],
				'status' => $c['status'],
				'points' => $c['earned'] . '/' . $c['points'],
				'detail' => $c['detail'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'status', 'points', 'detail' ) );
	}
}
