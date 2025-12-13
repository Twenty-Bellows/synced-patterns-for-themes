<?php

require_once __DIR__ . '/class-pattern-builder-post-type.php';

class Synced_Patterns_Loader {

private static $synced_theme_patterns = [];

	public function __construct() 
	{
		add_action( 'init', array( $this, 'register_patterns' ) );
		add_filter('rest_request_after_callbacks', [$this, 'inject_theme_synced_patterns'], 10, 3);
		add_filter('rest_request_after_callbacks', [$this, 'handle_hijack_block_update'], 10, 3);
		add_filter('pre_render_block', [$this, 'pass_pattern_content_to_referenced_patterns'], 10, 2);
	}

	public function register_patterns() {

		$pattern_registry = WP_Block_Patterns_Registry::get_instance();

		$pattern_files = $this->get_synced_patterns_from_theme_files();

		foreach ($pattern_files as $pattern_file_data) {

			$pattern_slug = $pattern_file_data['slug'];

			$pattern_post = get_page_by_path(sanitize_title($pattern_slug), OBJECT, 'pb_block');

			$file_modified_time = filemtime($pattern_file_data['file']);

			if ( $pattern_post) {
				$post_id = $pattern_post->ID;
				self::$synced_theme_patterns[ $pattern_slug ] = $post_id;
				$post_modified_time = strtotime($pattern_post->post_modified_gmt);

				if ( $file_modified_time > $post_modified_time ) {
					$pattern_post->post_title = $pattern_file_data['title'];
					$pattern_post->post_content = self::render_pattern($pattern_file_data['file']);
					$pattern_post->post_modified = gmdate('Y-m-d H:i:s', $file_modified_time);
					$pattern_post->post_modified_gmt = gmdate('Y-m-d H:i:s', $file_modified_time);
					wp_update_post($pattern_post);
				}
			}
			else {
				$post_id = wp_insert_post(array(
					'post_title' => $pattern_file_data['title'],
					'post_name' => $pattern_slug,
					'post_content' => self::render_pattern($pattern_file_data['file']),
					'post_type' => 'pb_block',
					'post_status' => 'publish',
					'ping_status' => 'closed',
					'comment_status' => 'closed',
					'post_modified' => gmdate('Y-m-d H:i:s', $file_modified_time),
					'post_modified_gmt' => gmdate('Y-m-d H:i:s', $file_modified_time),
				));
				self::$synced_theme_patterns[ $pattern_slug ] = $post_id;
			}

			if (! empty($pattern_file_data['categories'])) {
				wp_set_object_terms($post_id, $pattern_file_data['categories'], 'wp_pattern_category');
			}

			// UN register the unsynced pattern and RE register it with the reference to the synced pattern
			// this pattern injects a synced pattern block as the content.
			// and allows it to be used by anything that uses the wp:pattern with its slug

			if ($pattern_registry->is_registered($pattern_slug)) {
				$pattern_registry->unregister($pattern_slug);
			}
			
			$pattern_registry->register(
				$pattern_slug,
				array(
					'title'   => $pattern_file_data['title'],
					'slug'   => $pattern_slug,
					'inserter' => false,
					'content' => '<!-- wp:block {"ref":' . $post_id . '} /-->',
				)
			);
		}
	}

	public function inject_theme_synced_patterns($response, $server, $request)
	{
		// Requesting a single pattern.  Inject the synced theme pattern.
		if (preg_match('#/wp/v2/blocks/(?P<id>\d+)#', $request->get_route(), $matches)) {
			$block_id = intval($matches['id']);
			$pb_block = get_post($block_id);
			if ($pb_block && $pb_block->post_type === 'pb_block') {
				$data = $this->format_pb_block_response($pb_block, $request);
				$response = new WP_REST_Response($data);
			}
		}

		// Requesting all patterns.  Inject all of the synced theme patterns.
		else if ($request->get_route() === '/wp/v2/blocks') {

			$data = $response->get_data();
			$pattern_files = $this->get_synced_patterns_from_theme_files();

			foreach ($pattern_files as $pattern) {
				$post = get_page_by_path(sanitize_title($pattern['slug']), OBJECT, 'pb_block');
				$data[] = $this->format_pb_block_response($post, $request);
			}

			$response->set_data($data);
		}

		return $response;
	}

