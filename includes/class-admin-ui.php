<?php
/**
 * Admin User Interface
 * 
 * Handles admin menu, assets, and page rendering.
 *
 * @package HWS_Git_Push
 */

namespace HWS_Git_Push;

if (!defined('ABSPATH')) exit;

class Admin_UI {
    
    /** @var string The hook suffix for our admin page */
    private static $hook_suffix = '';
    
    /**
     * Initialize admin UI
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }
    
    /**
     * Register admin menu under Settings
     */
    public static function register_menu() {
        // Register under Settings menu
        self::$hook_suffix = add_options_page(
            Config::$page_title,           // Page title
            Config::$menu_title,           // Menu title
            Config::$required_capability,  // Capability
            Config::$menu_slug,            // Menu slug
            array(__CLASS__, 'render_page') // Callback
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets($hook) {
        // Only load on our settings page
        if ($hook !== self::$hook_suffix && $hook !== 'settings_page_' . Config::$menu_slug) {
            return;
        }
        
        wp_enqueue_style(
            'hws-git-push-admin',
            HWS_GIT_PUSH_URL . 'assets/css/admin.css',
            array(),
            HWS_GIT_PUSH_VERSION
        );
        
        wp_enqueue_script(
            'hws-git-push-admin',
            HWS_GIT_PUSH_URL . 'assets/js/admin.js',
            array('jquery'),
            HWS_GIT_PUSH_VERSION,
            true
        );
        
        // Get stored log from transient if exists (for persistence across refresh)
        $stored_log = get_transient('hws_git_push_log');
        
        wp_localize_script('hws-git-push-admin', 'hwsGitPush', array(
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce(Config::$nonce_action),
            'pluginUrl' => HWS_GIT_PUSH_URL,
            'version'   => HWS_GIT_PUSH_VERSION,
            'storedLog' => $stored_log ? $stored_log : ''
        ));
    }
    
    /**
     * Render the main admin page
     */
    public static function render_page() {
        if (!current_user_can(Config::$required_capability)) {
            wp_die(__('Permission denied.', Config::$text_domain));
        }
        include HWS_GIT_PUSH_DIR . 'templates/admin-page.php';
    }
    
    /*
    |--------------------------------------------------------------------------
    | Data Helpers for Templates
    |--------------------------------------------------------------------------
    */
    
    /**
     * Get registered plugins from database
     * These persist even when .git folder is deleted
     */
    public static function get_registered_plugins() {
        return get_option('hws_registered_plugins', array());
    }
    
    /**
     * Register a plugin for tracking
     */
    public static function register_plugin($slug, $github_repo) {
        $registered = self::get_registered_plugins();
        $registered[$slug] = array(
            'github_repo' => $github_repo,
            'registered_at' => current_time('mysql')
        );
        update_option('hws_registered_plugins', $registered);
        return true;
    }
    
    /**
     * Unregister a plugin
     */
    public static function unregister_plugin($slug) {
        $registered = self::get_registered_plugins();
        if (isset($registered[$slug])) {
            unset($registered[$slug]);
            update_option('hws_registered_plugins', $registered);
        }
        return true;
    }
    
    /**
     * Check if plugin is registered
     */
    public static function is_registered($slug) {
        $registered = self::get_registered_plugins();
        return isset($registered[$slug]);
    }
    
    /**
     * Get GitHub repo for a registered plugin
     */
    public static function get_plugin_github_repo($slug) {
        $registered = self::get_registered_plugins();
        return isset($registered[$slug]['github_repo']) ? $registered[$slug]['github_repo'] : null;
    }
    
    /**
     * Get all installed plugins with git status
     */
    public static function get_plugins_with_status() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $all_plugins = get_plugins();
        $registered = self::get_registered_plugins();
        $plugins = array();
        
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $slug = Helpers::get_plugin_slug($plugin_file);
            $path = Helpers::get_plugin_path($slug);
            $has_git = Helpers::has_git($path);
            $is_registered = isset($registered[$slug]);
            $github_repo = $is_registered ? $registered[$slug]['github_repo'] : null;
            
            $plugins[] = array(
                'file'        => $plugin_file,
                'slug'        => $slug,
                'name'        => $plugin_data['Name'],
                'version'     => $plugin_data['Version'],
                'path'        => $path,
                'has_git'     => $has_git,
                'is_registered' => $is_registered,
                'github_repo' => $github_repo,
                'needs_restore' => $is_registered && !$has_git,
                'status'      => $has_git ? Git_Operations::get_status($path) : null,
                'has_backups' => Backup::has_backups($slug)
            );
        }
        
        // Sort: registered first, then by name
        usort($plugins, function($a, $b) {
            if ($a['is_registered'] && !$b['is_registered']) return -1;
            if (!$a['is_registered'] && $b['is_registered']) return 1;
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $plugins;
    }
    
    /**
     * Get plugins that are registered (whether or not .git exists)
     */
    public static function get_git_plugins() {
        return array_filter(self::get_plugins_with_status(), function($p) {
            return $p['is_registered'];
        });
    }
    
    /**
     * Get GitHub token status
     */
    public static function get_token_status() {
        $token = GitHub_API::get_token();
        return array(
            'configured' => !empty($token),
            'masked'     => !empty($token) ? substr($token, 0, 8) . '...' : ''
        );
    }
    
    /**
     * Get PHP process username
     */
    public static function get_php_user() {
        return Helpers::get_process_user();
    }
    
    /**
     * Get plugins directory path
     */
    public static function get_plugins_dir() {
        return WP_PLUGIN_DIR;
    }
    
    /**
     * Get plugin info for footer
     */
    public static function get_plugin_info() {
        return array(
            'name'        => Config::$plugin_name,
            'version'     => HWS_GIT_PUSH_VERSION,
            'folder'      => Config::$plugin_folder,
            'github_repo' => Config::$github_repo,
            'author'      => Config::get_author_info(),
            'path'        => HWS_GIT_PUSH_DIR
        );
    }
}
