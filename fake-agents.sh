#!/usr/bin/env bash
# Generates 10 fake servers with varying health states to test the dashboard.
# Usage: bash fake-agents.sh [base_url]
#   e.g. bash fake-agents.sh http://localhost/sermony
set -euo pipefail

BASE_URL="${1:-http://localhost/sermony}"
BASE_URL="${BASE_URL%/}"
ENDPOINT="${BASE_URL}/index.php"

# ── Get enrollment key from the database ──────────────────────
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DB="${SCRIPT_DIR}/sermony.db"
if [[ ! -f "$DB" ]]; then
    echo "Error: Database not found at $DB"
    echo "Visit $BASE_URL first to initialize it, then re-run this script."
    exit 1
fi

ENROLL_KEY=$(sqlite3 "$DB" "SELECT value FROM settings WHERE key='enrollment_key'")
echo "Using enrollment key: ${ENROLL_KEY:0:8}..."
echo ""

# ── Server definitions ────────────────────────────────────────
# Format: hostname|ip|fqdn|cpu|mem|disk|iops|net_rx|net_tx|mail|l1|l5|l15|state

SERVERS=(
"web-prod-01|203.0.113.10|web01.example.com|22.4|45.2|38.1|156|524288|131072|0|0.82|0.65|0.58|healthy"
"web-prod-02|203.0.113.11|web02.example.com|94.7|67.3|42.5|890|2097152|1048576|0|12.40|11.80|10.20|high-cpu"
"db-master|10.0.1.50|db-master.internal|68.2|96.1|71.2|2450|4194304|2097152|0|4.20|3.90|3.50|high-mem"
"db-replica|10.0.1.51|db-replica.internal|35.1|82.5|92.8|1800|3145728|524288|0|2.10|1.90|1.85|high-disk"
"mail-gateway|198.51.100.5|mail.example.com|41.3|52.7|55.0|320|1048576|2097152|847|1.50|1.40|1.35|mail-stuck"
"cache-01|10.0.2.10|cache01.internal|15.8|88.4|12.3|50|262144|131072|0|0.30|0.28|0.25|warning"
"worker-01|10.0.3.20|worker01.internal|97.2|91.5|88.7|1200|524288|262144|12|8.50|7.90|7.10|overloaded"
"api-gateway|203.0.113.20|api.example.com|55.0|62.1|44.8|680|8388608|4194304|0|2.80|2.50|2.30|mixed"
"monitoring|10.0.0.5|mon.internal|8.2|31.4|22.1|45|65536|32768|0|0.15|0.12|0.10|idle"
"staging-01|10.0.5.100|staging.example.com|0|0|0|0|0|0|0|0|0|0|offline"
)

# ── Enroll and send metrics ───────────────────────────────────

for entry in "${SERVERS[@]}"; do
    IFS='|' read -r hostname ip fqdn cpu mem disk iops net_rx net_tx mail l1 l5 l15 state <<< "$entry"

    # Enroll
    resp=$(curl -sf --max-time 10 -X POST \
        -H "Content-Type: application/json" \
        -d "{\"enrollment_key\":\"${ENROLL_KEY}\",\"hostname\":\"${hostname}\"}" \
        "${ENDPOINT}?action=enroll" 2>&1) || {
        echo "FAIL enroll ${hostname}: ${resp}"
        continue
    }

    agent_key=$(echo "$resp" | grep -o '"agent_key"[[:space:]]*:[[:space:]]*"[^"]*"' | sed 's/.*:.*"\([^"]*\)".*/\1/' | head -1)
    if [[ -z "$agent_key" ]]; then
        echo "FAIL enroll ${hostname}: no agent_key in response"
        continue
    fi

    echo -n "${hostname} (${state})... enrolled"

    # Send a few historical data points with slight variation
    ts_now=$(date -u +%s)
    for offset in 2700 1800 900 0; do
        ts=$(date -u -d "@$((ts_now - offset))" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null \
          || date -u -r "$((ts_now - offset))" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)

        # Add random jitter (±5%)
        jitter=$(( (RANDOM % 10) - 5 ))
        j_cpu=$(awk "BEGIN{v=${cpu}+(${cpu}*${jitter}/100); if(v<0)v=0; if(v>100)v=100; printf \"%.1f\",v}")
        j_mem=$(awk "BEGIN{v=${mem}+(${mem}*${jitter}/100); if(v<0)v=0; if(v>100)v=100; printf \"%.1f\",v}")
        j_disk=$(awk "BEGIN{v=${disk}+(${disk}*${jitter}/100); if(v<0)v=0; if(v>100)v=100; printf \"%.1f\",v}")

        # Offline server only sends one old data point
        if [[ "$state" == "offline" && $offset -ne 2700 ]]; then
            continue
        fi

        curl -sf --max-time 10 -X POST \
            -H "Content-Type: application/json" \
            -d "{
                \"agent_key\":\"${agent_key}\",
                \"hostname\":\"${hostname}\",
                \"public_ip\":\"${ip}\",
                \"fqdn\":\"${fqdn}\",
                \"cpu_usage\":${j_cpu},
                \"memory_usage\":${j_mem},
                \"memory_total_mb\":16384,
                \"disk_usage\":${j_disk},
                \"disk_iops\":${iops},
                \"network_rx_bps\":${net_rx},
                \"network_tx_bps\":${net_tx},
                \"mail_queue\":${mail},
                \"load_1\":${l1},
                \"load_5\":${l5},
                \"load_15\":${l15},
                \"collected_at\":\"${ts}\"
            }" "${ENDPOINT}?action=ingest" >/dev/null 2>&1
    done

    # For offline server, backdate last_seen_at
    if [[ "$state" == "offline" ]]; then
        sqlite3 "$DB" "UPDATE servers SET last_seen_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '-2 hours') WHERE hostname='${hostname}'"
    fi

    echo " + metrics sent"
done

echo ""
echo "Done! 10 fake servers created."
echo ""
echo "Expected dashboard states:"
echo "  web-prod-01     healthy (green)"
echo "  web-prod-02     CRITICAL: CPU ~95%"
echo "  db-master       CRITICAL: memory ~96%"
echo "  db-replica      CRITICAL: disk ~93%"
echo "  mail-gateway    healthy, but mail queue 847"
echo "  cache-01        WARNING: memory ~88%"
echo "  worker-01       CRITICAL: CPU+mem+disk all red"
echo "  api-gateway     healthy (moderate load)"
echo "  monitoring      healthy (idle)"
echo "  staging-01      OFFLINE (last seen 2h ago)"
