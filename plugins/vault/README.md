# Credential Vault Plugin

Encrypted credential storage for Sermony. Store server logins (username, password, SSH key, notes) with client-side AES-256-GCM encryption. The server never sees your vault key.

## How It Works

1. Go to any server's detail page
2. Enter your **vault key** (a password you choose)
3. Fill in credentials — username, password, SSH key path, notes
4. Click **Save** — encrypted in your browser, stored as an opaque blob on the server
5. Next visit — enter the same vault key to decrypt and view

## Security

- **AES-256-GCM** encryption via the browser's Web Crypto API
- **PBKDF2** key derivation (100,000 iterations, SHA-256)
- **Server stores only encrypted blobs** — useless without the vault key
- **Vault key never leaves the browser** — not in requests, logs, or database
- **Wrong key = decryption fails** with a clear error message
- **sessionStorage** — key is kept for the browser tab session, cleared on tab close
- **CSRF protected** on all write/delete operations

## Shared Access

Share the vault key with your team through a secure channel (in person, encrypted chat, etc.). Anyone with the key can view and edit credentials. There's one shared key for all servers — simple and practical.

## Installation

Already included with Sermony. If you removed it, copy the `vault` folder back into `plugins/`:

```
plugins/
  vault/
    plugin.php
    README.md
```

No configuration needed. The vault table is auto-created on first use.

## Usage

### Storing Credentials

1. Open a server's detail page
2. Enter your vault key and click **Unlock**
3. Fill in the fields:
   - **Username** — SSH login user
   - **Password** — SSH or sudo password
   - **SSH Key Path** — path to private key on your machine
   - **Notes** — anything else (MySQL root pw, Webmin login, API keys, etc.)
4. Click **Save**

### Viewing Credentials

1. Open the server's detail page
2. Enter the vault key — if it's still in your session, it auto-unlocks
3. Click **Show** next to the password field to reveal it
4. Click **Copy** to copy any field to clipboard

### Deleting Credentials

1. Unlock the vault on the server's detail page
2. Click **Delete** — removes the encrypted blob from the server

### Locking

Click **Lock** to clear credentials from the page. The vault key stays in sessionStorage until the tab is closed.

## Changing the Vault Key

Go to **Settings → Credential Vault → Change Vault Key**. Enter the current key and the new key (twice).

Because the same vault key is shared across the **Credential Vault**, **API Keys**, and **Subscriptions** plugins, the rekey re-encrypts data in all three:

1. Browser fetches counts of each (`vault-rekey-stats`) and shows a confirmation with the totals.
2. On confirm, browser fetches every encrypted blob from each plugin (`vault-all`, `api-keys-all`, `subscriptions-all`).
3. Decrypts each with the old key and re-encrypts with the new key.
4. Bulk-saves back via `vault-bulk-save`, `api-keys-bulk-rekey`, `subscriptions-bulk-rekey`.
5. Updates your sessionStorage to the new key.
6. Writes a `vault.rekey` entry to the audit log with the per-plugin counts.

Entries that fail to decrypt with the old key are skipped and reported in the summary. The server never sees either key — the entire re-encryption happens in your browser.

## Technical Details

- Encryption: AES-256-GCM (Web Crypto API)
- Key derivation: PBKDF2 with SHA-256, 100k iterations, static salt
- Storage: `vault` table in Sermony's SQLite database
- Format: Base64-encoded (12-byte IV + ciphertext)
- Server endpoints: `vault-get`, `vault-save`, `vault-delete`
- Key storage: browser `sessionStorage` (per-tab, cleared on close)

## Limitations

- No per-user access control — anyone with the vault key can see all credentials
- Static PBKDF2 salt — acceptable for this threat model since the key is never sent to the server
- No key rotation tool — manual re-key process (see above)
- No audit log of credential access (could be added as a separate plugin)
