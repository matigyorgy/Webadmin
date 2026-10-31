// Runs only against the isolated 70-template fixture; no upstream game artifacts are downloaded.
const {chromium}=require('playwright');
(async()=>{
 const base=process.env.GAMEDOCK_TEST_URL;
 if(!base || !['127.0.0.1','localhost'].includes(new URL(base).hostname))throw Error('Loopback fixture URL required');
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 const admin=await browser.newPage({viewport:{width:1440,height:1000}}),client=await browser.newPage();
 const failures=[];for(const page of [admin,client])page.on('pageerror',error=>failures.push(error.message));
 async function login(page,email){await page.goto(base+'/');await page.fill('#email',email);await page.fill('#password','Fixture-password-123');await Promise.all([page.waitForNavigation(),page.click('button[value=login]')]);}
 async function action(page,values){return page.evaluate(async values=>{const data=new FormData();data.set('csrf',document.querySelector('[name=csrf]').value);data.set('server_id','710001');for(const [key,value]of Object.entries(values))data.set(key,value);const r=await fetch('/api.php',{method:'POST',body:data});return {status:r.status,body:await r.json()};},values);}
 await login(admin,'admin@example.com');await admin.goto(base+'/admin.php?section=templates&node=1');
 if(!await admin.getByRole('heading',{name:'Játékszerver-templatek',exact:true}).count())throw Error('General heading missing');
 for(const type of ['vanilla','bukkit','spigot','paper','forge','purpur','fabric']){
  await admin.selectOption('#release-type',type);await admin.waitForFunction(()=>document.querySelector('#release-version').options.length===11 && !document.querySelector('#release-version').disabled);
  if(await admin.locator('#release-version option').count()!==11)throw Error('Not ten releases: '+type);
 }
 await login(client,'client@example.com');await client.goto(base+'/?server=710001');await client.locator('a[href="#version-switch"]').click();await client.waitForFunction(()=>document.querySelectorAll('#version-choice optgroup').length===7);
 let result=await action(client,{action:'start'});if(result.status!==200)throw Error('Fixture startup failed '+JSON.stringify(result));
 // Set a finite server limit using the actual admin form while the fixture is running.
 await admin.goto(base+'/admin.php?section=servers&edit=710001');await admin.locator('[name=memory_limit_mb]').fill('512');await Promise.all([admin.waitForNavigation(),admin.getByRole('button',{name:'Mentés',exact:true}).first().click()]);
 await client.reload();await client.locator('a[href="#version-switch"]').click();await client.waitForFunction(()=>document.querySelectorAll('#version-choice optgroup').length===7);
 if(!await client.locator('#version-form button').isDisabled())throw Error('Online switch enabled');
 result=await action(client,{action:'change_version',template_key:'paper:1.21.8:template-test'});if(result.status===200 || !result.body.error.includes('állítsd le'))throw Error('Online server switch accepted');
 result=await action(client,{action:'stop'});if(result.status!==200)throw Error('Stop failed');
 await client.reload();await client.locator('a[href="#version-switch"]').click();await client.waitForFunction(()=>document.querySelector('#version-choice option[value="paper:1.21.10:template-test"]')?.disabled);
 result=await action(client,{action:'change_version',template_key:'paper:1.21.10:template-test'});if(result.status===200 || !result.body.error.includes('2048'))throw Error('Insufficient memory accepted');
 result=await action(client,{action:'change_version',template_key:'paper:1.21.9:template-test'});if(result.status===200 || !result.body.error.includes('Erősítsd'))throw Error('Unconfirmed reinstall accepted');
 await client.selectOption('#version-choice','paper:1.21.9:template-test');if(!await client.locator('[data-reinstall-warning]').isVisible() || !await client.locator('#version-form button').isDisabled())throw Error('Confirmation UI missing');
 // First do a preserving cross-type change, then a clean reinstall.
 await client.selectOption('#version-choice','spigot:1.21.8:template-test');await client.locator('#version-form button').click();await client.waitForFunction(()=>document.querySelector('[data-current-version]').textContent.includes('spigot · 1.21.8'),null,{timeout:45000});
 await client.selectOption('#version-choice','paper:1.21.9:template-test');await client.check('[name=confirm_reinstall]');await client.locator('#version-form button').click();await client.waitForFunction(()=>document.querySelector('[data-current-version]').textContent.includes('paper · 1.21.9'),null,{timeout:45000});
 if(!await client.locator('[data-platform-version]').first().textContent().then(t=>t.includes('Paper 1.21.9')))throw Error('Overview version label stale');
 await admin.goto(base+'/admin.php?section=templates&node=1');await admin.screenshot({path:'/tmp/gamedock-template-admin.png',fullPage:true});
 await client.screenshot({path:'/tmp/gamedock-template-client.png',fullPage:true});
 if(failures.length)throw Error(failures.join('\n'));
 await browser.close();console.log('Seven categories × ten releases, real online rejection, minimum-memory backend enforcement, confirmation enforcement, cross-type replacement and clean reinstall passed.');
})().catch(error=>{console.error(error);process.exit(1);});
