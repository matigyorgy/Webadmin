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
    const examples = {minecraft:'java -Xms1G -Xmx4G -jar server.jar nogui',cs2:'./game/bin/linuxsteamrt64/cs2 -dedicated -console -usercon +map de_dust2',fivem:'./run.sh +exec server.cfg',css:'./srcds_run -game cstrike -console +map de_dust2 -port 27015'};
    const updateExamples = () => {
        const startup = document.getElementById('startup-command');
        const source = document.getElementById('install-source');
        if (startup) startup.placeholder = examples[gameSelect.value] || '';
        if (source) source.placeholder = `/srv/gamedock/templates/${gameSelect.value}`;
    };
    gameSelect.addEventListener('change', updateExamples);
    updateExamples();
}
