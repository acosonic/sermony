#!/usr/bin/env bash
# Sermony PM2 Plugin Agent — collects PM2 process info
# Deployed to: /opt/sermony/plugins/pm2-agent.sh
# Runs after the main agent via cron or called by main agent
set -e

CONFIG="/opt/sermony/config"
[[ -f "$CONFIG" ]] || exit 0
source "$CONFIG"

# Find pm2 binary
PM2_BIN=$(command -v pm2 2>/dev/null || which pm2 2>/dev/null || ls /usr/local/bin/pm2 /usr/bin/pm2 /home/*/.nvm/versions/node/*/bin/pm2 2>/dev/null | head -1)
[[ -z "$PM2_BIN" || ! -x "$PM2_BIN" ]] && exit 0

# Collect PM2 processes from all users
pm2_json="["
first=1

for pm2dir in /home/*/.pm2 /root/.pm2; do
    [[ -d "$pm2dir" ]] || continue
    pm2user=$(basename "$(dirname "$pm2dir")")

    # Use pm2 jlist for structured JSON output
    jlist=$(sudo -u "$pm2user" PM2_HOME="$pm2dir" "$PM2_BIN" jlist 2>/dev/null || echo "[]")

    while IFS='|' read -r pname pstatus pcpu pmem prestart puptime pscript; do
        [[ -z "$pname" ]] && continue
        [[ $first -eq 0 ]] && pm2_json+=","
        pm2_json+="{\"name\":\"$(echo "$pname" | tr -cd '[:alnum:]._-')\",\"user\":\"${pm2user}\",\"status\":\"${pstatus}\",\"cpu\":\"${pcpu}\",\"mem\":\"${pmem}\",\"restarts\":\"${prestart}\",\"uptime\":\"${puptime}\",\"script\":\"${pscript}\"}"
        first=0
    done < <(echo "$jlist" | python3 -c "
import json, sys
try:
    procs = json.load(sys.stdin)
    for p in procs:
        env = p.get('pm2_env', {})
        mon = p.get('monit', {})
        mem_mb = round(mon.get('memory', 0) / 1048576, 1)
        # Calculate uptime
        uptime_ms = env.get('pm_uptime', 0)
        if uptime_ms:
            import time
            secs = int(time.time() * 1000 - uptime_ms) // 1000
            if secs < 3600: up = f'{secs // 60}m'
            elif secs < 86400: up = f'{secs // 3600}h {(secs % 3600) // 60}m'
            else: up = f'{secs // 86400}d {(secs % 86400) // 3600}h'
        else: up = ''
        print(f\"{p.get('name','')}|{env.get('status','unknown')}|{mon.get('cpu',0)}|{mem_mb}MB|{env.get('restart_time',0)}|{up}|{env.get('pm_exec_path','')}\")
except: pass
" 2>/dev/null)
done

pm2_json+="]"

# Send to server
curl -sf --max-time 10 -X POST \
    -H "Content-Type: application/json" \
    -d "{\"agent_key\":\"${AGENT_KEY}\",\"plugin\":\"pm2\",\"data\":${pm2_json}}" \
    "${SERVER_URL}/?action=plugin-data" >/dev/null 2>&1
