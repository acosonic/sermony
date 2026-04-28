<?php
/**
 * Sermony Subscriptions Plugin
 *
 * Track services/subscriptions you pay for: cost, billing cycle,
 * credentials (encrypted), linked servers. Calculates total monthly
 * cost. Uses same vault key as Credential Vault / API Keys plugins.
 */

function subsDb(): void {
    @db()->exec('CREATE TABLE IF NOT EXISTS subscriptions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        vendor TEXT DEFAULT "",
        category TEXT DEFAULT "",
        cost REAL DEFAULT 0,
        currency TEXT DEFAULT "USD",
        billing_cycle TEXT DEFAULT "monthly",
        next_renewal TEXT DEFAULT "",
        username TEXT DEFAULT "",
        encrypted_password TEXT DEFAULT "",
        login_url TEXT DEFAULT "",
        servers TEXT DEFAULT "[]",
        purpose TEXT DEFAULT "",
        notes TEXT DEFAULT "",
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\')),
        updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\'))
    )');
}

/** Convert any billing cycle to monthly cost */
function subsMonthlyCost(float $cost, string $cycle): float {
    return match ($cycle) {
        'daily'      => $cost * 30,
        'weekly'     => $cost * 4.33,
        'monthly'    => $cost,
        'quarterly'  => $cost / 3,
        'biannual'   => $cost / 6,
        'annual'     => $cost / 12,
        'biennial'   => $cost / 24,
        'lifetime'   => 0,
        default      => $cost,
    };
}

