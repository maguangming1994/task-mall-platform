$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')

Push-Location $workspace
try {
    & pnpm.cmd run typecheck
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & pnpm.cmd run lint
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    & pnpm.cmd run build
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    Push-Location (Join-Path $workspace 'apps\api')
    try {
        & php artisan test
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        & php vendor\bin\pint --test
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
    finally {
        Pop-Location
    }
}
finally {
    Pop-Location
}
