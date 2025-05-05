  # Synced Patterns for Themes 

  This is a utility WordPress Plugin that allows a theme to provide Synced Patterns.

  ## Usage

  Add `Synced: true` to the metadata of a theme pattern file.

  ## Details
  
  When the editor loads, patterns with the `Synced: true` are copied to the database as `wp-block` (just as if a user had created it themselves). This pattern can be edited by the user.
  
  Additionly, the pattern will ALSO be available as an unsynced pattern, allowing it to be used in templates and other patterns. This unsynced version of the pattern is a `<wp:block />` block that references the synced pattern in the database.  It is hidden from the inserter so that a user is only presented the synced version of the pattern in the editor.

  ## Limitations

  If a user edits this pattern in the editor the theme file is not changed.

  If a theme synced pattern file is changed the change is not propagated once it has been loaded into the database.

  Custom (PHP) logic included in a synced pattern will ONLY be executed the FIRST time the pattern is copied to the database, not every time that pattern is used.
  
  ## Development

  Node/NPM is used to install dependencies used for development.  Docker is used in combination with wp-env to run a local development environment.

  `npm install` to install all dependencies

  `npm run start` to start local development server (acces at http://localhost:8978). `npm run stop` to stop it.

  Once the server has been started unit tests can be ran with `npm run test`.

  See the `package.json` scripts for additional utilities.