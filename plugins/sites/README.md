# Sites Plugin

Discovers domains/websites hosted on each server by parsing Apache, nginx, and Virtualmin configurations. Makes domains searchable from the dashboard.

## Features

- Scans Apache `sites-enabled/` and `conf.d/` directories
- Scans nginx `sites-enabled/` and `conf.d/` directories
- Reads Virtualmin domain files from `/etc/webmin/virtual-server/domains/`
- Detects SSL-enabled sites
- Extracts document root per site
- Site count on dashboard cards and datagrid
- Full table on server detail page with clickable domain links
- Domains searchable from dashboard search

## Setup

Deploy the agent to each server:

```bash
sudo mkdir -p /opt/sermony/plugins
sudo curl -sf 'https://your-server/?action=plugin-agent&plugin=sites' \
  -o /opt/sermony/plugins/sites-agent.sh
sudo chmod 700 /opt/sermony/plugins/sites-agent.sh

# Add to cron (every 15 minutes)
(sudo crontab -l 2>/dev/null | grep -v sites-agent; \
  echo "*/15 * * * * /opt/sermony/plugins/sites-agent.sh >> /var/log/sermony-sites.log 2>&1") \
  | sudo crontab -
```

## Requirements

- Python3 (standard on Ubuntu)
- Read access to web server configs (agent runs as root via cron)

## What It Shows

### Dashboard Cards
Site count: `Sites 5`

### Datagrid
Column showing site count, sortable

### Server Detail Page

| Domain | Web Server | SSL | Document Root |
|--------|-----------|-----|---------------|
| example.com | apache | 🔒 | /var/www/example |
| api.example.com | nginx | 🔒 | /var/www/api |
| static.site.com | nginx | — | /var/www/static |

Domains are clickable links.

### Search

Type any domain name or partial domain (e.g. `example.com`, `inctime`, `.ai`) in the dashboard search to find which servers host it.

## Uninstall

```bash
sudo crontab -l | grep -v sites-agent | sudo crontab -
sudo rm -f /opt/sermony/plugins/sites-agent.sh
```
