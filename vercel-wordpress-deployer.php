<?php
/**
 * Plugin Name:       Vercel Deployer
 * Description:       Deploys a Vercel project when selected content changes, or when you deploy manually.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            nixonthekat
 * Author URI:        https://github.com/nixonthekat
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vercel-wordpress-deployer
 *
 * @package VercelWordPressDeployer
 */

defined( 'ABSPATH' ) || exit;

define( 'VERCEL_WORDPRESS_DEPLOYER_FILE', __FILE__ );
define( 'VERCEL_WORDPRESS_DEPLOYER_VERSION', '1.0.0' );
define( 'VERCEL_WORDPRESS_DEPLOYER_PATH', plugin_dir_path( __FILE__ ) );
define( 'VERCEL_WORDPRESS_DEPLOYER_URL', plugin_dir_url( __FILE__ ) );

require_once VERCEL_WORDPRESS_DEPLOYER_PATH . 'includes/class-settings.php';
require_once VERCEL_WORDPRESS_DEPLOYER_PATH . 'includes/class-deployer.php';
require_once VERCEL_WORDPRESS_DEPLOYER_PATH . 'includes/class-triggers.php';
require_once VERCEL_WORDPRESS_DEPLOYER_PATH . 'includes/class-admin.php';
require_once VERCEL_WORDPRESS_DEPLOYER_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'VercelWordPressDeployer\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VercelWordPressDeployer\\Plugin', 'deactivate' ) );

VercelWordPressDeployer\Plugin::init();
