Param(
    [string]$Version,
    [string]$Token,
    [string]$Repo = "JustCasey76/ff-ghl"
)

$ErrorActionPreference = "Stop"

$scriptDir   = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent $scriptDir
$pluginSlug  = "aqm-ghl-connector"
$pluginFile  = Join-Path $projectRoot "$pluginSlug.php"

if (-not $Token) {
    $Token = $env:GITHUB_TOKEN
}

if (-not $Token) {
    Write-Error "GITHUB_TOKEN not provided."
}

if (-not (Test-Path $pluginFile)) {
    Write-Error "Cannot find plugin file at $pluginFile"
}

if (-not $Version) {
    $content = Get-Content $pluginFile -Raw
    if ($content -match "Version:\s*([0-9\.]+)") {
        $Version = $Matches[1]
    } else {
        Write-Error "Unable to detect version from plugin header."
    }
}

$zipScript = Join-Path $scriptDir "create-plugin-zip.ps1"
& $zipScript -Version $Version

$zipPath = Join-Path $projectRoot "$pluginSlug-$Version.zip"
if (-not (Test-Path $zipPath)) {
    Write-Error "Zip not found at $zipPath"
}

$tag  = "v$Version"
$body = "Version $Version"

$headers = @{
    Authorization = "token $Token"
    Accept        = "application/vnd.github+json"
    "User-Agent"  = "aqm-ghl-connector-release-script"
}

# Create release
$releaseData = @{
    tag_name   = $tag
    name       = "Version $Version"
    body       = $body
    draft      = $false
    prerelease = $false
} | ConvertTo-Json -Depth 10

$release = Invoke-RestMethod -Method Post -Uri "https://api.github.com/repos/$Repo/releases" -Headers $headers -Body $releaseData

# Upload asset
$uploadUrl = $release.upload_url -replace "\{\?name,label\}", "?name=$($pluginSlug)-$Version.zip"
$zipBytes  = [System.IO.File]::ReadAllBytes($zipPath)

$assetHeaders = @{
    Authorization = "token $Token"
    "Content-Type" = "application/zip"
    Accept        = "application/vnd.github+json"
    "User-Agent"  = "aqm-ghl-connector-release-script"
}

$assetResponse = Invoke-RestMethod -Method Post -Uri $uploadUrl -Headers $assetHeaders -Body $zipBytes

Write-Host "Release created: $($release.html_url)"
Write-Host "Asset uploaded: $($assetResponse.browser_download_url)"

