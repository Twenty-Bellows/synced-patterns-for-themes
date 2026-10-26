# Synced Patterns for Themes

Let block markup supply the content for a pattern, so one pattern can carry the
design and another can carry the words.

```html
<!-- wp:pattern {"slug":"mytheme/hero","content":{"headline":{"content":"Built for the long haul"}}} /-->
```

## Why

A theme pattern is usually two things at once: a design, and the words that
happen to be sitting in it. Reuse the design and you copy the words with it — so
a theme that wants five hero sections ships five near-identical patterns, and
changing the design means changing all five.

This plugin separates them. A pattern marks the parts of itself that are meant to
be filled in; any other pattern, template or post can then use that design and
say what goes in it.

The `content` attribute is not new. It is the same attribute WordPress already
puts on a synced pattern when you override one of its fields — core just never
offered it to theme patterns. This plugin does.

## How

### 1. Mark the parts to fill in

In the pattern that holds the design, name each block that should be filled and
mark it as a slot. This is core's Pattern Overrides syntax, unchanged:

```php
<?php
/**
 * Title: Hero
 * Slug: mytheme/hero
 * Inserter: no
 */
?>
<!-- wp:cover {"url":"<?php echo esc_url( get_theme_file_uri( 'assets/hero.jpg' ) ); ?>","dimRatio":40} -->
<div class="wp-block-cover">
	<!-- wp:heading {"metadata":{"name":"headline","bindings":{"__default":{"source":"core/pattern-overrides"}}}} -->
	<h2 class="wp-block-heading">Headline goes here</h2>
	<!-- /wp:heading -->

	<!-- wp:paragraph {"metadata":{"name":"lede","bindings":{"__default":{"source":"core/pattern-overrides"}}}} -->
	<p>A sentence about what this section is for.</p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:cover -->
```

* `metadata.name` names the slot.
* `__default` opens up every attribute the block supports, and is the form to
  reach for — it is the only one WordPress will let someone type into inside a
  synced pattern.
* Naming attributes one by one — `{"content":{"source":"core/pattern-overrides"}}` —
  also works when the content comes from markup.
* `Inserter: no` keeps a pattern out of the inserter — worth doing for one that
  only ever gets filled in from markup, but not for one marked `Synced: yes`,
  which is meant to be inserted.

### 2. Fill them in

```php
<?php
/**
 * Title: Home Page
 * Slug: mytheme/page-home
 * Template Types: front-page
 */
?>
<!-- wp:pattern {"slug":"mytheme/hero","content":{
	"headline": { "content": "Built for the long haul" },
	"lede":     { "content": "Tools that outlast the fashion that made them." }
}} /-->

<!-- wp:pattern {"slug":"mytheme/hero","content":{
	"headline": { "content": "And again, differently" }
}} /-->
```

`content` is keyed by slot name, then by attribute name. Any slot you leave out
keeps the design pattern's own content, which makes a pattern's defaults double
as its documentation.

### 3. Keep it linked, if you want to

By default a pattern is a starting point: insert it and you get ordinary blocks
you are free to change. Add `Synced: yes` to its header and it behaves like a
synced pattern instead.

```php
<?php
/**
 * Title: Notice
 * Slug: mytheme/notice
 * Synced: yes
 */
?>
<!-- wp:paragraph {"metadata":{"name":"message","bindings":{"content":{"source":"core/pattern-overrides"}}}} -->
<p>Edit this file and every notice on the site changes with it.</p>
<!-- /wp:paragraph -->
```

Inserting it stores a reference, not a copy. The design comes from the theme file
and cannot be edited on the page; only the slots can. Change the file and every
use changes, each keeping the content it was given. The block toolbar offers
**Reset**, which restores the pattern's own content, and **Detach**, which breaks
the link and leaves ordinary editable blocks with the instance's content in them.

Nothing is copied into the database to make this work — unlike version 1, the
theme file stays the only source of truth.

**Slots in a synced pattern must bind with `__default`**, as above. WordPress
only lets you type into a bound field inside a pattern instance when the block
binds that way:

```js
// editable inside an instance
"bindings": { "__default": { "source": "core/pattern-overrides" } }

// fills from markup, but read-only inside an instance
"bindings": { "content": { "source": "core/pattern-overrides" } }
```

That rule is WordPress's, not this plugin's — `RichText` disables a bound field
whenever it is inside a `pattern/overrides` context without a `__default`
binding.

One thing WordPress gets wrong about these: the Patterns screen labels them
"Not synced". It decides that by pattern type — anything that is not a user
pattern is reported as unsynced — and offers no filter to change it. The lock
beside the label is correct; the wording is not.

Patterns registered by a plugin have no header to read, so they opt in through a
filter:

```php
add_filter( 'synced_patterns_for_themes_synced_patterns', function ( $slugs ) {
	$slugs[] = 'myplugin/notice';
	return $slugs;
} );
```

