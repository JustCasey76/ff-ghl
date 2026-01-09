# Updater Debugging Guide - v2

## Critical Issue: Updates Not Detected

If updates are still not being detected, please check the following:

### 1. Verify the Updated File is on Your WordPress Server

**IMPORTANT:** The updated `class-aqm-ghl-updater.php` file MUST be on your WordPress server for updates to work.

- File location: `wp-content/plugins/aqm-ghl-connector/includes/class-aqm-ghl-updater.php`
- Check that the file has been updated via FTP
- The file should have the latest code from the GitHub repository

### 2. Enable WordPress Debug Mode

Add to `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

### 3. Check Error Logs

After visiting the Plugins page, check:
- `wp-content/debug.log`
- Server error logs (cPanel → Errors, or contact hosting)

Look for lines starting with `[AQM GHL Updater]`:
- `Checking for updates. Plugin: ...`
- `Latest version from GitHub: ...`
- `Update needed: YES/NO`
- `Added update to transient. New version: ...`

### 4. Clear All Caches

**WordPress Cache:**
1. Go to Plugins page
2. Use "Clear Update Cache" button if available in plugin settings
3. Or manually clear: Plugins → Installed Plugins → Check for updates

**Database Cache (via phpMyAdmin or SQL):**
```sql
DELETE FROM wp_options WHERE option_name LIKE '_transient_aqm_ghl%';
DELETE FROM wp_options WHERE option_name LIKE '_transient_timeout_aqm_ghl%';
DELETE FROM wp_options WHERE option_name = '_site_transient_update_plugins';
```

**Server Cache:**
- Clear any caching plugins (WP Super Cache, W3 Total Cache, etc.)
- Clear object cache (Redis, Memcached) if enabled

### 5. Verify GitHub Release Exists

Visit: https://github.com/JustCasey76/ff-ghl/releases

Check:
- Latest release has a ZIP file attached
- Release version is newer than your current plugin version
- ZIP file is named `aqm-ghl-connector-X.X.X.zip`

### 6. Test GitHub API Directly

Try accessing this URL in your browser:
```
https://api.github.com/repos/JustCasey76/ff-ghl/releases
```

You should see JSON with release information. If you get a 404 or error, the repository might be private and require authentication.

### 7. Check Plugin Version

In WordPress admin:
1. Go to Plugins → Installed Plugins
2. Find "AQM GHL Formidable Connector"
3. Check the version number shown

Compare with the latest release on GitHub.

### 8. Manual FTP Update Test

As a test, manually FTP the latest `includes/class-aqm-ghl-updater.php` file from the repository to your WordPress server, then check for updates again.

### 9. Check Plugin Directory Name

Verify the plugin directory name matches:
- Expected: `aqm-ghl-connector`
- Location: `wp-content/plugins/aqm-ghl-connector/`

If the directory name is different, the updater might not work correctly.

### 10. Verify Repository is Public

If the repository is private, you need to add a GitHub token:

1. Create a GitHub Personal Access Token with `repo` scope
2. Add to `wp-config.php`:
```php
define( 'AQM_GHL_GITHUB_TOKEN', 'your_token_here' );
```

### Common Issues:

1. **File not updated on server** - Most common issue. The updater code on your WordPress server must be the latest version.
2. **Caching** - WordPress, server, or CDN caching old update checks
3. **Private repository** - Repository is private but no token configured
4. **Plugin directory name mismatch** - Plugin installed in different directory than expected
5. **Version mismatch** - Current version same or newer than GitHub release
