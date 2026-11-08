[CmdletBinding()]
param(
    [Alias('OutputDirectory')]
    [string]$PackageDir = (Join-Path $PSScriptRoot 'build\package'),
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

$downloads = Join-Path $PSScriptRoot 'build\_phase4-downloads'
$extracted = Join-Path $PSScriptRoot 'build\_phase4-extracted'
$repositoryRoot = Split-Path -Parent $PSScriptRoot
$output = [System.IO.Path]::GetFullPath($PackageDir)

function Ensure-VaultControlStopped {
    param([switch]$ForceStop)

    $running = @(Get-Process -Name 'VaultControl' -ErrorAction SilentlyContinue)
    if ($running.Count -eq 0) {
        return
    }

    if ($ForceStop) {
        foreach ($process in $running) {
            Stop-Process -Id $process.Id -Force -ErrorAction Stop
        }

        $deadline = [DateTime]::UtcNow.AddSeconds(15)
        do {
            $running = @(Get-Process -Name 'VaultControl' -ErrorAction SilentlyContinue)
            if ($running.Count -eq 0) {
                return
            }
            Start-Sleep -Milliseconds 250
        } while ([DateTime]::UtcNow -lt $deadline)

        $processIds = ($running | ForEach-Object { $_.Id }) -join ', '
        throw "VaultControl processes are still running (PID $processIds); cannot replace the packaged executable."
    }

    while ($running.Count -gt 0) {
        $processIds = ($running | ForEach-Object { $_.Id }) -join ', '
        Write-Warning "VaultControl is running (PID $processIds). Exit it from the system tray, then press Enter to continue. Press Ctrl+C to cancel."
        [void](Read-Host)
        $running = @(Get-Process -Name 'VaultControl' -ErrorAction SilentlyContinue)
    }
}

function Assert-Hash {
    param(
        [string]$Path,
        [string]$Expected
    )

    $actual = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash
    if ($actual -ne $Expected) {
        throw "SHA256 mismatch for $Path. Expected $Expected, got $actual."
    }
}

function Expand-ZipOnce {
    param(
        [string]$Archive,
        [string]$Destination
    )

    if (-not (Test-Path -LiteralPath $Destination)) {
        New-Item -ItemType Directory -Force -Path $Destination | Out-Null
        Expand-Archive -LiteralPath $Archive -DestinationPath $Destination
    }
}

$archives = @(
    @{
        Name = 'frankenphp-windows-x86_64.zip'
        Url = 'https://github.com/php/frankenphp/releases/download/v1.13.5/frankenphp-windows-x86_64.zip'
        Sha256 = 'FFE689EFA0AA1BB35416EFFF7DA52ECD4B2E46CFB82E454DB5188372B3A9E1F2'
    },
    @{
        Name = 'php-8.5.12-1-nts-Win32-vs17-x64.zip'
        Url = 'https://downloads.php.net/~windows/releases/archives/php-8.5.12-1-nts-Win32-vs17-x64.zip'
        Sha256 = '2A36A2EDCB7A5B9252F638FD5E4CF84D7F6E066092654220DC0B63E5CF4E9723'
    },
    @{
        Name = 'mariadb-11.4.10-winx64.zip'
        Url = 'https://downloads.mariadb.com/MariaDB/mariadb-11.4.10/winx64-packages/mariadb-11.4.10-winx64.zip'
        Sha256 = 'FB7C76F0804321EE373DAA49145F2056D2D88F321B614130ADEB05A1644EA003'
    },
    @{
        Name = 'Microsoft.WebView2.FixedVersionRuntime.156.0.4314.58.x64.cab'
        Url = 'https://msedge.sf.dl.delivery.mp.microsoft.com/filestreamingservice/files/b7919cc9-680d-4253-bf32-732988e68aa6/Microsoft.WebView2.FixedVersionRuntime.156.0.4314.58.x64.cab'
        Sha256 = '857DCFD6F7035508A29645FA2978C3A6A841F220DF77CC366D318894E19F4792'
    }
)

New-Item -ItemType Directory -Force -Path $downloads | Out-Null
foreach ($archive in $archives) {
    $path = Join-Path $downloads $archive.Name
    if (-not (Test-Path -LiteralPath $path)) {
        & curl.exe --location --fail --retry 4 --retry-all-errors --output $path $archive.Url
        if ($LASTEXITCODE -ne 0) {
            throw "Download failed for $($archive.Name)."
        }
    }
    Assert-Hash -Path $path -Expected $archive.Sha256
}

$phpSource = Join-Path $extracted 'php'
$frankenSource = Join-Path $extracted 'frankenphp'
$mariaSource = Join-Path $extracted 'mariadb\mariadb-11.4.10-winx64'
$webviewSource = Join-Path $extracted 'webview2'
Expand-ZipOnce -Archive (Join-Path $downloads 'php-8.5.12-1-nts-Win32-vs17-x64.zip') -Destination $phpSource
Expand-ZipOnce -Archive (Join-Path $downloads 'frankenphp-windows-x86_64.zip') -Destination $frankenSource
Expand-ZipOnce -Archive (Join-Path $downloads 'mariadb-11.4.10-winx64.zip') -Destination (Join-Path $extracted 'mariadb')

if (-not (Test-Path -LiteralPath $webviewSource)) {
    New-Item -ItemType Directory -Force -Path $webviewSource | Out-Null
    & expand.exe -F:* (Join-Path $downloads 'Microsoft.WebView2.FixedVersionRuntime.156.0.4314.58.x64.cab') $webviewSource | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not extract the Microsoft WebView2 fixed-version runtime.'
    }
}
$webviewExecutable = Get-ChildItem -LiteralPath $webviewSource -Filter 'msedgewebview2.exe' -File -Recurse | Select-Object -First 1
if (-not $webviewExecutable) {
    throw 'The Microsoft WebView2 runtime executable was not found in the extracted CAB.'
}
$webviewRuntimeSource = $webviewExecutable.DirectoryName

