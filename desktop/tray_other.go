//go:build !windows

package main

func startSystemTray(func(string)) func() {
	return func() {}
}
