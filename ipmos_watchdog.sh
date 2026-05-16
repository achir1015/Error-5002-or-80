#!/bin/bash
# ============================================================
# ipmos_watchdog.sh — 自動守護腳本
# 每 30 秒檢查一次 Flask + ngrok，斷了就自動重啟
#
# 使用方式：
#   chmod +x ipmos_watchdog.sh
#   nohup bash ipmos_watchdog.sh > /volume3/web/ytmp3/watchdog.log 2>&1 &
#
# 停止守護：
#   pkill -f ipmos_watchdog.sh
# ============================================================

# ── 設定區（依需求修改）────────────────────────────────────
FLASK_DIR="/volume3/web/ytmp3"       # Flask 專案目錄
FLASK_APP="app.py"                   # Flask 啟動腳本
FLASK_PORT=5002                      # Flask 監聽 port
FLASK_LOG="$FLASK_DIR/flask.log"     # Flask 日誌

NGROK_LOG="$FLASK_DIR/ngrok.log"     # ngrok 日誌

CHECK_INTERVAL=30                    # 每幾秒檢查一次
# ────────────────────────────────────────────────────────────

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

# ── 檢查並修復 Flask ────────────────────────────────────────
check_flask() {
    # 用 netstat 確認 port 5002 是否在監聽
    if netstat -tlnp 2>/dev/null | grep -q ":${FLASK_PORT}"; then
        return 0  # 正常，不做任何事
    fi

    log "⚠️  Flask 未運行，正在重啟..."

    # 殺掉殘留的 Flask 程序（以防僵屍進程）
    pkill -f "$FLASK_APP" 2>/dev/null
    sleep 2

    # 重新啟動 Flask
    cd "$FLASK_DIR"
    nohup python3 "$FLASK_APP" >> "$FLASK_LOG" 2>&1 &

    sleep 5  # 等待 Flask 啟動

    # 確認有沒有起來
    if netstat -tlnp 2>/dev/null | grep -q ":${FLASK_PORT}"; then
        log "✅ Flask 重啟成功（port ${FLASK_PORT}）"
    else
        log "❌ Flask 重啟失敗，請手動檢查 $FLASK_LOG"
    fi
}

# ── 檢查並修復 ngrok ────────────────────────────────────────
check_ngrok() {
    if pgrep -x "ngrok" > /dev/null; then
        return 0  # 正常，不做任何事
    fi

    log "⚠️  ngrok 未運行，正在重啟..."

    # 重新啟動 ngrok（啟動 yml 裡所有 tunnel）
    nohup ngrok start --all >> "$NGROK_LOG" 2>&1 &

    sleep 5

    if pgrep -x "ngrok" > /dev/null; then
        log "✅ ngrok 重啟成功"
    else
        log "❌ ngrok 重啟失敗，請手動檢查 $NGROK_LOG"
    fi
}

# ── 主迴圈 ──────────────────────────────────────────────────
log "🚀 ipmos watchdog 啟動（每 ${CHECK_INTERVAL} 秒檢查一次）"

while true; do
    check_flask
    check_ngrok
    sleep "$CHECK_INTERVAL"
done
