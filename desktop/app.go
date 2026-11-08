package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	wailsruntime "github.com/wailsapp/wails/v2/pkg/runtime"
)

type App struct {
	ctx             context.Context
	baseDir         string
	laravelDir      string
	development     bool
	baseDirErr      error
	startedAt       time.Time
	stackMu         sync.Mutex
	mu              sync.Mutex
	processes       map[string]*managedProcess
	schedulerCancel context.CancelFunc
	schedulerDone   chan struct{}
	trayStop        func()
	exitRequested   atomic.Bool
	shutdownOnce    atomic.Bool
}

type managedProcess struct {
	cmd  *exec.Cmd
	done chan struct{}
}

type Metrics struct {
	CPUPercent        float64 `json:"cpuPercent"`
	MemoryUsedMB      uint64  `json:"memoryUsedMB"`
	MemoryTotalMB     uint64  `json:"memoryTotalMB"`
	ActiveConnections int     `json:"activeConnections"`
	Uptime            string  `json:"uptime"`
}

type TableRows struct {
	Columns []string `json:"columns"`
	Rows    [][]any  `json:"rows"`
}

type databaseSettings struct {
	host     string
	port     string
	database string
	username string
	password string
}

var safeIdentifier = regexp.MustCompile(`^[A-Za-z0-9_]+$`)

const databasePasswordMismatchMessage = "DB password mismatch after reinstall. Rerun setup-package.ps1 with -Force to reconcile DB_PASSWORD in data/.env.production with the existing MariaDB account; data/mysql_data is preserved."

func NewApp() *App {
	executable, err := os.Executable()
	app := &App{
		startedAt: time.Now(),
		processes: make(map[string]*managedProcess),
	}
	if err != nil {
		app.baseDirErr = fmt.Errorf("resolve application directory: %w", err)
		return app
	}

	app.baseDir = filepath.Dir(executable)
	app.laravelDir = filepath.Join(app.baseDir, "app")
	if !hasLaravelArtisan(app.laravelDir) {
		if workingDir, err := os.Getwd(); err == nil {
			if laravelDir, ok := findLaravelDirectory(workingDir); ok {
				app.laravelDir = laravelDir
				app.development = true
			}
		}
	}

	return app
}

func (a *App) startup(ctx context.Context) {
	a.ctx = ctx
	a.trayStop = startSystemTray(func(action string) {
		go a.handleTrayAction(action)
	})
}

func (a *App) onBeforeClose(ctx context.Context) bool {
	if !a.shouldHideOnClose() {
		return false
	}
	wailsruntime.WindowHide(ctx)

	return true
}

func (a *App) shouldHideOnClose() bool {
	return !a.exitRequested.Load()
}

func (a *App) shutdown(context.Context) {
	if !a.shutdownOnce.CompareAndSwap(false, true) {
		return
	}
	if a.trayStop != nil {
		a.trayStop()
	}
	_ = a.StopStack()
}

func (a *App) handleTrayAction(action string) {
	switch action {
	case "open":
		wailsruntime.WindowShow(a.ctx)
	case "start":
		_ = a.StartStack()
	case "stop":
		_ = a.StopStack()
	case "exit":
		if !a.exitRequested.CompareAndSwap(false, true) {
			return
		}
		_ = a.StopStack()
		if a.trayStop != nil {
			a.trayStop()
		}
		wailsruntime.Quit(a.ctx)
	}
}

func (a *App) StartStack() string {
	a.stackMu.Lock()
	defer a.stackMu.Unlock()

	if a.baseDirErr != nil {
		return failureMessage(a.baseDirErr)
	}
	if !a.development {
		if _, err := a.readDatabaseSettings(); err != nil {
			return failureMessage(fmt.Errorf("configure data/.env.production before starting services: %w", err))
		}
	}
	mariaDBExecutable := filepath.Join(a.baseDir, "bin", "mariadb", "bin", "mysqld.exe")
	if _, err := os.Stat(mariaDBExecutable); err != nil {
		return failureMessage(fmt.Errorf("mariadb runtime executable is unavailable: %w", err))
	}
	if err := checkRequiredPorts(); err != nil {
		return failureMessage(err)
	}

	if err := a.startService("mariadb"); err != nil {
		return failureMessage(err)
	}
	if err := a.waitForDatabase(30 * time.Second); err != nil {
		_ = a.stopStack()
		return failureMessage(err)
	}

	for _, name := range []string{"web", "reverb", "queue1", "queue2"} {
		if err := a.startService(name); err != nil {
			_ = a.stopStack()
			return failureMessage(err)
		}
	}
	if err := a.waitForServicesToStayRunning([]string{"web", "reverb", "queue1", "queue2"}, time.Second); err != nil {
		_ = a.stopStack()
		return failureMessage(err)
	}
	a.startScheduler()

	return "All services started."
}

