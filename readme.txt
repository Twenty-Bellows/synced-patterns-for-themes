=== Synced Patterns for Themes ===
Contributors:      twentybellows, pbking
Tags:              patterns, block patterns, synced patterns, block bindings, themes
Requires at least: 6.8
Tested up to:      7.1
Requires PHP:      7.4
Stable tag:        2.0.1
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Let block markup supply the content for a pattern, so one pattern can carry the design and another can carry the words.

== Description ==

A theme pattern is usually two things at once: a design, and the words that happen
to be sitting in it. Reuse the design and you copy the words with it — so a theme
that wants five hero sections ships five near-identical patterns.

This plugin separates the two. A pattern marks the parts of itself that are meant
to be filled in, and any other pattern — or template, or post — can then use that
design and say what goes in it:

`<!-- wp:pattern {"slug":"mytheme/hero","content":{"headline":{"content":"Built for the long haul"}}} /-->`

One design pattern. As many uses as you like, each with its own content, all
expressed in theme files.

The `content` attribute here is not new: it is the same attribute WordPress
already puts on a synced pattern when you edit one of its fields. All this plugin
does is let a theme pattern accept it too.

= Marking the parts to fill in =

In the design pattern, give each block you want filled a name and mark it as a
slot. This is core's Pattern Overrides syntax, unchanged:

`
<!-- wp:heading {"metadata":{"name":"headline","bindings":{"__default":{"source":"core/pattern-overrides"}}}} -->
<h2 class="wp-block-heading">Headline goes here</h2>
<!-- /wp:heading -->
`

`metadata.name` names the slot. `__default` opens up every attribute the block
supports, and is the form to reach for: it is the only one WordPress will let
someone type into inside a synced pattern.

Naming attributes one by one instead — `{"content":{"source":"core/pattern-overrides"}}` —
also works when the content comes from markup, and is worth using when you want
to fill exactly one attribute and leave the rest alone.

= Filling them in =

Anywhere a pattern can be used, `content` supplies the values — keyed by slot
name, then by attribute name:

`
<!-- wp:pattern {"slug":"mytheme/hero","content":{
    "headline": { "content": "Built for the long haul" },
    "photo":    { "url": "…/roof.jpg", "alt": "A tin roof" }
}} /-->
`

Any slot you leave out keeps the design pattern's own content, so a pattern's
defaults double as its documentation.

= Keeping a pattern linked =

By default a pattern is a starting point: insert it and you get ordinary blocks
you are free to change. Add `Synced: yes` to a pattern's header and it behaves
like a synced pattern instead:

`
<?php
/**
 * Title: Notice
 * Slug: mytheme/notice
 * Synced: yes
 */
?>
`

Inserting it stores a reference rather than a copy. The design is rendered from
the theme file and cannot be edited on the page — only the content slots can.
Edit the file and every notice on the site changes with it, keeping whatever
content each one was given.

The block toolbar offers **Reset**, which puts the pattern's own content back,
and **Detach**, which breaks the link and leaves ordinary editable blocks with
the instance's content baked in.

Unlike version 1, nothing is copied into the database to make this work: the
theme file stays the only source of truth.

Slots in a synced pattern have to bind with `__default`. WordPress renders any
other binding read-only inside an instance — that is its rule, not this
plugin's.

= Where it works =

Patterns, templates, template parts, post content, and patterns inside other
patterns, to any depth.

Inserting an ordinary pattern that fills another gives you plain editable blocks
with the right content already in them. Inserting one marked `Synced: yes` gives
you a live instance instead, still linked to its file.

= What can be filled =

The blocks and attributes WordPress supports for Pattern Overrides:

* Paragraph and Heading — `content`
* Image — `url`, `alt`, `title`, `id`
* Button — `text`, `url`, `linkTarget`, `rel`
* List Item — `content` (WordPress 7.1 and later)

The list is core's, not this plugin's, so it grows as core's does.

== Installation ==