### Where it works

Patterns, templates, template parts, post content, and patterns inside other
patterns, to any depth. In the editor, inserting a pattern that uses this gives
you ordinary editable blocks with the content already in them.

### What can be filled

Whatever WordPress supports for Pattern Overrides — Paragraph and Heading
(`content`), Image (`url`, `alt`, `title`, `id`), Button (`text`, `url`,
`linkTarget`, `rel`), and List Item (`content`, in WordPress 7.1 and later).

## How it works

Three pieces, described at length in [`docs/PLAN-v2.md`](docs/PLAN-v2.md).

**On the front end**, `core/pattern` is given a `content` attribute and told to
provide it as `pattern/overrides` context — exactly what `core/block` already
declares in its `block.json`:

```jsonc
"attributes":      { "ref": {…}, "content": { "type": "object" } },
"providesContext": { "pattern/overrides": "content" }
```

The pattern's blocks are then rendered as inner blocks of the pattern block, so
that context reaches them, and core's own `core/pattern-overrides` binding source
resolves the values. The plugin never substitutes one itself.

**In the editor**, blocks are what matter rather than rendered HTML, and since
6.6 core flattens `core/pattern` blocks server-side before the editor sees them,
dropping the content on the way. So patterns and templates are composed for it:
each pattern block with content becomes the blocks it stands for, the values are
written into their markup, and the bindings that asked for them are removed. A
reference to a synced pattern is kept as written, content and all, for the
editor to render as an instance. Templates need that twice, because the
templates controller flattens them again after every filter has run, so a
template that places a synced pattern is composed once more on the way out.

**In the browser**, a filter declares the `content` attribute so it survives a
parse/serialize round trip, and a second one decides what a pattern block on the
canvas becomes: a synced pattern renders as a live instance, anything else
expands into ordinary blocks.

**A synced instance** is rendered the way core renders a synced pattern, in
`src/synced-pattern-edit.js`: the pattern's blocks go to `useInnerBlocksProps` as
a controlled value whose handlers discard changes, which is what locks the
design. The inserter is offered a companion entry whose content is a single
reference block, because a pattern cannot otherwise offer a reference to itself;
that entry is synthesised in the REST response rather than registered.

Two core behaviours are keyed to `core/block` by name, and a `core/pattern` host
has to stand in for each:

* **Editing modes.** Core derives "design locked, slots editable" in a reducer
  that collects hosts with `block?.name === 'core/block'`. There is no filter, so
  `useLockedDesign()` sets those modes explicitly with `setBlockEditingMode()` —
  an explicit mode wins, because the derivation skips blocks that already have
  one.
* **Where an edit is stored.** The `core/pattern-overrides` source reads from
  block context generically, but `setValues` looks only for a `core/block`
  ancestor and otherwise updates every block of the same name in the document —
  which would leak between two instances of one pattern on a page.
  `src/pattern-overrides-source.js` amends the registered source's `setValues` in
  place. It deliberately does *not* unregister and re-register: the client-side
  source carries no `label`, registration requires one, and a failed
  re-registration would leave the site with no pattern overrides at all.

Those two are the first places to look if a WordPress update breaks slot editing.

Nothing is written to the database, and no post type, capability or REST route is
added.

## Requirements

* WordPress 6.8 or later
* PHP 7.4 or later

Published on the [WordPress Plugin Directory](https://wordpress.org/plugins/synced-patterns-for-themes/);
`readme.txt` is the copy that directory reads.

## Upgrading from 1.x

Version 1 reached the same goal by copying theme patterns into the database as
synced patterns (`pb_block` posts) and making the REST API present them as
reusable blocks. None of that is left.

* `Synced: true` in a pattern header no longer copies the pattern into the
  database. It now keeps the pattern linked to its file instead — the same idea,
  without the shadow copy.
* The `pb_block` posts version 1 created are no longer used, and are safe to
  delete.
* Patterns now come from wherever WordPress registers them — parent themes, child
  themes, `.php` and `.html` files, plugins — rather than a glob over the active
  theme's `patterns/*.php`.

## Development

```bash
npm install          # editor script dependencies
composer install     # test and linting dependencies

npm run build        # build build/index.js
npm run lint:js      # lint the editor script
npm run test:unit    # run the editor script's unit tests
composer run lint    # lint the PHP

npm run start        # start WordPress at http://localhost:8978 (needs Docker)
npm run test         # run the PHP tests against that environment
npm run plugin-zip   # build the distributable zip (see .distignore)
```

`dev-assets/themes/synced-patterns-test` is a small block theme that uses the
feature; `npm run start` mounts it, so it can be activated from Appearance →
Themes. Its Hero and Card patterns are filled in from markup; its Notice pattern
is synced.

## License

GPL-2.0-or-later. Developed by [Twenty Bellows](https://twentybellows.com).
