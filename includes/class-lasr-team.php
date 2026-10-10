<?php
/**
 * The Team tab: LeyMish Store Team (a LeyMish service), built in since 2.0.
 *
 * A CEO agent writes a weekly plan for the store; on LeyMish Pro the Catalog and Reporting agents do its work
 * (attributes, image alt text, categories), and every change waits in the Approvals inbox here until the owner
 * approves it. Free: the weekly plan, read-only. Connecting uses WooCommerce's own approval screen; the site token
 * is kept here and passed to the service server-side, never to the browser. Nothing is fetched before the owner
 * connects or asks for the live demo.
 *
 * @package LeyMish_AI_Shopping_Readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection, dashboard, approvals and the tab.
 */
class LASR_Team {

	const OPTION    = 'lasr_team_connection'; // store_id, site_token (copied from Store Team 0.x by the migration)
	const COUNT_KEY = 'lasr_waiting_count';
	const COUNT_TTL = 300;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_lasr_team_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_lasr_team_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_lasr_team_beta', array( __CLASS__, 'handle_beta' ) );
		add_action( 'admin_post_lasr_team_report', array( __CLASS__, 'handle_report' ) );
		add_action( 'admin_post_lasr_team_goal', array( __CLASS__, 'handle_goal' ) );
		add_action( 'wp_ajax_lasr_team_revert', array( __CLASS__, 'ajax_revert' ) );
		add_action( 'wp_ajax_lasr_approvals', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_lasr_approvals_decide', array( __CLASS__, 'ajax_decide' ) );
	}

	/**
	 * Saved connection, or null.
	 *
	 * @return array|null
	 */
	public static function connection() {
		$c = get_option( self::OPTION );
		return is_array( $c ) && ! empty( $c['site_token'] ) ? $c : null;
	}

	/**
	 * POST to /v1/team/… with the site token.
	 *
	 * @param string $path Path under /v1/team/.
	 * @param array  $body Body.
	 * @return array{status:int,data:array}
	 */
	public static function call( $path, array $body = array(), $timeout = 15 ) {
		$c = self::connection();
		return LASR_Service::post( '/v1/team/' . $path, $body, $c ? $c['site_token'] : '', $timeout );
	}

	/**
	 * Tell the service about this site's licence, so a LeyMish Pro store's agents can work (and a lapsed one goes
	 * back to the free weekly plan).
	 */
	public static function sync_licence() {
		if ( ! self::connection() ) {
			return;
		}
		self::call( 'license', array( 'license_key' => LASR_License::has_licence() ? LASR_License::key() : '' ) );
		delete_transient( LASR_License::STATUS_KEY );
	}

	/**
	 * Approvals waiting (cached 5 minutes; 0 when not connected, without calling out).
	 *
	 * @param bool $fresh Skip the cache.
	 * @return int
	 */
	public static function waiting_count( $fresh = false ) {
		if ( ! self::connection() ) {
			return 0;
		}
		if ( ! $fresh ) {
			$cached = get_transient( self::COUNT_KEY );
			if ( false !== $cached ) {
				return (int) $cached;
			}
		}
		$r = self::call( 'approvals/count', array(), 5 ); // runs on every admin page: never hold one up for long
		$n = 200 === $r['status'] && isset( $r['data']['waiting'] ) ? (int) $r['data']['waiting'] : 0;
		set_transient( self::COUNT_KEY, $n, self::COUNT_TTL );
		return $n;
	}

	/**
	 * Guard for the form handlers.
	 *
	 * @param string $action Nonce.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Back to the Team tab.
	 *
	 * @param string $msg Code.
	 */
	private static function back( $msg = '' ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=team' . ( $msg ? '&lasr_msg=' . rawurlencode( $msg ) : '' ) ) );
		exit;
	}

