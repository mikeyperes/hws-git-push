<?php
/**
 * Admin Page Template
 *
 * @package HWS_Git_Push
 */

namespace HWS_Git_Push;

if (!defined('ABSPATH')) exit;

$plugins = Admin_UI::get_plugins_with_status();
$git_plugins = Admin_UI::get_git_plugins();
$token_status = Admin_UI::get_token_status();
$php_user = Admin_UI::get_php_user();
$plugins_dir = Admin_UI::get_plugins_dir();
$plugin_info = Admin_UI::get_plugin_info();
?>

<div class="wrap hws-git-push-admin">
    <h1><?php echo esc_html(Config::$page_title); ?> <small>v<?php echo esc_html(HWS_GIT_PUSH_VERSION); ?></small></h1>
    
    <!-- System Status -->
    <div class="hws-status-bar">
        <span id="hws-system-status">Checking system...</span>
        <button type="button" id="hws-check-system" class="button button-small">🔄 Recheck</button>
    </div>

    <!-- ================================================================== -->
    <!-- DASHBOARD - Version Status for All Git Plugins                    -->
    <!-- ================================================================== -->
    <div class="hws-section hws-section-dashboard">
        <h2>📊 Plugin Sync Dashboard 
            <button type="button" id="hws-refresh-dashboard" class="button button-small" style="margin-left: 10px;">🔄 Refresh All</button>
            <span id="hws-dashboard-status" style="margin-left: 10px; font-weight: normal; font-size: 13px;"></span>
        </h2>
        <p class="description">Compares your local plugin versions with GitHub. Plugins marked <strong style="color: #d63638;">Needs Push</strong> have local changes that haven't been pushed yet.</p>
        
        <div id="hws-dashboard-loading" style="padding: 20px; text-align: center;">
            <span class="spinner is-active" style="float: none;"></span> Loading plugin status...
        </div>
        
        <table id="hws-dashboard-table" class="widefat striped" style="display: none;">
            <thead>
                <tr>
                    <th>Plugin</th>
                    <th>Local Version</th>
                    <th>GitHub Version</th>
                    <th>Status</th>
                    <th>Uncommitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="hws-dashboard-body">
                <!-- Populated by JavaScript -->
            </tbody>
        </table>
        
        <div id="hws-dashboard-empty" style="display: none; padding: 20px; text-align: center; color: #666;">
            <p>No git-enabled plugins found. Use "Initialize Git Repository" below to set up a plugin.</p>
        </div>
        
        <div id="hws-dashboard-summary" style="display: none; margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
            <strong>Summary:</strong>
            <span id="hws-summary-text"></span>
        </div>
    </div>

    <!-- ================================================================== -->
    <!-- OUTPUT LOG - Right below dashboard for visibility                 -->
    <!-- ================================================================== -->
    <div class="hws-section hws-section-log">
        <h2>📋 Output Log <button type="button" id="hws-clear-log" class="button button-small">Clear</button></h2>
        <pre id="hws-log-output" class="hws-log-output">Ready. Select a plugin to see repository info.</pre>
    </div>

    <!-- ================================================================== -->
    <!-- CENTRALIZED SETTINGS - API Key (One place for all functionality) -->
    <!-- ================================================================== -->
    <div class="hws-section hws-section-primary">
        <h2>⚙️ Settings</h2>
        <p class="description">Configure your GitHub API token here. This token is used for all plugin functionality.</p>
        
        <table class="form-table">
            <tr>
                <th><label for="hws-github-token">GitHub Personal Access Token</label></th>
                <td>
                    <input type="password" id="hws-github-token" class="regular-text" 
                           placeholder="ghp_xxxxxxxxxxxx"
                           value="<?php echo $token_status['configured'] ? '••••••••••••' : ''; ?>">
                    <button type="button" id="hws-save-token" class="button button-primary">Save Token</button>
                    <button type="button" id="hws-toggle-token" class="button">👁️</button>
                    <span id="hws-token-status" style="margin-left: 10px;">
                        <?php if ($token_status['configured']): ?>
                            <span style="color: green;">✓ Token configured (<?php echo esc_html($token_status['masked']); ?>)</span>
                        <?php else: ?>
                            <span style="color: #d63638;">⚠️ No token configured</span>
                        <?php endif; ?>
                    </span>
                    <p class="description" style="margin-top: 8px;">
                        <a href="https://github.com/settings/tokens" target="_blank" rel="noopener">Generate a token on GitHub ↗</a> → 
                        Select scopes: <code>repo</code> (full access to private repositories)
                    </p>
                </td>
            </tr>
        </table>
    </div>

    <!-- Quick Push -->
    <div class="hws-section">
        <h2>🚀 Quick Push</h2>
        <?php if (empty($git_plugins)): ?>
            <p><em>No plugins with git initialized. Use "Initialize Repository" below.</em></p>
        <?php else: ?>
            <table class="form-table">
                <tr>
                    <th><label for="hws-push-plugin">Plugin</label></th>
                    <td>
                        <select id="hws-push-plugin" class="regular-text">
                            <option value="">-- Select plugin --</option>
                            <?php foreach ($git_plugins as $plugin): ?>
                                <option value="<?php echo esc_attr($plugin['slug']); ?>" 
                                        data-version="<?php echo esc_attr($plugin['version']); ?>"
                                        data-remote="<?php echo esc_attr($plugin['status']['remote'] ?? ''); ?>">
                                    <?php echo esc_html($plugin['name']); ?> (v<?php echo esc_html($plugin['version']); ?>) — <?php echo esc_html($plugin['slug']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="hws-commit-message">Commit Message</label></th>
                    <td><input type="text" id="hws-commit-message" class="regular-text" value="<?php echo esc_attr(Config::$default_commit_message); ?>"></td>
                </tr>
                <tr>
                    <th>Options</th>
                    <td><label><input type="checkbox" id="hws-force-push"> Force push</label></td>
                </tr>
                <tr>
                    <th>Actions</th>
                    <td>
                        <button type="button" id="hws-push-btn" class="button button-primary">📤 Push to GitHub</button>
                        <span id="hws-push-status" style="margin-left: 10px;"></span>
                    </td>
                </tr>
            </table>
        <?php endif; ?>
    </div>

    <!-- Upload & Auto-Push Plugin -->
    <div class="hws-section">
        <h2>📦 Upload & Auto-Push Plugin</h2>
        <p class="description">Upload or fetch a plugin zip, auto-install, and push to git.</p>
        
        <table class="form-table">
            <tr>
                <th>Upload Zip</th>
                <td>
                    <input type="file" id="hws-upload-plugin-zip" accept=".zip,application/zip,application/x-zip-compressed">
                    <button type="button" id="hws-upload-install" class="button">📤 Upload & Install</button>
                    <span id="hws-upload-spinner" class="spinner"></span>
                    <span id="hws-upload-status" style="margin-left: 10px;"></span>
                </td>
            </tr>
            <tr>
                <th>Fetch from URL</th>
                <td>
                    <input type="url" id="hws-fetch-url" class="large-text" placeholder="https://example.com/plugin.zip">
                    <button type="button" id="hws-fetch-install" class="button" style="margin-top: 5px;">⬇️ Fetch & Install</button>
                    <span id="hws-fetch-spinner" class="spinner"></span>
                    <span id="hws-fetch-status" style="margin-left: 10px;"></span>
                </td>
            </tr>
        </table>
    </div>

    <!-- Initialize Repository -->
    <div class="hws-section">
        <h2>📁 Initialize Git Repository</h2>
        <table class="form-table">
            <tr>
                <th><label for="hws-init-plugin">Plugin</label></th>
                <td>
                    <select id="hws-init-plugin" class="regular-text">
                        <option value="">-- Select plugin --</option>
                        <?php foreach ($plugins as $plugin): ?>
                            <option value="<?php echo esc_attr($plugin['slug']); ?>" 
                                    data-version="<?php echo esc_attr($plugin['version']); ?>">
                                <?php echo esc_html($plugin['name']); ?> (v<?php echo esc_html($plugin['version']); ?>) — <?php echo esc_html($plugin['slug']); ?>
                                <?php if ($plugin['has_git']): ?> 🔄<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="hws-github-repo">GitHub Repository</label></th>
                <td>
                    <select id="hws-github-repo" class="regular-text" style="width: 300px;">
                        <option value="">-- Click "Fetch Repos" --</option>
                    </select>
                    <button type="button" id="hws-fetch-repos" class="button">🔄 Fetch Repos</button>
                    <span id="hws-repos-status" style="margin-left: 10px;"></span>
                    <p class="description">Uses your saved API token above to fetch your repositories.</p>
                </td>
            </tr>
            <tr>
                <th><label for="hws-init-message">Commit Message</label></th>
                <td><input type="text" id="hws-init-message" class="regular-text" value="Initial commit"></td>
            </tr>
            <tr>
                <th>Actions</th>
                <td><button type="button" id="hws-init-btn" class="button button-primary">⚡ Initialize & Push</button></td>
            </tr>
        </table>
    </div>

    <!-- Git Backup/Restore Section -->
    <div class="hws-section">
        <h2>💾 Git Config Backup & Restore</h2>
        <p class="description">Backup and restore git configurations. Download/upload backups to transfer between WordPress installs.</p>
        
        <table class="form-table">
            <tr>
                <th><label for="hws-backup-plugin-select">Select Plugin</label></th>
                <td>
                    <select id="hws-backup-plugin-select" class="regular-text">
                        <option value="">-- Select any plugin --</option>
                        <?php foreach ($plugins as $plugin): ?>
                            <option value="<?php echo esc_attr($plugin['slug']); ?>">
                                <?php echo esc_html($plugin['name']); ?> — <?php echo esc_html($plugin['slug']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr id="hws-backup-info-row" style="display: none;">
                <th>Backup Status</th>
                <td id="hws-backup-info"><em>Loading...</em></td>
            </tr>
            <tr>
                <th>Actions</th>
                <td>
                    <button type="button" id="hws-backup-single" class="button" disabled>💾 Backup This Plugin</button>
                    <button type="button" id="hws-restore-single" class="button" disabled style="margin-left: 10px;">📥 Restore This Plugin</button>
                    <span id="hws-backup-spinner" class="spinner"></span>
                    <span id="hws-backup-status" style="margin-left: 10px;"></span>
                </td>
            </tr>
            <tr id="hws-backup-download-row" style="display: none;">
                <th>Download Backup</th>
                <td>
                    <button type="button" id="hws-download-backup" class="button">⬇️ Download Backup File</button>
                    <p class="description">Download the latest backup to transfer to another WordPress install.</p>
                </td>
            </tr>
            <tr>
                <th>Upload Backup</th>
                <td>
                    <input type="file" id="hws-upload-backup-file" accept=".tar.gz,.gz,.tar,application/gzip,application/x-gzip,application/x-tar">
                    <button type="button" id="hws-upload-backup" class="button" style="margin-left: 10px;">📤 Upload & Restore</button>
                    <span id="hws-upload-backup-spinner" class="spinner"></span>
                    <p class="description">Upload a backup file (.tar.gz) from another WordPress install.</p>
                </td>
            </tr>
            <tr>
                <th>Backup All</th>
                <td>
                    <button type="button" id="hws-backup-all" class="button">💾 Backup All Git Plugins</button>
                    <p class="description">Creates a backup of git config for ALL plugins with git repos.</p>
                </td>
            </tr>
        </table>
    </div>

    <!-- Rename Plugin Folder -->
    <div class="hws-section">
        <h2>✏️ Rename Plugin Folder</h2>
        <table class="form-table">
            <tr>
                <th><label for="hws-rename-plugin-select">Select Plugin</label></th>
                <td>
                    <select id="hws-rename-plugin-select" class="regular-text">
                        <option value="">-- Select a plugin --</option>
                        <?php foreach ($plugins as $plugin): ?>
                            <option value="<?php echo esc_attr($plugin['slug']); ?>">
                                <?php echo esc_html($plugin['name']); ?> — <?php echo esc_html($plugin['slug']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr id="hws-rename-fields" style="display: none;">
                <th>Current → New</th>
                <td>
                    <code id="hws-current-folder-name" style="background: #fff3cd; padding: 5px 10px; margin-right: 10px;"></code>
                    <span style="margin-right: 10px;">→</span>
                    <input type="text" id="hws-new-folder-name" class="regular-text" style="width: 200px;" placeholder="new-folder-name">
                    <button type="button" id="hws-rename-button" class="button" style="margin-left: 10px;">✏️ Rename</button>
                    <span id="hws-rename-spinner" class="spinner"></span>
                    <span id="hws-rename-status" style="margin-left: 10px;"></span>
                </td>
            </tr>
        </table>
    </div>

    <!-- Version Editor -->
    <div class="hws-section">
        <h2>📝 Plugin Version Editor</h2>
        <p class="description">Update plugin version info directly.</p>
        <table class="form-table">
            <tr>
                <th><label for="hws-version-plugin-select">Select Plugin</label></th>
                <td>
                    <select id="hws-version-plugin-select" class="regular-text">
                        <option value="">-- Select a plugin --</option>
                        <?php foreach ($plugins as $plugin): ?>
                            <option value="<?php echo esc_attr($plugin['slug']); ?>" data-version="<?php echo esc_attr($plugin['version']); ?>">
                                <?php echo esc_html($plugin['name']); ?> (v<?php echo esc_html($plugin['version']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr id="hws-version-info-row" style="display: none;">
                <th>Current Version</th>
                <td id="hws-current-version-display"><em>Select a plugin</em></td>
            </tr>
            <tr id="hws-version-edit-row" style="display: none;">
                <th>New Version</th>
                <td>
                    <input type="text" id="hws-new-version" class="regular-text" style="width: 120px;" placeholder="1.0.0">
                    <button type="button" id="hws-update-version" class="button">🔄 Update Version</button>
                    <span id="hws-version-edit-spinner" class="spinner"></span>
                    <span id="hws-version-edit-status" style="margin-left: 10px;"></span>
                </td>
            </tr>
        </table>
    </div>

    <!-- Troubleshooting -->
    <div class="hws-section">
        <h2>🔧 Troubleshooting Commands</h2>
        <table class="form-table">
            <tr>
                <th><label for="hws-troubleshoot-plugin">Select Plugin</label></th>
                <td>
                    <select id="hws-troubleshoot-plugin" class="regular-text">
                        <option value="">-- Select for specific commands --</option>
                        <?php foreach ($plugins as $plugin): ?>
                            <option value="<?php echo esc_attr($plugin['slug']); ?>"><?php echo esc_html($plugin['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="hws-troubleshoot-manual">Or Enter Folder</label></th>
                <td><input type="text" id="hws-troubleshoot-manual" class="regular-text" placeholder="plugin-folder-name"></td>
            </tr>
        </table>
        <?php include HWS_GIT_PUSH_DIR . 'templates/partials/troubleshooting-commands.php'; ?>
    </div>

    <!-- Plugin Information -->
    <div class="hws-section">
        <h2>📦 Plugin Information</h2>
        
        <div class="hws-info-box hws-info-success">
            <strong>Current Version:</strong> <span style="font-size: 16px; font-weight: bold;"><?php echo esc_html(HWS_GIT_PUSH_VERSION); ?></span>
            &nbsp;|&nbsp;
            <strong>Latest:</strong> <span id="hws-latest-version" style="font-size: 16px; font-weight: bold;">—</span>
            <div id="hws-version-comparison" style="margin-top: 5px;"></div>
        </div>
        
        <div class="hws-info-box">
            <strong>🔄 Update Actions</strong>
            <div style="margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" id="hws-force-update-check" class="button">🔍 Check for Updates</button>
                <button type="button" id="hws-direct-update" class="button button-primary" disabled>⬆️ Update from GitHub</button>
                <button type="button" id="hws-download-plugin-zip" class="button">⬇️ Download .zip</button>
            </div>
            <div id="hws-update-status" style="margin-top: 10px;"></div>
        </div>
        
        <div class="hws-info-box hws-info-warning">
            <strong>📜 Version History (Download Older Versions)</strong>
            <p style="font-size: 12px; color: #666; margin: 5px 0 10px;">Fetches recent commits from GitHub and extracts the plugin version from each. Download any previous version for rollbacks.</p>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <select id="hws-version-select" style="min-width: 320px;">
                    <option value="">-- Click "Load Versions" --</option>
                </select>
                <button type="button" id="hws-load-versions" class="button">🔄 Load Versions</button>
                <button type="button" id="hws-download-version" class="button" disabled>⬇️ Download Selected</button>
            </div>
            <div id="hws-version-status" style="margin-top: 10px;"></div>
        </div>
        
        <table class="widefat" style="margin-top: 15px;">
            <tr><td width="120"><strong>Plugin Name:</strong></td><td><?php echo esc_html($plugin_info['name']); ?></td></tr>
            <tr><td><strong>Folder:</strong></td><td><code><?php echo esc_html($plugin_info['folder']); ?></code></td></tr>
            <tr><td><strong>GitHub:</strong></td><td><a href="https://github.com/<?php echo esc_attr($plugin_info['github_repo']); ?>" target="_blank" rel="noopener"><?php echo esc_html($plugin_info['github_repo']); ?> ↗</a></td></tr>
            <tr><td><strong>Author:</strong></td><td><a href="<?php echo esc_url($plugin_info['author']['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($plugin_info['author']['name']); ?> ↗</a></td></tr>
        </table>
    </div>
</div>
