// Render: php tests/fixtures/automation.php > /tmp/automation.html
// Run: node tests/browser_automation.cjs /tmp/automation.html
const assert=require('node:assert/strict'),fs=require('node:fs'),http=require('node:http'),path=require('node:path');
const {chromium}=require('playwright');
(async()=>{
 const html=fs.readFileSync(process.argv[2],'utf8'),js=fs.readFileSync(path.join(__dirname,'../web/panel.js'),'utf8');
 const server=http.createServer((req,res)=>{res.setHeader('Content-Type',req.url==='/panel.js'?'text/javascript':'text/html; charset=utf-8');res.end(req.url==='/panel.js'?js:html+'<script src="/panel.js"></script>');});
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));let browser;
 try{
  browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
  const page=await browser.newPage(),posts=[],errors=[];page.on('pageerror',e=>errors.push(e.message));await page.clock.install();
  const state={scheduler_ready:true,server_state:'active',job:{state:'idle',message:''},schedules:[],backups:[{id:'a'.repeat(32),created_at:1793458800,size:1024,label:'Fixture'}]};
  await page.route('**/api.php*',async route=>{
   const request=route.request();if(request.method()==='POST'){
    const data=new URLSearchParams();for(const match of request.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g))data.set(match[1],match[2]);posts.push(data);assert.equal(data.get('csrf'),'automation-test');assert.equal(data.get('server_id'),'42');
    if(data.get('action')==='schedule_add')state.schedules=[{id:'b'.repeat(32),operation:data.get('operation'),repeat:data.get('repeat'),timezone:data.get('timezone'),next_run:2000000000,last_result:'Még nem futott.'}];
    if(data.get('action')==='schedule_delete')state.schedules=[];
    if(['backup','restore'].includes(data.get('action')))state.job={state:'running',operation:data.get('action'),message:'A művelet folyamatban van.'};
   }
   await route.fulfill({json:state});
  });
  const url=`http://127.0.0.1:${server.address().port}`;
  await page.goto(url+'/#backups');await page.locator('[data-backup-rows] form').waitFor();
  const restore=page.getByRole('button',{name:'Visszaállítás',exact:true}),confirm=page.locator('[name=confirmed]');
  assert(await confirm.isDisabled());assert(await restore.isDisabled());
  state.server_state='inactive';await page.clock.runFor(5000);await page.waitForFunction(()=>!document.querySelector('[name=confirmed]').disabled);
  assert(await restore.isDisabled());await confirm.check();assert(!(await restore.isDisabled()));await restore.click();
  await page.waitForFunction(()=>document.querySelector('[data-backup-create]').disabled);
  assert.equal(posts[0].get('action'),'restore');assert.equal(posts[0].get('confirmed'),'1');assert.equal(posts[0].get('backup_id'),'a'.repeat(32));
  state.job={state:'ready',operation:'restore',message:'Elkészült.'};await page.clock.runFor(5000);await page.waitForFunction(()=>!document.querySelector('[data-backup-create]').disabled);
  await page.getByRole('button',{name:'Mentés készítése',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-backup-create]').disabled);assert.equal(posts.at(-1).get('action'),'backup');
  state.job={state:'ready',message:'Elkészült.'};await page.clock.runFor(5000);await page.waitForFunction(()=>!document.querySelector('[data-backup-create]').disabled);
  await page.goto(url+'/#scheduler');await page.locator('[name=operation]').selectOption('backup');await page.locator('[name=local_time]').fill('2050-01-01T03:30');await page.locator('[name=repeat]').selectOption('daily');await page.locator('[name=keep]').fill('5');
  await page.getByRole('button',{name:'Időzítés mentése'}).click();await page.locator('[data-schedule-rows] button').waitFor();
  assert.equal(posts.at(-1).get('action'),'schedule_add');assert.equal(posts.at(-1).get('operation'),'backup');assert.equal(posts.at(-1).get('timezone'),'Europe/Budapest');assert.equal(posts.at(-1).get('keep'),'5');
  await page.locator('[data-schedule-rows] button').click();await page.waitForFunction(()=>!document.querySelector('[data-schedule-rows] button'));assert.equal(posts.at(-1).get('action'),'schedule_delete');
  assert.deepEqual(errors,[]);console.log('Automation browser passed: offline + confirmation gating, exact restore/backup requests, pending-operation locks, daily backup scheduling, timezone/retention and deletion.');
 }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;});
