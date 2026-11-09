package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

const (
	webPort       = 8000
	reverbPort    = 8080
	proxyPrefix   = "/_vault_proxy"
	probeTimeout  = 4 * time.Second
	maxProxyBytes = 16 << 20
)

var sharedVPNRange = &net.IPNet{IP: net.IP{100, 64, 0, 0}, Mask: net.CIDRMask(10, 32)}

type App struct {
	connectMu   sync.Mutex
	mu          sync.RWMutex
	serverIP    string
	reverbHost  string
	reverbPort  int
	proxyOrigin string
	proxyPort   int
	proxyServer *http.Server
	proxyErr    error
}

type preferences struct {
	LastServer  string `json:"lastServer"`
	Remember    bool   `json:"rememberServer"`
	AutoConnect bool   `json:"autoConnect"`
}

type WindowState struct {
	Width      int  `json:"width"`
	Height     int  `json:"height"`
	X          int  `json:"x"`
	Y          int  `json:"y"`
	Fullscreen bool `json:"fullscreen"`
}

type StartupState struct {
	LastServer  string      `json:"lastServer"`
	Remember    bool        `json:"rememberServer"`
	AutoConnect bool        `json:"autoConnect"`
	Window      WindowState `json:"window"`
	Error       string      `json:"error,omitempty"`
}

type ConnectionResult struct {
	Connected bool   `json:"connected"`
	Message   string `json:"message"`
	Technical string `json:"technical,omitempty"`
}

type clientConfig struct {
	WebPort    int    `json:"web_port"`
	ReverbHost string `json:"reverb_host"`
	ReverbPort int    `json:"reverb_port"`
}

type adminPolicy struct {
	AllowPublicIP bool `json:"allowPublicIP"`
}

func NewApp() *App {
	return &App{}
}

func (a *App) Startup(ctx context.Context) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		a.mu.Lock()
		a.proxyErr = err
		a.mu.Unlock()
		return
	}
	port := listener.Addr().(*net.TCPAddr).Port
	a.mu.Lock()
	a.proxyPort = port
	a.proxyServer = &http.Server{
		Handler:           http.HandlerFunc(a.proxyHandler),
		ReadHeaderTimeout: 5 * time.Second,
	}
	server := a.proxyServer
	a.mu.Unlock()
	go func() {
		if err := server.Serve(listener); err != nil && !errors.Is(err, http.ErrServerClosed) {
			a.mu.Lock()
			a.proxyErr = err
			a.mu.Unlock()
		}
	}()
}

func (a *App) Shutdown(context.Context) {
	a.mu.Lock()
	server := a.proxyServer
	a.proxyServer = nil
	a.mu.Unlock()
	if server != nil {
		_ = server.Close()
	}
}

func (a *App) ProxyURL() string {
	a.mu.RLock()
	defer a.mu.RUnlock()
	return a.proxyOrigin
}

func (a *App) InitialState() StartupState {
	state := StartupState{
		Remember: true,
		Window: WindowState{
			Width:      1180,
			Height:     820,
			Fullscreen: true,
		},
	}

	prefs, err := readJSON[preferences](preferencesPath())
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		state.Error = "Client settings could not be read. You can still enter the server IP."
	} else if err == nil {
		state.LastServer = prefs.LastServer
		state.Remember = prefs.Remember
		state.AutoConnect = prefs.AutoConnect
	}

	savedWindow, err := readJSON[WindowState](windowStatePath())
	if err == nil && savedWindow.Width >= 640 && savedWindow.Height >= 480 {
		state.Window = savedWindow
	} else if err != nil && !errors.Is(err, os.ErrNotExist) && state.Error == "" {
		state.Error = "The saved window size could not be read. Default window settings will be used."
	}

	return state
}

func (a *App) SaveWindowState(width, height, x, y int, fullscreen bool) error {
	if width < 100 || height < 100 {
		return nil
	}
	return writeJSON(windowStatePath(), WindowState{
		Width: width, Height: height, X: x, Y: y, Fullscreen: fullscreen,
	})
}

