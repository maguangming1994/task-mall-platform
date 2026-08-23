[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$Message
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$mutex = New-Object Threading.Mutex($false, 'TaskMallProductionRelease')
$hasMutex = $false

function Invoke-Git {
    $gitArguments = @($args)
    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& git @gitArguments 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorAction
    }
    if ($exitCode -ne 0) {
        throw "git $($gitArguments -join ' ') failed:`n$($output -join [Environment]::NewLine)"
    }
    return @($output | ForEach-Object { $_.ToString() })
}

function Get-GitScalar {
    $output = @(Invoke-Git @args)
    if ($output.Count -ne 1) {
        throw "Expected one line from git, received $($output.Count)."
    }
    return [string]$output[0]
}

function Test-ForbiddenPath {
    param([string]$Path)

    $normalized = $Path.Replace('\', '/')
    if ($normalized -match '(^|/)\.secrets(/|$)') { return $true }
    if ($normalized -match '(^|/)\.env($|\.)' -and $normalized -notmatch '\.example$') { return $true }
    if ($normalized -match '(?i)\.(pem|key|p12|pfx|crt|cer|kdbx|dump|sql|bak|backup)$') { return $true }
    if ($normalized -match '(?i)(^|/)(id_rsa|id_ed25519)$') { return $true }
    return $false
}

function Test-StagedContent {
    param([string[]]$Paths)

    $patterns = @(
        ('-----BEGIN ' + '(?:RSA |EC |OPENSSH )?PRIVATE KEY-----'),
        ('gh' + 'p_[A-Za-z0-9]{30,}'),
        ('github_pat' + '_[A-Za-z0-9_]{20,}'),
        ('AK' + 'IA[0-9A-Z]{16}')
    )

    foreach ($path in $Paths) {
        & git cat-file -e ":$path" 2>$null
        if ($LASTEXITCODE -ne 0) { continue }

        $size = [int64](Get-GitScalar cat-file -s ":$path")
        if ($size -gt 1048576) { continue }

        $content = (Invoke-Git show ":$path") -join "`n"
        foreach ($pattern in $patterns) {
            if ($content -match $pattern) {
                throw "A staged file may contain a credential or private key: $path"
            }
        }
    }
}

try {
    $hasMutex = $mutex.WaitOne(0)
    if (-not $hasMutex) { throw 'Another local release is already running.' }

    Push-Location $workspace
    try {
        $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
        if (-not (Get-Command git -ErrorAction SilentlyContinue)) { throw 'Git was not found.' }

        $branch = Get-GitScalar branch --show-current
        if ($branch -ne 'main') { throw "Releases must run from main. Current branch: $branch" }

        foreach ($operation in @('MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'BISECT_LOG')) {
            $operationPath = Get-GitScalar rev-parse --git-path $operation
            if (Test-Path -LiteralPath $operationPath) { throw "A Git operation is still in progress: $operation" }
        }
        foreach ($operationDirectory in @('rebase-merge', 'rebase-apply')) {
            $operationPath = Get-GitScalar rev-parse --git-path $operationDirectory
            if (Test-Path -LiteralPath $operationPath) { throw "A Git operation is still in progress: $operationDirectory" }
        }

        $staged = @(Invoke-Git -c core.quotePath=false diff --cached --name-only --diff-filter=ACMRDTUXB)
        if ($staged.Count -eq 0) {
            throw 'No staged changes. Select release files with git add <file> first.'
        }

        $unstaged = @(Invoke-Git -c core.quotePath=false diff --name-only)
        $untracked = @(Invoke-Git -c core.quotePath=false ls-files --others --exclude-standard)
        if ($unstaged.Count -gt 0 -or $untracked.Count -gt 0) {
            $remaining = @($unstaged + $untracked | Sort-Object -Unique)
            throw "Unstaged or untracked files remain. Stage or move them before release:`n$($remaining -join [Environment]::NewLine)"
        }

        $forbidden = @($staged | Where-Object { Test-ForbiddenPath $_ })
        if ($forbidden.Count -gt 0) {
            throw "The index contains a forbidden environment, key, certificate, or backup file:`n$($forbidden -join [Environment]::NewLine)"
        }

        Test-StagedContent $staged
        Invoke-Git diff --cached --check | Out-Null

        Write-Host '[1/4] Fetching origin/main'
        Invoke-Git fetch --prune origin main | Out-Null
        $localSha = Get-GitScalar rev-parse HEAD
        $remoteSha = Get-GitScalar rev-parse origin/main
        if ($localSha -ne $remoteSha) {
            throw "Local main must equal origin/main before release. local=$localSha remote=$remoteSha"
        }

        $indexBefore = Get-GitScalar write-tree
        Write-Host '[2/4] Running the full code check'
        & (Join-Path $PSScriptRoot 'check.ps1')
        if ($LASTEXITCODE -ne 0) { throw "Code checks failed with exit code $LASTEXITCODE." }
        $indexAfter = Get-GitScalar write-tree
        if ($indexBefore -ne $indexAfter) { throw 'The staged tree changed during checks. Release stopped.' }

        Write-Host '[3/4] Creating the commit'
        Invoke-Git commit -m $Message | ForEach-Object { Write-Host $_ }
        $releaseSha = Get-GitScalar rev-parse HEAD

        Write-Host '[4/4] Pushing main; production follows successful CI'
        Invoke-Git push origin HEAD:main | ForEach-Object { Write-Host $_ }
        Invoke-Git fetch origin main | Out-Null
        $publishedSha = Get-GitScalar rev-parse origin/main
        if ($publishedSha -ne $releaseSha) {
            throw "Remote commit verification failed. expected=$releaseSha actual=$publishedSha"
        }

        Write-Host "Pushed: $releaseSha"
        Write-Host 'GitHub deploys production serially after every CI job passes.'
    }
    finally {
        Pop-Location
    }
}
finally {
    if ($hasMutex) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
