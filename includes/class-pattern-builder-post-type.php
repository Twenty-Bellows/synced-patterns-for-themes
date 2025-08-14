<?php

class Pattern_Builder_Post_Type
{
	public function __construct()
	{
		add_action('init', [$this, 'register_pb_block_post_type']);
		add_filter('render_block', [$this, 'render_pb_blocks'] , 10, 2);
		add_filter('register_block_type_args', [$this, 'add_content_attribute_to_core_pattern_block'] , 10, 2);
	}

	/**
	 * Adds a "content" attribute to the core/pattern block type.
	 * This is used to store the pattern overrides for the block.
	 *
	 * @param array $args The block type arguments.
	 * @param string $block_type The block type name.
	 * @return array
	 */
	public function add_content_attribute_to_core_pattern_block($args, $block_type)
	{
		if ($block_type === 'core/pattern') {
			$extra_attributes = array(
				"content" => array(
					"type" => "object",
				)
			);
			$args['attributes'] = array_merge($args['attributes'], $extra_attributes);
		}
		return $args;
	}

	/**
	 * Registers the pb_block custom post type.
	 */
	public function register_pb_block_post_type()
	{
		$labels = [
			'name'               => __('PB Blocks', 'synced-patterns-for-themes'),
			'singular_name'      => __('PB Block', 'synced-patterns-for-themes'),
		];

		$args = [
			'labels'             => $labels,

			'public'             => true,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'show_in_rest'       => true,
			'rest_base'          => 'pb_blocks',
			'supports'           => ['title', 'editor', 'revisions'],
			'hierarchical'       => false,
			'capability_type'    => 'pb_block',
			'map_meta_cap'       => true,
		];

		register_post_type('pb_block', $args);

		register_post_meta('pb_block', 'wp_pattern_sync_status', [
			'show_in_rest' => true,
			'type'         => 'string',
			'single'       => true,
		]);

		register_post_meta('pb_block', 'wp_pattern_block_types', [
			'show_in_rest' => true,
			'type'         => 'string',
			'single'       => true,
		]);

		register_post_meta('pb_block', 'wp_pattern_template_types', [
			'show_in_rest' => true,
			'type'         => 'string',
			'single'       => true,
		]);

		register_post_meta('pb_block', 'wp_pattern_post_types', [
			'show_in_rest' => true,
			'type'         => 'string',
			'single'       => true,
		]);



		/**
		 * Add custom capabilities for the pb_block post type.
		 */

		$roles = ['administrator', 'editor'];

		$capabilities = [
			'edit_pb_block',
			'read_pb_block',
			'delete_pb_block',
			'edit_pb_blocks',
			'edit_others_pb_blocks',
			'publish_pb_blocks',
			'read_private_pb_blocks',
			'delete_pb_blocks',
			'delete_private_pb_blocks',
			'delete_published_pb_blocks',
			'delete_others_pb_blocks',
			'edit_private_pb_blocks',
			'edit_published_pb_blocks',
		];

		// Assign capabilities to each role
		foreach ($roles as $role_name) {
			$role = get_role($role_name);
			if ($role) {
				foreach ($capabilities as $capability) {
					$role->add_cap($capability);
				}
			}
		}
	}

	/**
	 * Renders a "pb_block" block pattern.
	 * This is a block pattern stored as a pb_block post type instead of a wp_block post type.
	 * Which means that it is a "theme pattern" instead of a "user pattern".
	 *
	 * This borrows heavily from the core block rendering function.
	 *
	 * @param string $block_content The block content.
	 * @param array  $block        The block data.
	 * @return string
	 */
	public function render_pb_blocks($block_content, $block)
	{
		// store a reference to the block to prevent infinite recursion
		static $seen_refs = array();

		// if we have a block pattern with no content we PROBABLY are trying to render
		// a pb_block (theme pattern)
		if ($block['blockName'] === 'core/block' && $block_content === '') {

			$attributes = $block['attrs'] ?? [];

			if (empty($attributes['ref'])) {
				return '';
			}

			$post = get_post($attributes['ref']);
			if (! $post || 'pb_block' !== $post->post_type) {
				return '';
			}

			// if we have already seen this block, return an empty string to prevent recursion
			if ( isset( $seen_refs[ $attributes['ref'] ] ) ) {
				return '';
			}

			if ('publish' !== $post->post_status || ! empty($post->post_password)) {
				return '';
			}

			$seen_refs[ $attributes['ref'] ] = true;


			// Handle embeds for reusable blocks.
			global $wp_embed;
			$content = $wp_embed->run_shortcode( $post->post_content );
			$content = $wp_embed->autoembed( $content );

			/**
			 * We set the `pattern/overrides` context through the `render_block_context`
			 * filter so that it is available when a pattern's inner blocks are
			 * rendering via do_blocks given it only receives the inner content.
			 */
			$has_pattern_overrides = isset($attributes['content']) && null !== get_block_bindings_source('core/pattern-overrides');
			if ($has_pattern_overrides) {
				$filter_block_context = static function ($context) use ($attributes) {
					$context['pattern/overrides'] = $attributes['content'];
					return $context;
				};
				add_filter('render_block_context', $filter_block_context, 1);
			}

			// Apply Block Hooks.
			$content = apply_block_hooks_to_content_from_post_object($content, $post);

			// Render the block content.
			$content = do_blocks($content);

			// It is safe to render this block again.  No infinite recursion worries.
			unset($seen_refs[$attributes['ref']]);

			if ($has_pattern_overrides) {
				remove_filter('render_block_context', $filter_block_context, 1);
			}

			return $content;
		}
		return $block_content;
	}
}

new Pattern_Builder_Post_Type();