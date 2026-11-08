package main

import (
	"embed"
	"log"
	"os"
	"path/filepath"

	"github.com/wailsapp/wails/v2"
	"github.com/wailsapp/wails/v2/pkg/options"
	"github.com/wailsapp/wails/v2/pkg/options/assetserver"
	"github.com/wailsapp/wails/v2/pkg/options/windows"
)

//go:embed all:frontend/dist
var assets embed.FS

func main() {
	primary, releaseInstance, err := acquireSingleInstance()
	if err != nil {
		log.Printf("Acquire single-instance lock: %v", err)
		return
	}
	if !primary {
		if err := focusExistingInstance(); err != nil {
			log.Printf("Focus existing VaultControl instance: %v", err)
		}
		return
	}
	defer releaseInstance()

	app := NewApp()
	webviewPath := ""
	if executable, err := os.Executable(); err == nil {
		candidate := filepath.Join(filepath.Dir(executable), "bin", "webview2")
		if info, err := os.Stat(filepath.Join(candidate, "msedgewebview2.exe")); err == nil && !info.IsDir() {
			webviewPath = candidate
		}
	}

	err = wails.Run(&options.App{
		Title:     "VaultControl",
		Width:     1180,
		Height:    840,
		MinWidth:  1050,
		MinHeight: 720,
		AssetServer: &assetserver.Options{
			Assets: assets,
		},
		BackgroundColour:  &options.RGBA{R: 8, G: 14, B: 22, A: 1},
		Windows:           &windows.Options{WebviewBrowserPath: webviewPath},
		OnStartup:         app.startup,
		OnBeforeClose:     app.onBeforeClose,
		OnShutdown:        app.shutdown,
		HideWindowOnClose: true,
		Bind: []interface{}{
			app,
		},
	})

	if err != nil {
		println("Error:", err.Error())
	}
}
