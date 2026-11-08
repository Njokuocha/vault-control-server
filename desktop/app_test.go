package main

import (
	"bytes"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/wailsapp/go-webview2/webviewloader"
)

func TestServiceCommandsUsePackagedPathsAndQueueConfiguration(t *testing.T) {
	root := filepath.Clean(filepath.Join("C:", "VaultControl"))
	app := &App{baseDir: root}
	php := filepath.Join(root, "bin", "php", "php.exe")
	workingDir := filepath.Join(root, "app")

	tests := []struct {
		name      string
		command   string
		directory string
		args      []string
	}{
		{
			name:      "web",
			command:   filepath.Join(root, "bin", "frankenphp.exe"),
			directory: workingDir,
			args:      []string{"run", "--config", "Caddyfile", "--adapter", "caddyfile"},
		},
		{
			name:      "reverb",
			command:   php,
			directory: workingDir,
			args:      []string{"artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"},
		},
		{
			name:      "queue1",
			command:   php,
			directory: workingDir,
			args:      []string{"artisan", "queue:work", "database", "--queue=broadcasts,default", "--sleep=3", "--timeout=2100"},
		},
		{
			name:      "queue2",
			command:   php,
			directory: workingDir,
			args:      []string{"artisan", "queue:work", "database", "--queue=video-processing", "--sleep=3", "--timeout=2100"},
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			command, directory, args, err := app.serviceCommand(test.name)
			if err != nil {
				t.Fatal(err)
			}
			if command != test.command || directory != test.directory || !reflect.DeepEqual(args, test.args) {
				t.Fatalf("unexpected service command: %q, %q, %#v", command, directory, args)
			}
		})
	}
}

func TestDatabaseSettingsRequireLoopbackHost(t *testing.T) {
	root := t.TempDir()
	dataDir := filepath.Join(root, "data")
	if err := os.MkdirAll(dataDir, 0o750); err != nil {
		t.Fatal(err)
	}
	envPath := filepath.Join(dataDir, ".env.production")
	env := "DB_HOST=192.168.1.20\nDB_PORT=3307\nDB_DATABASE=vault_control\nDB_USERNAME=vault\n"
	if err := os.WriteFile(envPath, []byte(env), 0o600); err != nil {
		t.Fatal(err)
	}

	app := &App{baseDir: root}
	if _, err := app.readDatabaseSettings(); err == nil {
		t.Fatal("expected non-loopback database host to be rejected")
	}

	env = "DB_HOST=127.0.0.1\nDB_PORT=3307\nDB_DATABASE=vault_control\nDB_USERNAME=vault\n"
	if err := os.WriteFile(envPath, []byte(env), 0o600); err != nil {
		t.Fatal(err)
	}
	settings, err := app.readDatabaseSettings()
	if err != nil {
		t.Fatal(err)
	}
	if settings.host != "127.0.0.1" || settings.port != "3307" || settings.database != "vault_control" {
		t.Fatalf("unexpected database settings: %#v", settings)
	}
}

func TestDatabaseAuthenticationErrorsIdentifyReinstallPasswordMismatch(t *testing.T) {
	tests := []struct {
		name string
		err  error
		want bool
	}{
		{name: "MariaDB 1045", err: errors.New("run read-only database query: ERROR 1045 (28000): Access denied for user"), want: true},
		{name: "access denied text", err: errors.New("access denied for user 'vault_control'@'127.0.0.1'"), want: true},
		{name: "connection refused", err: errors.New("dial tcp: connect: connection refused"), want: false},
		{name: "nil", err: nil, want: false},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := isDatabaseAuthenticationError(test.err); got != test.want {
				t.Fatalf("isDatabaseAuthenticationError(%v) = %t, want %t", test.err, got, test.want)
			}
		})
	}
}

