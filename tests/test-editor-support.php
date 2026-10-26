<?php
/**
 * Tests for the markup the block editor is served.
 *
 * @package SyncedPatternsForThemes
 */

use TwentyBellows\SyncedPatternsForThemes\Synced_Patterns;

/**
 * The markup the block editor is served.
 *
 * @covers \TwentyBellows\SyncedPatternsForThemes\Editor_Support
 */
class Test_Editor_Support extends Pattern_Test_Case {

	/**
	 * Sets the test up.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Requests the block editor's list of patterns.
	 *
	 * @return array[] The patterns in the response.
	 */
	private function request_patterns(): array {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/block-patterns/patterns' ) );

		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	/**
	 * Finds one pattern in a REST response.
	 *
	 * @param array[] $patterns Patterns from the response.
	 * @param string  $name     Pattern name.
	 * @return array|null The pattern.
	 */
	private function find_pattern( array $patterns, string $name ): ?array {
		foreach ( $patterns as $pattern ) {
			if ( isset( $pattern['name'] ) && $name === $pattern['name'] ) {
				return $pattern;
			}
		}

		return null;
	}

	/**
	 * Saves a template for the active theme the way the Site Editor stores
	 * one: as a post filed under the theme.
	 *
	 * @param string $content   Template content.
	 * @param string $post_type `wp_template` or `wp_template_part`.
	 * @param string $slug      Template slug.
	 * @return string The template's ID, as the REST API names it.
	 */
	private function create_template( string $content, string $post_type = 'wp_template', string $slug = 'archive' ): string {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => $post_type,
				'post_name'    => $slug,
				'post_title'   => $slug,
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);

		wp_set_post_terms( $post_id, get_stylesheet(), 'wp_theme' );

		return get_stylesheet() . '//' . $slug;
	}

