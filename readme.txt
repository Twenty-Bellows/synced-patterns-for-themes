=== Synced Patterns for Themes ===
Contributors:      twentybellows, pbking
Tags:              block
Tested up to:      6.8
Stable tag:        1.2.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

This is a utility WordPress Plugin that empowers themes to provide Synced Patterns.

== Description ==

When the editor loads, patterns with the `Synced: true` are copied to the database as `wp-block` (just as if a user had created it themselves). This pattern can be edited by the user.
  
Additionly, the pattern will ALSO be available as an unsynced pattern, allowing it to be used in templates and other patterns. This unsynced version of the pattern is a `<wp:block />` block that references the synced pattern in the database.  It is hidden from the inserter so that a user is only presented the synced version of the pattern in the editor.

== Usage ==

Add `Synced: true` to the metadata of a theme pattern file.

== Development ==

The plugin source is available here: https://github.com/twenty-bellows/synced-patterns-for-themes

Node & NPM are needed to install and run the development tools:

```
npm install
npm run build
```

See the source for more details.

== Frequently Asked Questions ==

== Screenshots ==

== Changelog ==

= 1.0.0 =
* Initial release
