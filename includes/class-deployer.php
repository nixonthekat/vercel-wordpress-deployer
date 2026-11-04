<?php
/**
 * Vercel deploy hook client.
 *
 * @package VercelWordPressDeployer
 */

namespace VercelWordPressDeployer;

defined( 'ABSPATH' ) || exit;

/**
 * Triggers a deploy hook and, when a token is stored, follows the deployment.
 */
class Deployer {

	const NOTICE = 'vercel_wordpress_deployer_notice';

	const DIRTY = 'vercel_wordpress_deployer_dirty';

	const LOOKUP = 'vercel_wordpress_deployer_lookup';

	const LOCK = 'vercel_wordpress_deployer_lock';

	const CRON_HOOK = 'vercel_wordpress_deployer_debounced';

	/**
	 * True while a hook request is running in this process.
	 *
	 * @var bool
	 */
	private static $executing = false;

	/**
	 * Registers the debounced runner.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::CRON_HOOK, array( self::class, 'pump' ) );
		add_action( 'shutdown', array( self::class, 'pump_on_shutdown' ) );
	}

	/**
	 * Runs a due content deployment after the response, for editors who do not poll.
	 *
	 * Anonymous front-end views are left alone so a visitor is not waiting on Vercel.
	 *
	 * @return void
	 */
	public static function pump_on_shutdown() {
		if ( ! is_user_logged_in() && ! wp_doing_cron() ) {
			return;
		}

		self::pump();
	}

	/**
	 * Records that content changed and should deploy once edits settle.
	 *
	 * @return void
	 */
	public static function mark() {
		if ( '' === Settings::hook_url() ) {
			return;
		}

		set_transient( self::DIRTY, time(), 10 * MINUTE_IN_SECONDS );
		self::schedule( self::debounce_seconds() );
	}

	/**
	 * Runs a content deployment after the debounce window.
	 *
	 * @return void
	 */
	public static function pump() {
		if ( self::$executing ) {
			return;
		}

		$dirty_at = (int) get_transient( self::DIRTY );

		if ( $dirty_at <= 0 ) {
			return;
		}

		if ( '' === Settings::hook_url() ) {
			delete_transient( self::DIRTY );
			return;
		}

		$wait = ( $dirty_at + self::debounce_seconds() ) - time();

		if ( $wait > 0 ) {
			self::schedule( $wait );
			return;
		}

		if ( ! self::lock() ) {
			self::schedule( self::debounce_seconds() );
			return;
		}

		delete_transient( self::DIRTY );

		try {
			self::execute( 'content' );
		} finally {
			self::unlock();
		}
	}

	/**
	 * Sends the deploy hook immediately.
	 *
	 * @param string $origin manual or content.
	 * @return array<string, mixed>
	 */
	public static function run( $origin ) {
		if ( ! self::lock() ) {
			$current = self::peek();

			if ( 'idle' !== $current['phase'] ) {
				return $current;
			}

			return self::present(
				array(
					'id'         => wp_generate_uuid4(),
					'phase'      => 'failed',
					'error_code' => 'busy',
					'updated_at' => time(),
				)
			);
		}

		if ( 'manual' === $origin ) {
			delete_transient( self::DIRTY );
		}

		try {
			return self::present( self::execute( $origin ) );
		} finally {
			self::unlock();
		}
	}

	/**
	 * Stored notice without starting a deployment.
	 *
	 * @return array<string, mixed>
	 */
	public static function peek() {
		return self::present( self::refresh_if_needed( self::read() ) );
	}

	/**
	 * Starts a pending content deployment, then returns the current notice.
	 *
	 * @return array<string, mixed>
	 */
	public static function status() {
		self::pump();

		return self::present( self::refresh_if_needed( self::read() ) );
	}

	/**
	 * Posts the deploy hook and stores the result.
	 *
	 * @param string $origin manual or content.
	 * @return array<string, mixed>
	 */
	private static function execute( $origin ) {
		self::$executing = true;

		try {
			return self::perform( $origin );
		} finally {
			self::$executing = false;
		}
	}