func checkRequiredPorts() error {
	for _, port := range []string{"3307", "8000", "8080", "2019"} {
		address := net.JoinHostPort("127.0.0.1", port)
		connection, err := net.DialTimeout("tcp", address, 150*time.Millisecond)
		if err == nil {
			_ = connection.Close()
			return fmt.Errorf("required service port %s is already in use", port)
		}

		listener, err := net.Listen("tcp", address)
		if err != nil {
			return fmt.Errorf("required service port %s is unavailable: %w", port, err)
		}
		if err := listener.Close(); err != nil {
			return fmt.Errorf("release preflight port %s: %w", address, err)
		}
	}

	return nil
}

func (a *App) StopStack() string {
	a.stackMu.Lock()
	defer a.stackMu.Unlock()

	return a.stopStack()
}

func (a *App) stopStack() string {
	a.stopScheduler()

	var failures []string
	for _, name := range []string{"queue2", "queue1", "reverb", "web", "mariadb"} {
		if err := a.stopService(name); err != nil {
			failures = append(failures, err.Error())
		}
	}
	if len(failures) > 0 {
		return failureMessage(errors.New(strings.Join(failures, "\n")))
	}

	return "All services stopped."
}

func (a *App) RestartService(serviceName string) string {
	a.stackMu.Lock()
	defer a.stackMu.Unlock()

	switch serviceName {
	case "reverb", "queue1", "queue2", "web":
	default:
		return failureMessage(errors.New("unknown service; restartable services are web, reverb, queue1, and queue2"))
	}

	if err := a.stopService(serviceName); err != nil {
		return failureMessage(err)
	}
	if err := a.startService(serviceName); err != nil {
		return failureMessage(err)
	}

	return serviceName + " restarted."
}

func (a *App) GetStatus() map[string]bool {
	status := make(map[string]bool, 5)
	for _, name := range []string{"mariadb", "web", "reverb", "queue1", "queue2"} {
		status[name] = a.serviceRunning(name)
	}

	return status
}

func (a *App) GetLANIP() string {
	interfaces, err := net.Interfaces()
	if err != nil {
		return ""
	}
	for _, networkInterface := range interfaces {
		if networkInterface.Flags&net.FlagUp == 0 || networkInterface.Flags&net.FlagLoopback != 0 {
			continue
		}
		addresses, err := networkInterface.Addrs()
		if err != nil {
			continue
		}
		for _, address := range addresses {
			ipNet, ok := address.(*net.IPNet)
			if !ok || ipNet.IP.IsLoopback() || ipNet.IP.IsLinkLocalUnicast() {
				continue
			}
			if ip := ipNet.IP.To4(); ip != nil {
				return ip.String()
			}
		}
	}

	return ""
}

func (a *App) GetUptime() string {
	return time.Since(a.startedAt).Round(time.Second).String()
}

func (a *App) GetMetrics() (Metrics, error) {
	cpu, usedMemory, totalMemory, err := readSystemMetrics()
	if err != nil {
		return Metrics{}, err
	}
	connections, err := a.activeConnections()
	if err != nil {
		return Metrics{}, err
	}

	return Metrics{
		CPUPercent:        cpu,
		MemoryUsedMB:      usedMemory / (1024 * 1024),
		MemoryTotalMB:     totalMemory / (1024 * 1024),
		ActiveConnections: connections,
		Uptime:            a.GetUptime(),
	}, nil
}

