<?php
/**
 * Sermony Vault Plugin — Encrypted Credential Storage
 *
 * Stores server credentials (username, password, SSH key, notes)
 * encrypted with AES-256-GCM. Encryption/decryption happens entirely
 * in the browser using the Web Crypto API. The server never sees the
 * vault key — it only stores opaque encrypted blobs.
 *
 * Share the vault key with trusted team members for shared access.
 * Key is stored in browser sessionStorage (cleared on tab close).
 */

// ── Vault DB helpers ─────────────────────────────────────────

function vaultDb(): void {
    @db()->exec('CREATE TABLE IF NOT EXISTS vault (
        server_id INTEGER PRIMARY KEY REFERENCES servers(id) ON DELETE CASCADE,
        encrypted TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\'))
    )');
}

function vaultGet(int $serverId): ?string {
    vaultDb();
    $s = db()->prepare('SELECT encrypted FROM vault WHERE server_id=:id');
    $s->bindValue(':id', $serverId, SQLITE3_INTEGER);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r['encrypted'] ?? null;
}

function vaultSave(int $serverId, string $encrypted): void {
    vaultDb();
    $s = db()->prepare('INSERT INTO vault (server_id, encrypted) VALUES (:id, :enc) ON CONFLICT(server_id) DO UPDATE SET encrypted=:enc, updated_at=strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\')');
    $s->bindValue(':id', $serverId, SQLITE3_INTEGER);
    $s->bindValue(':enc', $encrypted, SQLITE3_TEXT);
    $s->execute();
}

function vaultDelete(int $serverId): void {
    vaultDb();
    $s = db()->prepare('DELETE FROM vault WHERE server_id=:id');
    $s->bindValue(':id', $serverId, SQLITE3_INTEGER);
    $s->execute();
}

// ── Plugin definition ────────────────────────────────────────

