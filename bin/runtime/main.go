package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"
)

type Worker struct {
	ID        int
	Port      int
	Cmd       *exec.Cmd
	URL       *url.URL
	Proxy     *httputil.ReverseProxy
	Active    int64
	Mu        sync.Mutex
	IsRunning bool
}

type Cluster struct {
	Host        string
	Port        int
	Workers     []*Worker
	BasePath    string
	PublicDir   string
	PhpBin      string
	Driver      string
	MaxRequests int
	Counter     uint64
	Mu          sync.RWMutex
	Transport   *http.Transport
}

func main() {
	var (
		hostFlag        = flag.String("host", "127.0.0.1", "Host address to listen on")
		portFlag        = flag.Int("port", 8000, "Port to listen on")
		workersFlag     = flag.String("workers", "auto", "Worker count (e.g. 4, auto, auto*2)")
		driverFlag      = flag.String("driver", "native", "Internal PHP worker driver (native, revolt)")
		maxRequestsFlag = flag.Int("max-requests", 100000, "Max requests before worker memory recycle")
		basePathFlag    = flag.String("base-path", "", "Base path of the Kabuto application")
		phpBinFlag      = flag.String("php-bin", "php", "Path to PHP binary")
	)
	flag.Parse()

	basePath := *basePathFlag
	if basePath == "" {
		cwd, err := os.Getwd()
		if err != nil {
			log.Fatalf("Failed to get working directory: %v", err)
		}
		// If running from framework/bin or framework, normalize
		if strings.HasSuffix(cwd, "bin") || strings.HasSuffix(cwd, "runtime") {
			basePath = filepath.Dir(cwd)
			if strings.HasSuffix(basePath, "bin") {
				basePath = filepath.Dir(basePath)
			}
		} else {
			basePath = cwd
		}
	}

	publicDir := filepath.Join(basePath, "public")

	workerCount := resolveWorkerCount(*workersFlag)

	cluster := &Cluster{
		Host:        *hostFlag,
		Port:        *portFlag,
		BasePath:    basePath,
		PublicDir:   publicDir,
		PhpBin:      *phpBinFlag,
		Driver:      *driverFlag,
		MaxRequests: *maxRequestsFlag,
		Transport: &http.Transport{
			Proxy: http.ProxyFromEnvironment,
			DialContext: (&net.Dialer{
				Timeout:   5 * time.Second,
				KeepAlive: 30 * time.Second,
				Control: func(network, address string, c syscall.RawConn) error {
					return nil
				},
			}).DialContext,
			MaxIdleConns:        10000,
			MaxIdleConnsPerHost: 2000,
			IdleConnTimeout:     90 * time.Second,
			DisableCompression:  true,
		},
	}

	printBanner(cluster.Host, cluster.Port, workerCount, cluster.Driver, cluster.MaxRequests)

	// Spawn persistent PHP workers
	cluster.startWorkers(workerCount)

	// Setup HTTP server
	addr := fmt.Sprintf("%s:%d", cluster.Host, cluster.Port)
	server := &http.Server{
		Addr:         addr,
		Handler:      cluster,
		ReadTimeout:  60 * time.Second,
		WriteTimeout: 60 * time.Second,
		IdleTimeout:  120 * time.Second,
	}

	// Graceful shutdown listener
	stopChan := make(chan os.Signal, 1)
	signal.Notify(stopChan, os.Interrupt, syscall.SIGTERM)

	go func() {
		<-stopChan
		fmt.Println("\n\033[1;33m[!] Shutting down Kabuto Go Runtime...\033[0m")
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = server.Shutdown(ctx)
		cluster.stopWorkers()
		os.Exit(0)
	}()

	listener, err := net.Listen("tcp", addr)
	if err != nil {
		log.Fatalf("Failed to bind on %s: %v", addr, err)
	}

	fmt.Printf("  \033[1;32m✓\033[0m Server ready and accepting connections on \033[4;34mhttp://%s\033[0m\n\n", addr)
	if err := server.Serve(listener); err != nil && err != http.ErrServerClosed {
		log.Fatalf("Server error: %v", err)
	}
}

