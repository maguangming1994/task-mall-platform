<#
.SYNOPSIS
Publishes a validated production release and creates a WeChat DevTools preview QR code.

.EXAMPLE
powershell -ExecutionPolicy Bypass -File scripts/publish-release.ps1 -Message "Fix account profile"
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$Message,

    [string]$Repository = 'maguangming1994/task-mall-platform',

    [string]$PublicBaseUrl = 'https://rwsc.jxgxnxs.cn',

    [int]$DeploymentTimeoutMinutes = 45,

    [string]$DevToolsCli = $env:WECHAT_DEVTOOLS_CLI
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$releaseScript = Join-Path $PSScriptRoot 'release.ps1'
$previewDirectory = Join-Path $workspace 'artifacts\wechat-preview'

function Invoke-Git {
    $arguments = @($args)
    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& git @arguments 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorAction
    }

    if ($exitCode -ne 0) {
        throw "git $($arguments -join ' ') failed:`n$($output -join [Environment]::NewLine)"
    }

    return @($output | ForEach-Object { $_.ToString() })
}

function Get-GitValue {
    $output = @(Invoke-Git @args)
    if ($output.Count -ne 1) {
        throw "Expected one line from git, received $($output.Count)."
    }

    return [string]$output[0]
}

function Get-ConfiguredProxy {
    $proxy = $env:TASK_MALL_GIT_PROXY
    if ($proxy -eq 'direct') {
        return $null
    }

    if ([string]::IsNullOrWhiteSpace($proxy)) {
        $proxyProbe = Test-NetConnection -ComputerName '127.0.0.1' -Port 8800 -InformationLevel Quiet -WarningAction SilentlyContinue
        if ($proxyProbe) {
            $proxy = 'http://127.0.0.1:8800'
        }
    }

    if ([string]::IsNullOrWhiteSpace($proxy)) {
        return $null
    }

    return $proxy
}

function Get-GitNetworkArguments {
    $proxy = Get-ConfiguredProxy
    if ([string]::IsNullOrWhiteSpace($proxy)) {
        return @()
    }

    Write-Host 'Git network: command-scoped proxy enabled'
    return @('-c', "http.proxy=$proxy", '-c', "https.proxy=$proxy")
}

function Test-RebaseInProgress {
    foreach ($directory in @('rebase-merge', 'rebase-apply')) {
        $path = Get-GitValue rev-parse --git-path $directory
        if (Test-Path -LiteralPath $path) {
            return $true
        }
    }

    return $false
}

function Sync-WorkingTree {
    param([string[]]$GitNetworkArguments)

    $branch = Get-GitValue branch --show-current
    if ($branch -ne 'main') {
        throw "Publishing must run from main. Current branch: $branch"
    }

    $pending = @(& git status --porcelain)
    $stashCreated = $false
    $stashName = "task-mall-publish-sync-$([Guid]::NewGuid().ToString('N'))"

    if ($pending.Count -gt 0) {
        Write-Host '[1/7] Saving local changes before synchronizing main'
        Invoke-Git stash push --include-untracked --message $stashName | Out-Null
        $stashCreated = $true
    }

    try {
        Write-Host '[2/7] Fetching and rebasing onto origin/main'
        Invoke-Git @GitNetworkArguments fetch --prune origin main | Out-Null
        Invoke-Git rebase origin/main | Out-Null
    }
    catch {
        if (Test-RebaseInProgress) {
            Invoke-Git rebase --abort | Out-Null
        }
        throw
    }
    finally {
        if ($stashCreated) {
            Write-Host '[3/7] Restoring local changes on the updated base'
            try {
                Invoke-Git stash pop --index | Out-Null
            }
            catch {
                throw "The local changes conflict with origin/main. Resolve the conflict shown by git status, then rerun this command. The safety stash is retained: $stashName"
            }
        }
    }
}

function Wait-ForProductionDeployment {
    param(
        [Parameter(Mandatory = $true)][string]$Commit,
        [Parameter(Mandatory = $true)][string]$BaseUrl,
        [Parameter(Mandatory = $true)][string]$RepositoryName,
        [Parameter(Mandatory = $true)][int]$TimeoutMinutes
    )

    $deadline = (Get-Date).AddMinutes($TimeoutMinutes)
    $releaseUrl = "$($BaseUrl.TrimEnd('/'))/release.json"
    $actionsUrl = "https://github.com/$RepositoryName/actions"
    do {
        try {
            $cacheBust = [Guid]::NewGuid().ToString('N')
            $release = Invoke-RestMethod -Uri "$releaseUrl?commit=$cacheBust" -Method Get -TimeoutSec 15
            if ($release.release -eq $Commit) {
                Write-Host "Production release marker matched: $Commit"
                return $actionsUrl
            }
        }
        catch {
            # The marker is not available until the first successful deployment of this release.
        }

        Write-Host "Waiting for production release marker: $releaseUrl"
        Start-Sleep -Seconds 15
    } while ((Get-Date) -lt $deadline)

    throw "Timed out waiting for production commit $Commit. Inspect the deployment at $actionsUrl"
}