	/**
	 * Requests a templates route the way the Site Editor does.
	 *
	 * Templates need `edit_theme_options`, which an editor doesn't have, so
	 * the request is made as an administrator.
	 *
	 * @param string $method      HTTP method.
	 * @param string $route       REST route.
	 * @param array  $params      Request parameters.
	 * @param bool   $from_editor Whether the request counts as the editor's.
	 * @return array The response data.
	 */
	private function request_templates( string $method, string $route, array $params = array(), bool $from_editor = true ): array {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( $method, $route );
		$request->set_param( 'context', 'edit' );

		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		$is_editor_request = $from_editor ? '__return_true' : '__return_false';

		add_filter( 'synced_patterns_for_themes_is_editor_request', $is_editor_request );
		$response = rest_get_server()->dispatch( $request );
		remove_filter( 'synced_patterns_for_themes_is_editor_request', $is_editor_request );

		$this->assertFalse( $response->is_error(), wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Finds every block of one type in a tree of parsed blocks.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @param string  $name   Block name.
	 * @return array[] The blocks, depth first.
	 */
	private function find_blocks( array $blocks, string $name ): array {
		$found = array();

		foreach ( $blocks as $block ) {
			if ( $name === $block['blockName'] ) {
				$found[] = $block;
			}

			$found = array_merge( $found, $this->find_blocks( $block['innerBlocks'], $name ) );
		}

		return $found;
	}

	/**
	 * A synced hero placed in a group, with its slot filled.
	 *
	 * @return string Block markup.
	 */
	private function template_placing_a_synced_hero(): string {
		$hero = $this->register_pattern( 'test/hero', $this->bound_heading() );
		$this->mark_synced( $hero );

		return '<!-- wp:group --><div class="wp-block-group">'
			. $this->pattern_block( $hero, array( 'headline' => array( 'content' => 'From the template' ) ) )
			. '</div><!-- /wp:group -->';
	}

	/**
	 * Asserts that template content still references the synced hero, with
	 * the content the template gave it.
	 *
	 * @param string $markup Template content from a response.
	 * @return void
	 */
	private function assert_keeps_the_hero( string $markup ): void {
		$references = $this->find_blocks( parse_blocks( $markup ), 'core/pattern' );

		$this->assertCount( 1, $references, 'The synced pattern should still be a reference.' );
		$this->assertSame( 'test/hero', $references[0]['attrs']['slug'] );
		$this->assertSame(
			array( 'headline' => array( 'content' => 'From the template' ) ),
			$references[0]['attrs']['content'],
			'The reference should keep the content the template gave it.'
		);
		$this->assertStringNotContainsString( 'Default headline', $markup );
	}

	/**
	 * The patterns the editor lists have their content composed into them.
	 */
	public function test_patterns_endpoint_composes_content() {
		$hero = $this->register_pattern( 'test/hero', $this->bound_heading() );
		$page = $this->register_pattern(
			'test/page',
			$this->pattern_block( $hero, array( 'headline' => array( 'content' => 'From the page pattern' ) ) )
		);

		$pattern = $this->find_pattern( $this->request_patterns(), $page );

		$this->assertNotNull( $pattern, 'The page pattern should be in the response.' );
		$this->assertStringContainsString( 'From the page pattern', $pattern['content'] );
		$this->assertStringNotContainsString( 'Default headline', $pattern['content'] );
		$this->assertStringNotContainsString( 'core/pattern-overrides', $pattern['content'] );
	}

	/**
	 * A reference to a synced pattern survives the list, content and all.
	 *
	 * Core's resolver would inline it — the design unlocked, the link gone —
	 * so the editor could never show a page pattern's synced sections as the
	 * instances they are. The reference stays; the editor renders it.
	 */
	public function test_patterns_endpoint_keeps_synced_references() {
		$hero = $this->register_pattern( 'test/hero', $this->bound_heading() );
		$this->mark_synced( $hero );

		$page = $this->register_pattern(
			'test/page',
			'<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
			. $this->pattern_block( $hero, array( 'headline' => array( 'content' => 'From the page pattern' ) ) )
			. '</div><!-- /wp:group -->'
		);

		$pattern = $this->find_pattern( $this->request_patterns(), $page );

		$this->assertNotNull( $pattern );

		$blocks = parse_blocks( $pattern['content'] );
		$this->assertSame( 'core/group', $blocks[0]['blockName'] );

		$reference = $blocks[0]['innerBlocks'][0];
		$this->assertSame( 'core/pattern', $reference['blockName'], 'The synced pattern should still be a reference.' );
		$this->assertSame( $hero, $reference['attrs']['slug'] );
		$this->assertSame(
			array( 'headline' => array( 'content' => 'From the page pattern' ) ),
			$reference['attrs']['content'],
			'The reference should keep the content the page gave it.'
		);
		$this->assertStringNotContainsString( 'Default headline', $pattern['content'] );
	}

	/**
	 * Plain references beside a synced one are still inlined, as core would.
	 */
	public function test_patterns_endpoint_inlines_plain_references_beside_synced_ones() {
		$hero = $this->register_pattern( 'test/hero', $this->bound_heading() );
		$this->mark_synced( $hero );

		$plain = $this->register_pattern(
			'test/plain',
			'<!-- wp:paragraph --><p>Inlined by the plugin</p><!-- /wp:paragraph -->'
		);
		$page  = $this->register_pattern( 'test/page', $this->pattern_block( $plain ) . $this->pattern_block( $hero ) );

		$pattern = $this->find_pattern( $this->request_patterns(), $page );

		$this->assertNotNull( $pattern );

		$names = array_column( parse_blocks( $pattern['content'] ), 'blockName' );
		$this->assertContains( 'core/paragraph', $names, 'The plain pattern should be inlined.' );
		$this->assertContains( 'core/pattern', $names, 'The synced pattern should stay a reference.' );
		$this->assertSame( 1, substr_count( $pattern['content'], 'wp:pattern ' ) );
	}

	/**
	 * A template keeps its synced references for the editor as well.
	 */
	public function test_template_keeps_synced_references_for_the_editor() {
		$hero = $this->register_pattern( 'test/hero', $this->bound_heading() );
		$this->mark_synced( $hero );

		$template          = new WP_Block_Template();
		$template->content = $this->pattern_block( $hero, array( 'headline' => array( 'content' => 'From the template' ) ) );

		add_filter( 'synced_patterns_for_themes_is_editor_request', '__return_true' );
		$filtered = apply_filters( 'get_block_template', $template, 'test//index', 'wp_template' );
		remove_filter( 'synced_patterns_for_themes_is_editor_request', '__return_true' );

		$this->assertStringContainsString( 'wp:pattern', $filtered->content );
		$this->assertStringContainsString( 'From the template', $filtered->content );
		$this->assertStringNotContainsString( 'Default headline', $filtered->content );
	}

	/**
	 * Patterns that use no content are left exactly as core prepares them.
	 */
	public function test_patterns_endpoint_leaves_other_patterns_alone() {
		$plain = $this->register_pattern(
			'test/plain',
			'<!-- wp:paragraph --><p>Nothing to compose</p><!-- /wp:paragraph -->'
		);

		$pattern = $this->find_pattern( $this->request_patterns(), $plain );

		$this->assertNotNull( $pattern );
		$this->assertStringContainsString( 'Nothing to compose', $pattern['content'] );
	}

	/**
	 * Template content is composed for the editor.
	 */
	public function test_template_content_is_composed_for_the_editor() {
		$hero = $this->register_pattern( 'test/hero', $this->bound_heading() );

		$template          = new WP_Block_Template();
		$template->content = $this->pattern_block( $hero, array( 'headline' => array( 'content' => 'From the template' ) ) );

		add_filter( 'synced_patterns_for_themes_is_editor_request', '__return_true' );
		$filtered = apply_filters( 'get_block_template', $template, 'test//index', 'wp_template' );
		remove_filter( 'synced_patterns_for_themes_is_editor_request', '__return_true' );

		$this->assertStringContainsString( 'From the template', $filtered->content );
		$this->assertStringNotContainsString( 'wp:pattern', $filtered->content );
	}

	/**
	 * Template content is left alone outside the editor, where the pattern
	 * block renders the content itself.
	 */
	public function test_template_content_is_untouched_on_the_front_end() {
		$hero   = $this->register_pattern( 'test/hero', $this->bound_heading() );
		$markup = $this->pattern_block( $hero, array( 'headline' => array( 'content' => 'From the template' ) ) );

		$template          = new WP_Block_Template();
		$template->content = $markup;

		add_filter( 'synced_patterns_for_themes_is_editor_request', '__return_false' );
		$filtered = apply_filters( 'get_block_template', $template, 'test//index', 'wp_template' );
		remove_filter( 'synced_patterns_for_themes_is_editor_request', '__return_false' );

		$this->assertSame( $markup, $filtered->content );
		$this->assertStringContainsString( 'From the template', do_blocks( $filtered->content ) );
	}

	/**
	 * A list of templates is composed the same way a single one is.
	 */
	public function test_template_lists_are_composed() {
		$hero = $this->register_pattern( 'test/hero', $this->bound_heading() );

		$template          = new WP_Block_Template();
		$template->content = $this->pattern_block( $hero, array( 'headline' => array( 'content' => 'In a list' ) ) );

		add_filter( 'synced_patterns_for_themes_is_editor_request', '__return_true' );
		$filtered = apply_filters( 'get_block_templates', array( $template ), array(), 'wp_template' );
		remove_filter( 'synced_patterns_for_themes_is_editor_request', '__return_true' );

		$this->assertStringContainsString( 'In a list', $filtered[0]->content );
	}

	/**
	 * A template the Site Editor loads keeps its synced references.
	 *
	 * The filters above run before the templates controller prepares its
	 * response, and the controller then runs core's `resolve_pattern_blocks()`
	 * over the content, which inlines every reference, synced or not, and drops
	 * the content along the way. So this goes through the route itself.
	 */
	public function test_template_route_keeps_synced_references() {
		$id = $this->create_template( $this->template_placing_a_synced_hero() );

		$template = $this->request_templates( 'GET', '/wp/v2/templates/' . $id );

		$this->assert_keeps_the_hero( $template['content']['raw'] );
	}

	/**
	 * A template part keeps its synced references the same way.
	 */
	public function test_template_part_route_keeps_synced_references() {
		$id = $this->create_template( $this->template_placing_a_synced_hero(), 'wp_template_part', 'header' );

		$part = $this->request_templates( 'GET', '/wp/v2/template-parts/' . $id );

		$this->assert_keeps_the_hero( $part['content']['raw'] );
	}

	/**
	 * A template that isn't stored as a post keeps them as well.
	 *
	 * A theme's file and a plugin's template both pass through core's filters
	 * on the way to the controller, so what the editor is sent is recomposed
	 * from what those filters left. A registered template stands in for both,
	 * since the test theme has no template files.
	 */
	public function test_plugin_template_route_keeps_synced_references() {
		register_block_template( 'synced-patterns-test//plugin-archive', array( 'content' => $this->template_placing_a_synced_hero() ) );

		try {
			$template = $this->request_templates( 'GET', '/wp/v2/templates/' . get_stylesheet() . '//plugin-archive' );
		} finally {
			unregister_block_template( 'synced-patterns-test//plugin-archive' );
		}

		$this->assertSame( 'plugin', $template['source'] );
		$this->assert_keeps_the_hero( $template['content']['raw'] );
	}

	/**
	 * A customized template has its content written in for the editor too.
	 *
	 * Core builds a template stored as a post without running the
	 * `get_block_template` filter, so it never reaches the composing above,
	 * and an unsynced pattern that carries content would come out with the
	 * pattern's placeholder copy.
	 */
	public function test_customized_template_route_writes_content_in() {
		$hero = $this->register_pattern( 'test/hero', $this->bound_heading() );
		$id   = $this->create_template( $this->pattern_block( $hero, array( 'headline' => array( 'content' => 'From the template' ) ) ) );

		$template = $this->request_templates( 'GET', '/wp/v2/templates/' . $id );

		$this->assertStringContainsString( 'From the template', $template['content']['raw'] );
		$this->assertStringNotContainsString( 'Default headline', $template['content']['raw'] );
	}

	/**
	 * The list of templates keeps them too.
	 */
	public function test_template_list_keeps_synced_references() {
		$id = $this->create_template( $this->template_placing_a_synced_hero() );

		$templates = array_column( $this->request_templates( 'GET', '/wp/v2/templates' ), null, 'id' );

		$this->assertArrayHasKey( $id, $templates );
		$this->assert_keeps_the_hero( $templates[ $id ]['content']['raw'] );
	}

	/**
	 * So does the fallback the Site Editor seeds a new template from.
	 */
	public function test_template_lookup_keeps_synced_references() {
		$this->create_template( $this->template_placing_a_synced_hero() );

		$fallback = $this->request_templates( 'GET', '/wp/v2/templates/lookup', array( 'slug' => 'archive' ) );

		$this->assert_keeps_the_hero( $fallback['content']['raw'] );
	}

	/**
	 * Saving a template keeps the references the editor sends back, in what
	 * is stored and in the response the editor carries on from.
	 */
	public function test_saving_a_template_keeps_synced_references() {
		$markup = $this->template_placing_a_synced_hero();
		$id     = $this->create_template( '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->' );

		$template = $this->request_templates( 'POST', '/wp/v2/templates/' . $id, array( 'content' => $markup ) );

		$this->assert_keeps_the_hero( $template['content']['raw'] );
		$this->assert_keeps_the_hero( get_post_field( 'post_content', $template['wp_id'] ) );
	}

	/**
	 * Saving a template that took its synced pattern out answers with what was
	 * saved, not with the content the template had when it was loaded.
	 */
	public function test_saving_a_template_without_synced_patterns_answers_with_what_was_saved() {
		$id = $this->create_template( $this->template_placing_a_synced_hero() );
		$this->request_templates( 'GET', '/wp/v2/templates/' . $id );

		$after    = '<!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->';
		$template = $this->request_templates( 'POST', '/wp/v2/templates/' . $id, array( 'content' => $after ) );

		$this->assertSame( $after, $template['content']['raw'] );
	}

	/**
	 * A template's revisions keep their synced references, so restoring one
	 * doesn't save copies in their place.
	 *
	 * Revisions are built straight from their posts, without the filters the
	 * templates go through, so their content is read from the revision.
	 */
	public function test_template_revisions_keep_synced_references() {
		$markup = $this->template_placing_a_synced_hero();
		$id     = $this->create_template( '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->' );
		$this->request_templates( 'POST', '/wp/v2/templates/' . $id, array( 'content' => $markup ) );

		$revisions = $this->request_templates( 'GET', '/wp/v2/templates/' . $id . '/revisions' );

		$this->assertNotEmpty( $revisions );
		$this->assert_keeps_the_hero( $revisions[0]['content']['raw'] );
	}

	/**
	 * An autosave keeps them as well.
	 */
	public function test_template_autosave_keeps_synced_references() {
		$markup = $this->template_placing_a_synced_hero();
		$id     = $this->create_template( '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->' );

		$autosave = $this->request_templates( 'POST', '/wp/v2/templates/' . $id . '/autosaves', array( 'content' => $markup ) );

		$this->assert_keeps_the_hero( $autosave['content']['raw'] );
	}

	/**
	 * Plain references beside a synced one are still inlined, as core would,
	 * including one that only reaches the synced pattern through another.
	 */
	public function test_template_route_inlines_plain_references_around_synced_ones() {
		$hero  = $this->template_placing_a_synced_hero();
		$plain = $this->register_pattern( 'test/plain', '<!-- wp:paragraph --><p>Inlined</p><!-- /wp:paragraph -->' );
		$frame = $this->register_pattern( 'test/frame', $hero );
		$id    = $this->create_template( $this->pattern_block( $plain ) . $this->pattern_block( $frame ) );

		$template = $this->request_templates( 'GET', '/wp/v2/templates/' . $id );

		$this->assertStringContainsString( 'Inlined', $template['content']['raw'] );
		$this->assert_keeps_the_hero( $template['content']['raw'] );
	}

	/**
	 * A template that places no synced pattern is served exactly as core
	 * prepares it.
	 */
	public function test_template_route_leaves_templates_without_synced_patterns_alone() {
		$plain  = $this->register_pattern( 'test/plain', '<!-- wp:paragraph --><p>Inlined</p><!-- /wp:paragraph -->' );
		$markup = $this->pattern_block( $plain );
		$id     = $this->create_template( $markup );

		$template = $this->request_templates( 'GET', '/wp/v2/templates/' . $id );

		$this->assertSame(
			serialize_blocks( resolve_pattern_blocks( parse_blocks( $markup ) ) ),
			$template['content']['raw']
		);
	}

	/**
	 * Outside the editor the templates routes are left to core.
	 */
	public function test_template_route_is_untouched_outside_the_editor() {
		$id = $this->create_template( $this->template_placing_a_synced_hero() );

		$template = $this->request_templates( 'GET', '/wp/v2/templates/' . $id, array(), false );

		$this->assertEmpty( $this->find_blocks( parse_blocks( $template['content']['raw'] ), 'core/pattern' ) );
	}

	/**
	 * A synced pattern is offered to the inserter as a reference to itself.
	 *
	 * This goes through a real request rather than calling the plugin's own
	 * helpers: the first version of this feature worked when called directly
	 * and did nothing at all over REST, because it was wired to `init`, where
	 * `wp_is_serving_rest_request()` is still false.
	 */
	public function test_synced_pattern_is_offered_as_a_reference() {
		$this->register_pattern( 'test/hero', $this->bound_heading(), array( 'title' => 'Hero' ) );
		$this->mark_synced( 'test/hero' );

		$patterns  = $this->request_patterns();
		$companion = $this->find_pattern( $patterns, Synced_Patterns::get_inserter_slug( 'test/hero' ) );
		$design    = $this->find_pattern( $patterns, 'test/hero' );

		$this->assertNotNull( $companion, 'The inserter should be offered a reference.' );
		$this->assertSame(
			Synced_Patterns::get_reference_markup( 'test/hero' ),
			$companion['content'],
			'Inserting it should link the pattern rather than copy it.'
		);
		$this->assertSame( 'Hero', $companion['title'] );
		$this->assertTrue( $companion['inserter'] );

		// The pattern itself steps aside so the inserter offers it only once.
		$this->assertNotNull( $design );
		$this->assertFalse( $design['inserter'] );

		/*
		 * It still carries its blocks and their bindings, which is what the
		 * editor renders the instance from.
		 */
		$this->assertStringContainsString( 'core/pattern-overrides', $design['content'] );
		$this->assertStringContainsString( 'Default headline', $design['content'] );
	}

	/**
	 * A pattern already kept out of the inserter gets no companion.
	 */
	public function test_pattern_hidden_from_the_inserter_gets_no_companion() {
		$this->register_pattern(
			'test/hero',
			$this->bound_heading(),
			array( 'inserter' => false )
		);
		$this->mark_synced( 'test/hero' );

		$this->assertNull(
			$this->find_pattern(
				$this->request_patterns(),
				Synced_Patterns::get_inserter_slug( 'test/hero' )
			)
		);
	}

	/**
	 * A pattern that is not synced is offered exactly as core offers it.
	 */
	public function test_unsynced_pattern_is_untouched() {
		$this->register_pattern( 'test/plain', $this->bound_heading() );

		$patterns = $this->request_patterns();
		$plain    = $this->find_pattern( $patterns, 'test/plain' );

		$this->assertNotNull( $plain );
		$this->assertNotFalse( $plain['inserter'] ?? true );
		$this->assertNull(
			$this->find_pattern( $patterns, Synced_Patterns::get_inserter_slug( 'test/plain' ) )
		);
	}

	/**
	 * Other REST responses pass through untouched.
	 */
	public function test_other_rest_routes_are_untouched() {
		$post_id  = self::factory()->post->create( array( 'post_title' => 'A post' ) );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'A post', $response->get_data()['title']['rendered'] );
	}
}
