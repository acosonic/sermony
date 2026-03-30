#!/usr/bin/env bash
# Sermony Agent — collects server metrics and sends to monitoring server
set -e

CONFIG="/opt/sermony/config"
[[ -f "$CONFIG" ]] || { echo "sermony: config not found: $CONFIG" >&2; exit 1; }
source "$CONFIG"

# ─── Identifiers ─────────────────────────────────────────────

HOST=$(hostname | tr -cd '[:alnum:]._-')
FQDN=$(hostname -f 2>/dev/null || echo "")
PUBLIC_IP=$(curl -s --max-time 5 https://ifconfig.me 2>/dev/null \
         || curl -s --max-time 5 https://api.ipify.org 2>/dev/null \
         || echo "")

# ─── Snapshot before (CPU, network, disk I/O) ────────────────

cpu_b=($(awk '/^cpu / {print $2+$3+$4+$5+$6+$7+$8, $5}' /proc/stat))

net_b=($(awk 'NR>2 && $1!~/lo:/ {rx+=$2; tx+=$10} END {print rx+0, tx+0}' /proc/net/dev))

disk_rd_b=0; disk_wr_b=0
for s in /sys/block/*/stat; do
    d=$(basename "$(dirname "$s")")
    case "$d" in loop*|ram*) continue ;; esac
    read -r rd _ _ _ wr _ < "$s" 2>/dev/null || continue
    disk_rd_b=$((disk_rd_b + rd)); disk_wr_b=$((disk_wr_b + wr))
done

sleep 1

# ─── Snapshot after ───────────────────────────────────────────

cpu_a=($(awk '/^cpu / {print $2+$3+$4+$5+$6+$7+$8, $5}' /proc/stat))

net_a=($(awk 'NR>2 && $1!~/lo:/ {rx+=$2; tx+=$10} END {print rx+0, tx+0}' /proc/net/dev))

disk_rd_a=0; disk_wr_a=0
for s in /sys/block/*/stat; do
    d=$(basename "$(dirname "$s")")
    case "$d" in loop*|ram*) continue ;; esac
    read -r rd _ _ _ wr _ < "$s" 2>/dev/null || continue
    disk_rd_a=$((disk_rd_a + rd)); disk_wr_a=$((disk_wr_a + wr))
done

# ─── Calculate rates ─────────────────────────────────────────

cpu_total=$(( cpu_a[0] - cpu_b[0] ))
cpu_idle=$(( cpu_a[1] - cpu_b[1] ))
cpu_pct="0.0"
(( cpu_total > 0 )) && cpu_pct=$(awk "BEGIN {printf \"%.1f\", (1-$cpu_idle/$cpu_total)*100}")

net_rx=$(( net_a[0] - net_b[0] ))
net_tx=$(( net_a[1] - net_b[1] ))
# Clamp negatives (counter reset)
(( net_rx < 0 )) && net_rx=0
(( net_tx < 0 )) && net_tx=0

disk_iops=$(( (disk_rd_a - disk_rd_b) + (disk_wr_a - disk_wr_b) ))
(( disk_iops < 0 )) && disk_iops=0

# ─── Memory ──────────────────────────────────────────────────

mem_total=$(awk '/^MemTotal:/ {print $2}' /proc/meminfo)
mem_avail=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)
mem_total_mb=$((mem_total / 1024))
mem_pct=$(awk "BEGIN {printf \"%.1f\", (1-${mem_avail:-0}/${mem_total:-1})*100}")

# ─── Disk usage (root partition) ─────────────────────────────

disk_pct=$(df / 2>/dev/null | awk 'NR==2 {gsub(/%/,""); print $5+0}')
disk_pct=${disk_pct:-0}

# ─── Load averages ───────────────────────────────────────────

read -r load1 load5 load15 _ < /proc/loadavg

# ─── Mail queue ──────────────────────────────────────────────

mail_q="null"
if command -v mailq &>/dev/null; then
    mq=$(mailq 2>/dev/null || true)
    if echo "$mq" | grep -qi "empty"; then
        mail_q=0
    elif echo "$mq" | grep -qE '[0-9]+ Request'; then
        mail_q=$(echo "$mq" | grep -oE '[0-9]+( Request)' | grep -oE '^[0-9]+' | head -1)
        mail_q=${mail_q:-0}
    else
        mail_q=$(echo "$mq" | grep -cE '^[0-9A-F]' 2>/dev/null || echo "0")
    fi
elif command -v exim &>/dev/null; then
    mail_q=$(exim -bpc 2>/dev/null || echo "0")
fi

# ─── Timestamp ────────────────────────────────────────────────

ts=$(date -u +%Y-%m-%dT%H:%M:%SZ)
tz=$(cat /etc/timezone 2>/dev/null || timedatectl show -p Timezone --value 2>/dev/null || date +%Z)

# ─── Send ─────────────────────────────────────────────────────

curl -sf --max-time 15 -X POST \
    -H "Content-Type: application/json" \
    -d "{
  \"agent_key\":\"${AGENT_KEY}\",
  \"hostname\":\"${HOST}\",
  \"public_ip\":\"${PUBLIC_IP}\",
  \"fqdn\":\"${FQDN}\",
  \"cpu_usage\":${cpu_pct},
  \"memory_usage\":${mem_pct},
  \"memory_total_mb\":${mem_total_mb},
  \"disk_usage\":${disk_pct},
  \"disk_iops\":${disk_iops},
  \"network_rx_bps\":${net_rx},
  \"network_tx_bps\":${net_tx},
  \"mail_queue\":${mail_q},
  \"load_1\":${load1},
  \"load_5\":${load5},
  \"load_15\":${load15},
  \"collected_at\":\"${ts}\",
  \"timezone\":\"${tz}\"
}" "${SERVER_URL}/?action=ingest" >/dev/null

# ─── Pull config from server (auto-adjust interval) ───────────

config_resp=$(curl -sf --max-time 10 -X POST \
    -H "Content-Type: application/json" \
    -d "{\"agent_key\":\"${AGENT_KEY}\"}" \
    "${SERVER_URL}/?action=agent-config" 2>/dev/null || echo "")

if [[ -n "$config_resp" ]]; then
    # Strictly extract integer interval — reject anything non-numeric
    new_interval=$(echo "$config_resp" | grep -oE '"interval"[[:space:]]*:[[:space:]]*[0-9]+' | grep -oE '[0-9]+$' || echo "")

    if [[ -n "$new_interval" ]] && [[ "$new_interval" =~ ^[0-9]+$ ]] && (( new_interval >= 1 && new_interval <= 1440 )); then
        # Check current cron interval
        current_cron=$(crontab -l 2>/dev/null | grep "${AGENT_SCRIPT:-/opt/sermony/agent.sh}" || echo "")
        current_interval=$(echo "$current_cron" | grep -oE '^\*/[0-9]+' | grep -oE '[0-9]+' || echo "")

        if [[ -n "$current_interval" ]] && [[ "$current_interval" != "$new_interval" ]]; then
            agent_path="${AGENT_SCRIPT:-/opt/sermony/agent.sh}"
            new_cron="*/${new_interval} * * * * ${agent_path} >> /var/log/sermony.log 2>&1"
            ( crontab -l 2>/dev/null | grep -v "$agent_path" || true
              echo "$new_cron"
            ) | crontab -
            echo "sermony: interval updated from ${current_interval} to ${new_interval} minutes"
        fi
    fi
fi