	/**
	 * Start WooCommerce's key flow: the owner approves on their own store, WooCommerce sends the keys to LeyMish.
	 */
	public static function handle_connect() {
		self::guard( 'lasr_team_connect' );
		if ( 0 !== strpos( home_url(), 'https://' ) ) {
			self::back( 'https' );
		}
		$r = LASR_Service::post(
			'/v1/team/connect/start',
			array(
				'store_url'  => home_url( '/' ),
				'plan'       => 'free',
				'return_url' => admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=team' ),
			)
		);
		if ( 200 !== $r['status'] || empty( $r['data']['authorize_url'] ) ) {
			self::back( 'failed' );
		}
		update_option(
			self::OPTION,
			array(
				'store_id'   => sanitize_text_field( (string) $r['data']['store_id'] ),
				'site_token' => sanitize_text_field( (string) $r['data']['site_token'] ),
			),
			false
		);
		self::sync_licence();
		wp_redirect( esc_url_raw( $r['data']['authorize_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- this store's own /wc-auth page.
		exit;
	}

	/**
	 * Disconnect: the service deletes its copy of the keys; we forget the site token.
	 */
	public static function handle_disconnect() {
		self::guard( 'lasr_team_disconnect' );
		if ( self::connection() ) {
			self::call( 'disconnect' );
		}
		delete_option( self::OPTION );
		delete_transient( self::COUNT_KEY );
		delete_transient( LASR_License::STATUS_KEY );
		self::back( 'disconnected' );
	}

	/**
	 * Piku's store report: weekly (Mondays, the default) or daily.
	 */
	public static function handle_report() {
		self::guard( 'lasr_team_report' );
		$freq = isset( $_POST['frequency'] ) && 'daily' === sanitize_key( wp_unslash( $_POST['frequency'] ) ) ? 'daily' : 'weekly'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$msg  = 'report_failed';
		if ( self::connection() ) {
			$r = self::call( 'report', array( 'frequency' => $freq ) );
			if ( 200 === $r['status'] ) {
				update_option( 'lasr_team_report', $freq, false );
				$msg = 'report_ok';
			}
		}
		self::back( $msg );
	}

	/**
	 * The owner's monthly sales goal (0 clears it). The Team tab shows recorded WooCommerce sales against it.
	 */
	public static function handle_goal() {
		self::guard( 'lasr_team_goal' );
		$goal = isset( $_POST['goal_usd'] ) ? absint( wp_unslash( $_POST['goal_usd'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard().
		$msg  = 'goal_failed';
		if ( self::connection() ) {
			$r   = self::call( 'goal', array( 'goal_usd' => $goal ) );
			$msg = 200 === $r['status'] ? 'goal_ok' : 'goal_failed';
		}
		self::back( $msg );
	}

	/**
	 * Claim a founding beta spot: Store Team's Starter features and LeyMish Pro free for 30 days.
	 */
	public static function handle_beta() {
		self::guard( 'lasr_team_beta' );
		$msg = 'beta_failed';
		if ( self::connection() ) {
			$r   = self::call( 'beta/claim' );
			$msg = 200 === $r['status'] ? 'beta_ok' : ( 409 === $r['status'] ? 'beta_full' : 'beta_failed' );
			delete_transient( LASR_License::STATUS_KEY );
		}
		self::back( $msg );
	}

	/**
	 * Capability + nonce + a connection for the AJAX routes.
	 *
	 * @param string $nonce Nonce action.
	 * @return bool
	 */
	private static function ajax_guard( $nonce ) {
		check_ajax_referer( $nonce );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'leymish-ai-shopping-readiness' ) ), 403 );
		}
		if ( ! self::connection() ) {
			wp_send_json_error( array( 'message' => __( 'Connect your store first: this is demo data.', 'leymish-ai-shopping-readiness' ) ), 400 );
		}
		return true;
	}

	/**
	 * Undo one change: the service writes the old value back.
	 */
	public static function ajax_revert() {
		self::ajax_guard( 'lasr_team' );
		$r = self::call( 'revert', array( 'id' => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 ) );
		200 === $r['status'] ? wp_send_json_success( $r['data'] ) : wp_send_json_error( $r['data'], 400 );
	}

	/**
	 * Everything waiting, plus the decisions already made.
	 */
	public static function ajax_list() {
		self::ajax_guard( 'lasr_approvals' );
		$r = self::call( 'approvals' );
		if ( 200 !== $r['status'] ) {
			wp_send_json_error( array( 'message' => __( 'Could not load your approvals just now.', 'leymish-ai-shopping-readiness' ) ), 502 );
		}
		set_transient( self::COUNT_KEY, count( (array) ( isset( $r['data']['waiting'] ) ? $r['data']['waiting'] : array() ) ), self::COUNT_TTL );
		wp_send_json_success( $r['data'] );
	}

	/**
	 * Approve, reject, edit-then-approve, or the same in bulk. The service re-checks every edit (claims guard,
	 * attribute vocabulary, field whitelist), because an edit is still a write to the store.
	 */
	public static function ajax_decide() {
		self::ajax_guard( 'lasr_approvals' );
		$decision = isset( $_POST['decision'] ) && 'approve' === sanitize_key( wp_unslash( $_POST['decision'] ) ) ? 'approve' : 'reject';
		$body     = array( 'decision' => $decision );
		if ( isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['ids'] ) ) ) );
			if ( ! $ids ) {
				wp_send_json_error( array( 'message' => __( 'Tick at least one change first.', 'leymish-ai-shopping-readiness' ) ), 400 );
			}
			$body['ids'] = array_slice( $ids, 0, 50 );
		} else {
			$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
			if ( ! $id ) {
				wp_send_json_error( array( 'message' => __( 'That change no longer exists.', 'leymish-ai-shopping-readiness' ) ), 400 );
			}
			$body['id'] = $id;
			$edit       = self::read_edit();
			if ( $edit ) {
				$body['edit'] = $edit;
			}
		}
		$user       = wp_get_current_user();
		$body['by'] = $user && $user->exists() ? mb_substr( (string) $user->display_name, 0, 60 ) : 'owner';
		$r          = self::call( 'approvals/decide', $body );
		delete_transient( self::COUNT_KEY );
		if ( 200 !== $r['status'] ) {
			wp_send_json_error( array( 'message' => isset( $r['data']['error'] ) ? (string) $r['data']['error'] : __( 'That did not save.', 'leymish-ai-shopping-readiness' ) ), 400 );
		}
		$n = isset( $body['ids'] ) ? count( $body['ids'] ) : 1;
		LASR_Worklog::event(
			'approval',
			'approve' === $decision
				/* translators: 1: number of changes, 2: who approved. */
				? sprintf( _n( '%1$d Store Team change approved by %2$s', '%1$d Store Team changes approved by %2$s', $n, 'leymish-ai-shopping-readiness' ), $n, $body['by'] )
				/* translators: 1: number of changes, 2: who rejected. */
				: sprintf( _n( '%1$d Store Team change rejected by %2$s', '%1$d Store Team changes rejected by %2$s', $n, 'leymish-ai-shopping-readiness' ), $n, $body['by'] )
		);
		wp_send_json_success( $r['data'] );
	}

	/**
	 * The owner's edit: one text value or a list of attributes, plain text only.
	 *
	 * @return array|null
	 */
	private static function read_edit() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- ajax_guard() ran check_ajax_referer already.
		if ( isset( $_POST['edit_value'] ) ) {
			$v = trim( wp_strip_all_tags( wp_unslash( $_POST['edit_value'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- stripped here; the service validates.
			return '' === $v ? null : array( 'value' => $v );
		}
		if ( isset( $_POST['edit_attributes'] ) && is_array( $_POST['edit_attributes'] ) ) {
			$out = array();
			foreach ( wp_unslash( $_POST['edit_attributes'] ) as $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitised below.
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name  = trim( wp_strip_all_tags( (string) ( isset( $row['name'] ) ? $row['name'] : '' ) ) );
				$value = trim( wp_strip_all_tags( (string) ( isset( $row['value'] ) ? $row['value'] : '' ) ) );
				if ( '' !== $name && '' !== $value ) {
					$out[] = array(
						'name'  => $name,
						'value' => $value,
					);
				}
			}
			return $out ? array( 'attributes' => array_slice( $out, 0, 6 ) ) : null;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return null;
	}

	/**
	 * Script data for the approvals inbox.
	 *
	 * @return array
	 */
	public static function approvals_data() {
		return array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'lasr_approvals' ),
			'connected' => (bool) self::connection(),
			'i18n'      => array(
				'now'        => __( 'Now', 'leymish-ai-shopping-readiness' ),
				'proposed'   => __( 'Proposed', 'leymish-ai-shopping-readiness' ),
				'approve'    => __( 'Approve', 'leymish-ai-shopping-readiness' ),
				'reject'     => __( 'Reject', 'leymish-ai-shopping-readiness' ),
				'edit'       => __( 'Edit then approve', 'leymish-ai-shopping-readiness' ),
				'save'       => __( 'Save and approve', 'leymish-ai-shopping-readiness' ),
				'cancel'     => __( 'Cancel', 'leymish-ai-shopping-readiness' ),
				'selectAll'  => __( 'Select all', 'leymish-ai-shopping-readiness' ),
				'bulkYes'    => __( 'Approve selected', 'leymish-ai-shopping-readiness' ),
				'bulkNo'     => __( 'Reject selected', 'leymish-ai-shopping-readiness' ),
				'empty'      => __( 'No approvals waiting. Your team proposes changes every Monday.', 'leymish-ai-shopping-readiness' ),
				'loading'    => __( 'Loading…', 'leymish-ai-shopping-readiness' ),
				'applied'    => __( 'Approved and applied to your store.', 'leymish-ai-shopping-readiness' ),
				'queued'     => __( 'Approved. It will be applied on the next run.', 'leymish-ai-shopping-readiness' ),
				'rejected'   => __( 'Rejected. Nothing changed.', 'leymish-ai-shopping-readiness' ),
				'failed'     => __( 'That did not save.', 'leymish-ai-shopping-readiness' ),
				'offline'    => __( 'Could not reach LeyMish. Try again in a minute.', 'leymish-ai-shopping-readiness' ),
				'notConn'    => __( 'Connect your store before you can approve anything.', 'leymish-ai-shopping-readiness' ),
				'dropped'    => __( 'We left these out', 'leymish-ai-shopping-readiness' ),
				'history'    => __( 'Decisions you have already made', 'leymish-ai-shopping-readiness' ),
				'waiting'    => __( 'Waiting for you', 'leymish-ai-shopping-readiness' ),
				'chooseSome' => __( 'Tick at least one change first.', 'leymish-ai-shopping-readiness' ),
				'cols'       => array( __( 'When', 'leymish-ai-shopping-readiness' ), __( 'Product', 'leymish-ai-shopping-readiness' ), __( 'Field', 'leymish-ai-shopping-readiness' ), __( 'Was', 'leymish-ai-shopping-readiness' ), __( 'Became', 'leymish-ai-shopping-readiness' ), __( 'Outcome', 'leymish-ai-shopping-readiness' ), __( 'Approved by', 'leymish-ai-shopping-readiness' ) ),
				'outcome'    => array(
					'done'     => __( 'Applied', 'leymish-ai-shopping-readiness' ),
					'queued'   => __( 'Approved, waiting to apply', 'leymish-ai-shopping-readiness' ),
					'rejected' => __( 'Rejected', 'leymish-ai-shopping-readiness' ),
					'reverted' => __( 'Undone', 'leymish-ai-shopping-readiness' ),
					'failed'   => __( 'Not applied', 'leymish-ai-shopping-readiness' ),
					'paused'   => __( 'Paused (no credits)', 'leymish-ai-shopping-readiness' ),
				),
				/* translators: %d: number of old suggestions. */
				'expired'    => __( 'Expired before the new rules (%d)', 'leymish-ai-shopping-readiness' ),
				'expiredWhy' => __( 'Suggestions written before the attribute rules of 8 October. None of them was applied; your team proposes them again under the new rules.', 'leymish-ai-shopping-readiness' ),
				'empty_was'  => __( '(empty)', 'leymish-ai-shopping-readiness' ),
				'nothing'    => __( 'Nothing yet.', 'leymish-ai-shopping-readiness' ),
				'unknownBy'  => __( 'not recorded', 'leymish-ai-shopping-readiness' ),
			),
		);
	}

	/**
	 * Dashboard data: this store, or (when the owner asks) the live demo from our partner store.
	 *
	 * @param bool $demo Load the public demo.
	 * @return array{source:string,data:array}
	 */
	public static function data( $demo ) {
		if ( self::connection() ) {
			$r = self::call( 'dashboard' );
			if ( 200 === $r['status'] ) {
				return array(
					'source' => 'store',
					'data'   => $r['data'],
				);
			}
		}
		if ( $demo ) {
			$r = LASR_Service::get( '/v1/team/demo' );
			if ( 200 === $r['status'] && $r['data'] ) {
				return array(
					'source' => 'demo',
					'data'   => $r['data'],
				);
			}
		}
		return array(
			'source' => 'none',
			'data'   => array(),
		);
	}

	/**
	 * The tab.
	 */
	public static function render() {
		$conn = self::connection();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display switches.
		$demo = isset( $_GET['demo'] );
		$msg  = isset( $_GET['lasr_msg'] ) ? sanitize_key( wp_unslash( $_GET['lasr_msg'] ) ) : '';
		// phpcs:enable
		$d       = self::data( $demo );
		$waiting = $conn ? self::waiting_count() : 0;
		$done    = 'store' === $d['source'] && isset( $d['data']['overview']['done_this_week'] ) ? (int) $d['data']['overview']['done_this_week'] : 0;
		$pro     = LASR_License::is_pro();
		LASR_Admin::three(
			$conn
				/* translators: %d: changes waiting for approval. */
				? ( $waiting ? sprintf( _n( '%d change is waiting for your approval.', '%d changes are waiting for your approval.', $waiting, 'leymish-ai-shopping-readiness' ), $waiting ) : __( 'Nothing is waiting for you.', 'leymish-ai-shopping-readiness' ) )
				: __( 'Your store isn\'t connected to a team yet.', 'leymish-ai-shopping-readiness' ),
			/* translators: %d: changes done this week. */
			$conn ? sprintf( _n( '%d change done this week.', '%d changes done this week.', $done, 'leymish-ai-shopping-readiness' ), $done ) : __( 'A CEO agent plans the week; on LeyMish Pro, agents do the work and ask you first.', 'leymish-ai-shopping-readiness' ),
			$conn ? ( $waiting ? __( 'Review the changes below.', 'leymish-ai-shopping-readiness' ) : ( $pro ? __( 'Your team proposes changes every Monday.', 'leymish-ai-shopping-readiness' ) : __( 'Upgrade so the agents do the plan\'s work.', 'leymish-ai-shopping-readiness' ) ) ) : __( 'Connect your store (free).', 'leymish-ai-shopping-readiness' ),
			$conn ? ( $waiting ? array( '#lasr-approvals-h', __( 'Review', 'leymish-ai-shopping-readiness' ) ) : null ) : array( '#lasr-team-connect', __( 'Connect', 'leymish-ai-shopping-readiness' ) )
		);
		$msgs = array(
			'https'        => __( 'Your store needs https to connect.', 'leymish-ai-shopping-readiness' ),
			'failed'       => __( 'Couldn\'t start the connection. Try again in a minute.', 'leymish-ai-shopping-readiness' ),
			'report_ok'     => __( 'Saved. The store report will come at that pace.', 'leymish-ai-shopping-readiness' ),
			'goal_ok'       => __( 'Goal saved. Recorded sales update once a day.', 'leymish-ai-shopping-readiness' ),
			'goal_failed'   => __( 'Couldn\'t save the goal. Try again in a minute.', 'leymish-ai-shopping-readiness' ),
			'report_failed' => __( 'Couldn\'t save the report setting. Try again in a minute.', 'leymish-ai-shopping-readiness' ),
			'disconnected' => __( 'Disconnected. Also revoke the key in WooCommerce → Settings → Advanced → REST API.', 'leymish-ai-shopping-readiness' ),
			'beta_ok'      => __( 'You have a founding spot: Store Team and LeyMish Pro are free for 30 days. After that it\'s $12/month only if you choose to continue; nothing is charged automatically.', 'leymish-ai-shopping-readiness' ),
			'beta_full'    => __( 'All 5 founding spots are taken. Your team keeps working on the free plan.', 'leymish-ai-shopping-readiness' ),
			'beta_failed'  => __( 'Could not claim a spot just now. Try again in a minute.', 'leymish-ai-shopping-readiness' ),
		);
		if ( isset( $msgs[ $msg ] ) ) {
			echo '<div class="notice notice-info is-dismissible inline"><p>' . esc_html( $msgs[ $msg ] ) . '</p></div>';
		}

		if ( ! $conn ) {
			echo '<div class="lasr-two"><section class="lasr-card-box" id="lasr-team-connect"><h3>' . esc_html__( 'Your store\'s AI team', 'leymish-ai-shopping-readiness' ) . '</h3><ul class="lasr-list">';
			echo '<li>' . esc_html__( 'Every Sunday night, a CEO agent reads your catalogue and writes the week\'s plan in plain words.', 'leymish-ai-shopping-readiness' ) . '</li>';
			echo '<li>' . esc_html__( 'On LeyMish Pro, the Catalog and Reporting agents do the work: attributes stated in your product text, image alt text, Google categories.', 'leymish-ai-shopping-readiness' ) . '</li>';
			echo '<li>' . esc_html__( 'Every change waits here for your approval, with an undo. Never prices, payments, shipping, themes, plugins, users or deleting anything.', 'leymish-ai-shopping-readiness' ) . '</li></ul>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'lasr_team_connect' );
			echo '<input type="hidden" name="action" value="lasr_team_connect" />';
			submit_button( __( 'Connect this store (free)', 'leymish-ai-shopping-readiness' ), 'primary', 'submit', false );
			echo '<p class="description">' . esc_html__( 'WooCommerce shows its own approval screen. You can disconnect any time, and the key is stored encrypted.', 'leymish-ai-shopping-readiness' ) . '</p></form></section>';
			echo '<section class="lasr-card-box"><h3>' . esc_html__( 'See a real team at work', 'leymish-ai-shopping-readiness' ) . '</h3><p>' . esc_html__( 'Our partner store, mishbio.us, runs Store Team in "ask before every change" mode. Its plan and proposals are public.', 'leymish-ai-shopping-readiness' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . LASR_Admin::SLUG . '&tab=team&demo=1#lst-app' ) ) . '">' . esc_html__( 'Show the live demo', 'leymish-ai-shopping-readiness' ) . '</a></p><p class="description">' . esc_html__( 'Loads the demo from LeyMish when you click.', 'leymish-ai-shopping-readiness' ) . '</p></section></div>';
		}

		if ( $conn ) {
			echo '<h2 id="lasr-approvals-h">' . esc_html__( 'Approvals', 'leymish-ai-shopping-readiness' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Nothing below has happened yet. Approve, edit then approve, or reject: your store only changes when you say so.', 'leymish-ai-shopping-readiness' ) . '</p>';
			echo '<div id="lst-approvals"><p>' . esc_html__( 'Loading…', 'leymish-ai-shopping-readiness' ) . '</p></div>';
		}

		if ( 'none' !== $d['source'] ) {
			if ( 'demo' === $d['source'] ) {
				echo '<div class="lst-banner" role="status"><p>' . esc_html__( 'Demo: live data from mishbio.us, our partner store. Connect your store to see yours.', 'leymish-ai-shopping-readiness' ) . '</p></div>';
			}
			echo '<h2>' . esc_html__( 'Your team this week', 'leymish-ai-shopping-readiness' ) . '</h2>';
			echo '<div id="lst-app" data-source="' . esc_attr( $d['source'] ) . '" data-avatars="' . esc_url( plugins_url( 'assets/agents/', LASR_FILE ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'lasr_team' ) ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"' . ( $conn ? ' data-inbox="#lasr-approvals-h"' : '' ) . '><p>' . esc_html__( 'Loading…', 'leymish-ai-shopping-readiness' ) . '</p></div>';
			echo '<script type="application/json" id="lst-data">' . wp_json_encode( $d['data'], JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
		}

		if ( $conn && 'store' === $d['source'] && isset( $d['data']['store']['plan'] ) && 'free' === $d['data']['store']['plan'] && ! LASR_License::has_licence() ) {
			$b = LASR_Service::get( '/v1/team/beta' );
			if ( 200 === $b['status'] && ! empty( $b['data']['left'] ) ) {
				$left = (int) $b['data']['left'];
				echo '<div class="lst-banner lst-beta"><p><strong>' . esc_html__( 'Founding beta', 'leymish-ai-shopping-readiness' ) . '</strong> ';
				/* translators: %d: founding spots left out of 5. */
				echo esc_html( sprintf( _n( 'Store Team and LeyMish Pro are free for 30 days for 5 founding stores, then $12/month only if you choose to continue. No card now. %d of 5 spots left.', 'Store Team and LeyMish Pro are free for 30 days for 5 founding stores, then $12/month only if you choose to continue. No card now. %d of 5 spots left.', $left, 'leymish-ai-shopping-readiness' ), $left ) ) . '</p>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'lasr_team_beta' );
				echo '<input type="hidden" name="action" value="lasr_team_beta" />';
				submit_button( __( 'Join the founding beta', 'leymish-ai-shopping-readiness' ), 'primary', 'submit', false );
				echo '</form></div>';
			}
		}
		if ( $conn ) {
			$goal = 'store' === $d['source'] && ! empty( $d['data']['goal'] ) ? $d['data']['goal'] : null;
			echo '<section class="lasr-card-box lasr-goal" aria-labelledby="lasr-goal-h"><h3 id="lasr-goal-h">' . esc_html__( 'Monthly sales goal', 'leymish-ai-shopping-readiness' ) . '</h3>';
			if ( $goal ) {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: 1: goal, 2: recorded sales, 3: amount still to go. */
						__( 'Goal %1$s. Recorded in WooCommerce this month: %2$s. %3$s to go.', 'leymish-ai-shopping-readiness' ),
						'$' . number_format_i18n( (float) $goal['goal_usd'], 2 ),
						'$' . number_format_i18n( (float) $goal['recorded_usd'], 2 ),
						'$' . number_format_i18n( (float) $goal['gap_usd'], 2 )
					)
				) . '</p>';
			}
			echo '<p class="description">' . esc_html__( 'Your number, compared with the sales WooCommerce has recorded this month. No forecasts.', 'leymish-ai-shopping-readiness' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
			wp_nonce_field( 'lasr_team_goal' );
			echo '<input type="hidden" name="action" value="lasr_team_goal" />';
			echo '<label for="lasr-goal-usd">' . esc_html__( 'Goal for this month (USD, 0 to clear):', 'leymish-ai-shopping-readiness' ) . '</label> ';
			echo '<input type="number" min="0" step="1" id="lasr-goal-usd" name="goal_usd" class="small-text" value="' . esc_attr( $goal ? (string) (int) $goal['goal_usd'] : '' ) . '" /> ';
			submit_button( __( 'Save goal', 'leymish-ai-shopping-readiness' ), 'secondary small', 'submit', false );
			echo '</p></form></section>';
			$freq = get_option( 'lasr_team_report', 'weekly' );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-report-freq"><p>';
			wp_nonce_field( 'lasr_team_report' );
			echo '<input type="hidden" name="action" value="lasr_team_report" />';
			echo '<label for="lasr-report-frequency">' . esc_html__( 'Store report by email (visitors, changes, what\'s waiting):', 'leymish-ai-shopping-readiness' ) . '</label> ';
			echo '<select id="lasr-report-frequency" name="frequency"><option value="weekly"' . selected( $freq, 'weekly', false ) . '>' . esc_html__( 'Weekly, on Mondays', 'leymish-ai-shopping-readiness' ) . '</option>';
			echo '<option value="daily"' . selected( $freq, 'daily', false ) . '>' . esc_html__( 'Daily', 'leymish-ai-shopping-readiness' ) . '</option></select> ';
			submit_button( __( 'Save', 'leymish-ai-shopping-readiness' ), 'secondary small', 'submit', false );
			echo '</p></form>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lasr-disconnect">';
			wp_nonce_field( 'lasr_team_disconnect' );
			echo '<input type="hidden" name="action" value="lasr_team_disconnect" />';
			submit_button( __( 'Disconnect this store', 'leymish-ai-shopping-readiness' ), 'secondary small', 'submit', false );
			echo '</form>';
		}
	}
}
