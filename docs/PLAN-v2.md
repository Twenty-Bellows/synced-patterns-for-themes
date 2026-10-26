# Synced Patterns for Themes — Version 2 Plan

## The goal

**Block markup should be able to express the content that goes into a pattern.**

A theme ships a pattern that carries *layout and design*:

```html
<!-- patterns/hero.php -->
<!-- wp:cover {"url":"…","dimRatio":40} -->
<div class="wp-block-cover">
  <!-- wp:heading {"metadata":{"name":"headline","bindings":{"content":{"source":"core/pattern-overrides"}}}} -->
  <h2 class="wp-block-heading">Headline goes here</h2>
  <!-- /wp:heading -->
</div>
<!-- /wp:cover -->
```

…and a *page-level* pattern says which words go into it:

```html
<!-- patterns/page-home.php -->
<!-- wp:pattern {"slug":"mytheme/hero","content":{"headline":{"content":"Built for the long haul"}}} /-->
<!-- wp:pattern {"slug":"mytheme/hero","content":{"headline":{"content":"And again, differently"}}} /-->
```

One design, many instances, all expressed in theme files.

## What version 1 did

v1 reached the goal by turning theme patterns into **synced patterns** (reusable
blocks), because `core/block` is the only block core lets you pass a `content`
attribute to.

1. `Synced_Patterns_Loader::register_patterns()` scanned
   `get_stylesheet_directory() . '/patterns/*.php'`, and for every pattern with
   `Synced: yes` in its header it inserted/updated a **`pb_block` custom post**
   holding the pattern's markup.
2. It then re-registered the theme pattern with `inserter => false` and the
   content `<!-- wp:block {"ref":123} /-->`, so `wp:pattern` referenced the post.
3. `Pattern_Builder_Post_Type::render_pb_blocks()` re-implemented core's
   `render_block_core_block()` so `wp:block` could point at a `pb_block` instead
   of a `wp_block`.
4. `Synced_Patterns_Loader::pass_pattern_content_to_referenced_patterns()` hooked
   `pre_render_block`, took the `content` attribute off `wp:pattern`, and
   rebuilt a `wp:block` string carrying it.
5. Two `rest_request_after_callbacks` filters made `pb_block` posts masquerade as
   `wp_block` posts on `/wp/v2/blocks`, and blocked `PUT` on them with a 403.
6. `src/syncedPatternFilter.js` replaced `core/pattern`'s editor component so the
   editor would swap in the referenced `core/block`.

### Why it needs replacing

| | |
|---|---|
| **Database shadow copies** | Every theme pattern became a post that had to be inserted, updated and kept in sync. `wp_update_post()` ran for every synced pattern on *every* `init`, on the front end too. |
| **A parallel post type** | `pb_block` duplicated `wp_block`, needed twelve custom capabilities granted to roles on every request, and duplicated ~60 lines of `render_block_core_block()`. |
| **REST impersonation** | `/wp/v2/blocks` responses were rewritten to include fake `wp_block` entries, and writes to them were rejected with a 403 the editor never explains. |
| **Fought core instead of using it** | Core already has `pattern/overrides` context and the `core/pattern-overrides` binding source. v1 routed around them via a synthetic `wp:block`. |
| **Only the active theme, only `.php`** | The glob missed parent-theme patterns, `.html` patterns, and plugin-registered patterns. |
| **Conflated two features** | "Theme patterns can be synced" is not the same idea as "markup supplies a pattern's content". The first was only ever scaffolding for the second. |
| **Bugs** | `$pattern_post->ID` was read before the `if ( $pattern_post )` null check; the readme documented `Synced: true` while the code tested for `yes`; `is_plugin_active()` was called at plugin load, where it is undefined on the front end. |

## The version 2 idea

> `core/pattern` gets a `content` attribute that fills the pattern's override
> slots — the exact same `content` attribute `core/block` already has.

Nothing is copied to the database. Nothing impersonates anything. The plugin
teaches `core/pattern` one trick that core already taught `core/block`, and then
gets out of the way.

Compare the two block types:

```jsonc
// core/block — core, wp-includes/blocks/block/block.json
"attributes":      { "ref": {…}, "content": { "type": "object" } },
"providesContext": { "pattern/overrides": "content" }

// core/pattern — with this plugin
"attributes":      { "slug": {…}, "content": { "type": "object" } },
"providesContext": { "pattern/overrides": "content" }
```

Once `pattern/overrides` is in context, core's own
`core/pattern-overrides` binding source resolves every bound attribute. The
plugin never substitutes a value at render time; core does.

## Architecture

Three pieces, each with one job.

### 1. Runtime — `Pattern_Block` (front end, every context)

