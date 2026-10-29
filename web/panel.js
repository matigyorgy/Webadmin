'use strict';
async function queryServer(id, action) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
        const response = await fetch(`/api.php?server_id=${encodeURIComponent(id)}&action=${encodeURIComponent(action)}`, {credentials: 'same-origin', signal: controller.signal, cache: 'no-store'});
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'A lekérdezés sikertelen.');
        return data;
    } finally { clearTimeout(timeout); }
}
function showState(element, state) {
    if (!element) return;
    element.textContent = state;
    element.className = `badge ${state === 'active' ? 'text-bg-success' : 'text-bg-secondary'}`;
}
document.querySelectorAll('[data-status-id]').forEach(button => {
    button.addEventListener('click', async () => {
        button.disabled = true;
        const target = document.getElementById(`state-${button.dataset.statusId}`);
        try { showState(target, (await queryServer(button.dataset.statusId, 'status')).state); }
        catch (error) { showState(target, 'nem elérhető'); target.title = error.message; }
        finally { button.disabled = false; }
    });
});
const consolePanel = document.getElementById('console');
if (consolePanel) {
    const id = consolePanel.dataset.serverId;
    const refresh = document.getElementById('refresh-console');
    const output = document.getElementById('console-output');
    const feedback = document.getElementById('console-feedback');
    const automatic = document.getElementById('auto-refresh');
    let busy = false;
    let lastStatus = 0;
    async function update() {
        if (busy) return;
        busy = true; refresh.disabled = true;
        try {
            const data = await queryServer(id, 'logs');
            const atBottom = output.scrollHeight - output.scrollTop - output.clientHeight < 60;
            output.textContent = data.logs;
            if (atBottom) output.scrollTop = output.scrollHeight;
            feedback.textContent = `Frissítve: ${new Date().toLocaleTimeString('hu-HU')}`;
        } catch (error) { feedback.textContent = error.name === 'AbortError' ? 'A lekérdezés túllépte az időkorlátot.' : error.message; automatic.checked = false; }
        finally { busy = false; refresh.disabled = false; }
        if (Date.now() - lastStatus > 30000) {
            lastStatus = Date.now();
            try { showState(document.getElementById('server-state'), (await queryServer(id, 'status')).state); }
            catch (error) { showState(document.getElementById('server-state'), 'nem elérhető'); }
        }
    }
    refresh.addEventListener('click', update);
    setInterval(() => { if (automatic.checked && !document.hidden) update(); }, 5000);
    update();
}
const provisionPanel = document.querySelector('[data-provision-admin], [data-provision-client]');
if (provisionPanel) {
    const admin = provisionPanel.hasAttribute('data-provision-admin');
    const id = admin ? provisionPanel.dataset.provisionAdmin : provisionPanel.dataset.provisionClient;
    const state = provisionPanel.querySelector('[data-provision-state]');
    const message = provisionPanel.querySelector('[data-provision-message]');
    let busy = false;
    let complete = state && ['ready', 'failed'].includes(state.textContent.trim());
    async function updateProvision() {
        if (busy || complete || document.hidden) return;
        busy = true;
        try {
            const response = await fetch(admin ? `/admin_api.php?server_id=${encodeURIComponent(id)}` : `/api.php?action=provision&server_id=${encodeURIComponent(id)}`, {credentials: 'same-origin', cache: 'no-store'});
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Az állapot nem érhető el.');
            state.textContent = data.state;
            message.textContent = data.message;
            complete = ['ready', 'failed'].includes(data.state);
            if (!admin && data.state === 'ready') location.reload();
        } catch (error) { message.textContent = error.message; complete = true; }
        finally { busy = false; }
    }
    updateProvision();
    setInterval(updateProvision, 5000);
}
const gameSelect = document.getElementById('server-game');
if (gameSelect) {
    const node = document.getElementById('server-node');
    const options = document.getElementById('minecraft-options');
    const templates = document.getElementById('minecraft-template');
    async function updateGame() {
        const selected = gameSelect.selectedOptions[0];
        const startup = document.getElementById('startup-command');
        const source = document.getElementById('install-source');
        if (startup) startup.placeholder = selected?.dataset.startup || '';
        if (source) source.placeholder = selected?.dataset.template || '';
        const minecraft = selected?.dataset.provider === 'minecraft';
        if (options) options.hidden = !minecraft;
        if (minecraft && node?.value && templates) {
            templates.replaceChildren(new Option('Katalógus betöltése…', ''));
            try {
                const response = await fetch(`/admin_api.php?action=templates&node_id=${encodeURIComponent(node.value)}`, {cache:'no-store'});
                const data = await response.json();if (!response.ok) throw Error(data.error);
                templates.replaceChildren(new Option('Válassz típust és verziót', ''));
                for (const type of [...new Set((data.templates || []).map(entry=>entry.type))]) {
                    const group = document.createElement('optgroup');group.label=type;
                    for (const entry of data.templates || []) if(entry.type===type) {
                        const option=new Option(`${entry.version} · ${entry.build} · ${entry.state}`,entry.key);option.disabled=entry.state!=='ready';group.append(option);
                    }
                    templates.append(group);
                }
                document.getElementById('minecraft-catalog-message').textContent=data.message || '';
            } catch(error) { templates.replaceChildren(new Option(error.message || 'A katalógus nem érhető el', '')); }
        }
    }
    gameSelect.addEventListener('change',updateGame);node?.addEventListener('change',updateGame);updateGame();
}
const templatePanel=document.querySelector('[data-template-node]');
if(templatePanel) {
    let busy=false;
    const timer=setInterval(async()=>{
        if(busy || document.hidden)return;busy=true;
        try {
            const response=await fetch(`/admin_api.php?action=templates&node_id=${encodeURIComponent(templatePanel.dataset.templateNode)}`,{cache:'no-store'});
            const data=await response.json();if(!response.ok)throw Error(data.error);
            templatePanel.querySelector('[data-template-message]').textContent=data.message;
            const body=templatePanel.querySelector('tbody');body.replaceChildren();
            for(const entry of data.templates || []) { const row=document.createElement('tr');for(const field of ['type','version','build','state','message']) {const cell=document.createElement('td');cell.textContent=entry[field] || '';row.append(cell);}body.append(row); }
            if(data.state!=='running')clearInterval(timer);
        } catch(error){templatePanel.querySelector('[data-template-message]').textContent=error.message;clearInterval(timer);}finally{busy=false;}
    },5000);
}
const setup=document.getElementById('node-setup');
if(setup) document.getElementById('start-node-setup').addEventListener('click',async()=>{
    const button=document.getElementById('start-node-setup');const output=document.getElementById('node-setup-result');
    const data=new FormData();for(const input of setup.querySelectorAll('[data-setup]'))if(input.type!=='checkbox' || input.checked)data.set(input.dataset.setup,input.value);
    data.set('node_id',setup.dataset.nodeId);data.set('csrf',setup.dataset.csrf);button.disabled=true;
    try {
        for(let round=0;round<360;round++) {
            data.set('action',round===0?'setup_start':'setup_status');
            const response=await fetch('/admin_api.php',{method:'POST',body:data});const result=await response.json();if(!response.ok)throw Error(result.error || 'A telepítés nem érhető el');
            output.textContent=result.message+(result.log?'\n'+result.log:'');
            if(result.state!=='running')break;
            await new Promise(resolve=>setTimeout(resolve,5000));
        }
    } catch(error){output.textContent=error.message+' Az állapot folytatásához add meg újra a telepítési hozzáférést, majd kattints a gombra.';}
    finally {for(const key of ['setup_password','setup_key','setup_passphrase']){data.delete(key);setup.querySelector(`[data-setup="${key}"]`).value='';}button.disabled=false;}
});

