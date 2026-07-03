# ApexOne Command Center — local dev (PHP 8.3, Laravel, Vite, queue)
$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$php = Join-Path $root "php83\php.exe"

if (-not (Test-Path $php)) {
    Write-Error "PHP 8.3 not found at $php. Extract php83 into the project root or install PHP >= 8.3."
}

if (-not (Test-Path (Join-Path $root "database\database.sqlite"))) {
    New-Item -ItemType File -Path (Join-Path $root "database\database.sqlite") -Force | Out-Null
    & $php (Join-Path $root "artisan") migrate --force
}

Write-Host "Starting Laravel (http://127.0.0.1:8000), Vite, and queue worker..." -ForegroundColor Cyan
Write-Host "Admin login: http://127.0.0.1:8000/admin/login" -ForegroundColor Green

npx concurrently -c "#93c5fd,#c4b5fd,#fdba74" `
    "`"$php`" artisan serve --host=127.0.0.1 --port=8000" `
    "npm run dev" `
    "`"$php`" artisan queue:work --tries=3" `
    --names "server,vite,queue" --kill-others