func (a *App) GetLogs() (string, error) {
	if a.baseDirErr != nil {
		return "", a.baseDirErr
	}
	path := filepath.Join(a.applicationDir(), "storage", "logs", "laravel.log")
	file, err := os.Open(path)
	if err != nil {
		return "", fmt.Errorf("open Laravel log: %w", err)
	}
	defer file.Close()

	const maxBytes = 2 * 1024 * 1024
	info, err := file.Stat()
	if err != nil {
		return "", fmt.Errorf("inspect Laravel log: %w", err)
	}
	if info.Size() > maxBytes {
		if _, err := file.Seek(-maxBytes, 2); err != nil {
			return "", fmt.Errorf("seek Laravel log: %w", err)
		}
	}

	scanner := bufio.NewScanner(file)
	scanner.Buffer(make([]byte, 64*1024), 1024*1024)
	lines := make([]string, 0, 100)
	for scanner.Scan() {
		lines = append(lines, scanner.Text())
		if len(lines) > 100 {
			lines = lines[1:]
		}
	}
	if err := scanner.Err(); err != nil {
		return "", fmt.Errorf("read Laravel log: %w", err)
	}

	return strings.Join(lines, "\n"), nil
}

func (a *App) ClearLogs() string {
	if a.baseDirErr != nil {
		return failureMessage(a.baseDirErr)
	}
	path := filepath.Join(a.applicationDir(), "storage", "logs", "laravel.log")
	if err := os.Truncate(path, 0); err != nil {
		return failureMessage(fmt.Errorf("clear Laravel log: %w", err))
	}

	return "Laravel log cleared."
}

func (a *App) ListTables() ([]string, error) {
	if a.development {
		output, err := a.runLaravelPHP(
			laravelBootstrap +
				`$tables = []; foreach (Illuminate\Support\Facades\DB::select('SHOW TABLES') as $row) { $values = array_values((array) $row); if ($values) { $tables[] = $values[0]; } } ` +
				`echo json_encode($tables, JSON_THROW_ON_ERROR);`,
		)
		if err != nil {
			return nil, err
		}
		var tables []string
		if err := json.Unmarshal(output, &tables); err != nil {
			return nil, fmt.Errorf("decode Laravel table list: %w", err)
		}
		return tables, nil
	}

	settings, err := a.readDatabaseSettings()
	if err != nil {
		return nil, err
	}
	output, err := a.runMySQL(settings, "mysql.exe", "--batch", "--skip-column-names", "-e", "SHOW TABLES")
	if err != nil {
		return nil, err
	}

	tables := strings.Fields(string(output))
	return tables, nil
}

func (a *App) GetTableRows(table string) (TableRows, error) {
	if !safeIdentifier.MatchString(table) {
		return TableRows{}, errors.New("invalid table name")
	}

	tables, err := a.ListTables()
	if err != nil {
		return TableRows{}, err
	}
	allowed := false
	for _, candidate := range tables {
		if candidate == table {
			allowed = true
			break
		}
	}
	if !allowed {
		return TableRows{}, errors.New("requested table is not in the current database")
	}

	if a.development {
		return a.getLaravelTableRows(table)
	}

	settings, err := a.readDatabaseSettings()
	if err != nil {
		return TableRows{}, err
	}
	columnOutput, err := a.runMySQL(
		settings,
		"mysql.exe",
		"--batch",
		"--skip-column-names",
		"-e",
		"SHOW COLUMNS FROM `"+table+"`",
	)
	if err != nil {
		return TableRows{}, err
	}

	columns := make([]string, 0)
	for _, line := range strings.Split(strings.TrimSpace(string(columnOutput)), "\n") {
		fields := strings.SplitN(line, "\t", 2)
		if len(fields) != 0 && fields[0] != "" {
			columns = append(columns, fields[0])
		}
	}
	if len(columns) == 0 {
		return TableRows{}, errors.New("selected table has no readable columns")
	}

	expressions := make([]string, 0, len(columns))
	for _, column := range columns {
		escaped := strings.ReplaceAll(column, "`", "``")
		expressions = append(expressions, "`"+escaped+"`")
	}
	query := "SELECT JSON_ARRAY(" + strings.Join(expressions, ",") + ") FROM `" + table + "` LIMIT 100"
	output, err := a.runMySQL(settings, "mysql.exe", "--batch", "--skip-column-names", "-e", query)
	if err != nil {
		return TableRows{}, err
	}

	rows := make([][]any, 0)
	for _, line := range strings.Split(strings.TrimSpace(string(output)), "\n") {
		if strings.TrimSpace(line) == "" {
			continue
		}
		var row []any
		if err := json.Unmarshal([]byte(line), &row); err != nil {
			return TableRows{}, fmt.Errorf("decode table row: %w", err)
		}
		rows = append(rows, row)
	}

	return TableRows{Columns: columns, Rows: rows}, nil
}

