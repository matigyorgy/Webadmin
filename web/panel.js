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
    if(element?.id==='server-state')document.dispatchEvent(new CustomEvent('server-state-updated',{detail:state}));
    if (!element) return;
    element.textContent = state === 'active' ? 'online' : state === 'inactive' ? 'offline' : state;
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
        } catch (error) { feedback.textContent = error.name === 'AbortError' ? 'A lekérdezés túllépte az időkorlátot.' : error.message; }
        finally { busy = false; refresh.disabled = false; }
        if (Date.now() - lastStatus > 30000) {
            lastStatus = Date.now();
            try { showState(document.getElementById('server-state'), (await queryServer(id, 'status')).state); }
            catch (error) { showState(document.getElementById('server-state'), 'nem elérhető'); }
        }
    }
    refresh.addEventListener('click', update);
    document.addEventListener('server-operation-complete', update);
    setInterval(update, 5000);
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
        const automaticName = document.querySelector('[data-game-name]');
        if (automaticName) automaticName.value = selected?.textContent || '';
        const startup = document.getElementById('startup-command');
        const source = document.getElementById('install-source');
        if (startup) startup.placeholder = selected?.dataset.startup || '';
        if (source) source.placeholder = selected?.dataset.template || '';
        const minecraft = selected?.dataset.provider === 'minecraft';
        const custom=document.getElementById('custom-template'), customPanel=document.getElementById('custom-template-options');
        if(customPanel)customPanel.hidden=minecraft;
        if(custom && !minecraft){custom.replaceChildren(new Option('Játéktípus alapértelmezett mappája',''));if(node?.value)try{const response=await fetch(`/admin_api.php?action=templates&node_id=${encodeURIComponent(node.value)}`,{cache:'no-store'});const data=await response.json();if(!response.ok)throw Error(data.error);for(const entry of data.templates || [])if(entry.provider==='folder' && entry.game===gameSelect.value && entry.state==='ready')custom.append(new Option(`${entry.name} · ${entry.version} · min. ${entry.minimum_memory_mb} MiB`,entry.key));}catch(error){custom.replaceChildren(new Option(error.message,''));}}

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
if(templatePanel){let busy=false,refreshNeeded=false;setInterval(async()=>{
    if(busy || document.hidden)return;busy=true;
    try{
        const response=await fetch(`/admin_api.php?action=templates&node_id=${encodeURIComponent(templatePanel.dataset.templateNode)}`,{cache:'no-store'});
        const data=await response.json();if(!response.ok)throw Error(data.error);
        const previous=templatePanel.dataset.templateState;templatePanel.dataset.templateState=data.state;
        document.dispatchEvent(new Event('template-state-updated'));
        const message=templatePanel.querySelector('[data-template-message]');message.textContent=data.message || '';
        if(previous==='running' && data.state!=='running')refreshNeeded=true;
        if(refreshNeeded){
            const link=document.createElement('a');link.href=location.href;link.className='ms-2';link.textContent='Telepített templatek frissítése';message.append(link);
        }
    }catch(error){
        templatePanel.querySelector('[data-template-message]').textContent=error.message;
        templatePanel.dataset.templateState='unknown';document.dispatchEvent(new Event('template-state-updated'));
    }finally{busy=false;}
},5000);}

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
            const memoryMiB = Number(result.memory_bytes) / 1048576;
            const memoryLimit = Number(resources.dataset.memoryLimit || 0);
            resources.querySelector('[data-memory]').textContent = `${memoryMiB.toFixed(1)} MiB`;
            for (const [selector, value] of [['[data-cpu-progress]', Number(result.cpu_percent)], ['[data-memory-progress]', memoryLimit > 0 ? memoryMiB / memoryLimit * 100 : 0]]) {
                const progress = resources.querySelector(selector);
                if (progress) {
                    const percent = Math.max(0, Math.min(100, value));
                    progress.setAttribute('aria-valuenow', percent.toFixed(1));
                    progress.querySelector('.progress-bar').style.width = `${percent}%`;
                }
            }
            resources.querySelector('[data-resource-message]').textContent = `Frissítve: ${new Date().toLocaleTimeString('hu-HU')} · ${result.limits_enforced ? 'Linux-korlátozás aktív' : 'Folyamatadatok'}`;
            showState(document.getElementById('server-state'), result.state);
        } catch (error) {
            resources.querySelector('[data-cpu]').textContent = '—';
            resources.querySelector('[data-memory]').textContent = '—';
            for (const progress of resources.querySelectorAll('[role=progressbar]')) {progress.setAttribute('aria-valuenow', '0');progress.querySelector('.progress-bar').style.width='0%';}
            resources.querySelector('[data-resource-message]').textContent = 'A használati adatok nem érhetők el. Ellenőrizd a Linux-telepítést és az SSH-kapcsolatot.';
        } finally { readingResources = false; }
    };
    document.addEventListener('server-operation-complete', updateResources);
    updateResources();
    setInterval(updateResources, 5000);
}


