<?php
/**
 * Git Operations
 * 
 * Handles all local git operations.
 *
 * @package HWS_Git_Push
 */

namespace HWS_Git_Push;

if (!defined('ABSPATH')) exit;

class Git_Operations {
    
    /*
    |--------------------------------------------------------------------------
    | Status & Information
    |--------------------------------------------------------------------------
    */
    
    /**
     * Check if git is available on the system
     */
    public static function check_git_available() {
        $result = Helpers::run_command('which git');
        
        if (!$result['success']) {
            return array('available' => false, 'message' => 'Git not installed');
        }
        
        $version_result = Helpers::run_command('git --version');
        
        return array(
            'available' => true,
            'version'   => trim($version_result['output']),
            'path'      => trim($result['output'])
        );
    }
    
    /**
     * Get the status of a git repository
     */
    public static function get_status($repo_path) {
        if (!is_dir($repo_path . '/.git')) {
            return array('is_repo' => false);
        }
        
        $status = array(
            'is_repo'       => true,
            'branch'        => '',
            'remote'        => '',
            'remote_url'    => '',
            'has_changes'   => false,
            'changed_files' => array()
        );
        
        // Get branch
        $result = Helpers::run_command('git rev-parse --abbrev-ref HEAD', $repo_path);
        if ($result['success']) $status['branch'] = trim($result['output']);
        
        // Get remote (strip token from URL for security)
        $result = Helpers::run_command('git remote get-url origin', $repo_path);
        if ($result['success']) {
            $raw_url = trim($result['output']);
            $parsed = GitHub_API::parse_remote_url($raw_url);
            if ($parsed) {
                $status['remote'] = $parsed['owner'] . '/' . $parsed['repo'];
                // Store sanitized URL without token
                $status['remote_url'] = 'https://github.com/' . $parsed['owner'] . '/' . $parsed['repo'] . '.git';
            } else {
                $status['remote_url'] = $raw_url;
            }
        }
        
        // Check for changes
        $result = Helpers::run_command('git status --porcelain', $repo_path);
        if ($result['success'] && !empty(trim($result['output']))) {
            $status['has_changes'] = true;
            $status['changed_files'] = array_filter(explode("\n", trim($result['output'])));
        }
        
        return $status;
    }
    
    /*
    |--------------------------------------------------------------------------
    | Repository Operations
    |--------------------------------------------------------------------------
    */
    
    /**
     * Initialize a new git repository
     */
    public static function init($repo_path, $reinitialize = false) {
        $log = array();
        
        if ($reinitialize && is_dir($repo_path . '/.git')) {
            $log[] = '⚠️  Removing existing .git folder...';
            Helpers::run_command('rm -rf ' . escapeshellarg($repo_path . '/.git'));
        }
        
        $log[] = '📁 Initializing git repository...';
        $result = Helpers::run_command('git init', $repo_path);
        
        if (!$result['success']) {
            $log[] = '❌ Failed: ' . $result['output'];
            return array('success' => false, 'log' => $log);
        }
        
        $log[] = '✓ Git initialized';
        
        // Create main branch
        Helpers::run_command('git checkout -b ' . Config::$default_branch, $repo_path);
        $log[] = '✓ Branch created: ' . Config::$default_branch;
        
        return array('success' => true, 'log' => $log);
    }
    
    /**
     * Configure git user for a repository
     */
    public static function configure_user($repo_path, $email = null, $name = null) {
        $email = $email ?: Config::$default_git_email;
        $name = $name ?: Config::$default_git_name;
        
        Helpers::run_command('git config user.email ' . escapeshellarg($email), $repo_path);
        Helpers::run_command('git config user.name ' . escapeshellarg($name), $repo_path);
        
        return array('success' => true, 'log' => array('✓ Git user configured'));
    }
    
    /**
     * Add files and create a commit
     */
    public static function add_and_commit($repo_path, $message = null) {
        $log = array();
        $message = $message ?: Config::$default_commit_message;
        
        // Stage files
        $log[] = '📦 Staging files...';
        $result = Helpers::run_command('git add .', $repo_path);
        
        if (!$result['success']) {
            $log[] = '❌ Staging failed: ' . $result['output'];
            return array('success' => false, 'log' => $log);
        }
        $log[] = '✓ Files staged';
        
        // Commit
        $log[] = '📝 Creating commit...';
        $result = Helpers::run_command('git commit -m ' . escapeshellarg($message), $repo_path);
        
        if (!$result['success']) {
            if (strpos($result['output'], 'nothing to commit') !== false) {
                $log[] = 'ℹ️  Nothing to commit';
                return array('success' => true, 'log' => $log, 'nothing_to_commit' => true);
            }
            $log[] = '❌ Commit failed: ' . $result['output'];
            return array('success' => false, 'log' => $log);
        }
        $log[] = '✓ Commit created';
        
        return array('success' => true, 'log' => $log);
    }
    
