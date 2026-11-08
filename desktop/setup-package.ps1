[CmdletBinding()]
param(
    [Alias('PackageDirectory')]
    [string]$PackageDir = $(
        if (Test-Path -LiteralPath (Join-Path $PSScriptRoot 'data\.env.production.example')) {
            $PSScriptRoot
        }
        else {
            Join-Path $PSScriptRoot 'build\package'
        }
    ),
    [switch]$Force,
    [switch]$Wizard,
    [switch]$RepairDatabaseCredentials
)

$ErrorActionPreference = 'Stop'
if ($RepairDatabaseCredentials -and $Wizard) {
    throw 'Database credential repair cannot be combined with the setup wizard.'
}
$package = [System.IO.Path]::GetFullPath($PackageDir)
$environmentFile = Join-Path $package 'data\.env.production'
$environmentExample = Join-Path $package 'data\.env.production.example'
$mariaBin = Join-Path $package 'bin\mariadb\bin'
$mariaConfig = Join-Path $package 'bin\mariadb\my.ini'
$databaseDirectory = Join-Path $package 'data\mysql_data'
$systemTablesDirectory = Join-Path $databaseDirectory 'mysql'
$logDirectory = Join-Path $package 'data\logs'
$appDirectory = Join-Path $package 'app'
$php = Join-Path $package 'bin\php\php.exe'
$reusingExistingEnvironment = Test-Path -LiteralPath $environmentFile
$databaseAlreadyInitialized = Test-Path -LiteralPath $systemTablesDirectory -PathType Container
$databaseHasFiles = $false
if (Test-Path -LiteralPath $databaseDirectory -PathType Container) {
    $databaseHasFiles = $null -ne (Get-ChildItem -LiteralPath $databaseDirectory -Force | Select-Object -First 1)
}
if ($databaseHasFiles -and -not $databaseAlreadyInitialized) {
    throw "Existing MariaDB data was found in $databaseDirectory, but its system tables are missing. Refusing to initialize or overwrite existing database files."
}

foreach ($required in @(
    $environmentExample,
    (Join-Path $mariaBin 'mariadb-install-db.exe'),
    (Join-Path $mariaBin 'mysqld.exe'),
    (Join-Path $mariaBin 'mysql.exe'),
    $mariaConfig,
    $php,
    (Join-Path $appDirectory 'artisan')
)) {
    if (-not (Test-Path -LiteralPath $required)) {
        throw "Required package file is missing: $required"
    }
}
if ($RepairDatabaseCredentials -and -not $reusingExistingEnvironment) {
    throw "Cannot repair database credentials without $environmentFile."
}
if ($RepairDatabaseCredentials -and -not $databaseAlreadyInitialized) {
    throw "Cannot repair database credentials because initialized MariaDB system tables were not found in $databaseDirectory. Existing files were not changed."
}
if ($reusingExistingEnvironment -and -not $Force -and -not $RepairDatabaseCredentials) {
    throw "Refusing to overwrite existing production settings: $environmentFile"
}

