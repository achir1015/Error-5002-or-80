# Error-5002-or-80
<img width="1568" height="672" alt="image" src="https://github.com/user-attachments/assets/5777b9fd-d72d-46ca-819b-1781db176380" />
<img width="1568" height="620" alt="image" src="https://github.com/user-attachments/assets/4b0b4107-048a-4f93-9ac7-889e41ac61da" />

背景守護腳本，自動偵測並修復 Flask + ngrok 斷線問題。
使用方式
1. 上傳到 NAS 後，給執行權限並啟動：
bashchmod +x /volume3/web/ytmp3/ipmos_watchdog.sh
nohup bash /volume3/web/ytmp3/ipmos_watchdog.sh > /volume3/web/ytmp3/watchdog.log 2>&1 &
2. 確認守護程式在跑：
bashpgrep -a -f ipmos_watchdog
3. 查看守護日誌：
bashtail -f /volume3/web/ytmp3/watchdog.log

設定開機自動啟動（讓 NAS 重開機也自動恢復）
Synology 控制台 → 工作排程 → 新增 → 開機時執行：
bashnohup bash /volume3/web/ytmp3/ipmos_watchdog.sh > /volume3/web/ytmp3/watchdog.log 2>&1 &

守護邏輯
每 30 秒
  ├── 檢查 port 5002 有沒有在聽
  │     └── 沒有 → 自動重啟 Flask
  └── 檢查 ngrok 程序有沒有在跑
        └── 沒有 → 自動重啟 ngrok
