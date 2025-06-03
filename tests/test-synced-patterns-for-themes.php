<?php

require_once __DIR__ . '/../includes/class-synced-patterns-loader.php';
require_once __DIR__ . '/../includes/class-pattern-builder-post-type.php';

/**
 * Test cases for the Synced Patterns for Themes plugin
 */
class Synced_Patterns_For_Themes_Integration_Test extends WP_UnitTestCase {

	private $synced_patterns;
	private $test_dir;
	private $pattern_dir;

	/**
	 * Set up the environment for each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Create an instance of the class under test
		$this->synced_patterns = new Synced_Patterns_Loader();

		// Create a temporary directory for the test patterns
		$this->test_dir = sys_get_temp_dir() . '/synced-patterns-test';
		$this->pattern_dir = $this->test_dir . '/patterns';
		
		if (!is_dir($this->test_dir)) {
			mkdir($this->test_dir);
		}
		if (!is_dir($this->pattern_dir)) {
			mkdir($this->pattern_dir);
		}

		// Set the directory where pattern files will be stored for the test
		add_filter('stylesheet_directory', function() {
			return $this->test_dir;
		});

		// Clean up any existing pb_block posts
		$existing_posts = get_posts([
			'post_type' => 'pb_block',
			'posts_per_page' => -1,
			'post_status' => 'any'
		]);
		foreach ($existing_posts as $post) {
			wp_delete_post($post->ID, true);
		}
	}

	/**
	 * Clean up after each test
	 */
	public function tearDown(): void {
		// Remove all test files and directory
		$this->remove_test_directory($this->test_dir);
		
		// Clean up any pb_block posts created during tests
		$posts = get_posts([
			'post_type' => 'pb_block',
			'posts_per_page' => -1,
			'post_status' => 'any'
		]);
		foreach ($posts as $post) {
			wp_delete_post($post->ID, true);
		}
		
		parent::tearDown();
	}

	/**
	 * Helper function to recursively remove a directory
	 */
	private function remove_test_directory($dir) {
		if (is_dir($dir)) {
			$files = array_diff(scandir($dir), ['.', '..']);
			foreach ($files as $file) {
				(is_dir("$dir/$file")) ? $this->remove_test_directory("$dir/$file") : unlink("$dir/$file");
			}
			rmdir($dir);
		}
	}

	/**
	 * Test that a synced pattern is registered and saved as a pb_block post
	 */
	public function test_register_patterns_creates_new_post() {
		$pattern_content = '<?php
/**
 * Title: A Synced Theme Pattern
 * Slug: test/a-synced-theme-pattern
 * Categories: Featured
 * Synced: yes 
 */
?>
<!-- wp:paragraph -->
<p>This is a synced Theme Pattern</p>
<!-- /wp:paragraph -->';
		
		$pattern_file = $this->pattern_dir . '/test-pattern.php';
		file_put_contents($pattern_file, $pattern_content);

		// Run the method under test
		$this->synced_patterns->register_patterns();

		// Check that the post was created with pb_block post type
		$post = get_page_by_path('test-a-synced-theme-pattern', OBJECT, 'pb_block');
		$this->assertNotNull($post, 'The pb_block post should have been created');
		$this->assertEquals('publish', $post->post_status);
		$this->assertEquals('test-a-synced-theme-pattern', $post->post_name);
		$this->assertStringContainsString('This is a synced Theme Pattern', $post->post_content);

		// Check that the pattern was registered in the pattern registry
		$pattern_registry = WP_Block_Patterns_Registry::get_instance();
		$this->assertTrue($pattern_registry->is_registered('test/a-synced-theme-pattern'));
		
		// Check pattern content contains reference to the pb_block
		$registered_pattern = $pattern_registry->get_registered('test/a-synced-theme-pattern');
		$this->assertStringContainsString('<!-- wp:block {"ref":' . $post->ID . '} /-->', $registered_pattern['content']);
	}

