# Vault Control desktop controller

This Wails application is the local Windows control panel for the packaged Laravel
server. It does not start services on launch. Use **START ALL** to start MariaDB,
FrankenPHP, Reverb, both configured queue workers, and the scheduler. Closing the
window hides it to the system tray; **Exit** in the tray menu stops managed
processes before quitting.

The Windows app and controller frontend use the VaultControl brand assets from
`vault-control-brand-assets.zip`; the Laravel site's `public\favicon.ico` uses
the matching VaultControl icon. The separate Vault Client has its own assets
and build under the repository-root `client\` directory.

The controller resolves all paths relative to `VaultControl.exe`. The packaged
layout is expected to contain:

```text
VaultControl.exe
bin/
  frankenphp.exe
  php.ini
  php/php.exe
  php/php.ini
  mariadb/bin/mysqld.exe
  mariadb/bin/mysql.exe
  mariadb/bin/mysqldump.exe
  mariadb/my.ini
  webview2/msedgewebview2.exe
app/
  Caddyfile
  artisan
data/
  .env.production
  .env.production.example
  bootstrap/cache/
  storage/logs/laravel.log
  mysql_data/
  backups/
  logs/
```

Database viewing is read-only and runs through local MariaDB client processes
using `data/.env.production`. The database host must be loopback (`127.0.0.1` or
`localhost`); the desktop controller exposes no database HTTP endpoint.
Displayed table results are limited to 100 rows.

The fixed-version WebView2 runtime is stored under `bin/webview2` and is
selected by the controller when present. Unlike the Evergreen runtime, Microsoft
does not automatically update a fixed-version runtime; refresh it with a
security update when rebuilding the package. This adds over 250 MB to the
runtime package.

Packaged Laravel storage and bootstrap cache files live under `data\storage`
and `data\bootstrap\cache`, with installer-granted write access for standard
users. The production environment file remains separately permissioned.
Laravel and scheduler logs are both shown in the controller; missing or empty
logs display as `No logs yet`.

Build the standalone runtime directory from the repository root's checked-out
Laravel files with:

```powershell
cd .\desktop
.\package-runtime.ps1
```

The package script downloads pinned Windows runtimes, builds the frontend with
the public Reverb key, builds VaultControl with `wails build`, and stages Laravel
code without copying the repository `.env` or its storage data. Both packaging
and setup scripts default to `build\package`; refreshing an existing package
preserves its `data` directory. It leaves a
`data\.env.production.example` and an interactive one-time setup script. After
packaging, run:

```powershell
cd .\desktop\build\package
.\setup-package.ps1
```

Before refreshing an existing package, the script prompts you to exit any
running VaultControl tray process. Pass `-Force` to stop it automatically by
process ID before replacing the executable.

The setup generates a unique application key and Reverb secret, prompts for an
administrator email/password and database password, initializes a fresh local
database, and runs migrations. The DB listens only on loopback; the repository's `.env`, live
Herd database, and storage data are not copied into the package. The setup script
does not run when the desktop application launches.

To rerun setup against an existing package, pass `-Force`; it preserves the
existing application key and database credentials and reuses the existing
production environment settings. The wizard does not ask for a replacement
database password in that case, and setup reconciles the MariaDB account with
the saved password without reinitializing existing system tables. If the
environment file is missing but initialized MariaDB data exists, setup asks
for a replacement password and applies it to the existing account; non-empty
data without system tables is left untouched and reported as an error.

Silent installer refreshes reconcile the existing MariaDB account with the
saved environment password without changing the environment file or
application database contents.

Start All checks database authentication before starting the other services. An
authentication error reports `DB password mismatch after reinstall` and
recommends rerunning setup with `-Force`; it does not delete database files.

To verify database credentials without starting web or queue services, point the
integration test at an initialized package (with its services stopped):

```powershell
$env:VAULTCONTROL_PACKAGE_INTEGRATION = (Resolve-Path .\build\package).Path
go test -run '^TestPackagedDatabaseAuthenticationIntegration$' -v
Remove-Item Env:\VAULTCONTROL_PACKAGE_INTEGRATION
```

Build the single-file Windows installer after building the runtime package:

```powershell
.\build-installer.ps1
```

This requires NSIS. The installer targets `C:\Program Files\VaultControl`,
embeds runtime and application files but never embeds `data\.env.production` or
`data\mysql_data`, and starts a first-run wizard to collect the administrator
and database passwords. Reinstalling updates application files while preserving
the existing `data` directory; uninstalling also leaves data in place.

The scheduler is an opt-in integration test against a disposable configured
package. It starts only that package's MariaDB and waits for an every-minute
Laravel job to appear in `data\logs\scheduler.log`:

```powershell
$env:VAULTCONTROL_PACKAGE_INTEGRATION = (Resolve-Path .\build\phase4-package-check).Path
go test -run '^TestPackagedSchedulerIntegration$' -v
Remove-Item Env:\VAULTCONTROL_PACKAGE_INTEGRATION
```

For an integration smoke test, use a disposable package only; the test starts
queue workers and therefore must not run against production data:

```powershell
$env:VAULTCONTROL_PACKAGE_INTEGRATION = (Resolve-Path .\build\package).Path
go test -run '^TestPackaged' -v
Remove-Item Env:\VAULTCONTROL_PACKAGE_INTEGRATION
```

The test starts and stops the package services, queries the local database, and
checks the web health endpoint. It requires ports 3307, 8000, 8080, and 2019 to
be available for a full-stack run. If 8080 is occupied, it verifies that Start
All refuses to start and tests the other services separately (which still
requires ports 3307, 8000, and 2019 to be available).

The development controller can read the working tree's Laravel log and query
its configured Herd database. Its Start All action still needs the packaged
runtime layout; it does not substitute development services for the production
commands.

Build the Windows application from this directory with:

```powershell
wails build
```

Run the development interface from this directory with:

```powershell
$env:WAILS_DEV_ALLOWED_HOSTS = "wails.localhost"
wails dev
```

In a source checkout, the controller locates the Laravel project above
`desktop/`, reads its application log, and queries its configured database
through `php.exe` on `PATH`. Packaged builds use the separate `app/` directory,
bundled PHP and MariaDB tools, and `data/.env.production`.
