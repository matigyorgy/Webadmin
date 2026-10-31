const fs = require('fs');
const http = require('http');
const { chromium } = require('playwright');

const html = fs.readFileSync(process.argv[2], 'utf8');
const server = http.createServer((req, res) => {
  if (req.url === '/panel.js') {
    res.writeHead(200, { 'content-type': 'application/javascript' });
    res.end(fs.readFileSync('web/panel.js'));
    return;
  }
  res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
  res.end(html.replace('</body>', '<script src="/panel.js"></script></body>'));
});

(async () => {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
  try {
    const page = await browser.newPage();
    const errors = [];
    const statuses = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/api.php?action=status**', async route => {
      statuses.push(new URL(route.request().url()).searchParams.get('server_id'));
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ state: 'active' }) });
    });
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await page.locator('#state-11').getByText('online').waitFor();
    if ((await page.locator('#state-12').textContent()).trim() !== 'telepítés') throw new Error('Provisioning server status changed unexpectedly');
    if (!(await page.locator('body').innerText()).includes('games.example.test:25555')) throw new Error('Host and port are not shown');
    if (!(await page.locator('body').innerText()).includes('[2001:db8::2]:25556')) throw new Error('IPv6 host and port are not shown');
    if (await page.getByRole('button', { name: 'Állapot' }).count()) throw new Error('Manual status button remains');
    if (await page.getByRole('link', { name: 'Kezelés és konzol' }).count()) throw new Error('Old management button remains');
    if (await page.getByRole('link', { name: 'Kezelés', exact: true }).count() !== 2) throw new Error('Management links are missing');
    if (statuses.join(',') !== '11') throw new Error(`Unexpected status requests: ${statuses.join(',')}`);
    if (errors.length) throw new Error(`Browser errors: ${errors.join('; ')}`);
    console.log('Server list address and automatic status checks passed.');
  } finally {
    await browser.close();
    server.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
