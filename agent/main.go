package main

import (
	"bytes"
	"crypto/rand"
	"crypto/tls"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	mrand "math/rand"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"
)

// SuniPIP DNS 容灾 Agent
//
// 部署在中国大陆 VPS 上，定时访问 admin.sunipip.uk 拉取探测任务，
// 对 xui 面板上的 vless 节点做 TLS 握手探测。
// 连续失败会触发后端自动切换 DNS 到备机。
//
// 使用：
//   export SUNIPIP_AGENT_KEY=xxx
//   export SUNIPIP_ADMIN_URL=https://admin.sunipip.uk
//   ./sunipip-agent
//
// 或命令行参数：
//   ./sunipip-agent -key xxx -url https://admin.sunipip.uk -interval 20m

var (
	adminURL    = flag.String("url", getenv("SUNIPIP_ADMIN_URL", "https://admin.sunipip.uk"), "Admin panel URL")
	agentKey    = flag.String("key", getenv("SUNIPIP_AGENT_KEY", ""), "Agent key (from admin panel)")
	interval    = flag.Duration("interval", parseDuration(getenv("SUNIPIP_INTERVAL", "20m")), "Heartbeat interval")
	probeJitter = flag.Int("jitter", 60, "Random jitter (seconds) to avoid sync spikes")
	httpTimeout = flag.Duration("http-timeout", 20*time.Second, "HTTP request timeout")
	verbose     = flag.Bool("v", false, "Verbose logging")
)

// ===== Data types =====

type Target struct {
	ID             int    `json:"id"`
	Host           string `json:"host"`
	Port           int    `json:"port"`
	TimeoutSeconds int    `json:"timeout_seconds"`
	VlessURL       string `json:"vless_url"`
}

type HeartbeatResponse struct {
	Success bool `json:"success"`
	Message string `json:"message"`
	Data    struct {
		AgentID    int      `json:"agent_id"`
		ServerTime string   `json:"server_time"`
		Targets    []Target `json:"targets"`
	} `json:"data"`
}

type ProbeResult struct {
	TargetID     int    `json:"target_id"`
	Success      bool   `json:"success"`
	LatencyMs    *int   `json:"latency_ms"`
	ErrorMessage string `json:"error_message,omitempty"`
}

type ReportRequest struct {
	Results []ProbeResult `json:"results"`
}

// ===== Helpers =====

func getenv(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func parseDuration(s string) time.Duration {
	if d, err := time.ParseDuration(s); err == nil {
		return d
	}
	return 20 * time.Minute
}

func logInfo(format string, args ...any) {
	log.Printf("[INFO] "+format, args...)
}

func logDebug(format string, args ...any) {
	if *verbose {
		log.Printf("[DEBUG] "+format, args...)
	}
}

func logError(format string, args ...any) {
	log.Printf("[ERROR] "+format, args...)
}

// ===== HTTP client =====

var httpClient = &http.Client{
	Timeout: 20 * time.Second,
	Transport: &http.Transport{
		TLSClientConfig: &tls.Config{InsecureSkipVerify: false},
	},
}

func doJSON(method, path string, body any, out any) error {
	var bodyReader io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return err
		}
		bodyReader = bytes.NewReader(b)
	}

	url := strings.TrimRight(*adminURL, "/") + "/api/v1" + path
	req, err := http.NewRequest(method, url, bodyReader)
	if err != nil {
		return err
	}
	req.Header.Set("X-Agent-Key", *agentKey)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "sunipip-agent/1.0")

	resp, err := httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("http request: %w", err)
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(resp.Body)
	if resp.StatusCode >= 400 {
		return fmt.Errorf("http %d: %s", resp.StatusCode, string(raw))
	}

	if out != nil {
		if err := json.Unmarshal(raw, out); err != nil {
			return fmt.Errorf("json decode: %w (body: %s)", err, string(raw))
		}
	}
	return nil
}

// ===== Probe implementation =====

