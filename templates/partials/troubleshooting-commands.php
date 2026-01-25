<?php
/**
 * Troubleshooting Commands Partial
 *
 * @package HWS_Git_Push
 */

namespace HWS_Git_Push;

if (!defined('ABSPATH')) exit;

$php_user = Admin_UI::get_php_user();
$plugins_dir = Admin_UI::get_plugins_dir();
?>

<div id="hws-troubleshoot-commands" style="margin-top: 20px;">
    
    <!-- Permission Issues -->
    <div class="hws-cmd-section hws-cmd-warning">
        <h3>🔐 Permission Issues</h3>
        <p><strong>Symptoms:</strong> "Permission denied", "cannot write"</p>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">chown -R <?php echo esc_html($php_user); ?>:<?php echo esc_html($php_user); ?> <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span></pre>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">chmod -R 755 <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span></pre>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">chown -R <?php echo esc_html($php_user); ?>:<?php echo esc_html($php_user); ?> <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span>/.git</pre>
        <div class="hws-cmd-footer"><button type="button" class="button hws-copy-section">📋 Copy All</button> <small>PHP: <?php echo esc_html($php_user); ?></small></div>
    </div>
    
    <!-- Force Push -->
    <div class="hws-cmd-section hws-cmd-danger">
        <h3>⚡ Force Push / Rejection</h3>
        <p><strong>Symptoms:</strong> "rejected", "non-fast-forward"</p>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">cd <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span> && git push --force origin main</pre>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">cd <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span> && git fetch origin && git reset --hard origin/main</pre>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">cd <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span> && git push --force -u origin main</pre>
        <div class="hws-cmd-footer"><button type="button" class="button hws-copy-section">📋 Copy All</button></div>
    </div>
    
    <!-- SSH Issues -->
    <div class="hws-cmd-section hws-cmd-info">
        <h3>🔑 SSH Key Issues</h3>
        <p><strong>Symptoms:</strong> "Permission denied (publickey)"</p>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">ssh -T git@github.com</pre>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">ssh-keygen -t ed25519 -C "your-email@example.com"</pre>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">cat ~/.ssh/id_ed25519.pub</pre>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">eval "$(ssh-agent -s)" && ssh-add ~/.ssh/id_ed25519</pre>
        <div class="hws-cmd-footer"><button type="button" class="button hws-copy-section">📋 Copy All</button></div>
    </div>
    
    <!-- Git Identity -->
    <div class="hws-cmd-section hws-cmd-success">
        <h3>👤 Git Identity</h3>
        <p><strong>Symptoms:</strong> "Author identity unknown"</p>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">git config --global user.email "you@example.com" && git config --global user.name "Your Name"</pre>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">cd <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span> && git config --list</pre>
        <div class="hws-cmd-footer"><button type="button" class="button hws-copy-section">📋 Copy All</button></div>
    </div>
    
    <!-- Remote URL -->
    <div class="hws-cmd-section hws-cmd-muted">
        <h3>🌐 Remote URL Issues</h3>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">cd <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span> && git remote -v</pre>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">cd <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span> && git remote set-url origin git@github.com:USER/REPO.git</pre>
        <div class="hws-cmd-footer"><button type="button" class="button hws-copy-section">📋 Copy All</button></div>
    </div>
    
    <!-- Nuclear -->
    <div class="hws-cmd-section hws-cmd-danger">
        <h3>☢️ Nuclear Options</h3>
        <p><strong>⚠️ Warning:</strong> Data loss possible!</p>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">cd <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span> && rm -rf .git && git init && git add . && git commit -m "Fresh start"</pre>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">git config --global --add safe.directory <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span></pre>
        <div class="hws-cmd-footer"><button type="button" class="button hws-copy-section">📋 Copy All</button></div>
    </div>
    
    <!-- Diagnostics -->
    <div class="hws-cmd-section hws-cmd-primary">
        <h3>🔍 Diagnostics</h3>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">cd <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span> && git status && git remote -v && git branch -vv</pre>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">ls -la <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span>/.git/</pre>
        <pre class="hws-cmd" onclick="hwsCopyCommand(this)">cd <?php echo esc_html($plugins_dir); ?>/<span class="hws-plugin-slug">[plugin]</span> && git log --oneline -10</pre>
        <div class="hws-cmd-footer"><button type="button" class="button hws-copy-section">📋 Copy All</button></div>
    </div>
</div>

<script>
function hwsCopyCommand(el) {
    navigator.clipboard.writeText(el.innerText).then(function() {
        var orig = el.style.background;
        el.style.background = '#28a745'; el.style.color = '#fff';
        setTimeout(function() { el.style.background = orig; el.style.color = ''; }, 300);
    });
}

jQuery(document).ready(function($) {
    function updateSlug(slug) {
        $('.hws-plugin-slug').text(slug && slug.trim() ? slug.trim() : '[plugin]');
    }
    
    $('#hws-troubleshoot-plugin').on('change', function() {
        var slug = $(this).val();
        if (slug) $('#hws-troubleshoot-manual').val('');
        updateSlug(slug);
    });
    
    $('#hws-troubleshoot-manual').on('input', function() {
        var slug = $(this).val();
        if (slug) $('#hws-troubleshoot-plugin').val('');
        updateSlug(slug);
    });
    
    $('.hws-copy-section').on('click', function() {
        var cmds = [];
        $(this).closest('.hws-cmd-section').find('.hws-cmd').each(function() { cmds.push($(this).text()); });
        navigator.clipboard.writeText(cmds.join('\n')).then(function() {
            var $btn = $(event.target);
            var orig = $btn.text();
            $btn.text('✓ Copied!').css({'background':'#28a745','color':'#fff'});
            setTimeout(function() { $btn.text(orig).css({'background':'','color':''}); }, 1500);
        });
    });
});
</script>