	/**
	 * Posts the deploy hook and stores the result.
	 *
	 * @param string $origin manual or content.
	 * @return array<string, mixed>
	 */
	private static function perform( $origin ) {
		$settings = Settings::get();
		$hook     = $settings['hook_url'];
		$parsed   = Settings::normalize_hook( $hook );
		$notice   = array(
			'id'          => wp_generate_uuid4(),
			'phase'       => 'started',
			'error_code'  => '',
			'http_status' => 0,
			'detail'      => '',
			'url'         => '',
			'state'       => '',
			'since'       => (int) round( microtime( true ) * 1000 ) - 15000,
			'project_id'  => is_array( $parsed ) ? $parsed['project_id'] : '',
			'hook_id'     => is_array( $parsed ) ? $parsed['hook_id'] : '',
			'token_error' => '',
			'give_up'     => 0,
			'origin'      => $origin,
			'started_at'  => time(),
			'updated_at'  => time(),
		);

		if ( null === $parsed ) {
			$notice['phase']      = 'failed';
			$notice['error_code'] = 'missing_hook';
			self::store( $notice );

			return $notice;
		}

		self::store( $notice );

		$result = self::request(
			'POST',
			$parsed['url'],
			array(
				'Accept' => 'application/json',
			)
		);

		if ( 'unreachable' === $result['error'] ) {
			$notice['phase']      = 'failed';
			$notice['error_code'] = 'unreachable';
			self::store( $notice );
			do_action( 'vercel_wordpress_deployer_finished', $notice );

			return $notice;
		}

		$body = is_array( $result['body'] ) ? $result['body'] : array();
		$code = (int) $result['code'];
		$job       = isset( $body['job'] ) && is_array( $body['job'] ) ? $body['job'] : null;
		$job_state = is_array( $job ) && isset( $job['state'] ) ? strtoupper( (string) $job['state'] ) : '';

		if ( $code < 200 || $code >= 300 || null === $job || 'ERROR' === $job_state ) {
			$notice['phase']       = 'failed';
			$notice['error_code']  = 'http';
			$notice['http_status'] = $code;
			$notice['detail']      = self::safe_detail( self::error_message( $body ) );
			self::store( $notice );
			do_action( 'vercel_wordpress_deployer_finished', $notice );

			return $notice;
		}

		$created = isset( $job['createdAt'] ) ? (int) $job['createdAt'] : 0;

		if ( $created > 0 && $created < 100000000000 ) {
			$created *= 1000;
		}

		if ( $created <= 0 ) {
			$created = (int) round( microtime( true ) * 1000 );
		}

		$notice['phase'] = 'queued';
		$notice['since'] = max( 0, $created - 15000 );
		$notice          = self::lookup( $notice );
		self::store( $notice );

		if ( in_array( $notice['phase'], array( 'succeeded', 'failed' ), true ) ) {
			do_action( 'vercel_wordpress_deployer_finished', $notice );
		}

		return $notice;
	}

	/**
	 * Looks up the deployment created by the hook when a token is available.
	 *
	 * @param array<string, mixed> $notice Current notice.
	 * @return array<string, mixed>
	 */
	private static function refresh_if_needed( $notice ) {
		if ( empty( $notice['phase'] ) || 'idle' === $notice['phase'] ) {
			return $notice;
		}

		if ( ! in_array( $notice['phase'], array( 'queued', 'building' ), true ) ) {
			return $notice;
		}

		if ( ! empty( $notice['token_error'] ) || ! empty( $notice['give_up'] ) ) {
			return $notice;
		}

		if ( ! Settings::has_token() ) {
			return $notice;
		}

		$started_at = isset( $notice['started_at'] ) ? (int) $notice['started_at'] : time();

		if ( ( time() - $started_at ) > ( 10 * MINUTE_IN_SECONDS ) ) {
			$notice['give_up'] = 1;
			self::store( $notice );
			return $notice;
		}

		if ( get_transient( self::LOOKUP ) ) {
			return $notice;
		}

		$notice = self::lookup( $notice );
		self::store( $notice );

		if ( in_array( $notice['phase'], array( 'succeeded', 'failed' ), true ) ) {
			do_action( 'vercel_wordpress_deployer_finished', $notice );
		}

		return $notice;
	}

	/**
	 * Matches the hook to a deployment and copies its state and inspect link.
	 *
	 * @param array<string, mixed> $notice Current notice.
	 * @return array<string, mixed>
	 */
	private static function lookup( $notice ) {
		$settings = Settings::get();
		$token    = $settings['access_token'];

		if ( '' === $token || '' === $notice['project_id'] || '' === $notice['hook_id'] ) {
			return $notice;
		}

		set_transient( self::LOOKUP, 1, 3 );

		$query = array(
			'projectId' => (string) $notice['project_id'],
			'limit'     => '10',
			'since'     => (string) (int) $notice['since'],
		);
		$team  = $settings['team'];

		if ( 0 === strpos( $team, 'team_' ) ) {
			$query['teamId'] = $team;
		} elseif ( '' !== $team ) {
			$query['slug'] = $team;
		}

		$url    = add_query_arg( $query, 'https://api.vercel.com/v7/deployments' );
		$result = self::request(
			'GET',
			$url,
			array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			)
		);