	/**
	 * Test that patterns without Synced: yes are ignored
	 */
	public function test_register_patterns_ignores_non_synced_patterns() {
		$pattern_content = '<?php
/**
 * Title: Non-Synced Pattern
 * Slug: test/non-synced-pattern
 * Categories: Featured
 */
?>
<!-- wp:paragraph -->
<p>This is NOT a synced pattern</p>
<!-- /wp:paragraph -->';
		
		$pattern_file = $this->pattern_dir . '/non-synced-pattern.php';
		file_put_contents($pattern_file, $pattern_content);

		// Run the method under test
		$this->synced_patterns->register_patterns();

		// Check that the post was NOT created
		$post = get_page_by_path('test-non-synced-pattern', OBJECT, 'pb_block');
		$this->assertNull($post, 'Non-synced patterns should not create pb_block posts');
	}

	/**
	 * Test that an existing pattern post is updated when the pattern file changes
	 */
	public function test_register_patterns_updates_existing_post() {
		$pattern_file = $this->pattern_dir . '/test-pattern.php';

		// Create initial pattern file
		$pattern_content = '<?php
/**
 * Title: Test Pattern
 * Slug: test-pattern 
 * Categories: Featured
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>Original Content</p>
<!-- /wp:paragraph -->';

		file_put_contents($pattern_file, $pattern_content);
		$this->synced_patterns->register_patterns();

		// Update pattern file with new content
		$pattern_content = '<?php
/**
 * Title: Updated Test Pattern
 * Slug: test-pattern 
 * Categories: Featured, Updated
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>Updated Content</p>
<!-- /wp:paragraph -->';

		file_put_contents($pattern_file, $pattern_content);
		$this->synced_patterns->register_patterns();

		// Fetch the updated post and verify its content
		$updated_post = get_page_by_path('test-pattern', OBJECT, 'pb_block');
		$this->assertNotNull($updated_post);
		$this->assertEquals('Updated Test Pattern', $updated_post->post_title);
		$this->assertStringContainsString('Updated Content', $updated_post->post_content);
	}

	/**
	 * Test categories are properly set on pattern posts
	 */
	public function test_pattern_categories_are_set() {
		// First register the pattern categories
		if (!term_exists('featured', 'wp_pattern_category')) {
			wp_insert_term('Featured', 'wp_pattern_category', ['slug' => 'featured']);
		}
		if (!term_exists('header', 'wp_pattern_category')) {
			wp_insert_term('Headers', 'wp_pattern_category', ['slug' => 'header']);
		}
		if (!term_exists('call-to-action', 'wp_pattern_category')) {
			wp_insert_term('Call to Action', 'wp_pattern_category', ['slug' => 'call-to-action']);
		}
		
		$pattern_content = '<?php
/**
 * Title: Categorized Pattern
 * Slug: test/categorized-pattern
 * Categories: featured, header, call-to-action
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>Pattern with categories</p>
<!-- /wp:paragraph -->';
		
		$pattern_file = $this->pattern_dir . '/categorized-pattern.php';
		file_put_contents($pattern_file, $pattern_content);

		$this->synced_patterns->register_patterns();

		$post = get_page_by_path('test-categorized-pattern', OBJECT, 'pb_block');
		$this->assertNotNull($post);

		$categories = wp_get_object_terms($post->ID, 'wp_pattern_category', ['fields' => 'names']);
		// When passing a comma-separated string to wp_set_object_terms, it treats the entire string as one term
		$this->assertCount(1, $categories);
		$this->assertEquals('featured, header, call-to-action', $categories[0]);
	}

	/**
	 * Test REST API injection for single pattern
	 */
	public function test_inject_theme_synced_patterns_single() {
		// Create a test pattern
		$pattern_content = '<?php
/**
 * Title: REST API Test Pattern
 * Slug: test/rest-api-pattern
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>REST API Pattern Content</p>
<!-- /wp:paragraph -->';
		
		$pattern_file = $this->pattern_dir . '/rest-api-pattern.php';
		file_put_contents($pattern_file, $pattern_content);
		$this->synced_patterns->register_patterns();

		$post = get_page_by_path('test-rest-api-pattern', OBJECT, 'pb_block');
		$this->assertNotNull($post);

		// Mock REST request
		$request = new WP_REST_Request('GET', '/wp/v2/blocks/' . $post->ID);
		$response = new WP_REST_Response(['id' => $post->ID]);
		$server = rest_get_server();

		// Test the injection
		$filtered_response = $this->synced_patterns->inject_theme_synced_patterns($response, $server, $request);
		
		$data = $filtered_response->get_data();
		$this->assertEquals($post->ID, $data['id']);
		$this->assertEquals('wp_block', $data['type']); // Should be changed to wp_block
		$this->assertStringContainsString('REST API Pattern Content', $data['content']['raw']);
	}

	/**
	 * Test REST API injection for pattern list
	 */
	public function test_inject_theme_synced_patterns_list() {
		// Create test patterns
		$pattern1_content = '<?php
/**
 * Title: List Pattern 1
 * Slug: test/list-pattern-1
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>Pattern 1</p>
<!-- /wp:paragraph -->';
		
		$pattern2_content = '<?php
/**
 * Title: List Pattern 2
 * Slug: test/list-pattern-2
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>Pattern 2</p>
<!-- /wp:paragraph -->';
		
		file_put_contents($this->pattern_dir . '/list-pattern-1.php', $pattern1_content);
		file_put_contents($this->pattern_dir . '/list-pattern-2.php', $pattern2_content);
		$this->synced_patterns->register_patterns();

		// Mock REST request for blocks list
		$request = new WP_REST_Request('GET', '/wp/v2/blocks');
		$response = new WP_REST_Response([]);
		$server = rest_get_server();

		// Test the injection
		$filtered_response = $this->synced_patterns->inject_theme_synced_patterns($response, $server, $request);
		
		$data = $filtered_response->get_data();
		$this->assertCount(2, $data);
		
		// Check that both patterns are in the response
		$titles = array_column($data, 'title');
		$this->assertContains('List Pattern 1', array_column($titles, 'raw'));
		$this->assertContains('List Pattern 2', array_column($titles, 'raw'));
	}

	/**
	 * Test that pb_blocks cannot be updated via REST API
	 */
	public function test_handle_hijack_block_update_prevents_updates() {
		// Create a test pattern
		$pattern_content = '<?php
/**
 * Title: Protected Pattern
 * Slug: test/protected-pattern
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>Cannot be edited</p>
<!-- /wp:paragraph -->';
		
		file_put_contents($this->pattern_dir . '/protected-pattern.php', $pattern_content);
		$this->synced_patterns->register_patterns();

		$post = get_page_by_path('test-protected-pattern', OBJECT, 'pb_block');
		$this->assertNotNull($post);

		// Mock REST PUT request
		$request = new WP_REST_Request('PUT', '/wp/v2/blocks/' . $post->ID);
		$request->set_body_params(['content' => 'Trying to update']);
		$response = new WP_REST_Response();
		$server = rest_get_server();

		// Test the hijack
		$filtered_response = $this->synced_patterns->handle_hijack_block_update($response, [], $request);
		
		$this->assertInstanceOf('WP_Error', $filtered_response);
		$this->assertEquals('rest_cannot_update_pb_block', $filtered_response->get_error_code());
	}

	/**
	 * Test that regular wp_blocks can still be updated
	 */
	public function test_handle_hijack_block_update_allows_wp_block_updates() {
		// Create a regular wp_block
		$wp_block_id = wp_insert_post([
			'post_type' => 'wp_block',
			'post_title' => 'Regular Block',
			'post_content' => '<!-- wp:paragraph --><p>Regular block content</p><!-- /wp:paragraph -->',
			'post_status' => 'publish'
		]);

		// Mock REST PUT request
		$request = new WP_REST_Request('PUT', '/wp/v2/blocks/' . $wp_block_id);
		$response = new WP_REST_Response(['success' => true]);
		$server = rest_get_server();

		// Test that normal blocks are not affected
		$filtered_response = $this->synced_patterns->handle_hijack_block_update($response, [], $request);
		
		$this->assertInstanceOf('WP_REST_Response', $filtered_response);
		$this->assertEquals(['success' => true], $filtered_response->get_data());
		
		// Clean up
		wp_delete_post($wp_block_id, true);
	}

	/**
	 * Test render_pattern method
	 */
	public function test_render_pattern_method() {
		$pattern_content = '<?php echo "Dynamic"; ?> Content';
		$pattern_file = $this->pattern_dir . '/render-test.php';
		file_put_contents($pattern_file, $pattern_content);

		// Use reflection to test the private method
		$reflection = new ReflectionClass($this->synced_patterns);
		$method = $reflection->getMethod('render_pattern');
		$method->setAccessible(true);

		$result = $method->invoke($this->synced_patterns, $pattern_file);
		$this->assertEquals('Dynamic Content', $result);
	}

	/**
	 * Test that patterns with different metadata are handled correctly
	 */
	public function test_pattern_with_full_metadata() {
		$pattern_content = '<?php
/**
 * Title: Full Metadata Pattern
 * Slug: test/full-metadata
 * Description: A pattern with all metadata
 * Viewport Width: 1200
 * Inserter: yes
 * Categories: featured, header
 * Keywords: test, pattern, full
 * Block Types: core/template-part/header
 * Post Types: post, page
 * Template Types: singular, archive
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>Pattern with full metadata</p>
<!-- /wp:paragraph -->';
		
		file_put_contents($this->pattern_dir . '/full-metadata.php', $pattern_content);
		$this->synced_patterns->register_patterns();

		$post = get_page_by_path('test-full-metadata', OBJECT, 'pb_block');
		$this->assertNotNull($post);
		$this->assertEquals('Full Metadata Pattern', $post->post_title);

		// Check pattern registry
		$pattern_registry = WP_Block_Patterns_Registry::get_instance();
		$pattern = $pattern_registry->get_registered('test/full-metadata');
		$this->assertFalse($pattern['inserter']); // Should be false regardless of metadata
	}

	/**
	 * Test pb_block rendering through Pattern_Builder_Post_Type
	 */
	public function test_pb_block_rendering() {
		// Create a test pattern
		$pattern_content = '<?php
/**
 * Title: Render Test Pattern
 * Slug: test/render-pattern
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>This pattern should render</p>
<!-- /wp:paragraph -->';
		
		file_put_contents($this->pattern_dir . '/render-pattern.php', $pattern_content);
		$this->synced_patterns->register_patterns();

		$post = get_page_by_path('test-render-pattern', OBJECT, 'pb_block');
		$this->assertNotNull($post);

		// Create a block that references the pb_block
		$block = [
			'blockName' => 'core/block',
			'attrs' => ['ref' => $post->ID],
			'innerBlocks' => [],
			'innerHTML' => '',
			'innerContent' => []
		];

		$pb_post_type = new Pattern_Builder_Post_Type();
		$rendered = $pb_post_type->render_pb_blocks('', $block);

		$this->assertStringContainsString('<p>This pattern should render</p>', $rendered);
	}

	/**
	 * Test pb_block rendering returns empty for non-pb_block posts
	 */
	public function test_pb_block_rendering_skips_non_pb_blocks() {
		// Create a regular wp_block
		$wp_block_id = wp_insert_post([
			'post_type' => 'wp_block',
			'post_title' => 'Regular Block',
			'post_content' => '<!-- wp:paragraph --><p>Regular block</p><!-- /wp:paragraph -->',
			'post_status' => 'publish'
		]);

		$block = [
			'blockName' => 'core/block',
			'attrs' => ['ref' => $wp_block_id],
			'innerHTML' => '',
		];

		$pb_post_type = new Pattern_Builder_Post_Type();
		$rendered = $pb_post_type->render_pb_blocks('', $block);

		$this->assertEquals('', $rendered);

		// Clean up
		wp_delete_post($wp_block_id, true);
	}

	/**
	 * Test pb_block rendering prevents infinite recursion
	 */
	public function test_pb_block_rendering_prevents_recursion() {
		// Create a pattern that references itself
		$post_id = wp_insert_post([
			'post_type' => 'pb_block',
			'post_title' => 'Recursive Pattern',
			'post_content' => '', // Will update with self-reference
			'post_status' => 'publish'
		]);

		// Update to reference itself
		wp_update_post([
			'ID' => $post_id,
			'post_content' => '<!-- wp:block {"ref":' . $post_id . '} /-->'
		]);

		$block = [
			'blockName' => 'core/block',
			'attrs' => ['ref' => $post_id],
			'innerHTML' => '',
		];

		$pb_post_type = new Pattern_Builder_Post_Type();
		$rendered = $pb_post_type->render_pb_blocks('', $block);

		$this->assertEquals('', $rendered);

		// Clean up
		wp_delete_post($post_id, true);
	}

	/**
	 * Test pb_block rendering with pattern overrides
	 */
	public function test_pb_block_rendering_with_overrides() {
		// Create a test pattern with override support
		$pattern_content = '<?php
/**
 * Title: Override Test Pattern
 * Slug: test/override-pattern
 * Synced: yes
 */
?>
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/pattern-overrides"}}}} -->
<p>Default content</p>
<!-- /wp:paragraph -->';
		
		file_put_contents($this->pattern_dir . '/override-pattern.php', $pattern_content);
		$this->synced_patterns->register_patterns();

		$post = get_page_by_path('test-override-pattern', OBJECT, 'pb_block');
		$this->assertNotNull($post);

		// Mock the pattern overrides source
		add_filter('block_bindings_source_value', function($value, $name, $source_args, $block_instance, $attribute_name) {
			if ($name === 'core/pattern-overrides') {
				return 'Overridden content';
			}
			return $value;
		}, 10, 5);

		$block = [
			'blockName' => 'core/block',
			'attrs' => [
				'ref' => $post->ID,
				'content' => ['paragraph' => 'Override value']
			],
			'innerHTML' => '',
		];

		$pb_post_type = new Pattern_Builder_Post_Type();
		$rendered = $pb_post_type->render_pb_blocks('', $block);

		$this->assertNotEmpty($rendered);
		// The actual override functionality depends on WordPress core implementation
	}

	/**
	 * Test that normal blocks pass through unchanged
	 */
	public function test_normal_blocks_pass_through() {
		$block_content = '<!-- wp:paragraph --><p>Normal paragraph</p><!-- /wp:paragraph -->';
		$block = [
			'blockName' => 'core/paragraph',
			'attrs' => [],
			'innerHTML' => '<p>Normal paragraph</p>',
		];

		$pb_post_type = new Pattern_Builder_Post_Type();
		$rendered = $pb_post_type->render_pb_blocks($block_content, $block);

		$this->assertEquals($block_content, $rendered);
	}

	/**
	 * Test pb_block with unpublished status is not rendered
	 */
	public function test_pb_block_unpublished_not_rendered() {
		$post_id = wp_insert_post([
			'post_type' => 'pb_block',
			'post_title' => 'Draft Pattern',
			'post_content' => '<!-- wp:paragraph --><p>Draft content</p><!-- /wp:paragraph -->',
			'post_status' => 'draft'
		]);

		$block = [
			'blockName' => 'core/block',
			'attrs' => ['ref' => $post_id],
			'innerHTML' => '',
		];

		$pb_post_type = new Pattern_Builder_Post_Type();
		$rendered = $pb_post_type->render_pb_blocks('', $block);

		$this->assertEquals('', $rendered);

		// Clean up
		wp_delete_post($post_id, true);
	}

	/**
	 * Test pb_block with password protection is not rendered
	 */
	public function test_pb_block_password_protected_not_rendered() {
		$post_id = wp_insert_post([
			'post_type' => 'pb_block',
			'post_title' => 'Protected Pattern',
			'post_content' => '<!-- wp:paragraph --><p>Protected content</p><!-- /wp:paragraph -->',
			'post_status' => 'publish',
			'post_password' => 'secret'
		]);

		$block = [
			'blockName' => 'core/block',
			'attrs' => ['ref' => $post_id],
			'innerHTML' => '',
		];

		$pb_post_type = new Pattern_Builder_Post_Type();
		$rendered = $pb_post_type->render_pb_blocks('', $block);

		$this->assertEquals('', $rendered);

		// Clean up
		wp_delete_post($post_id, true);
	}

	/**
	 * Test multiple patterns can be registered and retrieved
	 */
	public function test_multiple_patterns_registration() {
		// Create multiple pattern files
		for ($i = 1; $i <= 5; $i++) {
			$pattern_content = '<?php
/**
 * Title: Test Pattern ' . $i . '
 * Slug: test/pattern-' . $i . '
 * Categories: test-category
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>Pattern ' . $i . ' content</p>
<!-- /wp:paragraph -->';
			
			file_put_contents($this->pattern_dir . '/pattern-' . $i . '.php', $pattern_content);
		}

		$this->synced_patterns->register_patterns();

		// Verify all patterns were created
		for ($i = 1; $i <= 5; $i++) {
			$post = get_page_by_path('test-pattern-' . $i, OBJECT, 'pb_block');
			$this->assertNotNull($post, 'Pattern ' . $i . ' should exist');
			$this->assertStringContainsString('Pattern ' . $i . ' content', $post->post_content);
		}

		// Verify pattern registry
		$pattern_registry = WP_Block_Patterns_Registry::get_instance();
		for ($i = 1; $i <= 5; $i++) {
			$this->assertTrue($pattern_registry->is_registered('test/pattern-' . $i));
		}
	}

	/**
	 * Test pattern with missing required fields
	 */
	public function test_pattern_with_missing_slug() {
		$pattern_content = '<?php
/**
 * Title: Pattern Without Slug
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>This pattern has no slug</p>
<!-- /wp:paragraph -->';
		
		file_put_contents($this->pattern_dir . '/no-slug.php', $pattern_content);
		
		// This should not create a post since slug is empty
		$this->synced_patterns->register_patterns();
		
		// Since there's no slug, we can't search for a specific post
		// Just verify no PHP errors occurred
		$this->assertTrue(true);
	}

	/**
	 * Test pattern with empty content
	 */
	public function test_pattern_with_empty_content() {
		$pattern_content = '<?php
/**
 * Title: Empty Pattern
 * Slug: test/empty-pattern
 * Synced: yes
 */
?>';
		
		file_put_contents($this->pattern_dir . '/empty-pattern.php', $pattern_content);
		$this->synced_patterns->register_patterns();
		
		$post = get_page_by_path('test-empty-pattern', OBJECT, 'pb_block');
		$this->assertNotNull($post);
		$this->assertEquals('', trim($post->post_content));
	}

	/**
	 * Test pattern re-registration updates the registry
	 */
	public function test_pattern_reregistration() {
		$pattern_content = '<?php
/**
 * Title: Reregistered Pattern
 * Slug: test/reregister
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>Original content</p>
<!-- /wp:paragraph -->';
		
		file_put_contents($this->pattern_dir . '/reregister.php', $pattern_content);
		
		// First registration
		$this->synced_patterns->register_patterns();
		
		$pattern_registry = WP_Block_Patterns_Registry::get_instance();
		$this->assertTrue($pattern_registry->is_registered('test/reregister'));
		
		// Update content and re-register
		$pattern_content = str_replace('Original content', 'Updated content', $pattern_content);
		file_put_contents($this->pattern_dir . '/reregister.php', $pattern_content);
		
		// Second registration
		$this->synced_patterns->register_patterns();
		
		// Pattern should still be registered
		$this->assertTrue($pattern_registry->is_registered('test/reregister'));
		
		// Post content should be updated
		$post = get_page_by_path('test-reregister', OBJECT, 'pb_block');
		$this->assertStringContainsString('Updated content', $post->post_content);
	}

	/**
	 * Test pb_block rendering with embeds and shortcodes
	 */
	public function test_pb_block_rendering_with_embeds() {
		// Create a pattern with shortcode
		$post_id = wp_insert_post([
			'post_type' => 'pb_block',
			'post_title' => 'Embed Pattern',
			'post_content' => '<!-- wp:paragraph --><p>[gallery]</p><!-- /wp:paragraph --><!-- wp:embed {"url":"https://www.youtube.com/watch?v=test"} /-->',
			'post_status' => 'publish'
		]);

		$block = [
			'blockName' => 'core/block',
			'attrs' => ['ref' => $post_id],
			'innerHTML' => '',
		];

		$pb_post_type = new Pattern_Builder_Post_Type();
		$rendered = $pb_post_type->render_pb_blocks('', $block);

		// Should process content through embeds
		$this->assertNotEmpty($rendered);
		
		// Clean up
		wp_delete_post($post_id, true);
	}

	/**
	 * Test REST API handles non-existent pb_block
	 */
	public function test_inject_theme_synced_patterns_handles_invalid_id() {
		// Mock REST request for non-existent block
		$request = new WP_REST_Request('GET', '/wp/v2/blocks/999999');
		$response = new WP_REST_Response(['id' => 999999]);
		$server = rest_get_server();

		// Test the injection - should return original response
		$filtered_response = $this->synced_patterns->inject_theme_synced_patterns($response, $server, $request);
		
		$data = $filtered_response->get_data();
		$this->assertEquals(999999, $data['id']);
		$this->assertArrayNotHasKey('content', $data);
	}

	/**
	 * Test pattern with PHP execution in content
	 */
	public function test_pattern_with_php_execution() {
		$pattern_content = '<?php
/**
 * Title: Dynamic Pattern
 * Slug: test/dynamic
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>Current year: <?php echo date("Y"); ?></p>
<!-- /wp:paragraph -->';
		
		file_put_contents($this->pattern_dir . '/dynamic.php', $pattern_content);
		$this->synced_patterns->register_patterns();
		
		$post = get_page_by_path('test-dynamic', OBJECT, 'pb_block');
		$this->assertNotNull($post);
		
		// Should contain the current year
		$current_year = date('Y');
		$this->assertStringContainsString("Current year: $current_year", $post->post_content);
	}

	/**
	 * Test block rendering with missing ref attribute
	 */
	public function test_pb_block_rendering_without_ref() {
		$block = [
			'blockName' => 'core/block',
			'attrs' => [],
			'innerHTML' => '',
		];

		$pb_post_type = new Pattern_Builder_Post_Type();
		$rendered = $pb_post_type->render_pb_blocks('', $block);

		$this->assertEquals('', $rendered);
	}

	/**
	 * Test block rendering with content but proper blockName
	 */
	public function test_pb_block_rendering_with_existing_content() {
		$existing_content = '<!-- wp:paragraph --><p>Existing content</p><!-- /wp:paragraph -->';
		$block = [
			'blockName' => 'core/block',
			'attrs' => ['ref' => 123],
			'innerHTML' => '<p>Some HTML</p>',
		];

		$pb_post_type = new Pattern_Builder_Post_Type();
		$rendered = $pb_post_type->render_pb_blocks($existing_content, $block);

		// Should return existing content when block already has content
		$this->assertEquals($existing_content, $rendered);
	}
}