func (a *App) SetAutoConnect(enabled bool) error {
	prefs, err := readJSON[preferences](preferencesPath())
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("read client preferences: %w", err)
	}
	if enabled && strings.TrimSpace(prefs.LastServer) == "" {
		return errors.New("connect to a server and choose Remember this server before enabling automatic connection")
	}
	prefs.AutoConnect = enabled
	return writeJSON(preferencesPath(), prefs)
}

func (a *App) Connect(rawIP string, remember bool) ConnectionResult {
	a.connectMu.Lock()
	defer a.connectMu.Unlock()

	ip, err := normalizeServerIP(rawIP)
	if err != nil {
		return ConnectionResult{Message: err.Error()}
	}
	policy, err := loadAdminPolicy()
	if err != nil {
		return ConnectionResult{
			Message:   "The administrator's network policy could not be read. Ask your administrator to check the client policy.",
			Technical: err.Error(),
		}
	}
	if !isAllowedServerIP(net.ParseIP(ip), policy.AllowPublicIP) {
		return ConnectionResult{
			Message: "This public address is blocked by the client network policy. Ask your administrator to enable public IP access if this server is approved.",
		}
	}
	a.mu.RLock()
	proxyErr := a.proxyErr
	proxyAvailable := a.proxyPort != 0
	a.mu.RUnlock()
	if proxyErr != nil || !proxyAvailable {
		details := "local proxy listener is unavailable"
		if proxyErr != nil {
			details = proxyErr.Error()
		}
		return ConnectionResult{
			Message:   "Vault Client could not start its local connection service. Restart the client or contact your administrator.",
			Technical: details,
		}
	}

	status, err := probeServer(ip)
	if err != nil {
		message := "Could not reach the server at this address. Check the IP and make sure you're on the same network / VPN as the server."
		if isConnectionRefused(err) {
			message = "The server is not running on this address, or the port is blocked. Ask your admin if the server is started."
		}
		return ConnectionResult{Message: message, Technical: err.Error()}
	}
	if status != http.StatusOK {
		return ConnectionResult{
			Message:   "Something else is answering on this address. Check the IP — this doesn't look like a Vault server.",
			Technical: fmt.Sprintf("GET /up returned HTTP %d", status),
		}
	}

	// The endpoint is optional; the typed address remains authoritative for both
	// the web server and Reverb if the route has not been deployed.
	config := fetchClientConfig(ip)

	prefs, err := readJSON[preferences](preferencesPath())
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return ConnectionResult{
			Message:   "The server is available, but this device could not read its saved connection settings.",
			Technical: err.Error(),
		}
	}
	prefs.Remember = remember
	if remember {
		prefs.LastServer = ip
	} else {
		prefs.LastServer = ""
		prefs.AutoConnect = false
	}
	if err := writeJSON(preferencesPath(), prefs); err != nil {
		return ConnectionResult{
			Message:   "The server is available, but this device could not save the connection preference.",
			Technical: err.Error(),
		}
	}

	a.mu.Lock()
	a.serverIP = ip
	a.reverbHost = config.ReverbHost
	a.reverbPort = config.ReverbPort
	a.proxyOrigin = fmt.Sprintf("http://vaultclient-%s.localhost:%d", strings.ReplaceAll(ip, ".", "-"), a.proxyPort)
	a.mu.Unlock()
	return ConnectionResult{Connected: true, Message: "Connected to the Vault server."}
}

func (a *App) Disconnect() error {
	prefs, err := readJSON[preferences](preferencesPath())
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("read client preferences while disconnecting: %w", err)
	}
	prefs.AutoConnect = false
	if err := writeJSON(preferencesPath(), prefs); err != nil {
		return fmt.Errorf("save disconnect preference: %w", err)
	}
	a.mu.Lock()
	a.serverIP = ""
	a.reverbHost = ""
	a.reverbPort = 0
	a.mu.Unlock()
	return nil
}

func (a *App) IsConnected() bool {
	a.mu.RLock()
	defer a.mu.RUnlock()
	return a.serverIP != ""
}

