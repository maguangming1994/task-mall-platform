$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$compose = Join-Path $workspace 'infra\compose.yaml'
$envFile = Join-Path $workspace 'infra\.env'

if (-not (Test-Path -LiteralPath $envFile)) {
    Copy-Item -LiteralPath (Join-Path $workspace 'infra\.env.example') -Destination $envFile
}

& docker compose --env-file $envFile -f $compose up -d --wait postgres redis minio
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& docker compose --env-file $envFile -f $compose run --rm minio-init
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
& docker compose --env-file $envFile -f $compose ps
