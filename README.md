# HWS Git Push

Push WordPress plugins to GitHub with automated backups and version management.

## Features

- 📊 **Sync Dashboard** - See all git-enabled plugins, compare local vs GitHub versions
- 🚀 **One-Click Push** - Push changes with a single click
- 📁 **Repository Init** - Set up git tracking for any plugin
- 💾 **Auto Backups** - Creates `.git` backups before operations
- 🔄 **Version Management** - Check updates, download specific versions
- 🔧 **Troubleshooting** - Copy-ready commands for common issues
- 📋 **Persistent Log** - Log survives page refresh

## Requirements

- WordPress 5.0+, PHP 7.4+, Git installed
- [GitHub Personal Access Token](https://github.com/settings/tokens)

## File Structure

```
hws-git-push/
├── hws-git-push.php          # Bootstrap (minimal)
├── includes/
│   ├── class-config.php      # All configuration
│   ├── class-helpers.php     # Utility functions
│   ├── class-github-api.php  # GitHub API
│   ├── class-git-operations.php  # Git commands
│   ├── class-backup.php      # Backup/restore
│   ├── class-ajax-handlers.php   # AJAX endpoints
│   ├── class-admin-ui.php    # Admin interface
│   └── class-core.php        # Main coordinator
├── templates/
│   ├── admin-page.php
│   └── partials/
│       └── troubleshooting-commands.php
└── assets/
    ├── css/admin.css
    └── js/admin.js
```

## Architecture

| Class | Purpose |
|-------|---------|
| `Config` | Static configuration values |
| `Helpers` | Reusable utilities (shell, files, AJAX) |
| `GitHub_API` | All GitHub communication |
| `Git_Operations` | Local git commands |
| `Backup` | Backup create/restore |
| `Ajax_Handlers` | All AJAX endpoints |
| `Admin_UI` | Menu, assets, rendering |
| `Core` | Plugin initialization |

## Configuration

All values in `includes/class-config.php`:

```php
Config::$plugin_name = 'HWS Git Push';
Config::$github_repo = 'developer-jeronimo/hws-git-push';
Config::$default_branch = 'main';
Config::$max_backups = 5;
```

## Public API

```php
$hws = hws_git_push_init();
$hws->plugin_has_git('my-plugin');
$hws->get_plugin_git_status('my-plugin');
$hws->push_plugin('my-plugin', 'Commit message');
```

## Changelog

### 3.3.0
- Added Plugin Sync Dashboard - compare local vs GitHub versions
- Dashboard shows: Needs Push, Behind, Up to date status
- Detect uncommitted changes
- Quick push from dashboard
- Single-plugin refresh

### 3.2.0
- Moved menu to Settings → HWS Git Push
- Persistent log across page refresh
- Version info in all log entries
- Centralized API token settings
- Fixed version history loading

### 3.1.0
- Complete architecture refactor
- Separated into logical class files
- Centralized configuration
- Copy-all per troubleshooting section

## License

GPL v2 or later

## Author

[Michael Peres](https://developer-jeronimo.com)
