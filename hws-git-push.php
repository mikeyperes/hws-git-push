<?php
/**
 * Plugin Name: HWS Git Push
 * Plugin URI: https://developer.suspended.dev/hws-git-push
 * Description: Push WordPress plugins to GitHub repositories with automated backups and version management.
 * Version: 3.6.0
 * Author: Michael Peres
 * Author URI: https://developer.suspended.dev
 * License: GPL v2 or later
 * Text Domain: hws-git-push
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package HWS_Git_Push
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Plugin Constants
|--------------------------------------------------------------------------
*/
define('HWS_GIT_PUSH_VERSION', '3.6.0');
define('HWS_GIT_PUSH_FILE', __FILE__);
define('HWS_GIT_PUSH_DIR', plugin_dir_path(__FILE__));
define('HWS_GIT_PUSH_URL', plugin_dir_url(__FILE__));
define('HWS_GIT_PUSH_BASENAME', plugin_basename(__FILE__));

/*
|--------------------------------------------------------------------------
| Autoloader
|--------------------------------------------------------------------------
*/
require_once HWS_GIT_PUSH_DIR . 'includes/class-config.php';
require_once HWS_GIT_PUSH_DIR . 'includes/class-helpers.php';
require_once HWS_GIT_PUSH_DIR . 'includes/class-github-api.php';
require_once HWS_GIT_PUSH_DIR . 'includes/class-git-operations.php';
require_once HWS_GIT_PUSH_DIR . 'includes/class-backup.php';
require_once HWS_GIT_PUSH_DIR . 'includes/class-ajax-handlers.php';
require_once HWS_GIT_PUSH_DIR . 'includes/class-admin-ui.php';
require_once HWS_GIT_PUSH_DIR . 'includes/class-core.php';

/*
|--------------------------------------------------------------------------
| Initialize Plugin
|--------------------------------------------------------------------------
*/
function hws_git_push_init() {
    return HWS_Git_Push\Core::get_instance();
}
add_action('plugins_loaded', 'hws_git_push_init');

/*
|--------------------------------------------------------------------------
| Activation Hook
|--------------------------------------------------------------------------
*/
register_activation_hook(__FILE__, function() {
    $backup_dir = WP_CONTENT_DIR . '/hws-git-backups';
    if (!is_dir($backup_dir)) {
        wp_mkdir_p($backup_dir);
    }
    if (get_option('hws_github_api_token') === false) {
        add_option('hws_github_api_token', '');
    }
});