func TestGetTableRowsRejectsNonIdentifierBeforeDatabaseAccess(t *testing.T) {
	app := &App{baseDir: t.TempDir()}
	if _, err := app.GetTableRows("users; DROP TABLE users"); err == nil {
		t.Fatal("expected invalid table identifier to be rejected")
	}
}

func TestWindowCloseHidesUnlessExitWasRequested(t *testing.T) {
	app := &App{}
	if !app.shouldHideOnClose() {
		t.Fatal("normal window close should hide the window")
	}
	app.exitRequested.Store(true)
	if app.shouldHideOnClose() {
		t.Fatal("window close should proceed after tray Exit")
	}
}

func TestFindLaravelDirectoryWalksToProjectRoot(t *testing.T) {
	root := t.TempDir()
	desktopDir := filepath.Join(root, "desktop")
	if err := os.MkdirAll(desktopDir, 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, "artisan"), []byte(""), 0o600); err != nil {
		t.Fatal(err)
	}

	directory, ok := findLaravelDirectory(desktopDir)
	if !ok || directory != root {
		t.Fatalf("findLaravelDirectory() = %q, %t; want %q, true", directory, ok, root)
	}
}

func TestStartStackReportsMissingRuntimeBeforeCreatingLogs(t *testing.T) {
	root := t.TempDir()
	dataDir := filepath.Join(root, "data")
	if err := os.MkdirAll(dataDir, 0o750); err != nil {
		t.Fatal(err)
	}
	env := "DB_HOST=127.0.0.1\nDB_PORT=3307\nDB_DATABASE=vault_control\nDB_USERNAME=vault_control\nDB_PASSWORD=password\n"
	if err := os.WriteFile(filepath.Join(dataDir, ".env.production"), []byte(env), 0o600); err != nil {
		t.Fatal(err)
	}
	app := &App{baseDir: root, processes: make(map[string]*managedProcess)}

	message := app.StartStack()
	if !strings.Contains(message, "ERROR: mariadb runtime executable is unavailable") {
		t.Fatalf("unexpected start result: %s", message)
	}
	if _, err := os.Stat(filepath.Join(dataDir, "mysql_data")); !os.IsNotExist(err) {
		t.Fatalf("expected no database initialization after missing runtime, got %v", err)
	}
	if _, err := os.Stat(filepath.Join(dataDir, "logs")); !os.IsNotExist(err) {
		t.Fatalf("expected no logs directory after missing runtime, got %v", err)
	}
}

