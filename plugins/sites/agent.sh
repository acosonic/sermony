#!/usr/bin/env bash
# Sermony Sites Plugin Agent — collects domains/sites hosted on the server
set -e

CONFIG="/opt/sermony/config"
[[ -f "$CONFIG" ]] || exit 0
source "$CONFIG"

# Collect from Apache and nginx configs + Virtualmin domains
sites_json=$(python3 -c "
import os, re, json, glob, subprocess

sites = []
seen = set()

def add_site(domain, webserver='', path='', ssl=False, docroot=''):
    domain = (domain or '').strip().lower()
    if not domain or domain in ('_', 'localhost', 'default_server', 'server_name'):
        return
    # Skip regex/wildcard placeholders
    if domain.startswith('~') or domain.startswith('*.') and not domain[2:]:
        return
    key = f'{domain}|{webserver}'
    if key in seen:
        return
    seen.add(key)
    sites.append({
        'domain': domain,
        'webserver': webserver,
        'ssl': ssl,
        'docroot': docroot,
        'config': path,
    })

# ── Apache ────────────────────────────────────────────────────
for path in sorted(glob.glob('/etc/apache2/sites-enabled/*.conf') +
                   glob.glob('/etc/httpd/conf.d/*.conf') +
                   glob.glob('/etc/httpd/sites-enabled/*.conf')):
    try:
        with open(path, 'r', errors='ignore') as f:
            content = f.read()
        # Parse vhosts
        for vh in re.split(r'(?i)<VirtualHost\s', content)[1:]:
            ssl = ':443' in vh.split('>',1)[0] or re.search(r'SSLEngine\s+on', vh, re.I)
            server_names = re.findall(r'(?im)^\s*Server(?:Name|Alias)\s+(.+?)\s*\$', vh)
            docroot_m = re.search(r'(?im)^\s*DocumentRoot\s+(\S+)', vh)
            docroot = docroot_m.group(1).strip('\"') if docroot_m else ''
            for line in server_names:
                for d in line.split():
                    add_site(d, 'apache', path, bool(ssl), docroot)
    except: pass

# ── nginx ─────────────────────────────────────────────────────
for path in sorted(glob.glob('/etc/nginx/sites-enabled/*') +
                   glob.glob('/etc/nginx/conf.d/*.conf')):
    try:
        with open(path, 'r', errors='ignore') as f:
            content = f.read()
        # Each server block
        server_blocks = re.findall(r'server\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}', content, re.S)
        for block in server_blocks:
            ssl = bool(re.search(r'listen\s+\d+\s+ssl|ssl_certificate\s', block))
            name_m = re.search(r'(?im)^\s*server_name\s+(.+?);', block)
            root_m = re.search(r'(?im)^\s*root\s+(\S+);', block)
            docroot = root_m.group(1) if root_m else ''
            if name_m:
                for d in name_m.group(1).split():
                    add_site(d, 'nginx', path, ssl, docroot)
    except: pass

# ── Virtualmin / Webmin domains ──────────────────────────────
for path in glob.glob('/etc/webmin/virtual-server/domains/*'):
    try:
        with open(path, 'r', errors='ignore') as f:
            domain = ''
            ssl = False
            docroot = ''
            for line in f:
                if line.startswith('dom='):
                    domain = line.split('=',1)[1].strip()
                elif line.startswith('public_html_path='):
                    docroot = line.split('=',1)[1].strip()
                elif line.startswith('ssl=') and line.split('=',1)[1].strip() == '1':
                    ssl = True
            if domain:
                add_site(domain, 'virtualmin', path, ssl, docroot)
    except: pass

# Sort by domain
sites.sort(key=lambda s: s['domain'])
print(json.dumps(sites))
" 2>/dev/null || echo "[]")

# Send to server
curl -sf --max-time 10 -X POST \
    -H "Content-Type: application/json" \
    -d "{\"agent_key\":\"${AGENT_KEY}\",\"plugin\":\"sites\",\"data\":${sites_json}}" \
    "${SERVER_URL}/?action=plugin-data" >/dev/null 2>&1