return [
    'name'    => 'Credential Vault',
    'version' => '1.0',
    'author'  => 'Sermony',

    'hooks' => [

        // API endpoints for save/load/delete
        'custom_action' => function (string $action) {
            if (!str_starts_with($action, 'vault-')) return;

            if ($action === 'vault-get') {
                $id = (int)($_GET['id'] ?? 0);
                if ($id < 1) jsonErr('Invalid ID');
                $enc = vaultGet($id);
                jsonOut(['encrypted' => $enc, 'has_data' => $enc !== null]);
            }

            if ($action === 'vault-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $in = json_decode(file_get_contents('php://input'), true);
                if (!$in) jsonErr('Invalid JSON');
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $id = (int)($in['server_id'] ?? 0);
                $enc = (string)($in['encrypted'] ?? '');
                if ($id < 1 || $enc === '') jsonErr('Missing data');
                vaultSave($id, $enc);
                jsonOut(['ok' => true]);
            }

            if ($action === 'vault-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $in = json_decode(file_get_contents('php://input'), true);
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $id = (int)($in['server_id'] ?? 0);
                if ($id < 1) jsonErr('Invalid ID');
                vaultDelete($id);
                jsonOut(['ok' => true]);
            }
        },

        // Credentials UI on server detail page
        'server_detail' => function (array $server) {
            $sid = (int)$server['id'];
            ?>
            <div class="vault-section" id="vaultSection" data-server-id="<?=$sid?>">
                <div class="vault-header">
                    <strong>Credentials</strong>
                    <span id="vaultStatus" class="vault-locked">Locked</span>
                </div>
                <div id="vaultLocked" class="vault-prompt">
                    <input type="password" id="vaultKeyInput" placeholder="Enter vault key..." class="vault-input">
                    <button onclick="vaultUnlock()" class="btn-sm">Unlock</button>
                </div>
                <div id="vaultUnlocked" style="display:none">
                    <div class="vault-fields">
                        <label>Username
                            <div class="vault-field-row">
                                <input type="text" id="vaultUser" placeholder="ubuntu">
                                <button onclick="vaultCopy('vaultUser')" class="btn-sm">Copy</button>
                            </div>
                        </label>
                        <label>Password
                            <div class="vault-field-row">
                                <input type="password" id="vaultPass" placeholder="password">
                                <button onclick="vaultToggle('vaultPass',this)" class="btn-sm">Show</button>
                                <button onclick="vaultCopy('vaultPass')" class="btn-sm">Copy</button>
                            </div>
                        </label>
                        <label>SSH Key Path
                            <div class="vault-field-row">
                                <input type="text" id="vaultKey" placeholder="/home/user/.ssh/id_rsa">
                                <button onclick="vaultCopy('vaultKey')" class="btn-sm">Copy</button>
                            </div>
                        </label>
                        <label>Notes
                            <textarea id="vaultNotes" rows="2" placeholder="MySQL root pw, Webmin login, etc."></textarea>
                        </label>
                    </div>
                    <div class="vault-actions">
                        <button onclick="vaultSave()" class="btn-sm">Save</button>
                        <button onclick="vaultDelete()" class="btn-sm btn-sm-danger">Delete</button>
                        <button onclick="vaultLock()" class="btn-sm" style="margin-left:auto">Lock</button>
                    </div>
                </div>
            </div>
            <style>
            .vault-section{margin-top:.75rem;padding:.75rem 1rem;border:1px dashed var(--card-border);border-radius:8px;background:color-mix(in srgb,var(--card) 95%,var(--amber))}
            .vault-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;font-size:.85rem}
            .vault-locked{font-size:.7rem;color:var(--red);text-transform:uppercase;font-weight:600}
            .vault-unlocked{font-size:.7rem;color:var(--green);text-transform:uppercase;font-weight:600}
            .vault-prompt{display:flex;gap:.5rem;align-items:center}
            .vault-input{flex:1;padding:.35rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.82rem;background:var(--input-bg);color:var(--text)}
            .vault-fields label{display:block;font-size:.78rem;color:var(--muted);margin-top:.4rem}
            .vault-field-row{display:flex;gap:.4rem;align-items:center;margin-top:.2rem}
            .vault-field-row input{flex:1;padding:.3rem .5rem;border:1px solid var(--input-border);border-radius:4px;font-size:.82rem;background:var(--input-bg);color:var(--text)}
            .vault-fields textarea{width:100%;padding:.3rem .5rem;border:1px solid var(--input-border);border-radius:4px;font-size:.82rem;background:var(--input-bg);color:var(--text);margin-top:.2rem;font-family:inherit;resize:vertical}
            .vault-actions{display:flex;gap:.4rem;margin-top:.5rem;align-items:center}
            </style>
            <script>
            (function(){
                var VAULT_KEY_STORAGE='sermony-vault-key';
                var serverId=document.getElementById('vaultSection').dataset.serverId;
                var cryptoKey=null;

                // Derive AES-GCM key from password using PBKDF2
                async function deriveKey(password){
                    var enc=new TextEncoder();
                    var keyMaterial=await crypto.subtle.importKey('raw',enc.encode(password),{name:'PBKDF2'},false,['deriveKey']);
                    return crypto.subtle.deriveKey({name:'PBKDF2',salt:enc.encode('sermony-vault-salt'),iterations:100000,hash:'SHA-256'},keyMaterial,{name:'AES-GCM',length:256},false,['encrypt','decrypt']);
                }

                async function encrypt(data,key){
                    var iv=crypto.getRandomValues(new Uint8Array(12));
                    var enc=new TextEncoder();
                    var ct=await crypto.subtle.encrypt({name:'AES-GCM',iv:iv},key,enc.encode(JSON.stringify(data)));
                    // Combine iv + ciphertext, base64 encode
                    var buf=new Uint8Array(iv.length+ct.byteLength);
                    buf.set(iv);buf.set(new Uint8Array(ct),iv.length);
                    return btoa(String.fromCharCode.apply(null,buf));
                }

                async function decrypt(b64,key){
                    try{
                        var raw=Uint8Array.from(atob(b64),function(c){return c.charCodeAt(0)});
                        var iv=raw.slice(0,12);
                        var ct=raw.slice(12);
                        var pt=await crypto.subtle.decrypt({name:'AES-GCM',iv:iv},key,ct);
                        return JSON.parse(new TextDecoder().decode(pt));
                    }catch(e){return null}
                }

                window.vaultUnlock=async function(){
                    var pw=document.getElementById('vaultKeyInput').value;
                    if(!pw)return;
                    cryptoKey=await deriveKey(pw);
                    sessionStorage.setItem(VAULT_KEY_STORAGE,pw);
                    // Try loading existing data
                    var resp=await fetch('?action=vault-get&id='+serverId);
                    var data=await resp.json();
                    if(data.has_data&&data.encrypted){
                        var dec=await decrypt(data.encrypted,cryptoKey);
                        if(dec){
                            document.getElementById('vaultUser').value=dec.username||'';
                            document.getElementById('vaultPass').value=dec.password||'';
                            document.getElementById('vaultKey').value=dec.ssh_key||'';
                            document.getElementById('vaultNotes').value=dec.notes||'';
                        }else{
                            alert('Wrong vault key — cannot decrypt stored credentials.');
                            cryptoKey=null;sessionStorage.removeItem(VAULT_KEY_STORAGE);return;
                        }
                    }
                    document.getElementById('vaultLocked').style.display='none';
                    document.getElementById('vaultUnlocked').style.display='';
                    document.getElementById('vaultStatus').textContent='Unlocked';
                    document.getElementById('vaultStatus').className='vault-unlocked';
                };

                window.vaultLock=function(){
                    cryptoKey=null;
                    document.getElementById('vaultUser').value='';
                    document.getElementById('vaultPass').value='';
                    document.getElementById('vaultKey').value='';
                    document.getElementById('vaultNotes').value='';
                    document.getElementById('vaultLocked').style.display='';
                    document.getElementById('vaultUnlocked').style.display='none';
                    document.getElementById('vaultStatus').textContent='Locked';
                    document.getElementById('vaultStatus').className='vault-locked';
                    document.getElementById('vaultKeyInput').value='';
                };

                window.vaultSave=async function(){
                    if(!cryptoKey){alert('Vault not unlocked');return}
                    var data={
                        username:document.getElementById('vaultUser').value,
                        password:document.getElementById('vaultPass').value,
                        ssh_key:document.getElementById('vaultKey').value,
                        notes:document.getElementById('vaultNotes').value
                    };
                    var enc=await encrypt(data,cryptoKey);
                    var resp=await fetch('?action=vault-save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({server_id:parseInt(serverId),encrypted:enc})});
                    var r=await resp.json();
                    if(r.ok)alert('Credentials saved.');else alert('Save failed: '+(r.error||'unknown'));
                };

                window.vaultDelete=async function(){
                    if(!confirm('Delete stored credentials for this server?'))return;
                    await fetch('?action=vault-delete',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({server_id:parseInt(serverId)})});
                    vaultLock();
                    alert('Credentials deleted.');
                };

                window.vaultToggle=function(id,btn){
                    var el=document.getElementById(id);
                    if(el.type==='password'){el.type='text';btn.textContent='Hide'}
                    else{el.type='password';btn.textContent='Show'}
                };

                window.vaultCopy=function(id){
                    var el=document.getElementById(id);
                    navigator.clipboard.writeText(el.value).then(function(){
                        var orig=el.style.borderColor;el.style.borderColor='var(--green)';
                        setTimeout(function(){el.style.borderColor=orig},500);
                    });
                };

                // Auto-unlock if key is in sessionStorage
                var stored=sessionStorage.getItem(VAULT_KEY_STORAGE);
                if(stored){document.getElementById('vaultKeyInput').value=stored;vaultUnlock()}
            })();
            </script>
            <?php
        },

        // Show vault key status in header
        'header_links' => function () {
            echo '<span id="vaultIndicator" style="font-size:.75rem;color:#94a3b8;cursor:help" title="Vault: check server detail page">&#128274;</span>';
        },
    ],
];
