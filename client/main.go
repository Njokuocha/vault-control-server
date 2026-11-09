package main

import (
	"context"
	"embed"
	"log"
	"os"
	"path/filepath"

	"github.com/wailsapp/wails/v2"
	"github.com/wailsapp/wails/v2/pkg/options"
	"github.com/wailsapp/wails/v2/pkg/options/assetserver"
	"github.com/wailsapp/wails/v2/pkg/options/windows"
	wailsruntime "github.com/wailsapp/wails/v2/pkg/runtime"
)

//go:embed all:frontend/dist
var assets embed.FS

func main() {
	executable, err := os.Executable()
	if err != nil {
		log.Fatalf("Resolve Vault Client executable: %v", err)
	}
	webviewPath := filepath.Join(filepath.Dir(executable), "bin", "webview2")
	if info, err := os.Stat(filepath.Join(webviewPath, "msedgewebview2.exe")); err != nil || info.IsDir() {
		log.Fatal("Vault Client requires its bundled WebView2 Fixed Version Runtime in bin\\webview2; Evergreen WebView2 is not supported.")
	}

	app := NewApp()
	initial := app.InitialState()
	err = wails.Run(&options.App{
		Title:      "Vault Client",
		Width:      initial.Window.Width,
		Height:     initial.Window.Height,
		Fullscreen: initial.Window.Fullscreen,
		MinWidth:   720,
		MinHeight:  560,
		AssetServer: &assetserver.Options{
			Assets: assets,
		},
		BackgroundColour: &options.RGBA{R: 8, G: 16, B: 29, A: 1},
		Windows: &windows.Options{
			WebviewBrowserPath:   webviewPath,
			WebviewUserDataPath:  filepath.Join(configDirectory(), "WebView2"),
			IsZoomControlEnabled: false,
			DisablePinchZoom:     true,
			Theme:                windows.Dark,
		},
		EnableDefaultContextMenu: false,
		OnStartup:                app.Startup,
		OnShutdown:               app.Shutdown,
		OnDomReady: func(ctx context.Context) {
			if !initial.Window.Fullscreen {
				wailsruntime.WindowSetSize(ctx, initial.Window.Width, initial.Window.Height)
				wailsruntime.WindowSetPosition(ctx, initial.Window.X, initial.Window.Y)
			}
		},
		Bind: []interface{}{app},
	})
	if err != nil {
		log.Fatalf("Run Vault Client: %v", err)
	}
}
