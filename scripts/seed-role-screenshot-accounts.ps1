param()

$projectRoot = Split-Path -Parent $PSScriptRoot
$apiRoot = Join-Path $projectRoot 'apps\api'

Push-Location $apiRoot
try {
    php artisan db:seed --class='Database\Seeders\RoleScreenshotAccountSeeder' --force
}
finally {
    Pop-Location
}
