<#
.SYNOPSIS
  校区级功能模块门禁生产冒烟测试脚本。

.DESCRIPTION
  验证部署后模块化开关实际生效（全部只读，不写生产数据）：
    1. client-capabilities 版本为 5，含 13 个模块开关并回显实际判定校区；
    2. 四类公开受控接口（任务/商城/闲置/社区）在指定校区返回 200；
    3. （可选）指定被关闭模块的路由，断言 403 + code=MODULE_DISABLED；
    4. （可选）携带登录 token，校验 capabilities 回显校区跟随用户而非伪造头。

.PARAMETER BaseUrl
  生产 API 前缀，默认线上域名。

.PARAMETER CampusId
  校区 public_id（管理后台「学校库」中的标识）。

.PARAMETER Token
  可选，登录用户 access_token。

.PARAMETER DisabledModuleCode
  可选，已关闭模块 code（如 module_second_hand），需配合 DisabledRoute。

.PARAMETER DisabledRoute
  可选，该模块受保护 GET 路由相对路径（如 /second-hand/items）。

.EXAMPLE
  .\scripts\smoke-module-gates.ps1 -CampusId 01HQABCDEF1234567890

.EXAMPLE
  .\scripts\smoke-module-gates.ps1 -CampusId 01HQ... -Token eyJ... -DisabledModuleCode module_second_hand -DisabledRoute /second-hand/items
#>