A `register_block_type_args` filter adds the `content` attribute, the
`provides_context` entry, and a replacement `render_callback` for `core/pattern`.
The callback mirrors core's `render_block_core_block()`: it attaches the
referenced pattern's blocks as *inner blocks* of the pattern block and re-renders
it non-dynamically, so `WP_Block` propagates the provided context down the tree.

```php
$block->parsed_block['innerBlocks']  = parse_blocks( $pattern['content'] );
$block->parsed_block['innerContent'] = array_fill( 0, count( … ), null );
$block->refresh_context_dependents();     // provides_context flows from here
return $block->render( array( 'dynamic' => false ) );
```

This covers post content, templates, template parts, and patterns nested inside
other patterns, at any depth. Recursion guard and `autoembed` behaviour match
core's `render_block_core_pattern()`.

### 2. Editor — `Pattern_Resolver` (+ `Block_Markup`)

The editor never runs the render callback, and since 6.6 core *flattens*
`core/pattern` blocks server-side before the editor sees them
(`resolve_pattern_blocks()`, called from the block-patterns and templates REST
controllers) — which drops the `content` attribute.

So for editor-facing content the plugin composes the result itself rather than
leaving it to core: replace each `core/pattern` that carries `content` with the
referenced pattern's blocks, write the override values into those blocks'
markup, and drop the now-satisfied `core/pattern-overrides` bindings so the
result is ordinary, editable content.

Writing a value into block markup follows the attribute's own schema, the same
way `WP_Block::replace_html()` does — `source: rich-text|html` replaces the inner
HTML of the selector's element, `source: attribute` sets an HTML attribute,
sourceless attributes go in the block comment. `Inner_HTML_Processor` extends
`WP_HTML_Processor` to add the inner-HTML replacement the HTML API does not yet
expose (core solves the same gap the same way).

A pattern block with no content of its own is expanded too, but only when the
pattern it points at reaches one that has some — otherwise core would flatten its
way down to that pattern and drop the content on the way. Anything that leads
nowhere near content is left to core's resolver, so every pattern that doesn't
use the feature comes out exactly as it does without the plugin.

### 3. Editor — `Editor_Support`

Three hook sites feed `Pattern_Resolver`:

* `rest_request_after_callbacks` on `/wp/v2/block-patterns/patterns` — recomputes
  each pattern's content from the registry so the inserter, previews and
  insertion all see the composed blocks.
* `get_block_templates` / `get_block_template` / `get_block_file_template` —
  composes template and template-part content during REST requests, before the
  templates controller flattens it. The
  `synced_patterns_for_themes_is_editor_request` filter decides what counts as
  such a request; by default `wp_is_serving_rest_request()` does.
* `rest_request_after_callbacks` on the templates and template parts routes,
  their revisions and their autosaves. The controller flattens each template
  after every filter has run, and builds one stored as a post without running
  `get_block_template` at all, so a template that places a synced pattern (or,
  stored as a post, a pattern with content) is composed again from the content
  core started from: what the filters left, remembered at `PHP_INT_MAX`, or the
  post built again the way core builds it.

All three are guarded by a `strpos()` check for a pattern block, and
`Pattern_Resolver::resolve()` hands back the markup it was given when it changed
nothing, so a site whose patterns don't use the feature pays almost nothing.

### 4. Editor JavaScript

Two small filters, for the case where a `core/pattern` block reaches the canvas
directly (hand-written post content, a template being edited):

* `blocks.registerBlockType` — declares `content` and `providesContext` client
  side, so the attribute survives a parse/serialize round trip.
* `editor.BlockEdit` — when `core/pattern` has `content`, expand it into the
  pattern's blocks with the overrides applied, mirroring the PHP resolver.

### 5. Keeping a pattern linked

Filling a pattern's content is one half of the goal; the other is that a pattern
a user drops into a page stays a pattern. `Synced: yes` in a pattern's header
opts into that, and `Synced_Patterns` reads it.

Core will not carry an unknown header through — `WP_Theme::get_block_patterns()`
reads a fixed list — so the files are read again, 8 KB at a time, cached per
theme, and only ever from the editor: the front end renders a pattern reference
the same way whether or not it was inserted as one, so it never needs to ask.

The inserter hands over a pattern's blocks, so a pattern cannot offer a reference
to itself. A companion entry can: it carries the title and categories, its
content is one pattern block pointing at the real pattern, and the real pattern
steps out of the inserter in its place. That entry is synthesised in the REST
response and never registered — nothing but the inserter asks for it, and the
block it inserts names the real pattern.

