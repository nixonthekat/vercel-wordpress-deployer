<?php
/**
 * Admin bar, assets, and deploy routes.
 *
 * @package VercelWordPressDeployer
 */

namespace VercelWordPressDeployer;

defined( 'ABSPATH' ) || exit;

/**
 * Toolbar deploy action and the toast client.
 */
class Admin {

	/**
	 * Registers admin UI and REST routes.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 80 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VERCEL_WORDPRESS_DEPLOYER_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array<int, string> $links Existing links.
	 * @return array<int, string>
	 */
	public static function action_links( $links ) {
		if ( is_network_admin() || ! current_user_can( Plugin::capability() ) ) {
			return $links;
		}

		$url = admin_url( 'options-general.php?page=' . Settings::PAGE );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'vercel-wordpress-deployer' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Deploy item in the top toolbar.
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar.
	 * @return void
	 */
	public static function admin_bar( $admin_bar ) {
		if ( is_network_admin() || ! is_admin_bar_showing() || ! current_user_can( Plugin::capability() ) ) {
			return;
		}

		$title = sprintf(
			'<span class="ab-icon dashicons dashicons-cloud-upload" aria-hidden="true"></span><span class="ab-label">%s</span>',
			esc_html__( 'Deploy', 'vercel-wordpress-deployer' )
		);

		$admin_bar->add_node(
			array(
				'id'    => 'vercel-wordpress-deployer',
				'title' => $title,
				'href'  => admin_url( 'options-general.php?page=' . Settings::PAGE ),
				'meta'  => array(
					'title' => __( 'Vercel Deployer', 'vercel-wordpress-deployer' ),
					'class' => 'vwd-admin-bar',
				),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'vercel-wordpress-deployer-now',
				'parent' => 'vercel-wordpress-deployer',
				'title'  => esc_html__( 'Deploy now', 'vercel-wordpress-deployer' ),
				'href'   => '#vercel-deploy',
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'vercel-wordpress-deployer-settings',
				'parent' => 'vercel-wordpress-deployer',
				'title'  => esc_html__( 'Settings', 'vercel-wordpress-deployer' ),
				'href'   => admin_url( 'options-general.php?page=' . Settings::PAGE ),
			)
		);
	}

	/**
	 * Loads the toast script for administrators, including the front-end toolbar.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( is_network_admin() || is_customize_preview() || ! current_user_can( Plugin::capability() ) ) {
			return;
		}

		if ( ! is_admin() && ! is_admin_bar_showing() ) {
			return;
		}

		wp_enqueue_style(
			'vercel-wordpress-deployer',
			VERCEL_WORDPRESS_DEPLOYER_URL . 'assets/css/admin.css',
			array(),
			VERCEL_WORDPRESS_DEPLOYER_VERSION
		);

		wp_enqueue_script(
			'vercel-wordpress-deployer',
			VERCEL_WORDPRESS_DEPLOYER_URL . 'assets/js/admin.js',
			array(),
			VERCEL_WORDPRESS_DEPLOYER_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			'vercel-wordpress-deployer',
			'window.vercelWordPressDeployer = ' . wp_json_encode( self::script_config() ) . ';',
			'before'
		);
	}

	/**
	 * Data the browser needs. The hook URL and token are not included.
	 *
	 * @return array<string, mixed>
	 */
	private static function script_config() {
		return array(
			'deployUrl' => rest_url( 'vercel-wordpress-deployer/v1/deploy' ),
			'statusUrl' => rest_url( 'vercel-wordpress-deployer/v1/status' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'i18n'      => array(
				'started' => __( 'Deployment started', 'vercel-wordpress-deployer' ),
				'network' => __( 'Deployment failed. The request did not complete.', 'vercel-wordpress-deployer' ),
				'see'     => __( 'See Deployment', 'vercel-wordpress-deployer' ),
				'dismiss' => __( 'Dismiss', 'vercel-wordpress-deployer' ),
			),
		);
	}

	/**
	 * REST routes for the manual deploy and toast polling.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'vercel-wordpress-deployer/v1',
			'/deploy',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest_deploy' ),
				'permission_callback' => array( self::class, 'can_deploy' ),
			)
		);

		register_rest_route(
			'vercel-wordpress-deployer/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_status' ),
				'permission_callback' => array( self::class, 'can_deploy' ),
			)
		);
	}

	/**
	 * Permission check for deploy routes.
	 *
	 * @return bool
	 */
	public static function can_deploy() {
		return current_user_can( Plugin::capability() );
	}

	/**
	 * Manual deployment.
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_deploy() {
		return self::json( Deployer::run( 'manual' ) );
	}

	/**
	 * Current deployment, and any content change that has finished debouncing.
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_status() {
		return self::json( Deployer::status() );
	}

	/**
	 * JSON response that must not be cached.
	 *
	 * @param array<string, mixed> $data Toast payload.
	 * @return \WP_REST_Response
	 */
	private static function json( $data ) {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );

		return $response;
	}
}