func (a *App) BackupDatabase() string {
	settings, err := a.readDatabaseSettings()
	if err != nil {
		return failureMessage(err)
	}
	binary := filepath.Join(a.baseDir, "bin", "mariadb", "bin", "mysqldump.exe")
	if _, err := os.Stat(binary); err != nil {
		return failureMessage(fmt.Errorf("MariaDB dump utility is unavailable: %w", err))
	}

	backupDir := filepath.Join(a.baseDir, "data", "backups")
	if err := os.MkdirAll(backupDir, 0o750); err != nil {
		return failureMessage(fmt.Errorf("create backup directory: %w", err))
	}
	filename := "backup_" + time.Now().Format("2006-01-02_15-04") + ".sql"
	file, err := os.OpenFile(filepath.Join(backupDir, filename), os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o600)
	if err != nil {
		return failureMessage(fmt.Errorf("create database backup: %w", err))
	}

	cmd := exec.Command(
		binary,
		"--no-defaults",
		"--host="+settings.host,
		"--port="+settings.port,
		"--user="+settings.username,
		"--single-transaction",
		settings.database,
	)
	cmd.Env = append(os.Environ(), "MYSQL_PWD="+settings.password)
	cmd.Stdout = file
	var stderr strings.Builder
	cmd.Stderr = &stderr
	configureCommand(cmd)
	err = cmd.Run()
	closeErr := file.Close()
	if err != nil {
		_ = os.Remove(file.Name())
		return failureMessage(fmt.Errorf("database backup failed: %s", strings.TrimSpace(stderr.String())))
	}
	if closeErr != nil {
		return failureMessage(fmt.Errorf("finalize database backup: %w", closeErr))
	}

	return "Database backup created: " + filename
}

const laravelBootstrap = `require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); `

func (a *App) getLaravelTableRows(table string) (TableRows, error) {
	script := laravelBootstrap +
		`$table = '` + table + `'; ` +
		`$columns = Illuminate\Support\Facades\Schema::getColumnListing($table); ` +
		`$rows = Illuminate\Support\Facades\DB::table($table)->limit(100)->get()->map(static fn ($row) => array_values((array) $row))->all(); ` +
		`echo json_encode(['columns' => $columns, 'rows' => $rows], JSON_THROW_ON_ERROR);`
	output, err := a.runLaravelPHP(script)
	if err != nil {
		return TableRows{}, err
	}

	var result TableRows
	if err := json.Unmarshal(output, &result); err != nil {
		return TableRows{}, fmt.Errorf("decode Laravel table rows: %w", err)
	}

	return result, nil
}

func (a *App) runLaravelPHP(script string) ([]byte, error) {
	if a.baseDirErr != nil {
		return nil, a.baseDirErr
	}
	php := filepath.Join(a.baseDir, "bin", "php", "php.exe")
	if a.development {
		var err error
		php, err = exec.LookPath("php.exe")
		if err != nil {
			return nil, fmt.Errorf("PHP executable is unavailable on PATH: %w", err)
		}
	}

	encoded := base64.StdEncoding.EncodeToString([]byte(script))
	cmd := exec.Command(php, "-r", "eval(base64_decode('"+encoded+"'));")
	cmd.Dir = a.applicationDir()
	cmd.Env = append(os.Environ(), "PHPRC="+filepath.Join(a.baseDir, "bin", "php"))
	configureCommand(cmd)
	output, err := cmd.CombinedOutput()
	if err != nil {
		return nil, fmt.Errorf("run Laravel database query: %s", strings.TrimSpace(string(output)))
	}

	return output, nil
}

func failureMessage(err error) string {
	return "ERROR: " + err.Error()
}