const serverControls = document.getElementById('server-controls');
if (serverControls) {
    let controlling = false;
    serverControls.addEventListener('submit', async event => {
        const action = event.submitter?.value;
        if (!['start', 'stop', 'restart', 'status'].includes(action)) return;
        event.preventDefault();
        if (controlling) return;
        controlling = true;
        const feedback = document.getElementById('control-feedback');
        const buttons = [...serverControls.querySelectorAll('button')];
        const disabled = buttons.map(button => button.disabled);
        const data = new FormData(serverControls);
        data.set('action', action);
        buttons.forEach(button => { button.disabled = true; });
        feedback.textContent = 'Művelet folyamatban…';
        feedback.className = 'small text-body-secondary';
        try {
            if (action !== 'status') {
                const response = await fetch('/api.php', {method: 'POST', body: data, credentials: 'same-origin'});
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'A vezérlési művelet sikertelen.');
            }
            feedback.textContent = action === 'status' ? 'Állapot frissítve.' : 'A vezérlési parancs lefutott.';
            feedback.className = 'small text-success';
            try {
                showState(document.getElementById('server-state'), (await queryServer(data.get('server_id'), 'status')).state);
            } catch (error) {
                showState(document.getElementById('server-state'), 'nem elérhető');
                feedback.textContent += ' Az új állapot nem kérdezhető le.';
            }
            document.dispatchEvent(new Event('server-operation-complete'));
        } catch (error) {
            feedback.textContent = error.message;
            feedback.className = 'small text-danger';
        } finally {
            buttons.forEach((button, index) => { button.disabled = disabled[index]; });
            controlling = false;
        }
    });
}

