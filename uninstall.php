<?php
/**
 * Removes plugin data on uninstall.
 *
 * @package VercelWordPressDeployer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes options, transients, and the debounce event for one site.
 *
 * @return void
 */
function vercel_wordpress_deployer_delete_site_data() {
	delete_option( 'vercel_wordpress_deployer_settings' );
	delete_option( 'vercel_wordpress_deployer_lock' );
	delete_transient( 'vercel_wordpress_deployer_notice' );
	delete_transient( 'vercel_wordpress_deployer_dirty' );
	delete_transient( 'vercel_wordpress_deployer_lookup' );
	wp_clear_scheduled_hook( 'vercel_wordpress_deployer_debounced' );
}

if ( is_multisite() ) {
	$vercel_wordpress_deployer_offset = 0;

	do {
		$vercel_wordpress_deployer_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $vercel_wordpress_deployer_offset,
			)
		);

		if ( ! is_array( $vercel_wordpress_deployer_site_ids ) ) {
			break;
		}

		foreach ( $vercel_wordpress_deployer_site_ids as $vercel_wordpress_deployer_site_id ) {
			switch_to_blog( (int) $vercel_wordpress_deployer_site_id );
			vercel_wordpress_deployer_delete_site_data();
			restore_current_blog();
		}

		$vercel_wordpress_deployer_offset += 100;
	} while ( 100 === count( $vercel_wordpress_deployer_site_ids ) );
} else {
	vercel_wordpress_deployer_delete_site_data();
}