if ($RepairDatabaseCredentials) {
    $adminEmail = ''
    $adminPassword = $null
    $password = $null
}
elseif ($Wizard) {
    Add-Type -AssemblyName System.Drawing
    Add-Type -AssemblyName System.Windows.Forms

    $form = [System.Windows.Forms.Form]::new()
    $form.Text = 'VaultControl First-Run Setup'
    $form.StartPosition = 'CenterScreen'
    $form.FormBorderStyle = 'FixedDialog'
    $form.MaximizeBox = $false
    $form.MinimizeBox = $false

    $fields = @(
        @{ Label = 'Administrator email'; Name = 'Email'; Password = $false },
        @{ Label = 'Administrator password'; Name = 'AdminPassword'; Password = $true },
        @{ Label = 'Confirm administrator password'; Name = 'AdminPasswordConfirm'; Password = $true }
    )
    if (-not $reusingExistingEnvironment) {
        $fields += @(
            @{ Label = 'Database password'; Name = 'DatabasePassword'; Password = $true },
            @{ Label = 'Confirm database password'; Name = 'DatabasePasswordConfirm'; Password = $true }
        )
    }
    $form.ClientSize = [System.Drawing.Size]::new(480, 90 + ($fields.Count * 48))
    $inputs = @{}
    for ($index = 0; $index -lt $fields.Count; $index++) {
        $label = [System.Windows.Forms.Label]::new()
        $label.Text = $fields[$index].Label
        $label.Location = [System.Drawing.Point]::new(18, 18 + ($index * 48))
        $label.Size = [System.Drawing.Size]::new(210, 22)
        $form.Controls.Add($label)

        $input = [System.Windows.Forms.TextBox]::new()
        $input.Name = $fields[$index].Name
        $input.Location = [System.Drawing.Point]::new(235, 16 + ($index * 48))
        $input.Size = [System.Drawing.Size]::new(225, 24)
        $input.UseSystemPasswordChar = $fields[$index].Password
        $form.Controls.Add($input)
        $inputs[$fields[$index].Name] = $input
    }

    $okButton = [System.Windows.Forms.Button]::new()
    $okButton.Text = 'Set up'
    $okButton.Location = [System.Drawing.Point]::new(270, $form.ClientSize.Height - 60)
    $okButton.DialogResult = [System.Windows.Forms.DialogResult]::OK
    $form.Controls.Add($okButton)
    $cancelButton = [System.Windows.Forms.Button]::new()
    $cancelButton.Text = 'Cancel'
    $cancelButton.Location = [System.Drawing.Point]::new(370, $form.ClientSize.Height - 60)
    $cancelButton.DialogResult = [System.Windows.Forms.DialogResult]::Cancel
    $form.Controls.Add($cancelButton)
    $form.AcceptButton = $okButton
    $form.CancelButton = $cancelButton

    if ($form.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) {
        exit 2
    }
    $adminEmail = $inputs.Email.Text.Trim().ToLowerInvariant()
    $password = $inputs.AdminPassword.Text
    $adminPasswordConfirm = $inputs.AdminPasswordConfirm.Text
    if ($inputs.ContainsKey('DatabasePassword')) {
        $databasePassword = $inputs.DatabasePassword.Text
        $databasePasswordConfirm = $inputs.DatabasePasswordConfirm.Text
    }
    else {
        $databasePassword = $null
        $databasePasswordConfirm = $null
    }
    $form.Dispose()

    if ($adminEmail -notmatch '^[^@\s]+@[^@\s]+\.[^@\s]+$') {
        [System.Windows.Forms.MessageBox]::Show('Enter a valid administrator email address.', 'VaultControl Setup') | Out-Null
        exit 2
    }
    if ($password.Length -lt 12 -or $password -cne $adminPasswordConfirm) {
        [System.Windows.Forms.MessageBox]::Show('Administrator passwords must match and contain at least 12 characters.', 'VaultControl Setup') | Out-Null
        exit 2
    }
    if ($databasePassword -cne $databasePasswordConfirm) {
        [System.Windows.Forms.MessageBox]::Show('Database passwords must match.', 'VaultControl Setup') | Out-Null
        exit 2
    }
    $adminPassword = $null
}
else {
    $adminEmail = (Read-Host 'Initial administrator email').Trim().ToLowerInvariant()
    if ($adminEmail -notmatch '^[^@\s]+@[^@\s]+\.[^@\s]+$') {
        throw 'Enter a valid administrator email address.'
    }
    $adminPassword = Read-Host 'Initial administrator password (at least 12 characters)' -AsSecureString
    $password = [System.Net.NetworkCredential]::new('', $adminPassword).Password
    if ($password.Length -lt 12) {
        throw 'The administrator password must contain at least 12 characters.'
    }
}

$databaseName = 'vault_control'
$text = [System.IO.File]::ReadAllText($(if ($reusingExistingEnvironment) { $environmentFile } else { $environmentExample }))
$databaseSetting = [regex]::Match($text, '(?m)^DB_DATABASE=(?<name>[A-Za-z0-9_]+)\s*$')
if (-not $databaseSetting.Success) {
    throw 'DB_DATABASE is missing or invalid in the packaged environment example.'
}
$databaseName = $databaseSetting.Groups['name'].Value

if ($RepairDatabaseCredentials) {
    $usernameSetting = [regex]::Match($text, '(?m)^DB_USERNAME=(?<value>\S+)\s*$')
    if (-not $usernameSetting.Success -or $usernameSetting.Groups['value'].Value -cne 'vault_control') {
        throw 'Cannot reconcile database credentials: DB_USERNAME must be vault_control.'
    }
}

