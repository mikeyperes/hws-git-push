<?php
/**
 * Plugin Configuration
 * 
 * Central location for all plugin configuration values.
 * No hardcoded values should exist elsewhere.
 *
 * @package HWS_Git_Push
 */

namespace HWS_Git_Push;

if (!defined('ABSPATH')) exit;

class Config {
    
    /*
    |--------------------------------------------------------------------------
    | Plugin Identity
    |--------------------------------------------------------------------------
    */
    public static $plugin_name    = 'HWS Git Push';
    public static $plugin_folder  = 'hws-git-push';
    public static $plugin_file    = 'hws-git-push.php';
    public static $text_domain    = 'hws-git-push';
    
    /*
    |--------------------------------------------------------------------------
    | GitHub Configuration
    |--------------------------------------------------------------------------
    */
    public static $github_repo    = 'mikeyperes/hws-git-push';
    public static $github_branch  = 'main';
    public static $github_api_url = 'https://api.github.com';
    public static $github_raw_url = 'https://raw.githubusercontent.com';
    public static $api_timeout    = 30;
    
    /*
    |--------------------------------------------------------------------------
    | Git Defaults
    |--------------------------------------------------------------------------
    */
    public static $default_commit_message = 'Update from WordPress';
    public static $default_branch         = 'main';
    public static $default_git_email      = 'wordpress@localhost';
    public static $default_git_name       = 'WordPress';
    
    /*
    |--------------------------------------------------------------------------
    | Backup Configuration
    |--------------------------------------------------------------------------
    */
    public static $backup_dir_name = 'hws-git-backups';
    public static $max_backups     = 5;
    public static $backup_prefix   = 'git-backup-';
    
    /*
    |--------------------------------------------------------------------------
    | Admin UI Configuration
    |--------------------------------------------------------------------------
    */
    public static $menu_slug           = 'hws-git-push';
    public static $required_capability = 'manage_options';
    public static $page_title          = 'HWS Git Push';
    public static $menu_title          = 'HWS Git Push';
    public static $menu_icon           = 'dashicons-cloud-upload';
    public static $menu_position       = 80;
    
    /*
    |--------------------------------------------------------------------------
    | Option Names (Database Keys)
    |--------------------------------------------------------------------------
    */
    public static $option_github_token = 'hws_github_api_token';
    public static $nonce_action        = 'hws_git_push_nonce';
    
    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    
    /**
     * Get the full path to the backup directory
     */
    public static function get_backup_dir() {
        return WP_CONTENT_DIR . '/' . self::$backup_dir_name;
    }
    
    /**
     * Get the plugin basename (folder/file.php)
     */
    public static function get_plugin_basename() {
        return self::$plugin_folder . '/' . self::$plugin_file;
    }
    
    /**
     * Get GitHub download URL for a ref (branch or tag)
     */
    public static function get_github_download_url($ref = null) {
        $ref = $ref ?: self::$github_branch;
        if (strpos($ref, '.') !== false || preg_match('/^v?\d/', $ref)) {
            return 'https://github.com/' . self::$github_repo . '/archive/refs/tags/' . $ref . '.zip';
        }
        return 'https://github.com/' . self::$github_repo . '/archive/refs/heads/' . $ref . '.zip';
    }
    
    /**
     * Get GitHub raw file URL
     */
    public static function get_github_raw_url($file_path, $ref = null) {
        $ref = $ref ?: self::$github_branch;
        return self::$github_raw_url . '/' . self::$github_repo . '/' . $ref . '/' . $file_path;
    }
    
    /**
     * Get author information
     */
    public static function get_author_info() {
        return array(
            'name' => 'Michael Peres',
            'url'  => 'https://developer.suspended.dev'
        );
    }
}
