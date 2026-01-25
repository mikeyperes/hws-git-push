<?php
/**
 * AJAX Request Handlers
 * 
 * All AJAX endpoints for the plugin.
 *
 * @package HWS_Git_Push
 */

namespace HWS_Git_Push;

if (!defined('ABSPATH')) exit;

class Ajax_Handlers {
    
    /**
     * Register all AJAX handlers
     */
    public static function register() {
        $actions = array(
            'hws_system_check',
            'hws_save_github_token',
            'hws_get_token',
            'hws_fetch_github_repos',
            'hws_push_plugin',
            'hws_init_git_repo',
            'hws_upload_plugin',
            'hws_fetch_plugin',
            'hws_unregister_plugin',
            'hws_restore_git',
            'hws_download_backup',
            'hws_restore_backup',
            'hws_backup_single',
            'hws_backup_all',
            'hws_get_backup_info',
            'hws_check_plugin_version',
            'hws_update_plugin_from_github',
            'hws_download_current_plugin',
            'hws_load_plugin_versions',
            'hws_download_plugin_version',
            'hws_save_log',
            'hws_clear_log',
            'hws_check_all_plugin_versions',
            'hws_check_single_plugin_version',
            'hws_rename_plugin',
            'hws_update_plugin_version',
            'hws_regenerate_secret',
        );
        
        foreach ($actions as $action) {
            add_action('wp_ajax_' . $action, array(__CLASS__, str_replace('hws_', 'handle_', $action)));
        }
    }
    
    /*
    |--------------------------------------------------------------------------
    | System Handlers
    |--------------------------------------------------------------------------
    */
    
    public static function handle_system_check() {
        Helpers::verify_ajax_request();
        $git_check = Git_Operations::check_git_available();
        
        Helpers::ajax_success('System check complete', array(
            'git'      => $git_check,
            'php_user' => Helpers::get_process_user(),
            'home_dir' => Helpers::get_home_dir()
        ));
    }
    
    /*
    |--------------------------------------------------------------------------
    | GitHub Token Handlers
    |--------------------------------------------------------------------------
    */
    
    public static function handle_save_github_token() {
        Helpers::verify_ajax_request();
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        
        if (empty($token)) {
            GitHub_API::save_token('');
            Helpers::ajax_success('Token cleared');
        }
        
        $verify = GitHub_API::verify_token($token);
        if (!$verify['valid']) {
            Helpers::ajax_error('Invalid token: ' . $verify['message']);
        }
        
        GitHub_API::save_token($token);
        Helpers::ajax_success('Token saved', array('username' => $verify['username']));
    }
    
    public static function handle_get_token() {
        Helpers::verify_ajax_request();
        $token = GitHub_API::get_token();
        
        if (empty($token)) {
            Helpers::ajax_error('No token configured');
        }
        
        Helpers::ajax_success('Token retrieved', array('token' => $token));
    }
    
    public static function handle_fetch_github_repos() {
        Helpers::verify_ajax_request();
        $repos = GitHub_API::get_repositories();
        
        if (is_wp_error($repos)) {
            Helpers::ajax_error($repos->get_error_message());
        }
        
        Helpers::ajax_success('Found ' . count($repos) . ' repositories', array(
            'repos' => $repos,
            'count' => count($repos)
        ));
    }
    
    /*
    |--------------------------------------------------------------------------
    | Git Operation Handlers
    |--------------------------------------------------------------------------
    */
    
    public static function handle_push_plugin() {
        Helpers::verify_ajax_request();
        
        $plugin_slug = isset($_POST['plugin']) ? sanitize_file_name($_POST['plugin']) : '';
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';
        $force = isset($_POST['force']) && $_POST['force'] === 'true';
        
        if (empty($plugin_slug)) Helpers::ajax_error('No plugin specified');
        
        $plugin_path = Helpers::get_plugin_path($plugin_slug);
        if (!is_dir($plugin_path)) Helpers::ajax_error('Plugin not found');
        
        // Get remote info before push
        $status = Git_Operations::get_status($plugin_path);
        $remote = '';
        if (!empty($status['remote_url'])) {
            $parsed = GitHub_API::parse_remote_url($status['remote_url']);
            if ($parsed) {
                $remote = $parsed['owner'] . '/' . $parsed['repo'];
            }
        }
        
        Backup::create($plugin_path);
        $result = Git_Operations::quick_push($plugin_path, $message, $force);
        
        if ($result['success']) {
            Helpers::ajax_success('Push complete', array(
                'log'    => implode("\n", $result['log']),
                'remote' => $remote
            ));
        } else {
            Helpers::ajax_error('Push failed', array(
                'log'    => implode("\n", $result['log']),
                'remote' => $remote
            ));
        }
    }
    
