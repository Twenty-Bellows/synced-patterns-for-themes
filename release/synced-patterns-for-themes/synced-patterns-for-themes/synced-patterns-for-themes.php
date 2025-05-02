<?php
/**
 * @wordpress-plugin
 * Plugin Name: Synced Patterns for Themes
 * Description: Empower Themes to provide Synced Patterns to an environment
 * Version: 1.0
 * Author: pbking
 * Author URI: https://pbking.com
 * License: GPL2
 * Text Domain: synced-patterns-for-themes 
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// If we are not in the admin area, do not load the plugin
if ( ! is_admin() ) {
	return;
}

require plugin_dir_path( __FILE__ ) . 'includes/class-synced-patterns-loader.php';
$synced_patterns_loader = new Synced_Patterns_Loader();