func (a *App) startService(name string) error {
	if a.baseDirErr != nil {
		return a.baseDirErr
	}
	a.mu.Lock()
	if process := a.processes[name]; process != nil {
		select {
		case <-process.done:
		default:
			a.mu.Unlock()
			return fmt.Errorf("%s is already running", name)
		}
	}
	a.mu.Unlock()

	command, workingDir, args, err := a.serviceCommand(name)
	if err != nil {
		return err
	}
	if info, err := os.Stat(command); err != nil {
		return fmt.Errorf("%s runtime executable is unavailable: %w", name, err)
	} else if info.IsDir() {
		return fmt.Errorf("%s runtime executable is a directory: %s", name, command)
	}
	if name == "mariadb" {
		if err := a.initializeMariaDB(); err != nil {
			return err
		}
	}

	logDir := filepath.Join(a.baseDir, "data", "logs")
	if err := os.MkdirAll(logDir, 0o750); err != nil {
		return fmt.Errorf("create service logs: %w", err)
	}
	logFile, err := os.OpenFile(filepath.Join(logDir, name+".log"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		return fmt.Errorf("open %s log: %w", name, err)
	}

	cmd := exec.Command(command, args...)
	cmd.Dir = workingDir
	cmd.Stdout = logFile
	cmd.Stderr = logFile
	phpIniDir := filepath.Join(a.baseDir, "bin", "php")
	if name == "web" {
		phpIniDir = filepath.Join(a.baseDir, "bin")
	}
	cmd.Env = append(os.Environ(), "PHPRC="+phpIniDir)
	configureCommand(cmd)
	if err := cmd.Start(); err != nil {
		_ = logFile.Close()
		return fmt.Errorf("start %s: %w", name, err)
	}

	process := &managedProcess{cmd: cmd, done: make(chan struct{})}
	a.mu.Lock()
	a.processes[name] = process
	a.mu.Unlock()

	go func() {
		if err := cmd.Wait(); err != nil {
			_, _ = fmt.Fprintf(logFile, "service exited: %v\n", err)
		}
		_ = logFile.Close()
		close(process.done)
	}()

	return nil
}

func (a *App) initializeMariaDB() error {
	dataDir := filepath.Join(a.baseDir, "data", "mysql_data")
	systemTables := filepath.Join(dataDir, "mysql")
	if entries, err := os.ReadDir(systemTables); err == nil && len(entries) > 0 {
		return nil
	} else if err != nil && !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("inspect MariaDB data directory: %w", err)
	}
	if entries, err := os.ReadDir(dataDir); err == nil && len(entries) > 0 {
		return errors.New("MariaDB data exists but system tables are missing; refusing to reinitialize or overwrite data/mysql_data")
	} else if err != nil && !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("inspect MariaDB data directory: %w", err)
	}

	installer := filepath.Join(a.baseDir, "bin", "mariadb", "bin", "mariadb-install-db.exe")
	if _, err := os.Stat(installer); err != nil {
		return fmt.Errorf("MariaDB is not initialized and its installer is unavailable: %w", err)
	}
	config := filepath.Join(a.baseDir, "bin", "mariadb", "my.ini")
	if _, err := os.Stat(config); err != nil {
		return fmt.Errorf("MariaDB configuration is unavailable: %w", err)
	}
	if err := os.MkdirAll(dataDir, 0o750); err != nil {
		return fmt.Errorf("create MariaDB data directory: %w", err)
	}

	command := exec.Command(
		installer,
		"--datadir="+dataDir,
		"--port=3307",
		"--config="+config,
		"--silent",
	)
	command.Dir = a.baseDir
	configureCommand(command)
	output, err := command.CombinedOutput()
	if err != nil {
		return fmt.Errorf("initialize MariaDB data directory: %s", strings.TrimSpace(string(output)))
	}
	entries, err := os.ReadDir(systemTables)
	if err != nil || len(entries) == 0 {
		if err != nil {
			return fmt.Errorf("verify MariaDB initialization: %w", err)
		}
		return errors.New("MariaDB initialization completed without creating system tables")
	}

	return nil
}

func (a *App) stopService(name string) error {
	a.mu.Lock()
	process := a.processes[name]
	a.mu.Unlock()
	if process == nil {
		return nil
	}

	select {
	case <-process.done:
		return nil
	default:
	}
	if err := process.cmd.Process.Kill(); err != nil {
		select {
		case <-process.done:
			return nil
		default:
			return fmt.Errorf("stop %s: %w", name, err)
		}
	}
	select {
	case <-process.done:
	case <-time.After(5 * time.Second):
		return fmt.Errorf("timed out waiting for %s to stop", name)
	}

	return nil
}

func (a *App) serviceRunning(name string) bool {
	a.mu.Lock()
	process := a.processes[name]
	a.mu.Unlock()
	if process == nil {
		return false
	}
	select {
	case <-process.done:
		return false
	default:
		return true
	}
}

