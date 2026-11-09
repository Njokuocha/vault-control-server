package main

import (
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestNormalizeServerIP(t *testing.T) {
	tests := []struct {
		name  string
		input string
		want  string
	}{
		{name: "plain ipv4", input: "192.168.1.40", want: "192.168.1.40"},
		{name: "http url", input: "http://192.168.1.40:8000/", want: "192.168.1.40"},
		{name: "https url", input: "https://10.2.3.4", want: "10.2.3.4"},
		{name: "port suffix", input: "172.20.1.5:8000", want: "172.20.1.5"},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			got, err := normalizeServerIP(test.input)
			if err != nil {
				t.Fatalf("normalizeServerIP(%q): %v", test.input, err)
			}
			if got != test.want {
				t.Fatalf("normalizeServerIP(%q) = %q, want %q", test.input, got, test.want)
			}
		})
	}
}

func TestNormalizeServerIPRejectsInvalidInput(t *testing.T) {
	for _, input := range []string{
		"", "vault-server", "256.1.1.1", "192.168.1.40:80",
		"file://192.168.1.40", "javascript:alert(1)", "http://192.168.1.40/admin",
	} {
		if _, err := normalizeServerIP(input); err == nil {
			t.Errorf("normalizeServerIP(%q) unexpectedly succeeded", input)
		}
	}
}

func TestPublicAddressRequiresAdminPolicy(t *testing.T) {
	public := net.ParseIP("8.8.8.8")
	if isAllowedServerIP(public, false) {
		t.Fatal("public IP was allowed without administrator policy")
	}
	if !isAllowedServerIP(public, true) {
		t.Fatal("public IP was blocked when administrator policy allows it")
	}
	for _, address := range []string{"10.1.2.3", "172.20.0.2", "192.168.1.2", "127.0.0.1", "100.90.0.4"} {
		if !isAllowedServerIP(net.ParseIP(address), false) {
			t.Errorf("private address %s was blocked", address)
		}
	}
}

func TestRewriteProxyBodyKeepsVaultAndBlocksExternalLinks(t *testing.T) {
	input := `<html><a href="/login">Login</a><a href="https://outside.example/path">External</a><form action="/admin/login"></form><script>window.Echo={wsHost:window.location.hostname,wsPort:8080};</script><img src="http://localhost:8000/logo.png">`
	got := string(rewriteProxyBody([]byte(input), "192.168.1.40", "text/html", "http://vaultclient.localhost:12345", 12345))
	for _, expected := range []string{
		`href="/_vault_proxy/login"`,
		`href="#external-link-blocked"`,
		`action="/_vault_proxy/admin/login"`,
		`wsPort:12345`,
		`src="http://vaultclient.localhost:12345/_vault_proxy/logo.png"`,
	} {
		if !strings.Contains(got, expected) {
			t.Errorf("rewritten response missing %q:\n%s", expected, got)
		}
	}
}

func TestDisconnectDisablesAutoConnectAndPreservesRememberedServer(t *testing.T) {
	configRoot := t.TempDir()
	t.Setenv("APPDATA", configRoot)
	t.Setenv("XDG_CONFIG_HOME", configRoot)
	prefs := preferences{
		LastServer:  "192.168.1.40",
		Remember:    true,
		AutoConnect: true,
	}
	if err := writeJSON(preferencesPath(), prefs); err != nil {
		t.Fatal(err)
	}
	app := NewApp()
	app.serverIP = prefs.LastServer

	if err := app.Disconnect(); err != nil {
		t.Fatalf("Disconnect() error: %v", err)
	}
	if app.IsConnected() {
		t.Fatal("client remained connected after Disconnect")
	}
	saved, err := readJSON[preferences](filepath.Join(os.Getenv("APPDATA"), "VaultClient", "config.json"))
	if err != nil {
		t.Fatal(err)
	}
	if saved.AutoConnect {
		t.Fatal("automatic connection remained enabled after Disconnect")
	}
	if saved.LastServer != prefs.LastServer || !saved.Remember {
		t.Fatalf("Disconnect changed remembered server settings: %+v", saved)
	}
}

func TestRewriteContentSecurityPoliciesForProxy(t *testing.T) {
	headers := make(http.Header)
	headers.Add("Content-Security-Policy", "default-src 'self'; connect-src http://192.168.1.40:8000 ws://192.168.1.40:8080; frame-ancestors 'none'")

	rewriteContentSecurityPolicies(headers, "192.168.1.40", "http://vaultclient-192-168-1-40.localhost:12345")

	policy := strings.Join(headers.Values("Content-Security-Policy"), ";")
	for _, expected := range []string{
		"http://vaultclient-192-168-1-40.localhost:12345/_vault_proxy",
		"ws://vaultclient-192-168-1-40.localhost:12345",
	} {
		if !strings.Contains(policy, expected) {
			t.Errorf("rewritten policy missing %q: %s", expected, policy)
		}
		if strings.Contains(policy, "frame-ancestors") {
			t.Errorf("upstream frame-ancestors was not removed: %s", policy)
		}
	}
}
