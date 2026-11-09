[CmdletBinding()]
param(
    [string]$PackageDir = (Join-Path $PSScriptRoot 'build\package'),
    [string]$OutputFile = (Join-Path $PSScriptRoot 'build\VaultClientSetup.exe')
)

$ErrorActionPreference = 'Stop'
$package = [System.IO.Path]::GetFullPath($PackageDir)
$output = [System.IO.Path]::GetFullPath($OutputFile)
$installerScript = Join-Path $PSScriptRoot 'build\installer.nsi'
$required = @(
    'VaultClient.exe',
    'bin\webview2\msedgewebview2.exe'
)
foreach ($path in $required) {
    if (-not (Test-Path -LiteralPath (Join-Path $package $path) -PathType Leaf)) {
        throw "Runtime package is missing required installer input: $path. Run package-client.ps1 first."
    }
}

$makensis = (Get-Command 'makensis.exe' -ErrorAction SilentlyContinue).Source
if (-not $makensis) {
    foreach ($candidate in @(
        (Join-Path ${env:ProgramFiles(x86)} 'NSIS\makensis.exe'),
        (Join-Path $env:ProgramFiles 'NSIS\makensis.exe')
    )) {
        if (Test-Path -LiteralPath $candidate) {
            $makensis = $candidate
            break
        }
    }
}
if (-not $makensis) {
    throw 'NSIS makensis.exe is required. Install NSIS and rerun this script.'
}

New-Item -ItemType Directory -Force -Path (Split-Path -Parent $output) | Out-Null
& $makensis `
    "/DPACKAGE_DIR=$package" `
    "/DOUTPUT_FILE=$output" `
    "/DICON_FILE=$(Join-Path $PSScriptRoot 'build\windows\icon.ico')" `
    $installerScript
if ($LASTEXITCODE -ne 0) {
    throw "NSIS failed to build VaultClientSetup.exe (exit code $LASTEXITCODE)."
}
if (-not (Test-Path -LiteralPath $output -PathType Leaf)) {
    throw "NSIS completed without producing the installer: $output"
}
Get-Item -LiteralPath $output | Select-Object FullName, Length, LastWriteTime