    public static function handle_init_git_repo() {
        Helpers::verify_ajax_request();
        
        $plugin_slug = isset($_POST['plugin']) ? sanitize_file_name($_POST['plugin']) : '';
        $github_repo = isset($_POST['github_repo']) ? sanitize_text_field($_POST['github_repo']) : '';
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : 'Initial commit';
        
        if (empty($plugin_slug) || empty($github_repo)) Helpers::ajax_error('Missing required fields');
        
        $plugin_path = Helpers::get_plugin_path($plugin_slug);
        if (!is_dir($plugin_path)) Helpers::ajax_error('Plugin not found');
        
        $is_reinit = Helpers::has_git($plugin_path);
        
        // Build remote URL with token
        $token = GitHub_API::get_token();
        $parsed = GitHub_API::parse_remote_url('https://github.com/' . $github_repo);
        if (!$parsed) Helpers::ajax_error('Invalid repository format');
        
        $remote_url = GitHub_API::build_remote_url($parsed['owner'], $parsed['repo'], $token);
        $result = Git_Operations::full_init_and_push($plugin_path, $remote_url, $message, $is_reinit);
        
        if ($result['success']) {
            // REGISTER IN DATABASE so it persists after updates
            Admin_UI::register_plugin($plugin_slug, $github_repo);
            
            $backup = Backup::create_with_log($plugin_path);
            $result['log'] = array_merge($result['log'], $backup['log']);
            $result['log'][] = "✓ Plugin registered in database (will persist after updates)";
            Helpers::ajax_success('Repository initialized', array('log' => implode("\n", $result['log'])));
        } else {
            Helpers::ajax_error($result['error'] ?? 'Init failed', array('log' => implode("\n", $result['log'])));
        }
    }
    
    /*
    |--------------------------------------------------------------------------
    | Plugin Upload/Install Handlers
    |--------------------------------------------------------------------------
    */
    
    public static function handle_upload_plugin() {
        Helpers::verify_ajax_request();
        
        if (!isset($_FILES['plugin_zip']) || $_FILES['plugin_zip']['error'] !== UPLOAD_ERR_OK) {
            Helpers::ajax_error('No file uploaded or upload error');
        }
        
        $file = $_FILES['plugin_zip'];
        
        // Verify it's a zip
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            Helpers::ajax_error('Only ZIP files allowed');
        }
        
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        WP_Filesystem();
        
        $upload_dir = wp_upload_dir();
        $tmp_file = $upload_dir['basedir'] . '/' . sanitize_file_name($file['name']);
        
        if (!move_uploaded_file($file['tmp_name'], $tmp_file)) {
            Helpers::ajax_error('Failed to move uploaded file');
        }
        
        // Get plugin slug from zip
        $zip = new \ZipArchive;
        $plugin_slug = '';
        if ($zip->open($tmp_file) === true) {
            $first_entry = $zip->getNameIndex(0);
            $plugin_slug = rtrim(explode('/', $first_entry)[0], '/');
            $zip->close();
        }
        
        if (empty($plugin_slug)) {
            $plugin_slug = pathinfo($file['name'], PATHINFO_FILENAME);
        }
        
        // Extract to plugins dir
        $result = unzip_file($tmp_file, WP_PLUGIN_DIR);
        @unlink($tmp_file);
        
        if (is_wp_error($result)) {
            Helpers::ajax_error('Unzip failed: ' . $result->get_error_message());
        }
        