const probe=document.getElementById('probe-host-key');
if(probe)probe.addEventListener('click',async()=>{
 probe.disabled=true;const message=document.getElementById('probe-result');
 try{const data=new FormData();data.set('action','probe');data.set('csrf',probe.dataset.csrf);data.set('host',document.getElementById('node-host').value);data.set('port',document.getElementById('node-port').value);
 const response=await fetch('/admin_api.php',{method:'POST',body:data});const result=await response.json();if(!response.ok)throw Error(result.error);document.getElementById('node-fingerprint').value=result.fingerprint;message.textContent='Lekérve. Első mentés előtt ellenőrizd a szolgáltatódnál.';
 }catch(error){message.textContent=error.message;}finally{probe.disabled=false;}
});

const resources = document.getElementById('server-resources');
if (resources) {
    let readingResources = false;
    const updateResources = async () => {
        if (readingResources || document.hidden) return;
        readingResources = true;
        try {
            const result = await queryServer(resources.dataset.serverId, 'metrics');
            resources.querySelector('[data-cpu]').textContent = `${Number(result.cpu_percent).toFixed(1)}%`;
            resources.querySelector('[data-memory]').textContent = `${(Number(result.memory_bytes) / 1048576).toFixed(1)} MiB`;
            resources.querySelector('[data-resource-message]').textContent = `Frissítve: ${new Date().toLocaleTimeString()} · ${result.limits_enforced ? 'Linux-korlátozás aktív' : 'Folyamatadatok'}`;
            showState(document.getElementById('server-state'), result.state);
        } catch (error) {
            resources.querySelector('[data-cpu]').textContent = '—';
            resources.querySelector('[data-memory]').textContent = '—';
            resources.querySelector('[data-resource-message]').textContent = 'A használati adatok nem érhetők el. Ellenőrizd a Linux-telepítést és az SSH-kapcsolatot.';
        } finally { readingResources = false; }
    };
    updateResources();
    setInterval(updateResources, 5000);
}
