param(
    [string]$BaseUrl = 'http://127.0.0.1:8000/api/v1',
    [int]$Requests = 200,
    [int]$Concurrency = 20,
    [int]$MaxP95Ms = 500
)

$ErrorActionPreference = 'Stop'
if ($Requests -lt 1 -or $Concurrency -lt 1 -or $Concurrency -gt 100) {
    throw 'Requests must be positive and Concurrency must be between 1 and 100.'
}

$client = [Net.Http.HttpClient]::new()
$client.Timeout = [TimeSpan]::FromSeconds(10)
$latencies = [Collections.Generic.List[double]]::new()
$failures = 0

try {
    for ($offset = 0; $offset -lt $Requests; $offset += $Concurrency) {
        $batchSize = [Math]::Min($Concurrency, $Requests - $offset)
        $items = for ($index = 0; $index -lt $batchSize; $index++) {
            $watch = [Diagnostics.Stopwatch]::StartNew()
            [pscustomobject]@{ Watch = $watch; Task = $client.GetAsync("$BaseUrl/health/live") }
        }
        foreach ($item in $items) {
            try {
                $response = $item.Task.GetAwaiter().GetResult()
                $item.Watch.Stop()
                $latencies.Add($item.Watch.Elapsed.TotalMilliseconds)
                if (-not $response.IsSuccessStatusCode) { $failures++ }
                $response.Dispose()
            }
            catch {
                $item.Watch.Stop()
                $latencies.Add($item.Watch.Elapsed.TotalMilliseconds)
                $failures++
            }
        }
    }
}
finally {
    $client.Dispose()
}

$sorted = $latencies | Sort-Object
$p95Index = [Math]::Max(0, [Math]::Ceiling($sorted.Count * 0.95) - 1)
$p95 = [Math]::Round($sorted[$p95Index], 2)
$average = [Math]::Round(($latencies | Measure-Object -Average).Average, 2)
$result = [ordered]@{ requests = $Requests; concurrency = $Concurrency; failures = $failures; average_ms = $average; p95_ms = $p95; target_p95_ms = $MaxP95Ms }
$result | ConvertTo-Json

if ($failures -gt 0 -or $p95 -gt $MaxP95Ms) { exit 1 }
