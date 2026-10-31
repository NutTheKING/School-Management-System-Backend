param(
    [ValidateRange(1024,65535)][int]$Port = 8010,
    [string]$BindAddress = '127.0.0.1'
)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$php = Join-Path $projectRoot '.tools/php/php.exe'
if (!(Test-Path -LiteralPath $php)) {
    $php = (Get-Command php -ErrorAction Stop).Source
}
$extensions = & $php -m
foreach ($extension in @('pdo_sqlite', 'mbstring')) {
    if ($extensions -notcontains $extension) { throw "PHP extension required: $extension" }
}
Write-Host "Web: http://localhost:$Port/"
Write-Host "Android emulator API: http://10.0.2.2:$Port/api.php"
Write-Host 'For a physical phone use -BindAddress 0.0.0.0 and your computer LAN IP.'
& $php -S "${BindAddress}:$Port" -t (Join-Path $projectRoot 'public') (Join-Path $projectRoot 'router.php')