$signature = Get-AuthenticodeSignature -LiteralPath $webviewExecutable.FullName
if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'Microsoft') {
    throw 'The extracted WebView2 executable does not have a valid Microsoft signature.'
}
foreach ($path in @($phpSource, $frankenSource, $mariaSource, $webviewSource)) {
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Required extracted runtime is missing: $path"
    }
}

$env:VITE_REVERB_APP_KEY = 'vault-control'
Push-Location $repositoryRoot
try {
    & npm run build
    if ($LASTEXITCODE -ne 0) {
        throw 'Laravel frontend build failed.'
    }
}
finally {
    Pop-Location
    Remove-Item Env:\VITE_REVERB_APP_KEY -ErrorAction SilentlyContinue
}

Push-Location $PSScriptRoot
try {
    & wails build -platform windows/amd64 -webview2 browser
    if ($LASTEXITCODE -ne 0) {
        throw 'Wails Windows build failed.'
    }
}
finally {
    Pop-Location
}

$stage = Join-Path $PSScriptRoot ('build\_package-stage-' + [guid]::NewGuid().ToString('N'))
if (Test-Path -LiteralPath $stage) {
    throw "Package staging directory already exists; inspect it before reusing: $stage"
}

$executable = Join-Path $PSScriptRoot 'build\bin\VaultControl.exe'
if (-not (Test-Path -LiteralPath $executable)) {
    throw 'Wails build completed without producing build\bin\VaultControl.exe.'
}

$paths = @(
    $stage,
    (Join-Path $stage 'bin\php'),
    (Join-Path $stage 'bin\mariadb'),
    (Join-Path $stage 'bin\webview2'),
    (Join-Path $stage 'app'),
    (Join-Path $stage 'app\bootstrap'),
    (Join-Path $stage 'app\bootstrap\cache'),
    (Join-Path $stage 'licenses'),
    (Join-Path $stage 'data\backups'),
    (Join-Path $stage 'data\logs'),
    (Join-Path $stage 'data\mysql_data'),
    (Join-Path $stage 'data\bootstrap\cache'),
    (Join-Path $stage 'data\storage\app\private'),
    (Join-Path $stage 'data\storage\app\public'),
    (Join-Path $stage 'data\storage\framework\cache\data'),
    (Join-Path $stage 'data\storage\framework\sessions'),
    (Join-Path $stage 'data\storage\framework\testing'),
    (Join-Path $stage 'data\storage\framework\views'),
    (Join-Path $stage 'data\storage\logs')
)
foreach ($path in $paths) {
    New-Item -ItemType Directory -Force -Path $path | Out-Null
}

