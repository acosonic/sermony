# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Sermony is a self-hosted server monitoring web application. A central PHP server receives metrics from remote Linux servers via Bash-based agents. Designed for extreme simplicity — no frameworks, no build steps, no dependencies beyond PHP 8+/SQLite and Bash/curl.

## File Layout

```
index.php            # Entire server app: routing, API, dashboard, details, CSS
sermony-agent.sh     # Client agent: collects metrics, sends via HTTPS POST
install.sh           # Client installer: enrolls, downloads agent, sets up cron
sermony.db           # SQLite database (auto-created on first request)
```

## Deployment

**Server (admin):** Copy `index.php`, `install.sh`, and `sermony-agent.sh` to a PHP-enabled web directory. Visit the URL — the database is auto-created and the enrollment key is shown on the dashboard.

**Client (per monitored machine):**
```bash
curl -sSL 'https://your-server/?action=install-script' | sudo bash -s -- 'https://your-server/' 'ENROLLMENT_KEY'
```

Optional third argument: interval in minutes (default: 15).

## Architecture

### Routing

All requests go through `index.php`. The `?action=` parameter selects the handler:

| Action | Method | Purpose |
|--------|--------|---------|
| (empty/dashboard) | GET | Dashboard with server cards |
| server | GET | Server detail page with metrics history |
| enroll | POST | Agent enrollment (returns unique agent_key) |
| ingest | POST | Receive metrics from agents |
| delete | POST | Remove server + cascading metric delete |
| install-script | GET | Serve `install.sh` |
| agent-script | GET | Serve `sermony-agent.sh` |

### Authentication Flow

1. First run auto-generates a 32-char hex **enrollment key** (stored in `settings` table)
2. `install.sh` sends enrollment key + hostname → server returns a unique 64-char hex **agent key**
3. Agent stores agent key in `/opt/sermony/config` and uses it for all future `ingest` POST requests
4. Re-enrollment with same hostname returns the existing agent key (idempotent)

### Database Schema (SQLite)

Three tables: `settings` (key-value), `servers` (id, hostname, agent_key, public_ip, fqdn, timestamps), `metrics` (server_id FK with ON DELETE CASCADE, cpu/mem/disk/iops/network/mail/load, timestamps).

Index: `idx_metrics_lookup ON metrics(server_id, received_at DESC)` — used by the dashboard's correlated subquery for latest metrics.

### Online/Offline Detection

A server is **online** if `last_seen_at` is within `interval_minutes * 2.5` of current time. The multiplier accounts for network delays and cron jitter.

### Metric Collection (agent)

The agent takes two `/proc/stat`, `/proc/net/dev`, and `/sys/block/*/stat` snapshots 1 second apart to calculate CPU%, network bytes/sec, and disk IOPS. Memory, disk usage, load, and mail queue are instant reads. Missing tools (mailq, exim) degrade gracefully to `null`.

### Retention

Metrics older than 30 days are cleaned up probabilistically (~2% of ingest requests trigger cleanup). Deleting a server cascades to its metrics via foreign key.

## Key Constants (top of index.php)

- `OFFLINE_MULT` — multiplier for offline threshold (2.5)
- `DEFAULT_INT` — default agent interval in minutes (15)
- `RETENTION` — metric retention in days (30)
- `HISTORY` — max rows shown on server detail page (200)

## Security Notes

- All SQL uses prepared statements
- Enrollment key compared with `hash_equals()` to prevent timing attacks
- Dashboard has no built-in admin auth — secure at web server level (`.htaccess`, nginx `auth_basic`, Cloudflare Access, etc.)
- Agent config (`/opt/sermony/config`) is chmod 600
- SQLite database file should not be web-accessible if stored in the document root — place it outside docroot or block access via server config

## Development

No build, no dependencies. Edit `index.php` and reload. PHP's built-in server works for local testing:

```bash
php -S localhost:8080
```

To test the ingest endpoint manually:

```bash
curl -X POST 'http://localhost:8080/?action=enroll' \
  -H 'Content-Type: application/json' \
  -d '{"enrollment_key":"YOUR_KEY","hostname":"test-server"}'
```