		if ( 'unreachable' === $result['error'] ) {
			return $notice;
		}

		$code = (int) $result['code'];

		if ( 401 === $code || 403 === $code ) {
			$notice['token_error'] = 'auth';
			return $notice;
		}

		if ( 404 === $code ) {
			$notice['token_error'] = 'missing';
			return $notice;
		}

		if ( 200 !== $code || ! is_array( $result['body'] ) || ! isset( $result['body']['deployments'] ) || ! is_array( $result['body']['deployments'] ) ) {
			return $notice;
		}

		$deployment = self::select_deployment(
			$result['body']['deployments'],
			(string) $notice['hook_id'],
			(string) $notice['project_id'],
			(int) $notice['since']
		);

		if ( null === $deployment ) {
			return $notice;
		}

		$state = '';

		if ( isset( $deployment['readyState'] ) && is_string( $deployment['readyState'] ) ) {
			$state = strtoupper( $deployment['readyState'] );
		} elseif ( isset( $deployment['state'] ) && is_string( $deployment['state'] ) ) {
			$state = strtoupper( $deployment['state'] );
		}

		$notice['state'] = $state;
		$notice['url']   = self::deployment_link( $deployment );

		if ( '' === $state ) {
			return $notice;
		}

		$notice['phase'] = self::phase_from_state( $state );

		if ( 'failed' === $notice['phase'] ) {
			$error_message    = isset( $deployment['errorMessage'] ) && is_string( $deployment['errorMessage'] ) ? $deployment['errorMessage'] : '';
			$notice['detail'] = self::safe_detail( $error_message );
		}

