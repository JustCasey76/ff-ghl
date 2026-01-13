<# 
Updates FTP-Deploy folder with latest files from repo-ff-ghl.
This script copies ALL plugin files (not just changed ones) to ensure FTP-Deploy is always in sync.

Usage: 
  .\update-ftp-deploy.ps1
#>

$ErrorActionPreference = "Stop"

# Get script directory and determine paths
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$repoDir = Split-Path -Parent $scriptDir  # repo-ff-ghl folder
$targetRoot = Split-Path -Parent $repoDir  # Main plugin folder (one level up)
$sourceDir = $repoDir
$ftpDir = Join-Path $targetRoot "FTP-Deploy"

Write-Host "Updating FTP-Deploy folder..." -ForegroundColor Cyan
Write-Host "Source: $sourceDir" -ForegroundColor Gray
Write-Host "Destination: $ftpDir" -ForegroundColor Gray
Write-Host ""

# Skip patterns - don't copy these
$skipDirs = @(".git", "scripts", ".vscode", ".idea", "FTP-Deploy", "node_modules", "vendor", ".github")
$skipFiles = @(".gitignore", ".DS_Store", "Thumbs.db", ".last-ftp-deploy.json")

function Should-Skip {
    param($relativePath)
    
    # Normalize path separators for comparison (use forward slash)
    $normalizedPath = $relativePath.Replace("\", "/")
    
    # Check directories - check both forward and backslash patterns
    foreach ($d in $skipDirs) { 
        if ($normalizedPath -like "*/$d/*" -or $normalizedPath -like "$d/*" -or $normalizedPath -like "*/$d" -or $normalizedPath -eq $d) { 
            return $true 
        }
        # Also check with backslashes (in case path wasn't normalized)
        if ($relativePath -like "*\$d\*" -or $relativePath -like "$d\*" -or $relativePath -like "*\$d" -or $relativePath -eq $d) {
            return $true
        }
    }
    
    # Check files
    foreach ($f in $skipFiles) { 
        if ($relativePath -like "*$f" -or $relativePath -eq $f) { 
            return $true 
        } 
    }
    
    # Skip markdown files and zip files
    if ($relativePath -like "*.md" -or $relativePath -like "*.zip") {
        return $true
    }
    
    return $false
}

# Clear and recreate FTP-Deploy directory to ensure clean sync
if (Test-Path $ftpDir) {
    Remove-Item $ftpDir -Recurse -Force
    Write-Host "Cleaned existing FTP-Deploy folder" -ForegroundColor Yellow
}

New-Item -ItemType Directory -Path $ftpDir -Force | Out-Null

# Copy all files from repo-ff-ghl to FTP-Deploy
$copied = 0
$skipped = 0
$files = Get-ChildItem -Path $sourceDir -Recurse -File

foreach ($file in $files) {
    $rel = $file.FullName.Substring($sourceDir.Length + 1).Replace("\", "/")
    
    if (Should-Skip $rel) { 
        $skipped++
        continue 
    }

    $destPath = Join-Path $ftpDir $rel
    $destDir = Split-Path $destPath -Parent
    if (-not (Test-Path $destDir)) { 
        New-Item -ItemType Directory -Path $destDir -Force | Out-Null 
    }
    
    Copy-Item $file.FullName -Destination $destPath -Force
    $copied++
}

Write-Host ""
Write-Host "FTP-Deploy Updated Successfully!" -ForegroundColor Green
Write-Host "Files copied: $copied" -ForegroundColor White
Write-Host "Files skipped: $skipped" -ForegroundColor Gray
Write-Host "Location: $ftpDir" -ForegroundColor White
