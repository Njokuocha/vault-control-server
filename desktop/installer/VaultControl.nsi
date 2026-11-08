Unicode true
RequestExecutionLevel admin
SetCompressor /SOLID zlib
ManifestDPIAware true
ShowInstDetails show
ShowUninstDetails show

!ifndef PACKAGE_DIR
    !error "PACKAGE_DIR must point to a clean runtime package."
!endif
!ifndef OUTPUT_FILE
    !define OUTPUT_FILE "..\build\VaultControl-Setup.exe"
!endif
!ifndef ICON_FILE
    !define ICON_FILE "..\build\windows\icon.ico"
!endif
!ifndef SETUP_SCRIPT
    !define SETUP_SCRIPT "${PACKAGE_DIR}\setup-package.ps1"
!endif

!include "MUI2.nsh"
!include "LogicLib.nsh"
!include "x64.nsh"
!include "WinVer.nsh"

!define PRODUCT_NAME "VaultControl"
!define PRODUCT_VERSION "1.0.0"
!define PRODUCT_PUBLISHER "Vault Control"
!define PRODUCT_EXE "VaultControl.exe"
!define UNINSTALL_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\VaultControl"

Name "${PRODUCT_NAME}"
OutFile "${OUTPUT_FILE}"
InstallDir "$PROGRAMFILES64\VaultControl"
InstallDirRegKey HKLM "${UNINSTALL_KEY}" "InstallLocation"
VIProductVersion "${PRODUCT_VERSION}.0"
VIAddVersionKey "ProductName" "${PRODUCT_NAME}"
VIAddVersionKey "ProductVersion" "${PRODUCT_VERSION}"
VIAddVersionKey "CompanyName" "${PRODUCT_PUBLISHER}"
VIAddVersionKey "FileDescription" "${PRODUCT_NAME} Setup"
VIAddVersionKey "FileVersion" "${PRODUCT_VERSION}"
VIAddVersionKey "LegalCopyright" "Copyright Vault Control"

!define MUI_ABORTWARNING
!define MUI_ICON "${ICON_FILE}"
!define MUI_UNICON "${ICON_FILE}"
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "English"

Function .onInit
    ${IfNot} ${AtLeastWin10}
        MessageBox MB_ICONSTOP "VaultControl requires Windows 10 or later."
        Abort
    ${EndIf}
    ${IfNot} ${IsNativeAMD64}
        MessageBox MB_ICONSTOP "This installer supports 64-bit x64 Windows."
        Abort
    ${EndIf}
FunctionEnd

!macro GrantWritableDataDirectory path
    ExecWait '"$SYSDIR\icacls.exe" "${path}" /grant "*S-1-5-32-545:(OI)(CI)M" /T /C' $0
    ${If} $0 != 0
        MessageBox MB_ICONSTOP "Could not grant the local Users group access to ${path}. Installation cannot continue."
        Abort
    ${EndIf}
!macroend

Section "Install VaultControl"
    SetShellVarContext all
    SetOutPath "$INSTDIR"
    File "${PACKAGE_DIR}\VaultControl.exe"
    File /oname=setup-package.ps1 "${SETUP_SCRIPT}"

    SetOutPath "$INSTDIR\bin"
    File /r "${PACKAGE_DIR}\bin\*"
    SetOutPath "$INSTDIR\app"
    File /r "${PACKAGE_DIR}\app\*"
    SetOutPath "$INSTDIR\licenses"
    File /r "${PACKAGE_DIR}\licenses\*"

    CreateDirectory "$INSTDIR\data"
    CreateDirectory "$INSTDIR\data\backups"
    CreateDirectory "$INSTDIR\data\logs"
    CreateDirectory "$INSTDIR\data\mysql_data"
    CreateDirectory "$INSTDIR\data\bootstrap\cache"
    CreateDirectory "$INSTDIR\data\storage\app\private"
    CreateDirectory "$INSTDIR\data\storage\app\public"
    CreateDirectory "$INSTDIR\data\storage\framework\cache\data"
    CreateDirectory "$INSTDIR\data\storage\framework\sessions"
    CreateDirectory "$INSTDIR\data\storage\framework\testing"
    CreateDirectory "$INSTDIR\data\storage\framework\views"
    CreateDirectory "$INSTDIR\data\storage\logs"
    IfFileExists "$INSTDIR\data\.env.production.example" env_example_exists
    SetOutPath "$INSTDIR\data"
    File "${PACKAGE_DIR}\data\.env.production.example"
