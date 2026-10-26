<?php
/**
 * Composes patterns for everything the editor loads.
 *
 * @package SyncedPatternsForThemes
 */

namespace TwentyBellows\SyncedPatternsForThemes;

use WP_Block_Patterns_Registry;
use WP_Block_Template;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Template_Autosaves_Controller;
use WP_REST_Template_Revisions_Controller;
use WP_REST_Templates_Controller;

/**
 * Runs `Pattern_Resolver` over the markup the block editor loads.
 *
 * Since WordPress 6.6 core flattens `core/pattern` blocks server side so the
 * editor doesn't have to (`resolve_pattern_blocks()`), and flattening throws
 * away everything but the pattern's slug — the content, and whether the
 * reference was to a synced pattern at all. The pattern list is recomposed
 * from the registry after core has flattened it. Templates are composed as
 * they're loaded, and the templates controller then flattens them on the way
 * out as well, so a template that places a synced pattern is recomposed once
 * more after that.
 *
 * Both are cheap on sites that don't use the feature: the markup is only parsed
 * when a substring check says it might be worth it.
 */
class Editor_Support {

	/**
	 * REST route the block editor loads patterns from.
	 */
	const PATTERNS_ROUTE = '/wp/v2/block-patterns/patterns';

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Content of the templates this request has loaded for the editor that
	 * place a pattern, by template ID, as the last filter left it.
	 *
	 * @var array<string, string>
	 */
	private $template_content = array();

	/**
	 * Constructor.
	 *
	 * @param string $file Absolute path to the main plugin file.
	 */
	public function __construct( string $file ) {
		$this->file = $file;
	}

	/**
	 * Registers the editor hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rest_request_after_callbacks', array( $this, 'resolve_patterns_response' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'forget_templates' ) );
		add_filter( 'rest_request_after_callbacks', array( $this, 'resolve_templates_response' ), 10, 3 );
		add_filter( 'get_block_templates', array( $this, 'resolve_templates' ) );
		add_filter( 'get_block_template', array( $this, 'resolve_template' ) );
		add_filter( 'get_block_file_template', array( $this, 'resolve_template' ) );
		add_filter( 'get_block_templates', array( $this, 'remember_templates' ), PHP_INT_MAX );
		add_filter( 'get_block_template', array( $this, 'remember_template' ), PHP_INT_MAX );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Composes the patterns the editor lists, previews and inserts.
	 *
	 * Core has already flattened the response by the time this runs, so the
	 * content is recomposed from the pattern registry rather than patched:
	 * content written in, plain references inlined as core inlines them, and
	 * references to synced patterns kept as written — with their content —
	 * for the editor to render as instances. Core's own resolver is never
	 * run over the result, since it would flatten those too.
	 *
	 * A synced pattern also gains a companion entry here. The inserter hands
	 * over a pattern's blocks, so a pattern cannot offer a reference to itself;
	 * the companion carries the same title and categories with a single
	 * reference block as its content, and the pattern itself steps out of the
	 * inserter in its place. The companion exists only in this response — it is
	 * never registered, because nothing but the inserter ever asks for it, and
	 * the block it inserts names the real pattern.
	 *
	 * @param WP_REST_Response|mixed $response Result to send to the client.
	 * @param array|mixed            $handler  Route handler used for the request.
	 * @param WP_REST_Request|mixed  $request  Request used to generate the response.
	 * @return WP_REST_Response|mixed The response.
	 */
	public function resolve_patterns_response( $response, $handler, $request ) {
		if ( ! $response instanceof WP_REST_Response
			|| ! $request instanceof WP_REST_Request
			|| self::PATTERNS_ROUTE !== $request->get_route()
		) {
			return $response;
		}

		$patterns = $response->get_data();

		if ( ! is_array( $patterns ) ) {
			return $response;
		}

		$registry   = WP_Block_Patterns_Registry::get_instance();
		$companions = array();
		$changed    = false;

		foreach ( $patterns as $index => $pattern ) {
			if ( ! isset( $pattern['name'], $pattern['content'] ) || ! $registry->is_registered( $pattern['name'] ) ) {
				continue;
			}

			$registered = $registry->get_registered( $pattern['name'] );
			$markup     = $registered['content'] ?? '';

			if ( Pattern_Resolver::contains_pattern_block( $markup ) ) {
				$patterns[ $index ]['content'] = Pattern_Resolver::compose( $markup );

				$changed = true;
			}

			$companion = $this->build_companion_pattern( $patterns[ $index ] );

			if ( null !== $companion ) {
				$patterns[ $index ]['inserter'] = false;
				$companions[]                   = $companion;
				$changed                        = true;
			}
		}

		if ( $changed ) {
			$response->set_data( array_merge( $patterns, $companions ) );
		}

		return $response;
	}

