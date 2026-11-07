//go:build windows

package main

import (
	"fmt"
	"log"
	"runtime"
	"sync"
	"sync/atomic"
	"syscall"
	"unsafe"
)

const (
	wmDestroy       = 0x0002
	wmClose         = 0x0010
	wmCommand       = 0x0111
	wmAppTray       = 0x8001
	wmContextMenu   = 0x007B
	wmLeftButtonUp  = 0x0202
	wmLeftButtonDbl = 0x0203
	wmRightButtonUp = 0x0205
	nimAdd          = 0
	nimDelete       = 2
	nifMessage      = 1
	nifIcon         = 2
	nifTip          = 4
	mfString        = 0
	tpmRightButton  = 2
	tpmReturnCmd    = 0x0100
)

type trayPoint struct {
	x int32
	y int32
}

type trayMessage struct {
	window  syscall.Handle
	message uint32
	wParam  uintptr
	lParam  uintptr
	time    uint32
	point   trayPoint
	private uint32
}

type trayWindowClass struct {
	size        uint32
	style       uint32
	windowProc  uintptr
	classExtra  int32
	windowExtra int32
	instance    syscall.Handle
	icon        syscall.Handle
	cursor      syscall.Handle
	background  syscall.Handle
	menuName    *uint16
	className   *uint16
	smallIcon   syscall.Handle
}

type notifyIconData struct {
	size           uint32
	window         syscall.Handle
	id             uint32
	flags          uint32
	callback       uint32
	icon           syscall.Handle
	tip            [128]uint16
	state          uint32
	stateMask      uint32
	info           [256]uint16
	timeoutVersion uint32
	infoTitle      [64]uint16
	infoFlags      uint32
	guid           [16]byte
	balloonIcon    syscall.Handle
}

var (
	trayUser32           = syscall.NewLazyDLL("user32.dll")
	trayShell32          = syscall.NewLazyDLL("shell32.dll")
	registerClassExW     = trayUser32.NewProc("RegisterClassExW")
	createWindowExW      = trayUser32.NewProc("CreateWindowExW")
	defWindowProcW       = trayUser32.NewProc("DefWindowProcW")
	getMessageW          = trayUser32.NewProc("GetMessageW")
	translateMessage     = trayUser32.NewProc("TranslateMessage")
	dispatchMessageW     = trayUser32.NewProc("DispatchMessageW")
	postQuitMessage      = trayUser32.NewProc("PostQuitMessage")
	postMessageW         = trayUser32.NewProc("PostMessageW")
	destroyWindow        = trayUser32.NewProc("DestroyWindow")
	loadIconW            = trayUser32.NewProc("LoadIconW")
	createPopupMenu      = trayUser32.NewProc("CreatePopupMenu")
	appendMenuW          = trayUser32.NewProc("AppendMenuW")
	trackPopupMenu       = trayUser32.NewProc("TrackPopupMenu")
	destroyMenu          = trayUser32.NewProc("DestroyMenu")
	setForegroundWindow  = trayUser32.NewProc("SetForegroundWindow")
	getCursorPos         = trayUser32.NewProc("GetCursorPos")
	shellNotifyIconW     = trayShell32.NewProc("Shell_NotifyIconW")
	trayWindowHandle     atomic.Uintptr
	trayActionCallback   func(string)
	trayActionCallbackMu sync.RWMutex
	trayWindowProcedure  = syscall.NewCallback(handleTrayWindowMessage)
)

func startSystemTray(onAction func(string)) func() {
	stop := make(chan struct{})
	ready := make(chan struct{})
	done := make(chan struct{})
	trayActionCallbackMu.Lock()
	trayActionCallback = onAction
	trayActionCallbackMu.Unlock()

	go runSystemTray(stop, ready, done)

	return func() {
		select {
		case <-stop:
		default:
			close(stop)
		}
		<-ready
		if hwnd := trayWindowHandle.Load(); hwnd != 0 {
			_, _, _ = postMessageW.Call(hwnd, wmClose, 0, 0)
		}
		<-done
		trayActionCallbackMu.Lock()
		trayActionCallback = nil
		trayActionCallbackMu.Unlock()
	}
}

