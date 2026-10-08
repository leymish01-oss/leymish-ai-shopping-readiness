<?php
/**
 * The dashboard pieces shared by the Impact and Audit tabs (1.4.0): the score hero, the four category cards,
 * "Your biggest wins" and the "Free vs Pro for your store" panel.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helpers (grade, categories, verdict, wins, panel rows) plus their HTML. Charts are inline SVG, and every
 * chart has its numbers in text or a screen-reader table next to it.
 */
class LASR_Dashboard {

	const DISMISS_META = 'lasr_pro_panel_dismissed';
	const DISMISS_DAYS = 30;
	const SITE         = 'https://www.leymish.com';
	// WOOLAUNCH ($10 off Pro) runs until the end of 30 November 2026 (UTC); after that the line disappears by itself.
	const LAUNCH_ENDS  = 1796083200;

	/**
	 * Letter grade for a score: A 90+, B 80+, C 65+, D 50+, F below.
	 *
	 * @param int $score 0..100.
	 * @return string
	 */
	public static function grade( $score ) {
		$score = (int) $score;
		if ( $score >= 90 ) {
			return 'A';
		}
		if ( $score >= 80 ) {
			return 'B';
		}
		if ( $score >= 65 ) {
			return 'C';
		}
		return $score >= 50 ? 'D' : 'F';
	}

	/**
	 * The four categories and the checks in each. Checks not listed here still appear in the full table.
	 *
	 * @return array
	 */
	public static function category_map() {
		return array(
			'access'   => array( __( 'Access', 'leymish-ai-shopping-readiness' ), __( 'Robots, crawlers and what they receive', 'leymish-ai-shopping-readiness' ), array( 'public', 'robots', 'bot_block', 'no_js', 'llms_txt' ) ),
			'product'  => array( __( 'Product data', 'leymish-ai-shopping-readiness' ), __( 'GTIN, brand, attributes, images and structured data', 'leymish-ai-shopping-readiness' ), array( 'catalog', 'jsonld' ) ),
			'api'      => array( __( 'Store API', 'leymish-ai-shopping-readiness' ), __( 'The product feed apps and agents read', 'leymish-ai-shopping-readiness' ), array( 'store_api' ) ),
			'checkout' => array( __( 'AI checkout readiness', 'leymish-ai-shopping-readiness' ), __( 'Guest checkout, UCP and ACP', 'leymish-ai-shopping-readiness' ), array( 'guest', 'ucp', 'acp', 'mcp' ) ),
		);
	}

	/**
	 * Roll the checks up into the four categories.
	 *
	 * @param array $checks Check results.
	 * @return array[] key, label, about, earned, points, percent, status (pass|warn|fail|skip|info), needs (labels).
	 */
	public static function categories( array $checks ) {
		$by_id = array();
		foreach ( $checks as $c ) {
			$by_id[ $c['id'] ] = $c;
		}
		$out = array();
		foreach ( self::category_map() as $key => $def ) {
			$earned  = 0.0;
			$points  = 0;
			$skipped = 0;
			$present = 0;
			$needs   = array();
			foreach ( $def[2] as $id ) {
				if ( ! isset( $by_id[ $id ] ) ) {
					continue;
				}
				$c = $by_id[ $id ];
				$present++;
				if ( 'skip' === $c['status'] ) {
					$skipped++;
					continue;
				}
				if ( 'info' === $c['status'] || empty( $c['points'] ) ) {
					continue;
				}
				$points += (int) $c['points'];
				$earned += max( 0.0, min( (float) $c['earned'], (float) $c['points'] ) );
				if ( 'pass' !== $c['status'] ) {
					$needs[] = $c['label'];
				}
			}
			if ( $points > 0 ) {
				$pct    = (int) round( 100 * $earned / $points );
				$status = LASR_Scoring::status_for( $earned / $points );
			} else {
				$pct    = null;
				$status = $skipped ? 'skip' : 'info';
			}
			$out[] = array(
				'key'     => $key,
				'label'   => $def[0],
				'about'   => $def[1],
				'earned'  => round( $earned, 1 ),
				'points'  => $points,
				'percent' => $pct,
				'status'  => $present ? $status : 'info',
				'needs'   => $needs,
				'present' => $present,
				'skipped' => $skipped,
			);
		}
		return $out;
	}