func (c *Cluster) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	// 1. Static file fast path from public/
	if (r.Method == http.MethodGet || r.Method == http.MethodHead) && c.serveStatic(w, r) {
		return
	}

	// 2. Select next PHP worker (Round-robin with least-busy preference)
	worker := c.nextWorker()
	if worker == nil {
		http.Error(w, `{"statusCode":503,"error":"Service Unavailable","message":"No available Kabuto PHP workers"}`, http.StatusServiceUnavailable)
		return
	}

	atomic.AddInt64(&worker.Active, 1)
	defer atomic.AddInt64(&worker.Active, -1)

	// Add standard proxy headers
	if clientIP, _, err := net.SplitHostPort(r.RemoteAddr); err == nil {
		r.Header.Set("X-Forwarded-For", clientIP)
		r.Header.Set("X-Real-IP", clientIP)
	}
	r.Header.Set("X-Forwarded-Proto", "http")

	worker.Proxy.ServeHTTP(w, r)
}

func (c *Cluster) serveStatic(w http.ResponseWriter, r *http.Request) bool {
	cleanPath := filepath.Clean(r.URL.Path)
	if strings.Contains(cleanPath, "..") {
		return false
	}

	target := filepath.Join(c.PublicDir, cleanPath)
	info, err := os.Stat(target)
	if err != nil || info.IsDir() {
		return false
	}

	w.Header().Set("Server", "Kabuto-GoRuntime/1.0")
	http.ServeFile(w, r, target)
	return true
}

func (c *Cluster) nextWorker() *Worker {
	c.Mu.RLock()
	defer c.Mu.RUnlock()

	total := len(c.Workers)
	if total == 0 {
		return nil
	}

	idx := atomic.AddUint64(&c.Counter, 1) % uint64(total)
	return c.Workers[idx]
}

func (c *Cluster) startWorkers(count int) {
	c.Mu.Lock()
	defer c.Mu.Unlock()

	for i := 0; i < count; i++ {
		port := findFreePort()
		w := &Worker{
			ID:   i + 1,
			Port: port,
		}
		c.spawn(w)
		c.Workers = append(c.Workers, w)
	}
}

func (c *Cluster) spawn(w *Worker) {
	kabutoScript := filepath.Join(c.BasePath, "kabuto")
	args := []string{
		kabutoScript,
		"serve",
		"--internal-worker",
		fmt.Sprintf("--worker-id=%d", w.ID),
		fmt.Sprintf("--host=%s", "127.0.0.1"),
		fmt.Sprintf("--port=%d", w.Port),
		fmt.Sprintf("--driver=%s", c.Driver),
		fmt.Sprintf("--max-requests=%d", c.MaxRequests),
	}

	cmd := exec.Command(c.PhpBin, args...)
	cmd.Dir = c.BasePath
	cmd.Stdout = nil
	cmd.Stderr = os.Stderr

	if err := cmd.Start(); err != nil {
		log.Printf("Worker #%d failed to start: %v", w.ID, err)
		return
	}

	w.Cmd = cmd
	w.IsRunning = true

	targetURL, _ := url.Parse(fmt.Sprintf("http://127.0.0.1:%d", w.Port))
	proxy := httputil.NewSingleHostReverseProxy(targetURL)
	proxy.Transport = c.Transport
	proxy.ErrorHandler = func(rw http.ResponseWriter, req *http.Request, err error) {
		rw.Header().Set("Content-Type", "application/json")
		rw.WriteHeader(http.StatusBadGateway)
		_, _ = rw.Write([]byte(fmt.Sprintf(`{"statusCode":502,"error":"Bad Gateway","message":"PHP worker #%d communication error: %v"}`, w.ID, err)))
	}
	w.Proxy = proxy
	w.URL = targetURL

	// Wait for worker port to become responsive
	waitForPort(w.Port, 3*time.Second)

	// Background monitor & auto-revival
	go func(worker *Worker) {
		_ = worker.Cmd.Wait()
		worker.Mu.Lock()
		worker.IsRunning = false
		worker.Mu.Unlock()

		// If cluster is still running, respawn the worker
		time.Sleep(100 * time.Millisecond)
		worker.Mu.Lock()
		c.spawn(worker)
		worker.Mu.Unlock()
	}(w)
}