Copy-Item -LiteralPath $executable -Destination (Join-Path $stage 'VaultControl.exe') -Force
Get-ChildItem -LiteralPath $phpSource -Force | Copy-Item -Destination (Join-Path $stage 'bin\php') -Recurse
Get-ChildItem -LiteralPath $frankenSource -Force | Copy-Item -Destination (Join-Path $stage 'bin') -Recurse
Get-ChildItem -LiteralPath $mariaSource -Force | Copy-Item -Destination (Join-Path $stage 'bin\mariadb') -Recurse
Get-ChildItem -LiteralPath $webviewRuntimeSource -Force | Copy-Item -Destination (Join-Path $stage 'bin\webview2') -Recurse

Copy-Item -LiteralPath (Join-Path $stage 'bin\php\php.ini-production') -Destination (Join-Path $stage 'bin\php\php.ini')
Copy-Item -LiteralPath (Join-Path $stage 'bin\php.ini-production') -Destination (Join-Path $stage 'bin\php.ini')
$phpSettings = @'
extension_dir="ext"
extension=curl
extension=exif
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=mysqli
extension=openssl
extension=pdo_mysql
extension=sodium
extension=zip
date.timezone=UTC
memory_limit=512M
upload_max_filesize=110M
post_max_size=120M
max_execution_time=0
'@
Add-Content -LiteralPath (Join-Path $stage 'bin\php\php.ini') -Value $phpSettings
Add-Content -LiteralPath (Join-Path $stage 'bin\php.ini') -Value $phpSettings

Push-Location (Join-Path $stage 'bin\php')
try {
    $phpModules = & .\php.exe -m
    if ($LASTEXITCODE -ne 0) {
        throw 'Packaged PHP failed to load its configured extensions.'
    }
    foreach ($module in @('bcmath', 'curl', 'exif', 'fileinfo', 'gd', 'intl', 'mbstring', 'mysqli', 'openssl', 'PDO', 'pdo_mysql', 'sodium', 'zip')) {
        if ($module -notin $phpModules) {
            throw "Required PHP extension is unavailable in the package: $module"
        }
    }
}
finally {
    Pop-Location
}

