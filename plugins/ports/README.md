# Open Ports Plugin

Monitors listening TCP/UDP ports on each server. Shows which ports are open, what programs are listening, and highlights publicly accessible ports.

## Features

- Collects all listening TCP and UDP ports
- Shows program name, PID, and user per port
- Highlights publicly accessible ports (bound to 0.0.0.0 / [::]) in amber
- Port count on dashboard cards
- Port numbers in datagrid column
- Full port table on server detail page
- Ports and program names searchable from dashboard

## Setup

### 1. Deploy Agent

```bash
sudo mkdir -p /opt/sermony/plugins
sudo curl -sf 'https://your-server/?action=plugin-agent&plugin=ports' \
  -o /opt/sermony/plugins/ports-agent.sh
sudo chmod 700 /opt/sermony/plugins/ports-agent.sh
```

### 2. Add to Cron

```bash
(sudo crontab -l 2>/dev/null | grep -v ports-agent; \
  echo "*/15 * * * * /opt/sermony/plugins/ports-agent.sh >> /var/log/sermony-ports.log 2>&1") \
  | sudo crontab -
```

### 3. Or One-Liner

```bash
sudo mkdir -p /opt/sermony/plugins && \
sudo curl -sf 'https://your-server/?action=plugin-agent&plugin=ports' \
  -o /opt/sermony/plugins/ports-agent.sh && \
sudo chmod 700 /opt/sermony/plugins/ports-agent.sh && \
(sudo crontab -l 2>/dev/null | grep -v ports-agent; \
  echo "*/15 * * * * /opt/sermony/plugins/ports-agent.sh >> /var/log/sermony-ports.log 2>&1") \
  | sudo crontab -
```

## Requirements

- `ss` command (standard on all modern Linux)
- Python3 (for reliable ss output parsing)
- Root access (agent runs via sudo cron to see all processes)

## What It Shows

### Dashboard Cards
Port count: `Ports 12 TCP`

### Datagrid
Column showing port numbers: `22, 25, 80, 443, 3306, 5432`

### Server Detail Page

| Port | Proto | Bind | Program | PID | User |
|------|-------|------|---------|-----|------|
| **22** | TCP | 0.0.0.0 | sshd | 1234 | root |
| **80** | TCP | 0.0.0.0 | nginx | 5678 | www-data |
| **3306** | TCP | 127.0.0.1 | mysqld | 9012 | mysql |
| **5432** | TCP | 127.0.0.1 | postgres | 3456 | postgres |

Ports bound to `0.0.0.0` or `[::]` are highlighted in amber — these are publicly accessible.

### Search

Type a port number (e.g. `3306`) or program name (e.g. `nginx`) in the dashboard search to find which servers have it.

## Uninstall

```bash
sudo crontab -l | grep -v ports-agent | sudo crontab -
sudo rm -f /opt/sermony/plugins/ports-agent.sh
```
