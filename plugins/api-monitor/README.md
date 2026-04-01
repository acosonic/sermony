# API Monitor Plugin

Lightweight HTTP endpoint monitoring for Sermony. Monitor APIs, websites, and health check URLs with configurable intervals, authentication, and expected response checks.

## Features

- Monitor any HTTP/HTTPS endpoint (GET or POST)
- Authentication: Bearer token, API key header, Basic auth
- Custom request headers and POST body
- Expected HTTP status code validation
- Expected response text matching
- Configurable timeout and check interval (1-1440 min)
- Failure streak counter
- SSRF protection (blocks private/local IPs by default)
- Dashboard alert when monitors are failing
- History with retention cleanup (default 7 days)
- Run Now button for immediate testing
- Enable/disable individual monitors
- Secrets masked in UI after save
- Search across monitor names and URLs

## Setup

### 1. Plugin

Already included. No configuration needed — tables auto-create.

### 2. Cron

Add to crontab (runs every minute, only checks monitors when due):

```bash
* * * * * php /path/to/sermony/plugins/api-monitor/check.php >> /var/log/sermony-monitors.log 2>&1
```

### 3. Settings

Go to **Settings** to configure:
- **History Retention** — days to keep results (default: 7)
- **Allow Private IPs** — disable SSRF protection if monitoring internal services

## Usage

### Adding a Monitor

Click **Monitors** in the header, then **+ Add Monitor**.

**Simple health check:**
```
Name:     Production API
URL:      https://api.example.com/health
Method:   GET
Expected: 200
Interval: 5 min
```

**API with Bearer token:**
```
Name:      Stripe API
URL:       https://api.stripe.com/v1/balance
Auth:      Bearer Token
Token:     sk_live_...
Expected:  200
```

**POST with JSON body:**
```
Name:      Webhook Test
URL:       https://hooks.example.com/test
Method:    POST
Headers:   Content-Type: application/json
Body:      {"event":"ping"}
Expected:  200
Text:      "ok"
```

### Monitor Actions

| Button | Action |
|--------|--------|
| ▶ | Run check immediately |
| 📄 | View check history |
| ⏸/▶ | Enable/disable monitor |
| ✎ | Edit monitor |
| ✕ | Delete monitor |

### Dashboard Alert

When any enabled monitor is failing, a red alert bar appears at the top of the dashboard showing the count of failing monitors.

## Security

- Secrets (tokens, passwords, API keys) are masked after save — editing an existing monitor preserves the original secret unless you type a new one
- Response bodies are truncated to 500 chars — no full payloads stored
- SSRF protection blocks requests to localhost, 127.0.0.1, ::1, and RFC1918 private IPs unless explicitly allowed in Settings
- Secrets are NOT encrypted at rest (unlike the Vault plugin) — they're stored in plain text in SQLite. If you need encrypted secret storage, use short-lived tokens or reference secrets by environment variable
- CSRF protection on all mutations

## Cron Runner Details

The `check.php` script:
1. Opens the Sermony SQLite database directly
2. Selects enabled monitors where `next_run_at` has passed
3. Executes each check with cURL
4. Stores result in `api_monitor_results`
5. Updates monitor status, response time, fail streak, and next_run_at
6. Prunes results older than retention period
7. Outputs status lines for logging

Safe to run every minute — it's idempotent and only checks monitors when their interval has elapsed.

## Data Model

### api_monitors

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER | Primary key |
| name | TEXT | Display name |
| url | TEXT | Target URL |
| method | TEXT | GET or POST |
| request_headers | TEXT | One header per line |
| request_body | TEXT | POST body |
| auth_type | TEXT | none, bearer, apikey, basic |
| auth_token | TEXT | Bearer token |
| auth_username/password | TEXT | Basic auth credentials |
| api_key_header/value | TEXT | Custom API key header |
| expected_status | INTEGER | Expected HTTP status (default 200) |
| expected_text | TEXT | Text that must appear in response |
| timeout_seconds | INTEGER | cURL timeout (default 10) |
| interval_minutes | INTEGER | Check frequency (default 5) |
| enabled | INTEGER | 1 = active, 0 = disabled |
| last_status | TEXT | ok, fail, or pending |
| fail_streak | INTEGER | Consecutive failure count |

### api_monitor_results

| Column | Type | Description |
|--------|------|-------------|
| monitor_id | INTEGER | FK to api_monitors |
| checked_at | TEXT | ISO 8601 timestamp |
| success | INTEGER | 1 = pass, 0 = fail |
| http_status | INTEGER | Response HTTP code |
| response_time_ms | INTEGER | Round-trip time |
| error_message | TEXT | Failure reason (max 500 chars) |
| response_excerpt | TEXT | First 500 chars of response |

## Uninstall

1. Remove the cron entry
2. Delete the `plugins/api-monitor/` folder
3. Optionally drop tables: `DROP TABLE api_monitor_results; DROP TABLE api_monitors;`
