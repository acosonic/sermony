# PM2 Monitor Plugin

Monitors PM2 processes across all your servers. Shows process status on dashboard cards, adds a PM2 column to the datagrid, and displays a full process table on server detail pages. PM2 process names are searchable from the dashboard.

## How It Works

This plugin has its own agent script that runs alongside the main Sermony agent. It collects PM2 process info from all user accounts on each server and sends it to the Sermony server via the `plugin-data` API.

## Requirements

- PM2 installed on monitored servers (any location — nvm, global, snap)
- Python3 on monitored servers (for parsing `pm2 jlist` output)
- The main Sermony agent must be installed first

## Installation

### 1. Plugin (server-side)

Already included with Sermony. The plugin folder should be at:

```
plugins/
  pm2/
    plugin.php
    agent.sh
    README.md
```

### 2. Agent (per monitored server)

Deploy the PM2 agent to each server that has PM2:

```bash
curl -sf 'https://your-server/?action=plugin-agent&plugin=pm2' \
  | sudo tee /opt/sermony/plugins/pm2-agent.sh > /dev/null
sudo chmod 700 /opt/sermony/plugins/pm2-agent.sh
```

Add to cron (runs every 15 minutes alongside the main agent):

```bash
echo "*/15 * * * * /opt/sermony/plugins/pm2-agent.sh >> /var/log/sermony-pm2.log 2>&1" \
  | sudo tee -a /var/spool/cron/crontabs/root > /dev/null
```

Or as a one-liner that handles everything:

```bash
sudo mkdir -p /opt/sermony/plugins && \
sudo curl -sf 'https://your-server/?action=plugin-agent&plugin=pm2' \
  -o /opt/sermony/plugins/pm2-agent.sh && \
sudo chmod 700 /opt/sermony/plugins/pm2-agent.sh && \
(sudo crontab -l 2>/dev/null | grep -v pm2-agent; \
  echo "*/15 * * * * /opt/sermony/plugins/pm2-agent.sh >> /var/log/sermony-pm2.log 2>&1") \
  | sudo crontab -
```

## What It Shows

### Dashboard Cards

Each card shows a PM2 status count:

- **3/3 online** (green) — all processes running
- **2/3 online** (amber) — some processes stopped
- **0/3 online** (red) — all processes down

### Datagrid

Adds a **PM2** column showing the online/total count, sortable.

### Server Detail Page

Full process table with:

| Column | Description |
|--------|-------------|
| Name | PM2 process name |
| User | Linux user running the process |
| Status | online, stopped, errored (color-coded) |
| CPU | Current CPU usage % |
| Mem | Memory usage in MB |
| Restarts | Total restart count |
| Uptime | How long the process has been running |
| Script | Script filename |

### Search

Type any PM2 process name in the dashboard search bar to find which servers run it. Also matches "pm2" as a keyword for all servers with PM2.

## How the Agent Works

1. Finds the `pm2` binary (checks PATH, nvm, global, snap locations)
2. Scans all user home directories for `.pm2` folders (`/home/*/.pm2`, `/root/.pm2`)
3. Runs `pm2 jlist` as each user to get structured JSON process data
4. Parses with Python3 to extract name, status, CPU, memory, restarts, uptime, script
5. Sends JSON to `?action=plugin-data` using the server's agent key

## Data Storage

PM2 data is stored in the `plugin_data` table:

```sql
plugin_data (
    server_id  INTEGER,  -- references servers(id)
    plugin     TEXT,      -- 'pm2'
    data       TEXT,      -- JSON array of processes
    updated_at TEXT
)
```

## Uninstall Agent

```bash
sudo crontab -l | grep -v pm2-agent | sudo crontab -
sudo rm -f /opt/sermony/plugins/pm2-agent.sh
```

## Troubleshooting

**No PM2 data showing:** The agent needs `pm2` in PATH or standard locations. Check with `which pm2` on the server. If pm2 is installed via nvm, ensure the nvm node version path is accessible to root.

**Only some users' processes showing:** The agent runs as root and uses `sudo -u` to check each user. Ensure the ubuntu user has passwordless sudo.

**Python3 not found:** The agent uses Python3 to parse `pm2 jlist` JSON. Install with `sudo apt install python3` if missing.
