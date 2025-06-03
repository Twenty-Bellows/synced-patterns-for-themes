# Synced Patterns for Themes

A WordPress plugin that empowers themes to provide synced patterns.

## Overview

This plugin enables theme developers to ship patterns that behave as synced patterns (reusable blocks) while maintaining the benefits of theme-bundled patterns. When a theme pattern is marked as synced, it automatically becomes available as a reusable block that updates across all instances when modified.

## Key Features

- **Theme-Provided Synced Patterns**: Convert any theme pattern into a synced pattern by adding a simple metadata flag
- **Automatic Synchronization**: Updates to synced patterns propagate across all instances site-wide
- **Block Bindings Support**: Full compatibility with WordPress block bindings
- **Template Integration**: Use synced patterns in templates and template parts
- **Seamless User Experience**: Synced patterns appear naturally in the pattern inserter

## Installation

1. Download the plugin from the [WordPress Plugin Directory](https://wordpress.org/plugins/synced-patterns-for-themes/) or [GitHub](https://github.com/twenty-bellows/synced-patterns-for-themes)
2. Upload to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

## Usage

### For Theme Developers

To make a theme pattern synced, add `Synced: true` to the pattern file's metadata header:

```php
<?php
/**
 * Title: My Synced Pattern
 * Slug: mytheme/my-pattern
 * Categories: featured
 * Synced: true
 */
?>
<!-- Your pattern blocks here -->
```

### Using Synced Patterns in Templates

Reference synced patterns in templates or other patterns using the pattern block:

```html
<!-- wp:pattern {"slug":"mytheme/my-pattern"} /-->
```


## Requirements

- WordPress 6.6 or higher
- PHP 7.2 or higher

## Development

### Setup

```bash
# Clone the repository
git clone https://github.com/twenty-bellows/synced-patterns-for-themes.git

# Install dependencies
npm install

# Start development environment (requires Docker)
npm run start
```

The development server runs at http://localhost:8978

### Available Commands

- `npm run start` - Start the development environment
- `npm run stop` - Stop the development environment
- `npm run test` - Run unit tests (requires running environment)
- `npm run build` - Build production assets

### Testing

Unit tests require a running development environment:

```bash
npm run start
npm run test
```

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request to the [GitHub repository](https://github.com/twenty-bellows/synced-patterns-for-themes).

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by [Twenty Bellows](https://twentybellows.com)