return [
    'name'    => 'Subscriptions',
    'version' => '1.0',
    'author'  => 'Sermony',
    'url'     => 'https://github.com/acosonic/sermony/tree/master/plugins/subscriptions',

    'hooks' => [

        'header_links' => function () {
            echo '<a href="?action=subscriptions">Subs</a>';
        },

        'custom_action' => function (string $action) {
            if (!str_starts_with($action, 'subscription')) return;
            subsDb();
            $d = db();

            // ── JSON API endpoints ─────────────────────────

            if ($action === 'subscriptions-list') {
                $res = $d->query('SELECT * FROM subscriptions ORDER BY active DESC, vendor, name');
                $items = [];
                $totalMonthly = 0;
                $totalAnnual = 0;
                while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                    if ($r['active']) {
                        $monthly = subsMonthlyCost((float)$r['cost'], $r['billing_cycle']);
                        $totalMonthly += $monthly;
                        $totalAnnual += $monthly * 12;
                        $r['_monthly_equiv'] = round($monthly, 2);
                    }
                    $items[] = $r;
                }
                jsonOut([
                    'subscriptions' => $items,
                    'total_monthly' => round($totalMonthly, 2),
                    'total_annual'  => round($totalAnnual, 2),
                ]);
            }

            if ($action === 'subscriptions-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true);
                if (!$in) jsonErr('Invalid JSON');
                $name = trim($in['name'] ?? '');
                if ($name === '') jsonErr('Name required');

                $id = (int)($in['id'] ?? 0);
                $cycle = in_array($in['billing_cycle'] ?? '', ['daily','weekly','monthly','quarterly','biannual','annual','biennial','lifetime']) ? $in['billing_cycle'] : 'monthly';

                if ($id > 0) {
                    $s = $d->prepare('UPDATE subscriptions SET name=:n, vendor=:v, category=:c, cost=:co, currency=:cu, billing_cycle=:bc, next_renewal=:nr, username=:un, encrypted_password=:ep, login_url=:lu, servers=:sv, purpose=:pu, notes=:no, active=:a, updated_at=strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\') WHERE id=:id');
                    $s->bindValue(':id', $id, SQLITE3_INTEGER);
                } else {
                    $s = $d->prepare('INSERT INTO subscriptions (name, vendor, category, cost, currency, billing_cycle, next_renewal, username, encrypted_password, login_url, servers, purpose, notes, active) VALUES (:n,:v,:c,:co,:cu,:bc,:nr,:un,:ep,:lu,:sv,:pu,:no,:a)');
                }
                $s->bindValue(':n',  $name);
                $s->bindValue(':v',  trim($in['vendor'] ?? ''));
                $s->bindValue(':c',  trim($in['category'] ?? ''));
                $s->bindValue(':co', (float)($in['cost'] ?? 0));
                $s->bindValue(':cu', strtoupper(trim($in['currency'] ?? 'USD')));
                $s->bindValue(':bc', $cycle);
                $s->bindValue(':nr', trim($in['next_renewal'] ?? ''));
                $s->bindValue(':un', trim($in['username'] ?? ''));
                $s->bindValue(':ep', $in['encrypted_password'] ?? '');
                $s->bindValue(':lu', trim($in['login_url'] ?? ''));
                $s->bindValue(':sv', json_encode($in['servers'] ?? []));
                $s->bindValue(':pu', trim($in['purpose'] ?? ''));
                $s->bindValue(':no', trim($in['notes'] ?? ''));
                $s->bindValue(':a',  empty($in['active']) ? 0 : 1, SQLITE3_INTEGER);
                $s->execute();
                jsonOut(['ok' => true, 'id' => $id > 0 ? $id : (int)$d->lastInsertRowID()]);
            }

            if ($action === 'subscriptions-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true);
                $id = (int)($in['id'] ?? 0);
                if ($id > 0) { $s = $d->prepare('DELETE FROM subscriptions WHERE id=:id'); $s->bindValue(':id', $id, SQLITE3_INTEGER); $s->execute(); }
                jsonOut(['ok' => true]);
            }

            // ── Vault rekey support (owner only) ──────────
            if ($action === 'subscriptions-all') {
                if (function_exists('requireOwnerJson')) requireOwnerJson();
                $res = $d->query('SELECT id, encrypted_password FROM subscriptions WHERE encrypted_password != ""');
                $rows = [];
                while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
                jsonOut(['entries' => $rows]);
            }

            if ($action === 'subscriptions-bulk-rekey' && $_SERVER['REQUEST_METHOD'] === 'POST') {
                if (function_exists('requireOwnerJson')) requireOwnerJson();
                $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!$hdr || !hash_equals(csrfToken(), $hdr)) jsonErr('Invalid CSRF', 403);
                $in = json_decode(file_get_contents('php://input'), true);
                $count = 0;
                $s = $d->prepare('UPDATE subscriptions SET encrypted_password=:ep, updated_at=strftime(\'%Y-%m-%dT%H:%M:%SZ\',\'now\') WHERE id=:id');
                foreach ($in['entries'] ?? [] as $entry) {
                    $id = (int)($entry['id'] ?? 0);
                    $ep = (string)($entry['encrypted_password'] ?? '');
                    if ($id > 0 && $ep !== '') {
                        $s->bindValue(':id', $id, SQLITE3_INTEGER);
                        $s->bindValue(':ep', $ep, SQLITE3_TEXT);
                        $s->execute();
                        $s->reset();
                        $count++;
                    }
                }
                jsonOut(['ok' => true, 'updated' => $count]);
            }

            // ── Main page ──────────────────────────────────

            if ($action === 'subscriptions') {
                $servers = [];
                $res = $d->query('SELECT id, hostname, display_name FROM servers ORDER BY COALESCE(display_name, hostname)');
                while ($r = $res->fetchArray(SQLITE3_ASSOC)) $servers[] = $r;

                pageTop('Subscriptions');
                ?>
                <div class="detail-header">
                    <a href="?" class="back">&larr; Dashboard</a>
                    <h1 style="margin:.75rem 0">Subscriptions</h1>

                    <div id="sbLocked" class="vault-prompt" style="margin-bottom:1rem;display:flex;gap:.5rem">
                        <input type="password" id="sbVaultKey" placeholder="Enter vault key to decrypt passwords..." style="flex:1;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text)">
                        <button onclick="sbUnlock()" class="btn-sm">Unlock</button>
                    </div>

                    <div id="sbUnlocked" style="display:none">
                        <!-- Totals -->
                        <div id="sbTotals" style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap"></div>

                        <div style="display:flex;gap:.5rem;margin-bottom:1rem;align-items:center">
                            <input type="text" id="sbSearch" placeholder="Search subscriptions..." oninput="sbFilter(this.value)" style="flex:1;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text)">
                            <select id="sbFilterStatus" onchange="sbRender()" style="padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text)">
                                <option value="active">Active</option>
                                <option value="all">All</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <button onclick="sbOpenModal()" class="btn-primary" style="padding:.4rem 1rem;font-size:.85rem">+ Add</button>
                        </div>
                        <div id="sbList">Loading...</div>
                    </div>
                </div>

                <!-- Modal -->
                <div id="sbModal" class="sb-modal-overlay" style="display:none" onclick="if(event.target===this)sbCloseModal()">
                    <div class="sb-modal">
                        <div class="sb-modal-header">
                            <strong id="sbModalTitle">Add Subscription</strong>
                            <button onclick="sbCloseModal()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--muted)">&times;</button>
                        </div>
                        <div class="sb-modal-body">
                            <input type="hidden" id="sbId" value="0">
                            <div class="field-row">
                                <label>Name <input type="text" id="sbName" placeholder="OVH VPS Production"></label>
                                <label>Vendor <input type="text" id="sbVendor" placeholder="OVH, AWS, GitHub..."></label>
                            </div>
                            <div class="field-row" style="margin-top:.5rem">
                                <label>Category
                                    <select id="sbCategory" style="width:100%;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text);margin-top:.25rem">
                                        <option value="">--</option>
                                        <option>Hosting</option>
                                        <option>Domain</option>
                                        <option>SaaS</option>
                                        <option>API/Service</option>
                                        <option>Software License</option>
                                        <option>Cloud Storage</option>
                                        <option>Email</option>
                                        <option>SSL</option>
                                        <option>CDN</option>
                                        <option>Backup</option>
                                        <option>Monitoring</option>
                                        <option>Other</option>
                                    </select>
                                </label>
                                <label><input type="checkbox" id="sbActive" checked> Active</label>
                            </div>
                            <div class="field-row" style="margin-top:.5rem">
                                <label>Cost <input type="number" id="sbCost" step="0.01" placeholder="9.99"></label>
                                <label>Currency
                                    <select id="sbCurrency" style="width:100%;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text);margin-top:.25rem">
                                        <option>USD</option><option>EUR</option><option>GBP</option><option>RSD</option><option>CHF</option><option>CAD</option><option>AUD</option>
                                    </select>
                                </label>
                                <label>Billing Cycle
                                    <select id="sbCycle" style="width:100%;padding:.4rem .6rem;border:1px solid var(--input-border);border-radius:6px;font-size:.85rem;background:var(--input-bg);color:var(--text);margin-top:.25rem">
                                        <option value="monthly">Monthly</option>
                                        <option value="annual">Annual</option>
                                        <option value="quarterly">Quarterly</option>
                                        <option value="biannual">Biannual (6mo)</option>
                                        <option value="biennial">Biennial (2yr)</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="daily">Daily</option>
                                        <option value="lifetime">Lifetime</option>
                                    </select>
                                </label>
                            </div>
                            <div class="field-row" style="margin-top:.5rem">
                                <label>Next Renewal Date <input type="date" id="sbRenewal"></label>
                                <label>Login URL <input type="text" id="sbLoginUrl" placeholder="https://www.ovh.com/manager"></label>
                            </div>
                            <div class="field-row" style="margin-top:.5rem">
                                <label>Username <input type="text" id="sbUsername" placeholder="user@example.com"></label>
                                <label>Password
                                    <div style="display:flex;gap:.4rem">
                                        <input type="password" id="sbPassword" style="flex:1">
                                        <button type="button" onclick="sbTogglePw()" class="btn-sm">Show</button>
                                    </div>
                                </label>
                            </div>
                            <label style="margin-top:.5rem">Servers Used For / On
                                <select id="sbServers" multiple style="width:100%;min-height:80px;padding:.3rem;border:1px solid var(--input-border);border-radius:6px;background:var(--input-bg);color:var(--text);font-size:.82rem">
                                    <?php foreach ($servers as $srv): ?>
                                    <option value="<?=$srv['id']?>"><?=e($srv['display_name'] ?: $srv['hostname'])?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="field-row" style="margin-top:.5rem">
                                <label>Purpose <input type="text" id="sbPurpose" placeholder="What is this for?"></label>
                            </div>
                            <label style="margin-top:.5rem">Notes
                                <textarea id="sbNotes" rows="2" placeholder="Account number, contract details, etc." style="width:100%;padding:.3rem .5rem;border:1px solid var(--input-border);border-radius:6px;font-size:.82rem;background:var(--input-bg);color:var(--text);font-family:inherit"></textarea>
                            </label>
                        </div>
                        <div class="sb-modal-footer">
                            <button onclick="sbSave()" class="btn-primary" style="padding:.4rem 1rem;font-size:.85rem">Save</button>
                            <button onclick="sbCloseModal()" class="btn-secondary" style="padding:.4rem 1rem;font-size:.85rem">Cancel</button>
                        </div>
                    </div>
                </div>

                <style>
                .sb-modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000;display:flex;align-items:center;justify-content:center;padding:1rem}
                .sb-modal{background:var(--card);border:1px solid var(--card-border);border-radius:12px;width:100%;max-width:700px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3)}
                .sb-modal-header{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;border-bottom:1px solid var(--card-border)}
                .sb-modal-body{padding:1rem 1.25rem;overflow-y:auto;flex:1}
                .sb-modal-footer{display:flex;gap:.5rem;padding:.75rem 1.25rem;border-top:1px solid var(--card-border)}

                .sb-total{background:var(--card);border:1px solid var(--card-border);border-radius:8px;padding:.6rem 1rem;font-size:.85rem;display:flex;flex-direction:column;gap:.1rem}
                .sb-total-label{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
                .sb-total-val{font-size:1.1rem;font-weight:700;color:var(--text)}

                .sb-card{background:var(--card);border:1px solid var(--card-border);border-radius:8px;padding:.75rem 1rem;margin-bottom:.5rem}
                .sb-card.sb-inactive{opacity:.5}
                .sb-card.sb-renewing{border-left:3px solid var(--amber)}
                .sb-card.sb-overdue{border-left:3px solid var(--red)}
                .sb-row{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
                .sb-name{font-weight:600;font-size:.95rem}
                .sb-vendor{font-size:.7rem;background:var(--code-bg);padding:.1rem .35rem;border-radius:4px;color:var(--muted)}
                .sb-cost{font-weight:600;color:var(--text);margin-left:auto}
                .sb-meta{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.2rem .75rem;font-size:.78rem;color:var(--muted);margin-top:.4rem}
                .sb-meta strong{color:var(--text)}
                .sb-tags{display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.4rem}
                .sb-tag{font-size:.7rem;background:var(--code-bg);padding:.1rem .35rem;border-radius:4px;color:var(--muted);border-left:2px solid var(--blue)}
                .sb-actions{display:flex;gap:.3rem;margin-top:.4rem}
                .sb-cred{display:flex;gap:.4rem;align-items:center;font-size:.8rem;margin-top:.3rem}
                .sb-cred code{background:var(--code-bg);padding:.15rem .4rem;border-radius:4px;font-family:monospace}
                </style>

                <script>
                (function(){
                    var VAULT_KEY='sermony-vault-key';
                    var cryptoKey=null;
                    var allSubs=[];
                    var totals={monthly:0,annual:0};
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

                    window.sbUnlock=async function(){
                        var pw=document.getElementById('sbVaultKey').value||sessionStorage.getItem(VAULT_KEY);
                        if(!pw){alert('Enter vault key');return}
                        cryptoKey=await deriveKey(pw);
                        sessionStorage.setItem(VAULT_KEY,pw);
                        document.getElementById('sbLocked').style.display='none';
                        document.getElementById('sbUnlocked').style.display='';
                        sbLoad();
                    };

                    async function sbLoad(){
                        var resp=await fetch('?action=subscriptions-list');
                        var data=await resp.json();
                        allSubs=data.subscriptions||[];
                        totals.monthly=data.total_monthly||0;
                        totals.annual=data.total_annual||0;
                        for(var s of allSubs) s._decrypted=await decrypt(s.encrypted_password,cryptoKey);
                        sbRenderTotals();
                        sbRender();
                    }

                    function sbRenderTotals(){
                        // Totals by currency (we treat single currency for simplicity)
                        var html='';
                        html+='<div class="sb-total"><span class="sb-total-label">Active subs</span><span class="sb-total-val">'+allSubs.filter(function(s){return s.active==1}).length+'</span></div>';
                        html+='<div class="sb-total"><span class="sb-total-label">Total / month</span><span class="sb-total-val">$'+totals.monthly.toFixed(2)+'</span></div>';
                        html+='<div class="sb-total"><span class="sb-total-label">Total / year</span><span class="sb-total-val">$'+totals.annual.toFixed(2)+'</span></div>';
                        // Group by category
                        var byCat={};
                        for(var s of allSubs){if(s.active!=1)continue;var c=s.category||'Other';byCat[c]=(byCat[c]||0)+(s._monthly_equiv||0)}
                        var topCat='';for(var c in byCat){if(!topCat||byCat[c]>byCat[topCat])topCat=c}
                        if(topCat)html+='<div class="sb-total"><span class="sb-total-label">Top category</span><span class="sb-total-val" style="font-size:.9rem">'+esc(topCat)+' $'+byCat[topCat].toFixed(2)+'/mo</span></div>';
                        document.getElementById('sbTotals').innerHTML=html;
                    }

                    window.sbRender=function(){
                        var filter=document.getElementById('sbFilterStatus').value;
                        var list=allSubs.filter(function(s){
                            if(filter==='active')return s.active==1;
                            if(filter==='inactive')return s.active==0;
                            return true;
                        });
                        var html='';
                        if(!list.length){html='<p style="text-align:center;color:var(--muted);padding:2rem">No subscriptions yet.</p>'}
                        var now=new Date().toISOString().split('T')[0];
                        var soon=new Date(Date.now()+30*86400000).toISOString().split('T')[0];
                        for(var s of list){
                            var servers=JSON.parse(s.servers||'[]');
                            var overdue=s.next_renewal&&s.next_renewal<now;
                            var renewing=s.next_renewal&&!overdue&&s.next_renewal<soon;
                            var cls='sb-card'+(s.active==0?' sb-inactive':'')+(overdue?' sb-overdue':'')+(renewing?' sb-renewing':'');
                            html+='<div class="'+cls+'" data-search="'+esc(s.name+' '+s.vendor+' '+s.category+' '+s.purpose+' '+s.username+' '+s.notes).toLowerCase()+'">';
                            html+='<div class="sb-row">';
                            html+='<span class="sb-name">'+esc(s.name)+'</span>';
                            if(s.vendor)html+='<span class="sb-vendor">'+esc(s.vendor)+'</span>';
                            if(s.category)html+='<span class="sb-vendor">'+esc(s.category)+'</span>';
                            if(overdue)html+='<span class="badge badge-crit">OVERDUE</span>';
                            else if(renewing)html+='<span class="badge badge-warn">RENEWING SOON</span>';
                            if(s.active==0)html+='<span class="badge" style="background:var(--subtle);color:#fff">INACTIVE</span>';
                            var costStr=s.currency+' '+Number(s.cost).toFixed(2)+' / '+s.billing_cycle;
                            if(s.active==1&&s._monthly_equiv)costStr+=' (~$'+Number(s._monthly_equiv).toFixed(2)+'/mo)';
                            html+='<span class="sb-cost">'+esc(costStr)+'</span>';
                            html+='</div>';
                            if(s.username||s._decrypted){
                                html+='<div class="sb-cred">';
                                if(s.username){html+='<strong>User:</strong> <code id="sbu'+s.id+'">'+esc(s.username)+'</code><button onclick="navigator.clipboard.writeText(document.getElementById(\'sbu'+s.id+'\').textContent)" class="btn-sm">Copy</button>'}
                                if(s._decrypted){html+='<strong style="margin-left:.5rem">Pass:</strong> <code id="sbp'+s.id+'" data-pw="'+esc(s._decrypted)+'">••••••</code>';
                                    html+='<button onclick="sbTogglePwShow('+s.id+')" class="btn-sm">Show</button>';
                                    html+='<button onclick="navigator.clipboard.writeText(document.getElementById(\'sbp'+s.id+'\').dataset.pw)" class="btn-sm">Copy</button>';
                                }
                                if(s.login_url)html+='<a href="'+esc(s.login_url)+'" target="_blank" class="btn-sm" style="text-decoration:none">Login &rarr;</a>';
                                html+='</div>';
                            }
                            html+='<div class="sb-meta">';
                            if(s.purpose)html+='<span><strong>Purpose:</strong> '+esc(s.purpose)+'</span>';
                            if(s.next_renewal)html+='<span><strong>Renews:</strong> '+esc(s.next_renewal)+'</span>';
                            if(s.notes)html+='<span><strong>Notes:</strong> '+esc(s.notes)+'</span>';
                            html+='</div>';
                            if(servers.length){
                                html+='<div class="sb-tags">';
                                for(var sid of servers){var srv=serverMap[sid];if(srv)html+='<span class="sb-tag"><a href="?action=server&id='+sid+'" style="color:inherit;text-decoration:none">'+esc(srv.display_name||srv.hostname)+'</a></span>'}
                                html+='</div>';
                            }
                            html+='<div class="sb-actions">';
                            html+='<button onclick="sbEdit('+s.id+')" class="btn-sm">Edit</button>';
                            html+='<button onclick="sbDel('+s.id+')" class="btn-sm btn-sm-danger">Delete</button>';
                            html+='</div></div>';
                        }
                        document.getElementById('sbList').innerHTML=html;
                    };

                    function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML}

                    window.sbFilter=function(q){
                        q=q.toLowerCase();
                        document.querySelectorAll('.sb-card').forEach(function(c){c.style.display=(!q||c.dataset.search.indexOf(q)>=0)?'':'none'});
                    };

                    window.sbTogglePwShow=function(id){
                        var el=document.getElementById('sbp'+id);
                        if(el.textContent.indexOf('•')===0){el.textContent=el.dataset.pw}
                        else{el.textContent='••••••'}
                    };

                    window.sbOpenModal=function(data){
                        data=data||{};
                        document.getElementById('sbId').value=data.id||0;
                        document.getElementById('sbModalTitle').textContent=data.id?'Edit Subscription':'Add Subscription';
                        document.getElementById('sbName').value=data.name||'';
                        document.getElementById('sbVendor').value=data.vendor||'';
                        document.getElementById('sbCategory').value=data.category||'';
                        document.getElementById('sbCost').value=data.cost||'';
                        document.getElementById('sbCurrency').value=data.currency||'USD';
                        document.getElementById('sbCycle').value=data.billing_cycle||'monthly';
                        document.getElementById('sbRenewal').value=data.next_renewal||'';
                        document.getElementById('sbLoginUrl').value=data.login_url||'';
                        document.getElementById('sbUsername').value=data.username||'';
                        document.getElementById('sbPassword').value=data._decrypted||'';
                        document.getElementById('sbPassword').type='password';
                        document.getElementById('sbPurpose').value=data.purpose||'';
                        document.getElementById('sbNotes').value=data.notes||'';
                        document.getElementById('sbActive').checked=data.id?data.active==1:true;
                        var srvs=data.servers?(typeof data.servers==='string'?JSON.parse(data.servers):data.servers):[];
                        var sel=document.getElementById('sbServers');
                        for(var o of sel.options)o.selected=srvs.indexOf(parseInt(o.value))>=0;
                        document.getElementById('sbModal').style.display='flex';
                        document.body.style.overflow='hidden';
                    };

                    window.sbCloseModal=function(){document.getElementById('sbModal').style.display='none';document.body.style.overflow=''};
                    window.sbTogglePw=function(){var el=document.getElementById('sbPassword');el.type=el.type==='password'?'text':'password'};
                    window.sbEdit=function(id){var s=allSubs.find(function(x){return x.id==id});if(s)sbOpenModal(s)};

                    window.sbSave=async function(){
                        var name=document.getElementById('sbName').value.trim();
                        if(!name){alert('Name is required');return}
                        var pw=document.getElementById('sbPassword').value;
                        var encPw=await encrypt(pw,cryptoKey);
                        var selSrvs=Array.from(document.getElementById('sbServers').selectedOptions).map(function(o){return parseInt(o.value)});
                        var payload={
                            id:parseInt(document.getElementById('sbId').value)||0,
                            name:name,
                            vendor:document.getElementById('sbVendor').value.trim(),
                            category:document.getElementById('sbCategory').value,
                            cost:parseFloat(document.getElementById('sbCost').value)||0,
                            currency:document.getElementById('sbCurrency').value,
                            billing_cycle:document.getElementById('sbCycle').value,
                            next_renewal:document.getElementById('sbRenewal').value,
                            login_url:document.getElementById('sbLoginUrl').value.trim(),
                            username:document.getElementById('sbUsername').value.trim(),
                            encrypted_password:encPw,
                            servers:selSrvs,
                            purpose:document.getElementById('sbPurpose').value.trim(),
                            notes:document.getElementById('sbNotes').value.trim(),
                            active:document.getElementById('sbActive').checked?1:0
                        };
                        var resp=await fetch('?action=subscriptions-save',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify(payload)});
                        var r=await resp.json();
                        if(r.ok){sbCloseModal();sbLoad()}else{alert('Error: '+(r.error||'unknown'))}
                    };

                    window.sbDel=async function(id){if(!confirm('Delete this subscription?'))return;await fetch('?action=subscriptions-delete',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.CSRF},body:JSON.stringify({id:id})});sbLoad()};

                    document.addEventListener('keydown',function(e){if(e.key==='Escape')sbCloseModal()});

                    var stored=sessionStorage.getItem(VAULT_KEY);
                    if(stored){document.getElementById('sbVaultKey').value=stored;sbUnlock()}
                })();
                </script>
                <?php
                pageBottom();
                exit;
            }
        },

        // Show subscriptions linked to this server on detail page
        'server_detail' => function (array $server) {
            subsDb();
            $res = db()->query('SELECT id, name, vendor, cost, currency, billing_cycle, active FROM subscriptions ORDER BY name');
            $matches = [];
            while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                $srvs = json_decode($r['servers'] ?? '[]', true);
                // Need to get servers — re-fetch with that field
            }
            // Better: do it in one query
            $res = db()->query('SELECT * FROM subscriptions');
            $matches = [];
            while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                $srvs = json_decode($r['servers'] ?? '[]', true);
                if (in_array((int)$server['id'], $srvs)) $matches[] = $r;
            }
            if (empty($matches)) return;
            $totalMonthly = 0;
            foreach ($matches as $m) {
                if ($m['active']) $totalMonthly += subsMonthlyCost((float)$m['cost'], $m['billing_cycle']);
            }
            ?>
            <div style="margin-top:.75rem;padding:.5rem .75rem;border:1px solid var(--card-border);border-radius:6px;font-size:.82rem;background:var(--card)">
                <strong>Subscriptions (<?=count($matches)?>):</strong>
                <?php foreach ($matches as $m): ?>
                    <a href="?action=subscriptions" style="margin-right:.4rem"><?=e($m['name'])?><?php if ($m['vendor']): ?> <span style="color:var(--muted)">(<?=e($m['vendor'])?>)</span><?php endif; ?></a>
                <?php endforeach; ?>
                <?php if ($totalMonthly > 0): ?>
                <span style="margin-left:.5rem;color:var(--muted)">~$<?=number_format($totalMonthly, 2)?>/mo</span>
                <?php endif; ?>
            </div>
            <?php
        },

        // Make subscriptions searchable
        'search_data' => function (array $parts, array $server): array {
            subsDb();
            $res = db()->query('SELECT name, vendor FROM subscriptions');
            // Need to filter by server — re-query properly
            $res = db()->query('SELECT name, vendor, servers FROM subscriptions');
            while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
                $srvs = json_decode($r['servers'] ?? '[]', true);
                if (in_array((int)$server['id'], $srvs)) {
                    $parts[] = 'subscription';
                    $parts[] = $r['name'] ?? '';
                    $parts[] = $r['vendor'] ?? '';
                }
            }
            return $parts;
        },
    ],
];
