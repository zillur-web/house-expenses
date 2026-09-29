$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot
$databaseConfig = Join-Path $projectRoot 'runtime/mysql/my.ini'
if (-not (Test-Path -LiteralPath $databaseConfig)) { throw 'Local database is not initialized. See README.md.' }
if (-not (Get-NetTCPConnection -LocalPort 3307 -State Listen -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath 'C:/xampp/mysql/bin/mysqld.exe' -ArgumentList ('--defaults-file="{0}" --bind-address=127.0.0.1' -f $databaseConfig) -WindowStyle Hidden -WorkingDirectory $projectRoot
}
if (-not (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath 'C:/xampp/php/php.exe' -ArgumentList @('artisan','serve','--host=127.0.0.1','--port=8000') -WindowStyle Hidden -WorkingDirectory (Join-Path $projectRoot 'app')
}
Write-Host 'Home Bazar: http://127.0.0.1:8000'
Write-Host 'Local credentials are in LOCAL-LOGIN.txt.'
