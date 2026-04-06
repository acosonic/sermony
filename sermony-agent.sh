#!/usr/bin/env bash
# Sermony Agent — collects server metrics and sends to monitoring server
set -e

CONFIG="/opt/sermony/config"
[[ -f "$CONFIG" ]] || { echo "sermony: config not found: $CONFIG" >&2; exit 1; }
source "$CONFIG"

# ─── Identifiers ─────────────────────────────────────────────

HOST=$(hostname | tr -cd '[:alnum:]._-')
FQDN=$(hostname -f 2>/dev/null || echo "")
PUBLIC_IP=$(curl -s4 --max-time 5 https://ifconfig.me 2>/dev/null \
         || curl -s4 --max-time 5 https://api.ipify.org 2>/dev/null \
         || echo "")
IPV6=$(curl -s6 --max-time 5 https://ifconfig.me 2>/dev/null \
    || ip -6 addr show scope global 2>/dev/null | awk '/inet6/ {print $2; exit}' \
    || echo "")

# ─── Snapshot before (CPU, network, disk I/O) ────────────────

cpu_b=($(awk '/^cpu / {printf "%d %d\n", $2+$3+$4+$5+$6+$7+$8, $5}' /proc/stat))

net_b=($(awk 'NR>2 && $1!~/lo:/ {rx+=$2; tx+=$10} END {printf "%d %d\n", rx, tx}' /proc/net/dev))

