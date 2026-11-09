[CmdletBinding()]
param(
    [string]$PackageDir = (Join-Path $PSScriptRoot 'build\package')
)

$ErrorActionPreference = 'Stop'
$package = [System.IO.Path]::GetFullPath($PackageDir)
$runtimeCache = Join-Path $PSScriptRoot 'bin\webview2'
$webviewExecutableName = 'msedgewebview2.exe'
$webviewCabName = 'Microsoft.WebView2.FixedVersionRuntime.156.0.4314.58.x64.cab'
$webviewCabHash = '857DCFD6F7035508A29645FA2978C3A6A841F220DF77CC366D318894E19F4792'
$webviewCabUrl = 'https://msedge.sf.dl.delivery.mp.microsoft.com/filestreamingservice/files/b7919cc9-680d-4253-bf32-732988e68aa6/Microsoft.WebView2.FixedVersionRuntime.156.0.4314.58.x64.cab'

function Assert-FixedRuntime {
    param([string]$Directory)

    $runtimeExecutable = Join-Path $Directory $webviewExecutableName
    if (-not (Test-Path -LiteralPath $runtimeExecutable -PathType Leaf)) {
        return $false
    }
    $signature = Get-AuthenticodeSignature -LiteralPath $runtimeExecutable
    if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'Microsoft') {
        throw "The fixed WebView2 runtime is not signed by Microsoft: $runtimeExecutable"
    }
    return $true
}

$webviewExe = Join-Path $runtimeCache $webviewExecutableName
if (-not (Test-Path -LiteralPath $webviewExe)) {
    $desktopRuntime = Join-Path (Split-Path -Parent $PSScriptRoot) 'desktop\build\package\bin\webview2'
    if (Assert-FixedRuntime -Directory $desktopRuntime) {
        New-Item -ItemType Directory -Force -Path $runtimeCache | Out-Null
        Get-ChildItem -LiteralPath $desktopRuntime -Force | Copy-Item -Destination $runtimeCache -Recurse
    }
}

if (-not (Assert-FixedRuntime -Directory $runtimeCache)) {
    $downloads = Join-Path $PSScriptRoot 'build\_downloads'
    $extracted = Join-Path $PSScriptRoot 'build\_webview2'
    $cab = Join-Path $downloads $webviewCabName
    New-Item -ItemType Directory -Force -Path $downloads | Out-Null
    if (-not (Test-Path -LiteralPath $cab)) {
        & curl.exe --location --fail --retry 4 --retry-all-errors --output $cab $webviewCabUrl
        if ($LASTEXITCODE -ne 0) {
            throw 'Could not download the pinned WebView2 Fixed Version Runtime.'
        }
    }
    $actualHash = (Get-FileHash -LiteralPath $cab -Algorithm SHA256).Hash
    if ($actualHash -ne $webviewCabHash) {
        throw "WebView2 runtime SHA-256 mismatch. Expected $webviewCabHash, got $actualHash."
    }
    if (-not (Test-Path -LiteralPath $extracted)) {
        New-Item -ItemType Directory -Force -Path $extracted | Out-Null
        & expand.exe -F:* $cab $extracted | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw 'Could not extract the WebView2 Fixed Version Runtime CAB.'
        }
    }
    $runtime = Get-ChildItem -LiteralPath $extracted -Filter $webviewExecutableName -File -Recurse | Select-Object -First 1
    if (-not $runtime) {
        throw 'The extracted WebView2 runtime executable was not found.'
    }
    New-Item -ItemType Directory -Force -Path $runtimeCache | Out-Null
    Get-ChildItem -LiteralPath $runtime.DirectoryName -Force | Copy-Item -Destination $runtimeCache -Recurse
}

if (-not (Assert-FixedRuntime -Directory $runtimeCache)) {
    throw 'The WebView2 Fixed Version Runtime is missing. Evergreen WebView2 is not a supported fallback.'
}

Push-Location $PSScriptRoot
try {
    & wails build -platform windows/amd64 -webview2 browser -skipbindings
    if ($LASTEXITCODE -ne 0) {
        throw 'Wails could not build VaultClient.exe.'
    }
}
finally {
    Pop-Location
}

$executable = Join-Path $PSScriptRoot 'build\bin\VaultClient.exe'
if (-not (Test-Path -LiteralPath $executable -PathType Leaf)) {
    throw "Wails completed without producing $executable."
}

$stage = Join-Path $PSScriptRoot ('build\_package-stage-' + [guid]::NewGuid().ToString('N'))
try {
    New-Item -ItemType Directory -Force -Path (Join-Path $stage 'bin\webview2') | Out-Null
    Copy-Item -LiteralPath $executable -Destination (Join-Path $stage 'VaultClient.exe')
    Get-ChildItem -LiteralPath $runtimeCache -Force | Copy-Item -Destination (Join-Path $stage 'bin\webview2') -Recurse
    New-Item -ItemType Directory -Force -Path $package | Out-Null
    & robocopy.exe $stage $package /E /COPY:DAT /DCOPY:DAT /R:2 /W:2 /NFL /NDL /NJH /NJS
    if ($LASTEXITCODE -ge 8) {
        throw "Could not stage the Vault Client runtime package (robocopy exit code $LASTEXITCODE)."
    }
}
finally {
    if (Test-Path -LiteralPath $stage) {
        Remove-Item -LiteralPath $stage -Recurse -Force
    }
}

$stagedRuntime = Join-Path $package 'bin\webview2'
if (-not (Assert-FixedRuntime -Directory $stagedRuntime)) {
    throw 'The runtime package does not contain its signed fixed-version WebView2 runtime.'
}
Get-Item -LiteralPath (Join-Path $package 'VaultClient.exe') | Select-Object FullName, Length, LastWriteTime
$global:LASTEXITCODE = 0