if ($reusingExistingEnvironment) {
    $databasePasswordSetting = [regex]::Match($text, '(?m)^DB_PASSWORD=(?<value>\S+)\s*$')
    $reverbSecretSetting = [regex]::Match($text, '(?m)^REVERB_APP_SECRET=(?<value>\S+)\s*$')
    $applicationKeySetting = [regex]::Match($text, '(?m)^APP_KEY=(?<value>\S+)\s*$')
    if (-not $databasePasswordSetting.Success -or -not $reverbSecretSetting.Success) {
        throw 'Cannot safely reuse production settings: DB_PASSWORD and REVERB_APP_SECRET must be set.'
    }

    $databasePassword = $databasePasswordSetting.Groups['value'].Value
    $reverbSecret = $reverbSecretSetting.Groups['value'].Value
    $hasApplicationKey = $applicationKeySetting.Success
}
elseif (-not $Wizard) {
    $randomBytes = New-Object byte[] 32
    $random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $random.GetBytes($randomBytes)
        $reverbSecret = [System.BitConverter]::ToString($randomBytes).Replace('-', '').ToLowerInvariant()
    }
    finally {
        $random.Dispose()
    }
    [Array]::Clear($randomBytes, 0, $randomBytes.Length)
    $databasePassword = Read-Host 'Database password (at least 12 characters)' -AsSecureString | ForEach-Object {
        [System.Net.NetworkCredential]::new('', $_).Password
    }
    $hasApplicationKey = $false
}
else {
    $randomBytes = New-Object byte[] 32
    $random = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $random.GetBytes($randomBytes)
        $reverbSecret = [System.BitConverter]::ToString($randomBytes).Replace('-', '').ToLowerInvariant()
    }
    finally {
        $random.Dispose()
    }
    [Array]::Clear($randomBytes, 0, $randomBytes.Length)
    $hasApplicationKey = $false
}

if ($databasePassword -notmatch '^[A-Za-z0-9]{12,64}$') {
    $message = 'Use a database password containing 12 to 64 letters and numbers.'
    if ($Wizard) {
        [System.Windows.Forms.MessageBox]::Show($message, 'VaultControl Setup') | Out-Null
        exit 2
    }
    throw $message
}

if (-not $RepairDatabaseCredentials) {
    $text = [regex]::Replace($text, '(?m)^DB_USERNAME=.*$', 'DB_USERNAME=vault_control')
    $text = [regex]::Replace($text, '(?m)^DB_PASSWORD=.*$', "DB_PASSWORD=$databasePassword")
    $text = [regex]::Replace($text, '(?m)^ADMIN_EMAIL=.*$', "ADMIN_EMAIL=$adminEmail")
    $text = [regex]::Replace($text, '(?m)^REVERB_APP_SECRET=.*$', "REVERB_APP_SECRET=$reverbSecret")
    [System.IO.File]::WriteAllText(
        $environmentFile,
        $text,
        [System.Text.UTF8Encoding]::new($false)
    )
}

$storageDirectories = @(
    (Join-Path $package 'data\bootstrap\cache'),
    (Join-Path $package 'data\storage\app\private'),
    (Join-Path $package 'data\storage\app\public'),
    (Join-Path $package 'data\storage\framework\cache\data'),
    (Join-Path $package 'data\storage\framework\sessions'),
    (Join-Path $package 'data\storage\framework\testing'),
    (Join-Path $package 'data\storage\framework\views'),
    (Join-Path $package 'data\storage\logs')
)
New-Item -ItemType Directory -Force -Path ($storageDirectories + @($databaseDirectory, $logDirectory)) | Out-Null
$packageConfigPath = $package.Replace('\', '/')
$mariaConfigContent = @"
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
Set-Content -LiteralPath $mariaConfig -Value $mariaConfigContent -Encoding ascii
$account = "$env:USERDOMAIN\$env:USERNAME"
& icacls.exe $environmentFile /inheritance:r /grant:r "$($account):(M)" 'SYSTEM:(F)' | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Could not restrict access to the production environment file.'
}

$installer = Join-Path $mariaBin 'mariadb-install-db.exe'
if (-not $databaseAlreadyInitialized) {
    & $installer "--datadir=$databaseDirectory" '--port=3307' "--config=$mariaConfig" '--silent'
    if ($LASTEXITCODE -ne 0) {
        throw "MariaDB system-table initialization failed with exit code $LASTEXITCODE."
    }
}

$serverLog = Join-Path $logDirectory 'setup-mariadb.log'
$server = Start-Process `
    -FilePath (Join-Path $mariaBin 'mysqld.exe') `
    -ArgumentList @("""--defaults-file=$mariaConfig""") `
    -WorkingDirectory $package `
    -PassThru `
    -RedirectStandardOutput (Join-Path $logDirectory 'setup-mariadb.stdout.log') `
    -RedirectStandardError $serverLog
$mysql = Join-Path $mariaBin 'mysql.exe'
$serverStopped = $false

