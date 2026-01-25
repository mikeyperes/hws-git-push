<?php
/**
 * GitHub API Integration
 * 
 * Handles all communication with the GitHub API.
 *
 * @package HWS_Git_Push
 */

namespace HWS_Git_Push;

if (!defined('ABSPATH')) exit;

class GitHub_API {
    
    /** @var string|null Cached API token */
    private static $token = null;
    
    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */
    
    /**
     * Get the stored GitHub API token
     */
    public static function get_token() {
        if (self::$token === null) {
            self::$token = get_option(Config::$option_github_token, '');
        }
        return self::$token;
    }
    
    /**
     * Save the GitHub API token
     */
    public static function save_token($token) {
        self::$token = $token;
        return update_option(Config::$option_github_token, $token);
    }
    
    /**
     * Verify a GitHub token is valid
     */
    public static function verify_token($token) {
        $response = self::request('/user', $token);
        
        if (is_wp_error($response)) {
            return array('valid' => false, 'message' => $response->get_error_message());
        }
        
        if (isset($response['login'])) {
            return array(
                'valid'    => true,
                'username' => $response['login'],
                'name'     => $response['name'] ?? $response['login']
            );
        }
        
        return array('valid' => false, 'message' => 'Invalid response from GitHub');
    }
    
    /*
    |--------------------------------------------------------------------------
    | API Requests
    |--------------------------------------------------------------------------
    */
    
    /**
     * Make a request to the GitHub API
     */
    public static function request($endpoint, $token = null, $method = 'GET', $body = null) {
        $token = $token ?: self::get_token();
        $url = Config::$github_api_url . $endpoint;
        
        $args = array(
            'method'  => $method,
            'timeout' => Config::$api_timeout,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => Config::$plugin_name . '/' . HWS_GIT_PUSH_VERSION
            )
        );
        
