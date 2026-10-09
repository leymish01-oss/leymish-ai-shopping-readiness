<?php
/**
 * The dashboard pieces shared by the Overview and Audit tabs: the score hero, the four category cards and the wins.
 * (2.0 dropped the 1.4 "Free vs Pro" panel: each tab now shows what Pro would do for this store, in place.)
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helpers (grade, categories, verdict, wins) plus their HTML. Charts are inline SVG, and every
 * chart has its numbers in text or a screen-reader table next to it.
 */
class LASR_Dashboard {

	const SITE = 'https://www.leymish.com';

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
			'checkout' => array( __( 'AI checkout readiness', 'leymish-ai-shopping-readiness' ), __( 'Guest checkout, returns policy, UCP and ACP', 'leymish-ai-shopping-readiness' ), array( 'guest', 'returns', 'ucp', 'acp', 'mcp' ) ),
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
					$needs[] = LASR_Scoring::is_watch( $c ) ? LASR_Scoring::watch_label( $c ) : $c['label'];
				}
			}
			if ( $points > 0 ) {
				$pct = (int) round( 100 * $earned / $points );
				// 2.0.1: the word follows the number people see, so 100% is never "Needs work".
				$status = $pct >= 100 ? 'pass' : LASR_Scoring::status_for( $earned / $points );
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
		$watch = self::watch_text( $checks );
		if ( '' !== $watch ) {
			return $watch;
		}
		return __( 'AI shopping agents can read your store, and nothing we check for is missing.', 'leymish-ai-shopping-readiness' );
	}

	/**
	 * "Nothing urgent. Next to watch: …" when the only points left need something nobody can switch on yet (2.0.1).
	 *
	 * @param array $checks Check results.
	 * @return string Empty when there is nothing to watch.
	 */
	public static function watch_text( array $checks ) {
		$watch = LASR_Scoring::watch_list( $checks );
		if ( ! $watch ) {
			return '';
		}
		/* translators: %s: what to watch, e.g. UCP checkout (not available for WooCommerce yet). */
		return sprintf( __( 'Nothing urgent. Next to watch: %s.', 'leymish-ai-shopping-readiness' ), implode( ', ', array_map( array( 'LASR_Scoring', 'watch_label' ), $watch ) ) );
	}

	/**
	 * Products that still have a gap, worst first, with what's missing in words (2.0.1).
	 *
	 * @param array $products Audit product rows.
	 * @param int   $max      How many.
	 * @return array[] id, name, missing (labels).
	 */
	public static function gap_products( $products, $max = 3 ) {
		$labels = LASR_Audit::field_labels();
		$out    = array();
		foreach ( (array) $products as $p ) {
			if ( empty( $p['missing'] ) ) {
				continue;
			}
			$out[] = array(
				'id'      => isset( $p['id'] ) ? (int) $p['id'] : 0,
				'name'    => isset( $p['name'] ) ? wp_specialchars_decode( (string) $p['name'], ENT_QUOTES ) : '',
				'missing' => array_map(
					function ( $f ) use ( $labels ) {
						return isset( $labels[ $f ] ) ? $labels[ $f ] : $f;
					},
					(array) $p['missing']
				),
			);
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
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
	 * Where a fix happens: our own tab when the plugin can fix it here, otherwise the free guide.
	 *
	 * @param string $id Check id.
	 * @return string
	 */
	public static function fix_url( $id ) {
		$tabs = array(
			'catalog'  => 'products',
			'jsonld'   => 'products',
			'llms_txt' => 'feeds',
			'ucp'      => 'feeds',
		);
		if ( isset( $tabs[ $id ] ) && class_exists( 'LASR_Admin' ) ) {
			return admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=' . $tabs[ $id ] );
		}
		if ( 'returns' === $id && class_exists( 'LASR_Audit' ) && function_exists( 'get_edit_post_link' ) ) {
			$page = LASR_Audit::sample_returns_page();
			$edit = $page ? get_edit_post_link( $page, 'raw' ) : '';
			if ( $edit ) {
				return $edit;
			}
		}
		return self::guide_url( $id );
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
	public static function cards( array $checks, array $products = array() ) {
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
			$gaps = 'product' === $c['key'] && null !== $c['percent'] ? self::gap_products( $products, 4 ) : array();
			if ( $gaps && count( $gaps ) <= 3 ) {
				echo '<ul class="lasr-card-gaps">';
				foreach ( $gaps as $g ) {
					/* translators: 1: product name, 2: what is missing. */
					echo '<li>' . esc_html( sprintf( __( '%1$s: %2$s', 'leymish-ai-shopping-readiness' ), $g['name'], implode( ', ', $g['missing'] ) ) );
					if ( $g['id'] && class_exists( 'LASR_Admin' ) ) {
						echo ' <a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=products&card=' . $g['id'] ) ) . '">' . esc_html__( 'Fix it', 'leymish-ai-shopping-readiness' ) . '<span class="screen-reader-text"> ' . esc_html( $g['name'] ) . '</span></a>';
					}
					echo '</li>';
				}
				echo '</ul>';
			} else {
				echo '<p class="lasr-card-about">' . esc_html( $c['needs'] ? implode( ', ', $c['needs'] ) : $c['about'] ) . '</p>';
			}
			if ( $c['skipped'] && null !== $c['percent'] ) {
				/* translators: 1: checks not tested, 2: checks in this area. */
				echo '<p class="lasr-card-untested">' . esc_html( sprintf( _n( '%1$d of %2$d check could not be tested this run, so this is a partial score.', '%1$d of %2$d checks could not be tested this run, so this is a partial score.', $c['present'], 'leymish-ai-shopping-readiness' ), $c['skipped'], $c['present'] ) ) . '</p>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * The fix list: "Fix it here" where this plugin fixes it, and the free guide for every fix.
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
			if ( self::fix_url( $w['id'] ) !== self::guide_url( $w['id'] ) ) {
				echo ' <a class="button button-primary" href="' . esc_url( self::fix_url( $w['id'] ) ) . '">' . esc_html__( 'Fix it here', 'leymish-ai-shopping-readiness' ) . '</a>';
			}
			echo '</p></li>';
		}
		echo '</ol>';
	}
}