func normalizeServerIP(input string) (string, error) {
	value := strings.TrimSpace(input)
	if value == "" {
		return "", errors.New("Enter the Server IP provided by your administrator.")
	}

	if strings.Contains(value, "://") {
		parsed, err := url.Parse(value)
		if err != nil || (parsed.Scheme != "http" && parsed.Scheme != "https") ||
			parsed.User != nil || parsed.RawQuery != "" || parsed.Fragment != "" ||
			(parsed.Path != "" && parsed.Path != "/") {
			return "", errors.New("Enter a valid IP address, e.g. 192.168.1.40")
		}
		if parsed.Port() != "" && parsed.Port() != strconv.Itoa(webPort) {
			return "", errors.New("Enter a valid IP address, e.g. 192.168.1.40")
		}
		value = parsed.Hostname()
	} else {
		value = strings.TrimSuffix(value, "/")
		value = strings.TrimSuffix(value, ":"+strconv.Itoa(webPort))
	}

	ip := net.ParseIP(value)
	if ip == nil || ip.To4() == nil {
		return "", errors.New("Enter a valid IP address, e.g. 192.168.1.40")
	}
	return ip.To4().String(), nil
}

func isAllowedServerIP(ip net.IP, allowPublic bool) bool {
	if ip == nil {
		return false
	}
	return ip.IsPrivate() || ip.IsLoopback() || ip.IsLinkLocalUnicast() ||
		sharedVPNRange.Contains(ip.To4()) || allowPublic
}

func loadAdminPolicy() (adminPolicy, error) {
	programData := os.Getenv("ProgramData")
	if programData == "" {
		programData = `C:\ProgramData`
	}
	path := filepath.Join(programData, "VaultClient", "policy.json")
	policy, err := readJSON[adminPolicy](path)
	if errors.Is(err, os.ErrNotExist) {
		return adminPolicy{}, nil
	}
	return policy, err
}

