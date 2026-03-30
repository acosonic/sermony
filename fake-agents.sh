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
# Format: hostname|ip|fqdn|cpu|mem|disk|iops|net_rx|net_tx|mail|l1|l5|l15|state|cores|ram_gb|disk_total|cpu_model|os|docker_containers|services

SERVERS=(
"web-prod-01|203.0.113.10|web01.example.com|22.4|45.2|38.1|156|524288|131072|0|0.82|0.65|0.58|healthy|8|32.0|500G|Intel Xeon E-2388G|Ubuntu 22.04.4 LTS|nginx:latest,php-app:8.2|nginx,php-fpm"
"web-prod-02|203.0.113.11|web02.example.com|94.7|67.3|42.5|890|2097152|1048576|0|12.40|11.80|10.20|high-cpu|4|16.0|250G|Intel Xeon E-2288G|Ubuntu 22.04.4 LTS|nginx:latest,php-app:8.2|nginx,php-fpm"
"db-master|10.0.1.50|db-master.internal|68.2|96.1|71.2|2450|4194304|2097152|0|4.20|3.90|3.50|high-mem|16|64.0|2T|AMD EPYC 7282 16-Core|Ubuntu 22.04.4 LTS|postgres:16|postgresql"
"db-replica|10.0.1.51|db-replica.internal|35.1|82.5|92.8|1800|3145728|524288|0|2.10|1.90|1.85|high-disk|16|64.0|2T|AMD EPYC 7282 16-Core|Ubuntu 22.04.4 LTS|postgres:16|postgresql"
"mail-gateway|198.51.100.5|mail.example.com|41.3|52.7|55.0|320|1048576|2097152|847|1.50|1.40|1.35|mail-stuck|2|4.0|80G|Intel Core i3-10100|Ubuntu 20.04.6 LTS||postfix,dovecot"
"cache-01|10.0.2.10|cache01.internal|15.8|88.4|12.3|50|262144|131072|0|0.30|0.28|0.25|warning|4|32.0|100G|Intel Xeon E-2288G|Ubuntu 22.04.4 LTS|redis:7-alpine|redis-server"
"worker-01|10.0.3.20|worker01.internal|97.2|91.5|88.7|1200|524288|262144|12|8.50|7.90|7.10|overloaded|2|8.0|120G|Intel Core i5-10400|Ubuntu 20.04.6 LTS|worker:latest,rabbitmq:3|rabbitmq-server"
"api-gateway|203.0.113.20|api.example.com|55.0|62.1|44.8|680|8388608|4194304|0|2.80|2.50|2.30|mixed|8|16.0|250G|AMD EPYC 7371 16-Core|Ubuntu 22.04.4 LTS|nginx:latest,node-api:18|nginx"
"monitoring|10.0.0.5|mon.internal|8.2|31.4|22.1|45|65536|32768|0|0.15|0.12|0.10|idle|2|4.0|50G|Intel Core i3-10100|Ubuntu 22.04.4 LTS|||"
"staging-01|10.0.5.100|staging.example.com|0|0|0|0|0|0|0|0|0|0|offline|4|8.0|100G|AMD EPYC 7282 16-Core|Ubuntu 22.04.4 LTS||nginx,postgresql"
)

# ── Enroll and send metrics ───────────────────────────────────

for entry in "${SERVERS[@]}"; do
    IFS='|' read -r hostname ip fqdn cpu mem disk iops net_rx net_tx mail l1 l5 l15 state cores ram_gb disk_total cpu_model os docker_list services <<< "$entry"

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

    # Build docker containers JSON
    docker_json="[]"
    docker_count=0
    has_docker="false"
    if [[ -n "$docker_list" ]]; then
        has_docker="true"
        docker_json="["
        first=1
        IFS=',' read -ra DOCKS <<< "$docker_list"
        for dc in "${DOCKS[@]}"; do
            [[ $first -eq 0 ]] && docker_json+=","
            docker_json+="{\"name\":\"${dc%%:*}\",\"image\":\"${dc}\",\"status\":\"Up 3 weeks\",\"ports\":\"\"}"
            first=0
            docker_count=$((docker_count+1))
        done
        docker_json+="]"
    fi

    # Build services JSON
    services_json="[]"
    if [[ -n "$services" ]]; then
        services_json="["
        first=1
        IFS=',' read -ra SVCS <<< "$services"
        for svc in "${SVCS[@]}"; do
            [[ $first -eq 0 ]] && services_json+=","
            services_json+="{\"name\":\"${svc}\",\"active\":true}"
            first=0
        done
        services_json+="]"
    fi

    # Build NIC JSON
    nic_json="[{\"name\":\"eth0\",\"state\":\"UP\",\"ip\":\"${ip}/24\",\"mac\":\"$(printf '%02x:%02x:%02x:%02x:%02x:%02x' $((RANDOM%256)) $((RANDOM%256)) $((RANDOM%256)) $((RANDOM%256)) $((RANDOM%256)) $((RANDOM%256)))\",\"speed\":\"10000Mb/s\"}]"

    # Send historical data points with slight variation
    ts_now=$(date -u +%s)
    for offset in 2700 1800 900 0; do
        ts=$(date -u -d "@$((ts_now - offset))" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null \
          || date -u -r "$((ts_now - offset))" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)

        # Add random jitter (+-5%)
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
                \"memory_total_mb\":$((${ram_gb%.*} * 1024)),
                \"disk_usage\":${j_disk},
                \"disk_iops\":${iops},
                \"network_rx_bps\":${net_rx},
                \"network_tx_bps\":${net_tx},
                \"mail_queue\":${mail},
                \"load_1\":${l1},
                \"load_5\":${l5},
                \"load_15\":${l15},
                \"collected_at\":\"${ts}\",
                \"timezone\":\"America/New_York\",
                \"system_info\":{
                    \"cpu_cores\":${cores},
                    \"cpu_model\":\"${cpu_model}\",
                    \"ram_total_gb\":${ram_gb},
                    \"disk_total\":\"${disk_total}\",
                    \"os\":\"${os}\",
                    \"kernel\":\"5.15.0-91-generic\",
                    \"uptime\":\"3 weeks, 2 days\",
                    \"net_interfaces\":${nic_json},
                    \"iface_count\":1,
                    \"dns_servers\":\"8.8.8.8, 1.1.1.1\",
                    \"docker\":${has_docker},
                    \"docker_count\":${docker_count},
                    \"docker_containers\":${docker_json},
                    \"services\":${services_json}
                }
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
echo "  web-prod-01     healthy (8 cores, 32GB, nginx+php)"
echo "  web-prod-02     CRITICAL: CPU ~95% (4 cores, 16GB)"
echo "  db-master       CRITICAL: memory ~96% (16 cores, 64GB, postgres)"
echo "  db-replica      CRITICAL: disk ~93% (16 cores, 64GB, postgres)"
echo "  mail-gateway    healthy, mail queue 847 (postfix+dovecot)"
echo "  cache-01        WARNING: memory ~88% (4 cores, 32GB, redis)"
echo "  worker-01       CRITICAL: all red (2 cores, 8GB, rabbitmq)"
echo "  api-gateway     healthy moderate (8 cores, 16GB, nginx+node)"
echo "  monitoring      healthy idle (2 cores, 4GB)"
echo "  staging-01      OFFLINE (4 cores, 8GB)"
