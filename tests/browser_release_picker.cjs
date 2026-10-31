// Render first: php tests/fixtures/releases.php > /tmp/releases.html
// Then: node tests/browser_release_picker.cjs /tmp/releases.html
// Exercises the actual Smarty HTML and panel.js with isolated catalog responses.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const http=require('node:http');
const {chromium}=require('playwright');
(async()=>{
 const html=fs.readFileSync(process.argv[2],'utf8'),script=fs.readFileSync(require('node:path').join(__dirname,'../web/panel.js'),'utf8');
 const server=http.createServer((req,res)=>{
  res.setHeader('Content-Type',req.url==='/panel.js'?'text/javascript':'text/html; charset=utf-8');
  res.end(req.url==='/panel.js'?script:html+'<script src="/panel.js"></script>');
 });
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 let browser;
 try{
  browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
  const page=await browser.newPage(),errors=[],posts=[];
  page.on('pageerror',e=>errors.push(e.message));
  const rows=type=>Array.from({length:12},(_,i)=>({type,key:`${type}:1.21.${12-i}:build`,version:`1.21.${12-i}`,build:'build'}));
  let fail=false,empty=false;
  await page.route('**/admin_api.php?*',async route=>{
   const type=new URL(route.request().url()).searchParams.get('type');
   if(type==='paper')await new Promise(resolve=>setTimeout(resolve,200));
   await route.fulfill({status:fail?503:200,json:fail?{error:'Catalog unavailable'}:{templates:empty?[]:rows(type)}}).catch(()=>{});
  });
  await page.route('**/*',async route=>{
   if(route.request().method()==='POST'){
    posts.push(new URLSearchParams(route.request().postData()));
    return route.fulfill({status:200,contentType:'text/html',body:'Installed fixture selection'});
   }
   await route.fallback();
  });
  const url=`http://127.0.0.1:${server.address().port}`;
  await page.goto(url);
  const type=page.locator('#release-type'),version=page.locator('#release-version'),button=page.locator('[data-release-install]');
  assert(await version.isDisabled());assert(await button.isDisabled());
  assert(!(await type.isVisible()));assert(!(await page.locator('[data-custom-template]').isVisible()));
  await page.selectOption('#template-game','cs2');
  assert(await page.locator('[data-custom-template]').isVisible());assert(!(await type.isVisible()));
  assert.equal(await page.locator('[name=template_game]').inputValue(),'cs2');
  await page.locator('[name=template_name]').fill('Counter-Strike 2');
  await page.locator('[name=template_version]').fill('2026.10');
  await page.locator('[name=template_path]').fill('/home/gamedock/templates/cs2/2026.10');
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Template felvétele',exact:true}).click()]);
  assert.equal(posts.length,1);assert.equal(posts[0].get('action'),'save_custom_template');
  assert.equal(posts[0].get('template_game'),'cs2');assert.equal(posts[0].get('template_version'),'2026.10');
  assert.equal(posts[0].get('template_path'),'/home/gamedock/templates/cs2/2026.10');
  posts.length=0;await page.goto(url);await page.selectOption('#template-game','minecraft');
  assert(await type.isVisible());assert(!(await page.locator('[data-custom-template]').isVisible()));
  for(const name of ['vanilla','bukkit','spigot','paper','forge','purpur','fabric']){
   await type.selectOption(name);
   await page.waitForFunction(()=>!document.querySelector('#release-version').disabled);
   assert.equal(await version.locator('option').count(),11);
   assert(await button.isDisabled());
   await version.selectOption(`${name}:1.21.12:build`);assert(!(await button.isDisabled()));
   assert.equal(posts.length,0);
  }
  await page.selectOption('#template-game','custom');
  assert(await button.isDisabled());assert.equal(await page.locator('[name=template_game]').inputValue(),'custom');
  assert.equal(await page.locator('[name=template_path]').inputValue(),'');
  assert.equal(await page.locator('[name=template_name]').inputValue(),'Custom game');
  await page.selectOption('#template-game','minecraft');assert.equal(await type.inputValue(),'');assert(await button.isDisabled());
  // A late response must not populate another type's version picker.
  await type.selectOption('paper');await type.selectOption('forge');
  await page.waitForFunction(()=>document.querySelector('#release-version').options[1]?.value.startsWith('forge:'));
  await page.waitForTimeout(300);
  assert((await version.locator('option').nth(1).getAttribute('value')).startsWith('forge:'));
  assert(await button.isDisabled());
  fail=true;await type.selectOption('purpur');await page.locator('[data-release-retry]').waitFor({state:'visible'});
  assert(await version.isDisabled());assert(await button.isDisabled());
  fail=false;await page.locator('[data-release-retry]').click();await page.waitForFunction(()=>!document.querySelector('#release-version').disabled);
  empty=true;await type.selectOption('fabric');await page.getByRole('status').filter({hasText:'nincs elérhető'}).waitFor();
  assert(await version.isDisabled());assert(await button.isDisabled());
  empty=false;await type.selectOption('vanilla');await page.waitForFunction(()=>!document.querySelector('#release-version').disabled);
  await version.selectOption('vanilla:1.21.11:build');
  // Submitting without the install button must never send an installation request.
  await page.locator('[data-release-form]').evaluate(form=>form.requestSubmit());assert.equal(posts.length,0);
  await Promise.all([page.waitForNavigation(),button.click()]);
  assert.equal(posts.length,1);assert.equal(posts[0].get('action'),'install_template');
  assert.equal(posts[0].get('template_key'),'vanilla:1.21.11:build');assert.equal(posts[0].get('node_id'),'1');assert.equal(posts[0].get('csrf'),'release-picker-test');
  assert.deepEqual(errors,[]);
  console.log('Game/template picker passed: game selection, custom version/path POST, seven Minecraft types, latest ten versions, explicit install only, selection reset, stale responses, error/retry, empty catalog and exact POST payload.');
 }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;});