        Helpers::ajax_success('Plugin installed: ' . $plugin_slug, array(
            'plugin_slug' => $plugin_slug
        ));
    }
    
    public static function handle_fetch_plugin() {
        Helpers::verify_ajax_request();
        
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        
        if (empty($url)) {
            Helpers::ajax_error('No URL provided');
        }
        
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        
        $tmp_file = download_url($url, 300);
        
        if (is_wp_error($tmp_file)) {
            Helpers::ajax_error('Download failed: ' . $tmp_file->get_error_message());
        }
        
        // Get plugin slug from zip
        $zip = new \ZipArchive;
        $plugin_slug = '';
        if ($zip->open($tmp_file) === true) {
            $first_entry = $zip->getNameIndex(0);
            $plugin_slug = rtrim(explode('/', $first_entry)[0], '/');
            $zip->close();
        }
        
        if (empty($plugin_slug)) {
            $plugin_slug = pathinfo($url, PATHINFO_FILENAME);
        }
        
        // Extract to plugins dir
        $result = unzip_file($tmp_file, WP_PLUGIN_DIR);
        @unlink($tmp_file);
        
        if (is_wp_error($result)) {
            Helpers::ajax_error('Unzip failed: ' . $result->get_error_message());
        }
        
        Helpers::ajax_success('Plugin installed: ' . $plugin_slug, array(
            'plugin_slug' => $plugin_slug
        ));
    }
    
    public static function handle_unregister_plugin() {
        Helpers::verify_ajax_request();
        
        $plugin_slug = isset($_POST['plugin']) ? sanitize_file_name($_POST['plugin']) : '';
        if (empty($plugin_slug)) Helpers::ajax_error('No plugin specified');
        
        Admin_UI::unregister_plugin($plugin_slug);
        Helpers::ajax_success('Plugin unregistered from dashboard');
    }
    
    public static function handle_restore_git() {
        Helpers::verify_ajax_request();
        
        $plugin_slug = isset($_POST['plugin']) ? sanitize_file_name($_POST['plugin']) : '';
        if (empty($plugin_slug)) Helpers::ajax_error('No plugin specified');
        
        $plugin_path = Helpers::get_plugin_path($plugin_slug);
        if (!is_dir($plugin_path)) Helpers::ajax_error('Plugin folder not found');
        
        // Get saved GitHub repo
        $github_repo = Admin_UI::get_plugin_github_repo($plugin_slug);
        if (!$github_repo) Helpers::ajax_error('No GitHub repo registered for this plugin');
        
        // Try to restore from backup first
        $backup = Backup::get_latest($plugin_slug);
        if ($backup) {
            $result = Backup::restore($backup['file'], $plugin_path);
            if ($result['success']) {
                Helpers::ajax_success('Git restored from backup', array('method' => 'backup'));
            }
        }
        
        // No backup - reinitialize
        $token = GitHub_API::get_token();
        $parsed = GitHub_API::parse_remote_url('https://github.com/' . $github_repo);
        if (!$parsed) Helpers::ajax_error('Invalid repository format');
        
        $remote_url = GitHub_API::build_remote_url($parsed['owner'], $parsed['repo'], $token);
        $result = Git_Operations::full_init_and_push($plugin_path, $remote_url, 'Re-init after update', true);
        
        if ($result['success']) {
            Backup::create($plugin_path);
            Helpers::ajax_success('Git reinitialized', array('method' => 'reinit'));
        } else {
            Helpers::ajax_error('Failed to restore: ' . ($result['error'] ?? 'Unknown error'));
        }
    }
    
    /*
    |--------------------------------------------------------------------------
    | Backup Handlers
    |--------------------------------------------------------------------------
    */
    
    public static function handle_download_backup() {
        Helpers::verify_ajax_request();
        
        $plugin_slug = isset($_POST['plugin']) ? sanitize_file_name($_POST['plugin']) : '';
        if (empty($plugin_slug)) Helpers::ajax_error('No plugin specified');
        
        $backup = Backup::get_latest($plugin_slug);
        if (!$backup) Helpers::ajax_error('No backups found');
        
        $upload_dir = wp_upload_dir();
        $download_file = $upload_dir['basedir'] . '/' . $backup['filename'];
        
        if (!copy($backup['file'], $download_file)) {
            Helpers::ajax_error('Failed to prepare download');
        }
        
        Helpers::ajax_success('Backup ready', array(
            'url'      => $upload_dir['baseurl'] . '/' . $backup['filename'],
            'filename' => $backup['filename']
        ));
    }
    
    public static function handle_restore_backup() {
        Helpers::verify_ajax_request();
        
        $plugin_slug = isset($_POST['plugin']) ? sanitize_file_name($_POST['plugin']) : '';
        $backup_file = isset($_POST['backup']) ? sanitize_file_name($_POST['backup']) : '';
        
        if (empty($plugin_slug)) Helpers::ajax_error('No plugin specified');
        
        $plugin_path = Helpers::get_plugin_path($plugin_slug);
        
        // If no specific backup file, use latest
        if (empty($backup_file)) {
            $backup = Backup::get_latest($plugin_slug);
            if (!$backup) Helpers::ajax_error('No backups found');
            $backup_path = $backup['file'];
        } else {
            $backup_path = Backup::get_backup_dir($plugin_slug) . '/' . $backup_file;
        }
        
        $result = Backup::restore($backup_path, $plugin_path);
        
        if ($result['success']) {
            Helpers::ajax_success($result['message']);
        } else {
            Helpers::ajax_error($result['message']);
        }
    }
    
    public static function handle_backup_single() {
        Helpers::verify_ajax_request();
        
        $plugin_slug = isset($_POST['plugin']) ? sanitize_file_name($_POST['plugin']) : '';
        if (empty($plugin_slug)) Helpers::ajax_error('No plugin specified');
        
        $plugin_path = Helpers::get_plugin_path($plugin_slug);
        if (!is_dir($plugin_path . '/.git')) Helpers::ajax_error('No git repository in this plugin');
        
        $result = Backup::create($plugin_path);
        
        if ($result['success']) {
            Helpers::ajax_success('Backup created: ' . basename($result['file']), array(
                'file' => basename($result['file'])
            ));
        } else {
            Helpers::ajax_error($result['message']);
        }
    }
    
    public static function handle_backup_all() {
        Helpers::verify_ajax_request();
        
        $git_plugins = Admin_UI::get_git_plugins();
        $backed_up = 0;
        $errors = array();
        
        foreach ($git_plugins as $plugin) {
            $plugin_path = Helpers::get_plugin_path($plugin['slug']);
            $result = Backup::create($plugin_path);
            if ($result['success']) {
                $backed_up++;
            } else {
                $errors[] = $plugin['slug'] . ': ' . $result['message'];
            }
        }
        
        if ($backed_up > 0) {
            $msg = 'Backed up ' . $backed_up . ' plugin(s)';
            if (!empty($errors)) $msg .= '. Errors: ' . implode(', ', $errors);
            Helpers::ajax_success($msg, array('count' => $backed_up));
        } else {
            Helpers::ajax_error('No plugins backed up. ' . implode(', ', $errors));
        }
    }
    
    public static function handle_get_backup_info() {
        Helpers::verify_ajax_request();
        
        $plugin_slug = isset($_POST['plugin']) ? sanitize_file_name($_POST['plugin']) : '';
        if (empty($plugin_slug)) Helpers::ajax_error('No plugin specified');
        
        $plugin_path = Helpers::get_plugin_path($plugin_slug);
        $has_git = is_dir($plugin_path . '/.git');
        $backups = Backup::list_backups($plugin_slug);
        $latest = Backup::get_latest($plugin_slug);
        
        Helpers::ajax_success('Info loaded', array(
            'has_git'      => $has_git,
            'backup_count' => count($backups),
            'latest'       => $latest ? array(
                'file'     => basename($latest['file']),
                'date'     => date('Y-m-d H:i:s', $latest['time']),
                'size'     => size_format($latest['size'])
            ) : null
        ));
    }
    
    /*
    |--------------------------------------------------------------------------
    | Plugin Version Handlers
    |--------------------------------------------------------------------------
    */
    
    public static function handle_check_plugin_version() {
        Helpers::verify_ajax_request('update_plugins');
        
        $latest = GitHub_API::get_latest_version();
        if (!$latest) Helpers::ajax_error('Could not check version');
        
        $current = HWS_GIT_PUSH_VERSION;
        
        Helpers::ajax_success('Version check complete', array(
            'version'          => $latest,
            'current'          => $current,
            'update_available' => version_compare($latest, $current, '>')
        ));
    }
    
    public static function handle_update_plugin_from_github() {
        Helpers::verify_ajax_request('update_plugins');
        
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        WP_Filesystem();
        global $wp_filesystem;
        
        $dest = WP_PLUGIN_DIR . '/' . Config::$plugin_folder;
        
        // BACKUP GIT CONFIG BEFORE UPDATE
        $had_git = is_dir($dest . '/.git');
        if ($had_git) {
            Backup::create($dest);
        }
        
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/hws-update-' . time();
        $temp_zip = $temp_dir . '/github.zip';
        
        if (!wp_mkdir_p($temp_dir)) Helpers::ajax_error('Could not create temp directory');
        
        $download = GitHub_API::download_archive($temp_zip);
        if (is_wp_error($download)) {
            Helpers::delete_directory($temp_dir);
            Helpers::ajax_error('Download failed');
        }
        
        $extract_dir = $temp_dir . '/extracted';
        wp_mkdir_p($extract_dir);
        $unzip = unzip_file($temp_zip, $extract_dir);
        
        if (is_wp_error($unzip)) {
            Helpers::delete_directory($temp_dir);
            Helpers::ajax_error('Extract failed');
        }
        
        $folders = glob($extract_dir . '/*', GLOB_ONLYDIR);
        if (empty($folders)) {
            Helpers::delete_directory($temp_dir);
            Helpers::ajax_error('No folder in archive');
        }
        
        $source = $folders[0];
        $plugin_file = Config::get_plugin_basename();
        
        $was_active = is_plugin_active($plugin_file);
        if ($was_active) deactivate_plugins($plugin_file, true);
        
        if (is_dir($dest)) $wp_filesystem->delete($dest, true);
        
        if (!$wp_filesystem->move($source, $dest)) {
            Helpers::delete_directory($temp_dir);
            Helpers::ajax_error('Installation failed');
        }
        
        Helpers::delete_directory($temp_dir);
        
        // AUTO-RESTORE GIT CONFIG AFTER UPDATE
        if ($had_git) {
            $backup = Backup::get_latest(Config::$plugin_folder);
            if ($backup) {
                Backup::restore($backup['file'], $dest);
            }
        }
        
        if ($was_active) activate_plugin($plugin_file);
        
        $new_data = get_plugin_data($dest . '/' . Config::$plugin_file);
        $msg = 'Updated to v' . $new_data['Version'];
        if ($had_git) $msg .= ' (git config restored)';
        Helpers::ajax_success($msg);
    }
    
    public static function handle_download_current_plugin() {
        Helpers::verify_ajax_request();
        
        $plugin_path = HWS_GIT_PUSH_DIR;
        $upload_dir = wp_upload_dir();
        $zip_file = $upload_dir['basedir'] . '/' . Config::$plugin_folder . '.zip';
        
        $result = Helpers::create_zip($plugin_path, $zip_file, Config::$plugin_folder);
        if (!$result) Helpers::ajax_error('Failed to create zip');
        
        Helpers::ajax_success('Download ready', array(
            'url'      => $upload_dir['baseurl'] . '/' . Config::$plugin_folder . '.zip',
            'filename' => Config::$plugin_folder . '.zip'
        ));
    }
    
    public static function handle_load_plugin_versions() {
        Helpers::verify_ajax_request('update_plugins');
        
        $debug = array();
        $debug['step'] = 'start';
        $debug['repo'] = Config::$github_repo;
        $debug['token_exists'] = !empty(GitHub_API::get_token());
        $debug['token_preview'] = GitHub_API::get_token() ? substr(GitHub_API::get_token(), 0, 10) . '...' : 'NONE';
        
        // Make direct API call here for debugging
        $url = 'https://api.github.com/repos/' . Config::$github_repo . '/commits?per_page=10';
        $debug['api_url'] = $url;
        
        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'HWS-Git-Push/' . HWS_GIT_PUSH_VERSION
            )
        );
        
        $token = GitHub_API::get_token();
        if (!empty($token)) {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            $debug['step'] = 'wp_error';
            $debug['error'] = $response->get_error_message();
            Helpers::ajax_error('API Error: ' . $response->get_error_message(), array('debug' => $debug));
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);
        
        $debug['http_code'] = $code;
        $debug['body_length'] = strlen($body_raw);
        $debug['body_preview'] = substr($body_raw, 0, 500);
        $debug['is_array'] = is_array($body);
        $debug['commit_count'] = is_array($body) ? count($body) : 0;
        
        if ($code !== 200) {
            $debug['step'] = 'http_error';
            $msg = isset($body['message']) ? $body['message'] : 'HTTP ' . $code;
            Helpers::ajax_error('GitHub Error: ' . $msg, array('debug' => $debug));
        }
        
        if (!is_array($body) || empty($body)) {
            $debug['step'] = 'empty_body';
            Helpers::ajax_error('No commits found', array('debug' => $debug));
        }
        
        // Build versions list - SIMPLE, just show commits
        $versions = array();
        foreach ($body as $i => $commit) {
            $sha = $commit['sha'];
            $short_sha = substr($sha, 0, 7);
            $message = isset($commit['commit']['message']) ? $commit['commit']['message'] : 'No message';
            $message = strtok($message, "\n");
            $message = substr($message, 0, 50);
            $date = isset($commit['commit']['committer']['date']) ? date('M j, Y', strtotime($commit['commit']['committer']['date'])) : '';
            
            $versions[] = array(
                'name' => $message . ' (' . $date . ' - ' . $short_sha . ')',
                'sha' => $sha,
                'short_sha' => $short_sha,
                'version' => $short_sha,
                'date' => $date,
                'message' => $message
            );
        }
        
        $debug['step'] = 'success';
        $debug['versions_built'] = count($versions);
        
        Helpers::ajax_success('Loaded ' . count($versions) . ' commits', array(
            'versions' => $versions,
            'debug' => $debug
        ));
    }
    
    public static function handle_download_plugin_version() {
        Helpers::verify_ajax_request();
        
        $sha = isset($_POST['sha']) ? sanitize_text_field($_POST['sha']) : '';
        $version_label = isset($_POST['version']) ? sanitize_text_field($_POST['version']) : '';
        
        if (empty($sha)) Helpers::ajax_error('No commit specified');
        
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/hws-temp-' . time();
        $temp_zip = $temp_dir . '/github.zip';
        
        // Sanitize version for filename
        $version_slug = preg_replace('/[^a-zA-Z0-9\.\-]/', '-', $version_label);
        $short_sha = substr($sha, 0, 7);
        $final_zip = $upload_dir['basedir'] . '/' . Config::$plugin_folder . '-v' . $version_slug . '-' . $short_sha . '.zip';
        
        if (!wp_mkdir_p($temp_dir)) Helpers::ajax_error('Could not create temp directory');
        
        // Download archive at specific commit SHA
        $download = GitHub_API::download_archive($temp_zip, $sha);
        if (is_wp_error($download)) {
            Helpers::delete_directory($temp_dir);
            Helpers::ajax_error('Download failed: ' . $download->get_error_message());
        }
        
        $zip = new \ZipArchive();
        if ($zip->open($temp_zip) !== true) {
            Helpers::delete_directory($temp_dir);
            Helpers::ajax_error('Could not open archive');
        }
        
        $extract_dir = $temp_dir . '/extracted';
        wp_mkdir_p($extract_dir);
        $zip->extractTo($extract_dir);
        $zip->close();
        
        $folders = glob($extract_dir . '/*', GLOB_ONLYDIR);
        if (empty($folders)) {
            Helpers::delete_directory($temp_dir);
            Helpers::ajax_error('No folder in archive');
        }
        
        $source_folder = $folders[0];
        $correct_folder = $extract_dir . '/' . Config::$plugin_folder;
        
        if (basename($source_folder) !== Config::$plugin_folder) {
            rename($source_folder, $correct_folder);
        } else {
            $correct_folder = $source_folder;
        }
        
        $result = Helpers::create_zip($correct_folder, $final_zip, Config::$plugin_folder);
        Helpers::delete_directory($temp_dir);
        
        if (!$result) Helpers::ajax_error('Failed to create download');
        
        $filename = Config::$plugin_folder . '-v' . $version_slug . '-' . $short_sha . '.zip';
        
        Helpers::ajax_success('Download ready', array(
            'url'      => $upload_dir['baseurl'] . '/' . $filename,
            'filename' => $filename
        ));
    }
    
    /**
     * Save log to transient for persistence across refresh
     */
    public static function handle_save_log() {
        Helpers::verify_ajax_request();
        
        $log = isset($_POST['log']) ? wp_kses_post($_POST['log']) : '';
        
        // Store in transient for 1 hour
        set_transient('hws_git_push_log', $log, HOUR_IN_SECONDS);
        
        Helpers::ajax_success('Log saved');
    }
    
    /**
     * Clear stored log
     */
    public static function handle_clear_log() {
        Helpers::verify_ajax_request();
        
        delete_transient('hws_git_push_log');
        
        Helpers::ajax_success('Log cleared');
    }
    
    /**
     * Check all git-enabled plugins against their GitHub remotes
     */
    public static function handle_check_all_plugin_versions() {
        Helpers::verify_ajax_request();
        
        $results = array();
        
        // Get all plugins
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $all_plugins = get_plugins();
        $registered = Admin_UI::get_registered_plugins();
        
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $slug = Helpers::get_plugin_slug($plugin_file);
            $path = Helpers::get_plugin_path($slug);
            
            // Check if this plugin is registered OR has .git
            $is_registered = isset($registered[$slug]);
            $has_git = Helpers::has_git($path);
            
            if (!$is_registered && !$has_git) {
                continue; // Not tracked
            }
            
            // If has .git but not registered, auto-register it
            if ($has_git && !$is_registered) {
                $status = Git_Operations::get_status($path);
                if (!empty($status['remote_url'])) {
                    $parsed = GitHub_API::parse_remote_url($status['remote_url']);
                    if ($parsed) {
                        Admin_UI::register_plugin($slug, $parsed['owner'] . '/' . $parsed['repo']);
                        $is_registered = true;
                    }
                }
            }
            
            $local_version = $plugin_data['Version'];
            $github_repo = Admin_UI::get_plugin_github_repo($slug);
            $needs_restore = $is_registered && !$has_git;
            
            // If needs restore, return minimal data
            if ($needs_restore) {
                $results[] = array(
                    'slug'            => $slug,
                    'name'            => $plugin_data['Name'],
                    'local_version'   => $local_version,
                    'github_version'  => 'Unknown',
                    'status'          => 'needs_restore',
                    'has_changes'     => false,
                    'needs_restore'   => true,
                    'remote'          => $github_repo ?: 'unknown/unknown',
                    'branch'          => 'main'
                );
                continue;
            }
            
            // Normal flow for plugins with .git
            $status = Git_Operations::get_status($path);
            
            if (empty($status['remote_url'])) {
                continue;
            }
            
            $parsed = GitHub_API::parse_remote_url($status['remote_url']);
            
            if (!$parsed) {
                continue;
            }
            
            $github_version = null;
            $version_status = 'unknown';
            
            // Try to get GitHub version
            $github_version = self::get_github_plugin_version(
                $parsed['owner'], 
                $parsed['repo'], 
                $slug,
                $status['branch'] ?? null
            );
            
            if ($github_version) {
                $compare = version_compare($local_version, $github_version);
                if ($compare > 0) {
                    $version_status = 'needs_push';
                } elseif ($compare < 0) {
                    $version_status = 'behind';
                } else {
                    $version_status = 'current';
                }
            }
            
            $results[] = array(
                'slug'            => $slug,
                'name'            => $plugin_data['Name'],
                'local_version'   => $local_version,
                'github_version'  => $github_version ?: 'Unknown',
                'status'          => $version_status,
                'has_changes'     => $status['has_changes'] ?? false,
                'needs_restore'   => false,
                'remote'          => $parsed['owner'] . '/' . $parsed['repo'],
                'branch'          => $status['branch'] ?? 'main'
            );
        }
        
        Helpers::ajax_success('Version check complete', array(
            'plugins' => $results,
            'count'   => count($results)
        ));
    }
    
    /**
     * Check a single plugin's version against GitHub
     */
    public static function handle_check_single_plugin_version() {
        Helpers::verify_ajax_request();
        
        $plugin_slug = isset($_POST['plugin']) ? sanitize_file_name($_POST['plugin']) : '';
        
        if (empty($plugin_slug)) {
            Helpers::ajax_error('No plugin specified');
        }
        
        $plugin_path = Helpers::get_plugin_path($plugin_slug);
        
        if (!is_dir($plugin_path)) {
            Helpers::ajax_error('Plugin not found');
        }
        
        if (!Helpers::has_git($plugin_path)) {
            Helpers::ajax_error('Plugin does not have git initialized');
        }
        
        // Get local version
        $plugin_file = self::find_plugin_main_file($plugin_path);
        if (!$plugin_file) {
            Helpers::ajax_error('Could not find plugin main file');
        }
        
        $plugin_data = get_plugin_data($plugin_file);
        $local_version = $plugin_data['Version'];
        
        // Get git status
        $status = Git_Operations::get_status($plugin_path);
        
        if (empty($status['remote_url'])) {
            Helpers::ajax_error('No remote configured');
        }
        
        $parsed = GitHub_API::parse_remote_url($status['remote_url']);
        
        if (!$parsed) {
            Helpers::ajax_error('Could not parse remote URL');
        }
        
        // Get GitHub version (pass branch from git status)
        $github_version = self::get_github_plugin_version(
            $parsed['owner'], 
            $parsed['repo'], 
            $plugin_slug,
            $status['branch'] ?? null
        );
        
        $version_status = 'unknown';
        if ($github_version) {
            $compare = version_compare($local_version, $github_version);
            if ($compare > 0) {
                $version_status = 'needs_push';
            } elseif ($compare < 0) {
                $version_status = 'behind';
            } else {
                $version_status = 'current';
            }
        }
        
        Helpers::ajax_success('Check complete', array(
            'slug'           => $plugin_slug,
            'name'           => $plugin_data['Name'],
            'local_version'  => $local_version,
            'github_version' => $github_version ?: 'Unknown',
            'status'         => $version_status,
            'has_changes'    => $status['has_changes'] ?? false,
            'remote'         => $parsed['owner'] . '/' . $parsed['repo']
        ));
    }
    
    /**
     * Get plugin version from GitHub repository
     * 
     * @param string $owner GitHub owner
     * @param string $repo Repository name
     * @param string $plugin_slug Plugin folder name
     * @param string $branch Branch name (optional)
     * @return string|false Version string or false
     */
    private static function get_github_plugin_version($owner, $repo, $plugin_slug, $branch = null) {
        $token = GitHub_API::get_token();
        
        // Branches to try if none specified
        $branches = $branch ? array($branch) : array('main', 'master');
        
        // Build list of possible file paths to check
        // The repo structure could be:
        // 1. Repo root IS the plugin (files at root)
        // 2. Repo contains the plugin in a subfolder
        $possible_paths = array();
        
        foreach ($branches as $br) {
            // Most common: repo root is the plugin, main file = repo-name.php
            $possible_paths[] = array('branch' => $br, 'path' => $repo . '.php');
            
            // Plugin slug as filename at root (when slug differs from repo name)
            if ($plugin_slug !== $repo) {
                $possible_paths[] = array('branch' => $br, 'path' => $plugin_slug . '.php');
            }
            
            // Common generic names at root
            $possible_paths[] = array('branch' => $br, 'path' => 'plugin.php');
            $possible_paths[] = array('branch' => $br, 'path' => 'index.php');
            
            // Nested: plugin in subfolder matching repo name
            $possible_paths[] = array('branch' => $br, 'path' => $repo . '/' . $repo . '.php');
            
            // Nested: plugin in subfolder matching plugin slug
            if ($plugin_slug !== $repo) {
                $possible_paths[] = array('branch' => $br, 'path' => $plugin_slug . '/' . $plugin_slug . '.php');
            }
        }
        
        foreach ($possible_paths as $attempt) {
            $url = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$attempt['branch']}/{$attempt['path']}";
            
            $args = array(
                'timeout' => 10,
                'sslverify' => true,
                'headers' => array(
                    'User-Agent' => Config::$plugin_name . '/' . HWS_GIT_PUSH_VERSION,
                    'Accept' => 'text/plain'
                )
            );
            
            // Add token for private repos
            if (!empty($token)) {
                $args['headers']['Authorization'] = 'Bearer ' . $token;
            }
            
            $response = wp_remote_get($url, $args);
            
            if (is_wp_error($response)) {
                continue;
            }
            
            $code = wp_remote_retrieve_response_code($response);
            
            if ($code !== 200) {
                continue;
            }
            
            $content = wp_remote_retrieve_body($response);
            
            // Look for WordPress plugin header Version:
            if (preg_match('/^\s*\*?\s*Version:\s*(.+)$/mi', $content, $matches)) {
                return trim($matches[1]);
            }
        }
        
        // Fallback: Use GitHub API to list repo contents and find PHP files
        return self::get_github_version_via_api($owner, $repo, $branches, $token);
    }
    
    /**
     * Fallback: Use GitHub API to find plugin main file
     */
    private static function get_github_version_via_api($owner, $repo, $branches, $token) {
        foreach ($branches as $branch) {
            // Get repo contents
            $url = Config::$github_api_url . "/repos/{$owner}/{$repo}/contents?ref={$branch}";
            
            $args = array(
                'timeout' => 10,
                'headers' => array(
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => Config::$plugin_name . '/' . HWS_GIT_PUSH_VERSION
                )
            );
            
            if (!empty($token)) {
                $args['headers']['Authorization'] = 'Bearer ' . $token;
            }
            
            $response = wp_remote_get($url, $args);
            
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                continue;
            }
            
            $contents = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!is_array($contents)) {
                continue;
            }
            
            // Find PHP files at root level
            $php_files = array();
            foreach ($contents as $item) {
                if ($item['type'] === 'file' && preg_match('/\.php$/i', $item['name'])) {
                    $php_files[] = $item;
                }
            }
            
            // Check each PHP file for plugin header
            foreach ($php_files as $file) {
                $file_url = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/" . $file['name'];
                
                $file_args = array(
                    'timeout' => 10,
                    'headers' => array(
                        'User-Agent' => Config::$plugin_name . '/' . HWS_GIT_PUSH_VERSION
                    )
                );
                
                if (!empty($token)) {
                    $file_args['headers']['Authorization'] = 'Bearer ' . $token;
                }
                
                $file_response = wp_remote_get($file_url, $file_args);
                
                if (is_wp_error($file_response) || wp_remote_retrieve_response_code($file_response) !== 200) {
                    continue;
                }
                
                $content = wp_remote_retrieve_body($file_response);
                
                // Check if this file has Plugin Name header (indicating it's the main plugin file)
                if (strpos($content, 'Plugin Name:') !== false) {
                    // Found the main plugin file - get version
                    if (preg_match('/^\s*\*?\s*Version:\s*(.+)$/mi', $content, $matches)) {
                        return trim($matches[1]);
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Find the main plugin file in a plugin directory
     */
    private static function find_plugin_main_file($plugin_path) {
        $slug = basename($plugin_path);
        
        // Try common patterns
        $patterns = array(
            $plugin_path . '/' . $slug . '.php',
            $plugin_path . '/plugin.php',
            $plugin_path . '/index.php'
        );
        
        foreach ($patterns as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                if (strpos($content, 'Plugin Name:') !== false) {
                    return $file;
                }
            }
        }
        
        // Scan directory for any PHP file with plugin header
        $files = glob($plugin_path . '/*.php');
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if (strpos($content, 'Plugin Name:') !== false) {
                return $file;
            }
        }
        
        return false;
    }
    
    /*
    |--------------------------------------------------------------------------
    | Rename Plugin Handler
    |--------------------------------------------------------------------------
    */
    
    public static function handle_rename_plugin() {
        Helpers::verify_ajax_request();
        
        $old_slug = isset($_POST['plugin']) ? sanitize_file_name($_POST['plugin']) : '';
        $new_slug = isset($_POST['new_name']) ? sanitize_file_name($_POST['new_name']) : '';
        
        if (empty($old_slug)) Helpers::ajax_error('No plugin selected.');
        if (empty($new_slug)) Helpers::ajax_error('Enter a new folder name.');
        if (!preg_match('/^[a-z0-9\-]+$/', $new_slug)) Helpers::ajax_error('Invalid name. Use lowercase, numbers, hyphens only.');
        if ($old_slug === $new_slug) Helpers::ajax_error('Names are the same.');
        
        $old_path = WP_PLUGIN_DIR . '/' . $old_slug;
        $new_path = WP_PLUGIN_DIR . '/' . $new_slug;
        
        if (!is_dir($old_path)) Helpers::ajax_error('Source folder not found.');
        if (file_exists($new_path)) Helpers::ajax_error('Target folder already exists.');
        
        // Deactivate if active
        $active_plugins = get_option('active_plugins', array());
        $was_active = false;
        $old_plugin_file = '';
        
        foreach ($active_plugins as $plugin) {
            if (strpos($plugin, $old_slug . '/') === 0) {
                $old_plugin_file = $plugin;
                $was_active = true;
                deactivate_plugins($plugin);
                break;
            }
        }
        
        if (!@rename($old_path, $new_path)) {
            if ($was_active && $old_plugin_file) {
                activate_plugin($old_plugin_file);
            }
            Helpers::ajax_error('Rename failed. Check permissions.');
        }
        
        $msg = 'Renamed "' . $old_slug . '" → "' . $new_slug . '"';
        if ($was_active) $msg .= '. Plugin deactivated - please reactivate.';
        
        Helpers::ajax_success($msg, array('new_slug' => $new_slug));
    }
    
    /*
    |--------------------------------------------------------------------------
    | Version Editor Handler
    |--------------------------------------------------------------------------
    */
    
    public static function handle_update_plugin_version() {
        Helpers::verify_ajax_request();
        
        $plugin_slug = isset($_POST['plugin']) ? sanitize_file_name($_POST['plugin']) : '';
        $new_version = isset($_POST['version']) ? sanitize_text_field($_POST['version']) : '';
        
        if (empty($plugin_slug)) Helpers::ajax_error('No plugin selected.');
        if (empty($new_version) || !preg_match('/^\d+\.\d+\.?\d*$/', $new_version)) Helpers::ajax_error('Invalid version format. Use: 1.0.0');
        
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_slug;
        if (!is_dir($plugin_path)) Helpers::ajax_error('Plugin not found.');
        
        $main_file = self::find_plugin_main_file($plugin_path);
        if (!$main_file || !is_writable($main_file)) Helpers::ajax_error('Cannot write to plugin file.');
        
        $content = file_get_contents($main_file);
        $updated = preg_replace('/^(\s*\*?\s*Version:\s*)[\d\.]+/mi', '${1}' . $new_version, $content, 1, $count);
        
        // Also update define constant if exists
        $constant = strtoupper(str_replace('-', '_', $plugin_slug)) . '_VERSION';
        $updated = preg_replace("/define\s*\(\s*['\"]" . $constant . "['\"]\s*,\s*['\"][\d\.]+['\"]\s*\)/i",
            "define('" . $constant . "', '" . $new_version . "')", $updated);
        
        if ($count === 0) Helpers::ajax_error('Version header not found in plugin file.');
        if (file_put_contents($main_file, $updated) === false) Helpers::ajax_error('Failed to write file.');
        
        Helpers::ajax_success('Version updated to ' . $new_version, array('new_version' => $new_version));
    }
    
    /*
    |--------------------------------------------------------------------------
    | Regenerate Secret Handler
    |--------------------------------------------------------------------------
    */
    
    public static function handle_regenerate_secret() {
        Helpers::verify_ajax_request();
        
        $new_key = wp_generate_password(32, false);
        update_option('hws_git_push_secret_key', $new_key);
        
        Helpers::ajax_success('Secret key regenerated.', array('new_key' => $new_key));
    }
}
