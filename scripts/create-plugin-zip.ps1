Param(
    [string]$Version
)

$ErrorActionPreference = "Stop"

$scriptDir   = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent $scriptDir
$pluginSlug  = "aqm-ghl-connector"
$pluginFile  = Join-Path $projectRoot "$pluginSlug.php"

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

$output = Join-Path $projectRoot "$pluginSlug-$Version.zip"

if (Test-Path $output) {
    Remove-Item $output -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zipFileStream = [System.IO.File]::Create($output)
$zip           = New-Object System.IO.Compression.ZipArchive($zipFileStream, [System.IO.Compression.ZipArchiveMode]::Create)

$excludeDirs = @(".git", "scripts", ".vscode")
$excludeFiles = @($output)
$files = Get-ChildItem -Path $projectRoot -Recurse -File | Where-Object {
    foreach ($ex in $excludeDirs) {
        if ($_.FullName -like "*\$ex*") { return $false }
    }
    foreach ($exFile in $excludeFiles) {
        if ($_.FullName -eq $exFile) { return $false }
    }
    return $true
}

foreach ($file in $files) {
    $relativePath = $file.FullName.Substring($projectRoot.Length + 1)
    $entryName    = "$pluginSlug/" + ($relativePath -replace '\\', '/')

    $entry = $zip.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
    $entryStream = $entry.Open()
    $fileStream  = [System.IO.File]::OpenRead($file.FullName)
    $fileStream.CopyTo($entryStream)
    $fileStream.Dispose()
    $entryStream.Dispose()
}

$zip.Dispose()
$zipFileStream.Dispose()

Write-Host "Created $output"

