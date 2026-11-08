//go:build windows

package main

import "testing"

func TestSingleInstanceMutexAllowsOnlyOneOwner(t *testing.T) {
	primary, releasePrimary, err := acquireSingleInstance()
	if err != nil {
		t.Fatal(err)
	}
	if !primary {
		t.Skip("another VaultControl instance owns the single-instance mutex")
	}
	defer releasePrimary()

	secondary, releaseSecondary, err := acquireSingleInstance()
	if err != nil {
		t.Fatal(err)
	}
	if secondary {
		releaseSecondary()
		t.Fatal("second acquisition unexpectedly became primary")
	}
	if releaseSecondary != nil {
		t.Fatal("secondary acquisition should not retain the mutex")
	}
}