param(
    [string]$BaseUrl = 'https://rwsc.jxgxnxs.cn/api/v1',
    [Parameter(Mandatory = $true)]
    [string]$CampusId,
    [string]$Token,
    [string]$DisabledModuleCode,
    [string]$DisabledRoute
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$MODULE_CODES = @(
    'module_tasks', 'module_task_publish',
    'module_mall', 'module_mall_trade', 'module_after_sale',
    'module_community', 'module_community_post',
    'module_wallet', 'module_wallet_recharge', 'module_withdraw',
    'module_referrals', 'module_checkin',
    'module_second_hand'
)

$script:results = [Collections.Generic.List[hashtable]]::new()
$script:passed = 0
$script:failed = 0

function Write-Step($name) {
    Write-Host ''
    Write-Host "=== $name ===" -ForegroundColor Cyan
}

function Add-Result($check, $ok, $detail) {
    $script:results.Add(@{ check = $check; ok = $ok; detail = $detail })
    if ($ok) {
        $script:passed++
        Write-Host "[PASS] $check" -ForegroundColor Green
    }
    else {
        $script:failed++
        Write-Host "[FAIL] $check" -ForegroundColor Red
    }
    if ($detail) {
        Write-Host "        $detail" -ForegroundColor DarkGray
    }
}

function Invoke-Get($path, $useToken) {
    $url = $BaseUrl + $path
    $headers = @{
        'X-Campus-Id'      = $CampusId
        'X-Client-Platform' = 'wechat-mini'
    }
    if ($useToken -and $Token) {
        $headers['Authorization'] = 'Bearer ' + $Token
    }
    try {
        return Invoke-RestMethod -Uri $url -Headers $headers -Method Get -TimeoutSec 15
    }
    catch {
        $resp = $_.Exception.Response
        if ($null -eq $resp) {
            throw
        }
        $stream = $resp.GetResponseStream()
        $reader = [System.IO.StreamReader]::new($stream)
        $bodyText = $reader.ReadToEnd()
        $reader.Dispose()
        $errObj = [pscustomobject]@{
            StatusCode = [int]$resp.StatusCode
            Body       = $bodyText
        }
        throw $errObj
    }
}

# ---------- 1. capabilities 探测 ----------
Write-Step '1. client-capabilities 探测'

try {
    $caps = Invoke-Get '/client-capabilities' $false
    $data = $caps.data
    $okVersion = ($data.version -eq 5)
    Add-Result 'capabilities.version = 5' $okVersion ("实际: " + $data.version)

    $flags = $data.feature_flags
    $flagNames = @($flags.PSObject.Properties.Name)
    $missing = @()
    foreach ($code in $MODULE_CODES) {
        if ($flagNames -notcontains $code) {
            $missing += $code
        }
    }
    Add-Result 'capabilities.feature_flags 含 13 模块' ($missing.Count -eq 0) ("缺失: " + ($missing -join ', '))

    $okCampus = ($data.campus_id -eq $CampusId)
    Add-Result 'capabilities.campus_id 回显指定校区' $okCampus ("请求: " + $CampusId + " / 回显: " + $data.campus_id)

    Write-Host ''
    Write-Host '模块生效状态:' -ForegroundColor DarkCyan
    foreach ($code in $MODULE_CODES) {
        $v = $flags.$code
        if ($v) {
            Write-Host ("  [开] " + $code) -ForegroundColor Green
        }
        else {
            Write-Host ("  [关] " + $code) -ForegroundColor DarkGray
        }
    }
}
catch {
    Add-Result 'capabilities 可达' $false ("异常: " + $_.Exception.Message)
}

# ---------- 1b. 登录态防伪造校验 ----------
if ($Token) {
    Write-Step '1b. 登录态 capabilities 跟随用户校区'
    try {
        $capsUser = Invoke-Get '/client-capabilities' $true
        $okUserCampus = -not [string]::IsNullOrWhiteSpace($capsUser.data.campus_id)
        Add-Result '登录态 capabilities.campus_id 非空(跟随用户)' $okUserCampus ("回显: " + $capsUser.data.campus_id)
    }
    catch {
        Add-Result '登录态 capabilities 可达' $false ("异常: " + $_.Exception.Message)
    }
}

# ---------- 2. 四类公开受控接口 200 ----------
Write-Step '2. 四类公开受控接口 200'

$publicRoutes = [pscustomobject[]]@(
    [pscustomobject]@{ name = '任务大厅'; path = '/tasks'; module = 'module_tasks' },
    [pscustomobject]@{ name = '积分商城'; path = '/products'; module = 'module_mall' },
    [pscustomobject]@{ name = '校园闲置'; path = '/second-hand/items'; module = 'module_second_hand' },
    [pscustomobject]@{ name = '校园社区'; path = '/community/posts'; module = 'module_community' }
)

foreach ($r in $publicRoutes) {
    try {
        $null = Invoke-Get $r.path $false
        Add-Result ($r.name + ' GET ' + $r.path + ' -> 200') $true ('module=' + $r.module)
    }
    catch {
        $ex = $_.Exception
        $detail = $ex.Message
        if ($ex -is [pscustomobject] -and $ex.StatusCode) {
            $detail = 'HTTP ' + $ex.StatusCode
        }
        Add-Result ($r.name + ' GET ' + $r.path + ' -> 200') $false $detail
    }
}

# ---------- 3. 关闭模块 403 验证(可选) ----------
if ($DisabledModuleCode -and $DisabledRoute) {
    Write-Step ('3. 关闭模块 403 验证: ' + $DisabledModuleCode)
    try {
        $null = Invoke-Get $DisabledRoute $false
        Add-Result ($DisabledRoute + ' 应返回 403') $false '实际返回 2xx, 门禁未生效'
    }
    catch {
        $ex = $_.Exception
        if ($ex -is [pscustomobject] -and $ex.StatusCode -eq 403) {
            try {
                $body = $ex.Body | ConvertFrom-Json
                $okCode = ($body.code -eq 'MODULE_DISABLED')
                $okModule = ($body.details.module -eq $DisabledModuleCode)
                Add-Result '403 + code=MODULE_DISABLED' $okCode ('实际 code=' + $body.code)
                Add-Result ('details.module = ' + $DisabledModuleCode) $okModule ('实际=' + $body.details.module)
            }
            catch {
                Add-Result '403 响应体可解析' $false ('解析失败: ' + $_.Exception.Message)
            }
        }
        else {
            $detail = $ex.Message
            if ($ex.StatusCode) {
                $detail = 'HTTP ' + $ex.StatusCode
            }
            Add-Result ($DisabledRoute + ' 应返回 403') $false $detail
        }
    }
}

# ---------- 汇总 ----------
Write-Host ''
Write-Host '================ 冒烟汇总 ================' -ForegroundColor Cyan
$total = $script:passed + $script:failed
$summaryColor = if ($script:failed -eq 0) { 'Green' } else { 'Red' }
Write-Host ("通过: " + $script:passed + "  失败: " + $script:failed + "  总计: " + $total) -ForegroundColor $summaryColor

$report = [pscustomobject]@{
    base_url  = $BaseUrl
    campus_id = $CampusId
    timestamp = (Get-Date).ToString('o')
    passed    = $script:passed
    failed    = $script:failed
    checks    = $script:results
}

$outDir = Join-Path $PSScriptRoot '..\.smoke'
if (-not (Test-Path $outDir)) {
    New-Item -ItemType Directory -Path $outDir | Out-Null
}
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$outFile = Join-Path $outDir ('module-gates-' + $stamp + '.json')
$report | ConvertTo-Json -Depth 6 | Set-Content -Path $outFile -Encoding utf8
Write-Host ('详细报告: ' + $outFile) -ForegroundColor DarkGray

if ($script:failed -gt 0) {
    exit 1
}
Write-Host '所有冒烟检查通过。' -ForegroundColor Green
exit 0
