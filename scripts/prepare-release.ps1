param(
    [Parameter(Mandatory = $true)][string]$Version,
    [Parameter(Mandatory = $true)][ValidatePattern('^[a-f0-9]{40}$')][string]$CommitSha
)

$ErrorActionPreference = 'Stop'
if ($Version -notmatch '^[A-Za-z0-9._-]{1,80}$') { throw 'Version contains unsupported characters.' }
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
function Get-TextSha256([string]$Text) {
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($algorithm.ComputeHash([Text.Encoding]::UTF8.GetBytes($Text))) -replace '-', '').ToLowerInvariant() }
    finally { $algorithm.Dispose() }
}

Push-Location $workspace
try {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'uat.ps1') -Release $Version
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    $artifactFiles = Get-ChildItem apps/user-app/dist/build/h5, apps/user-app/dist/build/mp-weixin, apps/admin-web/dist -File -Recurse | Sort-Object FullName
    $artifactLines = $artifactFiles | ForEach-Object { "$($_.FullName.Substring($workspace.Length)):$((Get-FileHash -Algorithm SHA256 -LiteralPath $_.FullName).Hash)" }
    $artifactHash = Get-TextSha256 ($artifactLines -join "`n")
    $configFiles = @('infra/compose.production.yaml', 'infra/nginx/task-mall.conf', 'infra/php/production.ini', 'infra/.env.production.example')
    $configLines = $configFiles | ForEach-Object { "$($_):$((Get-FileHash -Algorithm SHA256 -LiteralPath $_).Hash)" }
    $configurationHash = Get-TextSha256 ($configLines -join "`n")
    Push-Location apps/api
    try { & php artisan release:register $Version $CommitSha $artifactHash $configurationHash; exit $LASTEXITCODE } finally { Pop-Location }
}
finally {
    Pop-Location
}
