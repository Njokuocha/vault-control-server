Unicode true
RequestExecutionLevel admin
SetCompressor /SOLID zlib
ManifestDPIAware true
ShowInstDetails show
ShowUninstDetails show

!ifndef PACKAGE_DIR
    !error "PACKAGE_DIR must point to a complete Vault Client package."
!endif
!ifndef OUTPUT_FILE
    !define OUTPUT_FILE "..\build\VaultClientSetup.exe"
!endif
!ifndef ICON_FILE
    !define ICON_FILE "..\build\windows\icon.ico"
!endif

!include "MUI2.nsh"
!include "LogicLib.nsh"
!include "x64.nsh"
!include "WinVer.nsh"

!define PRODUCT_NAME "Vault Client"
!define PRODUCT_VERSION "1.0.0"
!define PRODUCT_PUBLISHER "Vault Control"
!define PRODUCT_EXE "VaultClient.exe"
!define UNINSTALL_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\VaultClient"

Name "${PRODUCT_NAME}"
OutFile "${OUTPUT_FILE}"
InstallDir "$PROGRAMFILES64\VaultClient"
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
        MessageBox MB_ICONSTOP "Vault Client requires 64-bit Windows 10 or later."
        Abort
    ${EndIf}
    ${IfNot} ${IsNativeAMD64}
        MessageBox MB_ICONSTOP "Vault Client supports 64-bit x64 Windows."
        Abort
    ${EndIf}
FunctionEnd

Section "Install Vault Client"
    SetShellVarContext all
    SetOutPath "$INSTDIR"
    File /r "${PACKAGE_DIR}\*"

    CreateShortcut "$SMPROGRAMS\${PRODUCT_NAME}.lnk" "$INSTDIR\${PRODUCT_EXE}" "" "$INSTDIR\${PRODUCT_EXE}"
    CreateShortcut "$DESKTOP\${PRODUCT_NAME}.lnk" "$INSTDIR\${PRODUCT_EXE}" "" "$INSTDIR\${PRODUCT_EXE}"
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
    Delete "$INSTDIR\${PRODUCT_EXE}"
    RMDir /r "$INSTDIR\bin"
    DeleteRegKey HKLM "${UNINSTALL_KEY}"
    RMDir "$INSTDIR"
SectionEnd
