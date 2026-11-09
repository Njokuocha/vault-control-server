# Vault Client

Vault Client is a standalone Windows desktop shell for the existing Vault web
application. It is separate from VaultControl, does not contain database
credentials, and does not change server authentication. Users still sign in
through Laravel.

## Requirements and behavior

- 64-bit Windows 10 or later.
- Go, Node.js/npm, Wails CLI, and NSIS to build the installer.
- The packaged app always uses Microsoft's signed **WebView2 Fixed Version
  Runtime** under `bin\webview2`. It does not depend on Evergreen WebView2,
  install a browser, or download a runtime when launched.
- The connection page accepts an explicit IPv4 address only. It also strips
  `http://`, `https://`, an optional `:8000`, and a trailing slash from pasted
  URLs. Hostnames are intentionally not resolved.
- Private IPv4, loopback, link-local, and the shared VPN range `100.64.0.0/10`
  are allowed by default. Other public addresses are blocked unless an administrator creates
  `C:\ProgramData\VaultClient\policy.json` with:

  ```json
  {
    "allowPublicIP": true
  }
  ```

- The client probes `http://<IP>:8000/up` with a four-second timeout. After a
  successful probe it reads `/client-config` when available; older servers
  fall back to the entered IP and Reverb port `8080`.
- Laravel is shown inside a locked WebView. The client proxies web and Reverb
  traffic only to the entered server, blocks external links, and does not show
  a URL or browser chrome. **Change Server** clears the WebView's server
  cookies and local storage before returning to the IP page. Each server IP has
  its own local WebView origin, so login cookies are not shared across servers.
- The normal window close exits cleanly. The first launch is fullscreen;
  subsequent launches restore the last size, position, and fullscreen state.
  The signed-in browser session remains on the device until Change Server or
  normal Laravel session expiry.
- **Change Server** is also available with `Ctrl+Shift+D` while the Vault page
  has focus.
- The remembered IP, window state, and WebView data live under
  `%APPDATA%\VaultClient`. The installer and uninstaller do not remove them.

Direct browser access to `http://<server-ip>:8000` remains available; Vault
Client is a convenience application, not an access-control gate.

## Build

From the repository root in PowerShell:

```powershell
cd .\client
go mod download
.\package-client.ps1
.\build-installer.ps1
```

`package-client.ps1` builds the Wails app and stages
`build\package\VaultClient.exe` plus the pinned fixed-version WebView2 runtime.
It reuses the already staged VaultControl runtime when available; otherwise it
downloads and SHA-256 verifies Microsoft's pinned CAB. The runtime is
deliberately included, so the final setup executable is expected to be
250 MB or larger.

`build-installer.ps1` requires NSIS and creates the single-file
`build\VaultClientSetup.exe`. The installer requires administrator approval
and installs to `C:\Program Files\VaultClient`. It creates Desktop and Start
Menu shortcuts and a standard Windows uninstall entry.

## Install, connect, and change server

Run `VaultClientSetup.exe`, approve the administrator prompt, and finish the
wizard. Launch **Vault Client**, enter the IPv4 address supplied by the
administrator, and select **Connect**. A successful connection opens the
existing Vault login page. Check **Connect automatically next time** in the
client toolbar only if this device should reconnect to the remembered server
on launch.

Use **Change Server** to clear the web session and return to the IP page.
It disables automatic reconnection; the remembered IP remains available unless
**Remember this server** is unchecked on the connection page.

## Uninstall

Use **Vault Client** in Windows Installed Apps, or its Uninstall entry. This
removes the installed program and shortcuts but intentionally preserves
`%APPDATA%\VaultClient\config.json`, `window.json`, and WebView2 session data.
Delete that per-user folder separately only if the user explicitly wants to
remove remembered settings and all local client session data.

## Server route and packaging

The Laravel app exposes public `GET /client-config`, returning only the web
port, request host, and Reverb port. It contains no secrets. The existing
`/up` health endpoint and normal browser routes are unchanged.

Generated executables, staged packages, the WebView2 runtime, and frontend
build output are ignored by Git. The app icon, installer definition, and build
scripts remain source assets.
