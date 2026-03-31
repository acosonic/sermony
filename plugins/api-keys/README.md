# API Key Manager Plugin

Track API keys across your infrastructure — which servers use which keys, where they're stored, when they expire, and how much they cost. Keys are encrypted client-side with the same AES-256-GCM crypto as the Credential Vault.

## Features

- Encrypted key storage (same vault key as Credential Vault)
- Link keys to multiple servers
- Track file paths where each key is used
- Expiry tracking with visual badges (expired = red, expiring within 30 days = amber)
- Monthly budget tracking with cost URL links
- Search across key names, providers, purposes, and notes
- Server detail pages show linked API keys
- Dashboard search finds servers by their API key provider

## Access

Click **API Keys** in the header navigation, or go to `?action=api-keys`.

Enter your vault key to unlock — same key as the Credential Vault. If you've already unlocked the vault in this session, it auto-unlocks.

## Fields

| Field | Description |
|-------|-------------|
| Name | Descriptive name (e.g. "AWS Production", "Cloudflare Main") |
| Provider | Service provider (AWS, Cloudflare, Stripe, SendGrid, etc.) |
| API Key / Secret | The actual key — encrypted client-side, never sent in plain text |
| Purpose | What this key is used for (email sending, backups, DNS, etc.) |
| Issued Date | When the key was created |
| Expires Date | When the key expires (triggers visual warnings) |
| Cost Tracking URL | Link to the provider's billing/spending page |
| Monthly Budget | Expected monthly cost ($) |
| Servers Used On | Which Sermony servers use this key (multi-select) |
| Paths / Folders | File paths where the key appears (e.g. `/var/www/app/.env`) |
| Notes | Any additional info |

## Usage Examples

### Track an AWS Key

```
Name:        AWS Capped Out Media
Provider:    AWS
API Key:     AKIA3UVDQT4EFLUIXIOV
Purpose:     S3 backups and SES email
Issued:      2024-01-15
Expires:     2025-01-15
Budget:      $50/mo
Cost URL:    https://console.aws.amazon.com/billing/
Servers:     web-prod-01, db-master
Paths:       /var/www/app/.env
             /opt/backup/config.sh
```

### Track a Cloudflare API Token

```
Name:        Cloudflare DNS Automation
Provider:    Cloudflare
API Key:     cf-xxxxxxxxxxxx
Purpose:     Certbot DNS validation
Servers:     web-prod-01, web-prod-02, mail-gateway
Paths:       /etc/letsencrypt/cloudflare.ini
```

## Search

From the API Keys page, search by:
- Key name
- Provider name
- Purpose
- Notes

From the dashboard, search by provider name or key name to find which servers use a specific key.

## Security

- API keys encrypted with AES-256-GCM (Web Crypto API)
- Same PBKDF2 key derivation as the Credential Vault (100k iterations, SHA-256)
- Vault key never sent to or stored on the server
- All metadata (name, provider, purpose, dates) stored in plain text — only the actual key/secret is encrypted
- CSRF protection on all save/delete operations

## Installation

Already included with Sermony. The plugin folder should be at:

```
plugins/
  api-keys/
    plugin.php
    README.md
```

No configuration needed. The `api_keys` table is auto-created on first use.

## Changing Vault Key

Use the **Change Vault Key** feature in Settings (from the Credential Vault plugin). The API Key Manager uses the same vault key, so changing it re-encrypts everything.

Note: The bulk re-key in the Vault plugin currently re-encrypts server credentials only. To re-encrypt API keys, you'll need to:

1. Unlock with the old key
2. Note down/export your keys
3. Change the vault key in Settings
4. Re-save each API key (it will encrypt with the new key)