func (c *Cluster) stopWorkers() {
	c.Mu.Lock()
	defer c.Mu.Unlock()

	for _, w := range c.Workers {
		if w.Cmd != nil && w.Cmd.Process != nil {
			_ = w.Cmd.Process.Kill()
		}
	}
}

func findFreePort() int {
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return 8081
	}
	defer l.Close()
	return l.Addr().(*net.TCPAddr).Port
}

func waitForPort(port int, timeout time.Duration) bool {
	deadline := time.Now().Add(timeout)
	addr := fmt.Sprintf("127.0.0.1:%d", port)
	for time.Now().Before(deadline) {
		conn, err := net.DialTimeout("tcp", addr, 100*time.Millisecond)
		if err == nil {
			conn.Close()
			return true
		}
		time.Sleep(50 * time.Millisecond)
	}
	return false
}

func resolveWorkerCount(val string) int {
	val = strings.ToLower(strings.TrimSpace(val))
	cores := runtime.NumCPU()

	if val == "" || val == "auto" {
		return cores
	}

	if strings.HasPrefix(val, "auto*") || strings.HasPrefix(val, "auto:") {
		multiplierStr := strings.TrimPrefix(strings.TrimPrefix(val, "auto*"), "auto:")
		if mult, err := strconv.Atoi(multiplierStr); err == nil && mult > 0 {
			return cores * mult
		}
	}

	if n, err := strconv.Atoi(val); err == nil && n > 0 {
		return n
	}

	return cores
}

func printBanner(host string, port int, workers int, driver string, maxRequests int) {
	fmt.Println()
	fmt.Println("\033[1;36m  _  __     _           _        \033[0m")
	fmt.Println("\033[1;36m | |/ /    | |         | |       \033[0m")
	fmt.Println("\033[1;36m | ' / __ _| |__  _   _| |_ ___  \033[0m")
	fmt.Println("\033[1;36m |  < / _` | '_ \\| | | | __/ _ \\ \033[0m")
	fmt.Println("\033[1;36m | . \\ (_| | |_) | |_| | || (_) |\033[0m")
	fmt.Println("\033[1;36m |_|\\_\\__,_|_.__/ \\__,_|\\__\\___/ \033[0m")
	fmt.Println()
	fmt.Println("  \033[1;32m⚡ Kabuto Go Runtime Server & Supervisor (net/http Engine)\033[0m")
	fmt.Println("  ─────────────────────────────────────────────────────────────")
	fmt.Printf("  \033[1m• Server URL  :\033[0m \033[4;34mhttp://%s:%d\033[0m\n", host, port)
	fmt.Printf("  \033[1m• Worker Pool :\033[0m %d persistent PHP workers (%s driver)\n", workers, driver)
	fmt.Printf("  \033[1m• Concurrency :\033[0m Go Non-blocking I/O + Goroutine Multiplexer\n")
	if maxRequests > 0 {
		fmt.Printf("  \033[1m• Max Requests:\033[0m %s (Auto worker recycling)\n", strconv.Itoa(maxRequests))
	} else {
		fmt.Printf("  \033[1m• Max Requests:\033[0m Unlimited\n")
	}
	fmt.Println("  ─────────────────────────────────────────────────────────────")
	fmt.Println("  Press \033[1mCtrl+C\033[0m to stop the server.")
	fmt.Println()
}