	/**
	 * Builds the entry that offers a synced pattern to the inserter.
	 *
	 * @param array $pattern A prepared pattern from the REST response.
	 * @return array|null The companion entry, or null if the pattern needs none.
	 */
	private function build_companion_pattern( array $pattern ): ?array {
		if ( ! Synced_Patterns::is_synced( $pattern['name'] ) ) {
			return null;
		}

		// A pattern already kept out of the inserter is only used from markup.
		if ( isset( $pattern['inserter'] ) && ! $pattern['inserter'] ) {
			return null;
		}

		$companion             = $pattern;
		$companion['name']     = Synced_Patterns::get_inserter_slug( $pattern['name'] );
		$companion['content']  = Synced_Patterns::get_reference_markup( $pattern['name'] );
		$companion['inserter'] = true;

		return $companion;
	}

	/**
	 * Composes the patterns used by a list of templates.
	 *
	 * @param WP_Block_Template[]|mixed $templates Templates being returned.
	 * @return WP_Block_Template[]|mixed The templates.
	 */
	public function resolve_templates( $templates ) {
		if ( ! is_array( $templates ) ) {
			return $templates;
		}

		foreach ( $templates as $index => $template ) {
			$templates[ $index ] = $this->resolve_template( $template );
		}

		return $templates;
	}

	/**
	 * Composes the patterns used by a single template or template part.
	 *
	 * Only for the editor: on the front end `Pattern_Block` renders the
	 * template's pattern blocks with their content already in context.
	 *
	 * @param WP_Block_Template|mixed $template Template being returned.
	 * @return WP_Block_Template|mixed The template.
	 */
	public function resolve_template( $template ) {
		if ( ! $template instanceof WP_Block_Template || ! self::is_editor_request() ) {
			return $template;
		}

		$template->content = Pattern_Resolver::resolve( $template->content );

		return $template;
	}

	/**
	 * Remembers a list of templates for the response the editor is sent.
	 *
	 * @param WP_Block_Template[]|mixed $templates Templates being returned.
	 * @return WP_Block_Template[]|mixed The templates, unchanged.
	 */
	public function remember_templates( $templates ) {
		if ( is_array( $templates ) ) {
			array_map( array( $this, 'remember_template' ), $templates );
		}

		return $templates;
	}

	/**
	 * Remembers a template's content for the response the editor is sent.
	 *
	 * This runs after every other filter, so what it keeps is what the editor
	 * would be sent if the templates controller didn't flatten it. Only the
	 * first load in a request counts: a plugin's template is one shared object,
	 * and the controller writes the flattened content into it while preparing
	 * the response, so loading it again hands back what core flattened.
	 *
	 * @param WP_Block_Template|mixed $template Template being returned.
	 * @return WP_Block_Template|mixed The template, unchanged.
	 */
	public function remember_template( $template ) {
		if ( $template instanceof WP_Block_Template
			&& is_string( $template->id )
			&& ! isset( $this->template_content[ $template->id ] )
			&& Pattern_Resolver::contains_pattern_block( $template->content )
			&& self::is_editor_request()
		) {
			$this->template_content[ $template->id ] = $template->content;
		}

		return $template;
	}

	/**
	 * Forgets the templates an earlier request remembered.
	 *
	 * This runs as each REST request's callback is about to start, so what is
	 * remembered is only ever what the request being answered has loaded.
	 *
	 * @param WP_REST_Response|mixed $response Result to send to the client, if a filter has already decided it.
	 * @return WP_REST_Response|mixed The same value, unchanged.
	 */
	public function forget_templates( $response ) {
		$this->template_content = array();

		return $response;
	}

	/**
	 * Recomposes the templates the editor is sent after core has flattened them.
	 *
	 * The templates controller runs core's `resolve_pattern_blocks()` while it
	 * prepares each template, after every filter above, and that inlines every
	 * `core/pattern` block, synced or not. An instance comes out as a copy of
	 * the pattern's own blocks: the link is gone, the content the template gave
	 * it is replaced by the pattern's placeholder copy, and its slots are
	 * renamed after the pattern. Saved from the Site Editor, that copy is what
	 * the template keeps. Core offers no filter after that point, so a template
	 * that needs it is recomposed here from the content core started from, the
	 * way the pattern list is: content written in, plain references inlined as
	 * core inlines them, and synced ones kept as written for the editor to
	 * render as instances.
	 *
	 * This covers the templates and template parts routes, their revisions and
	 * their autosaves. Every other template is left exactly as core prepared it.
	 *
	 * @param WP_REST_Response|mixed $response Result to send to the client.
	 * @param array|mixed            $handler  Route handler used for the request.
	 * @param WP_REST_Request|mixed  $request  Request used to generate the response.
	 * @return WP_REST_Response|mixed The response.
	 */
	public function resolve_templates_response( $response, $handler, $request ) {
		$controller = self::get_controller( $handler );

		if ( ! $response instanceof WP_REST_Response
			|| ! $request instanceof WP_REST_Request
			|| ! ( $controller instanceof WP_REST_Templates_Controller
				|| $controller instanceof WP_REST_Template_Revisions_Controller
				|| $controller instanceof WP_REST_Template_Autosaves_Controller )
			|| ! self::is_editor_request()
		) {
			return $response;
		}

		$data = $response->get_data();

		// A lookup that finds nothing answers with an empty object.
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$is_list   = wp_is_numeric_array( $data );
		$templates = $is_list ? $data : array( $data );
		$changed   = false;

		foreach ( $templates as $index => $template ) {
			$content = $this->recompose_template( $template );

			if ( null !== $content ) {
				$templates[ $index ]['content']['raw'] = $content;

				$changed = true;
			}
		}

		if ( $changed ) {
			$response->set_data( $is_list ? $templates : $templates[0] );
		}

		return $response;
	}

