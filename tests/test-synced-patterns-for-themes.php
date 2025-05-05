<?php

require_once __DIR__ . '/../includes/class-synced-patterns-loader.php';

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
		mkdir($this->test_dir);
		mkdir($this->pattern_dir);

		// Set the directory where pattern files will be stored for the test
		add_filter('stylesheet_directory', function() {
			return $this->test_dir;
		});
	}

	/**
	 * Clean up after each test
	 */
	public function tearDown(): void {
		// Remove all test files and directory
		$this->remove_test_directory($this->test_dir);
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
	 * Test that a new pattern is registered and saved as a wp_block post
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

		// Create a pattern file in the test directory
		file_put_contents($pattern_file, $pattern_content);

		// Run the method under test
		$this->synced_patterns->register_patterns();

		// Check that the post was created
		$post = get_page_by_path('test-a-synced-theme-pattern', OBJECT, 'wp_block');

		$this->assertNotNull($post, 'The post should have been created');

		$this->assertEquals('publish', $post->post_status);
		$this->assertEquals('test-a-synced-theme-pattern', $post->post_name);
		$this->assertStringContainsString('This is a synced Theme Pattern', $post->post_content);

		// check that the posts's modified date is the same as the file's modified date
		$this->assertEquals(filemtime($pattern_file), strtotime($post->post_modified));
	}

	/**
	 * Test that an existing pattern post is not updated when the pattern file changes
	 */
	public function test_register_patterns_does_not_update_existing_post() {

		$pattern_file = $this->pattern_dir . '/test-pattern.php';

		// Create a pattern file in the test directory
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

		// Register the patterns
		$this->synced_patterns->register_patterns();

		// Create a newer pattern file in the test directory
		$pattern_content = '<?php
/**
 * Title: Test Pattern
 * Slug: test-pattern 
 * Categories: Featured
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>Updated Content</p>
<!-- /wp:paragraph -->';

		file_put_contents($pattern_file, $pattern_content);
		touch($pattern_file, time() + 3000);
		clearstatcache();

		// Run the method under test
		$this->synced_patterns->register_patterns();

		// Fetch the updated post and verify its content
		$updated_post = get_page_by_path('test-pattern', OBJECT, 'wp_block');

		$this->assertStringContainsString('Original Content', $updated_post->post_content);
	}

	/**
	 * Test that an existing pattern post can be updated by the user 
	 */
	public function test_register_patterns_does_not_replace_modified_post() {
		
		$pattern_file = $this->pattern_dir . '/test-pattern.php';

		// Create a pattern file in the test directory
		$pattern_content = '<?php
/**
 * Title: Test Pattern
 * Slug: test-pattern 
 * Categories: Featured
 * Synced: yes
 */
?>
<!-- wp:paragraph -->
<p>Old Content</p>
<!-- /wp:paragraph -->';

		file_put_contents($pattern_file, $pattern_content);

		// Register the patterns
		$this->synced_patterns->register_patterns();

		// get the post of the pattern
		$post = get_page_by_path('test-pattern', OBJECT, 'wp_block');

		//update the post content
		wp_update_post(array(
			'ID' => $post->ID,
			'post_content' => '<!-- wp:paragraph --><p>User updated content</p><!-- /wp:paragraph -->',
		));

		// re-register the patterns
		$this->synced_patterns->register_patterns();

		// ensure that the post content was not updated
		$updated_post = get_page_by_path('test-pattern', OBJECT, 'wp_block');

		$this->assertStringContainsString('User updated content', $updated_post->post_content);

	}

}