<?php
/**
 * Minimal robots.txt evaluator (RFC 9309 group matching, longest-match Allow/Disallow, * and $).
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helper with no WordPress dependencies.
 */
class LASR_Robots {

	/**
	 * AI crawlers the audit cares about: robots.txt token => user agent used for the live request test.
	 * Google-Extended is a robots.txt token only (Google crawls with Googlebot), so it has no user agent.
	 *
	 * @return array<string,string>
	 */
	public static function ai_bots() {
		return array(
			// OpenAI (developers.openai.com/api/docs/bots). ChatGPT-User fetches on a user's request and
			// "robots.txt rules may not apply" to it, so a firewall block matters more than a robots rule.
			'GPTBot'           => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.4; +https://openai.com/gptbot',
			'OAI-SearchBot'    => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.4; +https://openai.com/searchbot',
			'ChatGPT-User'     => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot',
			// Anthropic: training, search indexing, and user-requested fetches.
			'ClaudeBot'        => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
			'Claude-SearchBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0; +claude-searchbot@anthropic.com)',
			'Claude-User'      => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +claude-user@anthropic.com)',
			// Perplexity: search indexing, and user-requested fetches (which may not follow robots.txt).
			'PerplexityBot'    => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
			'Perplexity-User'  => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)',
			// Google-Extended is a robots.txt token only (Google crawls with Googlebot), so it has no user agent.
			'Google-Extended'  => '',
		);
	}

	/**
	 * Parse robots.txt into groups of user agents with their rules.
	 *
	 * @param string $robots File contents.
	 * @return array<int,array{agents:string[],rules:array<int,array{0:string,1:string}>}>
	 */
	public static function parse( $robots ) {
		$groups  = array();
		$current = null;
		$last    = '';
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $robots ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );
			if ( 'user-agent' === $field ) {
				if ( null === $current || 'agent' !== $last ) {
					$groups[] = array(
						'agents' => array(),
						'rules'  => array(),
					);
					$current  = count( $groups ) - 1;
				}
				$groups[ $current ]['agents'][] = strtolower( $value );
				$last                           = 'agent';
			} elseif ( ( 'allow' === $field || 'disallow' === $field ) && null !== $current ) {
				$groups[ $current ]['rules'][] = array( $field, $value );
				$last                          = 'rule';
			}
		}
		return $groups;
	}

	/**
	 * Whether a crawler token may fetch a path.
	 *
	 * @param string $robots File contents.
	 * @param string $token  Product token, e.g. "GPTBot".
	 * @param string $path   URL path, e.g. "/product/mug/".
	 * @return bool
	 */
	public static function is_allowed( $robots, $token, $path = '/' ) {
		$groups   = self::parse( $robots );
		$token    = strtolower( $token );
		$matching = array();
		foreach ( $groups as $g ) {
			if ( in_array( $token, $g['agents'], true ) ) {
				$matching[] = $g;
			}
		}
		if ( ! $matching ) {
			foreach ( $groups as $g ) {
				if ( in_array( '*', $g['agents'], true ) ) {
					$matching[] = $g;
				}
			}
		}
		$best_len   = -1;
		$best_allow = true;
		foreach ( $matching as $g ) {
			foreach ( $g['rules'] as $rule ) {
				list( $type, $pattern ) = $rule;
				if ( '' === $pattern ) {
					continue; // "Disallow:" with no value allows everything.
				}
				if ( self::matches( $pattern, $path ) ) {
					$len   = strlen( $pattern );
					$allow = ( 'allow' === $type );
					if ( $len > $best_len || ( $len === $best_len && $allow ) ) {
						$best_len   = $len;
						$best_allow = $allow;
					}
				}
			}
		}
		return $best_allow;
	}

	/**
	 * Robots pattern match with * wildcards and a trailing $ anchor.
	 *
	 * @param string $pattern Rule path pattern.
	 * @param string $path    URL path.
	 * @return bool
	 */
	public static function matches( $pattern, $path ) {
		$anchored = ( '$' === substr( $pattern, -1 ) );
		if ( $anchored ) {
			$pattern = substr( $pattern, 0, -1 );
		}
		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . ( $anchored ? '$' : '' ) . '#';
		return 1 === preg_match( $regex, $path );
	}
}