func (a *App) serviceCommand(name string) (string, string, []string, error) {
	root := a.baseDir
	appDir := a.applicationDir()
	php := filepath.Join(root, "bin", "php", "php.exe")
	switch name {
	case "mariadb":
		return filepath.Join(root, "bin", "mariadb", "bin", "mysqld.exe"), root,
			[]string{"--defaults-file=bin/mariadb/my.ini"}, nil
	case "web":
		return filepath.Join(root, "bin", "frankenphp.exe"), appDir,
			[]string{"run", "--config", "Caddyfile", "--adapter", "caddyfile"}, nil
	case "reverb":
		return php, appDir, []string{"artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"}, nil
	case "queue1":
		return php, appDir, []string{"artisan", "queue:work", "database", "--queue=broadcasts,default", "--sleep=3", "--timeout=2100"}, nil
	case "queue2":
		return php, appDir, []string{"artisan", "queue:work", "database", "--queue=video-processing", "--sleep=3", "--timeout=2100"}, nil
	default:
		return "", "", nil, fmt.Errorf("unknown service %q", name)
	}
}

func (a *App) waitForDatabase(timeout time.Duration) error {
	deadline := time.Now().Add(timeout)
	notBefore := time.Now().Add(3 * time.Second)
	var settings databaseSettings
	if !a.development {
		var err error
		settings, err = a.readDatabaseSettings()
		if err != nil {
			return err
		}
	}
	var queryErr error

	for time.Now().Before(deadline) {
		if !time.Now().Before(notBefore) {
			connection, err := net.DialTimeout("tcp", "127.0.0.1:3307", 250*time.Millisecond)
			if err == nil {
				_ = connection.Close()
				if a.development {
					return nil
				}
				if _, err := a.runMySQL(settings, "mysql.exe", "--batch", "--skip-column-names", "-e", "SELECT 1"); err == nil {
					return nil
				} else {
					if isDatabaseAuthenticationError(err) {
						return errors.New(databasePasswordMismatchMessage)
					}
					queryErr = err
				}
			}
			if !a.serviceRunning("mariadb") {
				return errors.New("MariaDB exited before becoming ready; inspect data/logs/mariadb.log")
			}
		}
		time.Sleep(250 * time.Millisecond)
	}

	if queryErr != nil {
		return fmt.Errorf("MariaDB did not accept a database query within %s: %w", timeout, queryErr)
	}
	return fmt.Errorf("MariaDB did not become ready within %s", timeout)
}

func isDatabaseAuthenticationError(err error) bool {
	if err == nil {
		return false
	}
	message := strings.ToLower(err.Error())

	return strings.Contains(message, "error 1045") || strings.Contains(message, "access denied for user")
}

func (a *App) waitForServicesToStayRunning(names []string, duration time.Duration) error {
	deadline := time.Now().Add(duration)
	ticker := time.NewTicker(100 * time.Millisecond)
	defer ticker.Stop()

	for {
		for _, name := range names {
			if !a.serviceRunning(name) {
				return fmt.Errorf("%s exited during startup; inspect data/logs/%s.log", name, name)
			}
		}
		if !time.Now().Before(deadline) {
			return nil
		}
		<-ticker.C
	}
}

func (a *App) startScheduler() {
	a.mu.Lock()
	if a.schedulerCancel != nil {
		a.mu.Unlock()
		return
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	a.schedulerCancel = cancel
	a.schedulerDone = done
	a.mu.Unlock()

	go func() {
		defer close(done)
		ticker := time.NewTicker(60 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				a.runSchedulerCommand(ctx)
			}
		}
	}()
}

func (a *App) stopScheduler() {
	a.mu.Lock()
	cancel := a.schedulerCancel
	done := a.schedulerDone
	a.schedulerCancel = nil
	a.schedulerDone = nil
	a.mu.Unlock()
	if cancel == nil {
		return
	}
	cancel()
	<-done
}

func (a *App) runSchedulerCommand(ctx context.Context) {
	command := filepath.Join(a.baseDir, "bin", "php", "php.exe")
	cmd := exec.CommandContext(ctx, command, "artisan", "schedule:run")
	cmd.Dir = a.applicationDir()
	cmd.Env = append(os.Environ(), "PHPRC="+filepath.Join(a.baseDir, "bin", "php"))
	configureCommand(cmd)

	logPath := filepath.Join(a.baseDir, "data", "logs", "scheduler.log")
	file, err := os.OpenFile(logPath, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		return
	}
	cmd.Stdout = file
	cmd.Stderr = file
	if err := cmd.Run(); err != nil && ctx.Err() == nil {
		_, _ = fmt.Fprintf(file, "scheduler command failed: %v\n", err)
	}
	_ = file.Close()
}

