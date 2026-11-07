[CmdletBinding()]
param(
    [string]$PackageDir = (Join-Path $PSScriptRoot 'build\package'),
    [string]$OutputFile = (Join-Path $PSScriptRoot 'build\VaultControl-Setup.exe')
)

$ErrorActionPreference = 'Stop'
$package = [System.IO.Path]::GetFullPath($PackageDir)
$output = [System.IO.Path]::GetFullPath($OutputFile)
$installerScript = Join-Path $PSScriptRoot 'installer\VaultControl.nsi'
$required = @(
    'VaultControl.exe',
    'setup-package.ps1',
    'bin\mariadb\bin\mysqld.exe',
    'bin\php\php.exe',
    'bin\webview2\msedgewebview2.exe',
    'app\artisan',
    'data\.env.production.example'
)
foreach ($path in $required) {
    if (-not (Test-Path -LiteralPath (Join-Path $package $path))) {
        throw "Runtime package is missing required installer input: $path"
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

$outputDirectory = Split-Path -Parent $output
New-Item -ItemType Directory -Force -Path $outputDirectory | Out-Null
$arguments = @(
    "/DPACKAGE_DIR=$package",
    "/DOUTPUT_FILE=$output",
    "/DICON_FILE=$(Join-Path $PSScriptRoot 'build\windows\icon.ico')",
    "/DSETUP_SCRIPT=$(Join-Path $PSScriptRoot 'setup-package.ps1')",
    $installerScript
)
& $makensis @arguments
if ($LASTEXITCODE -ne 0) {
    throw "NSIS failed to build VaultControl-Setup.exe (exit code $LASTEXITCODE)."
}
if (-not (Test-Path -LiteralPath $output)) {
    throw "NSIS completed without producing the installer: $output"
}

Get-Item -LiteralPath $output | Select-Object FullName, Length, LastWriteTime
