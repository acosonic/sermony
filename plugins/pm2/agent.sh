#!/usr/bin/env bash
# Sermony PM2 Plugin Agent — collects PM2 process info from all users
set -e

CONFIG="/opt/sermony/config"
[[ -f "$CONFIG" ]] || exit 0
source "$CONFIG"

# Collect PM2 processes from all users who have .pm2 directories
pm2_json="["
first=1

for pm2dir in /home/*/.pm2 /root/.pm2; do
    [[ -d "$pm2dir" ]] || continue
    pm2user=$(basename "$(dirname "$pm2dir")")
    pm2home=$(dirname "$pm2dir")

    # Find pm2 binary for this user (nvm paths vary per user)
    pm2bin=""
    for candidate in \
        "${pm2home}/.nvm/versions/node"/*/bin/pm2 \
        /usr/local/bin/pm2 \
        /usr/bin/pm2 \
        /snap/bin/pm2; do
        [[ -x "$candidate" ]] && pm2bin="$candidate" && break
    done
    [[ -z "$pm2bin" ]] && continue

    nodedir=$(dirname "$pm2bin")

    # Try jlist first (works when CLI and daemon versions match)
    raw_jlist=$(sudo -u "$pm2user" env HOME="$pm2home" PM2_HOME="$pm2dir" PATH="${nodedir}:/usr/local/bin:/usr/bin:/bin" "$pm2bin" jlist 2>/dev/null || echo "[]")
    # Strip any warning text before the JSON array — find first [
    jlist=$(echo "$raw_jlist" | sed -n '/^\[/,$p')
    [[ -z "$jlist" ]] && jlist="[]"

    # If jlist is empty, fall back to parsing pm2 list table output
    if [[ "$jlist" == "[]" ]]; then
        # Parse the table output from pm2 list
        listout=$(sudo -u "$pm2user" env HOME="$pm2home" PM2_HOME="$pm2dir" PATH="${nodedir}:/usr/local/bin:/usr/bin:/bin" "$pm2bin" list --no-color 2>/dev/null || echo "")

        if [[ -n "$listout" ]]; then
            while IFS= read -r line; do
                # Parse table rows: │ id │ name │ ns │ ver │ mode │ pid │ uptime │ restarts │ status │ cpu │ mem │ user │ watching │
                pname=$(echo "$line" | awk -F'│' '{gsub(/^[ \t]+|[ \t]+$/,"",$3); print $3}')
                pstatus=$(echo "$line" | awk -F'│' '{gsub(/^[ \t]+|[ \t]+$/,"",$10); print $10}')
                pcpu=$(echo "$line" | awk -F'│' '{gsub(/^[ \t]+|[ \t]+$/,"",$11); gsub(/%/,""); print $11}')
                pmem=$(echo "$line" | awk -F'│' '{gsub(/^[ \t]+|[ \t]+$/,"",$12); print $12}')
                prestart=$(echo "$line" | awk -F'│' '{gsub(/^[ \t]+|[ \t]+$/,"",$9); print $9}')
                puptime=$(echo "$line" | awk -F'│' '{gsub(/^[ \t]+|[ \t]+$/,"",$8); print $8}')

                [[ -z "$pname" || "$pname" == "name" ]] && continue

                # Sanitize
                pname=$(echo "$pname" | tr -cd '[:alnum:]. _-')

                [[ $first -eq 0 ]] && pm2_json+=","
                pm2_json+="{\"name\":\"${pname}\",\"user\":\"${pm2user}\",\"status\":\"${pstatus}\",\"cpu\":\"${pcpu}\",\"mem\":\"${pmem}\",\"restarts\":\"${prestart}\",\"uptime\":\"${puptime}\",\"script\":\"\"}"
                first=0
            done < <(echo "$listout" | grep -E "online|stopped|errored")
        fi
        continue
    fi

    # Parse jlist JSON output
    while IFS='|' read -r pname pstatus pcpu pmem prestart puptime pscript; do
        [[ -z "$pname" ]] && continue
        pname=$(echo "$pname" | tr -cd '[:alnum:]. _-')
        pscript=$(echo "$pscript" | tr -cd '[:alnum:]._/: -')
        [[ $first -eq 0 ]] && pm2_json+=","
        pm2_json+="{\"name\":\"${pname}\",\"user\":\"${pm2user}\",\"status\":\"${pstatus}\",\"cpu\":\"${pcpu}\",\"mem\":\"${pmem}\",\"restarts\":\"${prestart}\",\"uptime\":\"${puptime}\",\"script\":\"${pscript}\"}"
        first=0
    done < <(echo "$jlist" | python3 -c "
import json, sys
try:
    procs = json.load(sys.stdin)
    for p in procs:
        env = p.get('pm2_env', {})
        mon = p.get('monit', {})
        mem_mb = round(mon.get('memory', 0) / 1048576, 1)
        uptime_ms = env.get('pm_uptime', 0)
        if uptime_ms:
            import time
            secs = max(0, int(time.time() * 1000 - uptime_ms) // 1000)
            if secs < 3600: up = f'{secs // 60}m'
            elif secs < 86400: up = f'{secs // 3600}h {(secs % 3600) // 60}m'
            else: up = f'{secs // 86400}d {(secs % 86400) // 3600}h'
        else: up = ''
        print(f\"{p.get('name','?')}|{env.get('status','unknown')}|{mon.get('cpu',0)}|{mem_mb}MB|{env.get('restart_time',0)}|{up}|{env.get('pm_exec_path','')}\")
except: pass
" 2>/dev/null)
done

pm2_json+="]"

# Only send if we found any processes
if [[ "$pm2_json" != "[]" ]]; then
    curl -sf --max-time 10 -X POST \
        -H "Content-Type: application/json" \
        -d "{\"agent_key\":\"${AGENT_KEY}\",\"plugin\":\"pm2\",\"data\":${pm2_json}}" \
        "${SERVER_URL}/?action=plugin-data" >/dev/null 2>&1
fi