func probeServer(ip string) (int, error) {
	client := &http.Client{
		Timeout: probeTimeout,
		Transport: &http.Transport{
			Proxy: nil,
		},
		CheckRedirect: func(*http.Request, []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}
	response, err := client.Get(fmt.Sprintf("http://%s:%d/up", ip, webPort))
	if err != nil {
		return 0, err
	}
	defer response.Body.Close()
	return response.StatusCode, nil
}

func fetchClientConfig(ip string) clientConfig {
	config := clientConfig{WebPort: webPort, ReverbHost: ip, ReverbPort: reverbPort}
	client := &http.Client{
		Timeout: probeTimeout,
		Transport: &http.Transport{
			Proxy: nil,
		},
		CheckRedirect: func(*http.Request, []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}
	response, err := client.Get(fmt.Sprintf("http://%s:%d/client-config", ip, webPort))
	if err != nil {
		return config
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return config
	}
	var serverConfig clientConfig
	if json.NewDecoder(io.LimitReader(response.Body, 4096)).Decode(&serverConfig) == nil &&
		serverConfig.WebPort == webPort &&
		serverConfig.ReverbHost == ip &&
		serverConfig.ReverbPort == reverbPort {
		return serverConfig
	}
	return config
}

func isConnectionRefused(err error) bool {
	return errors.Is(err, syscall.ECONNREFUSED)
}

func (a *App) proxyHandler(w http.ResponseWriter, r *http.Request) {
	a.mu.RLock()
	expectedHost := strings.TrimPrefix(a.proxyOrigin, "http://")
	a.mu.RUnlock()
	if expectedHost == "" || r.Host != expectedHost {
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	if r.URL.Path == "/_client/clear" {
		a.clearProxyCookies(w, r)
		return
	}
	if r.URL.Path == "/_client/session.js" {
		w.Header().Set("Content-Type", "text/javascript; charset=utf-8")
		_, _ = io.WriteString(w, `window.addEventListener("message",function(event){if(event.data==="vault-client-clear-session"){try{localStorage.clear();sessionStorage.clear();}finally{event.source.postMessage("vault-client-session-cleared",event.origin);}}});window.addEventListener("keydown",function(event){if(event.ctrlKey&&event.shiftKey&&event.key.toLowerCase()==="d"){event.preventDefault();window.parent.postMessage("vault-client-disconnect","*");}if(event.key==="F11"){event.preventDefault();window.parent.postMessage("vault-client-toggle-fullscreen","*");}});`)
		return
	}
	if strings.HasPrefix(r.URL.Path, proxyPrefix+"/") || r.URL.Path == proxyPrefix ||
		strings.EqualFold(r.Header.Get("Upgrade"), "websocket") {
		a.proxy(w, r)
		return
	}
	http.NotFound(w, r)
}

func (a *App) clearProxyCookies(w http.ResponseWriter, r *http.Request) {
	origin := r.Header.Get("Origin")
	a.mu.RLock()
	proxyOrigin := a.proxyOrigin
	a.mu.RUnlock()
	expectedHost := strings.TrimPrefix(proxyOrigin, "http://")
	if origin != "" && origin != "http://wails.localhost" && origin != "http://127.0.0.1:5174" && origin != proxyOrigin {
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	if origin == "" && r.Host != expectedHost {
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	if origin != "" {
		w.Header().Set("Access-Control-Allow-Origin", origin)
		w.Header().Set("Access-Control-Allow-Credentials", "true")
		w.Header().Set("Vary", "Origin")
	}
	if r.Method != http.MethodGet && r.Method != http.MethodOptions {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	if r.Method == http.MethodOptions {
		w.Header().Set("Access-Control-Allow-Methods", "GET, OPTIONS")
		w.WriteHeader(http.StatusNoContent)
		return
	}
	for _, cookie := range r.Cookies() {
		http.SetCookie(w, &http.Cookie{
			Name: cookie.Name, Value: "", Path: "/", MaxAge: -1,
			Expires: time.Unix(1, 0), HttpOnly: true, SameSite: http.SameSiteLaxMode,
		})
	}
	w.WriteHeader(http.StatusNoContent)
}

func (a *App) proxy(w http.ResponseWriter, r *http.Request) {
	a.mu.RLock()
	ip := a.serverIP
	a.mu.RUnlock()
	if ip == "" {
		http.Error(w, "Connect to a Vault server first.", http.StatusServiceUnavailable)
		return
	}

	port := webPort
	targetIP := ip
	a.mu.RLock()
	reverbHost := a.reverbHost
	configuredReverbPort := a.reverbPort
	proxyOrigin := a.proxyOrigin
	a.mu.RUnlock()
	if strings.EqualFold(r.Header.Get("Upgrade"), "websocket") {
		if reverbHost != "" {
			targetIP = reverbHost
		}
		port = configuredReverbPort
		if port == 0 {
			port = reverbPort
		}
	}
	target := &url.URL{
		Scheme: "http",
		Host:   net.JoinHostPort(targetIP, strconv.Itoa(port)),
	}
	proxy := httputil.NewSingleHostReverseProxy(target)
	proxy.Transport = &http.Transport{Proxy: nil, ResponseHeaderTimeout: 15 * time.Second}
	proxy.FlushInterval = -1
	proxy.Director = func(out *http.Request) {
		out.URL.Scheme = target.Scheme
		out.URL.Host = target.Host
		out.URL.Path = strings.TrimPrefix(out.URL.Path, proxyPrefix)
		if out.URL.Path == "" {
			out.URL.Path = "/"
		}
		out.Host = target.Host
		out.Header.Del("Accept-Encoding")
		if out.Header.Get("Origin") != "" {
			out.Header.Set("Origin", target.Scheme+"://"+target.Host)
		}
		if referer := out.Header.Get("Referer"); strings.HasPrefix(referer, proxyOrigin+proxyPrefix) {
			out.Header.Set("Referer", target.Scheme+"://"+target.Host+strings.TrimPrefix(referer, proxyOrigin+proxyPrefix))
		}
		out.Header.Set("X-Forwarded-Host", target.Host)
		out.Header.Set("X-Forwarded-Proto", target.Scheme)
	}
	proxy.ModifyResponse = func(response *http.Response) error {
		rewriteCookies(response)
		policy := fmt.Sprintf("connect-src 'self' ws://%s; frame-ancestors http://wails.localhost http://127.0.0.1:5174; navigate-to 'self'", proxyHost(proxyOrigin))
		response.Header.Del("X-Frame-Options")
		rewriteContentSecurityPolicies(response.Header, ip, proxyOrigin)
		response.Header.Add("Content-Security-Policy", policy)
		rewriteLocation(response, ip, proxyOrigin)
		if !isRewritableContent(response.Header.Get("Content-Type")) {
			return nil
		}
		body, err := io.ReadAll(io.LimitReader(response.Body, maxProxyBytes+1))
		if err != nil {
			return fmt.Errorf("read upstream response: %w", err)
		}
		if len(body) > maxProxyBytes {
			return errors.New("upstream response exceeded the client rewrite limit")
		}
		_ = response.Body.Close()
		body = rewriteProxyBody(body, ip, response.Header.Get("Content-Type"), proxyOrigin, proxyPort(proxyOrigin))
		response.Body = io.NopCloser(bytes.NewReader(body))
		response.ContentLength = int64(len(body))
		response.Header.Set("Content-Length", strconv.Itoa(len(body)))
		response.Header.Del("Content-Encoding")
		return nil
	}
	proxy.ErrorHandler = func(w http.ResponseWriter, _ *http.Request, _ error) {
		http.Error(w, "The Vault server connection was interrupted. Return to the connection page and retry.", http.StatusBadGateway)
	}
	proxy.ServeHTTP(w, r)
}

func proxyPort(proxyOrigin string) int {
	parsed, err := url.Parse(proxyOrigin)
	if err != nil {
		return 0
	}
	port, _ := strconv.Atoi(parsed.Port())
	return port
}

func proxyHost(proxyOrigin string) string {
	parsed, err := url.Parse(proxyOrigin)
	if err != nil {
		return ""
	}
	return parsed.Host
}

func rewriteCookies(response *http.Response) {
	cookies := response.Header.Values("Set-Cookie")
	if len(cookies) == 0 {
		return
	}
	response.Header.Del("Set-Cookie")
	domainPattern := regexp.MustCompile(`(?i);\s*domain=[^;]+`)
	for _, cookie := range cookies {
		response.Header.Add("Set-Cookie", domainPattern.ReplaceAllString(cookie, ""))
	}
}

func rewriteContentSecurityPolicies(headers http.Header, ip, proxyOrigin string) {
	policies := headers.Values("Content-Security-Policy")
	headers.Del("Content-Security-Policy")
	webOrigin := proxyOrigin + proxyPrefix
	proxyHost := proxyHost(proxyOrigin)
	webSocketOrigin := "ws://" + proxyHost
	webSocketPattern := regexp.MustCompile(`(?i)wss?://[^;,\s'"]+:8080`)
	for _, policy := range policies {
		cleaned := regexp.MustCompile(`(?i)(?:^|;)\s*frame-ancestors[^;]*`).ReplaceAllString(policy, "")
		for _, origin := range []string{
			fmt.Sprintf("http://%s:%d", ip, webPort),
			fmt.Sprintf("https://%s:%d", ip, webPort),
			"http://localhost:8000",
			"http://127.0.0.1:8000",
		} {
			cleaned = strings.ReplaceAll(cleaned, origin, webOrigin)
		}
		cleaned = webSocketPattern.ReplaceAllString(cleaned, webSocketOrigin)
		cleaned = strings.ReplaceAll(cleaned, ip+":8000", proxyHost)
		if strings.TrimSpace(cleaned) != "" {
			headers.Add("Content-Security-Policy", cleaned)
		}
	}
}

func rewriteLocation(response *http.Response, ip, proxyOrigin string) {
	location := response.Header.Get("Location")
	if location == "" {
		return
	}
	remote := fmt.Sprintf("http://%s:%d", ip, webPort)
	local := proxyOrigin + proxyPrefix
	for _, origin := range []string{
		remote,
		"http://localhost:8000",
		"http://127.0.0.1:8000",
	} {
		if strings.HasPrefix(location, origin) {
			response.Header.Set("Location", strings.ReplaceAll(location, origin, local))
			return
		}
	}
	if strings.HasPrefix(location, "/") && !strings.HasPrefix(location, "//") {
		response.Header.Set("Location", proxyPrefix+location)
		return
	}
	if strings.HasPrefix(location, "//"+ip+":"+strconv.Itoa(webPort)+"/") {
		response.Header.Set("Location", local+strings.TrimPrefix(location, "//"+ip+":"+strconv.Itoa(webPort)))
		return
	}
	response.Header.Set("Location", "#external-navigation-blocked")
}

func isRewritableContent(contentType string) bool {
	return strings.Contains(contentType, "text/html") ||
		strings.Contains(contentType, "javascript") ||
		strings.Contains(contentType, "text/css") ||
		strings.Contains(contentType, "application/json")
}

var (
	rootPathPattern    = regexp.MustCompile("([\"'`])(/[^/])")
	attributePattern   = regexp.MustCompile(`(?i)(href|src|action|formaction|poster)\s*=\s*(["'])([^"']+)(["'])`)
	cssRootPathPattern = regexp.MustCompile(`(?i)url\(/`)
)

func rewriteProxyBody(body []byte, ip, contentType, proxyOrigin string, proxyPort int) []byte {
	value := string(body)
	local := proxyOrigin + proxyPrefix
	localHost := proxyHost(proxyOrigin)
	for _, origin := range []string{
		fmt.Sprintf("http://%s:%d", ip, webPort),
		"http://localhost:8000",
		"http://127.0.0.1:8000",
	} {
		value = strings.ReplaceAll(value, origin, local)
	}
	value = strings.ReplaceAll(value, fmt.Sprintf("//%s:%d", ip, webPort), "//"+strings.TrimPrefix(proxyOrigin, "http://")+proxyPrefix)
	value = strings.ReplaceAll(value, fmt.Sprintf("ws://%s:%d", ip, reverbPort), "ws://"+localHost)
	value = strings.ReplaceAll(value, fmt.Sprintf("wss://%s:%d", ip, reverbPort), "ws://"+localHost)
	value = regexp.MustCompile(`(?i)wss?://[^/:]+:8080`).ReplaceAllString(value, "ws://"+localHost)
	if strings.Contains(contentType, "text/html") || strings.Contains(contentType, "javascript") || strings.Contains(contentType, "text/css") {
		value = regexp.MustCompile(`\b(wss?Port)\s*:\s*8080`).ReplaceAllString(value, "$1:"+strconv.Itoa(proxyPort))
		value = rootPathPattern.ReplaceAllString(value, "$1"+proxyPrefix+"$2")
		if strings.Contains(contentType, "text/css") {
			value = cssRootPathPattern.ReplaceAllString(value, "url("+proxyPrefix+"/")
		}
		value = attributePattern.ReplaceAllStringFunc(value, func(attribute string) string {
			parts := attributePattern.FindStringSubmatch(attribute)
			if len(parts) != 5 {
				return attribute
			}
			destination := parts[3]
			parsed, err := url.Parse(destination)
			if err != nil || parsed.Scheme != "" && parsed.Host != localHost ||
				strings.HasPrefix(destination, "//") && !strings.Contains(destination, localHost) {
				return parts[1] + "=" + parts[2] + "#external-link-blocked" + parts[4]
			}
			return attribute
		})
	}
	if strings.Contains(contentType, "text/html") {
		value = strings.Replace(value, "</body>", `<script src="`+proxyOrigin+`/_client/session.js"></script></body>`, 1)
	}
	return []byte(value)
}

func preferencesPath() string {
	return filepath.Join(configDirectory(), "config.json")
}

func windowStatePath() string {
	return filepath.Join(configDirectory(), "window.json")
}

func configDirectory() string {
	base, err := os.UserConfigDir()
	if err != nil {
		base = os.Getenv("APPDATA")
	}
	return filepath.Join(base, "VaultClient")
}

func readJSON[T any](path string) (T, error) {
	var value T
	data, err := os.ReadFile(path)
	if err != nil {
		return value, err
	}
	if err := json.Unmarshal(data, &value); err != nil {
		return value, err
	}
	return value, nil
}

func writeJSON(path string, value any) error {
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return fmt.Errorf("create client settings directory: %w", err)
	}
	data, err := json.MarshalIndent(value, "", "  ")
	if err != nil {
		return fmt.Errorf("encode client settings: %w", err)
	}
	if err := os.WriteFile(path, data, 0600); err != nil {
		return fmt.Errorf("save client settings: %w", err)
	}
	return nil
}
