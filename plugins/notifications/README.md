# Notifications Plugin

Receive and display notifications from external systems via webhook. Use it to get alerts from CI/CD pipelines, deployment scripts, cron jobs, or any system that can send an HTTP POST.

## Features

- Webhook endpoint for receiving notifications (token-authenticated, no login required)
- Four priority levels: critical, warning, info, success
- Dashboard banner shows unread critical/warning notifications
- Header badge shows unread count
- Acknowledge individual or all notifications
- Retention cleanup with configurable period
- Auto-refresh every 15 seconds
- Optional: link to a server, include a URL

## Setup

1. Go to **Settings** — a webhook token is auto-generated under "Notifications"
2. Copy the token
3. Send notifications via curl from anywhere

No cron needed — it's a passive receiver.

## Sending Notifications

### Basic

```bash
curl -X POST 'https://your-server/?action=notify' \
  -H 'Content-Type: application/json' \
  -d '{"token":"YOUR_TOKEN","title":"Hello","priority":"info"}'
```

### Full Example

```bash
curl -X POST 'https://your-server/?action=notify' \
  -H 'Content-Type: application/json' \
  -d '{
    "token": "YOUR_TOKEN",
    "title": "Deploy Complete",
    "message": "v2.1.0 deployed to production successfully",
    "priority": "success",
    "source": "github-actions",
    "server_id": 5,
    "url": "https://github.com/org/repo/actions/runs/12345"
  }'
```

### Priority Levels

| Priority | Color | Dashboard | Use For |
|----------|-------|-----------|---------|
| `critical` | Red | Banner + badge | Outages, failures, security alerts |
| `warning` | Amber | Banner + badge | Degraded performance, disk warnings |
| `info` | Blue | Badge only | Deployments, config changes |
| `success` | Green | Badge only | Successful operations, recoveries |

### Fields

| Field | Required | Description |
|-------|----------|-------------|
| `token` | Yes | Webhook token from Settings |
| `title` | Yes | Short notification title (max 200 chars) |
| `message` | No | Detailed message (max 2000 chars) |
| `priority` | No | critical, warning, info (default), success |
| `source` | No | Where it came from (max 100 chars) |
| `server_id` | No | Link to a Sermony server by ID |
| `url` | No | External link (max 500 chars) |

## Integration Examples

### After a Deploy

```bash
#!/bin/bash
# deploy.sh
git pull && composer install && php artisan migrate
curl -sf -X POST 'https://monitor.example.com/?action=notify' \
  -H 'Content-Type: application/json' \
  -d "{\"token\":\"$SERMONY_TOKEN\",\"title\":\"Deploy: $(git log -1 --format=%s)\",\"priority\":\"success\",\"source\":\"deploy\"}"
```

### Cron Job Failure

```bash
0 * * * * /opt/backup.sh || curl -sf -X POST 'https://monitor.example.com/?action=notify' \
  -H 'Content-Type: application/json' \
  -d '{"token":"TOKEN","title":"Backup failed","priority":"critical","source":"cron"}'
```

### GitHub Actions

```yaml
- name: Notify Sermony
  if: always()
  run: |
    STATUS=${{ job.status == 'success' && 'success' || 'critical' }}
    curl -sf -X POST '${{ secrets.SERMONY_URL }}?action=notify' \
      -H 'Content-Type: application/json' \
      -d "{\"token\":\"${{ secrets.SERMONY_TOKEN }}\",\"title\":\"Build ${{ github.ref_name }}: ${{ job.status }}\",\"priority\":\"$STATUS\",\"source\":\"github\"}"
```

## Uninstall

Delete the `plugins/notifications/` folder. Optionally: `DROP TABLE notifications;`
