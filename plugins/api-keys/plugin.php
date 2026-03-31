<?php
/**
 * Sermony API Key Manager Plugin
 *
 * Track API keys across servers — where they're used, spending,
 * expiry, purpose. Keys are encrypted client-side using the same
 * vault crypto (AES-256-GCM + PBKDF2) as the Credential Vault.
 */

function apiKeysDb(): void {
    @db()->exec('CREATE TABLE IF NOT EXISTS api_keys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        provider TEXT DEFAULT "",
        encrypted_key TEXT DEFAULT "",
        servers TEXT DEFAULT "[]",
        paths TEXT DEFAULT "[]",
        purpose TEXT DEFAULT "",
        cost_url TEXT DEFAULT "",
        issued_at TEXT DEFAULT "",
        expires_at TEXT DEFAULT "",
        monthly_budget REAL DEFAULT 0,
        notes TEXT DEFAULT "",
        created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\')),
        updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\'))
    )');
}

return [
    'name'    => 'API Key Manager',
    'version' => '1.0',
    'author'  => 'Sermony',
    'url'     => 'https://github.com/acosonic/sermony/tree/master/plugins/api-keys',

    'hooks' => [

        // Add nav link
        'header_links' => function () {
            echo '<a href="?action=api-keys">API Keys</a>';
        },

        // Handle all API key routes
        'custom_action' => function (string $action) {
            if (!str_starts_with($action, 'api-key')) return;
            apiKeysDb();
            $d = db();

            // ── JSON API endpoints ───────────────────────
            if ($action === 'api-keys-list') {
                $res = $d->query('SELECT * FROM api_keys ORDER BY provider, name');
                $keys = [];
                while ($r = $res->fetchArray(SQLITE3_ASSOC)) $keys[] = $r;
                jsonOut(['keys' => $keys]);
            }

            if ($action === 'api-keys-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true);
                if (!$in) jsonErr('Invalid JSON');

                $id = (int)($in['id'] ?? 0);
                if ($id > 0) {
                    $s = $d->prepare('UPDATE api_keys SET name=:n, provider=:p, encrypted_key=:ek, servers=:srv, paths=:pa, purpose=:pu, cost_url=:cu, issued_at=:ia, expires_at=:ea, monthly_budget=:mb, notes=:no, updated_at=strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\') WHERE id=:id');
                    $s->bindValue(':id', $id, SQLITE3_INTEGER);
                } else {
                    $s = $d->prepare('INSERT INTO api_keys (name, provider, encrypted_key, servers, paths, purpose, cost_url, issued_at, expires_at, monthly_budget, notes) VALUES (:n, :p, :ek, :srv, :pa, :pu, :cu, :ia, :ea, :mb, :no)');
                }
                $s->bindValue(':n', trim($in['name'] ?? ''));
                $s->bindValue(':p', trim($in['provider'] ?? ''));
                $s->bindValue(':ek', $in['encrypted_key'] ?? '');
                $s->bindValue(':srv', json_encode($in['servers'] ?? []));
                $s->bindValue(':pa', json_encode($in['paths'] ?? []));
                $s->bindValue(':pu', trim($in['purpose'] ?? ''));
                $s->bindValue(':cu', trim($in['cost_url'] ?? ''));
                $s->bindValue(':ia', trim($in['issued_at'] ?? ''));
                $s->bindValue(':ea', trim($in['expires_at'] ?? ''));
                $s->bindValue(':mb', (float)($in['monthly_budget'] ?? 0));
                $s->bindValue(':no', trim($in['notes'] ?? ''));
                $s->execute();
                $newId = $id > 0 ? $id : (int)$d->lastInsertRowID();
                jsonOut(['ok' => true, 'id' => $newId]);
            }

            if ($action === 'api-keys-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true);
                $id = (int)($in['id'] ?? 0);
                if ($id > 0) {
                    $s = $d->prepare('DELETE FROM api_keys WHERE id=:id');
                    $s->bindValue(':id', $id, SQLITE3_INTEGER);
                    $s->execute();
                }
                jsonOut(['ok' => true]);
            }

            // ── Main page ────────────────────────────────
            if ($action === 'api-keys') {
                // Get server list for the dropdown
                $servers = [];
                $res = $d->query('SELECT id, hostname, display_name FROM servers ORDER BY COALESCE(display_name, hostname)');
                while ($r = $res->fetchArray(SQLITE3_ASSOC)) $servers[] = $r;

                pageTop('API Keys');
                ?>
                <div class="detail-header">
                    <a href="?" class="back">&larr; Dashboard</a>
                    <h1 style="margin:.75rem 0">API Key Manager</h1>

                    <div id="akLocked" class="vault-prompt" style="margin-bottom:1rem">
                        <input type="password" id="akVaultKey" placeholder="Enter vault key to decrypt API keys..." style="flex:1;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text)">
                        <button onclick="akUnlock()" class="btn-sm">Unlock</button>
                    </div>

                    <div id="akUnlocked" style="display:none">
                        <div style="display:flex;gap:.5rem;margin-bottom:1rem;align-items:center">
                            <input type="text" id="akSearch" placeholder="Search keys..." oninput="akFilter(this.value)" style="flex:1;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text)">
                            <button onclick="akShowForm()" class="btn-primary" style="padding:.4rem 1rem;font-size:.85rem">+ Add Key</button>
                        </div>

                        <!-- Key form (hidden by default) -->
                        <div id="akForm" style="display:none;margin-bottom:1rem;padding:1rem;border:1px solid var(--card-border);border-radius:8px;background:var(--card)">
                            <input type="hidden" id="akEditId" value="0">
                            <div class="field-row">
                                <label>Name <input type="text" id="akName" placeholder="AWS Production"></label>
                                <label>Provider <input type="text" id="akProvider" placeholder="AWS, Cloudflare, Stripe..."></label>
                            </div>
                            <div class="field-row" style="margin-top:.5rem">
                                <label>API Key / Secret
                                    <div style="display:flex;gap:.4rem">
                                        <input type="password" id="akKeyValue" placeholder="AKIA... or sk-..." style="flex:1">
                                        <button onclick="akToggleKey()" class="btn-sm">Show</button>
                                    </div>
                                </label>
                                <label>Purpose <input type="text" id="akPurpose" placeholder="Email sending, backups..."></label>
                            </div>
                            <div class="field-row" style="margin-top:.5rem">
                                <label>Issued Date <input type="date" id="akIssued"></label>
                                <label>Expires Date <input type="date" id="akExpires"></label>
                            </div>
                            <div class="field-row" style="margin-top:.5rem">
                                <label>Cost Tracking URL <input type="text" id="akCostUrl" placeholder="https://console.aws.amazon.com/billing/..."></label>
                                <label>Monthly Budget ($) <input type="number" id="akBudget" step="0.01" placeholder="0.00"></label>
                            </div>
                            <label style="margin-top:.5rem">Servers Used On
                                <select id="akServers" multiple style="width:100%;min-height:80px;padding:.3rem;border:1px solid var(--input-border);border-radius:6px;background:var(--input-bg);color:var(--text);font-size:.82rem">
                                    <?php foreach ($servers as $srv): ?>
                                    <option value="<?=$srv['id']?>"><?=e($srv['display_name'] ?: $srv['hostname'])?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label style="margin-top:.5rem">Paths / Folders (one per line)
                                <textarea id="akPaths" rows="2" placeholder="/var/www/app/.env&#10;/opt/service/config.json" style="width:100%;padding:.3rem .5rem;border:1px solid var(--input-border);border-radius:6px;font-size:.82rem;background:var(--input-bg);color:var(--text);font-family:inherit"></textarea>
                            </label>
                            <label style="margin-top:.5rem">Notes
                                <textarea id="akNotes" rows="2" placeholder="Additional info..." style="width:100%;padding:.3rem .5rem;border:1px solid var(--input-border);border-radius:6px;font-size:.82rem;background:var(--input-bg);color:var(--text);font-family:inherit"></textarea>
                            </label>
                            <div style="display:flex;gap:.5rem;margin-top:.75rem">
                                <button onclick="akSave()" class="btn-primary" style="padding:.4rem 1rem;font-size:.85rem">Save</button>
                                <button onclick="akCancelForm()" class="btn-secondary" style="padding:.4rem 1rem;font-size:.85rem">Cancel</button>
                            </div>
                        </div>

                        <!-- Key list -->
                        <div id="akList"></div>
                    </div>
                </div>

                <style>
                .ak-card{background:var(--card);border:1px solid var(--card-border);border-radius:8px;padding:1rem 1.25rem;margin-bottom:.75rem}
                .ak-card:hover{box-shadow:0 2px 8px var(--card-hover)}
                .ak-header{display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem}
                .ak-name{font-weight:600;font-size:1rem;flex:1}
                .ak-provider{font-size:.75rem;background:var(--code-bg);padding:.15rem .4rem;border-radius:4px;color:var(--muted)}
                .ak-meta{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.3rem .75rem;font-size:.82rem;color:var(--muted)}
                .ak-meta strong{color:var(--text)}
                .ak-key-row{display:flex;align-items:center;gap:.4rem;margin:.4rem 0;font-size:.82rem}
                .ak-key-val{font-family:monospace;background:var(--code-bg);padding:.2rem .4rem;border-radius:4px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
                .ak-tags{display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.4rem}
                .ak-tag{font-size:.7rem;background:var(--code-bg);padding:.1rem .35rem;border-radius:4px;color:var(--muted)}
                .ak-tag-server{border-left:2px solid var(--blue)}
                .ak-tag-path{border-left:2px solid var(--amber)}
                .ak-expired{border-left:3px solid var(--red)}
                .ak-expiring{border-left:3px solid var(--amber)}
                .ak-actions{display:flex;gap:.3rem;margin-top:.5rem}
                </style>

                <script>
                (function(){
                    var VAULT_KEY='sermony-vault-key';
                    var cryptoKey=null;
                    var allKeys=[];
                    var serverMap=<?=json_encode(array_column($servers, null, 'id'))?>;

                    async function deriveKey(pw){
                        var enc=new TextEncoder();
                        var km=await crypto.subtle.importKey('raw',enc.encode(pw),{name:'PBKDF2'},false,['deriveKey']);
                        return crypto.subtle.deriveKey({name:'PBKDF2',salt:enc.encode('sermony-vault-salt'),iterations:100000,hash:'SHA-256'},km,{name:'AES-GCM',length:256},false,['encrypt','decrypt']);
                    }
                    async function encrypt(text,key){
                        if(!text)return '';
                        var iv=crypto.getRandomValues(new Uint8Array(12));
                        var ct=await crypto.subtle.encrypt({name:'AES-GCM',iv:iv},key,new TextEncoder().encode(text));
                        var buf=new Uint8Array(iv.length+ct.byteLength);buf.set(iv);buf.set(new Uint8Array(ct),iv.length);
                        return btoa(String.fromCharCode.apply(null,buf));
                    }
                    async function decrypt(b64,key){
                        if(!b64)return '';
                        try{var raw=Uint8Array.from(atob(b64),function(c){return c.charCodeAt(0)});
                        var pt=await crypto.subtle.decrypt({name:'AES-GCM',iv:raw.slice(0,12)},key,raw.slice(12));
                        return new TextDecoder().decode(pt)}catch(e){return '[decrypt failed]'}
                    }

                    window.akUnlock=async function(){
                        var pw=document.getElementById('akVaultKey').value||sessionStorage.getItem(VAULT_KEY);
                        if(!pw){alert('Enter vault key');return}
                        cryptoKey=await deriveKey(pw);
                        sessionStorage.setItem(VAULT_KEY,pw);
                        document.getElementById('akLocked').style.display='none';
                        document.getElementById('akUnlocked').style.display='';
                        akLoadList();
                    };

                    async function akLoadList(){
                        var resp=await fetch('?action=api-keys-list');
                        var data=await resp.json();
                        allKeys=data.keys||[];
                        // Decrypt keys
                        for(var k of allKeys) k._decrypted=await decrypt(k.encrypted_key,cryptoKey);
                        akRender(allKeys);
                    }

                    function akRender(keys){
                        var html='';
                        if(!keys.length){html='<p style="text-align:center;color:var(--muted);padding:2rem">No API keys stored yet.</p>'}
                        var now=new Date().toISOString().split('T')[0];
                        var soon=new Date(Date.now()+30*86400000).toISOString().split('T')[0];
                        for(var k of keys){
                            var servers=JSON.parse(k.servers||'[]');
                            var paths=JSON.parse(k.paths||'[]');
                            var expired=k.expires_at&&k.expires_at<now;
                            var expiring=k.expires_at&&!expired&&k.expires_at<soon;
                            var cls='ak-card'+(expired?' ak-expired':'')+(expiring?' ak-expiring':'');
                            html+='<div class="'+cls+'" data-search="'+esc(k.name+' '+k.provider+' '+k.purpose+' '+(k._decrypted||'')+' '+k.notes).toLowerCase()+'">';
                            html+='<div class="ak-header"><span class="ak-name">'+esc(k.name)+'</span>';
                            if(k.provider)html+='<span class="ak-provider">'+esc(k.provider)+'</span>';
                            if(expired)html+='<span class="badge badge-off">EXPIRED</span>';
                            else if(expiring)html+='<span class="badge badge-warn">EXPIRING</span>';
                            html+='</div>';
                            if(k._decrypted){
                                html+='<div class="ak-key-row"><code class="ak-key-val" id="akv'+k.id+'">'+esc(k._decrypted)+'</code>';
                                html+='<button onclick="navigator.clipboard.writeText(document.getElementById(\'akv'+k.id+'\').textContent)" class="btn-sm">Copy</button></div>';
                            }
                            html+='<div class="ak-meta">';
                            if(k.purpose)html+='<span><strong>Purpose:</strong> '+esc(k.purpose)+'</span>';
                            if(k.issued_at)html+='<span><strong>Issued:</strong> '+esc(k.issued_at)+'</span>';
                            if(k.expires_at)html+='<span><strong>Expires:</strong> '+esc(k.expires_at)+'</span>';
                            if(k.monthly_budget>0)html+='<span><strong>Budget:</strong> $'+Number(k.monthly_budget).toFixed(2)+'/mo</span>';
                            if(k.cost_url)html+='<span><strong>Cost:</strong> <a href="'+esc(k.cost_url)+'" target="_blank">View Spending</a></span>';
                            if(k.notes)html+='<span><strong>Notes:</strong> '+esc(k.notes)+'</span>';
                            html+='</div>';
                            if(servers.length||paths.length){
                                html+='<div class="ak-tags">';
                                for(var sid of servers){var s=serverMap[sid];if(s)html+='<span class="ak-tag ak-tag-server">'+esc(s.display_name||s.hostname)+'</span>'}
                                for(var p of paths)if(p)html+='<span class="ak-tag ak-tag-path">'+esc(p)+'</span>';
                                html+='</div>';
                            }
                            html+='<div class="ak-actions">';
                            html+='<button onclick="akEdit('+k.id+')" class="btn-sm">Edit</button>';
                            html+='<button onclick="akDel('+k.id+')" class="btn-sm btn-sm-danger">Delete</button>';
                            html+='</div></div>';
                        }
                        document.getElementById('akList').innerHTML=html;
                    }

                    function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML}

                    window.akFilter=function(q){
                        q=q.toLowerCase();
                        document.querySelectorAll('.ak-card').forEach(function(c){
                            c.style.display=(!q||c.dataset.search.indexOf(q)>=0)?'':'none';
                        });
                    };

                    window.akShowForm=function(data){
                        data=data||{};
                        document.getElementById('akEditId').value=data.id||0;
                        document.getElementById('akName').value=data.name||'';
                        document.getElementById('akProvider').value=data.provider||'';
                        document.getElementById('akKeyValue').value=data._decrypted||'';
                        document.getElementById('akKeyValue').type='password';
                        document.getElementById('akPurpose').value=data.purpose||'';
                        document.getElementById('akIssued').value=data.issued_at||'';
                        document.getElementById('akExpires').value=data.expires_at||'';
                        document.getElementById('akCostUrl').value=data.cost_url||'';
                        document.getElementById('akBudget').value=data.monthly_budget||'';
                        document.getElementById('akNotes').value=data.notes||'';
                        // Set selected servers
                        var srvs=data.servers||[];
                        if(typeof srvs==='string')try{srvs=JSON.parse(srvs)}catch(e){srvs=[]}
                        var sel=document.getElementById('akServers');
                        for(var o of sel.options)o.selected=srvs.indexOf(parseInt(o.value))>=0;
                        // Set paths
                        var paths=data.paths||[];
                        if(typeof paths==='string')try{paths=JSON.parse(paths)}catch(e){paths=[]}
                        document.getElementById('akPaths').value=paths.join('\n');
                        document.getElementById('akForm').style.display='';
                        window.scrollTo({top:document.getElementById('akForm').offsetTop-80,behavior:'smooth'});
                    };

                    window.akCancelForm=function(){document.getElementById('akForm').style.display='none'};
                    window.akToggleKey=function(){var el=document.getElementById('akKeyValue');el.type=el.type==='password'?'text':'password'};

                    window.akEdit=function(id){
                        var k=allKeys.find(function(x){return x.id==id});
                        if(k)akShowForm(k);
                    };

                    window.akSave=async function(){
                        var name=document.getElementById('akName').value.trim();
                        if(!name){alert('Name is required');return}
                        var keyVal=document.getElementById('akKeyValue').value;
                        var encKey=await encrypt(keyVal,cryptoKey);
                        var selSrvs=Array.from(document.getElementById('akServers').selectedOptions).map(function(o){return parseInt(o.value)});
                        var paths=document.getElementById('akPaths').value.split('\n').map(function(s){return s.trim()}).filter(Boolean);
                        var payload={
                            id:parseInt(document.getElementById('akEditId').value)||0,
                            name:name,
                            provider:document.getElementById('akProvider').value.trim(),
                            encrypted_key:encKey,
                            servers:selSrvs,
                            paths:paths,
                            purpose:document.getElementById('akPurpose').value.trim(),
                            cost_url:document.getElementById('akCostUrl').value.trim(),
                            issued_at:document.getElementById('akIssued').value,
                            expires_at:document.getElementById('akExpires').value,
                            monthly_budget:parseFloat(document.getElementById('akBudget').value)||0,
                            notes:document.getElementById('akNotes').value.trim()
                        };
                        var resp=await fetch('?action=api-keys-save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify(payload)});
                        var r=await resp.json();
                        if(r.ok){akCancelForm();akLoadList()}else{alert('Save failed: '+(r.error||'unknown'))}
                    };

                    window.akDel=async function(id){
                        if(!confirm('Delete this API key?'))return;
                        await fetch('?action=api-keys-delete',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({id:id})});
                        akLoadList();
                    };

                    // Auto-unlock if vault key in session
                    var stored=sessionStorage.getItem(VAULT_KEY);
                    if(stored){document.getElementById('akVaultKey').value=stored;akUnlock()}
                })();
                </script>
                <?php
                pageBottom();
                exit;
            }
        },

        // Make API keys searchable from dashboard (adds provider names to search)
        'search_data' => function (array $parts, array $server): array {
            apiKeysDb();
            $res = db()->query('SELECT name, provider, servers FROM api_keys');
            while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                $srvs = json_decode($r['servers'] ?? '[]', true);
                if (in_array((int)$server['id'], $srvs)) {
                    $parts[] = 'apikey';
                    $parts[] = $r['name'] ?? '';
                    $parts[] = $r['provider'] ?? '';
                }
            }
            return $parts;
        },

        // Show API key count on server detail
        'server_detail' => function (array $server) {
            apiKeysDb();
            $res = db()->query('SELECT id, name, provider, servers FROM api_keys');
            $matches = [];
            while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                $srvs = json_decode($r['servers'] ?? '[]', true);
                if (in_array((int)$server['id'], $srvs)) $matches[] = $r;
            }
            if (empty($matches)) return;
            echo '<div style="margin-top:.75rem;padding:.5rem .75rem;border:1px solid var(--card-border);border-radius:6px;font-size:.82rem">';
            echo '<strong>API Keys (' . count($matches) . '):</strong> ';
            $parts = [];
            foreach ($matches as $m) {
                $label = e($m['name']);
                if ($m['provider']) $label .= ' <span style="color:var(--muted)">(' . e($m['provider']) . ')</span>';
                $parts[] = '<a href="?action=api-keys">' . $label . '</a>';
            }
            echo implode(', ', $parts);
            echo '</div>';
        },
    ],
];
