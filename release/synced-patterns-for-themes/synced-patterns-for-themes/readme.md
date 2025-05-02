  This is a utility WordPress Plugin that allows a theme to provide Synced Patterns.

  Usage: put pattern files in a /synced-patterns directory in a theme.
  
  Patterns in this folder will have a SYNCED PATTERN post created and be available to users as SYNCED PATTERNS.
  
  If a user edits this pattern in the editor, the post will be updated and used throughout the site.
  
  If the theme changes the pattern file it will be updated in the database.
  
  These themes will ALSO be registered as UNSYNCED patterns which are just a reference to the SYNCED pattern.
  This allows the patterns to be used in templates by referencing their slug.  
  These unsynced patterns are "hidden" and not shown to the user.
  
  IMPORTANT: Making changes in the THEME version of these patterns will CLOBBER the database version of the pattern.
  