func TestInitializeMariaDBPreservesUninitializedExistingData(t *testing.T) {
	root := t.TempDir()
	dataDir := filepath.Join(root, "data", "mysql_data")
	existingData := filepath.Join(dataDir, "ibdata1")
	if err := os.MkdirAll(dataDir, 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(existingData, []byte("existing database data"), 0o600); err != nil {
		t.Fatal(err)
	}

	app := &App{baseDir: root}
	err := app.initializeMariaDB()
	if err == nil || !strings.Contains(err.Error(), "refusing to reinitialize") {
		t.Fatalf("initializeMariaDB() error = %v, want refusal to reinitialize existing data", err)
	}
	if contents, err := os.ReadFile(existingData); err != nil || string(contents) != "existing database data" {
		t.Fatalf("existing database file changed: contents=%q, error=%v", contents, err)
	}
}

func TestDevelopmentReadsLaravelLogAndDatabase(t *testing.T) {
	if os.Getenv("VAULTCONTROL_LIVE_INTEGRATION") != "1" {
		t.Skip("set VAULTCONTROL_LIVE_INTEGRATION=1 to use the local Laravel database")
	}

	app := NewApp()
	if !app.development {
		t.Fatal("expected the source-checkout development layout")
	}
	if _, err := app.GetLogs(); err != nil {
		t.Fatal(err)
	}

	tables, err := app.ListTables()
	if err != nil {
		t.Fatal(err)
	}
	if len(tables) == 0 {
		t.Fatal("expected tables from the configured Laravel database")
	}
	rows, err := app.GetTableRows(tables[0])
	if err != nil {
		t.Fatal(err)
	}
	if len(rows.Columns) == 0 || len(rows.Rows) > 100 {
		t.Fatalf("unexpected table result shape: %d columns, %d rows", len(rows.Columns), len(rows.Rows))
	}
}

func TestPackagedWebViewRuntime(t *testing.T) {
	root := os.Getenv("VAULTCONTROL_PACKAGE_INTEGRATION")
	if root == "" {
		t.Skip("set VAULTCONTROL_PACKAGE_INTEGRATION to a package directory")
	}

	version, err := webviewloader.GetAvailableCoreWebView2BrowserVersionString(filepath.Join(root, "bin", "webview2"))
	if err != nil || version == "" {
		t.Fatalf("fixed WebView2 runtime is unavailable: version=%q, error=%v", version, err)
	}
	t.Logf("Using fixed WebView2 runtime %s", version)
}

func TestPackagedStackIntegration(t *testing.T) {
	root := os.Getenv("VAULTCONTROL_PACKAGE_INTEGRATION")
	if root == "" {
		t.Skip("set VAULTCONTROL_PACKAGE_INTEGRATION to an initialized test package directory")
	}

	app := &App{
		baseDir:    root,
		laravelDir: filepath.Join(root, "app"),
		processes:  make(map[string]*managedProcess),
		startedAt:  time.Now(),
	}
	for name, running := range app.GetStatus() {
		if running {
			t.Fatalf("%s unexpectedly running before StartStack", name)
		}
	}

	stopped := false
	defer func() {
		if !stopped {
			if result := app.StopStack(); result != "All services stopped." {
				t.Errorf("StopStack() = %q", result)
			}
		}
	}()

	startResult := app.StartStack()
	reverbPortOccupied := strings.Contains(startResult, "required service port 8080 is already in use")
	if reverbPortOccupied {
		for name, running := range app.GetStatus() {
			if running {
				t.Fatalf("%s started despite the occupied Reverb port", name)
			}
		}
		t.Log("Reverb startup is blocked by an existing listener on port 8080; testing the other packaged services.")
		if err := app.startService("mariadb"); err != nil {
			t.Fatal(err)
		}
		if err := app.waitForDatabase(30 * time.Second); err != nil {
			t.Fatal(err)
		}
		if _, err := app.ListTables(); err != nil {
			t.Fatalf("packaged database query failed: %v", err)
		}
		for _, name := range []string{"web", "queue1", "queue2"} {
			if err := app.startService(name); err != nil {
				t.Fatal(err)
			}
		}
	} else if startResult != "All services started." {
		t.Fatalf("StartStack() = %q", startResult)
	}

	expectedServices := []string{"mariadb", "web", "queue1", "queue2"}
	if !reverbPortOccupied {
		expectedServices = append(expectedServices, "reverb")
	}
	deadline := time.Now().Add(90 * time.Second)
	webReady := false
	for time.Now().Before(deadline) {
		status := app.GetStatus()
		allRunning := true
		for _, name := range expectedServices {
			allRunning = allRunning && status[name]
		}
		if allRunning {
			response, err := (&http.Client{Timeout: 2 * time.Second}).Get("http://127.0.0.1:8000/up")
			if err == nil {
				_ = response.Body.Close()
				if response.StatusCode == http.StatusOK {
					webReady = true
					break
				}
			}
		}
		time.Sleep(250 * time.Millisecond)
	}
	if !webReady {
		t.Fatalf("packaged web service did not return HTTP 200 from /up; status: %#v", app.GetStatus())
	}
	for _, name := range expectedServices {
		if !app.serviceRunning(name) {
			t.Errorf("%s stopped unexpectedly; inspect data/logs/%s.log", name, name)
		}
	}

	tables, err := app.ListTables()
	if err != nil {
		t.Fatal(err)
	}
	hasMigrations := false
	for _, table := range tables {
		hasMigrations = hasMigrations || table == "migrations"
	}
	if !hasMigrations {
		t.Fatal("packaged database does not contain the migrations table")
	}
	rows, err := app.GetTableRows("migrations")
	if err != nil {
		t.Fatal(err)
	}
	if len(rows.Columns) == 0 || len(rows.Rows) == 0 {
		t.Fatalf("unexpected migrations table result: %d columns, %d rows", len(rows.Columns), len(rows.Rows))
	}
	if _, err := app.GetMetrics(); err != nil {
		t.Fatal(err)
	}

	if result := app.RestartService("queue1"); result != "queue1 restarted." {
		t.Fatalf("RestartService(queue1) = %q", result)
	}
	time.Sleep(500 * time.Millisecond)
	if !app.serviceRunning("queue1") {
		t.Fatal("queue1 exited after restart")
	}

	if result := app.StopStack(); result != "All services stopped." {
		t.Fatalf("StopStack() = %q", result)
	}
	stopped = true
	for _, name := range expectedServices {
		if app.serviceRunning(name) {
			t.Errorf("%s remains running after StopStack", name)
		}
	}
}

func TestPackagedDatabaseAuthenticationIntegration(t *testing.T) {
	root := os.Getenv("VAULTCONTROL_PACKAGE_INTEGRATION")
	if root == "" {
		t.Skip("set VAULTCONTROL_PACKAGE_INTEGRATION to an initialized package directory")
	}

	app := &App{
		baseDir:    root,
		laravelDir: filepath.Join(root, "app"),
		processes:  make(map[string]*managedProcess),
		startedAt:  time.Now(),
	}
	if err := app.startService("mariadb"); err != nil {
		t.Fatal(err)
	}
	defer func() {
		if result := app.StopStack(); result != "All services stopped." {
			t.Errorf("StopStack() = %q", result)
		}
	}()
	if err := app.waitForDatabase(30 * time.Second); err != nil {
		t.Fatal(err)
	}
	if _, err := app.ListTables(); err != nil {
		t.Fatalf("database login succeeded but table listing failed: %v", err)
	}
}

func TestPackagedSchedulerIntegration(t *testing.T) {
	root := os.Getenv("VAULTCONTROL_PACKAGE_INTEGRATION")
	if root == "" {
		t.Skip("set VAULTCONTROL_PACKAGE_INTEGRATION to an initialized test package directory")
	}

	app := &App{
		baseDir:    root,
		laravelDir: filepath.Join(root, "app"),
		processes:  make(map[string]*managedProcess),
		startedAt:  time.Now(),
	}
	if err := app.startService("mariadb"); err != nil {
		t.Fatal(err)
	}
	defer func() {
		app.stopScheduler()
		if result := app.StopStack(); result != "All services stopped." {
			t.Errorf("StopStack() = %q", result)
		}
	}()
	if err := app.waitForDatabase(30 * time.Second); err != nil {
		t.Fatal(err)
	}

	logPath := filepath.Join(root, "data", "logs", "scheduler.log")
	if err := os.Remove(logPath); err != nil && !os.IsNotExist(err) {
		t.Fatal(err)
	}
	app.startScheduler()

	deadline := time.Now().Add(125 * time.Second)
	for time.Now().Before(deadline) {
		log, err := os.ReadFile(logPath)
		if err == nil && bytes.Contains(log, []byte("staff:expire-freezes")) {
			t.Logf("Scheduler ran an every-minute job; output logged to %s", logPath)
			return
		}
		time.Sleep(250 * time.Millisecond)
	}

	log, err := os.ReadFile(logPath)
	if err != nil {
		t.Fatalf("scheduler did not create its log file: %v", err)
	}
	t.Fatalf("scheduler did not log the every-minute staff:expire-freezes job; log contents: %s", log)
}
