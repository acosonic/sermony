# Sermony

Simple, self-hosted server monitoring. One PHP file, one SQLite database, one Bash agent.

## What it does

A central PHP web app receives metrics from remote Linux servers via lightweight Bash agents. Each server gets its own card on a dashboard showing CPU, memory, disk, IOPS, network, mail queue, and load averages — with configurable warning/critical thresholds.

## Requirements

**Server:** PHP 8+, SQLite3, a web server (Apache/nginx/Caddy)

**Agents:** Bash, curl, cron (standard on any Linux)

## Quick Start

### 1. Deploy the server

Copy `index.php`, `install.sh`, and `sermony-agent.sh` to a PHP-enabled web directory:

```bash
cp index.php install.sh sermony-agent.sh /var/www/sermony/
```

Visit the URL in your browser. The database is created automatically.

### 2. Add servers to monitor

Go to **Settings** to find the install command, then run it on each machine you want to monitor:

```bash
curl -sSL 'https://your-server/?action=install-script' | sudo bash -s -- 'https://your-server/' 'ENROLLMENT_KEY'
```

Optional third argument: interval in minutes (default: 15).

That's it. Servers appear on the dashboard automatically.

## Files

| File | Purpose |
|------|---------|
| `index.php` | Entire server app — routing, API, dashboard, settings, CSS |
| `sermony-agent.sh` | Client agent — collects metrics, sends JSON via curl |
| `install.sh` | Client installer — enrolls, downloads agent, sets up cron |
| `fake-agents.sh` | Test script — creates 10 fake servers with various health states |

## Features

- Dark/light theme with localStorage persistence
- Configurable alert thresholds (CPU, memory, disk, mail queue)
- Status badges: CRITICAL (pulsing), WARNING, OFFLINE
- Dashboard status summary bar
- Server detail page with metrics history table
- Auto-refresh based on configured interval
- Settings page for all configuration
- Secure enrollment flow (one-time enrollment key, unique per-agent keys)
- Graceful degradation when agent tools are missing
- 30-day metric retention with probabilistic cleanup

## Security

- Prepared statements for all SQL
- `hash_equals()` for enrollment key comparison
- Unique 64-char hex agent key per server
- Agent config stored chmod 600
- No built-in dashboard auth — secure at web server level (`.htaccess`, `auth_basic`, Cloudflare Access, etc.)

## Uninstall agent

```bash
sudo crontab -l | grep -v sermony | sudo crontab -
sudo rm -rf /opt/sermony
```
