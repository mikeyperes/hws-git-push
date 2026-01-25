<?php
/**
 * Backup Operations
 * 
 * Handles backup creation and restoration for git configurations.
 *
 * @package HWS_Git_Push
 */

namespace HWS_Git_Push;

if (!defined('ABSPATH')) exit;

class Backup {
    
    /**
     * Create a backup of a plugin's .git folder
     */
    public static function create($plugin_path) {
        $git_dir = rtrim($plugin_path, '/') . '/.git';
        if (!is_dir($git_dir)) return false;
        
        $plugin_slug = basename($plugin_path);
        $backup_dir = self::get_backup_dir($plugin_slug);
        
        if (!is_dir($backup_dir)) wp_mkdir_p($backup_dir);
        
        $timestamp = time();
        $backup_file = $backup_dir . '/' . Config::$backup_prefix . $timestamp . '.tar.gz';
        
        $cmd = 'cd ' . escapeshellarg(dirname($git_dir)) . ' && tar -czf ' . escapeshellarg($backup_file) . ' .git 2>&1';
        Helpers::run_command($cmd);
        
        if (!file_exists($backup_file)) return false;
        
        self::cleanup_old_backups($plugin_slug);
        return $backup_file;
    }
    
    /**
     * Create backup and return log messages
     */
    public static function create_with_log($plugin_path) {
        $log = array('💾 Creating backup...');
        $backup_file = self::create($plugin_path);
        
        if ($backup_file) {
            $log[] = '✓ Backup: ' . basename($backup_file);
            return array('success' => true, 'file' => $backup_file, 'log' => $log);
        }
        
        $log[] = '⚠️  Backup failed (non-fatal)';
        return array('success' => false, 'log' => $log);
    }
    
    /**
     * Get all backups for a plugin
     */
    public static function get_backups($plugin_slug) {
        $backup_dir = self::get_backup_dir($plugin_slug);
        if (!is_dir($backup_dir)) return array();
        
        $backups = array();
        $files = glob($backup_dir . '/' . Config::$backup_prefix . '*.tar.gz');
        
        foreach ($files as $file) {
            preg_match('/' . Config::$backup_prefix . '(\d+)\.tar\.gz$/', $file, $matches);
            $timestamp = isset($matches[1]) ? (int) $matches[1] : filemtime($file);
            
            $backups[] = array(
                'file'      => $file,
                'filename'  => basename($file),
                'timestamp' => $timestamp,
                'date'      => date('Y-m-d H:i:s', $timestamp),
                'size'      => filesize($file)
            );
        }
        
        usort($backups, function($a, $b) { return $b['timestamp'] - $a['timestamp']; });
        return $backups;
    }
    
    /**
     * Get the most recent backup
     */
    public static function get_latest($plugin_slug) {
        $backups = self::get_backups($plugin_slug);
        return !empty($backups) ? $backups[0] : false;
    }
    
    /**
     * Delete old backups, keeping only recent ones
     */
    public static function cleanup_old_backups($plugin_slug, $keep = null) {
        $keep = $keep ?: Config::$max_backups;
        $backups = self::get_backups($plugin_slug);
        $deleted = 0;
        
        if (count($backups) > $keep) {
            $to_delete = array_slice($backups, $keep);
            foreach ($to_delete as $backup) {
                if (@unlink($backup['file'])) $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * Restore a backup
     */
    public static function restore($backup_file, $plugin_path) {
        if (!file_exists($backup_file)) {
            return array('success' => false, 'message' => 'Backup file not found');
        }
        
        if (!is_dir($plugin_path)) {
            return array('success' => false, 'message' => 'Plugin directory not found');
        }
        
        // Remove existing .git
        $git_dir = $plugin_path . '/.git';
        if (is_dir($git_dir)) Helpers::delete_directory($git_dir);
        
        // Extract backup
        $cmd = 'cd ' . escapeshellarg($plugin_path) . ' && tar -xzf ' . escapeshellarg($backup_file) . ' 2>&1';
        $result = Helpers::run_command($cmd);
        
        if (!$result['success']) {
            return array('success' => false, 'message' => 'Extract failed');
        }
        
        if (!is_dir($git_dir)) {
            return array('success' => false, 'message' => '.git not restored');
        }
        
        return array('success' => true, 'message' => 'Backup restored');
    }
    
    /**
     * Get the backup directory for a plugin
     */
    public static function get_backup_dir($plugin_slug) {
        return Config::get_backup_dir() . '/' . $plugin_slug;
    }
    
    /**
     * Check if any backups exist for a plugin
     */
    public static function has_backups($plugin_slug) {
        return !empty(self::get_backups($plugin_slug));
    }
}