disk_rd_b=0; disk_wr_b=0
for s in /sys/block/*/stat; do
    d=$(basename "$(dirname "$s")")
    case "$d" in loop*|ram*) continue ;; esac
    read -r rd _ _ _ wr _ < "$s" 2>/dev/null || continue
    disk_rd_b=$((disk_rd_b + rd)); disk_wr_b=$((disk_wr_b + wr))
done

sleep 1

# ─── Snapshot after ───────────────────────────────────────────

cpu_a=($(awk '/^cpu / {printf "%d %d\n", $2+$3+$4+$5+$6+$7+$8, $5}' /proc/stat))

net_a=($(awk 'NR>2 && $1!~/lo:/ {rx+=$2; tx+=$10} END {printf "%d %d\n", rx, tx}' /proc/net/dev))

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

# ─── System info (hardware & config snapshot) ─────────────────

cpu_cores=$(nproc 2>/dev/null || grep -c '^processor' /proc/cpuinfo 2>/dev/null || echo "0")
cpu_model=$(grep -m1 'model name' /proc/cpuinfo 2>/dev/null | sed 's/.*: //' || echo "")
ram_total_gb=$(awk '/^MemTotal:/ {printf "%.1f", $2/1048576}' /proc/meminfo 2>/dev/null)
disk_total=$(df -h / 2>/dev/null | awk 'NR==2 {print $2}')
os_name=$(. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME" || uname -sr)
kernel=$(uname -r)
uptime_str=$(uptime -p 2>/dev/null | sed 's/^up //' || echo "")
# Build per-interface JSON array
net_ifaces_json="["
first_nic=1
while read -r ifname ifstate; do
    [[ "$ifname" == "lo" ]] && continue
    ifip4=$(ip -4 addr show "$ifname" 2>/dev/null | awk '/inet / {print $2}' | head -1)
    ifip6=$(ip -6 addr show "$ifname" scope global 2>/dev/null | awk '/inet6/ {print $2}' | head -1)
    ifmac=$(ip link show "$ifname" 2>/dev/null | awk '/link\/ether/ {print $2}')
    ifspeed=$(ethtool "$ifname" 2>/dev/null | awk '/Speed:/ {print $2}')
    [[ "$ifspeed" == "Unknown!" ]] && ifspeed=""
    [[ $first_nic -eq 0 ]] && net_ifaces_json+=","
    net_ifaces_json+="{\"name\":\"${ifname}\",\"state\":\"${ifstate}\",\"ip4\":\"${ifip4}\",\"ip6\":\"${ifip6}\",\"mac\":\"${ifmac}\",\"speed\":\"${ifspeed}\"}"
    first_nic=0
done < <(ip -br link 2>/dev/null | awk '{print $1, $2}')
net_ifaces_json+="]"
iface_count=$(echo "$net_ifaces_json" | grep -o '"name"' | wc -l)
dns_servers=$(grep '^nameserver' /etc/resolv.conf 2>/dev/null | awk '{print $2}' | tr '\n' ', ' | sed 's/,$//')
has_docker="false"
docker_count=0
DOCKER_BIN=$(command -v docker 2>/dev/null || which docker 2>/dev/null || ls /snap/bin/docker /usr/local/bin/docker /usr/bin/docker 2>/dev/null | head -1)
docker_containers_json="[]"
if [[ -n "$DOCKER_BIN" && -x "$DOCKER_BIN" ]]; then
    has_docker="true"
    docker_count=$($DOCKER_BIN ps -q 2>/dev/null | wc -l || echo "0")
    # Collect running container details
    docker_containers_json="["
    first_dc=1
    while IFS='|' read -r cname cimage cstatus cports; do
        [[ -z "$cname" ]] && continue
        [[ $first_dc -eq 0 ]] && docker_containers_json+=","
        # Escape quotes in fields
        cstatus=$(echo "$cstatus" | sed 's/"/\\"/g')
        cports=$(echo "$cports" | sed 's/"/\\"/g')
        docker_containers_json+="{\"name\":\"${cname}\",\"image\":\"${cimage}\",\"status\":\"${cstatus}\",\"ports\":\"${cports}\"}"
        first_dc=0
    done < <($DOCKER_BIN ps --format '{{.Names}}|{{.Image}}|{{.Status}}|{{.Ports}}' 2>/dev/null)
    docker_containers_json+="]"
fi
iface_count=$(ip -br link 2>/dev/null | grep -cv '^lo ' || echo "0")

# ─── Detect notable services ─────────────────────────────────
services_json="["
first_svc=1
for svc in mysql mariadb postgresql nginx apache2 httpd postfix dovecot exim4 redis-server mongod rabbitmq-server elasticsearch php-fpm named bind9; do
    if systemctl is-active --quiet "$svc" 2>/dev/null; then
        [[ $first_svc -eq 0 ]] && services_json+=","
        services_json+="{\"name\":\"${svc}\",\"active\":true}"
        first_svc=0
    fi
done
services_json+="]"

# ─── Send ─────────────────────────────────────────────────────

# Build JSON payload
PAYLOAD="{
  \"agent_key\":\"${AGENT_KEY}\",
  \"hostname\":\"${HOST}\",
  \"public_ip\":\"${PUBLIC_IP}\",
  \"ipv6\":\"${IPV6}\",
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
  \"timezone\":\"${tz}\",
  \"system_info\":{
    \"cpu_cores\":${cpu_cores},
    \"cpu_model\":\"${cpu_model}\",
    \"ram_total_gb\":${ram_total_gb:-0},
    \"disk_total\":\"${disk_total}\",
    \"os\":\"${os_name}\",
    \"kernel\":\"${kernel}\",
    \"uptime\":\"${uptime_str}\",
    \"net_interfaces\":${net_ifaces_json},
    \"iface_count\":${iface_count},
    \"dns_servers\":\"${dns_servers}\",
    \"docker\":${has_docker},
    \"docker_count\":${docker_count},
    \"docker_containers\":${docker_containers_json},
    \"services\":${services_json}
  }
}"

# Encrypt payload with AES-256-CBC using agent_key as key
ENC_KEY=$(echo -n "${AGENT_KEY}" | sha256sum | awk '{print $1}')
IV=$(openssl rand -hex 16)
ENCRYPTED=$(echo -n "$PAYLOAD" | openssl enc -aes-256-cbc -K "$ENC_KEY" -iv "$IV" -base64 -A 2>/dev/null)

if [[ -n "$ENCRYPTED" ]]; then
    # Send encrypted
    curl -sf --max-time 15 -X POST \
        -H "Content-Type: application/json" \
        -d "{\"agent_key\":\"${AGENT_KEY}\",\"encrypted\":true,\"iv\":\"${IV}\",\"data\":\"${ENCRYPTED}\"}" \
        "${SERVER_URL}/?action=ingest" >/dev/null
else
    # Fallback: send plain (if openssl not available)
    curl -sf --max-time 15 -X POST \
        -H "Content-Type: application/json" \
        -d "$PAYLOAD" \
        "${SERVER_URL}/?action=ingest" >/dev/null
fi

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
