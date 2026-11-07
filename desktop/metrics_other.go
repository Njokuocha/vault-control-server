//go:build !windows

package main

import (
	"runtime"
)

func readSystemMetrics() (float64, uint64, uint64, error) {
	var memory runtime.MemStats
	runtime.ReadMemStats(&memory)

	return 0, memory.Alloc, 0, nil
}