        if (!empty($token)) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }
        
        if ($body !== null) {
            $args['body'] = json_encode($body);
            $args['headers']['Content-Type'] = 'application/json';
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) return $response;
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code < 200 || $code >= 300) {
            $message = isset($body['message']) ? $body['message'] : 'GitHub API error (HTTP ' . $code . ')';
            return new \WP_Error('github_api_error', $message);
        }
        
        return $body;
    }
    
    /*
    |--------------------------------------------------------------------------
    | Repository Operations
    |--------------------------------------------------------------------------
    */
    
    /**
     * Fetch all repositories accessible to the authenticated user
     */
    public static function get_repositories() {
        $token = self::get_token();
        
        if (empty($token)) {
            return new \WP_Error('no_token', 'No GitHub token configured.');
        }
        
        $all_repos = array();
        $page = 1;
        $per_page = 100;
        
        do {
            $endpoint = '/user/repos?per_page=' . $per_page . '&page=' . $page . '&sort=updated&affiliation=owner,collaborator';
            $repos = self::request($endpoint);
            
            if (is_wp_error($repos)) return $repos;
            if (empty($repos)) break;
            
            foreach ($repos as $repo) {
                $all_repos[] = array(
                    'name'           => $repo['name'],
                    'full_name'      => $repo['full_name'],
                    'html_url'       => $repo['html_url'],
                    'ssh_url'        => $repo['ssh_url'],
                    'clone_url'      => $repo['clone_url'],
                    'private'        => $repo['private'],
                    'owner'          => $repo['owner']['login'],
                    'default_branch' => $repo['default_branch'] ?? Config::$default_branch,
                    'updated_at'     => $repo['updated_at']
                );
            }
            $page++;
        } while (count($repos) === $per_page && $page <= 10);
        
        usort($all_repos, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $all_repos;
    }
    
    /**
     * Get version history from commits
     * Fetches recent commits and extracts the Version: from the plugin file at each commit
     */
    public static function get_versions($repo = null) {
        $repo = $repo ?: Config::$github_repo;
        $token = self::get_token();
        
        // Get recent commits
        $url = Config::$github_api_url . '/repos/' . $repo . '/commits?per_page=30';
        
        $args = array(
            'timeout' => Config::$api_timeout,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => Config::$plugin_name . '/' . HWS_GIT_PUSH_VERSION
            )
        );
        
        if (!empty($token)) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code !== 200) {
            $message = isset($body['message']) ? $body['message'] : 'GitHub API error (HTTP ' . $code . ')';
            return new \WP_Error('github_api_error', $message);
        }
        
        if (!is_array($body) || empty($body)) {
            return new \WP_Error('no_commits', 'No commits found');
        }
        
        // Find the main plugin file name (usually repo-name.php)
        $repo_name = basename($repo);
        $main_file = self::find_main_plugin_file($repo, $token);
        
        if (!$main_file) {
            // Fallback to common patterns
            $main_file = $repo_name . '.php';
        }
        
        $versions = array();
        
        foreach ($body as $commit) {
            $sha = $commit['sha'];
            $short_sha = substr($sha, 0, 7);
            $date = isset($commit['commit']['committer']['date']) ? $commit['commit']['committer']['date'] : '';
            $message = isset($commit['commit']['message']) ? $commit['commit']['message'] : '';
            $message = strtok($message, "\n"); // First line only
            $message = substr($message, 0, 40); // Truncate
            
            // Format date
            $formatted_date = '';
            if ($date) {
                $timestamp = strtotime($date);
                $formatted_date = date('M j, Y', $timestamp);
            }
            
            // Try to get version from this commit
            $version = self::get_version_at_commit($repo, $sha, $main_file, $token);
            $version_label = $version ?: '?';
            
            // Show commit message + version + date + sha
            $display_name = $message . ' (v' . $version_label . ' - ' . $formatted_date . ' - ' . $short_sha . ')';
            
            $versions[] = array(
                'name'        => $display_name,
                'sha'         => $sha,
                'short_sha'   => $short_sha,
                'version'     => $version_label,
                'date'        => $formatted_date,
                'message'     => $message,
                'is_tag'      => false
            );
            
            // Limit to 30 commits
            if (count($versions) >= 30) {
                break;
            }
        }
        
        return $versions;
    }
    
    /**
     * Find the main plugin file in a repository
     */
    private static function find_main_plugin_file($repo, $token = null) {
        $url = Config::$github_api_url . '/repos/' . $repo . '/contents';
        
        $args = array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => Config::$plugin_name . '/' . HWS_GIT_PUSH_VERSION
            )
        );
        
        if (!empty($token)) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        
        $contents = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!is_array($contents)) {
            return null;
        }
        
        // Look for PHP files at root
        foreach ($contents as $item) {
            if ($item['type'] === 'file' && preg_match('/\.php$/i', $item['name'])) {
                // Check if it's likely the main plugin file
                $name = $item['name'];
                $repo_name = basename($repo);
                
                // Prioritize file matching repo name
                if ($name === $repo_name . '.php') {
                    return $name;
                }
            }
        }
        
        // Fallback: return first PHP file found
        foreach ($contents as $item) {
            if ($item['type'] === 'file' && preg_match('/\.php$/i', $item['name'])) {
                return $item['name'];
            }
        }
        
        return null;
    }
    
    /**
     * Get the plugin version at a specific commit
     */
    private static function get_version_at_commit($repo, $sha, $file, $token = null) {
        // Use raw.githubusercontent.com with commit SHA
        $owner = dirname($repo);
        $repo_name = basename($repo);
        
        $url = "https://raw.githubusercontent.com/{$repo}/{$sha}/{$file}";
        
        $args = array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => Config::$plugin_name . '/' . HWS_GIT_PUSH_VERSION
            )
        );
        
        if (!empty($token)) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        
        $content = wp_remote_retrieve_body($response);
        
        // Extract Version: header
        if (preg_match('/^\s*\*?\s*Version:\s*(.+)$/mi', $content, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }
    
    /**
     * Get the latest version from GitHub
     */
    public static function get_latest_version($repo = null) {
        $url = Config::get_github_raw_url(Config::$plugin_file);
        
        $response = wp_remote_get($url, array('timeout' => 15, 'sslverify' => true));
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        
        if (preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $body, $matches)) {
            return trim($matches[1]);
        }
        
        return false;
    }
    
    /**
     * Download a repository archive
     */
    public static function download_archive($destination, $ref = null) {
        $url = Config::get_github_download_url($ref);
        
        $response = wp_remote_get($url, array(
            'timeout'  => 120,
            'stream'   => true,
            'filename' => $destination
        ));
        
        if (is_wp_error($response)) return $response;
        
        if (!file_exists($destination)) {
            return new \WP_Error('download_failed', 'Download failed');
        }
        
        return true;
    }
    
    /*
    |--------------------------------------------------------------------------
    | URL Utilities
    |--------------------------------------------------------------------------
    */
    
    /**
     * Parse a GitHub remote URL
     */
    public static function parse_remote_url($url) {
        // HTTPS with token
        if (preg_match('#https://([^@]+)@github\.com/([^/]+)/([^/]+?)(?:\.git)?$#', $url, $m)) {
            return array('token' => $m[1], 'owner' => $m[2], 'repo' => $m[3]);
        }
        // Standard HTTPS
        if (preg_match('#https://github\.com/([^/]+)/([^/]+?)(?:\.git)?$#', $url, $m)) {
            return array('owner' => $m[1], 'repo' => $m[2]);
        }
        // SSH
        if (preg_match('#git@github\.com:([^/]+)/([^/]+?)(?:\.git)?$#', $url, $m)) {
            return array('owner' => $m[1], 'repo' => $m[2]);
        }
        return false;
    }
    
    /**
     * Build a GitHub remote URL
     */
    public static function build_remote_url($owner, $repo, $token = null, $ssh = false) {
        if ($ssh) return 'git@github.com:' . $owner . '/' . $repo . '.git';
        if ($token) return 'https://' . $token . '@github.com/' . $owner . '/' . $repo . '.git';
        return 'https://github.com/' . $owner . '/' . $repo . '.git';
    }
}
