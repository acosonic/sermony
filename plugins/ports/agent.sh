#!/usr/bin/env bash
# Sermony Open Ports Plugin Agent — collects listening ports
set -e

CONFIG="/opt/sermony/config"
[[ -f "$CONFIG" ]] || exit 0
source "$CONFIG"

# Use ss + python3 for reliable parsing
ports_json=$(ss -tlnp 2>/dev/null | python3 -c "
import sys, json, subprocess, re

ports = []
seen = set()

for line in sys.stdin:
    line = line.strip()
    if not line or line.startswith('State'):
        continue
    parts = line.split()
    if len(parts) < 5:
        continue

    state = parts[0]
    local = parts[3]

    # Parse address:port
    if ']:' in local:  # IPv6 [::]:port
        port = local.rsplit(':', 1)[-1]
        bind = local.rsplit(':', 1)[0]
    elif local.count(':') == 1:  # IPv4 addr:port
        bind, port = local.rsplit(':', 1)
    else:
        continue

    try:
        port = int(port)
    except:
        continue

    # Parse process info
    prog = ''
    pid = 0
    user_info = ' '.join(parts[5:])
    m = re.search(r'\"([^\"]+)\",pid=(\d+)', user_info)
    if m:
        prog = m.group(1)
        pid = int(m.group(2))

    key = f'tcp:{port}:{bind}'
    if key in seen:
        continue
    seen.add(key)

    # Get user
    puser = 'unknown'
    if pid:
        try:
            puser = subprocess.check_output(['ps', '-o', 'user=', '-p', str(pid)], stderr=subprocess.DEVNULL).decode().strip()
        except:
            pass

    ports.append({
        'port': port,
        'proto': 'tcp',
        'bind': bind,
        'program': prog,
        'pid': pid,
        'user': puser,
        'state': 'LISTEN'
    })

# Also get UDP
import subprocess as sp
try:
    udp = sp.check_output(['ss', '-ulnp'], stderr=sp.DEVNULL).decode()
    for line in udp.strip().split('\n')[1:]:
        parts = line.split()
        if len(parts) < 5:
            continue
        local = parts[3]
        if ']:' in local:
            port = local.rsplit(':', 1)[-1]
            bind = local.rsplit(':', 1)[0]
        elif local.count(':') == 1:
            bind, port = local.rsplit(':', 1)
        else:
            continue
        try:
            port = int(port)
        except:
            continue
        prog = ''
        pid = 0
        user_info = ' '.join(parts[5:])
        m = re.search(r'\"([^\"]+)\",pid=(\d+)', user_info)
        if m:
            prog = m.group(1)
            pid = int(m.group(2))
        key = f'udp:{port}:{bind}'
        if key in seen:
            continue
        seen.add(key)
        puser = 'unknown'
        if pid:
            try:
                puser = sp.check_output(['ps', '-o', 'user=', '-p', str(pid)], stderr=sp.DEVNULL).decode().strip()
            except:
                pass
        ports.append({'port': port, 'proto': 'udp', 'bind': bind, 'program': prog, 'pid': pid, 'user': puser, 'state': 'LISTEN'})
except:
    pass

# Sort by port number
ports.sort(key=lambda x: x['port'])
print(json.dumps(ports))
" 2>/dev/null || echo "[]")

# Send to server
curl -sf --max-time 10 -X POST \
    -H "Content-Type: application/json" \
    -d "{\"agent_key\":\"${AGENT_KEY}\",\"plugin\":\"ports\",\"data\":${ports_json}}" \
    "${SERVER_URL}/?action=plugin-data" >/dev/null 2>&1
