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

function Test-HttpProxyTunnel {
    param(
        [Parameter(Mandatory = $true)]
        [string]$HostName,
        [Parameter(Mandatory = $true)]
        [int]$Port,
        [string]$TargetHost = 'github.com',
        [int]$TargetPort = 443,
        [int]$TimeoutMilliseconds = 3000
    )

    $client = [Net.Sockets.TcpClient]::new()
    try {
        $connectTask = $client.ConnectAsync($HostName, $Port)
        if (-not $connectTask.Wait($TimeoutMilliseconds)) { return $false }
        if (-not $client.Connected) { return $false }

        $stream = $client.GetStream()
        $stream.ReadTimeout = $TimeoutMilliseconds
        $stream.WriteTimeout = $TimeoutMilliseconds
        $request = "CONNECT ${TargetHost}:${TargetPort} HTTP/1.1`r`nHost: ${TargetHost}:${TargetPort}`r`nProxy-Connection: Keep-Alive`r`n`r`n"
        $requestBytes = [Text.Encoding]::ASCII.GetBytes($request)
        $stream.Write($requestBytes, 0, $requestBytes.Length)
        $stream.Flush()

        $reader = [IO.StreamReader]::new($stream, [Text.Encoding]::ASCII, $false, 1024, $true)
        $statusLine = $reader.ReadLine()
        if ($statusLine -notmatch '^HTTP/\d(?:\.\d)?\s+200\b') { return $false }
        while ($reader.ReadLine()) { }

        $sslStream = [Net.Security.SslStream]::new($stream, $false)
        $tlsTask = $sslStream.AuthenticateAsClientAsync($TargetHost)
        if (-not $tlsTask.Wait($TimeoutMilliseconds)) { return $false }
        return $sslStream.IsAuthenticated
    }
    catch {
        return $false
    }
    finally {
        $client.Dispose()
    }
}

function Get-GitNetworkArguments {
    $proxy = $env:TASK_MALL_GIT_PROXY
    if ($proxy -eq 'direct') {
        Write-Host 'Git network: explicitly using direct connection'
        return @()
    }

    if ([string]::IsNullOrWhiteSpace($proxy)) {
        $proxyReady = Test-HttpProxyTunnel -HostName '127.0.0.1' -Port 8800

        if ($proxyReady) {
            $proxy = 'http://127.0.0.1:8800'
        }
        elseif (Get-Process -Name 'QuickQ', 'quickqservice-g90' -ErrorAction SilentlyContinue) {
            throw "QuickQ is running but its HTTP proxy is unavailable at 127.0.0.1:8800. Reconnect QuickQ before releasing, or set TASK_MALL_GIT_PROXY=direct to explicitly use the direct route."
        }
    }

    if ([string]::IsNullOrWhiteSpace($proxy)) {
        Write-Host 'Git network: direct connection'
        return @()
    }

    Write-Host 'Git network: command-scoped proxy enabled'
    return @('-c', "http.proxy=$proxy", '-c', "https.proxy=$proxy")
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
        $previousErrorAction = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        try {
            & git cat-file -e ":$path" 2>$null
            $existsInIndex = $LASTEXITCODE -eq 0
        }
        finally {
            $ErrorActionPreference = $previousErrorAction
        }
        if (-not $existsInIndex) { continue }

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
        $machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
        $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
        $env:Path = @($env:Path, $machinePath, $userPath) -join ';'
        $gitCommand = Get-Command git -ErrorAction SilentlyContinue
        if (-not $gitCommand) { throw 'Git was not found.' }
        $gitRoot = Split-Path (Split-Path $gitCommand.Source -Parent) -Parent
        $gitRuntimeBin = Join-Path $gitRoot 'mingw64\bin'
        if (Test-Path -LiteralPath (Join-Path $gitRuntimeBin 'git-remote-https.exe')) {
            $env:GIT_EXEC_PATH = $gitRuntimeBin
        }
        $gitNetworkArguments = @(Get-GitNetworkArguments)

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
        Invoke-Git @gitNetworkArguments fetch --prune origin main | Out-Null
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
        Invoke-Git @gitNetworkArguments push origin HEAD:main | ForEach-Object { Write-Host $_ }
        Invoke-Git @gitNetworkArguments fetch origin main | Out-Null
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
