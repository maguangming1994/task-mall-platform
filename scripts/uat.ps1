param(
    [string]$Release = 'local',
    [switch]$ExternalChannels,
    [switch]$RealDevice
)

$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')

Push-Location $workspace
try {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'check.ps1')
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

    Push-Location (Join-Path $workspace 'apps\api')
    try {
        $arguments = @('artisan', 'uat:run', "--release=$Release", '--automated-suite=passed', '--json', '--strict')
        if ($ExternalChannels) { $arguments += '--external-channels' }
        if ($RealDevice) { $arguments += '--real-device' }
        & php @arguments
        exit $LASTEXITCODE
    }
    finally {
        Pop-Location
    }
}
finally {
    Pop-Location
}
