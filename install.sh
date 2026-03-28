#!/usr/bin/env bash
# Sermony Agent Installer
# Usage: curl -sSL 'https://your-server/?action=install-script' | sudo bash -s -- URL KEY [INTERVAL]
set -euo pipefail

# ─── Arguments ────────────────────────────────────────────────

if [[ $# -lt 2 ]]; then
    echo "Usage: $0 <server_url> <enrollment_key> [interval_minutes]"
    echo ""
    echo "  server_url       URL of the Sermony monitoring server"
    echo "  enrollment_key   Enrollment key from the dashboard"
    echo "  interval_minutes Check interval in minutes (default: from server)"
    exit 1
fi

SERVER_URL="${1%/}"
ENROLL_KEY="$2"
CUSTOM_INTERVAL="${3:-}"

INSTALL_DIR="/opt/sermony"
AGENT="${INSTALL_DIR}/agent.sh"
CONFIG="${INSTALL_DIR}/config"

echo "==============================="
echo " Sermony Agent Installer"
echo "==============================="
echo "Server: ${SERVER_URL}"
echo ""

# ─── Checks ──────────────────────────────────────────────────

if [[ $EUID -ne 0 ]]; then
    echo "Error: Please run as root (sudo)." >&2
    exit 1
fi

for cmd in curl hostname awk; do
    if ! command -v "$cmd" &>/dev/null; then
        echo "Error: Required command '$cmd' not found." >&2
        exit 1
    fi
done

# ─── Enroll ──────────────────────────────────────────────────

mkdir -p "$INSTALL_DIR"

HOST=$(hostname)
echo "Enrolling ${HOST}..."

RESPONSE=$(curl -sf --max-time 15 -X POST \
    -H "Content-Type: application/json" \
    -d "{\"enrollment_key\":\"${ENROLL_KEY}\",\"hostname\":\"${HOST}\"}" \
    "${SERVER_URL}/?action=enroll" 2>&1) || {
    echo "Error: Enrollment request failed." >&2
    echo "Response: ${RESPONSE}" >&2
    exit 1
}

# Parse JSON response (no jq dependency)
json_str() { echo "$1" | grep -o "\"$2\"[[:space:]]*:[[:space:]]*\"[^\"]*\"" | sed 's/.*:.*"\([^"]*\)".*/\1/' | head -1; }
json_int() { echo "$1" | grep -o "\"$2\"[[:space:]]*:[[:space:]]*[0-9]*" | grep -o '[0-9]*$' | head -1; }

AGENT_KEY=$(json_str "$RESPONSE" "agent_key")
SRV_INTERVAL=$(json_int "$RESPONSE" "interval")

if [[ -z "$AGENT_KEY" ]]; then
    ERROR=$(json_str "$RESPONSE" "error")
    echo "Error: Enrollment failed. ${ERROR:-$RESPONSE}" >&2
    exit 1
fi

INTERVAL=${CUSTOM_INTERVAL:-${SRV_INTERVAL:-15}}

echo "Enrolled! Agent key: ${AGENT_KEY:0:8}..."
echo "Interval: every ${INTERVAL} minutes"

# ─── Config ──────────────────────────────────────────────────

cat > "$CONFIG" <<EOF
SERVER_URL="${SERVER_URL}"
AGENT_KEY="${AGENT_KEY}"
EOF
chmod 600 "$CONFIG"

# ─── Agent Script ────────────────────────────────────────────

echo "Downloading agent script..."
curl -sf --max-time 15 "${SERVER_URL}/?action=agent-script" -o "$AGENT" || {
    echo "Error: Failed to download agent script." >&2
    exit 1
}
chmod 700 "$AGENT"

# ─── Cron ────────────────────────────────────────────────────

CRON_LINE="*/${INTERVAL} * * * * ${AGENT} >> /var/log/sermony.log 2>&1"

# Remove any existing sermony cron entry, then add new one
( crontab -l 2>/dev/null | grep -v "${AGENT}" || true
  echo "$CRON_LINE"
) | crontab -

echo "Cron job installed."

# ─── First Run ───────────────────────────────────────────────

echo "Running first collection..."
if "$AGENT" 2>&1; then
    echo "First report sent!"
else
    echo "Warning: First collection failed (will retry via cron)."
fi

# ─── Done ────────────────────────────────────────────────────

echo ""
echo "==============================="
echo " Installation complete"
echo "==============================="
echo "Agent:     ${AGENT}"
echo "Config:    ${CONFIG}"
echo "Logs:      /var/log/sermony.log"
echo "Interval:  every ${INTERVAL} minutes"
echo ""
echo "Uninstall:"
echo "  crontab -l | grep -v sermony | crontab -"
echo "  rm -rf ${INSTALL_DIR}"
