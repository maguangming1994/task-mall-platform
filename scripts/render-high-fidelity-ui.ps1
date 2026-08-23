param([string]$OutputDirectory = '')

$ErrorActionPreference = 'Stop'

$chromeCandidates = @(
    'C:\Program Files\Google\Chrome\Application\chrome.exe',
    'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
    'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
    'C:\Program Files\Microsoft\Edge\Application\msedge.exe'
)

$browserPath = $chromeCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (-not $browserPath) { throw 'Chrome or Edge was not found.' }

$projectRoot = Split-Path -Parent $PSScriptRoot
$htmlFile = Get-ChildItem -LiteralPath (Join-Path $projectRoot 'design\ui') -Recurse -Filter 'index.html' |
    Where-Object { Test-Path -LiteralPath (Join-Path $_.DirectoryName 'styles.css') } |
    Select-Object -First 1
if (-not $htmlFile) { throw 'High fidelity UI entry was not found.' }
$htmlPath = $htmlFile.FullName
if (-not $OutputDirectory) { $OutputDirectory = Join-Path $htmlFile.DirectoryName 'PNG' }

New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null

$boards = @(
    @{ Id = 'board-01'; File = '01-visitor-and-login.png' },
    @{ Id = 'board-02'; File = '02-task-detail-and-claim.png' },
    @{ Id = 'board-03'; File = '03-task-execution-and-review.png' },
    @{ Id = 'board-04'; File = '04-mall-and-checkout.png' },
    @{ Id = 'board-05'; File = '05-orders-and-after-sales.png' },
    @{ Id = 'board-06'; File = '06-assets-and-referral.png' },
    @{ Id = 'board-07'; File = '07-community-and-messages.png' },
    @{ Id = 'board-08'; File = '08-account-privacy-address.png' },
    @{ Id = 'board-09'; File = '09-publisher-basics-and-budget.png' },
    @{ Id = 'board-10'; File = '10-publisher-steps-and-review.png' }
)

$fileUrl = 'file:///' + ($htmlPath -replace '\\', '/')
$profilePath = Join-Path $env:TEMP 'task-mall-hifi-render-profile'

foreach ($board in $boards) {
    $outputPath = Join-Path $OutputDirectory $board.File
    $url = "${fileUrl}?board=$($board.Id)"
    $arguments = @(
        '--headless=new',
        '--disable-gpu',
        '--hide-scrollbars',
        '--allow-file-access-from-files',
        '--force-device-scale-factor=1',
        '--window-size=1800,1000',
        '--virtual-time-budget=2500',
        '--run-all-compositor-stages-before-draw',
        "--user-data-dir=$profilePath-$($board.Id)",
        "--screenshot=$outputPath",
        $url
    )

    & $browserPath @arguments | Out-Null
    if (-not (Test-Path -LiteralPath $outputPath)) {
        throw "Export failed: $outputPath"
    }
}

Get-ChildItem -LiteralPath $OutputDirectory -Filter '*.png' | Sort-Object Name | Select-Object Name, Length, LastWriteTime