		return $notice;
	}

	/**
	 * Picks the deployment created by this hook.
	 *
	 * @param array<int, mixed> $deployments Deployments from the API.
	 * @param string            $hook_id Deploy hook ID.
	 * @param string            $project_id Project ID.
	 * @param int               $since_ms Earliest created time, in milliseconds.
	 * @return array<string, mixed>|null
	 */
	private static function select_deployment( $deployments, $hook_id, $project_id, $since_ms ) {
		$hook_match   = null;
		$source_match = null;

		foreach ( $deployments as $deployment ) {
			if ( ! is_array( $deployment ) ) {
				continue;
			}

			$created = self::timestamp_ms( $deployment );

			if ( $created > 0 && $created < $since_ms ) {
				continue;
			}

			$project = isset( $deployment['projectId'] ) ? (string) $deployment['projectId'] : '';

			if ( '' !== $project && $project !== $project_id ) {
				continue;
			}

			$meta = isset( $deployment['meta'] ) && is_array( $deployment['meta'] ) ? $deployment['meta'] : array();

			foreach ( array( 'deployHookId', 'deployHookID', 'hookId' ) as $key ) {
				if ( isset( $meta[ $key ] ) && (string) $meta[ $key ] === $hook_id ) {
					$hook_match = $deployment;
					break 2;
				}
			}

			$source = isset( $deployment['source'] ) ? (string) $deployment['source'] : '';

			if ( null === $source_match && 'git-deploy-hook' === $source ) {
				$source_match = $deployment;
			}
		}

		if ( is_array( $hook_match ) ) {
			return $hook_match;
		}

		if ( is_array( $source_match ) ) {
			return $source_match;
		}

		return null;
	}

	/**
	 * Deployment created time in milliseconds.
	 *
	 * @param array<string, mixed> $deployment Deployment payload.
	 * @return int
	 */
	private static function timestamp_ms( $deployment ) {
		$raw = 0;

		if ( isset( $deployment['createdAt'] ) ) {
			$raw = (int) $deployment['createdAt'];
		} elseif ( isset( $deployment['created'] ) ) {
			$raw = (int) $deployment['created'];
		}

		if ( $raw > 0 && $raw < 100000000000 ) {
			$raw *= 1000;
		}

		return $raw;
	}

	/**
	 * Maps a Vercel ready state onto a toast phase.
	 *
	 * @param string $state Vercel state.
	 * @return string
	 */
	private static function phase_from_state( $state ) {
		switch ( $state ) {
			case 'READY':
				return 'succeeded';
			case 'ERROR':
			case 'CANCELED':
			case 'CANCELLED':
			case 'BLOCKED':
			case 'DELETED':
				return 'failed';
			default:
				return 'building';
		}
	}

	/**
	 * Inspect link, or the deployment host when that is all Vercel returned.
	 *
	 * @param array<string, mixed> $deployment Deployment payload.
	 * @return string
	 */
	private static function deployment_link( $deployment ) {
		if ( isset( $deployment['inspectorUrl'] ) && is_string( $deployment['inspectorUrl'] ) ) {
			$inspector = esc_url_raw( $deployment['inspectorUrl'] );
			$parts     = wp_parse_url( $inspector );

			if (
				is_array( $parts )
				&& isset( $parts['scheme'], $parts['host'] )
				&& 'https' === $parts['scheme']
				&& 'vercel.com' === strtolower( (string) $parts['host'] )
			) {
				return $inspector;
			}
		}

		if ( ! isset( $deployment['url'] ) || ! is_string( $deployment['url'] ) ) {
			return '';
		}

		$host = strtolower( $deployment['url'] );
		$host = (string) preg_replace( '#^https?://#', '', $host );
		$host = explode( '/', $host )[0];

		if ( is_string( $host ) && preg_match( '/^[a-z0-9.-]+\.vercel\.app$/', $host ) ) {
			return 'https://' . $host;
		}

		return '';
	}

	/**
	 * Reads the stored notice.
	 *
	 * @return array<string, mixed>
	 */
	private static function read() {
		$notice = get_transient( self::NOTICE );

		if ( ! is_array( $notice ) || empty( $notice['phase'] ) ) {
			return array( 'phase' => 'idle' );
		}

		return $notice;
	}

	/**
	 * Persists a notice for the admin toast.
	 *
	 * @param array<string, mixed> $notice Notice payload.
	 * @return void
	 */
	private static function store( $notice ) {
		$notice['updated_at'] = time();
		set_transient( self::NOTICE, $notice, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Public toast payload. Secrets stay on the server.
	 *
	 * @param array<string, mixed> $notice Stored notice.
	 * @return array<string, mixed>
	 */
	private static function present( $notice ) {
		$phase = isset( $notice['phase'] ) ? (string) $notice['phase'] : 'idle';

		if ( 'idle' === $phase ) {
			return array(
				'id'         => '',
				'phase'      => 'idle',
				'message'    => '',
				'url'        => '',
				'watch'      => false,
				'updated_at' => 0,
			);
		}

		$url  = isset( $notice['url'] ) ? esc_url_raw( (string) $notice['url'] ) : '';
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		if ( 'https' !== ( is_array( $parts ) && isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) ) {
			$url = '';
		} elseif ( 'vercel.com' !== $host && '.vercel.app' !== substr( $host, -11 ) ) {
			$url = '';
		}

		$watch = false;

		if ( empty( $notice['give_up'] ) && in_array( $phase, array( 'started', 'building' ), true ) ) {
			$watch = true;
		}

		if ( 'queued' === $phase && empty( $notice['give_up'] ) && empty( $notice['token_error'] ) && Settings::has_token() ) {
			$watch = true;
		}

		return array(
			'id'         => isset( $notice['id'] ) ? (string) $notice['id'] : '',
			'phase'      => $phase,
			'message'    => self::message( $notice ),
			'url'        => $url,
			'watch'      => $watch,
			'updated_at' => isset( $notice['updated_at'] ) ? (int) $notice['updated_at'] : time(),
		);
	}

	/**
	 * Toast copy for a notice.
	 *
	 * @param array<string, mixed> $notice Stored notice.
	 * @return string
	 */
	private static function message( $notice ) {
		$phase      = isset( $notice['phase'] ) ? (string) $notice['phase'] : 'idle';
		$error_code = isset( $notice['error_code'] ) ? (string) $notice['error_code'] : '';
		$detail     = isset( $notice['detail'] ) ? (string) $notice['detail'] : '';
		$code       = isset( $notice['http_status'] ) ? (int) $notice['http_status'] : 0;
		$token_error = isset( $notice['token_error'] ) ? (string) $notice['token_error'] : '';

		if ( 'missing_hook' === $error_code ) {
			return __( 'Add a deploy hook URL in the settings.', 'vercel-wordpress-deployer' );
		}

		if ( 'busy' === $error_code ) {
			return __( 'A deployment is already running.', 'vercel-wordpress-deployer' );
		}

		if ( 'failed' === $phase ) {
			if ( 'unreachable' === $error_code ) {
				return __( 'Deployment failed. WordPress could not reach Vercel.', 'vercel-wordpress-deployer' );
			}

			if ( $code > 0 && '' !== $detail ) {
				return sprintf(
					/* translators: 1: HTTP status code, 2: short reason from Vercel */
					__( 'Deployment failed (HTTP %1$d). %2$s', 'vercel-wordpress-deployer' ),
					$code,
					$detail
				);
			}

			if ( $code > 0 ) {
				return sprintf(
					/* translators: %d: HTTP status code */
					__( 'Deployment failed (HTTP %d).', 'vercel-wordpress-deployer' ),
					$code
				);
			}

			if ( '' !== $detail ) {
				return sprintf(
					/* translators: %s: short failure reason */
					__( 'Deployment failed. %s', 'vercel-wordpress-deployer' ),
					$detail
				);
			}

			return __( 'Deployment failed.', 'vercel-wordpress-deployer' );
		}

		if ( 'queued' === $phase && 'auth' === $token_error ) {
			return __( 'Deployment queued. The access token could not read the deployment.', 'vercel-wordpress-deployer' );
		}

		if ( 'queued' === $phase && 'missing' === $token_error ) {
			return __( 'Deployment queued. Vercel did not return a deployment for this token and team.', 'vercel-wordpress-deployer' );
		}

		switch ( $phase ) {
			case 'started':
				return __( 'Deployment started', 'vercel-wordpress-deployer' );
			case 'building':
				return __( 'Deployment is building', 'vercel-wordpress-deployer' );
			case 'succeeded':
				return __( 'Deployment succeeded', 'vercel-wordpress-deployer' );
			case 'queued':
			default:
				return __( 'Deployment queued', 'vercel-wordpress-deployer' );
		}
	}

	/**
	 * Error string from a Vercel JSON body.
	 *
	 * @param array<string, mixed> $body Response body.
	 * @return string
	 */
	private static function error_message( $body ) {
		if ( isset( $body['error'] ) && is_array( $body['error'] ) && isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
			return $body['error']['message'];
		}

		if ( isset( $body['message'] ) && is_string( $body['message'] ) ) {
			return $body['message'];
		}

		return '';
	}

	/**
	 * Strips tags, URLs, and hook identifiers from a reason shown in the admin.
	 *
	 * @param string $detail Raw reason.
	 * @return string
	 */
	private static function safe_detail( $detail ) {
		$detail = wp_strip_all_tags( $detail );
		$detail = (string) preg_replace( '#https?://\S+#', '', $detail );
		$detail = trim( (string) preg_replace( '/\s+/', ' ', $detail ) );

		if ( false !== stripos( $detail, 'prj_' ) || false !== stripos( $detail, 'integrations/deploy' ) ) {
			return '';
		}

		if ( strlen( $detail ) > 140 ) {
			$detail = substr( $detail, 0, 137 ) . '...';
		}

		return $detail;
	}

	/**
	 * Calls Vercel without following redirects.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url Absolute Vercel URL.
	 * @param array<string, string> $headers Request headers.
	 * @return array{ok: bool, code: int, body: array<string, mixed>|null, error: string}
	 */
	private static function request( $method, $url, $headers ) {
		$timeout = (int) apply_filters( 'vercel_wordpress_deployer_timeout', 12 );

		if ( $timeout < 3 ) {
			$timeout = 3;
		}

		if ( $timeout > 20 ) {
			$timeout = 20;
		}

		$response = wp_safe_remote_request(
			$url,
			array(
				'method'      => $method,
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => $headers,
				'user-agent'  => 'VercelWordPressDeployer/' . VERCEL_WORDPRESS_DEPLOYER_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'body'  => null,
				'error' => 'unreachable',
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'ok'    => true,
			'code'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'  => is_array( $decoded ) ? $decoded : null,
			'error' => '',
		);
	}

	/**
	 * Seconds to wait so a burst of saves becomes one deployment.
	 *
	 * @return int
	 */
	private static function debounce_seconds() {
		$seconds = (int) apply_filters( 'vercel_wordpress_deployer_debounce', 3 );

		if ( $seconds < 0 ) {
			return 0;
		}

		if ( $seconds > 60 ) {
			return 60;
		}

		return $seconds;
	}

	/**
	 * Schedules one follow-up when WP-Cron is the only runner.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	private static function schedule( $delay ) {
		$timestamp = time() + max( 1, (int) $delay );

		$next = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $next && $next <= $timestamp ) {
			return;
		}

		if ( false !== $next ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}

		wp_schedule_single_event( $timestamp, self::CRON_HOOK );
	}

	/**
	 * Takes a short lock so two requests do not fire the hook together.
	 *
	 * @return bool
	 */
	private static function lock() {
		$now = time();

		if ( add_option( self::LOCK, $now, '', false ) ) {
			return true;
		}

		$existing = (int) get_option( self::LOCK, 0 );

		if ( ( $now - $existing ) < 90 ) {
			return false;
		}

		update_option( self::LOCK, $now, false );

		return true;
	}

	/**
	 * Releases the deploy lock.
	 *
	 * @return void
	 */
	private static function unlock() {
		delete_option( self::LOCK );
	}
}
