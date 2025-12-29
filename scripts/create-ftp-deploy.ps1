<# 
Creates FTP-Deploy folder with files changed since the last run.
Usage: from scripts/ folder -> .\create-ftp-deploy.ps1
#>

$ErrorActionPreference = "Stop"

$rootDir        = Split-Path -Parent $PSScriptRoot
$sourceDir      = $rootDir
$ftpDir         = Join-Path $rootDir "FTP-Deploy"
$stateFile      = Join-Path $rootDir ".last-ftp-deploy.json"
$pluginFileName = "aqm-ghl-connector.php"

# Load previous state
$lastDeploy = @{}
if (Test-Path $stateFile) {
    try {
        $lastDeploy = Get-Content $stateFile | ConvertFrom-Json -AsHashtable
        if ($null -eq $lastDeploy) { $lastDeploy = @{} }
    } catch { $lastDeploy = @{} }
}

# Skip patterns
$skipDirs  = @(".git", "scripts", ".vscode", ".idea", "FTP-Deploy", "node_modules", "vendor")
$skipFiles = @(".gitignore", ".DS_Store", "Thumbs.db", ".last-ftp-deploy.json")

function Should-Skip {
    param($relativePath)
    foreach ($d in $skipDirs) { if ($relativePath -like "$d*") { return $true } }
    foreach ($f in $skipFiles) { if ($relativePath -like "*$f") { return $true } }
    return $false
}

function File-HasChanged {
    param($path, $rel, $lastDeploy)
    $mtime = (Get-Item $path).LastWriteTime.Ticks
    if (-not $lastDeploy.ContainsKey($rel)) { return $true }
    return $mtime -gt $lastDeploy[$rel]
}

if (Test-Path $ftpDir) {
    Remove-Item $ftpDir -Recurse -Force
}
New-Item -ItemType Directory -Path $ftpDir | Out-Null

$newState = @{}
$changed  = @()

$files = Get-ChildItem -Path $sourceDir -Recurse -File
foreach ($file in $files) {
    $rel = $file.FullName.Substring($sourceDir.Length + 1) -replace "\\", "/"
    if (Should-Skip $rel) { continue }

    $newState[$rel] = $file.LastWriteTime.Ticks

    if (-not (File-HasChanged -path $file.FullName -rel $rel -lastDeploy $lastDeploy)) {
        continue
    }

    $destPath = Join-Path $ftpDir $rel
    $destDir  = Split-Path $destPath -Parent
    if (-not (Test-Path $destDir)) { New-Item -ItemType Directory -Path $destDir -Force | Out-Null }
    Copy-Item $file.FullName -Destination $destPath -Force
    $changed += $rel
    Write-Host "[CHANGED] $rel" -ForegroundColor Green
}

$newState | ConvertTo-Json -Depth 10 | Set-Content $stateFile

# Ensure plugin header/constant are preserved (no edit needed; already copied)

Write-Host ""
Write-Host "FTP Deployment Package Created" -ForegroundColor Cyan
Write-Host "Location: $ftpDir" -ForegroundColor White
Write-Host "Changed Files: $($changed.Count)" -ForegroundColor White
if ($changed.Count -eq 0) {
    Write-Host "No changes since last FTP package." -ForegroundColor Yellow
}

