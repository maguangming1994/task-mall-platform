param(
    [string]$BackupDirectory = '',
    [string]$ReleaseVersion = 'manual'
)

$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$target = if ($BackupDirectory) { [IO.Path]::GetFullPath($BackupDirectory) } else { [IO.Path]::GetFullPath((Join-Path $workspace 'artifacts\backups')) }
if (-not $BackupDirectory -and -not $target.StartsWith($workspace, [StringComparison]::OrdinalIgnoreCase)) { throw 'Default backup target escaped the workspace.' }
if (-not (Get-Command pg_dump -ErrorAction SilentlyContinue)) { throw 'pg_dump is required and was not found in PATH.' }
foreach ($name in @('PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD')) { if (-not [Environment]::GetEnvironmentVariable($name)) { throw "$name must be supplied through the environment." } }

New-Item -ItemType Directory -Path $target -Force | Out-Null
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$safeVersion = $ReleaseVersion -replace '[^A-Za-z0-9._-]', '_'
$backupPath = Join-Path $target "task-mall-$safeVersion-$timestamp.dump"
& pg_dump --format=custom --no-owner --no-acl --file=$backupPath
if ($LASTEXITCODE -ne 0) { throw "pg_dump failed with exit code $LASTEXITCODE." }

$hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $backupPath).Hash.ToLowerInvariant()
$manifest = [ordered]@{ version = 1; release_version = $ReleaseVersion; created_at = (Get-Date).ToUniversalTime().ToString('o'); database = $env:PGDATABASE; file = [IO.Path]::GetFileName($backupPath); size_bytes = (Get-Item -LiteralPath $backupPath).Length; sha256 = $hash }
$manifestPath = "$backupPath.manifest.json"
$manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding UTF8
$manifest | ConvertTo-Json
