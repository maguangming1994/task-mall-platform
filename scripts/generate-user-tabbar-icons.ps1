param(
    [string]$OutputDirectory = (Join-Path $PSScriptRoot '..\apps\user-app\src\static\tabbar')
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Drawing

$resolvedOutput = [System.IO.Path]::GetFullPath($OutputDirectory)
[System.IO.Directory]::CreateDirectory($resolvedOutput) | Out-Null

function New-Pen([string]$Color) {
    $pen = [System.Drawing.Pen]::new([System.Drawing.ColorTranslator]::FromHtml($Color), 4.4)
    $pen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
    $pen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
    $pen.LineJoin = [System.Drawing.Drawing2D.LineJoin]::Round
    return $pen
}

function New-RoundedRectanglePath([float]$X, [float]$Y, [float]$Width, [float]$Height, [float]$Radius) {
    $path = [System.Drawing.Drawing2D.GraphicsPath]::new()
    $diameter = $Radius * 2
    $path.AddArc($X, $Y, $diameter, $diameter, 180, 90)
    $path.AddArc($X + $Width - $diameter, $Y, $diameter, $diameter, 270, 90)
    $path.AddArc($X + $Width - $diameter, $Y + $Height - $diameter, $diameter, $diameter, 0, 90)
    $path.AddArc($X, $Y + $Height - $diameter, $diameter, $diameter, 90, 90)
    $path.CloseFigure()
    return $path
}

function New-TabIcon([string]$Name, [string]$Color, [scriptblock]$Draw) {
    $bitmap = [System.Drawing.Bitmap]::new(81, 81, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    $graphics.Clear([System.Drawing.Color]::Transparent)
    $graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $pen = New-Pen $Color
    $brush = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml($Color))

    try {
        & $Draw $graphics $pen $brush
        $path = Join-Path $resolvedOutput "$Name.png"
        $bitmap.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
    }
    finally {
        $brush.Dispose()
        $pen.Dispose()
        $graphics.Dispose()
        $bitmap.Dispose()
    }
}

$icons = @{
    home = {
        param($g, $pen, $brush)
        $g.DrawLines($pen, [System.Drawing.PointF[]]@(
            [System.Drawing.PointF]::new(16, 40),
            [System.Drawing.PointF]::new(40.5, 19),
            [System.Drawing.PointF]::new(65, 40)
        ))
        $g.DrawLines($pen, [System.Drawing.PointF[]]@(
            [System.Drawing.PointF]::new(23, 35),
            [System.Drawing.PointF]::new(23, 63),
            [System.Drawing.PointF]::new(58, 63),
            [System.Drawing.PointF]::new(58, 35)
        ))
        $g.DrawRectangle($pen, 35, 48, 11, 15)
    }
    tasks = {
        param($g, $pen, $brush)
        $card = New-RoundedRectanglePath 21 19 40 46 7
        $clip = New-RoundedRectanglePath 31 15 19 10 4
        $whiteBrush = [System.Drawing.SolidBrush]::new([System.Drawing.Color]::White)
        try {
            $g.DrawPath($pen, $card)
            $g.FillPath($whiteBrush, $clip)
            $g.DrawPath($pen, $clip)
            $g.DrawLines($pen, [System.Drawing.PointF[]]@(
                [System.Drawing.PointF]::new(29, 41),
                [System.Drawing.PointF]::new(34, 46),
                [System.Drawing.PointF]::new(43, 36)
            ))
            $g.DrawLine($pen, 47, 40, 53, 40)
            $g.DrawLine($pen, 29, 55, 52, 55)
        }
        finally {
            $whiteBrush.Dispose()
            $card.Dispose()
            $clip.Dispose()
        }
    }
    mall = {
        param($g, $pen, $brush)
        $g.DrawLines($pen, [System.Drawing.PointF[]]@(
            [System.Drawing.PointF]::new(18, 31),
            [System.Drawing.PointF]::new(23, 20),
            [System.Drawing.PointF]::new(58, 20),
            [System.Drawing.PointF]::new(63, 31)
        ))
        $g.DrawLine($pen, 18, 31, 63, 31)
        $g.DrawArc($pen, 18, 25, 15, 15, 0, 180)
        $g.DrawArc($pen, 33, 25, 15, 15, 0, 180)
        $g.DrawArc($pen, 48, 25, 15, 15, 0, 180)
        $g.DrawLines($pen, [System.Drawing.PointF[]]@(
            [System.Drawing.PointF]::new(22, 39),
            [System.Drawing.PointF]::new(22, 63),
            [System.Drawing.PointF]::new(59, 63),
            [System.Drawing.PointF]::new(59, 39)
        ))
        $g.DrawRectangle($pen, 37, 49, 13, 14)
    }
    community = {
        param($g, $pen, $brush)
        $large = New-RoundedRectanglePath 16 19 38 29 9
        $small = New-RoundedRectanglePath 33 37 32 25 8
        try {
            $g.DrawPath($pen, $large)
            $g.DrawLines($pen, [System.Drawing.PointF[]]@(
                [System.Drawing.PointF]::new(25, 47),
                [System.Drawing.PointF]::new(20, 56),
                [System.Drawing.PointF]::new(33, 49)
            ))
            $g.DrawPath($pen, $small)
            $g.DrawLines($pen, [System.Drawing.PointF[]]@(
                [System.Drawing.PointF]::new(55, 61),
                [System.Drawing.PointF]::new(61, 67),
                [System.Drawing.PointF]::new(60, 59)
            ))
            $g.FillEllipse($brush, 27, 31, 4, 4)
            $g.FillEllipse($brush, 35, 31, 4, 4)
            $g.FillEllipse($brush, 43, 31, 4, 4)
        }
        finally {
            $large.Dispose()
            $small.Dispose()
        }
    }
    account = {
        param($g, $pen, $brush)
        $g.DrawEllipse($pen, 31, 17, 20, 20)
        $g.DrawArc($pen, 20, 39, 42, 31, 188, 164)
        $g.DrawLine($pen, 20, 63, 62, 63)
    }
}

foreach ($entry in $icons.GetEnumerator()) {
    New-TabIcon -Name $entry.Key -Color '#7A859B' -Draw $entry.Value
    New-TabIcon -Name "$($entry.Key)-active" -Color '#2F67DF' -Draw $entry.Value
}

Write-Output "Generated 10 tab bar icons in $resolvedOutput"
