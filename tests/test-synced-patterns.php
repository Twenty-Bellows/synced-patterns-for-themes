<?php
/**
 * Tests for the patterns a theme wants kept linked.
 *
 * @package SyncedPatternsForThemes
 */

use TwentyBellows\SyncedPatternsForThemes\Synced_Patterns;

/**
 * Detecting synced patterns and offering them as references.
 *
 * @covers \TwentyBellows\SyncedPatternsForThemes\Synced_Patterns
 */
class Test_Synced_Patterns extends Pattern_Test_Case {

	/**
	 * Directory standing in for the active theme.
	 *
	 * @var string
	 */
	private $theme_dir;

	/**
	 * Sets the test up.
	 */
	public function set_up() {
		parent::set_up();

		$this->theme_dir = get_temp_dir() . 'spft-theme-' . wp_generate_password( 8, false );

		mkdir( $this->theme_dir . '/patterns', 0777, true );

		add_filter( 'stylesheet_directory', array( $this, 'filter_theme_directory' ) );
		add_filter( 'template_directory', array( $this, 'filter_theme_directory' ) );

		Synced_Patterns::flush();
	}

	/**
	 * Tears the test down.
	 */
	public function tear_down() {
		Synced_Patterns::flush();

		remove_filter( 'stylesheet_directory', array( $this, 'filter_theme_directory' ) );
		remove_filter( 'template_directory', array( $this, 'filter_theme_directory' ) );

		$this->remove_directory( $this->theme_dir );

		parent::tear_down();
	}

	/**
	 * Points the theme directory at the test's fixtures.
	 *
	 * @return string The directory.
	 */
	public function filter_theme_directory(): string {
		return $this->theme_dir;
	}

	/**
	 * Removes a directory and everything in it.
	 *
	 * @param string $directory Absolute directory path.
	 * @return void
	 */
	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$entries = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $entries as $entry ) {
			$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
		}

		rmdir( $directory );
	}

	/**
	 * Writes a pattern file into the stand-in theme.
	 *
	 * @param string $name    File name below `patterns/`, without extension.
	 * @param string $slug    Pattern slug.
	 * @param string $headers Extra header lines.
	 * @return void
	 */
	private function write_pattern_file( string $name, string $slug, string $headers = '' ): void {
		$file = $this->theme_dir . '/patterns/' . $name . '.php';

		wp_mkdir_p( dirname( $file ) );
		file_put_contents(
			$file,
			"<?php\n/**\n * Title: {$name}\n * Slug: {$slug}\n{$headers} */\n?>\n"
			. '<!-- wp:paragraph --><p>' . $name . '</p><!-- /wp:paragraph -->'
		);
	}

	/**
	 * A pattern marked `Synced: yes` is found; an unmarked one is not.
	 */
	public function test_synced_header_is_read() {
		$this->write_pattern_file( 'hero', 'test/hero', " * Synced: yes\n" );
		$this->write_pattern_file( 'plain', 'test/plain' );

		$this->assertSame( array( 'test/hero' ), Synced_Patterns::get_slugs() );
		$this->assertTrue( Synced_Patterns::is_synced( 'test/hero' ) );
		$this->assertFalse( Synced_Patterns::is_synced( 'test/plain' ) );
	}

	/**
	 * Core registers a pattern filed in a folder below `patterns/`, as Pattern
	 * Builder files the ones it installs, so it is found there too.
	 */
	public function test_patterns_in_folders_are_read() {
		$this->write_pattern_file( 'hero', 'test/hero', " * Synced: yes\n" );
		$this->write_pattern_file( 'acme/kit/card', 'acme/kit/card', " * Synced: yes\n" );
		$this->write_pattern_file( 'acme/kit/plain', 'acme/kit/plain' );

		$this->assertEqualsCanonicalizing( array( 'test/hero', 'acme/kit/card' ), Synced_Patterns::get_slugs() );
	}

	/**
	 * A file core would not register is not read either: one in a hidden
	 * folder, or one a filter took off core's list.
	 */
	public function test_files_core_skips_are_skipped() {
		$this->write_pattern_file( '.drafts/hidden', 'test/hidden', " * Synced: yes\n" );
		$this->write_pattern_file( 'kit/removed', 'test/removed', " * Synced: yes\n" );
		$this->write_pattern_file( 'kit/kept', 'test/kept', " * Synced: yes\n" );

		$remove = static function ( $files ) {
			unset( $files['kit/removed.php'] );

			return $files;
		};

		add_filter( 'theme_block_pattern_files', $remove );
		$slugs = Synced_Patterns::get_slugs();
		remove_filter( 'theme_block_pattern_files', $remove );

		$this->assertSame( array( 'test/kept' ), $slugs );
	}

	/**
	 * The header is read the way a theme author is likely to write it.
	 *
	 * Version 1 documented `Synced: true` but only ever tested for `yes`.
	 */
	public function test_header_spellings() {
		$this->write_pattern_file( 'a', 'test/a', " * Synced: true\n" );
		$this->write_pattern_file( 'b', 'test/b', " * Synced: YES\n" );
		$this->write_pattern_file( 'c', 'test/c', " * Synced: no\n" );

		$slugs = Synced_Patterns::get_slugs();

		$this->assertContains( 'test/a', $slugs );
		$this->assertContains( 'test/b', $slugs );
		$this->assertNotContains( 'test/c', $slugs );
	}

	/**
	 * A plugin with no file header can opt a pattern in.
	 */
	public function test_patterns_can_be_added_by_filter() {
		$add = static function ( $slugs ) {
			$slugs[] = 'plugin/pattern';

			return $slugs;
		};

		add_filter( 'synced_patterns_for_themes_synced_patterns', $add );
		$is_synced = Synced_Patterns::is_synced( 'plugin/pattern' );
		remove_filter( 'synced_patterns_for_themes_synced_patterns', $add );

		$this->assertTrue( $is_synced );
	}

	/**
	 * The companion entry's slug is derived from the pattern's own.
	 */
	public function test_companion_slug_is_derived_from_the_pattern() {
		$this->assertStringStartsWith( 'test/hero', Synced_Patterns::get_inserter_slug( 'test/hero' ) );
		$this->assertNotSame( 'test/hero', Synced_Patterns::get_inserter_slug( 'test/hero' ) );
	}

	/**
	 * The reference markup names the pattern and carries no content of its own.
	 */
	public function test_reference_markup_names_the_pattern() {
		$markup = Synced_Patterns::get_reference_markup( 'test/hero' );
		$blocks = parse_blocks( $markup );

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'core/pattern', $blocks[0]['blockName'] );
		$this->assertSame( 'test/hero', $blocks[0]['attrs']['slug'] );
	}
}
