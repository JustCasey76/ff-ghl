# Updater Debugging Steps

## 1. Verify GitHub Release Exists
Visit: https://github.com/JustCasey76/ff-ghl/releases
- Should see v1.3.2 with a ZIP file attached

## 2. Enable WordPress Debug Mode
Add to `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

## 3. Check Error Logs
After visiting Plugins page, check:
- `wp-content/debug.log` (if WP_DEBUG_LOG is enabled)
- Server error logs

Look for lines starting with `[AQM GHL Updater]`

## 4. Manual Cache Clear (Database)
Run this SQL query (or use phpMyAdmin):
```sql
DELETE FROM wp_options WHERE option_name LIKE '_transient_aqm_ghl%';
DELETE FROM wp_options WHERE option_name LIKE '_transient_timeout_aqm_ghl%';
DELETE FROM wp_options WHERE option_name = '_site_transient_update_plugins';
```

## 5. Verify File Was Uploaded
Check that the file on your server has the fix:
- File: `wp-content/plugins/aqm-ghl-connector/includes/class-aqm-ghl-updater.php`
- Line 176 should say: `$plugin_info->slug = $this->repository;`
- Line 364 should say: `if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== $this->repository ) {`

## 6. Test GitHub API Directly
Try accessing this URL in your browser (while logged into GitHub):
https://api.github.com/repos/JustCasey76/ff-ghl/releases

You should see JSON with release information.
