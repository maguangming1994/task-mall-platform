param(
    [string]$ApiEnvPath = "apps/api/.env",
    [string]$MiniProgramBuildPath = "apps/user-app/dist/build/mp-weixin",
    [switch]$Strict
)

$ErrorActionPreference = "Stop"
$workspace = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$envFile = Join-Path $workspace $ApiEnvPath
$buildPath = Join-Path $workspace $MiniProgramBuildPath
$values = @{}

if (Test-Path -LiteralPath $envFile) {
    foreach ($line in Get-Content -LiteralPath $envFile) {
        if ($line -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$') {
            $values[$matches[1]] = $matches[2].Trim('"', "'")
        }
    }
}

function Has-Value([string]$name) {
    return $values.ContainsKey($name) -and -not [string]::IsNullOrWhiteSpace([string]$values[$name])
}

$checks = @(
    [pscustomobject]@{ Group = 'Mini Program Build'; Check = 'Build directory'; Ready = (Test-Path -LiteralPath $buildPath); Action = 'Run pnpm --dir apps/user-app build:mp-weixin' },
    [pscustomobject]@{ Group = 'Mini Program Build'; Check = 'app.json'; Ready = (Test-Path -LiteralPath (Join-Path $buildPath 'app.json')); Action = 'Inspect uni-app mini program output' },
    [pscustomobject]@{ Group = 'WeChat Login'; Check = 'Real WeChat adapter'; Ready = ($values['WECHAT_AUTH_DRIVER'] -eq 'wechat'); Action = 'Set WECHAT_AUTH_DRIVER=wechat' },
    [pscustomobject]@{ Group = 'WeChat Login'; Check = 'Mini program identity'; Ready = ((Has-Value 'WECHAT_MINI_APP_ID') -and (Has-Value 'WECHAT_MINI_APP_SECRET')); Action = 'Configure mini program identity in the private local env file' },
    [pscustomobject]@{ Group = 'Network'; Check = 'HTTPS API URL'; Ready = ((Has-Value 'APP_URL') -and $values['APP_URL'].StartsWith('https://')); Action = 'Configure a device-accessible HTTPS API URL' },
    [pscustomobject]@{ Group = 'Network'; Check = 'Authorized domains'; Ready = (Has-Value 'WECHAT_AUTHORIZED_DOMAINS'); Action = 'Register request/upload/download domains in WeChat console' },
    [pscustomobject]@{ Group = 'WeChat Pay'; Check = 'Real payment adapter'; Ready = ($values['WECHAT_PAYMENT_DRIVER'] -eq 'wechat'); Action = 'Set WECHAT_PAYMENT_DRIVER=wechat' },
    [pscustomobject]@{ Group = 'WeChat Pay'; Check = 'Merchant and certificates'; Ready = ((Has-Value 'WECHAT_PAYMENT_MERCHANT_ID') -and (Has-Value 'WECHAT_PAYMENT_API_V3_KEY') -and (Has-Value 'WECHAT_PAYMENT_PRIVATE_KEY_PATH')); Action = 'Configure merchant, APIv3 key and read-only certificate paths' }
)

$checks | Select-Object Group, Check, @{ Name = 'Status'; Expression = { if ($_.Ready) { 'PASS' } else { 'TODO' } } }, @{ Name = 'NextAction'; Expression = { if ($_.Ready) { '-' } else { $_.Action } } } | Format-Table -AutoSize -Wrap
$failed = @($checks | Where-Object { -not $_.Ready }).Count
Write-Host "WeChat device readiness: $($checks.Count - $failed)/$($checks.Count); pending: $failed. No secret values were printed."
if ($Strict -and $failed -gt 0) { exit 1 }
