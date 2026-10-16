param([Parameter(Mandatory=$true)][string]$Source, [Parameter(Mandatory=$true)][ValidateSet('en','de_DE','uk','pl_PL')][string]$Locale)
# Mechanical resizing of imagegen artwork only; no painting or text replacement.
Add-Type -AssemblyName System.Drawing
$assetDirectory = Join-Path (Split-Path $PSScriptRoot) 'wordpressorg-assets'
$original = [System.Drawing.Image]::FromFile((Resolve-Path -LiteralPath $Source).Path)
try {
    foreach ($size in @(@(772,250), @(1544,500))) {
        $bitmap = New-Object System.Drawing.Bitmap($size[0],$size[1])
        $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
        try {
            $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
            $graphics.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
            $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
            $graphics.DrawImage($original,0,0,$size[0],$size[1])
            $suffix = if ($Locale -eq 'en') { '' } else { "-$Locale" }
            $name = "banner-$($size[0])x$($size[1])$suffix.png"
            $bitmap.Save((Join-Path $assetDirectory $name),[System.Drawing.Imaging.ImageFormat]::Png)
        } finally { $graphics.Dispose(); $bitmap.Dispose() }
    }
} finally { $original.Dispose() }
