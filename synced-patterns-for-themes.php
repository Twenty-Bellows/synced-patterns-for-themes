<?php
/**
 * @wordpress-plugin
 * Plugin Name:       Synced Patterns for Themes
 * Plugin URI:        https://github.com/Twenty-Bellows/synced-patterns-for-themes
 * Description:       Empower Themes to provide Synced Patterns
 * Requires at least: 6.6
 * Requires PHP:      7.2
 * Version:           1.0.0
 * Author:            Twenty Bellows 
 * Author URI:        https://twentybellows.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       synced-patterns-for-themes 
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require plugin_dir_path( __FILE__ ) . 'includes/class-synced-patterns-loader.php';
$synced_patterns_loader = new Synced_Patterns_Loader();