const versionPanel = document.getElementById('version-switch');
if (versionPanel) {
    const form=document.getElementById('version-form'), choice=document.getElementById('version-choice');
    const button=form.querySelector('button'), message=versionPanel.querySelector('[data-version-message]');
    const confirmBox=form.querySelector('[name=confirm_reinstall]'), warning=form.querySelector('[data-reinstall-warning]');
    let pending=false, loading=false, state='unknown', job={state:'idle'}, versions=new Map();
    versionPanel.hidden=location.hash!=='#version-switch';
    const reveal=()=>{versionPanel.hidden=false;loadVersions().catch(error=>{state='unknown';message.textContent=error.message;update();});};
    document.querySelectorAll('a[href="#version-switch"]').forEach(link=>link.addEventListener('click',reveal));
    window.addEventListener('hashchange',()=>{if(location.hash==='#version-switch')reveal();});
    function update(){
        const selected=versions.get(choice.value), reinstall=!!selected?.requires_reinstall;
        warning.hidden=!reinstall;confirmBox.required=reinstall;confirmBox.disabled=!reinstall || pending;
        choice.disabled=pending;
        for(const control of document.querySelectorAll('#server-controls button[value="start"],#server-controls button[value="restart"]'))control.disabled=pending;
        button.disabled=pending || state!=='inactive' || !selected || selected.current || !selected.allowed || (reinstall && !confirmBox.checked);
        if(state!=='inactive')message.textContent=state==='active' ? 'A szerver online. Verzióváltáshoz előbb állítsd le.' : 'A leállított állapot még nem ellenőrizhető. Verzióváltás zárolva.';
        else if(pending)message.textContent=job.message || 'A verzióváltás folyamatban.';
        else message.textContent=job.message || 'A szerver offline. Válassz típust és verziót.';
    }
    async function loadVersions(){
        if(loading)return;loading=true;
        try{
            const result=await queryServer(versionPanel.dataset.serverId,'versions');state=result.server_state;job=result.job;pending=['queued','running'].includes(job.state);
            const old=choice.value;choice.replaceChildren(new Option('Válassz típust és verziót',''));versions=new Map();
            const groups=new Map(), names={vanilla:'Vanilla',bukkit:'Bukkit',spigot:'Spigot',paper:'Paper',forge:'Forge',purpur:'Purpur',fabric:'Fabric'};
            for(const [type,label] of Object.entries(names[result.current_type] ? names : {})){const group=document.createElement('optgroup');group.label=label;groups.set(type,group);choice.append(group);}
            for(const entry of result.versions || []){
                if(!groups.has(entry.type)){const group=document.createElement('optgroup');group.label=entry.name || entry.type;groups.set(entry.type,group);choice.append(group);}
                const option=new Option(`${entry.version} · ${entry.build} · min. ${entry.minimum_memory_mb} MiB${entry.requires_reinstall ? ' · újratelepítés' : ''}${entry.current ? ' · jelenlegi' : ''}${!entry.allowed ? ' · kevés memória' : ''}`,entry.key);
                option.disabled=entry.current || !entry.allowed;groups.get(entry.type).append(option);versions.set(entry.key,entry);
            }
            for(const group of groups.values())if(!group.children.length){const option=new Option('Nincs telepített template','');option.disabled=true;group.append(option);}
            if(versions.has(old) && !versions.get(old).current && versions.get(old).allowed)choice.value=old;
            for(const label of document.querySelectorAll('[data-platform-version]'))label.textContent=`${names[result.current_type] || result.current_type} ${result.current_version}`;
            versionPanel.querySelector('[data-current-version]').textContent=`${result.current_type} · ${result.current_version}`;
            update();
        }finally{loading=false;}
    }
    choice.addEventListener('change',()=>{confirmBox.checked=false;update();});confirmBox.addEventListener('change',update);
    document.addEventListener('server-state-updated',event=>{state=event.detail;update();});
    form.addEventListener('submit',async event=>{
        event.preventDefault();if(button.disabled)return;
        const data=new FormData(form);data.set('action','change_version');data.set('server_id',versionPanel.dataset.serverId);
        pending=true;update();
        try{const response=await fetch('/api.php',{method:'POST',body:data,credentials:'same-origin'});const result=await response.json();if(!response.ok)throw Error(result.error);job=result;pending=['queued','running'].includes(result.state);update();}
        catch(error){pending=false;update();message.textContent=error.message;}
    });
    setInterval(async()=>{
        if(document.hidden || versionPanel.hidden)return;
        if(!pending){loadVersions().catch(error=>{state='unknown';update();message.textContent=error.message;});return;}
        try{job=await queryServer(versionPanel.dataset.serverId,'version_status');pending=['queued','running'].includes(job.state);update();if(!pending){confirmBox.checked=false;await loadVersions();document.dispatchEvent(new Event('server-operation-complete'));}}
        catch(error){message.textContent=error.message;}
    },5000);
    if(!versionPanel.hidden)reveal();
}