    /**
     * Add a remote to the repository
     */
    public static function add_remote($repo_path, $url, $name = 'origin') {
        Helpers::run_command('git remote remove ' . $name, $repo_path);
        return Helpers::run_command('git remote add ' . $name . ' ' . escapeshellarg($url), $repo_path);
    }
    
    /**
     * Push to remote repository
     */
    public static function push($repo_path, $remote = 'origin', $branch = null, $force = false, $set_upstream = false) {
        $branch = $branch ?: Config::$default_branch;
        
        $cmd = 'git push';
        if ($force) $cmd .= ' --force';
        if ($set_upstream) $cmd .= ' -u';
        $cmd .= ' ' . $remote . ' ' . $branch;
        
        return Helpers::run_command($cmd, $repo_path);
    }
    
    /*
    |--------------------------------------------------------------------------
    | Complete Workflows
    |--------------------------------------------------------------------------
    */
    
    /**
     * Full init, configure, add remote, commit and push
     */
    public static function full_init_and_push($repo_path, $remote_url, $commit_message = null, $reinitialize = false) {
        $log = array();
        $commit_message = $commit_message ?: 'Initial commit';
        
        $log[] = '═══════════════════════════════════════════════════';
        $log[] = '  ' . ($reinitialize ? 'REINITIALIZE' : 'INITIALIZE') . ' GIT REPOSITORY';
        $log[] = '  ' . date('Y-m-d H:i:s');
        $log[] = '═══════════════════════════════════════════════════';
        $log[] = '';
        
        // Initialize
        $result = self::init($repo_path, $reinitialize);
        $log = array_merge($log, $result['log']);
        if (!$result['success']) return array('success' => false, 'log' => $log, 'error' => 'Init failed');
        
        // Configure user
        $result = self::configure_user($repo_path);
        $log = array_merge($log, $result['log']);
        
        // Add remote
        $log[] = '';
        $log[] = '🔗 Adding remote origin...';
        $result = self::add_remote($repo_path, $remote_url);
        if (!$result['success']) {
            $log[] = '❌ Failed: ' . $result['output'];
            return array('success' => false, 'log' => $log, 'error' => 'Remote failed');
        }
        $log[] = '✓ Remote added';
        
        // Commit
        $log[] = '';
        $result = self::add_and_commit($repo_path, $commit_message);
        $log = array_merge($log, $result['log']);
        if (!$result['success']) return array('success' => false, 'log' => $log, 'error' => 'Commit failed');
        
        // Push
        $log[] = '';
        $log[] = '🚀 Pushing to GitHub...';
        $result = self::push($repo_path, 'origin', Config::$default_branch, true, true);
        if (!$result['success']) {
            $log[] = '❌ Push failed: ' . $result['output'];
            return array('success' => false, 'log' => $log, 'error' => 'Push failed');
        }
        $log[] = '✓ Pushed successfully!';
        
        $log[] = '';
        $log[] = '═══════════════════════════════════════════════════';
        $log[] = '  ✓ SUCCESS';
        $log[] = '═══════════════════════════════════════════════════';
        
        return array('success' => true, 'log' => $log);
    }
    
    /**
     * Quick push - add, commit, and push
     */
    public static function quick_push($repo_path, $message = null, $force = false) {
        $log = array();
        
        $log[] = '═══════════════════════════════════════════════════';
        $log[] = '  GIT PUSH - ' . date('Y-m-d H:i:s');
        $log[] = '═══════════════════════════════════════════════════';
        $log[] = '';
        
        $status = self::get_status($repo_path);
        if (!$status['is_repo']) {
            $log[] = '❌ Not a git repository';
            return array('success' => false, 'log' => $log);
        }
        
        $log[] = '📍 Branch: ' . $status['branch'];
        $log[] = '📡 Remote: ' . $status['remote'];
        $log[] = '';
        
        // Commit
        $result = self::add_and_commit($repo_path, $message);
        $log = array_merge($log, $result['log']);
        
        // Push
        $log[] = '';
        $log[] = '🚀 Pushing...';
        $result = self::push($repo_path, 'origin', $status['branch'], $force);
        
        if (!$result['success']) {
            $log[] = '❌ Push failed: ' . $result['output'];
            return array('success' => false, 'log' => $log);
        }
        $log[] = '✓ Pushed successfully!';
        
        return array('success' => true, 'log' => $log);
    }
}