// probeTarget 尝试探测一个 vless+reality 节点的可达性。
//
// 策略（由简到复杂，任一成功即视为可达）：
//  1. DNS 解析 host
//  2. TCP 连接 host:port（timeout秒内）
//  3. 发起 TLS ClientHello 握手（SNI 用 www.intel.com，reality 标准伪装域名）
//
// 中国大陆的 GFW 对 TCP 层 RST 和 TLS ClientHello 层劫持两种都能触发。
// TCP 连得上 + TLS 能发出去至少 1 byte 响应 = 通畅。
//
// 返回：latency_ms, error_message
func probeTarget(t Target) ProbeResult {
	result := ProbeResult{TargetID: t.ID}
	timeout := time.Duration(t.TimeoutSeconds) * time.Second
	if timeout <= 0 {
		timeout = 8 * time.Second
	}

	start := time.Now()

	// Step 1: DNS + TCP dial
	addr := net.JoinHostPort(t.Host, fmt.Sprintf("%d", t.Port))
	dialer := net.Dialer{Timeout: timeout}
	conn, err := dialer.Dial("tcp", addr)
	if err != nil {
		result.ErrorMessage = "TCP dial failed: " + err.Error()
		return result
	}
	defer conn.Close()

	// Step 2: TLS ClientHello (SNI = www.intel.com, 与 reality 伪装域名一致)
	tlsConfig := &tls.Config{
		ServerName:         "www.intel.com",
		InsecureSkipVerify: true, // reality 不用真证书
		MinVersion:         tls.VersionTLS12,
	}
	conn.SetDeadline(time.Now().Add(timeout))

	tlsConn := tls.Client(conn, tlsConfig)
	err = tlsConn.Handshake()

	// reality 的设计：握手不会真正完成（握手失败是正常的），
	// 但如果 GFW 拦截，会在 ClientHello 后直接 RST，表现为 "connection reset by peer"
	// 如果只是证书校验失败 / 握手超时但不 reset，说明网络通畅
	latency := int(time.Since(start).Milliseconds())
	result.LatencyMs = &latency

	if err == nil {
		// 正常来说 reality 不会走到这里，但万一面板改成真证书就过了
		result.Success = true
		return result
	}

	errStr := err.Error()
	// GFW 常见特征
	if strings.Contains(errStr, "reset by peer") ||
		strings.Contains(errStr, "connection reset") ||
		strings.Contains(errStr, "forcibly closed") {
		result.Success = false
		result.ErrorMessage = "TLS RST (可能被墙): " + errStr
		return result
	}
	// 握手超时也算异常
	if strings.Contains(errStr, "timeout") || strings.Contains(errStr, "deadline exceeded") {
		result.Success = false
		result.ErrorMessage = "TLS handshake timeout: " + errStr
		return result
	}

	// 其他错误（比如 "bad certificate"、"protocol version mismatch"）都算网络通畅
	// 因为只要能收到对方的 ServerHello，说明 TCP 链路没被劫持
	result.Success = true
	return result
}

// ===== Main loop =====

func run() {
	logInfo("sunipip-agent started, admin=%s, interval=%s", *adminURL, *interval)

	// Initial delay (random) to avoid thundering herd after restart
	mrand.New(mrand.NewSource(time.Now().UnixNano()))

	for {
		tick(*interval)
	}
}

func tick(d time.Duration) {
	// 主循环：一次心跳 + 任务处理 + 睡眠
	runOnce()

	// 加随机 jitter 避免固定周期
	jitterSec := 0
	if *probeJitter > 0 {
		buf := make([]byte, 4)
		rand.Read(buf)
		jitterSec = int(buf[0]) % (*probeJitter*2 + 1)
		jitterSec -= *probeJitter
	}

	sleep := d + time.Duration(jitterSec)*time.Second
	if sleep < time.Second {
		sleep = time.Second
	}
	logDebug("sleeping for %s", sleep)
	time.Sleep(sleep)
}

func runOnce() {
	var hbResp HeartbeatResponse
	if err := doJSON("POST", "/agent/heartbeat", nil, &hbResp); err != nil {
		logError("heartbeat failed: %v", err)
		return
	}

	if !hbResp.Success {
		logError("heartbeat api error: %s", hbResp.Message)
		return
	}

	targets := hbResp.Data.Targets
	logInfo("heartbeat ok (agent_id=%d), got %d target(s)", hbResp.Data.AgentID, len(targets))

	if len(targets) == 0 {
		return
	}

	// 对每个目标做探测
	results := make([]ProbeResult, 0, len(targets))
	for _, t := range targets {
		logDebug("probing target #%d %s:%d ...", t.ID, t.Host, t.Port)
		r := probeTarget(t)
		if r.Success {
			lat := 0
			if r.LatencyMs != nil {
				lat = *r.LatencyMs
			}
			logInfo("  ✓ target #%d %s:%d OK (%dms)", t.ID, t.Host, t.Port, lat)
		} else {
			logInfo("  ✗ target #%d %s:%d FAIL: %s", t.ID, t.Host, t.Port, r.ErrorMessage)
		}
		results = append(results, r)
	}

	// 上报结果
	if err := doJSON("POST", "/agent/report", ReportRequest{Results: results}, nil); err != nil {
		logError("report failed: %v", err)
		return
	}
	logInfo("report ok (%d results)", len(results))
}

// Avoid unused import
var _ = url.Parse

func main() {
	flag.Parse()
	if *agentKey == "" {
		log.Fatal("missing agent key (SUNIPIP_AGENT_KEY or -key)")
	}
	if *adminURL == "" {
		log.Fatal("missing admin URL (SUNIPIP_ADMIN_URL or -url)")
	}
	httpClient.Timeout = *httpTimeout

	// Graceful shutdown
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		<-sigCh
		logInfo("shutting down")
		os.Exit(0)
	}()

	run()
}
