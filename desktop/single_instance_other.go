//go:build !windows

package main

func acquireSingleInstance() (bool, func(), error) {
	return true, func() {}, nil
}

func focusExistingInstance() error {
	return nil
}
