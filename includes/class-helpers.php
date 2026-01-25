<?php
/**
 * Helper Utilities
 * 
 * Reusable utility functions used throughout the plugin.
 *
 * @package HWS_Git_Push
 */

namespace HWS_Git_Push;

if (!defined('ABSPATH')) exit;

class Helpers {
    
    /*
    |--------------------------------------------------------------------------
    | Shell Command Execution
    |--------------------------------------------------------------------------
    */
    
    /**
     * Execute a shell command safely
     *
     * @param string $command Command to execute
     * @param string|null $cwd Working directory
     * @return array {success, output, code}
     */
    public static function run_command($command, $cwd = null) {
        $env_prefix = 'export HOME=' . escapeshellarg(self::get_home_dir()) . ' && ';
        $full_command = $env_prefix . $command . ' 2>&1';
        
        if ($cwd && is_dir($cwd)) {
            $full_command = 'cd ' . escapeshellarg($cwd) . ' && ' . $full_command;
        }
        
        $output = array();
        $return_code = 0;
        exec($full_command, $output, $return_code);
        
        return array(
            'success' => ($return_code === 0),
            'output'  => implode("\n", $output),
            'code'    => $return_code
        );
    }
    
    /**
     * Get home directory for shell commands
     */
    public static function get_home_dir() {
        $home = getenv('HOME');
        if ($home && is_dir($home)) return $home;
        
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $user_info = posix_getpwuid(posix_getuid());
            if (isset($user_info['dir']) && is_dir($user_info['dir'])) {
                return $user_info['dir'];
            }
        }
        
        return sys_get_temp_dir();
    }
    
    /**
     * Get the username PHP is running as
     */
    public static function get_process_user() {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (isset($user['name'])) return $user['name'];
        }
        
        $result = self::run_command('whoami');
        if ($result['success']) return trim($result['output']);
        
        return getenv('USER') ?: getenv('USERNAME') ?: 'unknown';
    }
    
    /*
    |--------------------------------------------------------------------------
    | File System Operations
    |--------------------------------------------------------------------------
    */
    
    /**
     * Delete a directory recursively
     */
    public static function delete_directory($dir) {
        if (!is_dir($dir)) return true;
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::delete_directory($path) : @unlink($path);
        }
        
        return @rmdir($dir);
    }
    
    /**
     * Add a folder to a ZipArchive recursively
     */
    public static function add_folder_to_zip($zip, $folder, $base_name) {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folder, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($files as $file) {
            $file_path = $file->getRealPath();
            $relative_path = $base_name . '/' . substr($file_path, strlen($folder) + 1);
            
            // Skip .git directories
            if (strpos($relative_path, '.git') !== false) continue;
            
            if ($file->isDir()) {
                $zip->addEmptyDir($relative_path);
            } else {
                $zip->addFile($file_path, $relative_path);
            }
        }
    }
    
    /**
     * Create a zip file from a folder
     */
    public static function create_zip($source_dir, $destination, $folder_name) {
        if (!is_dir($source_dir)) return false;
        
        $zip = new \ZipArchive();
        if ($zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        
        self::add_folder_to_zip($zip, $source_dir, $folder_name);
        $zip->close();
        
        return file_exists($destination) ? $destination : false;
    }
    
    /*
    |--------------------------------------------------------------------------
    | Plugin Utilities
    |--------------------------------------------------------------------------
    */
    
    /**
     * Get plugin slug from plugin file path
     */
    public static function get_plugin_slug($plugin_file) {
        $slug = dirname($plugin_file);
        return ($slug === '.') ? basename($plugin_file, '.php') : $slug;
    }
    
    /**
     * Get full path to a plugin directory
     */
    public static function get_plugin_path($slug) {
        return WP_PLUGIN_DIR . '/' . $slug;
    }
    
    /**
     * Check if a plugin has git initialized
     */
    public static function has_git($plugin_path) {
        return is_dir($plugin_path . '/.git');
    }
    
    /*
    |--------------------------------------------------------------------------
    | AJAX Utilities
    |--------------------------------------------------------------------------
    */
    
    /**
     * Verify AJAX request and check permissions
     */
    public static function verify_ajax_request($capability = null) {
        $capability = $capability ?: Config::$required_capability;
        
        if (!check_ajax_referer(Config::$nonce_action, 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }
        
        if (!current_user_can($capability)) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }
        
        return true;
    }
    
    /**
     * Send standardized JSON success response
     */
    public static function ajax_success($message, $data = array()) {
        wp_send_json_success(array_merge(array('message' => $message), $data));
    }
    
    /**
     * Send standardized JSON error response
     */
    public static function ajax_error($message, $data = array()) {
        wp_send_json_error(array_merge(array('message' => $message), $data));
    }
}
