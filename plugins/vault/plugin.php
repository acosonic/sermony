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
    'url'     => 'https://github.com/acosonic/sermony/tree/master/plugins/vault',

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

            if ($action === 'vault-all') {
                if (function_exists('requireOwnerJson')) requireOwnerJson();
                vaultDb();
                $res = db()->query('SELECT server_id, encrypted FROM vault');
                $all = [];
                while ($r = $res->fetchArray(SQLITE3_ASSOC)) $all[] = $r;
                jsonOut(['entries' => $all]);
            }

            if ($action === 'vault-sample') {
                vaultDb();
                $r = db()->querySingle('SELECT encrypted FROM vault LIMIT 1', true);
                jsonOut(['encrypted' => $r['encrypted'] ?? null]);
            }

            if ($action === 'vault-rekey-stats') {
                if (function_exists('requireOwnerJson')) requireOwnerJson();
                vaultDb();
                $vault = (int)db()->querySingle('SELECT COUNT(*) FROM vault');
                $apiKeys = 0; $subs = 0;
                try { $apiKeys = (int)db()->querySingle('SELECT COUNT(*) FROM api_keys WHERE encrypted_key != ""'); } catch (Throwable $e) {}
                try { $subs = (int)db()->querySingle('SELECT COUNT(*) FROM subscriptions WHERE encrypted_password != ""'); } catch (Throwable $e) {}
                jsonOut(['vault' => $vault, 'api_keys' => $apiKeys, 'subscriptions' => $subs]);
            }

            if ($action === 'vault-audit-rekey' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                if (function_exists('requireOwnerJson')) requireOwnerJson();
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true) ?: [];
                $v = (int)($in['vault'] ?? 0);
                $a = (int)($in['api_keys'] ?? 0);
                $s = (int)($in['subscriptions'] ?? 0);
                $f = (int)($in['failed'] ?? 0);
                $details = "vault=$v, api_keys=$a, subscriptions=$s" . ($f > 0 ? ", failed=$f" : '');
                if (function_exists('audit')) audit('vault.rekey', $details);
                jsonOut(['ok' => true]);
            }

            if ($action === 'vault-bulk-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                if (function_exists('requireOwnerJson')) requireOwnerJson();
                $in = json_decode(file_get_contents('php://input'), true);
                if (!$in) jsonErr('Invalid JSON');
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                foreach ($in['entries'] ?? [] as $entry) {
                    $id = (int)($entry['server_id'] ?? 0);
                    $enc = (string)($entry['encrypted'] ?? '');
                    if ($id > 0 && $enc !== '') vaultSave($id, $enc);
                }
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
                    var key=await deriveKey(pw);
                    var resp=await fetch('?action=vault-get&id='+serverId);
                    var data=await resp.json();
                    if(data.has_data&&data.encrypted){
                        var dec=await decrypt(data.encrypted,key);
                        if(!dec){
                            alert('Wrong vault key — cannot decrypt stored credentials.');
                            sessionStorage.removeItem(VAULT_KEY_STORAGE);return;
                        }
                        document.getElementById('vaultUser').value=dec.username||'';
                        document.getElementById('vaultPass').value=dec.password||'';
                        document.getElementById('vaultKey').value=dec.ssh_key||'';
                        document.getElementById('vaultNotes').value=dec.notes||'';
                    }else{
                        // No data on this server — validate the key against any existing blob
                        // so we don't silently encrypt with a wrong/mismatched team key.
                        var sampleResp=await fetch('?action=vault-sample');
                        var sample=await sampleResp.json();
                        if(sample.encrypted){
                            var test=await decrypt(sample.encrypted,key);
                            if(!test){
                                alert('Wrong vault key — does not match the team key already in use.');
                                sessionStorage.removeItem(VAULT_KEY_STORAGE);return;
                            }
                        }
                        // else: vault is empty everywhere — first entry, accept the key.
                    }
                    cryptoKey=key;
                    sessionStorage.setItem(VAULT_KEY_STORAGE,pw);
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

        // Vault key management on settings page
        'settings_panel' => function () {
            $isOwner = function_exists('currentUserIsOwner') && currentUserIsOwner();
            ?>
            <fieldset style="margin-top:1rem">
                <legend>Credential Vault</legend>
                <div id="vaultSettings">
                    <label>Vault Key (for this session)
                        <div class="vault-field-row" style="margin-top:.25rem">
                            <input type="password" id="settingsVaultKey" placeholder="Enter vault key..." style="flex:1;padding:.35rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.82rem;background:var(--input-bg);color:var(--text)">
                            <button onclick="settingsVaultSet()" class="btn-sm">Set for Session</button>
                        </div>
                    </label>
                    <p style="font-size:.75rem;color:var(--subtle);margin-top:.25rem">Sets the vault key in your browser session. Use this instead of entering it on each server page.</p>

                    <?php if ($isOwner): ?>
                    <div style="margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--card-border)">
                        <strong style="font-size:.82rem">Change Vault Key</strong>
                        <p style="font-size:.75rem;color:var(--subtle);margin:.25rem 0">Re-encrypts all stored credentials, API keys, and subscription passwords with a new key. <strong>Owner only.</strong></p>
                        <div style="display:flex;flex-direction:column;gap:.4rem;margin-top:.4rem">
                            <input type="password" id="vaultOldKey" placeholder="Current vault key" style="padding:.35rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.82rem;background:var(--input-bg);color:var(--text)">
                            <input type="password" id="vaultNewKey" placeholder="New vault key" style="padding:.35rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.82rem;background:var(--input-bg);color:var(--text)">
                            <input type="password" id="vaultNewKey2" placeholder="Confirm new vault key" style="padding:.35rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.82rem;background:var(--input-bg);color:var(--text)">
                            <button onclick="vaultRekey()" class="btn-sm" style="align-self:flex-start">Change Vault Key</button>
                        </div>
                    </div>
                    <?php else: ?>
                    <p style="font-size:.75rem;color:var(--subtle);margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--card-border)">Changing the vault key requires the <strong>owner</strong> role.</p>
                    <?php endif; ?>
                </div>
            </fieldset>
            <script>
            (function(){
                var KEY='sermony-vault-key';

                // Show current status
                var stored=sessionStorage.getItem(KEY);
                if(stored)document.getElementById('settingsVaultKey').value=stored;

                window.settingsVaultSet=function(){
                    var pw=document.getElementById('settingsVaultKey').value;
                    if(!pw){sessionStorage.removeItem(KEY);alert('Vault key cleared.');return}
                    sessionStorage.setItem(KEY,pw);
                    alert('Vault key set for this session. It will be used automatically on server pages.');
                };

                async function deriveKey(password){
                    var enc=new TextEncoder();
                    var km=await crypto.subtle.importKey('raw',enc.encode(password),{name:'PBKDF2'},false,['deriveKey']);
                    return crypto.subtle.deriveKey({name:'PBKDF2',salt:enc.encode('sermony-vault-salt'),iterations:100000,hash:'SHA-256'},km,{name:'AES-GCM',length:256},false,['encrypt','decrypt']);
                }

                async function decrypt(b64,key){
                    var raw=Uint8Array.from(atob(b64),function(c){return c.charCodeAt(0)});
                    var iv=raw.slice(0,12),ct=raw.slice(12);
                    var pt=await crypto.subtle.decrypt({name:'AES-GCM',iv:iv},key,ct);
                    return JSON.parse(new TextDecoder().decode(pt));
                }

                async function encrypt(data,key){
                    var iv=crypto.getRandomValues(new Uint8Array(12));
                    var enc=new TextEncoder();
                    var ct=await crypto.subtle.encrypt({name:'AES-GCM',iv:iv},key,enc.encode(JSON.stringify(data)));
                    var buf=new Uint8Array(iv.length+ct.byteLength);
                    buf.set(iv);buf.set(new Uint8Array(ct),iv.length);
                    return btoa(String.fromCharCode.apply(null,buf));
                }

                // Encrypt a plain string (used for api_keys/subscriptions blobs)
                async function encryptStr(text,key){
                    if(!text)return '';
                    var iv=crypto.getRandomValues(new Uint8Array(12));
                    var ct=await crypto.subtle.encrypt({name:'AES-GCM',iv:iv},key,new TextEncoder().encode(text));
                    var buf=new Uint8Array(iv.length+ct.byteLength);buf.set(iv);buf.set(new Uint8Array(ct),iv.length);
                    return btoa(String.fromCharCode.apply(null,buf));
                }
                async function decryptStr(b64,key){
                    var raw=Uint8Array.from(atob(b64),function(c){return c.charCodeAt(0)});
                    var pt=await crypto.subtle.decrypt({name:'AES-GCM',iv:raw.slice(0,12)},key,raw.slice(12));
                    return new TextDecoder().decode(pt);
                }

                window.vaultRekey=async function(){
                    var oldPw=document.getElementById('vaultOldKey').value;
                    var newPw=document.getElementById('vaultNewKey').value;
                    var newPw2=document.getElementById('vaultNewKey2').value;
                    if(!oldPw||!newPw){alert('Enter both old and new vault keys.');return}
                    if(newPw!==newPw2){alert('New keys do not match.');return}
                    if(newPw.length<4){alert('New key too short (min 4 chars).');return}

                    // Show counts and confirm before doing anything destructive
                    var statsResp=await fetch('?action=vault-rekey-stats');
                    var stats=await statsResp.json();
                    var total=(stats.vault||0)+(stats.api_keys||0)+(stats.subscriptions||0);
                    if(total===0){alert('No encrypted data found anywhere — nothing to rekey.');return}
                    var msg='Re-encrypt all encrypted data with the new vault key?\n\n'+
                            '  • '+stats.vault+' server credential entries\n'+
                            '  • '+stats.api_keys+' API keys\n'+
                            '  • '+stats.subscriptions+' subscription passwords\n\n'+
                            'Anything that fails to decrypt with the OLD key will be skipped.';
                    if(!confirm(msg))return;

                    var oldKey=await deriveKey(oldPw);
                    var newKey=await deriveKey(newPw);

                    var totalFailed=0;
                    var totalOk={vault:0,api_keys:0,subscriptions:0};

                    // 1. vault — JSON-encoded credential objects
                    if(stats.vault>0){
                        var vAll=await(await fetch('?action=vault-all')).json();
                        var vOut=[];
                        for(var e of (vAll.entries||[])){
                            try{
                                var plain=await decrypt(e.encrypted,oldKey);
                                if(plain===null)throw new Error('decrypt returned null');
                                var enc=await encrypt(plain,newKey);
                                vOut.push({server_id:e.server_id,encrypted:enc});
                            }catch(err){totalFailed++;console.error('vault rekey failed for server',e.server_id,err)}
                        }
                        if(vOut.length>0){
                            await fetch('?action=vault-bulk-save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({entries:vOut})});
                            totalOk.vault=vOut.length;
                        }
                    }

                    // 2. api_keys — single-string blobs
                    if(stats.api_keys>0){
                        var akAll=await(await fetch('?action=api-keys-all')).json();
                        var akOut=[];
                        for(var e of (akAll.entries||[])){
                            try{
                                var plain=await decryptStr(e.encrypted_key,oldKey);
                                var enc=await encryptStr(plain,newKey);
                                akOut.push({id:e.id,encrypted_key:enc});
                            }catch(err){totalFailed++;console.error('api-keys rekey failed for id',e.id,err)}
                        }
                        if(akOut.length>0){
                            var rs=await(await fetch('?action=api-keys-bulk-rekey',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({entries:akOut})})).json();
                            totalOk.api_keys=rs.updated||akOut.length;
                        }
                    }

                    // 3. subscriptions — single-string blobs
                    if(stats.subscriptions>0){
                        var sbAll=await(await fetch('?action=subscriptions-all')).json();
                        var sbOut=[];
                        for(var e of (sbAll.entries||[])){
                            try{
                                var plain=await decryptStr(e.encrypted_password,oldKey);
                                var enc=await encryptStr(plain,newKey);
                                sbOut.push({id:e.id,encrypted_password:enc});
                            }catch(err){totalFailed++;console.error('subscriptions rekey failed for id',e.id,err)}
                        }
                        if(sbOut.length>0){
                            var rs=await(await fetch('?action=subscriptions-bulk-rekey',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({entries:sbOut})})).json();
                            totalOk.subscriptions=rs.updated||sbOut.length;
                        }
                    }

                    var totalReEncrypted=totalOk.vault+totalOk.api_keys+totalOk.subscriptions;
                    if(totalReEncrypted===0){
                        alert('Could not decrypt any entries. Wrong old key?');
                        return;
                    }

                    // Update sessionStorage and audit log
                    sessionStorage.setItem(KEY,newPw);
                    await fetch('?action=vault-audit-rekey',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({vault:totalOk.vault,api_keys:totalOk.api_keys,subscriptions:totalOk.subscriptions,failed:totalFailed})});

                    var summary='Vault key changed.\n\n'+
                                '  • '+totalOk.vault+' server credentials re-encrypted\n'+
                                '  • '+totalOk.api_keys+' API keys re-encrypted\n'+
                                '  • '+totalOk.subscriptions+' subscriptions re-encrypted'+
                                (totalFailed?'\n\n'+totalFailed+' entries failed (could not decrypt with old key).':'');
                    alert(summary);
                    document.getElementById('settingsVaultKey').value=newPw;
                    document.getElementById('vaultOldKey').value='';
                    document.getElementById('vaultNewKey').value='';
                    document.getElementById('vaultNewKey2').value='';
                };
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