func (a *App) applicationDir() string {
	if a.laravelDir != "" {
		return a.laravelDir
	}

	return filepath.Join(a.baseDir, "app")
}

func hasLaravelArtisan(directory string) bool {
	info, err := os.Stat(filepath.Join(directory, "artisan"))
	return err == nil && !info.IsDir()
}

func findLaravelDirectory(start string) (string, bool) {
	directory, err := filepath.Abs(start)
	if err != nil {
		return "", false
	}
	for {
		if hasLaravelArtisan(directory) {
			return directory, true
		}
		parent := filepath.Dir(directory)
		if parent == directory {
			return "", false
		}
		directory = parent
	}
}

func (a *App) activeConnections() (int, error) {
	cmd := exec.Command("netstat.exe", "-ano", "-p", "tcp")
	configureCommand(cmd)
	output, err := cmd.Output()
	if err != nil {
		return 0, fmt.Errorf("read active TCP connections: %w", err)
	}

	count := 0
	for _, line := range strings.Split(string(output), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 4 || !strings.EqualFold(fields[3], "ESTABLISHED") {
			continue
		}
		localAddress := fields[1]
		if strings.HasSuffix(localAddress, ":8000") || strings.HasSuffix(localAddress, ":8080") {
			count++
		}
	}

	return count, nil
}

func (a *App) readDatabaseSettings() (databaseSettings, error) {
	if a.baseDirErr != nil {
		return databaseSettings{}, a.baseDirErr
	}
	path := filepath.Join(a.baseDir, "data", ".env.production")
	file, err := os.Open(path)
	if err != nil {
		return databaseSettings{}, fmt.Errorf("open database environment file: %w", err)
	}
	defer file.Close()

	values := make(map[string]string)
	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		value = strings.TrimSpace(value)
		if len(value) >= 2 && ((value[0] == '"' && value[len(value)-1] == '"') || (value[0] == '\'' && value[len(value)-1] == '\'')) {
			value = value[1 : len(value)-1]
		}
		values[strings.TrimSpace(key)] = value
	}
	if err := scanner.Err(); err != nil {
		return databaseSettings{}, fmt.Errorf("read database environment file: %w", err)
	}

	settings := databaseSettings{
		host:     values["DB_HOST"],
		port:     values["DB_PORT"],
		database: values["DB_DATABASE"],
		username: values["DB_USERNAME"],
		password: values["DB_PASSWORD"],
	}
	if settings.host == "" {
		settings.host = "127.0.0.1"
	}
	if settings.port == "" {
		settings.port = "3307"
	}
	if settings.host != "127.0.0.1" && settings.host != "localhost" {
		return databaseSettings{}, errors.New("database viewer only permits a loopback database host")
	}
	if settings.database == "" || settings.username == "" {
		return databaseSettings{}, errors.New("DB_DATABASE and DB_USERNAME must be set in data/.env.production")
	}

	return settings, nil
}

func (a *App) runMySQL(settings databaseSettings, executable string, args ...string) ([]byte, error) {
	binary := filepath.Join(a.baseDir, "bin", "mariadb", "bin", executable)
	if _, err := os.Stat(binary); err != nil {
		return nil, fmt.Errorf("MariaDB client is unavailable: %w", err)
	}

	commandArgs := []string{
		"--no-defaults",
		"--host=" + settings.host,
		"--port=" + settings.port,
		"--user=" + settings.username,
		"--database=" + settings.database,
	}
	commandArgs = append(commandArgs, args...)
	cmd := exec.Command(binary, commandArgs...)
	cmd.Env = append(os.Environ(), "MYSQL_PWD="+settings.password)
	configureCommand(cmd)
	var output bytes.Buffer
	var stderr bytes.Buffer
	cmd.Stdout = &output
	cmd.Stderr = &stderr
	err := cmd.Run()
	if err != nil {
		details := strings.TrimSpace(stderr.String())
		if details == "" {
			details = strings.TrimSpace(output.String())
		}
		if details == "" {
			return nil, fmt.Errorf("run read-only database query: %w", err)
		}
		return nil, fmt.Errorf("run read-only database query: %s", details)
	}

	return output.Bytes(), nil
}
