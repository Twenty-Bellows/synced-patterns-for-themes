<?php

class Synced_Patterns_Loader {

	public function __construct() 
	{
		add_action( 'plugins_loaded', array( $this, 'register_patterns' ) );
	}

	function render_pattern($pattern_file)
	{
		ob_start();
		include $pattern_file;
		return ob_get_clean();
	}

	function register_patterns() {

		$pattern_files = glob(get_stylesheet_directory() . '/patterns/*.php');
	
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

			// check if the pattern already exists
			$pattern_post = get_page_by_path(sanitize_title($pattern_data['slug']), OBJECT, 'wp_block');

			if ( $pattern_post) {
				$post_id = $pattern_post->ID;
			} 
			else {
				// the post does not exist.  create it.
				$post_id = wp_insert_post(array(
					'post_title' => $pattern_data['title'],
					'post_name' => $pattern_data['slug'],
					'post_content' => self::render_pattern($pattern_file),
					'post_type' => 'wp_block',
					'post_status' => 'publish',
					'ping_status' => 'closed',
					'comment_status' => 'closed',
					'meta_input' => array(
						'wp_pattern_sync_status' => $pattern_data['synced'] === 'yes' ? "" : "unsynced",
					),
				));

				// Set the categories of the post
				$categories = self::get_pattern_categories($pattern_data);

				if (! empty($categories)) {
					wp_set_object_terms($post_id, $categories, 'wp_pattern_category');
				}
			}

			// UN register the unsynced pattern and RE register it with the reference to the synced pattern
			// this pattern injects a synced pattern block as the content.
			// and allows it to be used by anything that uses the wp:pattern with its slug
			$pattern_registry = WP_Block_Patterns_Registry::get_instance();

			if ($pattern_registry->is_registered($pattern_data['slug'])) {
				$pattern_registry->unregister($pattern_data['slug']);
			}
			
			$pattern_registry->register(
				$pattern_data['slug'],
				array(
					'title'   => $pattern_data['title'] . ' (Synced)',
					'slug'   => $pattern_data['slug'],
					'inserter' => false,
					'content' => '<!-- wp:block {"ref":' . $post_id . '} /-->',
				)
			);
		}
	}

	function get_pattern_categories($pattern_data)
	{
		//get the default pattern categories
		$registered_pattern_categories = WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered();

		$category_ids = array();
		$categories = explode(',', $pattern_data['categories']);
		$terms = get_terms(array(
			'taxonomy' => 'wp_pattern_category',
			'hide_empty' => false,
			'fields' => 'all',

		));
		foreach ($categories as $category) {
			$category = sanitize_title($category);
			$found = false;
			foreach ($terms as $term) {
				if (sanitize_title($term->name) === $category || sanitize_title($term->slug) === $category) {
					$category_ids[] = $term->term_id;
					$found = true;
					break;
				}
			}
			if (! $found) {
				// See if it's in the registered_pattern_categories
				foreach ($registered_pattern_categories as $registered_category) {
					if (
						(isset($registered_category['slug']) && sanitize_title($registered_category['slug']) === $category) ||
						(isset($registered_category['name']) && sanitize_title($registered_category['name']) === $category)
					) {
						$term = wp_insert_term($registered_category['label'], 'wp_pattern_category', array(
							'slug' => $registered_category['name'],
							'description' => $registered_category['description'] ?? '',
						));
						$terms[] = (object) $term;
						$category_ids[] = $term['term_id'];
						$found = true;
						break;
					}
				}
			}
			// if the term is still not found then I guess we're just out of luck.
		}
		return $category_ids;
	}
}