param(
    [Parameter(Mandatory = $true)][string]$BackupPath
)

$ErrorActionPreference = 'Stop'
$resolvedBackup = (Resolve-Path -LiteralPath $BackupPath).Path
$manifestPath = "$resolvedBackup.manifest.json"
if (-not (Test-Path -LiteralPath $manifestPath)) { throw 'Backup manifest is missing.' }
foreach ($command in @('createdb', 'pg_restore', 'dropdb')) { if (-not (Get-Command $command -ErrorAction SilentlyContinue)) { throw "$command is required and was not found in PATH." } }
foreach ($name in @('PGHOST', 'PGPORT', 'PGUSER', 'PGPASSWORD')) { if (-not [Environment]::GetEnvironmentVariable($name)) { throw "$name must be supplied through the environment." } }

$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$actualHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $resolvedBackup).Hash.ToLowerInvariant()
if ($actualHash -ne $manifest.sha256) { throw 'Backup checksum does not match its manifest.' }
$drillDatabase = 'task_mall_restore_drill_' + (Get-Date -Format 'yyyyMMddHHmmss') + '_' + (Get-Random -Minimum 1000 -Maximum 9999)
if ($drillDatabase -notmatch '^task_mall_restore_drill_[0-9]{14}_[0-9]{4}$') { throw 'Generated drill database name failed validation.' }

try {
    & createdb $drillDatabase
    if ($LASTEXITCODE -ne 0) { throw 'Unable to create isolated restore-drill database.' }
    & pg_restore --exit-on-error --no-owner --no-acl --dbname=$drillDatabase $resolvedBackup
    if ($LASTEXITCODE -ne 0) { throw 'Restore drill failed.' }
    [ordered]@{ restored = $true; database = $drillDatabase; backup_sha256 = $actualHash; completed_at = (Get-Date).ToUniversalTime().ToString('o') } | ConvertTo-Json
}
finally {
    if ($drillDatabase -match '^task_mall_restore_drill_[0-9]{14}_[0-9]{4}$') { & dropdb --if-exists $drillDatabase | Out-Null }
}
