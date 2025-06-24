<?php
/**
 * @wordpress-plugin
 * Plugin Name:       Synced Patterns for Themes
 * Plugin URI:        https://github.com/Twenty-Bellows/synced-patterns-for-themes
 * Description:       Empower Themes to provide Synced Patterns
 * Requires at least: 6.6
 * Requires PHP:      7.2
 * Version:           1.1.0
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

// If the Pattern Builder plugin is active, do not load this plugin.  Synced Patterns are already supported.
if (is_plugin_active( 'pattern-builder/pattern-builder.php' )) {
	return;
}

require plugin_dir_path( __FILE__ ) . 'includes/class-synced-patterns-loader.php';
$synced_patterns_loader = new Synced_Patterns_Loader();

add_action('enqueue_block_editor_assets', function() {
	$asset_file = include plugin_dir_path(__FILE__) . './build/index.asset.php';
	wp_enqueue_script(
		'synced-patterns-for-themes',
		plugins_url( './build/index.js', __FILE__ ),
		$asset_file['dependencies'],
		$asset_file['version']
	);
});