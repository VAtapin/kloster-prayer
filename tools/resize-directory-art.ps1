param(
    [Parameter(Mandatory=$true)][string]$IconSource,
    [Parameter(Mandatory=$true)][string]$BannerSource
)
# Mechanical export only: artwork and wording are created with imagegen.
Add-Type -AssemblyName System.Drawing
$assetDirectory = Join-Path (Split-Path $PSScriptRoot) 'wordpressorg-assets'
New-Item -ItemType Directory -Path $assetDirectory -Force | Out-Null
function Export-Asset([string]$Source, [string]$Name, [int]$Width, [int]$Height) {
    $original = [System.Drawing.Image]::FromFile((Resolve-Path -LiteralPath $Source).Path)
    $bitmap = New-Object System.Drawing.Bitmap($Width,$Height)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    try {
        $graphics.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
        $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
        $graphics.DrawImage($original,0,0,$Width,$Height)
        $bitmap.Save((Join-Path $assetDirectory $Name),[System.Drawing.Imaging.ImageFormat]::Png)
    } finally {
        $graphics.Dispose(); $bitmap.Dispose(); $original.Dispose()
    }
}
Export-Asset $IconSource 'icon-256x256.png' 256 256
Export-Asset $IconSource 'icon-128x128.png' 128 128
Export-Asset $BannerSource 'banner-1544x500.png' 1544 500
Export-Asset $BannerSource 'banner-772x250.png' 772 250
