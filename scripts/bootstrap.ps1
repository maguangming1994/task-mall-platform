$ErrorActionPreference = 'Stop'

$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')

if (-not (Test-Path -LiteralPath (Join-Path $workspace 'apps\api\.env'))) {
    Copy-Item -LiteralPath (Join-Path $workspace 'apps\api\.env.example') -Destination (Join-Path $workspace 'apps\api\.env')
}

if (-not (Test-Path -LiteralPath (Join-Path $workspace 'infra\.env'))) {
    Copy-Item -LiteralPath (Join-Path $workspace 'infra\.env.example') -Destination (Join-Path $workspace 'infra\.env')
}

Push-Location $workspace
try {
    & pnpm.cmd install --frozen-lockfile
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
finally {
    Pop-Location
}

Push-Location (Join-Path $workspace 'apps\api')
try {
    & composer.cmd install --no-interaction --prefer-dist
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    $appKeyConfigured = Select-String -LiteralPath '.env' -Pattern '^APP_KEY=.+$' -Quiet
    if (-not $appKeyConfigured) {
        & php artisan key:generate
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
}
finally {
    Pop-Location
}

Write-Host 'Bootstrap complete. Start infrastructure with scripts/dev-infra.ps1.' -ForegroundColor Green