env_example_exists:

    !insertmacro GrantWritableDataDirectory "$INSTDIR\data\backups"
    !insertmacro GrantWritableDataDirectory "$INSTDIR\data\logs"
    !insertmacro GrantWritableDataDirectory "$INSTDIR\data\mysql_data"
    !insertmacro GrantWritableDataDirectory "$INSTDIR\data\bootstrap\cache"
    !insertmacro GrantWritableDataDirectory "$INSTDIR\data\storage"

    IfFileExists "$INSTDIR\data\.env.production" repair_existing_database
    IfSilent setup_complete
    ExecWait '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "$INSTDIR\setup-package.ps1" -Wizard -Force -PackageDir "$INSTDIR"' $0
    ${If} $0 != 0
        MessageBox MB_ICONEXCLAMATION "First-run setup was cancelled or failed. The application is installed, but must be configured by running setup-package.ps1 before starting services."
    ${EndIf}
    Goto warm_laravel_manifests

repair_existing_database:
    ExecWait '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "$INSTDIR\setup-package.ps1" -RepairDatabaseCredentials -PackageDir "$INSTDIR"' $0
    ${If} $0 != 0
        MessageBox MB_ICONSTOP "Could not reconcile the existing MariaDB account with data\.env.production. Database files were not reinitialized. Inspect data\logs\setup-mariadb.log and run setup-package.ps1 -Force after resolving the error."
        Abort
    ${EndIf}

    IfFileExists "$INSTDIR\data\.env.production" warm_laravel_manifests
    Goto setup_complete
warm_laravel_manifests:
    SetOutPath "$INSTDIR\app"
    ExecWait '"$INSTDIR\bin\php\php.exe" artisan package:discover --ansi' $0
    ${If} $0 != 0
        MessageBox MB_ICONSTOP "Could not prepare Laravel's writable package cache. Inspect data\storage\logs\laravel.log and data\bootstrap\cache."
        Abort
    ${EndIf}
setup_complete:

    CreateShortcut "$SMPROGRAMS\${PRODUCT_NAME}.lnk" "$INSTDIR\${PRODUCT_EXE}"
    CreateShortcut "$DESKTOP\${PRODUCT_NAME}.lnk" "$INSTDIR\${PRODUCT_EXE}"
    WriteUninstaller "$INSTDIR\uninstall.exe"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayName" "${PRODUCT_NAME}"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "DisplayVersion" "${PRODUCT_VERSION}"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "Publisher" "${PRODUCT_PUBLISHER}"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "InstallLocation" "$INSTDIR"
    WriteRegStr HKLM "${UNINSTALL_KEY}" "UninstallString" '"$INSTDIR\uninstall.exe"'
    WriteRegDWORD HKLM "${UNINSTALL_KEY}" "NoModify" 1
    WriteRegDWORD HKLM "${UNINSTALL_KEY}" "NoRepair" 1
SectionEnd

Section "Uninstall"
    SetShellVarContext all
    Delete "$SMPROGRAMS\${PRODUCT_NAME}.lnk"
    Delete "$DESKTOP\${PRODUCT_NAME}.lnk"
    Delete "$INSTDIR\uninstall.exe"
    Delete "$INSTDIR\setup-package.ps1"
    Delete "$INSTDIR\${PRODUCT_EXE}"
    RMDir /r "$INSTDIR\app"
    RMDir /r "$INSTDIR\bin"
    RMDir /r "$INSTDIR\licenses"
    DeleteRegKey HKLM "${UNINSTALL_KEY}"
    RMDir "$INSTDIR"
SectionEnd
