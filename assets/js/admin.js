/**
 * HWS Git Push - Admin JavaScript v3.1.0
 * 
 * Features:
 * - Persistent log across page refresh
 * - Version info in all log entries
 * - Centralized API token usage
 */

(function($) {
    'use strict';

    var config = { 
        ajaxUrl: hwsGitPush.ajaxUrl, 
        nonce: hwsGitPush.nonce,
        version: hwsGitPush.version
    };

    /*
    |--------------------------------------------------------------------------
    | Utility Functions
    |--------------------------------------------------------------------------
    */

    // AJAX helper
    function ajax(action, data, opts) {
        data = data || {}; opts = opts || {};
        data.action = action; data.nonce = config.nonce;
        return $.ajax({ url: config.ajaxUrl, type: 'POST', data: data, timeout: opts.timeout || 30000 });
    }

    // Status helper
    function showStatus($el, msg, type) {
        var colors = { success: 'green', error: 'red', loading: '#666' };
        var prefix = { success: '✅ ', error: '❌ ', loading: '⏳ ' };
        $el.html('<span style="color:' + (colors[type]||'#666') + ';">' + (prefix[type]||'') + msg + '</span>');
    }

    /*
    |--------------------------------------------------------------------------
    | Persistent Log System
    |--------------------------------------------------------------------------
    */

    // Append to log with timestamp and optional version info
    function appendLog(message, pluginName, pluginVersion) {
        var $log = $('#hws-log-output');
        var timestamp = new Date().toLocaleTimeString();
        var header = '[' + timestamp + ']';
        
        // Add plugin version info if provided
        if (pluginName && pluginVersion) {
            header += ' [' + pluginName + ' v' + pluginVersion + ']';
        }
        
        var currentLog = $log.text();
        if (currentLog === 'Ready. Select a plugin to see repository info.') {
            currentLog = '';
        }
        
        var newLog = currentLog + (currentLog ? '\n' : '') + header + '\n' + message;
        $log.text(newLog);
        
        // Auto-scroll to bottom
        $log.scrollTop($log[0].scrollHeight);
        
        // Save to server for persistence
        saveLog(newLog);
    }

    // Save log to server (persists across refresh)
    function saveLog(logContent) {
        ajax('hws_save_log', { log: logContent });
    }

    // Clear log
    function clearLog() {
        $('#hws-log-output').text('Ready. Select a plugin to see repository info.');
        ajax('hws_clear_log');
    }

    // Restore log on page load
    function restoreLog() {
        if (hwsGitPush.storedLog && hwsGitPush.storedLog.trim()) {
            $('#hws-log-output').text(hwsGitPush.storedLog);
            var $log = $('#hws-log-output');
            $log.scrollTop($log[0].scrollHeight);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | System Check
    |--------------------------------------------------------------------------
    */

    function checkSystem() {
        $('#hws-system-status').text('Checking...');
        ajax('hws_system_check').done(function(r) {
            if (r.success && r.data.git.available) {
                $('#hws-system-status').html('<span style="color:green;">✓ ' + r.data.git.version + '</span>');
            } else {
                $('#hws-system-status').html('<span style="color:red;">✗ Git not available</span>');
            }
        }).fail(function() { 
            $('#hws-system-status').html('<span style="color:red;">✗ Check failed</span>'); 
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Initialize on Document Ready
    |--------------------------------------------------------------------------
    */

    $(document).ready(function() {
        // Restore persisted log
        restoreLog();
        
        // System check
        checkSystem();
        $('#hws-check-system').on('click', checkSystem);

        // Clear log button
        $('#hws-clear-log').on('click', clearLog);

        // Auto-check HWS Git Push version on page load
        setTimeout(function() {
            ajax('hws_check_plugin_version').done(function(r) {
                if (r.success) {
                    $('#hws-latest-version').text(r.data.version);
                    if (r.data.update_available) {
                        $('#hws-latest-version').css('color', '#d63638');
                        $('#hws-version-comparison').html('<span style="color:#d63638; font-weight: bold;">⚠️ Update: v' + r.data.current + ' → v' + r.data.version + '</span>');
                        $('#hws-direct-update').prop('disabled', false);
                    } else {
                        $('#hws-latest-version').css('color', '#00a32a');
                        $('#hws-version-comparison').html('<span style="color:#00a32a;">✓ Latest</span>');
                    }
                }
            });
        }, 500);

        /*
        |----------------------------------------------------------------------
        | Dashboard - Version Comparison
        |----------------------------------------------------------------------
        */

        // Load dashboard on page load
        loadDashboard();

        // Refresh dashboard button
        $('#hws-refresh-dashboard').on('click', function() {
            loadDashboard();
        });

        function loadDashboard() {
            var $loading = $('#hws-dashboard-loading');
            var $table = $('#hws-dashboard-table');
            var $empty = $('#hws-dashboard-empty');
            var $summary = $('#hws-dashboard-summary');
            var $status = $('#hws-dashboard-status');
            
            $loading.show();
            $table.hide();
            $empty.hide();
            $summary.hide();
            $status.html('<span style="color: #666;">⏳ Checking versions...</span>');
            
            ajax('hws_check_all_plugin_versions', {}, { timeout: 120000 }).done(function(r) {
                $loading.hide();
                
                if (!r.success) {
                    $status.html('<span style="color: red;">❌ ' + (r.data.message || 'Error') + '</span>');
                    return;
                }
                
                var plugins = r.data.plugins;
                
                if (!plugins || plugins.length === 0) {
                    $empty.show();
                    $status.html('<span style="color: #666;">No registered plugins</span>');
                    return;
                }
                
                // Build table
                var $tbody = $('#hws-dashboard-body');
                $tbody.empty();
                
                var needsPush = 0;
                var behind = 0;
                var current = 0;
                var unknown = 0;
                var withChanges = 0;
                var needsRestore = 0;
                
                $.each(plugins, function(i, plugin) {
                    var statusHtml = '';
                    var statusClass = '';
                    var actionsHtml = '';
                    
                    // Check if needs restore (registered but no .git)
                    if (plugin.needs_restore) {
                        statusHtml = '<span style="color: #d63638; font-weight: bold;">⚠️ Needs Restore</span>';
                        statusClass = 'hws-row-needs-restore';
                        needsRestore++;
                        actionsHtml = '<button type="button" class="button button-primary button-small hws-dash-restore" data-plugin="' + escapeHtml(plugin.slug) + '">🔧 Restore Git</button> ' +
                                      '<button type="button" class="button button-small hws-dash-remove" data-plugin="' + escapeHtml(plugin.slug) + '" title="Remove from dashboard">✕</button>';
                    } else {
                        switch (plugin.status) {
                            case 'needs_push':
                                statusHtml = '<span style="color: #d63638; font-weight: bold;">⬆️ Needs Push</span>';
                                statusClass = 'hws-row-needs-push';
                                needsPush++;
                                break;
                            case 'behind':
                                statusHtml = '<span style="color: #dba617; font-weight: bold;">⬇️ Behind</span>';
                                statusClass = 'hws-row-behind';
                                behind++;
                                break;
                            case 'current':
                                statusHtml = '<span style="color: #00a32a;">✓ Up to date</span>';
                                current++;
                                break;
                            default:
                                statusHtml = '<span style="color: #666;">❓ Unknown</span>';
                                unknown++;
                        }
                        
                        actionsHtml = '<div style="display: flex; gap: 5px; align-items: center; flex-wrap: wrap;">' +
                                      '<input type="text" class="hws-dash-commit-msg" data-plugin="' + escapeHtml(plugin.slug) + '" placeholder="Commit message" style="width: 140px; font-size: 12px; padding: 3px 6px;">' +
                                      '<button type="button" class="button button-primary button-small hws-dash-push" data-plugin="' + escapeHtml(plugin.slug) + '" data-version="' + escapeHtml(plugin.local_version) + '" data-name="' + escapeHtml(plugin.name) + '" data-remote="' + escapeHtml(plugin.remote) + '">Push</button> ' +
                                      '<button type="button" class="button button-small hws-dash-check" data-plugin="' + escapeHtml(plugin.slug) + '" title="Refresh">🔄</button> ' +
                                      '<button type="button" class="button button-small hws-dash-remove" data-plugin="' + escapeHtml(plugin.slug) + '" title="Remove from dashboard">✕</button>' +
                                      '</div>';
                    }
                    
                    var changesHtml = plugin.needs_restore 
                        ? '<span style="color: #666;">N/A</span>'
                        : (plugin.has_changes 
                            ? '<span style="color: #d63638;">⚠️ Yes</span>' 
                            : '<span style="color: #00a32a;">✓ No</span>');
                    
                    if (plugin.has_changes && !plugin.needs_restore) withChanges++;
                    
                    var githubVersionHtml = plugin.needs_restore 
                        ? '<span style="color: #666;">—</span>'
                        : '<code style="font-size: 14px;">' + (plugin.github_version !== 'Unknown' ? 'v' : '') + escapeHtml(plugin.github_version) + '</code>';
                    
                    var row = '<tr class="' + statusClass + '">' +
                        '<td><strong>' + escapeHtml(plugin.name) + '</strong><br>' +
                            '<code style="font-size: 11px;">' + escapeHtml(plugin.slug) + '</code><br>' +
                            '<a href="https://github.com/' + escapeHtml(plugin.remote) + '" target="_blank" rel="noopener" style="font-size: 11px;">' + escapeHtml(plugin.remote) + ' ↗</a>' +
                        '</td>' +
                        '<td><code style="font-size: 14px; font-weight: bold;">v' + escapeHtml(plugin.local_version) + '</code></td>' +
                        '<td>' + githubVersionHtml + '</td>' +
                        '<td>' + statusHtml + '</td>' +
                        '<td>' + changesHtml + '</td>' +
                        '<td>' + actionsHtml + '</td>' +
                    '</tr>';
                    
                    $tbody.append(row);
                });
                
                $table.show();
                
                // Summary
                var summaryParts = [];
                if (needsRestore > 0) summaryParts.push('<span style="color: #d63638; font-weight: bold;">' + needsRestore + ' need restore</span>');
                if (needsPush > 0) summaryParts.push('<span style="color: #d63638; font-weight: bold;">' + needsPush + ' need push</span>');
                if (behind > 0) summaryParts.push('<span style="color: #dba617;">' + behind + ' behind</span>');
                if (current > 0) summaryParts.push('<span style="color: #00a32a;">' + current + ' up to date</span>');
                if (unknown > 0) summaryParts.push('<span style="color: #666;">' + unknown + ' unknown</span>');
                if (withChanges > 0) summaryParts.push('<span style="color: #d63638;">' + withChanges + ' with uncommitted changes</span>');
                
                $('#hws-summary-text').html(summaryParts.join(' &nbsp;|&nbsp; '));
                $summary.show();
                
                // Status
                if (needsRestore > 0 || needsPush > 0 || withChanges > 0) {
                    $status.html('<span style="color: #d63638;">⚠️ ' + (needsRestore + needsPush + withChanges) + ' plugin(s) need attention</span>');
                } else {
                    $status.html('<span style="color: #00a32a;">✓ All plugins synced</span>');
                }
                
                appendLog('Dashboard loaded: ' + plugins.length + ' plugins checked\n' +
                         '  Needs Restore: ' + needsRestore + '\n' +
                         '  Needs Push: ' + needsPush + '\n' +
                         '  Behind: ' + behind + '\n' +
                         '  Up to date: ' + current + '\n' +
                         '  Uncommitted changes: ' + withChanges);
                
            }).fail(function(xhr, status, error) {
                $loading.hide();
                $status.html('<span style="color: red;">❌ Failed to load: ' + error + '</span>');
                appendLog('Dashboard load failed: ' + error);
            });
        }

        // Dashboard push button
        $(document).on('click', '.hws-dash-push', function() {
            var $btn = $(this);
            var plugin = $btn.data('plugin');
            var version = $btn.data('version');
            var name = $btn.data('name');
            var remote = $btn.data('remote') || '';
            
            // Get commit message from input field
            var $msgInput = $('.hws-dash-commit-msg[data-plugin="' + plugin + '"]');
            var commitMsg = $msgInput.val().trim();
            
            // Require commit message
            if (!commitMsg) {
                $msgInput.css('border-color', 'red').focus();
                appendLog('⚠️ Please enter a commit message', name, version);
                return;
            }
            $msgInput.css('border-color', '');
            
            $btn.prop('disabled', true).text('...');
            appendLog('Pushing: ' + commitMsg, name, version);
            
            ajax('hws_push_plugin', { 
                plugin: plugin, 
                message: commitMsg,
                force: 'true'
            }, { timeout: 120000 }).done(function(r) {
                if (r.success) {
                    var logContent = r.data.log || 'Push complete';
                    // Add GitHub links
                    if (remote || r.data.remote) {
                        var repoUrl = 'https://github.com/' + (r.data.remote || remote);
                        logContent += '\n\n════════════════════════════════════════════\n';
                        logContent += '🔗 QUICK LINKS:\n';
                        logContent += '   Repository: ' + repoUrl + '\n';
                        logContent += '   Commits:    ' + repoUrl + '/commits\n';
                        logContent += '   Latest:     ' + repoUrl + '/commit/HEAD\n';
                        logContent += '════════════════════════════════════════════';
                    }
                    appendLog(logContent, name, version);
                    $msgInput.val(''); // Clear input on success
                    // Refresh just this row
                    refreshSinglePlugin(plugin, $btn.closest('tr'));
                } else {
                    // Show actual error with log
                    var errorMsg = r.data.message || r.data || 'Unknown error';
                    var errorLog = r.data.log || '';
                    appendLog('❌ Push failed: ' + errorMsg + (errorLog ? '\n' + errorLog : ''), name, version);
                    $btn.prop('disabled', false).text('Push');
                }
            }).fail(function(xhr, status, error) {
                appendLog('❌ Push request failed: ' + error, name, version);
                $btn.prop('disabled', false).text('Push');
            });
        });

        // Dashboard restore button (for plugins that lost .git)
        $(document).on('click', '.hws-dash-restore', function() {
            var $btn = $(this);
            var plugin = $btn.data('plugin');
            
            $btn.prop('disabled', true).text('Restoring...');
            appendLog('Restoring git for: ' + plugin);
            
            ajax('hws_restore_git', { plugin: plugin }, { timeout: 60000 }).done(function(r) {
                if (r.success) {
                    appendLog('✓ Git restored via ' + (r.data.method || 'unknown') + ' for: ' + plugin);
                    loadDashboard(); // Reload entire dashboard
                } else {
                    appendLog('✗ Restore failed: ' + (r.data.message || 'Unknown error'));
                    $btn.prop('disabled', false).text('🔧 Restore Git');
                }
            }).fail(function() {
                appendLog('✗ Restore request failed');
                $btn.prop('disabled', false).text('🔧 Restore Git');
            });
        });

        // Dashboard remove button (unregister plugin from tracking)
        $(document).on('click', '.hws-dash-remove', function() {
            var $btn = $(this);
            var plugin = $btn.data('plugin');
            
            if (!confirm('Remove "' + plugin + '" from the dashboard?\n\nThis will NOT delete the plugin or its git repo, just stop tracking it here.')) {
                return;
            }
            
            $btn.prop('disabled', true);
            
            ajax('hws_unregister_plugin', { plugin: plugin }).done(function(r) {
                if (r.success) {
                    appendLog('Removed from dashboard: ' + plugin);
                    $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
                } else {
                    appendLog('Failed to remove: ' + (r.data.message || 'Unknown'));
                    $btn.prop('disabled', false);
                }
            }).fail(function() {
                $btn.prop('disabled', false);
            });
        });

        // Dashboard single refresh button
        $(document).on('click', '.hws-dash-check', function() {
            var $btn = $(this);
            var plugin = $btn.data('plugin');
            var $row = $btn.closest('tr');
            
            $btn.prop('disabled', true).text('...');
            
            refreshSinglePlugin(plugin, $row, function() {
                $btn.prop('disabled', false).text('🔄');
            });
        });

        function refreshSinglePlugin(plugin, $row, callback) {
            ajax('hws_check_single_plugin_version', { plugin: plugin }).done(function(r) {
                if (r.success) {
                    var p = r.data;
                    var statusHtml = '';
                    var statusClass = '';
                    
                    switch (p.status) {
                        case 'needs_push':
                            statusHtml = '<span style="color: #d63638; font-weight: bold;">⬆️ Needs Push</span>';
                            statusClass = 'hws-row-needs-push';
                            break;
                        case 'behind':
                            statusHtml = '<span style="color: #dba617; font-weight: bold;">⬇️ Behind</span>';
                            statusClass = 'hws-row-behind';
                            break;
                        case 'current':
                            statusHtml = '<span style="color: #00a32a;">✓ Up to date</span>';
                            break;
                        default:
                            statusHtml = '<span style="color: #666;">❓ Unknown</span>';
                    }
                    
                    var changesHtml = p.has_changes 
                        ? '<span style="color: #d63638;">⚠️ Yes</span>' 
                        : '<span style="color: #00a32a;">✓ No</span>';
                    
                    // Update row
                    $row.removeClass('hws-row-needs-push hws-row-behind').addClass(statusClass);
                    $row.find('td:eq(1) code').text('v' + p.local_version);
                    $row.find('td:eq(2) code').text((p.github_version !== 'Unknown' ? 'v' : '') + p.github_version);
                    $row.find('td:eq(3)').html(statusHtml);
                    $row.find('td:eq(4)').html(changesHtml);
                    
                    // Update button data
                    $row.find('.hws-dash-push').data('version', p.local_version).prop('disabled', false).text('Push');
                }
                if (callback) callback();
            }).fail(function() {
                if (callback) callback();
            });
        }

        // Helper function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /*
        |----------------------------------------------------------------------
        | Token Management
        |----------------------------------------------------------------------
        */

        $('#hws-toggle-token').on('click', function() {
            var $i = $('#hws-github-token');
            var $btn = $(this);

            // Security: Only show masked version (first 8 chars), never the full token
            if ($i.val() === '••••••••••••' || $i.attr('type') === 'password') {
                $btn.prop('disabled', true).text('...');

                ajax('hws_get_token').done(function(r) {
                    if (r.success && r.data.token) {
                        $i.val(r.data.token).attr('type', 'text');
                        $btn.text('🙈');
                    } else {
                        alert('No token configured');
                    }
                }).fail(function() {
                    alert('Failed to fetch token');
                }).always(function() {
                    $btn.prop('disabled', false);
                });
            } else {
                // Hide it again
                $i.val('••••••••••••').attr('type', 'password');
                $btn.text('👁️');
            }
        });

        $('#hws-save-token').on('click', function() {
            var $btn = $(this), token = $('#hws-github-token').val();
            if (token === '••••••••••••') { 
                showStatus($('#hws-token-status'), 'Enter new token to update', 'loading'); 
                return; 
            }
            $btn.prop('disabled', true).text('Saving...');
            ajax('hws_save_github_token', { token: token }).done(function(r) {
                if (r.success) { 
                    showStatus($('#hws-token-status'), 'Saved (' + r.data.username + ')', 'success'); 
                    if(token) $('#hws-github-token').val('••••••••••••');
                    appendLog('GitHub token saved successfully for user: ' + r.data.username);
                } else { 
                    showStatus($('#hws-token-status'), r.data.message, 'error'); 
                    appendLog('Error saving token: ' + r.data.message);
                }
            }).fail(function() {
                showStatus($('#hws-token-status'), 'Save failed', 'error');
            }).always(function() { 
                $btn.prop('disabled', false).text('Save Token'); 
            });
        });

        $('#hws-test-token').on('click', function() {
            console.log('[HWS] Test Credentials button clicked');
            var $btn = $(this);
            var $result = $('#hws-test-token-result');
            $btn.prop('disabled', true).text('Testing...');
            $result.show().css({ background: '#f0f0f1', border: '1px solid #c3c4c7', color: '#50575e' }).html('⏳ Testing GitHub credentials...');

            ajax('hws_test_github_token').done(function(r) {
                console.log('[HWS] Test Credentials response:', r);
                if (r.success) {
                    $result.css({ background: '#d4edda', border: '2px solid #00a32a', color: '#0a3622' }).html('✅ SUCCESS — Authenticated as: <strong>' + r.data.username + '</strong>' + (r.data.name ? ' (' + r.data.name + ')' : ''));
                    showStatus($('#hws-token-status'), 'Saved (' + r.data.username + ')', 'success');
                    appendLog('GitHub token test passed - authenticated as: ' + r.data.username + (r.data.name ? ' (' + r.data.name + ')' : ''));
                } else {
                    var msg = (r.data && r.data.message) ? r.data.message : 'Invalid token';
                    $result.css({ background: '#f8d7da', border: '2px solid #d63638', color: '#58151c' }).html('❌ FAILED — ' + msg);
                    showStatus($('#hws-token-status'), msg, 'error');
                    appendLog('GitHub token test failed: ' + msg);
                }
            }).fail(function(xhr, status, error) {
                console.error('[HWS] Test Credentials AJAX error:', status, error);
                $result.css({ background: '#f8d7da', border: '2px solid #d63638', color: '#58151c' }).html('❌ REQUEST FAILED — ' + (error || 'Could not reach server'));
                showStatus($('#hws-token-status'), 'Test request failed', 'error');
                appendLog('GitHub token test request failed: ' + error);
            }).always(function() {
                $btn.prop('disabled', false).text('🔑 Test Credentials');
            });
        });

        /*
        |----------------------------------------------------------------------
        | Fetch Repositories
        |----------------------------------------------------------------------
        */

        $('#hws-fetch-repos').on('click', function() {
            var $btn = $(this), $sel = $('#hws-github-repo');
            $btn.prop('disabled', true).text('Fetching...');
            showStatus($('#hws-repos-status'), 'Fetching repositories...', 'loading');
            
            appendLog('Fetching GitHub repositories...');
            
            ajax('hws_fetch_github_repos', {}, { timeout: 60000 }).done(function(r) {
                if (r.success) {
                    $sel.empty().append('<option value="">-- Select repository --</option>');
                    $.each(r.data.repos, function(i, repo) {
                        $sel.append('<option value="' + repo.full_name + '">' + repo.full_name + (repo.private ? ' 🔒' : '') + '</option>');
                    });
                    showStatus($('#hws-repos-status'), r.data.count + ' repos found', 'success');
                    appendLog('Found ' + r.data.count + ' repositories');
                } else { 
                    showStatus($('#hws-repos-status'), r.data.message, 'error'); 
                    appendLog('Error fetching repos: ' + r.data.message);
                }
            }).fail(function() { 
                showStatus($('#hws-repos-status'), 'Failed to fetch', 'error');
                appendLog('Failed to fetch repositories - check your API token');
            }).always(function() { 
                $btn.prop('disabled', false).text('🔄 Fetch Repos'); 
            });
        });

        /*
        |----------------------------------------------------------------------
        | Quick Push
        |----------------------------------------------------------------------
        */

        $('#hws-push-btn').on('click', function() {
            var $sel = $('#hws-push-plugin');
            var plugin = $sel.val();
            var pluginVersion = $sel.find(':selected').data('version') || 'unknown';
            var pluginName = $sel.find(':selected').text().split(' (')[0] || plugin;
            var remote = $sel.find(':selected').data('remote') || '';
            var msg = $('#hws-commit-message').val();
            var force = $('#hws-force-push').is(':checked');
            
            if (!plugin) { 
                showStatus($('#hws-push-status'), 'Select plugin first', 'error'); 
                return; 
            }
            
            showStatus($('#hws-push-status'), 'Pushing...', 'loading');
            appendLog('Starting push operation...', pluginName, pluginVersion);
            
            ajax('hws_push_plugin', { 
                plugin: plugin, 
                message: msg, 
                force: force ? 'true' : 'false' 
            }, { timeout: 120000 }).done(function(r) {
                showStatus($('#hws-push-status'), r.success ? 'Complete' : 'Failed', r.success ? 'success' : 'error');
                if (r.data.log) {
                    var logContent = r.data.log;
                    // Add GitHub links if we have remote info
                    if (r.success && (remote || r.data.remote)) {
                        var repoUrl = 'https://github.com/' + (r.data.remote || remote);
                        logContent += '\n\n════════════════════════════════════════════\n';
                        logContent += '🔗 QUICK LINKS:\n';
                        logContent += '   Repository: ' + repoUrl + '\n';
                        logContent += '   Commits:    ' + repoUrl + '/commits\n';
                        logContent += '   Latest:     ' + repoUrl + '/commit/HEAD\n';
                        logContent += '════════════════════════════════════════════';
                    }
                    appendLog(logContent, pluginName, pluginVersion);
                }
                // Refresh dashboard row
                if (r.success) {
                    loadDashboard();
                }
            }).fail(function() { 
                showStatus($('#hws-push-status'), 'Request failed', 'error');
                appendLog('Push request failed', pluginName, pluginVersion);
            });
        });

        // Quick push from table
        $(document).on('click', '.hws-quick-push', function() {
            var $btn = $(this);
            var plugin = $btn.data('plugin');
            var pluginVersion = $btn.data('version') || 'unknown';
            
            $btn.prop('disabled', true).text('...');
            appendLog('Quick push starting...', plugin, pluginVersion);
            
            ajax('hws_push_plugin', { plugin: plugin, message: 'Quick update' }).done(function(r) {
                if (r.success && r.data.log) {
                    appendLog(r.data.log, plugin, pluginVersion);
                } else if (!r.success) {
                    appendLog('Push failed: ' + (r.data.message || 'Unknown error'), plugin, pluginVersion);
                }
            }).fail(function() {
                appendLog('Push request failed', plugin, pluginVersion);
            }).always(function() { 
                $btn.prop('disabled', false).text('Push'); 
            });
        });

        /*
        |----------------------------------------------------------------------
        | Initialize Repository
        |----------------------------------------------------------------------
        */

        $('#hws-init-btn').on('click', function() {
            var $selPlugin = $('#hws-init-plugin');
            var plugin = $selPlugin.val();
            var pluginVersion = $selPlugin.find(':selected').data('version') || 'unknown';
            var pluginName = $selPlugin.find(':selected').text().split(' (')[0] || plugin;
            var repo = $('#hws-github-repo').val();
            var msg = $('#hws-init-message').val();
            
            if (!plugin || !repo) { 
                alert('Please select both a plugin and a repository'); 
                return; 
            }
            if (!confirm('Initialize "' + plugin + '" (v' + pluginVersion + ') and push to "' + repo + '"?')) return;
            
            var $btn = $(this);
            $btn.prop('disabled', true).text('Initializing...');
            
            appendLog('═══════════════════════════════════════════════════\n' +
                      '  INITIALIZING REPOSITORY\n' +
                      '  Plugin: ' + pluginName + '\n' +
                      '  Version: ' + pluginVersion + '\n' +
                      '  Target: ' + repo + '\n' +
                      '═══════════════════════════════════════════════════', 
                      pluginName, pluginVersion);
            
            ajax('hws_init_git_repo', { 
                plugin: plugin, 
                github_repo: repo, 
                message: msg 
            }, { timeout: 180000 }).done(function(r) {
                if (r.success) { 
                    appendLog(r.data.log + '\n\n✓ SUCCESS - Page will reload...', pluginName, pluginVersion);
                    setTimeout(function() { location.reload(); }, 3000);
                } else { 
                    appendLog('FAILED: ' + (r.data.message || 'Unknown error') + '\n' + (r.data.log || ''), pluginName, pluginVersion);
                }
            }).fail(function() {
                appendLog('Request failed - check console for details', pluginName, pluginVersion);
            }).always(function() { 
                $btn.prop('disabled', false).text('⚡ Initialize & Push'); 
            });
        });

        /*
        |----------------------------------------------------------------------
        | Version Check & Update
        |----------------------------------------------------------------------
        */

        $('#hws-force-update-check').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Checking...');
            showStatus($('#hws-update-status'), 'Checking GitHub...', 'loading');
            
            ajax('hws_check_plugin_version').done(function(r) {
                if (r.success) {
                    $('#hws-latest-version').text(r.data.version);
                    if (r.data.update_available) {
                        $('#hws-latest-version').css('color', '#d63638');
                        $('#hws-version-comparison').html('<span style="color:#d63638; font-weight: bold;">⚠️ Update available: v' + r.data.current + ' → v' + r.data.version + '</span>');
                        $('#hws-direct-update').prop('disabled', false);
                        showStatus($('#hws-update-status'), 'Update available!', 'success');
                    } else {
                        $('#hws-latest-version').css('color', '#00a32a');
                        $('#hws-version-comparison').html('<span style="color:#00a32a;">✓ You are running the latest version</span>');
                        showStatus($('#hws-update-status'), 'Up to date', 'success');
                    }
                    appendLog('Version check: Current v' + r.data.current + ', Latest v' + r.data.version);
                } else { 
                    showStatus($('#hws-update-status'), r.data.message, 'error'); 
                }
            }).fail(function() {
                showStatus($('#hws-update-status'), 'Check failed', 'error');
            }).always(function() { 
                $btn.prop('disabled', false).text('🔍 Check for Updates'); 
            });
        });

        // Direct update from GitHub
        $('#hws-direct-update').on('click', function() {
            if (!confirm('Download and install the latest version from GitHub?\n\nCurrent: v' + config.version + '\n\nThe page will reload after update.')) return;
            
            var $btn = $(this);
            $btn.prop('disabled', true).text('Updating...');
            showStatus($('#hws-update-status'), 'Downloading from GitHub...', 'loading');
            
            appendLog('Starting plugin update from GitHub...\nCurrent version: v' + config.version);
            
            ajax('hws_update_plugin_from_github', {}, { timeout: 180000 }).done(function(r) {
                if (r.success) { 
                    showStatus($('#hws-update-status'), r.data.message + ' - Reloading...', 'success');
                    appendLog('Update successful! ' + r.data.message + '\nReloading page...');
                    setTimeout(function() { location.reload(); }, 2000);
                } else { 
                    showStatus($('#hws-update-status'), r.data.message, 'error'); 
                    appendLog('Update failed: ' + r.data.message);
                    $btn.prop('disabled', false).text('⬆️ Update from GitHub');
                }
            }).fail(function() { 
                showStatus($('#hws-update-status'), 'Update failed', 'error');
                appendLog('Update request failed');
                $btn.prop('disabled', false).text('⬆️ Update from GitHub');
            });
        });

        // Download current version
        $('#hws-download-plugin-zip').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Preparing...');
            
            ajax('hws_download_current_plugin').done(function(r) {
                if (r.success) {
                    window.location.href = r.data.url;
                    appendLog('Downloaded: ' + r.data.filename);
                } else {
                    alert('Download failed: ' + r.data.message);
                }
            }).always(function() { 
                $btn.prop('disabled', false).text('⬇️ Download .zip'); 
            });
        });

        /*
        |----------------------------------------------------------------------
        | Version History (Load & Download)
        |----------------------------------------------------------------------
        */

        $('#hws-load-versions').on('click', function() {
            var $btn = $(this), $sel = $('#hws-version-select');
            $btn.prop('disabled', true).text('Loading...');
            showStatus($('#hws-version-status'), 'Fetching commits from GitHub...', 'loading');
            
            ajax('hws_load_plugin_versions', {}, { timeout: 60000 }).done(function(r) {
                console.log('=== HWS VERSION LOAD RESPONSE ===', r);
                
                // ALWAYS show debug in log
                if (r.data && r.data.debug) {
                    var d = r.data.debug;
                    appendLog('=== VERSION LOAD DEBUG ===\n' +
                        'Step: ' + d.step + '\n' +
                        'Repo: ' + d.repo + '\n' +
                        'Token exists: ' + d.token_exists + '\n' +
                        'Token preview: ' + d.token_preview + '\n' +
                        'HTTP Code: ' + d.http_code + '\n' +
                        'Commits found: ' + d.commit_count + '\n' +
                        'Versions built: ' + d.versions_built + '\n' +
                        (d.error ? 'ERROR: ' + d.error + '\n' : '') +
                        '=== END DEBUG ===');
                }
                
                if (r.success && r.data.versions && r.data.versions.length > 0) {
                    $sel.empty().append('<option value="">-- Select Version (' + r.data.versions.length + ' commits) --</option>');
                    $.each(r.data.versions, function(i, v) { 
                        $sel.append('<option value="' + escapeHtml(v.sha) + '" data-version="' + escapeHtml(v.version) + '" data-name="' + escapeHtml(v.name) + '">' + escapeHtml(v.name) + '</option>'); 
                    });
                    showStatus($('#hws-version-status'), '✓ Found ' + r.data.versions.length + ' commits', 'success');
                    $('#hws-download-version').prop('disabled', false);
                } else { 
                    var errMsg = (r.data && r.data.message) ? r.data.message : 'No versions found';
                    showStatus($('#hws-version-status'), '✗ ' + errMsg, 'error');
                }
            }).fail(function(xhr, status, error) { 
                showStatus($('#hws-version-status'), '✗ Request failed: ' + error, 'error');
                appendLog('VERSION LOAD FAILED: ' + error + '\nStatus: ' + status);
            }).always(function() { 
                $btn.prop('disabled', false).text('🔄 Load Versions'); 
            });
        });

        // Download specific version by SHA
        $('#hws-download-version').on('click', function() {
            var $sel = $('#hws-version-select');
            var sha = $sel.val();
            var version = $sel.find(':selected').data('version') || '';
            var name = $sel.find(':selected').data('name') || '';
            
            if (!sha) { 
                alert('Please select a version first'); 
                return; 
            }
            
            var $btn = $(this);
            $btn.prop('disabled', true).text('Downloading...');
            showStatus($('#hws-version-status'), 'Downloading ' + name + '...', 'loading');
            
            ajax('hws_download_plugin_version', { 
                sha: sha, 
                version: version 
            }, { timeout: 120000 }).done(function(r) {
                if (r.success) { 
                    showStatus($('#hws-version-status'), 'Ready: ' + r.data.filename, 'success'); 
                    window.location.href = r.data.url;
                    appendLog('Downloaded: ' + r.data.filename);
                } else { 
                    showStatus($('#hws-version-status'), r.data.message, 'error');
                    appendLog('Download failed: ' + r.data.message);
                }
            }).fail(function() {
                showStatus($('#hws-version-status'), 'Download failed', 'error');
            }).always(function() { 
                $btn.prop('disabled', false).text('⬇️ Download Selected'); 
            });
        });

        /*
        |----------------------------------------------------------------------
        | Rename Plugin
        |----------------------------------------------------------------------
        */

        $('#hws-rename-plugin-select').on('change', function() {
            var slug = $(this).val();
            if (slug) {
                $('#hws-current-folder-name').text(slug);
                $('#hws-new-folder-name').val('');
                $('#hws-rename-fields').show();
            } else {
                $('#hws-rename-fields').hide();
            }
        });

        $('#hws-rename-button').on('click', function() {
            var oldSlug = $('#hws-rename-plugin-select').val();
            var newSlug = $('#hws-new-folder-name').val().trim().toLowerCase();
            
            if (!oldSlug) { showStatus($('#hws-rename-status'), 'Select a plugin first', 'error'); return; }
            if (!newSlug) { showStatus($('#hws-rename-status'), 'Enter a new name', 'error'); return; }
            if (!/^[a-z0-9\-]+$/.test(newSlug)) { showStatus($('#hws-rename-status'), 'Use only lowercase, numbers, hyphens', 'error'); return; }
            if (oldSlug === newSlug) { showStatus($('#hws-rename-status'), 'Names are the same', 'error'); return; }
            
            if (!confirm('Rename "' + oldSlug + '" to "' + newSlug + '"?\n\nThis will deactivate the plugin if active.')) return;
            
            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#hws-rename-spinner').addClass('is-active');
            showStatus($('#hws-rename-status'), 'Renaming...', 'loading');
            
            ajax('hws_rename_plugin', { plugin: oldSlug, new_name: newSlug }).done(function(r) {
                if (r.success) {
                    showStatus($('#hws-rename-status'), r.data.message, 'success');
                    appendLog('Renamed plugin: ' + oldSlug + ' → ' + newSlug);
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    showStatus($('#hws-rename-status'), r.data.message, 'error');
                }
            }).fail(function() {
                showStatus($('#hws-rename-status'), 'Rename failed', 'error');
            }).always(function() {
                $btn.prop('disabled', false);
                $('#hws-rename-spinner').removeClass('is-active');
            });
        });

        /*
        |----------------------------------------------------------------------
        | Version Editor
        |----------------------------------------------------------------------
        */

        $('#hws-version-plugin-select').on('change', function() {
            var $opt = $(this).find(':selected');
            var version = $opt.data('version');
            
            if (version) {
                $('#hws-current-version-display').html('<code style="background:#e7f3ff; padding: 5px 10px; font-size: 14px;">v' + version + '</code>');
                $('#hws-version-info-row, #hws-version-edit-row').show();
                $('#hws-new-version').val(version);
            } else {
                $('#hws-version-info-row, #hws-version-edit-row').hide();
            }
        });

        $('#hws-update-version').on('click', function() {
            var plugin = $('#hws-version-plugin-select').val();
            var newVersion = $('#hws-new-version').val().trim();
            
            if (!plugin) { showStatus($('#hws-version-edit-status'), 'Select a plugin', 'error'); return; }
            if (!newVersion || !/^\d+\.\d+\.?\d*$/.test(newVersion)) { showStatus($('#hws-version-edit-status'), 'Invalid version format (use X.Y.Z)', 'error'); return; }
            
            var $btn = $(this);
            $btn.prop('disabled', true);
            $('#hws-version-edit-spinner').addClass('is-active');
            showStatus($('#hws-version-edit-status'), 'Updating...', 'loading');
            
            ajax('hws_update_plugin_version', { plugin: plugin, version: newVersion }).done(function(r) {
                if (r.success) {
                    showStatus($('#hws-version-edit-status'), r.data.message, 'success');
                    appendLog('Version updated: ' + plugin + ' → v' + newVersion);
                    var $opt = $('#hws-version-plugin-select option:selected');
                    $opt.data('version', newVersion);
                    $opt.text($opt.text().replace(/\(v[\d\.]+\)/, '(v' + newVersion + ')'));
                    $('#hws-current-version-display').html('<code style="background:#d4edda; padding: 5px 10px; font-size: 14px;">v' + newVersion + '</code>');
                } else {
                    showStatus($('#hws-version-edit-status'), r.data.message, 'error');
                }
            }).fail(function() {
                showStatus($('#hws-version-edit-status'), 'Update failed', 'error');
            }).always(function() {
                $btn.prop('disabled', false);
                $('#hws-version-edit-spinner').removeClass('is-active');
            });
        });

        /*
        |----------------------------------------------------------------------
        | Upload & Install Plugin
        |----------------------------------------------------------------------
        */

        $('#hws-upload-install').on('click', function() {
            var fileInput = $('#hws-upload-plugin-zip')[0];
            if (!fileInput.files || !fileInput.files[0]) {
                showStatus($('#hws-upload-status'), 'Select a ZIP file first', 'error');
                return;
            }
            
            var $btn = $(this).prop('disabled', true);
            $('#hws-upload-spinner').addClass('is-active');
            showStatus($('#hws-upload-status'), 'Uploading...', 'loading');
            
            var formData = new FormData();
            formData.append('action', 'hws_upload_plugin');
            formData.append('nonce', hwsGitPush.nonce);
            formData.append('plugin_zip', fileInput.files[0]);
            
            $.ajax({
                url: hwsGitPush.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 120000
            }).done(function(r) {
                if (r.success) {
                    showStatus($('#hws-upload-status'), '✓ ' + r.data.message, 'success');
                    appendLog('Plugin uploaded: ' + r.data.plugin_slug);
                    fileInput.value = '';
                    // Refresh page to show new plugin
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    showStatus($('#hws-upload-status'), '✗ ' + r.data.message, 'error');
                }
            }).fail(function(xhr, status, error) {
                showStatus($('#hws-upload-status'), '✗ Upload failed: ' + error, 'error');
            }).always(function() {
                $btn.prop('disabled', false);
                $('#hws-upload-spinner').removeClass('is-active');
            });
        });

        $('#hws-fetch-install').on('click', function() {
            var url = $('#hws-fetch-url').val().trim();
            if (!url) {
                showStatus($('#hws-fetch-status'), 'Enter a URL first', 'error');
                return;
            }
            
            var $btn = $(this).prop('disabled', true);
            $('#hws-fetch-spinner').addClass('is-active');
            showStatus($('#hws-fetch-status'), 'Fetching...', 'loading');
            
            ajax('hws_fetch_plugin', { url: url }, { timeout: 120000 }).done(function(r) {
                if (r.success) {
                    showStatus($('#hws-fetch-status'), '✓ ' + r.data.message, 'success');
                    appendLog('Plugin fetched: ' + r.data.plugin_slug);
                    $('#hws-fetch-url').val('');
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    showStatus($('#hws-fetch-status'), '✗ ' + r.data.message, 'error');
                }
            }).fail(function() {
                showStatus($('#hws-fetch-status'), '✗ Fetch failed', 'error');
            }).always(function() {
                $btn.prop('disabled', false);
                $('#hws-fetch-spinner').removeClass('is-active');
            });
        });

        /*
        |----------------------------------------------------------------------
        | Backup/Restore
        |----------------------------------------------------------------------
        */

        $('#hws-backup-plugin-select').on('change', function() {
            var plugin = $(this).val();
            if (!plugin) {
                $('#hws-backup-info-row, #hws-backup-download-row').hide();
                $('#hws-backup-single, #hws-restore-single').prop('disabled', true);
                return;
            }
            
            $('#hws-backup-single').prop('disabled', false);
            $('#hws-backup-info-row').show();
            $('#hws-backup-info').html('<em>Loading...</em>');
            
            ajax('hws_get_backup_info', { plugin: plugin }).done(function(r) {
                if (r.success) {
                    var html = '';
                    if (r.data.has_git) {
                        html += '<span style="color:green;">✓ Has Git repo</span><br>';
                    } else {
                        html += '<span style="color:orange;">⚠ No Git repo</span><br>';
                    }
                    if (r.data.backup_count > 0) {
                        html += '<span style="color:green;">✓ ' + r.data.backup_count + ' backup(s)</span><br>';
                        if (r.data.latest) {
                            html += 'Latest: ' + r.data.latest.date + ' (' + r.data.latest.size + ')';
                        }
                        $('#hws-restore-single').prop('disabled', false);
                        $('#hws-backup-download-row').show();
                    } else {
                        html += '<span style="color:#666;">No backups yet</span>';
                        $('#hws-restore-single').prop('disabled', true);
                        $('#hws-backup-download-row').hide();
                    }
                    $('#hws-backup-info').html(html);
                }
            });
        });

        $('#hws-backup-single').on('click', function() {
            var plugin = $('#hws-backup-plugin-select').val();
            if (!plugin) return;
            
            var $btn = $(this).prop('disabled', true);
            $('#hws-backup-spinner').addClass('is-active');
            showStatus($('#hws-backup-status'), 'Creating backup...', 'loading');
            
            ajax('hws_backup_single', { plugin: plugin }).done(function(r) {
                showStatus($('#hws-backup-status'), r.success ? r.data.message : r.data.message, r.success ? 'success' : 'error');
                if (r.success) {
                    appendLog('Backup created: ' + plugin);
                    $('#hws-backup-plugin-select').trigger('change'); // Refresh info
                }
            }).always(function() {
                $btn.prop('disabled', false);
                $('#hws-backup-spinner').removeClass('is-active');
            });
        });

        $('#hws-restore-single').on('click', function() {
            var plugin = $('#hws-backup-plugin-select').val();
            if (!plugin) return;
            if (!confirm('Restore git config for "' + plugin + '" from the latest backup?')) return;
            
            var $btn = $(this).prop('disabled', true);
            $('#hws-backup-spinner').addClass('is-active');
            showStatus($('#hws-backup-status'), 'Restoring...', 'loading');
            
            ajax('hws_restore_backup', { plugin: plugin }).done(function(r) {
                showStatus($('#hws-backup-status'), r.success ? r.data.message : r.data.message, r.success ? 'success' : 'error');
                if (r.success) {
                    appendLog('Backup restored: ' + plugin);
                    loadDashboard(); // Refresh dashboard
                }
            }).always(function() {
                $btn.prop('disabled', false);
                $('#hws-backup-spinner').removeClass('is-active');
            });
        });

        $('#hws-download-backup').on('click', function() {
            var plugin = $('#hws-backup-plugin-select').val();
            if (!plugin) return;
            
            var $btn = $(this).prop('disabled', true).text('Preparing...');
            
            ajax('hws_download_backup', { plugin: plugin }).done(function(r) {
                if (r.success) {
                    window.location.href = r.data.url;
                    appendLog('Downloaded backup: ' + r.data.filename);
                } else {
                    alert(r.data.message);
                }
            }).always(function() {
                $btn.prop('disabled', false).text('⬇️ Download Backup File');
            });
        });

        $('#hws-backup-all').on('click', function() {
            if (!confirm('Create backup for ALL git-enabled plugins?')) return;
            
            var $btn = $(this).prop('disabled', true).text('Backing up...');
            
            ajax('hws_backup_all', {}, { timeout: 120000 }).done(function(r) {
                alert(r.success ? r.data.message : r.data.message);
                if (r.success) appendLog('Backup all: ' + r.data.message);
            }).always(function() {
                $btn.prop('disabled', false).text('💾 Backup All Git Plugins');
            });
        });

    }); // End document ready

})(jQuery);
