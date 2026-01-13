# Automatic FTP-Deploy Updates

The FTP-Deploy folder is automatically kept in sync with the latest code from `repo-ff-ghl`.

## How It Works

1. **Git Hook (Automatic)**: A git post-commit hook automatically runs `update-ftp-deploy.ps1` after each commit
2. **Manual Update**: You can also run the script manually if needed

## Manual Update

To manually update FTP-Deploy:

```powershell
# From the repo-ff-ghl/scripts directory:
.\update-ftp-deploy.ps1

# Or from the root directory:
.\repo-ff-ghl\scripts\update-ftp-deploy.ps1
```

## Git Hook Setup

The git hook (`.git/hooks/post-commit`) automatically updates FTP-Deploy after each commit. If the hook doesn't exist or isn't working:

1. The hook file is at: `repo-ff-ghl/.git/hooks/post-commit`
2. Make sure it's executable (on Linux/Mac): `chmod +x .git/hooks/post-commit`
3. On Windows, PowerShell should handle it automatically

## What Gets Copied

- All plugin files from `repo-ff-ghl/` 
- All `includes/` files
- All `assets/` files
- Main plugin file (`aqm-ghl-connector.php`)

## What Gets Excluded

- `.git/` directory
- `scripts/` directory
- `.github/` directory
- `FTP-Deploy/` directory
- `.gitignore`, `.md` files, `.zip` files
- Debug files

## Notes

- The FTP-Deploy folder is completely replaced on each update
- Always test FTP uploads before deploying to production
- The script preserves the folder structure
