$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$compose = Join-Path $workspace 'infra\compose.yaml'
$envFile = Join-Path $workspace 'infra\.env'

& docker compose --env-file $envFile -f $compose down
exit $LASTEXITCODE