function Invoke-LocalMariaDBSQL {
    param([string]$Statement)

    $startInfo = [System.Diagnostics.ProcessStartInfo]::new()
    $startInfo.FileName = $mysql
    $startInfo.Arguments = '--no-defaults --host=127.0.0.1 --port=3307 --user=root --batch --skip-column-names'
    $startInfo.WorkingDirectory = $package
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardInput = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $client = [System.Diagnostics.Process]::new()
    $client.StartInfo = $startInfo
    if (-not $client.Start()) {
        throw 'Could not start the local MariaDB client.'
    }
    $client.StandardInput.WriteLine($Statement)
    $client.StandardInput.Close()
    $output = $client.StandardOutput.ReadToEnd()
    $errors = $client.StandardError.ReadToEnd()
    $client.WaitForExit()
    if ($client.ExitCode -ne 0) {
        throw "Local MariaDB command failed: $($errors.Trim()) $($output.Trim())"
    }
}

try {
    $deadline = [DateTime]::UtcNow.AddSeconds(30)
    $ready = $false
    while ([DateTime]::UtcNow -lt $deadline) {
        if (-not (Get-Process -Id $server.Id -ErrorAction SilentlyContinue)) {
            throw "MariaDB exited during setup; inspect $serverLog."
        }
        $client = [System.Net.Sockets.TcpClient]::new()
        try {
            $connect = $client.BeginConnect('127.0.0.1', 3307, $null, $null)
            if ($connect.AsyncWaitHandle.WaitOne(250) -and $client.Connected) {
                $ready = $true
                break
            }
        }
        finally {
            $client.Dispose()
        }
        Start-Sleep -Milliseconds 250
    }
    if (-not $ready) {
        throw "MariaDB did not become ready; inspect $serverLog."
    }

    $sql = "CREATE USER IF NOT EXISTS 'vault_control'@'127.0.0.1' IDENTIFIED BY '$databasePassword'; " +
        "CREATE USER IF NOT EXISTS 'vault_control'@'localhost' IDENTIFIED BY '$databasePassword'; " +
        "ALTER USER 'vault_control'@'127.0.0.1' IDENTIFIED BY '$databasePassword'; " +
        "ALTER USER 'vault_control'@'localhost' IDENTIFIED BY '$databasePassword'; " +
        "GRANT ALL PRIVILEGES ON ``$databaseName``.* TO 'vault_control'@'127.0.0.1'; " +
        "GRANT ALL PRIVILEGES ON ``$databaseName``.* TO 'vault_control'@'localhost';"
    if (-not $RepairDatabaseCredentials) {
        $sql = "CREATE DATABASE IF NOT EXISTS ``$databaseName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; " + $sql
    }
    Invoke-LocalMariaDBSQL -Statement $sql

    if ($RepairDatabaseCredentials) {
        Invoke-LocalMariaDBSQL -Statement 'SHUTDOWN'
        Wait-Process -Id $server.Id -Timeout 15
        $serverStopped = $true
        Write-Output 'Existing MariaDB account password reconciled with data/.env.production. Database files were preserved.'
        exit 0
    }

    $env:PHPRC = Join-Path $package 'bin\php'
    $env:ADMIN_PASSWORD = $password
    Push-Location $appDirectory
    try {
        if (-not $hasApplicationKey) {
            & $php artisan key:generate --force
            if ($LASTEXITCODE -ne 0) {
                throw 'Could not generate the application encryption key.'
            }
        }
        & $php artisan migrate --force
        if ($LASTEXITCODE -ne 0) {
            throw 'Laravel database migrations failed.'
        }
        & $php artisan db:seed --force
        if ($LASTEXITCODE -ne 0) {
            throw 'Initial administrator and settings setup failed.'
        }
    }
    finally {
        Pop-Location
        Remove-Item Env:\ADMIN_PASSWORD -ErrorAction SilentlyContinue
        Remove-Item Env:\PHPRC -ErrorAction SilentlyContinue
        $password = $null
        $adminPassword = $null
    }

    Invoke-LocalMariaDBSQL -Statement 'SHUTDOWN'
    Wait-Process -Id $server.Id -Timeout 15
    $serverStopped = $true
    Write-Output 'Package setup completed. You can now launch VaultControl.exe and choose START ALL.'
}
finally {
    Remove-Item Env:\ADMIN_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:\PHPRC -ErrorAction SilentlyContinue
    if (-not $serverStopped -and (Get-Process -Id $server.Id -ErrorAction SilentlyContinue)) {
        try {
            Invoke-LocalMariaDBSQL -Statement 'SHUTDOWN'
        }
        catch {
            Write-Warning "Could not gracefully stop setup MariaDB: $($_.Exception.Message)"
        }
        Wait-Process -Id $server.Id -Timeout 15 -ErrorAction SilentlyContinue
        if (Get-Process -Id $server.Id -ErrorAction SilentlyContinue) {
            Stop-Process -Id $server.Id
        }
    }
}
