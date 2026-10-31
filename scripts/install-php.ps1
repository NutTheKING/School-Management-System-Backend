$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$runtime = Join-Path $projectRoot '.tools/php'
$archive = Join-Path $projectRoot '.tools/php.zip'
New-Item -ItemType Directory -Force $runtime | Out-Null
# Official release metadata supplies the current PHP 8.5 NTS x64 checksum.
$release = (Invoke-RestMethod 'https://downloads.php.net/~windows/releases/releases.json').'8.5'
$package = $release.'nts-vs17-x64'.zip
if (!$package.path -or !$package.sha256) { throw 'PHP release metadata is incomplete' }
Invoke-WebRequest "https://downloads.php.net/~windows/releases/$($package.path)" -OutFile $archive
if ((Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash -ne $package.sha256) {
    throw 'PHP checksum mismatch; installation stopped'
}
Expand-Archive -LiteralPath $archive -DestinationPath $runtime -Force
$extensions = Join-Path $runtime 'ext'
@"
extension_dir="$extensions"
extension=pdo_sqlite
extension=sqlite3
extension=mbstring
date.timezone=Asia/Bangkok
display_errors=Off
log_errors=On
session.upload_progress.enabled=0
"@ | Set-Content (Join-Path $runtime 'php.ini')
& (Join-Path $runtime 'php.exe') -v
if ($LASTEXITCODE -ne 0) { throw 'PHP could not start. Install the Microsoft Visual C++ 2015-2022 x64 Redistributable.' }
