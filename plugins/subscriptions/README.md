# Subscriptions Plugin

Track recurring services and subscriptions you pay for. Records cost, billing cycle, login credentials, and links each subscription to the servers it relates to. Calculates total monthly and annual spend automatically.

## Features

- Track any recurring service: hosting, domains, SaaS, APIs, licenses, email, CDN, backup
- 8 billing cycles: daily, weekly, monthly, quarterly, biannual, annual, biennial, lifetime
- All costs normalized to monthly equivalent for total spend calculation
- 7 currencies supported (display only — totals shown in entered currency)
- Login credentials encrypted client-side (same vault key as Credential Vault / API Keys)
- Link multiple servers to each subscription
- Renewal date tracking with overdue (red) and renewing-soon (amber) badges
- Active/inactive toggle (inactive subs excluded from totals)
- Category grouping with top-spending category shown
- Server detail page shows linked subscriptions and their monthly cost
- Search by name, vendor, category, purpose, username, notes

## Why Same Vault Key as Other Plugins

Subscriptions use the same vault key as the Credential Vault and API Keys plugins. Set it once per browser session — works across all three plugins. Encrypted with AES-256-GCM via Web Crypto API, key derived with PBKDF2 (100k iterations). Server stores only opaque encrypted blobs.

## Usage

1. Click **Subs** in the header nav
2. Enter your vault key (same as Credential Vault)
3. Click **+ Add** to record a subscription:
   - **Name**: Display name (e.g. "OVH VPS Production")
   - **Vendor**: Provider (e.g. "OVH")
   - **Category**: Hosting, Domain, SaaS, etc.
   - **Cost** + **Currency** + **Billing Cycle**: e.g. $12.99 USD monthly
   - **Next Renewal**: Date — flagged amber 30 days before, red if overdue
   - **Login URL** + **Username** + **Password**: Encrypted credentials with copy buttons
   - **Servers**: Multi-select which servers this is for/used on
   - **Purpose** + **Notes**: Free-text fields

## Totals

Top of the page shows:
- **Active subs**: count of currently-paying subscriptions
- **Total / month**: sum of all active subs converted to monthly
- **Total / year**: monthly × 12
- **Top category**: which category costs the most per month

Inactive subscriptions are excluded from totals.

## Server Detail Integration

Each server detail page shows its linked subscriptions and the combined monthly cost from those services. Useful for understanding the full cost of running a particular server (hosting + domain + SSL + monitoring + backup + ...).

## Search

Type any part of: subscription name, vendor, category, purpose, username, or notes. Also "subscription" as a keyword finds servers with any linked subscription.

## Examples

```
Name:     OVH VPS Production
Vendor:   OVH
Category: Hosting
Cost:     $89.99 / monthly
Next:     2026-05-01
Username: aleksandar@inctime.com
Login:    https://www.ovh.com/manager/
Servers:  web-prod-01, db-master
Purpose:  Production application hosting
```

```
Name:     GitHub Team
Vendor:   GitHub
Category: SaaS
Cost:     $48.00 / annual
Next:     2026-08-15
Username: aleksandar
Login:    https://github.com
Purpose:  Git hosting, private repos, CI/CD minutes
```

## Uninstall

Delete `plugins/subscriptions/`. Optionally `DROP TABLE subscriptions;`
