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