function Wait-ForPublicHealth {
    param([Parameter(Mandatory = $true)][string]$BaseUrl)

    $healthUrl = "$($BaseUrl.TrimEnd('/'))/api/v1/health/ready"
    for ($attempt = 1; $attempt -le 12; $attempt++) {
        try {
            $response = Invoke-RestMethod -Uri $healthUrl -Method Get -TimeoutSec 15
            if ($response.data.status -eq 'ready') {
                Write-Host "Production health check passed: $healthUrl"
                return
            }
        }
        catch {
            if ($attempt -eq 12) { throw }
        }

        Start-Sleep -Seconds 10
    }

    throw "Production health check did not report ready: $healthUrl"
}

function Resolve-DevToolsCli {
    param([string]$ConfiguredPath)

    $candidates = @($ConfiguredPath) | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }
    foreach ($programFiles in @(${env:ProgramFiles(x86)}, $env:ProgramFiles)) {
        if ([string]::IsNullOrWhiteSpace($programFiles)) {
            continue
        }

        $tencentDirectory = Join-Path $programFiles 'Tencent'
        if (Test-Path -LiteralPath $tencentDirectory -PathType Container) {
            $candidates += @(Get-ChildItem -LiteralPath $tencentDirectory -Filter 'cli.bat' -Recurse -File -ErrorAction SilentlyContinue |
                Select-Object -ExpandProperty FullName)
        }
    }

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }

    throw 'WeChat DevTools CLI was not found. Set WECHAT_DEVTOOLS_CLI to cli.bat.'
}

function Invoke-WechatPreview {
    param(
        [Parameter(Mandatory = $true)][string]$Commit,
        [Parameter(Mandatory = $true)][string]$CliPath
    )

    $project = Join-Path $workspace 'apps\user-app\dist\build\mp-weixin'
    Write-Host '[6/7] Building the WeChat Mini Program bundle'
    & pnpm.cmd run build:user:wechat
    if ($LASTEXITCODE -ne 0) {
        throw "WeChat Mini Program build failed with exit code $LASTEXITCODE."
    }

    if (-not (Test-Path -LiteralPath (Join-Path $project 'app.json'))) {
        throw "WeChat Mini Program output is incomplete: $project"
    }

    New-Item -ItemType Directory -Force -Path $previewDirectory | Out-Null
    $qrPath = Join-Path $previewDirectory "wechat-preview-$Commit.png"
    $infoPath = Join-Path $previewDirectory "wechat-preview-$Commit.json"
    Remove-Item -Force -ErrorAction SilentlyContinue $qrPath, $infoPath

    & $CliPath open --project $project --port 54497 --lang zh
    if ($LASTEXITCODE -ne 0) {
        throw "WeChat DevTools could not open the generated project (exit code $LASTEXITCODE)."
    }

    Write-Host '[7/7] Generating a WeChat DevTools preview QR code'
    & $CliPath preview --project $project --port 54497 --lang zh --qr-format image --qr-output $qrPath --info-output $infoPath
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $qrPath)) {
        throw 'WeChat DevTools did not return a preview QR code. Open the project in DevTools, confirm it is signed in and idle, then rerun this command.'
    }

    return $qrPath
}

Push-Location $workspace
try {
    $machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
    $env:Path = @($env:Path, $machinePath, $userPath) -join ';'

    if ($DeploymentTimeoutMinutes -lt 1 -or $DeploymentTimeoutMinutes -gt 60) {
        throw 'DeploymentTimeoutMinutes must be between 1 and 60.'
    }

    $gitNetworkArguments = @(Get-GitNetworkArguments)
    Sync-WorkingTree -GitNetworkArguments $gitNetworkArguments

    Write-Host '[4/7] Staging the synchronized working tree'
    Invoke-Git add --all | Out-Null

    $staged = @(Invoke-Git diff --cached --name-only)
    if ($staged.Count -eq 0) {
        throw 'There are no changes to publish after synchronization.'
    }

    Write-Host '[5/7] Running the protected Git release'
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $releaseScript -Message $Message
    if ($LASTEXITCODE -ne 0) {
        throw "The Git release failed with exit code $LASTEXITCODE."
    }

    $releaseCommit = Get-GitValue rev-parse HEAD
    $workflowUrl = Wait-ForProductionDeployment -Commit $releaseCommit -BaseUrl $PublicBaseUrl -RepositoryName $Repository -TimeoutMinutes $DeploymentTimeoutMinutes
    Wait-ForPublicHealth -BaseUrl $PublicBaseUrl

    $cliPath = Resolve-DevToolsCli -ConfiguredPath $DevToolsCli
    $qrCode = Invoke-WechatPreview -Commit $releaseCommit -CliPath $cliPath

    Write-Host ''
    Write-Host "Release complete: $releaseCommit"
    Write-Host "Deployment: $workflowUrl"
    Write-Host "Preview QR: $qrCode"
}
finally {
    Pop-Location
}
