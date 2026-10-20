<?php
/**
 * Finds the patterns a theme wants kept linked.
 *
 * @package SyncedPatternsForThemes
 */

namespace TwentyBellows\SyncedPatternsForThemes;

/**
 * Reads the `Synced` header from a theme's pattern files.
 *
 * A pattern marked `Synced: yes` is inserted as a reference to itself rather
 * than as a copy of its blocks, so editing the theme file keeps changing every
 * place it was used.
 *
 * `WP_Theme::get_block_patterns()` reads a fixed list of headers and `Synced`
 * is not one of them, so the files are read again here. Only the first 8 KB of
 * each is read, no PHP in them runs, the answer is cached per theme version,
 * and nothing asks for it outside the editor: the front end renders a pattern
 * reference the same way whether or not it was inserted as one.
 */
class Synced_Patterns {

	/**
	 * Suffix for the companion entry that puts a reference in the inserter.
	 */
	const INSERTER_SUFFIX = '--synced-instance';

	/**
	 * Prefix for the cache of synced pattern slugs.
	 */
	const CACHE_KEY_PREFIX = 'synced_patterns_for_themes_';

	/**
	 * Slugs of the synced patterns, once looked up.
	 *
	 * @var string[]|null
	 */
	private static $slugs = null;

	/**
	 * Lists the slugs of every pattern the active theme wants kept linked.
	 *
	 * @return string[] Pattern slugs.
	 */
	public static function get_slugs(): array {
		if ( null !== self::$slugs ) {
			return self::$slugs;
		}

		$cache_key     = self::CACHE_KEY_PREFIX . get_stylesheet();
		$can_use_cache = ! wp_is_development_mode( 'theme' );
		$slugs         = $can_use_cache ? get_transient( $cache_key ) : false;

		if ( ! is_array( $slugs ) ) {
			$slugs = self::scan_theme_patterns();

			if ( $can_use_cache ) {
				set_transient( $cache_key, $slugs, WEEK_IN_SECONDS );
			}
		}

		/**
		 * Filters the patterns that are inserted as a reference to themselves.
		 *
		 * Patterns registered by a plugin have no file header to read, so this
		 * is how they opt in.
		 *
		 * @since 2.0.0
		 *
		 * @param string[] $slugs Pattern slugs, including namespace.
		 */
		$slugs = (array) apply_filters( 'synced_patterns_for_themes_synced_patterns', $slugs );

		self::$slugs = array_values( array_unique( array_filter( $slugs, 'is_string' ) ) );

		return self::$slugs;
	}

	/**
	 * Determines whether a pattern is one the theme wants kept linked.
	 *
	 * @param mixed $slug Pattern slug, including namespace.
	 * @return bool Whether the pattern is synced.
	 */
	public static function is_synced( $slug ): bool {
		return is_string( $slug ) && in_array( $slug, self::get_slugs(), true );
	}

	/**
	 * Returns the slug of the companion pattern that inserts a reference.
	 *
	 * @param string $slug Pattern slug, including namespace.
	 * @return string The companion pattern's slug.
	 */
	public static function get_inserter_slug( string $slug ): string {
		return $slug . self::INSERTER_SUFFIX;
	}

	/**
	 * Block markup that references a pattern rather than copying it.
	 *
	 * @param string $slug Pattern slug, including namespace.
	 * @return string Block markup.
	 */
	public static function get_reference_markup( string $slug ): string {
		// Core's serializer, so the markup matches what a round trip produces.
		return '<!-- wp:pattern ' . serialize_block_attributes( array( 'slug' => $slug ) ) . ' /-->';
	}

	/**
	 * Forgets the cached lookup.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$slugs = null;

		delete_transient( self::CACHE_KEY_PREFIX . get_stylesheet() );
	}

	/**
	 * Reads the `Synced` header from the active theme's pattern files.
	 *
	 * @return string[] Pattern slugs.
	 */
	private static function scan_theme_patterns(): array {
		$slugs = array();

		foreach ( self::get_pattern_directories() as $directory ) {
			foreach ( self::get_pattern_files( $directory ) as $file ) {
				$headers = get_file_data(
					$file,
					array(
						'slug'   => 'Slug',
						'synced' => 'Synced',
					)
				);

				if ( ! empty( $headers['slug'] ) && self::header_means_yes( $headers['synced'] ) ) {
					$slugs[] = $headers['slug'];
				}
			}
		}

		return $slugs;
	}

	/**
	 * Lists the pattern files in a directory, the ones core registers.
	 *
	 * Core reads `patterns/` to any depth, so a theme can file its patterns in
	 * folders, and a pattern that is registered but not found here is inserted
	 * as a copy however its header reads.
	 *
	 * @param string $directory Absolute path of a `patterns` directory.
	 * @return string[] Absolute file paths.
	 */
	private static function get_pattern_files( string $directory ): array {
		/** This filter is documented in wp-includes/class-wp-theme.php */
		$files = apply_filters( 'theme_block_pattern_files', self::find_php_files( $directory ), $directory ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.

		return array_values( array_filter( (array) $files, 'is_string' ) );
	}

	/**
	 * Finds the PHP files under a directory, skipping what `WP_Theme::scandir()` skips.
	 *
	 * @param string $directory     Absolute directory path.
	 * @param string $relative_path The directory's path below the one the search began in.
	 * @return string[] Absolute file paths, keyed by their path below that one.
	 */
	private static function find_php_files( string $directory, string $relative_path = '' ): array {
		$entries = scandir( $directory );
		$files   = array();

		if ( ! is_array( $entries ) ) {
			return $files;
		}

		/** This filter is documented in wp-includes/class-wp-theme.php */
		$exclusions = (array) apply_filters( 'theme_scandir_exclusions', array( 'CVS', 'node_modules', 'vendor', 'bower_components' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter.

		foreach ( $entries as $entry ) {
			if ( '.' === $entry[0] || in_array( $entry, $exclusions, true ) ) {
				continue;
			}

			$path = $directory . '/' . $entry;

			if ( is_dir( $path ) ) {
				$files = array_merge( $files, self::find_php_files( $path, $relative_path . $entry . '/' ) );
			} elseif ( '.php' === substr( $entry, -4 ) ) {
				$files[ $relative_path . $entry ] = $path;
			}
		}

		return $files;
	}

	/**
	 * Lists the pattern directories of the active theme and its parent.
	 *
	 * @return string[] Absolute directory paths.
	 */
	private static function get_pattern_directories(): array {
		$directories = array( get_stylesheet_directory() . '/patterns' );

		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$directories[] = get_template_directory() . '/patterns';
		}

		return array_filter( $directories, 'is_dir' );
	}

	/**
	 * Reads a header value as a yes or a no.
	 *
	 * Accepts what a theme author is likely to write. Version 1 of this plugin
	 * documented `Synced: true` but only ever tested for `yes`.
	 *
	 * @param string $value Raw header value.
	 * @return bool Whether the header says yes.
	 */
	private static function header_means_yes( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( 'yes', 'true', '1', 'on' ), true );
	}
}