The first attempt registered it on `init` instead, behind a
`wp_is_serving_rest_request()` gate. That gate is always false at `init`, because
`REST_REQUEST` is defined from `parse_request`, so the companion was never
registered on the one request that needed it and the editor kept offering the
plain pattern. The tests called the registration helper directly and sailed past
the broken wiring; they now go through a dispatched request instead.

In the editor, `SyncedPatternEdit` renders the instance the way core's
`ReusableBlockEdit` renders a synced pattern: the pattern's blocks are handed to
`useInnerBlocksProps` as a controlled value with handlers that discard changes.

### Standing in for `core/block`

Core keys two behaviours to the host's block name, and neither has a filter:

* **Editing modes.** `getDerivedBlockEditingModesForTree()` collects hosts with
  `block?.name === 'core/block'` and then marks bound descendants `contentOnly`
  and everything else `disabled`. `useLockedDesign()` sets those modes explicitly
  instead; an explicit mode wins, because the derivation skips any block that
  already has one.
* **Where an edit is stored.** `setValues` on the `core/pattern-overrides` source
  looks only for a `core/block` ancestor, and with none updates every block of
  the same name in the document — which would leak between two instances of one
  pattern on a page. The registered source's `setValues` is amended in place.
  Unregistering and re-registering is not an option: the client-side source
  carries no `label`, registration requires one, and a failed re-registration
  leaves the site with no pattern overrides at all. That failure was real, and
  only a browser caught it.

### What a theme author has to know

A slot is only editable inside an instance when the block binds with
`__default`. `RichText` disables a bound field whenever it sits in a
`pattern/overrides` context without one:

```js
const isInsidePatternOverrides = !!blockContext?.[ 'pattern/overrides' ];
const hasOverrideEnabled = blockBindings?.__default?.source === 'core/pattern-overrides';
const shouldDisableForPattern = isInsidePatternOverrides && ! hasOverrideEnabled;
```

Per-attribute bindings still fill from markup; they are just read-only in an
instance.

### Metadata the inserter adds

`parsePattern()` stamps a single-block pattern with
`metadata: { categories, patternName, name }`. For an instance that is wrong
three times over: `patternName` names the companion entry rather than the
pattern, its `--` has to be escaped to `\u002d\u002d` in the saved markup, and
its presence makes the editor offer a Detach of its own that only strips the
metadata again and leaves the instance looking untouched. `SyncedPatternEdit`
removes it and keeps `name`, which is what the list view shows.

The list view otherwise calls every pattern block "Pattern Placeholder". Core
adds a label callback that would use `metadata.name`, but only for block types
that support renaming, and `core/pattern` does not — so the plugin sets
`__experimentalLabel` itself, which core's callback defers to.

### Not there yet

The Patterns screen labels a synced pattern "Not synced". `getPatternSyncStatus()`
returns unsynced for every pattern whose type is not `user`, and the field is a
DataViews definition with no filter, so this cannot be corrected from a plugin.

## What is removed

* The `pb_block` post type, its twelve capabilities, and its 60-line copy of
  `render_block_core_block()`.
* The database writes on `init`.
* Both REST impersonation filters and the 403.
* The `Synced: yes` pattern header, and the `patterns/*.php` glob.

## What theme authors write

```php
<?php
/**
 * Title: Hero
 * Slug: mytheme/hero
 * Inserter: no
 */
?>
<!-- wp:heading {"metadata":{"name":"headline","bindings":{"content":{"source":"core/pattern-overrides"}}}} -->
<h2 class="wp-block-heading">Headline goes here</h2>
<!-- /wp:heading -->
```

```php
<?php
/**
 * Title: Home Page
 * Slug: mytheme/page-home
 */
?>
<!-- wp:pattern {"slug":"mytheme/hero","content":{"headline":{"content":"Built for the long haul"}}} /-->
```

`metadata.name` names the slot; `bindings` marks which attributes the slot fills;
the `content` object on `wp:pattern` supplies the values, keyed by slot name and
then by attribute name. That shape is core's, unchanged — it is exactly what the
editor writes when you override a synced pattern by hand.

## Testing

`tests/` covers all three pieces against the WordPress test suite: rendering
(content reaching slots, nesting, recursion, defaults), resolution (values
written into rich text and HTML attributes, bindings removed, inner content kept
in step with inner blocks, escaping), and the editor's REST and template paths.

## Compatibility

* Requires WordPress 6.8 (`WP_Block::refresh_context_dependents()`) and PHP 7.4.
* `Synced: yes` no longer does anything. Themes that used it keep working as
  ordinary theme patterns; the `pb_block` posts v1 created become inert and can
  be deleted.
* v1 did not load itself when the Pattern Builder plugin was active, because both
  created synced theme patterns. v2 creates nothing, so that check is gone — it
  was also calling `is_plugin_active()` at a point where the function does not
  exist on the front end.