	/**
	 * Recomposes one template from a response, if it needs it.
	 *
	 * A template stored as a post (a Site Editor customization, a revision or
	 * an autosave) is built again from that post the way core builds it,
	 * because core builds some of them without running any filter, so neither
	 * the filters above nor anything remembered have seen it. One that comes
	 * from a theme file or a plugin always passes through the filters, so its
	 * content is the one remembered.
	 *
	 * It needs recomposing if it places a synced pattern, at any depth, or a
	 * pattern that carries content, since core would replace that content with
	 * the pattern's placeholder copy.
	 *
	 * @param array|mixed $template A prepared template from the response.
	 * @return string|null The content for the editor, or null to leave the template as core prepared it.
	 */
	private function recompose_template( $template ): ?string {
		if ( ! is_array( $template ) || ! isset( $template['content']['raw'] ) ) {
			return null;
		}

		$content = empty( $template['wp_id'] )
			? ( $this->template_content[ $template['id'] ?? '' ] ?? '' )
			: self::get_post_template_content( $template['wp_id'] );

		if ( ! Pattern_Resolver::contains_pattern_block( $content ) ) {
			return null;
		}

		$composed = Pattern_Resolver::compose( $content );

		if ( Pattern_Resolver::resolve( $content ) === $content && ! self::has_synced_reference( parse_blocks( $composed ) ) ) {
			return null;
		}

		return $composed;
	}

	/**
	 * Builds a template's content from its post the way the controller does
	 * before flattening it, hooked blocks and all.
	 *
	 * @param int|mixed $post_id The template's, revision's or autosave's post ID.
	 * @return string The content, or an empty string if there's no such post.
	 */
	private static function get_post_template_content( $post_id ): string {
		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return '';
		}

		$template = _build_block_template_result_from_post( $post );

		return $template instanceof WP_Block_Template ? (string) $template->content : '';
	}

	/**
	 * Finds the controller a REST route is handled by.
	 *
	 * @param array|mixed $handler Route handler used for the request.
	 * @return object|null The controller, or null if a controller doesn't handle the route.
	 */
	private static function get_controller( $handler ): ?object {
		$callback = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;

		return is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ? $callback[0] : null;
	}

	/**
	 * Determines whether parsed blocks reference a synced pattern anywhere.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return bool Whether one of them, at any depth, is a synced reference.
	 */
	private static function has_synced_reference( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( 'core/pattern' === ( $block['blockName'] ?? null )
				&& Synced_Patterns::is_synced( $block['attrs']['slug'] ?? null )
			) {
				return true;
			}

			if ( ! empty( $block['innerBlocks'] ) && self::has_synced_reference( $block['innerBlocks'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determines whether the current request is loading content for the editor.
	 *
	 * @return bool Whether template content should be composed for this request.
	 */
	public static function is_editor_request(): bool {
		/**
		 * Filters whether template content is composed for the current request.
		 *
		 * Templates only need composing for the block editor, which loads them
		 * over the REST API. The front end renders their pattern blocks
		 * directly, with the content already in block context.
		 *
		 * @since 2.0.0
		 *
		 * @param bool $is_editor_request Whether this request loads content for the editor.
		 */
		return (bool) apply_filters(
			'synced_patterns_for_themes_is_editor_request',
			wp_is_serving_rest_request()
		);
	}

	/**
	 * Enqueues the editor script.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$asset_file = plugin_dir_path( $this->file ) . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'synced-patterns-for-themes',
			plugins_url( 'build/index.js', $this->file ),
			$asset['dependencies'],
			$asset['version'],
			array( 'in_footer' => true )
		);

		/*
		 * The editor script has translatable strings of its own, and without
		 * this WordPress never hands it the translations it downloads.
		 */
		wp_set_script_translations(
			'synced-patterns-for-themes',
			'synced-patterns-for-themes'
		);

		wp_add_inline_script(
			'synced-patterns-for-themes',
			sprintf(
				'window.syncedPatternsForThemes = %s;',
				wp_json_encode( array( 'syncedPatterns' => Synced_Patterns::get_slugs() ) )
			),
			'before'
		);
	}
}
