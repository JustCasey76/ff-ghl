# Release and Zip Process (ff-ghl / aqm-ghl-connector)

## Versioning
- Update version in `aqm-ghl-connector.php` header and the `AQM_GHL_CONNECTOR_VERSION` constant.
- Tag format: `vX.Y.Z`.

## Build ZIP (forward slashes, single root folder)
```powershell
cd scripts
.\create-plugin-zip.ps1 -Version "1.1.0"
```
Outputs `aqm-ghl-connector-1.1.0.zip` with correct `aqm-ghl-connector/` root and forward-slash paths.

## Create GitHub Release (manual script)
Requires `GITHUB_TOKEN` (repo scope) in env or pass `-Token`.
```powershell
cd scripts
.\create-release.ps1 -Version "1.1.0"
```
Creates tag `v1.1.0`, release, and uploads the ZIP to `JustCasey76/ff-ghl`.

## Notes
- Script excludes `.git`, `scripts`, `.vscode` from the ZIP.
- If you change the plugin slug, update the slug in both scripts.
- For CI automation, mirror the pattern from `aqm-chatbot` (GitHub Actions) if desired.