	/**
	 * Products missing a valid identifier or a brand.
	 *
	 * @param array $products Audit product rows.
	 * @return int
	 */
	public static function identifier_gaps( $products ) {
		$n = 0;
		foreach ( (array) $products as $p ) {
			if ( ! empty( $p['missing'] ) && array_intersect( array( 'identifier', 'brand' ), (array) $p['missing'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Products with any gap at all.
	 *
	 * @param array $products Audit product rows.
	 * @return int
	 */
	public static function products_with_gaps( $products ) {
		$n = 0;
		foreach ( (array) $products as $p ) {
			if ( ! empty( $p['missing'] ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * One plain sentence about where the store stands.
	 *
	 * @param array $result Last audit.
	 * @return string
	 */
	public static function verdict( array $result ) {
		$checks = (array) $result['checks'];
		$scored = 0;
		foreach ( $checks as $c ) {
			if ( ! in_array( $c['status'], array( 'info', 'skip' ), true ) && ! empty( $c['points'] ) ) {
				$scored++;
			}
		}
		if ( 0 === $scored ) {
			return __( 'We could not test enough of your store to score it. Run the audit again in a minute.', 'leymish-ai-shopping-readiness' );
		}
		$by_id = array();
		foreach ( $checks as $c ) {
			$by_id[ $c['id'] ] = $c;
		}
		$partial = false;
		foreach ( array( 'public', 'robots', 'bot_block', 'jsonld', 'no_js' ) as $id ) {
			if ( isset( $by_id[ $id ] ) && 'skip' === $by_id[ $id ]['status'] ) {
				$partial = true;
			}
		}
		$prefix = $partial ? __( 'Partial result: some of your pages didn\'t answer in time. ', 'leymish-ai-shopping-readiness' ) : '';
		return $prefix . self::verdict_text( $checks, $by_id, $result );
	}

	/**
	 * The verdict sentence itself (verdict() adds the partial-result prefix).
	 *
	 * @param array $checks Check results.
	 * @param array $by_id  Checks by id.
	 * @param array $result Last audit.
	 * @return string
	 */
	private static function verdict_text( array $checks, array $by_id, array $result ) {
		foreach ( array( 'public', 'robots', 'bot_block' ) as $id ) {
			if ( isset( $by_id[ $id ] ) && 'fail' === $by_id[ $id ]['status'] ) {
				/* translators: %s: name of the failing check. */
				return sprintf( __( 'AI shopping agents can\'t fully reach your store yet: start with "%s".', 'leymish-ai-shopping-readiness' ), $by_id[ $id ]['label'] );
			}
		}
		$gaps = self::identifier_gaps( isset( $result['products'] ) ? $result['products'] : array() );
		if ( $gaps > 0 ) {
			return sprintf(
				/* translators: %d: number of products. */
				_n(
					'AI shopping agents can read your store, but %d product is missing details they use to match it.',
					'AI shopping agents can read your store, but %d products are missing details they use to match them.',
					$gaps,
					'leymish-ai-shopping-readiness'
				),
				$gaps
			);
		}
		$fixes = LASR_Scoring::fix_list( $checks );
		if ( $fixes ) {
			/* translators: %s: name of the check worth the most points. */
			return sprintf( __( 'AI shopping agents can read your store. Your biggest remaining win is "%s".', 'leymish-ai-shopping-readiness' ), $fixes[0]['label'] );
		}
		return __( 'AI shopping agents can read your store, and nothing we check for is missing.', 'leymish-ai-shopping-readiness' );
	}

	/**
	 * Our free guide for each check (with campaign tags so our own site can count the visits; the plugin sends nothing).
	 *
	 * @param string $id       Check id.
	 * @param string $campaign Campaign tag.
	 * @return string
	 */
	public static function guide_url( $id, $campaign = 'fix-free' ) {
		$guides = array(
			'catalog'        => 'add-gtin-woocommerce',
			'jsonld'         => 'add-brand-woocommerce-products',
			'robots'         => 'woocommerce-robots-txt-ai-crawlers',
			'bot_block'      => 'woocommerce-robots-txt-ai-crawlers',
			'llms_txt'       => 'llms-txt-woocommerce',
			'store_api'      => 'woocommerce-store-api-bom-error',
			'guest'          => 'woocommerce-ready-for-chatgpt-checkout',
			'ucp'            => 'woocommerce-ready-for-chatgpt-checkout',
			'acp'            => 'woocommerce-ready-for-chatgpt-checkout',
		);
		$slug = isset( $guides[ $id ] ) ? $guides[ $id ] : 'woocommerce-chatgpt-shopping-visibility';
		return self::SITE . '/woocommerce/guides/' . $slug . '.html?utm_source=plugin&utm_medium=dashboard&utm_campaign=' . rawurlencode( $campaign ) . '&utm_content=' . rawurlencode( $id );
	}

	/**
	 * Our Pro page, tagged with where the click came from.
	 *
	 * @param string $campaign fix-in-pro or free-vs-pro.
	 * @param string $content  Check id or panel part.
	 * @return string
	 */
	public static function pro_url( $campaign, $content = '' ) {
		return self::SITE . '/woocommerce/pro.html?utm_source=plugin&utm_medium=dashboard&utm_campaign=' . rawurlencode( $campaign ) . ( '' !== $content ? '&utm_content=' . rawurlencode( $content ) : '' );
	}

	/**
	 * The top fixes, each with how many products it touches (null when it's store-wide) and whether Pro has a tool.
	 *
	 * @param array $result Last audit.
	 * @param int   $limit  How many.
	 * @return array[]
	 */
	public static function wins( array $result, $limit = 3 ) {
		$products = isset( $result['products'] ) ? $result['products'] : array();
		$out      = array();
		foreach ( LASR_Scoring::fix_list( (array) $result['checks'] ) as $f ) {
			$affected = null;
			if ( 'catalog' === $f['id'] ) {
				$affected = self::products_with_gaps( $products );
			} elseif ( 'jsonld' === $f['id'] ) {
				$affected = self::identifier_gaps( $products );
			}
			$f['affected'] = $affected;
			$f['pro']      = LASR_Scoring::pro_helps( $f['id'] );
			$out[]         = $f;
			if ( $limit > 0 && count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * "Free vs Pro for your store" rows, built from this store's own numbers.
	 *
	 * @param array $result Last audit.
	 * @return array[] what, free, pro.
	 */
	public static function panel_rows( array $result ) {
		$gaps = self::identifier_gaps( isset( $result['products'] ) ? $result['products'] : array() );
		$rows = array();
		if ( $gaps > 0 ) {
			$rows[] = array(
				/* translators: %d: number of products. */
				'what' => sprintf( _n( '%d product needs a GTIN, MPN or brand', '%d products need a GTIN, MPN or brand', $gaps, 'leymish-ai-shopping-readiness' ), $gaps ),
				/* translators: %d: number of products. */
				'free' => sprintf( _n( 'Edit %d product by hand', 'Edit %d products one by one', $gaps, 'leymish-ai-shopping-readiness' ), $gaps ),
				'pro'  => __( 'One screen for all of them, GTIN check digits validated before saving', 'leymish-ai-shopping-readiness' ),
			);
		}
		$rows[] = array(
			'what' => __( 'Product feeds for OpenAI and Google', 'leymish-ai-shopping-readiness' ),
			'free' => __( 'Not included', 'leymish-ai-shopping-readiness' ),
			'pro'  => __( 'Both feeds on your own domain, rebuilt a minute after any product changes', 'leymish-ai-shopping-readiness' ),
		);
		$rows[] = array(
			'what' => __( 'Keeping an eye on it', 'leymish-ai-shopping-readiness' ),
			'free' => __( 'Run the audit when you remember', 'leymish-ai-shopping-readiness' ),
			'pro'  => __( 'A weekly re-audit email', 'leymish-ai-shopping-readiness' ),
		);
		$rows[] = array(
			'what' => __( 'Showing the work', 'leymish-ai-shopping-readiness' ),
			'free' => __( 'This Impact tab', 'leymish-ai-shopping-readiness' ),
			'pro'  => __( 'A before/after report and an llms.txt generator', 'leymish-ai-shopping-readiness' ),
		);
		return $rows;
	}

	/**
	 * Whether the WOOLAUNCH line is still true.
	 *
	 * @param int|null $now Unix time.
	 * @return bool
	 */
	public static function launch_offer_active( $now = null ) {
		return ( null === $now ? time() : (int) $now ) < self::LAUNCH_ENDS;
	}

	/**
	 * The panel shows only without Pro, and not for 30 days after this user dismissed it.
	 *
	 * @param int|null $dismissed_at When this user dismissed it (0 = never).
	 * @param int|null $now          Unix time.
	 * @return bool
	 */
	public static function panel_due( $dismissed_at, $now = null ) {
		if ( defined( 'LASR_PRO_VERSION' ) ) {
			return false;
		}
		$now = null === $now ? time() : (int) $now;
		return ! $dismissed_at || ( $now - (int) $dismissed_at ) >= self::DISMISS_DAYS * DAY_IN_SECONDS;
	}

	/* ---------------------------------------------------------------- HTML */

	/**
	 * Score donut (inline SVG). The number sits in the middle as real text for screen readers and the QA agent.
	 *
	 * @param int $score 0..100.
	 */
	private static function gauge( $score ) {
		$score = max( 0, min( 100, (int) $score ) );
		$r     = 52;
		$len   = 2 * M_PI * $r;
		$on    = round( $len * $score / 100, 2 );
		echo '<div class="lasr-gauge">';
		echo '<svg viewBox="0 0 120 120" aria-hidden="true" focusable="false"><circle class="lasr-gauge-track" cx="60" cy="60" r="' . esc_attr( (string) $r ) . '" />';
		echo '<circle class="lasr-gauge-fill" cx="60" cy="60" r="' . esc_attr( (string) $r ) . '" stroke-dasharray="' . esc_attr( $on . ' ' . round( $len, 2 ) ) . '" transform="rotate(-90 60 60)" /></svg>';
		echo '<div class="lasr-number">' . esc_html( (string) $score ) . '<span>/100</span></div></div>';
	}

	/**
	 * Small trend line (decorative; the same numbers are in the "+N since you started" text and the full chart).
	 *
	 * @param array[] $history Snapshots, oldest first.
	 */
	private static function sparkline( array $history ) {
		if ( count( $history ) < 2 ) {
			return;
		}
		$pts  = self::spark_points( array_map( function ( $s ) {
			return (int) $s['s'];
		}, array_values( $history ) ), 120, 32 );
		$last = end( $pts );
		echo '<svg class="lasr-spark" viewBox="-4 -4 128 40" aria-hidden="true" focusable="false"><polyline points="' . esc_attr( implode( ' ', array_map( function ( $p ) {
			return $p[0] . ',' . $p[1];
		}, $pts ) ) ) . '" /><circle cx="' . esc_attr( (string) $last[0] ) . '" cy="' . esc_attr( (string) $last[1] ) . '" r="3" /></svg>';
	}

	/**
	 * Sparkline points scaled to the scores' own range, padded by at least 10 points so a small change doesn't look
	 * dramatic, and a flat history sits in the middle. The exact numbers are always in the text next to it.
	 *
	 * @param int[] $scores Scores, oldest first.
	 * @param int   $w      Width.
	 * @param int   $h      Height.
	 * @return array[] [x, y] pairs.
	 */
	public static function spark_points( array $scores, $w = 120, $h = 32 ) {
		$lo  = max( 0, min( $scores ) - 5 );
		$hi  = min( 100, max( $scores ) + 5 );
		if ( $hi - $lo < 10 ) {
			$mid = ( $hi + $lo ) / 2;
			$lo  = max( 0, $mid - 5 );
			$hi  = min( 100, $lo + 10 );
		}
		$n   = count( $scores );
		$out = array();
		foreach ( $scores as $i => $s ) {
			$x     = $n > 1 ? round( $w * $i / ( $n - 1 ), 1 ) : 0;
			$y     = round( $h - ( $s - $lo ) / ( $hi - $lo ) * $h, 1 );
			$out[] = array( $x, $y );
		}
		return $out;
	}

	/**
	 * Hero: gauge, grade, verdict, trend and the run button.
	 *
	 * @param array      $result   Last audit.
	 * @param array      $history  Weekly snapshots (may be empty).
	 * @param array|null $baseline First audit.
	 * @param string     $when     The line under the verdict.
	 * @param callable   $run_form Prints the run form.
	 */
	public static function hero( array $result, array $history, $baseline, $when, $run_form ) {
		$score = (int) $result['score'];
		$grade = self::grade( $score );
		echo '<section class="lasr-hero" aria-labelledby="lasr-hero-h">';
		echo '<div class="lasr-score lasr-band-' . esc_attr( LASR_Scoring::band( $score ) ) . '">';
		self::gauge( $score );
		echo '</div><div class="lasr-hero-text">';
		/* translators: %s: letter grade A to F. */
		echo '<h2 id="lasr-hero-h" class="lasr-grade lasr-grade-' . esc_attr( strtolower( $grade ) ) . '">' . esc_html( sprintf( __( 'Grade %s', 'leymish-ai-shopping-readiness' ), $grade ) ) . '</h2>';
		echo '<p class="lasr-verdict">' . esc_html( self::verdict( $result ) ) . '</p>';
		echo '<div class="lasr-trend">';
		self::sparkline( $history );
		echo '<span class="lasr-when">' . esc_html( $when ) . '</span></div>';
		call_user_func( $run_form );
		echo '</div></section>';
	}

	/**
	 * Four category cards with a bar each. Status is in words, not just colour.
	 *
	 * @param array $checks Check results.
	 */
	public static function cards( array $checks ) {
		$words = array(
			'pass' => __( 'Good', 'leymish-ai-shopping-readiness' ),
			'warn' => __( 'Needs work', 'leymish-ai-shopping-readiness' ),
			'fail' => __( 'Fix first', 'leymish-ai-shopping-readiness' ),
			'skip' => __( 'Not tested', 'leymish-ai-shopping-readiness' ),
			'info' => __( 'Information', 'leymish-ai-shopping-readiness' ),
		);
		echo '<ul class="lasr-cards" aria-label="' . esc_attr__( 'Score by area', 'leymish-ai-shopping-readiness' ) . '">';
		foreach ( self::categories( $checks ) as $c ) {
			echo '<li class="lasr-card lasr-st-' . esc_attr( $c['status'] ) . '"><h3>' . esc_html( $c['label'] ) . '</h3>';
			echo '<span class="lasr-badge lasr-' . esc_attr( $c['status'] ) . '">' . esc_html( $words[ $c['status'] ] ) . '</span>';
			if ( null !== $c['percent'] ) {
				echo '<div class="lasr-bar" aria-hidden="true"><span style="width:' . esc_attr( (string) $c['percent'] ) . '%"></span></div>';
				/* translators: 1: points earned, 2: points possible, 3: percent. */
				echo '<p class="lasr-card-num">' . esc_html( sprintf( __( '%1$s of %2$d points (%3$d%%)', 'leymish-ai-shopping-readiness' ), $c['earned'], $c['points'], $c['percent'] ) ) . '</p>';
			} else {
				echo '<p class="lasr-card-num">' . esc_html( 'skip' === $c['status'] ? __( 'Could not be tested on this run.', 'leymish-ai-shopping-readiness' ) : __( 'Shown for information; not scored yet.', 'leymish-ai-shopping-readiness' ) ) . '</p>';
			}
			echo '<p class="lasr-card-about">' . esc_html( $c['needs'] ? implode( ', ', $c['needs'] ) : $c['about'] ) . '</p>';
			if ( $c['skipped'] && null !== $c['percent'] ) {
				/* translators: 1: checks not tested, 2: checks in this area. */
				echo '<p class="lasr-card-untested">' . esc_html( sprintf( _n( '%1$d of %2$d check could not be tested this run, so this is a partial score.', '%1$d of %2$d checks could not be tested this run, so this is a partial score.', $c['present'], 'leymish-ai-shopping-readiness' ), $c['skipped'], $c['present'] ) ) . '</p>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * The fix list with a free-guide button and, only where Pro has a tool for it, a Pro button.
	 *
	 * @param array[] $wins From wins().
	 */
	public static function wins_list( array $wins ) {
		echo '<ol class="lasr-wins">';
		foreach ( $wins as $w ) {
			echo '<li class="lasr-win"><div class="lasr-win-head"><strong>' . esc_html( $w['label'] ) . '</strong>';
			/* translators: %s: points that fixing this would add. */
			echo ' <span class="lasr-gain">' . esc_html( sprintf( __( '+%s points', 'leymish-ai-shopping-readiness' ), max( 1, (int) round( (float) $w['lost'] ) ) ) ) . '</span>';
			if ( null !== $w['affected'] && 'jsonld' === $w['id'] ) {
				/* translators: %d: number of products. */
				echo ' <span class="lasr-affects">' . esc_html( sprintf( _n( '%d product without a GTIN, MPN or brand', '%d products without a GTIN, MPN or brand', (int) $w['affected'], 'leymish-ai-shopping-readiness' ), (int) $w['affected'] ) ) . '</span>';
			} elseif ( null !== $w['affected'] ) {
				/* translators: %d: number of products. */
				echo ' <span class="lasr-affects">' . esc_html( sprintf( _n( '%d product with at least one gap', '%d products with at least one gap', (int) $w['affected'], 'leymish-ai-shopping-readiness' ), (int) $w['affected'] ) ) . '</span>';
			} else {
				echo ' <span class="lasr-affects">' . esc_html__( 'store-wide', 'leymish-ai-shopping-readiness' ) . '</span>';
			}
			echo '</div><p class="lasr-detail">' . esc_html( $w['fix'] ) . '</p><p class="lasr-win-actions">';
			echo '<a class="button" href="' . esc_url( self::guide_url( $w['id'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'How to fix it free', 'leymish-ai-shopping-readiness' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'leymish-ai-shopping-readiness' ) . '</span></a>';
			if ( $w['pro'] && ! defined( 'LASR_PRO_VERSION' ) ) {
				echo ' <a class="button lasr-pro-link" href="' . esc_url( self::pro_url( 'fix-in-pro', $w['id'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Fix it in Pro', 'leymish-ai-shopping-readiness' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'leymish-ai-shopping-readiness' ) . '</span></a>';
			}
			echo '</p></li>';
		}
		echo '</ol>';
	}

	/**
	 * "Free vs Pro for your store". Only on our screens, only without Pro, dismissible for 30 days.
	 *
	 * @param array $result Last audit.
	 */
	public static function panel( array $result ) {
		if ( ! self::panel_due( (int) get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) ) {
			return;
		}
		echo '<section class="lasr-panel" aria-labelledby="lasr-panel-h"><h2 id="lasr-panel-h">' . esc_html__( 'Free vs Pro for your store', 'leymish-ai-shopping-readiness' ) . '</h2>';
		echo '<table class="widefat lasr-panel-table"><thead><tr><th scope="col"><span class="screen-reader-text">' . esc_html__( 'What', 'leymish-ai-shopping-readiness' ) . '</span></th><th scope="col">' . esc_html__( 'Free (this plugin)', 'leymish-ai-shopping-readiness' ) . '</th><th scope="col">' . esc_html__( 'Pro add-on', 'leymish-ai-shopping-readiness' ) . '</th></tr></thead><tbody>';
		foreach ( self::panel_rows( $result ) as $r ) {
			echo '<tr><th scope="row">' . esc_html( $r['what'] ) . '</th><td>' . esc_html( $r['free'] ) . '</td><td>' . esc_html( $r['pro'] ) . '</td></tr>';
		}
		echo '</tbody></table><p class="lasr-price"><strong>' . esc_html__( '$29 once per store', 'leymish-ai-shopping-readiness' ) . '</strong>, ' . esc_html__( 'updates included, refund within 14 days.', 'leymish-ai-shopping-readiness' );
		if ( self::launch_offer_active() ) {
			echo ' ' . esc_html__( 'Code WOOLAUNCH takes $10 off until 30 November 2026.', 'leymish-ai-shopping-readiness' );
		}
		echo '</p><div class="lasr-panel-actions"><a class="button" href="' . esc_url( self::pro_url( 'free-vs-pro', 'panel' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'See Pro', 'leymish-ai-shopping-readiness' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'leymish-ai-shopping-readiness' ) . '</span></a>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lasr_dismiss_panel' );
		echo '<input type="hidden" name="action" value="lasr_dismiss_panel" /><button type="submit" class="button-link">' . esc_html__( 'Hide this for 30 days', 'leymish-ai-shopping-readiness' ) . '</button></form></div>';
		echo '<p class="description">' . esc_html__( 'The free plugin keeps every check and feature either way. Nothing here is locked.', 'leymish-ai-shopping-readiness' ) . '</p></section>';
	}

	/**
	 * Hide the panel for this user for 30 days.
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( 'lasr_dismiss_panel' );
		update_user_meta( get_current_user_id(), self::DISMISS_META, time() );
		wp_safe_redirect( admin_url( 'admin.php?page=' . LASR_Admin::SLUG ) );
		exit;
	}
}