func runSystemTray(stop <-chan struct{}, ready chan<- struct{}, done chan<- struct{}) {
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()
	defer close(done)

	instance, _, _ := kernel32.NewProc("GetModuleHandleW").Call(0)
	className, _ := syscall.UTF16PtrFromString("VaultControlTrayWindow")
	windowClass := trayWindowClass{
		size:       uint32(unsafe.Sizeof(trayWindowClass{})),
		windowProc: trayWindowProcedure,
		instance:   syscall.Handle(instance),
		className:  className,
	}
	if atom, _, err := registerClassExW.Call(uintptr(unsafe.Pointer(&windowClass))); atom == 0 {
		log.Printf("Register system tray window: %v", err)
		close(ready)
		return
	}

	title, _ := syscall.UTF16PtrFromString("Vault Control Server")
	hwnd, _, err := createWindowExW.Call(
		0,
		uintptr(unsafe.Pointer(className)),
		uintptr(unsafe.Pointer(title)),
		0,
		0, 0, 0, 0,
		0, 0,
		instance,
		0,
	)
	if hwnd == 0 {
		log.Printf("Create system tray window: %v", err)
		close(ready)
		return
	}
	trayWindowHandle.Store(hwnd)
	if !addTrayIcon(syscall.Handle(hwnd)) {
		trayWindowHandle.Store(0)
		_, _, _ = destroyWindow.Call(hwnd)
		close(ready)
		return
	}
	close(ready)

	go func() {
		<-stop
		if current := trayWindowHandle.Load(); current != 0 {
			_, _, _ = postMessageW.Call(current, wmClose, 0, 0)
		}
	}()

	var message trayMessage
	for {
		result, _, _ := getMessageW.Call(uintptr(unsafe.Pointer(&message)), 0, 0, 0)
		if int32(result) <= 0 {
			break
		}
		_, _, _ = translateMessage.Call(uintptr(unsafe.Pointer(&message)))
		_, _, _ = dispatchMessageW.Call(uintptr(unsafe.Pointer(&message)))
	}
	trayWindowHandle.Store(0)
}

func addTrayIcon(hwnd syscall.Handle) bool {
	icon, _, err := loadIconW.Call(0, 32512)
	if icon == 0 {
		log.Printf("Load system tray icon: %v", err)
		return false
	}
	data := notifyIconData{
		size:     uint32(unsafe.Sizeof(notifyIconData{})),
		window:   hwnd,
		id:       1,
		flags:    nifMessage | nifIcon | nifTip,
		callback: wmAppTray,
		icon:     syscall.Handle(icon),
	}
	copy(data.tip[:], syscall.StringToUTF16("Vault Control Server"))
	result, _, err := shellNotifyIconW.Call(nimAdd, uintptr(unsafe.Pointer(&data)))
	if result == 0 {
		log.Printf("Add system tray icon: %v", err)
		return false
	}
	return true
}

func showTrayMenu(hwnd syscall.Handle) {
	menuHandle, _, err := createPopupMenu.Call()
	if menuHandle == 0 {
		log.Printf("Create system tray menu: %v", err)
		return
	}
	defer destroyMenu.Call(menuHandle)

	labels := []string{"Open Panel", "Start All", "Stop All", "Exit"}
	for index, label := range labels {
		text, _ := syscall.UTF16PtrFromString(label)
		_, _, _ = appendMenuW.Call(menuHandle, mfString, uintptr(index+1), uintptr(unsafe.Pointer(text)))
	}

	var point trayPoint
	_, _, _ = getCursorPos.Call(uintptr(unsafe.Pointer(&point)))
	_, _, _ = setForegroundWindow.Call(uintptr(hwnd))
	command, _, _ := trackPopupMenu.Call(
		menuHandle,
		tpmReturnCmd|tpmRightButton,
		uintptr(point.x),
		uintptr(point.y),
		0,
		uintptr(hwnd),
		0,
	)
	switch command {
	case 1:
		dispatchTrayAction("open")
	case 2:
		dispatchTrayAction("start")
	case 3:
		dispatchTrayAction("stop")
	case 4:
		dispatchTrayAction("exit")
	}
}

func handleTrayWindowMessage(hwnd uintptr, message uint32, wParam, lParam uintptr) uintptr {
	switch message {
	case wmAppTray:
		switch uint32(lParam) {
		case wmLeftButtonUp, wmLeftButtonDbl:
			dispatchTrayAction("open")
		case wmRightButtonUp, wmContextMenu:
			showTrayMenu(syscall.Handle(hwnd))
		}
		return 0
	case wmCommand:
		return 0
	case wmClose:
		data := notifyIconData{
			size:   uint32(unsafe.Sizeof(notifyIconData{})),
			window: syscall.Handle(hwnd),
			id:     1,
		}
		_, _, _ = shellNotifyIconW.Call(nimDelete, uintptr(unsafe.Pointer(&data)))
		_, _, _ = destroyWindow.Call(hwnd)
		return 0
	case wmDestroy:
		_, _, _ = postQuitMessage.Call(0)
		return 0
	}

	result, _, _ := defWindowProcW.Call(hwnd, uintptr(message), wParam, lParam)
	return result
}

func dispatchTrayAction(action string) {
	trayActionCallbackMu.RLock()
	callback := trayActionCallback
	trayActionCallbackMu.RUnlock()
	if callback == nil {
		return
	}
	go callback(action)
}

func init() {
	if err := kernel32.Load(); err != nil {
		panic(fmt.Sprintf("load Windows kernel API: %v", err))
	}
}