const templateGame=document.getElementById('template-game');
const releaseCatalog=document.querySelector('[data-release-catalog]');
if(releaseCatalog){
    const form=releaseCatalog.querySelector('[data-release-form]'),type=releaseCatalog.querySelector('#release-type'),version=releaseCatalog.querySelector('#release-version');
    const message=releaseCatalog.querySelector('[data-release-message]'),retry=releaseCatalog.querySelector('[data-release-retry]'),install=releaseCatalog.querySelector('[data-release-install]');
    let request=0,controller,submitting=false;
    const update=()=>{
        const state=templatePanel?.dataset.templateState,blocked=state==='running' || state==='unknown';
        install.disabled=submitting || blocked || version.disabled || !version.value;
        releaseCatalog.querySelector('[data-install-state]').textContent=state==='running' ? 'Ezen a Linux-gépen már egy template telepítése fut. Új telepítést a befejezése után indíthatsz.' : state==='unknown' ? 'A telepítés állapota nem érhető el. A kapcsolat helyreállása után újra próbálhatod.' : '';
    };
    document.addEventListener('template-state-updated',update);
    async function load(){
        const current=++request,selectedType=releaseCatalog.hidden ? '' : type.value;
        controller?.abort();controller=new AbortController();
        version.replaceChildren(new Option(selectedType ? 'Verziók betöltése…' : 'Előbb válassz típust',''));
        version.disabled=true;retry.hidden=true;update();
        message.textContent=selectedType ? 'Stabil kiadások lekérése…' : 'Válassz típust a verziók betöltéséhez.';
        if(!selectedType)return;
        try{
            const response=await fetch(`/admin_api.php?action=releases&node_id=${encodeURIComponent(releaseCatalog.dataset.node)}&type=${encodeURIComponent(selectedType)}`,{cache:'no-store',signal:controller.signal});
            const data=await response.json();if(current!==request)return;
            if(!response.ok)throw Error(data.error || 'A verziók lekérése nem sikerült.');
            const seen=new Set(),entries=(data.templates || []).filter(entry=>{
                if(entry.type!==selectedType || !entry.key || !entry.version || seen.has(entry.version))return false;
                seen.add(entry.version);return true;
            }).slice(0,10);
            version.replaceChildren(new Option('Válassz verziót',''));
            for(const entry of entries)version.append(new Option(`${entry.version}${entry.build ? ' · '+entry.build : ''}`,entry.key));
            version.disabled=entries.length===0;
            message.textContent=entries.length ? 'Válassz verziót, majd kattints a Sablon telepítése gombra.' : 'Ehhez a típushoz jelenleg nincs elérhető stabil kiadás.';
        }catch(error){
            if(current!==request || error.name==='AbortError')return;
            version.replaceChildren(new Option('A verziók nem érhetők el',''));message.textContent=error.message;retry.hidden=false;
        }finally{if(current===request)update();}
    }
    type.addEventListener('change',load);version.addEventListener('change',update);retry.addEventListener('click',load);
    form.addEventListener('submit',event=>{
        if(releaseCatalog.hidden || submitting || event.submitter!==install || install.disabled || !form.reportValidity()){event.preventDefault();return;}
        submitting=true;
        // Keep the submit button enabled until the browser has serialized its action value.
        setTimeout(update,0);
    });
}

if(templateGame){
    const custom=document.querySelector('[data-custom-template]'),customFields=custom.querySelector('[data-custom-template-fields]');
    const steam=document.querySelector('[data-steam-template]'),steamFields=steam.querySelector('[data-steam-fields]'),steamButton=steam.querySelector('[data-steam-install]');
    let steamPending=false;
    function updateSteam(){
        const state=templatePanel?.dataset.templateState,blocked=['running','unknown'].includes(state);
        steamButton.disabled=steamPending || steam.hidden || blocked;
        steam.querySelector('[data-steam-message]').textContent=state==='running' ? 'Már fut egy template telepítése ezen a Linux-gépen. Várd meg a befejezését.' : state==='unknown' ? 'A telepítés állapota jelenleg nem érhető el.' : '';
    }
    document.addEventListener('template-state-updated',updateSteam);
    steam.querySelector('form').addEventListener('submit',event=>{
        if(steamButton.disabled || event.submitter!==steamButton){event.preventDefault();return;}
        steamPending=true;setTimeout(updateSteam,0);
    });
    const releaseFields=releaseCatalog.querySelector('[data-release-fields]'),type=releaseCatalog.querySelector('#release-type');
    function selectGame(){
        const selected=templateGame.selectedOptions[0],minecraft=selected?.dataset.provider==='minecraft',folder=selected?.dataset.provider==='folder';
        releaseCatalog.hidden=!minecraft;releaseFields.disabled=!minecraft;
        custom.hidden=!folder;customFields.disabled=!folder;
        steam.hidden=!(folder && ['cs2','css'].includes(templateGame.value));steamFields.disabled=steam.hidden;
        steam.querySelector('[name=steam_game]').value=steam.hidden ? '' : templateGame.value;updateSteam();
        custom.querySelector('form').reset();
        custom.querySelector('[name=template_name]').value=folder ? selected.textContent : '';
        custom.querySelector('[name=template_game]').value=folder ? templateGame.value : '';
        type.value='';type.dispatchEvent(new Event('change'));
    }
    templateGame.addEventListener('change',selectGame);selectGame();
}
