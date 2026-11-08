//go:build windows

package main

import (
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"syscall"
	"time"
	"unsafe"
)

const (
	singleInstanceMutexName = `Local\VaultControl.SingleInstance`
	errorAlreadyExists      = 183
)

var (
	singleInstanceKernel32 = syscall.NewLazyDLL("kernel32.dll")
	singleInstanceUser32   = syscall.NewLazyDLL("user32.dll")
	createMutexW           = singleInstanceKernel32.NewProc("CreateMutexW")
	closeHandle            = singleInstanceKernel32.NewProc("CloseHandle")
	openProcess            = singleInstanceKernel32.NewProc("OpenProcess")
	queryFullProcessImage  = singleInstanceKernel32.NewProc("QueryFullProcessImageNameW")
	enumWindows            = singleInstanceUser32.NewProc("EnumWindows")
	getClassNameW          = singleInstanceUser32.NewProc("GetClassNameW")
	getWindowProcessID     = singleInstanceUser32.NewProc("GetWindowThreadProcessId")
	instanceSearchMu       sync.Mutex
	activeInstanceSearch   *instanceWindowSearch
)

type instanceWindowSearch struct {
	executablePath string
	found          bool
}

var enumInstanceWindowsCallback = syscall.NewCallback(func(hwnd, _ uintptr) uintptr {
	instanceSearchMu.Lock()
	search := activeInstanceSearch
	instanceSearchMu.Unlock()
	if search == nil {
		return 1
	}

	className := make([]uint16, 256)
	classLength, _, _ := getClassNameW.Call(hwnd, uintptr(unsafe.Pointer(&className[0])), uintptr(len(className)))
	if syscall.UTF16ToString(className[:classLength]) != "VaultControlTrayWindow" {
		return 1
	}

	var processID uint32
	_, _, _ = getWindowProcessID.Call(hwnd, uintptr(unsafe.Pointer(&processID)))
	process, _, _ := openProcess.Call(0x1000, 0, uintptr(processID))
	if process == 0 {
		return 1
	}
	defer closeHandle.Call(process)

	imagePath := make([]uint16, 32768)
	imagePathLength := uint32(len(imagePath))
	result, _, _ := queryFullProcessImage.Call(
		process,
		0,
		uintptr(unsafe.Pointer(&imagePath[0])),
		uintptr(unsafe.Pointer(&imagePathLength)),
	)
	if result == 0 {
		return 1
	}
	if !strings.EqualFold(filepath.Clean(syscall.UTF16ToString(imagePath[:imagePathLength])), search.executablePath) {
		return 1
	}

	_, _, _ = postMessageW.Call(hwnd, wmAppActivate, 0, 0)
	instanceSearchMu.Lock()
	search.found = true
	instanceSearchMu.Unlock()
	return 0
})

func acquireSingleInstance() (bool, func(), error) {
	name, err := syscall.UTF16PtrFromString(singleInstanceMutexName)
	if err != nil {
		return false, nil, fmt.Errorf("encode single-instance mutex name: %w", err)
	}

	handle, _, callErr := createMutexW.Call(0, 1, uintptr(unsafe.Pointer(name)))
	lastError := syscall.GetLastError()
	runtime.KeepAlive(name)
	if handle == 0 {
		return false, nil, fmt.Errorf("create single-instance mutex: %w", callErr)
	}

	if lastError == syscall.Errno(errorAlreadyExists) || callErr == syscall.Errno(errorAlreadyExists) {
		_, _, _ = closeHandle.Call(handle)
		return false, nil, nil
	}

	return true, func() {
		_, _, _ = closeHandle.Call(handle)
	}, nil
}

func focusExistingInstance() error {
	executablePath, err := os.Executable()
	if err != nil {
		return fmt.Errorf("resolve current executable path: %w", err)
	}

	search := instanceWindowSearch{executablePath: filepath.Clean(executablePath)}
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		instanceSearchMu.Lock()
		activeInstanceSearch = &search
		instanceSearchMu.Unlock()
		_, _, _ = enumWindows.Call(enumInstanceWindowsCallback, 0)
		instanceSearchMu.Lock()
		activeInstanceSearch = nil
		if search.found {
			instanceSearchMu.Unlock()
			return nil
		}
		search.found = false
		instanceSearchMu.Unlock()
		time.Sleep(100 * time.Millisecond)
	}

	return fmt.Errorf("existing VaultControl tray window was not found")
}
