//go:build windows

package main

import (
	"fmt"
	"sync"
	"syscall"
	"unsafe"
)

type systemFileTime struct {
	low  uint32
	high uint32
}

type memoryStatusEx struct {
	length               uint32
	memoryLoad           uint32
	totalPhysical        uint64
	availablePhysical    uint64
	totalPageFile        uint64
	availablePageFile    uint64
	totalVirtual         uint64
	availableVirtual     uint64
	availableExtendedVir uint64
}

var (
	kernel32             = syscall.NewLazyDLL("kernel32.dll")
	getSystemTimes       = kernel32.NewProc("GetSystemTimes")
	globalMemoryStatusEx = kernel32.NewProc("GlobalMemoryStatusEx")
	cpuSampleMu          sync.Mutex
	lastTotalSystemTime  uint64
	lastIdleSystemTime   uint64
)

func readSystemMetrics() (float64, uint64, uint64, error) {
	var idle, kernel, user systemFileTime
	result, _, callErr := getSystemTimes.Call(
		uintptr(unsafe.Pointer(&idle)),
		uintptr(unsafe.Pointer(&kernel)),
		uintptr(unsafe.Pointer(&user)),
	)
	if result == 0 {
		return 0, 0, 0, fmt.Errorf("read CPU times: %w", callErr)
	}

	idleTime := uint64(idle.high)<<32 | uint64(idle.low)
	kernelTime := uint64(kernel.high)<<32 | uint64(kernel.low)
	userTime := uint64(user.high)<<32 | uint64(user.low)
	totalTime := kernelTime + userTime

	cpuSampleMu.Lock()
	cpuPercent := float64(0)
	if lastTotalSystemTime != 0 && totalTime > lastTotalSystemTime {
		totalDelta := totalTime - lastTotalSystemTime
		idleDelta := idleTime - lastIdleSystemTime
		cpuPercent = float64(totalDelta-idleDelta) * 100 / float64(totalDelta)
	}
	lastTotalSystemTime = totalTime
	lastIdleSystemTime = idleTime
	cpuSampleMu.Unlock()

	memory := memoryStatusEx{}
	memory.length = uint32(unsafe.Sizeof(memory))
	result, _, callErr = globalMemoryStatusEx.Call(uintptr(unsafe.Pointer(&memory)))
	if result == 0 {
		return 0, 0, 0, fmt.Errorf("read memory status: %w", callErr)
	}

	return cpuPercent, memory.totalPhysical - memory.availablePhysical, memory.totalPhysical, nil
}
