$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$api = Join-Path $workspace 'apps\api'
$env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')

Push-Location $api
try {
    & php artisan migrate --force
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & php artisan db:seed --class='Database\Seeders\DemoDataSeeder' --force
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
} finally {
    Pop-Location
}
