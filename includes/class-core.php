<?php
/**
 * Plugin Core
 * 
 * Main plugin class that coordinates all components.
 *
 * @package HWS_Git_Push
 */

namespace HWS_Git_Push;

if (!defined('ABSPATH')) exit;

class Core {
    
    /** @var Core|null Singleton instance */
    private static $instance = null;
    
    /**
     * Get the singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize the plugin
     */
    private function init() {
        // Initialize admin UI
        Admin_UI::init();
        
        // Register AJAX handlers
        Ajax_Handlers::register();
        
        // Register update hooks
        add_filter('upgrader_pre_install', array($this, 'pre_update_backup'), 10, 2);
        
        // Add plugin action links
        add_filter('plugin_action_links_' . HWS_GIT_PUSH_BASENAME, array($this, 'add_action_links'));
    }
    
    /**
     * Create backup before plugin update
     */
    public function pre_update_backup($response, $hook_extra) {
        if (!isset($hook_extra['plugin'])) return $response;
        
        $plugin_slug = Helpers::get_plugin_slug($hook_extra['plugin']);
        $plugin_path = Helpers::get_plugin_path($plugin_slug);
        
        if (Helpers::has_git($plugin_path)) {
            Backup::create($plugin_path);
        }
        
        return $response;
    }
    
    /**
     * Add action links to plugins page
     */
    public function add_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=' . Config::$menu_slug) . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /*
    |--------------------------------------------------------------------------
    | Public API
    |--------------------------------------------------------------------------
    */
    
    /**
     * Get the plugin version
     */
    public function get_version() {
        return HWS_GIT_PUSH_VERSION;
    }
    
    /**
     * Check if a plugin has git initialized
     */
    public function plugin_has_git($plugin_slug) {
        return Helpers::has_git(Helpers::get_plugin_path($plugin_slug));
    }
    
    /**
     * Get git status for a plugin
     */
    public function get_plugin_git_status($plugin_slug) {
        $path = Helpers::get_plugin_path($plugin_slug);
        if (!Helpers::has_git($path)) return false;
        return Git_Operations::get_status($path);
    }
    
    /**
     * Push a plugin to GitHub
     */
    public function push_plugin($plugin_slug, $message = '') {
        $path = Helpers::get_plugin_path($plugin_slug);
        if (!Helpers::has_git($path)) {
            return array('success' => false, 'message' => 'Plugin has no git');
        }
        return Git_Operations::quick_push($path, $message);
    }
}
