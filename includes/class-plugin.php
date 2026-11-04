<?php
/**
 * Plugin bootstrap.
 *
 * @package VercelWordPressDeployer
 */

namespace VercelWordPressDeployer;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 */
class Plugin {

	/**
	 * Capability used for settings and manual deploys.
	 *
	 * @return string
	 */
	public static function capability() {
		$capability = apply_filters( 'vercel_wordpress_deployer_capability', 'manage_options' );

		if ( ! is_string( $capability ) || '' === $capability ) {
			return 'manage_options';
		}

		return $capability;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( self::class, 'load_textdomain' ) );
		Settings::register();
		Deployer::register();
		Triggers::register();
		Admin::register();
	}

	/**
	 * Loads translations after init, which WordPress 6.7 requires.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain(
			'vercel-wordpress-deployer',
			false,
			dirname( plugin_basename( VERCEL_WORDPRESS_DEPLOYER_FILE ) ) . '/languages'
		);
	}

	/**
	 * Stores the default settings without autoloading the deploy hook.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( Settings::OPTION, false );
		}
	}

	/**
	 * Clears the debounce event and any in-flight lock.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Deployer::CRON_HOOK );
		delete_option( Deployer::LOCK );
	}
}