$packageConfigPath = $output.Replace('\', '/')
$mariaConfig = @"
[mysqld]
port=3307
basedir=$packageConfigPath/bin/mariadb
datadir=$packageConfigPath/data/mysql_data
bind-address=127.0.0.1
max_connections=250
innodb_buffer_pool_size=512M
innodb_log_file_size=128M
innodb_flush_log_at_trx_commit=2
innodb_lock_wait_timeout=50
skip-name-resolve
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
"@
Set-Content -LiteralPath (Join-Path $stage 'bin\mariadb\my.ini') -Value $mariaConfig -Encoding ascii
Copy-Item -LiteralPath (Join-Path $stage 'bin\php\license.txt') -Destination (Join-Path $stage 'licenses\php-license.txt')
if (Test-Path -LiteralPath (Join-Path $stage 'bin\license.txt')) {
    Copy-Item -LiteralPath (Join-Path $stage 'bin\license.txt') -Destination (Join-Path $stage 'licenses\frankenphp-license.txt')
}
foreach ($licenseName in @('COPYING', 'LICENSE')) {
    $mariaLicense = Join-Path (Join-Path $stage 'bin\mariadb') $licenseName
    if (Test-Path -LiteralPath $mariaLicense) {
        Copy-Item -LiteralPath $mariaLicense -Destination (Join-Path $stage ('licenses\mariadb-' + $licenseName.ToLowerInvariant()))
    }
}

foreach ($name in @('app', 'config', 'database', 'public', 'resources', 'routes', 'vendor')) {
    Copy-Item -LiteralPath (Join-Path $repositoryRoot $name) -Destination (Join-Path $stage 'app') -Recurse
}
foreach ($name in @('artisan', 'composer.json', 'composer.lock')) {
    Copy-Item -LiteralPath (Join-Path $repositoryRoot $name) -Destination (Join-Path $stage 'app')
}
Copy-Item -LiteralPath (Join-Path $repositoryRoot 'Caddyfile') -Destination (Join-Path $stage 'app\Caddyfile')
Get-ChildItem -LiteralPath (Join-Path $repositoryRoot 'bootstrap') -File | Copy-Item -Destination (Join-Path $stage 'app\bootstrap')
New-Item -ItemType Directory -Force -Path (Join-Path $stage 'app\bootstrap\cache') | Out-Null

foreach ($relativePath in @(
    'app\private',
    'app\public',
    'framework\cache\data',
    'framework\sessions',
    'framework\testing',
    'framework\views',
    'logs'
)) {
    New-Item -ItemType Directory -Force -Path (Join-Path $stage ('app\storage\' + $relativePath)) | Out-Null
}
Copy-Item -LiteralPath (Join-Path $PSScriptRoot '.env.production.example') -Destination (Join-Path $stage 'data\.env.production.example')
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'setup-package.ps1') -Destination (Join-Path $stage 'setup-package.ps1')

if ((Test-Path -LiteralPath (Join-Path $stage 'app\.env')) -or (Test-Path -LiteralPath (Join-Path $stage '.env'))) {
    throw 'A Laravel .env file must never be included in the runtime package.'
}

Ensure-VaultControlStopped -ForceStop:$Force

if (Test-Path -LiteralPath $output) {
    if (-not (Test-Path -LiteralPath $output -PathType Container)) {
        throw "Package path exists but is not a directory: $output"
    }

    New-Item -ItemType Directory -Force -Path $output | Out-Null
    & robocopy.exe $stage $output /E /COPY:DAT /DCOPY:DAT /R:2 /W:2 /NFL /NDL /NJH /NJS /XD (Join-Path $stage 'data') /XF (Join-Path $stage 'VaultControl.exe')
    $copyExitCode = $LASTEXITCODE
    if ($copyExitCode -ge 8) {
        throw "Could not refresh package contents (robocopy exit code $copyExitCode)."
    }
}
else {
    try {
        Move-Item -LiteralPath $stage -Destination $output
    }
    catch {
        $moveError = $_.Exception.Message
        New-Item -ItemType Directory -Force -Path $output | Out-Null
        & robocopy.exe $stage $output /E /COPY:DAT /DCOPY:DAT /R:2 /W:2 /NFL /NDL /NJH /NJS
        $copyExitCode = $LASTEXITCODE
        if ($copyExitCode -ge 8) {
            throw "Could not finalize package by move ($moveError) or copy (robocopy exit code $copyExitCode)."
        }
        try {
            Remove-Item -LiteralPath $stage -Recurse -Force
        }
        catch {
            Write-Warning "Package was copied successfully, but temporary staging cleanup failed at ${stage}: $($_.Exception.Message)"
        }
    }
}

$writableDirectories = @(
    'data\backups',
    'data\logs',
    'data\mysql_data',
    'data\bootstrap\cache',
    'data\storage\app\private',
    'data\storage\app\public',
    'data\storage\framework\cache\data',
    'data\storage\framework\sessions',
    'data\storage\framework\testing',
    'data\storage\framework\views',
    'data\storage\logs'
)
foreach ($directory in $writableDirectories) {
    New-Item -ItemType Directory -Force -Path (Join-Path $output $directory) | Out-Null
}

foreach ($required in @('VaultControl.exe', 'bin\frankenphp.exe', 'app\artisan', 'data\.env.production.example')) {
    if (-not (Test-Path -LiteralPath (Join-Path $output $required))) {
        throw "Package finalization did not produce required file $required."
    }
}
Ensure-VaultControlStopped -ForceStop:$Force
try {
    Copy-Item -LiteralPath $executable -Destination (Join-Path $output 'VaultControl.exe') -Force
}
catch {
    throw "Could not replace the packaged VaultControl.exe. Exit VaultControl from the system tray and rerun packaging. $($_.Exception.Message)"
}
if ((Get-FileHash -LiteralPath $executable -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath (Join-Path $output 'VaultControl.exe') -Algorithm SHA256).Hash) {
    throw 'The packaged VaultControl.exe does not match the Wails build output.'
}
if (Test-Path -LiteralPath $stage) {
    try {
        Remove-Item -LiteralPath $stage -Recurse -Force
    }
    catch {
        Write-Warning "Package was finalized successfully, but temporary staging cleanup failed at ${stage}: $($_.Exception.Message)"
    }
}
Write-Output "Runtime package created at $output"
