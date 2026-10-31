// php tests/fixtures/storage.php > /tmp/storage.html; node tests/browser_storage.cjs /tmp/storage.html
const assert=require('node:assert/strict'),fs=require('node:fs'),http=require('node:http'),path=require('node:path');const {chromium}=require('playwright');
(async()=>{
 const html=fs.readFileSync(process.argv[2],'utf8'),js=fs.readFileSync(path.join(__dirname,'../web/panel.js'),'utf8');const server=http.createServer((req,res)=>{res.setHeader('Content-Type',req.url==='/panel.js'?'text/javascript':'text/html; charset=utf-8');res.end(req.url==='/panel.js'?js:html+'<script src="/panel.js"></script>');});await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));let browser;
 try{
  browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});const page=await browser.newPage(),errors=[];page.on('pageerror',error=>errors.push(error.message));await page.clock.install();
  let result={state:'inactive',cpu_percent:0,memory_bytes:0,limits_enforced:false,disk_bytes:5368709120,disk_limit_mb:10240,disk_enforced:true,disk_state:'ready'};
  await page.route('**/api.php*',route=>route.fulfill({json:result}));await page.goto(`http://127.0.0.1:${server.address().port}`);await page.locator('[data-disk]').filter({hasText:'5.00 GiB / 10.00 GiB'}).waitFor();assert.equal(await page.locator('[data-disk-progress]').getAttribute('aria-valuenow'),'50.0');
  result={...result,disk_bytes:8192,disk_limit_mb:0,disk_enforced:false};await page.clock.runFor(5000);await page.locator('[data-disk]').filter({hasText:'8.0 KiB / Korlátlan'}).waitFor();assert.equal(await page.locator('[data-disk-progress]').getAttribute('aria-valuenow'),'0.0');
  result={...result,disk_bytes:null,disk_state:'measuring'};await page.clock.runFor(5000);await page.locator('[data-disk]').filter({hasText:'Mérés folyamatban'}).waitFor();
  result={...result,disk_bytes:8192,disk_limit_mb:10240,disk_state:'unsupported'};await page.clock.runFor(5000);await page.locator('[data-disk-message]').filter({hasText:'nem érvényesül'}).waitFor();assert(await page.locator('[data-disk-message]').evaluate(el=>el.classList.contains('text-danger')));
  assert.deepEqual(errors,[]);console.log('Storage browser passed: offline occupied space, actual maximum, progress ratio, unlimited cap, pending measurement and unenforced-quota warning.');
 }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;});