	public function format_pb_block_response($post, $request)
	{
		// Use WordPress core's REST controller for proper formatting
		$controller = new WP_REST_Blocks_Controller('wp_block');

		// Change the post type to wp_block for proper magic making
		$post->post_type = 'wp_block';

		// Use the controller's prepare_item_for_response method
		$response = $controller->prepare_item_for_response($post, $request);
		$data = $response->get_data();

		return $data;
	}

	public function handle_hijack_block_update($response, $handler, $request)
	{
		$route = $request->get_route();

		if (preg_match('#^/wp/v2/blocks/(\d+)$#', $route, $matches) && $request->get_method() === 'PUT') {

			$id = intval($matches[1]);
			$post = get_post($id);

			if ($post && $post->post_type === 'pb_block') {
				// pb_blocks cannot be saved.  return an error response
				return new WP_Error(
					'rest_cannot_update_pb_block',
					__('Synced Theme Patterns cannot be updated in the editor without additional tools.', 'synced-patterns-for-themes'),
					array('status' => 403)
				);
			}
		}
		return $response;
	}

	function render_pattern($pattern_file)
	{
		ob_start();
		include $pattern_file;
		return ob_get_clean();
	}

	private function get_synced_patterns_from_theme_files()
	{
		$pattern_files = glob(get_stylesheet_directory() . '/patterns/*.php');
		$patterns = [];

		foreach ($pattern_files as $pattern_file) {
			$pattern_data = get_file_data($pattern_file, array(
				'title'         => 'Title',
				'slug'          => 'Slug',
				'description'   => 'Description',
				'viewportWidth' => 'Viewport Width',
				'inserter'      => 'Inserter',
				'categories'    => 'Categories',
				'keywords'      => 'Keywords',
				'blockTypes'    => 'Block Types',
				'postTypes'     => 'Post Types',
				'templateTypes' => 'Template Types',
				'synced'	=> 'Synced',
			));

			// if the pattern is not synced skip it 
			if ($pattern_data['synced'] !== 'yes') {
				continue;
			}

			$pattern_data['file'] = $pattern_file;

			$patterns[] = $pattern_data;
		}

		return $patterns;
	}

	/**
	 * Filters pattern block data to apply attributes to nested wp:block.
	 * This allows patterns with content attributes to pass those attributes down to the nested wp:block.
	 *
	 * @param array $parsed_block The parsed block data.
	 * @param array $source_block The original block data.
	 * @return array Modified block data.
	 */
	public function pass_pattern_content_to_referenced_patterns($pre_render, $parsed_block)
	{
		// Only process wp:pattern blocks
		if ($parsed_block['blockName'] !== 'core/pattern') {
			return $pre_render;
		}

		// Extract attributes from the pattern block
		$pattern_attrs = isset($parsed_block['attrs']) ? $parsed_block['attrs'] : [];

		$slug = $pattern_attrs['slug'] ?? '';

		// Remove attributes we don't want to pass down
		unset($pattern_attrs['slug']);

		// If no attributes to apply, return as-is
		if (empty($pattern_attrs)) {
			return $pre_render;
		}

		$synced_pattern_id = self::$synced_theme_patterns[$slug] ?? null;

		// if there is a synced_pattern_id then contruct the block with a reference to the synced pattern that also has the rest of the pattern's attributes and render it.
		if ($synced_pattern_id) {
			$block_attributes = array_merge(
				['ref' => $synced_pattern_id],
				$pattern_attrs
			);
			$block_attributes = wp_json_encode($block_attributes);
			$block_string = "<!-- wp:block $block_attributes /-->";
			return do_blocks($block_string);
		}

		return $pre_render;
	}
}