1. Install and activate the plugin.
2. Add `metadata.name` and a `core/pattern-overrides` binding to each block in a
   theme pattern you want to fill in.
3. Use that pattern from another pattern, template or post, passing a `content`
   attribute on the pattern block.
4. Optionally add `Synced: yes` to the design pattern's header, so inserting it
   keeps it linked to the file rather than copying it.

No settings, no admin screen. Nothing is written to the database.

== Frequently Asked Questions ==

= Do I need to mark my patterns as synced? =

Only if you want them to stay linked when someone inserts them. Filling a
pattern's content from block markup needs no header at all.

`Synced: yes` means what it says now — the pattern is inserted as a reference to
the theme file, and edits to that file reach every use. Version 1 used the same
header to copy the pattern into the database as a `wp_block`; version 2 keeps
the file as the only source of truth.

= Can a plugin's patterns be synced? =

Yes. A plugin's patterns have no file header to read, so they opt in through a
filter:

`add_filter( 'synced_patterns_for_themes_synced_patterns', function ( $slugs ) {
	$slugs[] = 'myplugin/notice';
	return $slugs;
} );`

= What happens to the posts version 1 created? =

They stop being used. They are `pb_block` posts, invisible in the admin, and safe
to delete.

= Is this the same as Pattern Overrides on a synced pattern? =

It is the same mechanism, aimed somewhere else. Pattern Overrides let a *person*
fill in a synced pattern from the editor, and the values live in the database.
This lets a *theme* fill in a pattern from block markup, and the values live in
the theme.

= Should the design pattern be hidden from the inserter? =

It depends which half you are using. A pattern that only ever gets filled in from
markup has little to offer on its own, so `Inserter: no` keeps it out of the way.
A pattern marked `Synced: yes` is meant to be inserted, so leave it visible — the
plugin makes sure it is offered exactly once, as a live instance.

= Why does the Patterns screen say "Not synced"? =

Because WordPress decides that label by pattern type, not by behaviour: every
pattern that is not a user pattern is reported as unsynced, with no filter to
change it. The lock beside it is right — the pattern lives in a file and cannot
be edited from the admin — but the wording is WordPress's, and this plugin
cannot correct it.

= Does it work in the site editor? =

Yes. Patterns, templates and template parts are all composed before the editor
loads them, so what you see is what the front end renders. A synced pattern
placed in a template stays a live instance there, just as it does in a post.

= What if I use a slot name that the design pattern doesn't have? =

Nothing happens. Unknown slots are ignored, and slots with no value keep the
design pattern's own content.

== Changelog ==

= 2.0.1 =
* Does not load when Pattern Builder 2.0+ is active.
* The cached synced-pattern lookup is flushed when the theme is switched.

= 2.0.0 =
* A pattern block can carry a `content` attribute, so one pattern supplies the
  words for another pattern's design. Works in patterns, templates, template
  parts and post content, and through patterns nested in patterns.
* `Synced: yes` in a pattern header keeps a pattern linked when someone inserts
  it: the page stores a reference, the design renders from the theme file and
  cannot be edited there, and editing the file reaches every instance.
* Nothing is copied into the database any more. The `pb_block` post type version
  1 created, the twelve capabilities it granted, the writes it made on every
  request, and the REST filters that made those posts look like reusable blocks
  are all gone.
* Patterns come from wherever WordPress registers them — parent themes, child
  themes, `.php` and `.html` files, and plugins — instead of a glob over the
  active theme's `patterns/*.php`.
* Added the `synced_patterns_for_themes_synced_patterns` filter, so patterns
  registered by a plugin can be synced too.
* Slots in a synced pattern must bind with `__default`; WordPress renders any
  other binding read-only inside an instance.
* Requires WordPress 6.8 and PHP 7.4.

= 1.2.0 =
* Added block bindings support for pattern content.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.0.0 =
A rewrite. Theme patterns are no longer copied into the database: `Synced: yes`
now keeps a pattern linked to its file instead. The `pb_block` posts version 1
created are unused and safe to delete. Requires WordPress 6.8.
