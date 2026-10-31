// php tests/fixtures/ftp.php > /tmp/ftp.html; node tests/browser_ftp.cjs /tmp/ftp.html
const assert=require('node:assert/strict'),fs=require('node:fs'),http=require('node:http'),path=require('node:path');
const {chromium}=require('playwright');
(async()=>{
 const html=fs.readFileSync(process.argv[2],'utf8'),js=fs.readFileSync(path.join(__dirname,'../web/panel.js'),'utf8');
 const server=http.createServer((req,res)=>{res.setHeader('Content-Type',req.url==='/panel.js'?'text/javascript':'text/html; charset=utf-8');res.end(req.url==='/panel.js'?js:html+'<script src="/panel.js"></script>');});await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));let browser;
 try{
  browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});const page=await browser.newPage(),posts=[],errors=[];page.on('pageerror',e=>errors.push(e.message));
  let state={configured:false,ready:false,accounts:[],host:'games.example.test'};
  await page.route('**/api.php*',async route=>{
   const request=route.request();if(request.method()==='POST'){
    const data=new URLSearchParams();for(const m of request.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g))data.set(m[1],m[2]);posts.push(data);assert.equal(data.get('csrf'),'ftp-test');assert.equal(data.get('server_id'),'42');
    if(data.get('action')==='ftp_create')state.accounts.push({username:data.get('ftp_username'),created_at:1793458800,last_login:null,expires_at:1794754800});
    if(data.get('action')==='ftp_delete'){assert.equal(data.get('ftp_password'),null);state.accounts=state.accounts.filter(a=>a.username!==data.get('ftp_username'));}
   }
   await route.fulfill({json:state});
  });
  const url=`http://127.0.0.1:${server.address().port}/#ftp-access`;await page.goto(url);await page.getByRole('status').filter({hasText:'Pure-FTPd még nincs'}).waitFor();assert(await page.locator('[name=ftp_username]').isDisabled());
  state={configured:true,ready:true,accounts:[],host:'games.example.test',port:21};await page.goto(url);await page.waitForFunction(()=>!document.querySelector('fieldset').disabled);
  await page.locator('[name=ftp_username]').fill('chosen_user');await page.locator('[name=ftp_password]').fill('Fixture-FTP-password-123');await page.locator('[name=ftp_password_confirm]').fill('Different-password-123');await page.getByRole('button',{name:'FTP-fiók létrehozása'}).click();assert.equal(posts.length,0);
  await page.locator('[name=ftp_password_confirm]').fill('Fixture-FTP-password-123');await page.getByRole('button',{name:'FTP-fiók létrehozása'}).click();await page.getByText('chosen_user',{exact:true}).waitFor();
  assert.equal(posts[0].get('ftp_username'),'chosen_user');assert.equal(posts[0].get('ftp_password'),'Fixture-FTP-password-123');assert.equal(await page.locator('[name=ftp_password]').inputValue(),'');assert.equal(await page.locator('[name=ftp_password_confirm]').inputValue(),'');
  assert.equal(await page.locator('[data-ftp-host]').textContent(),'games.example.test');assert.equal(await page.locator('[data-ftp-port]').textContent(),'21');assert(await page.getByText(/nem titkosított/).count());
  for(const username of ['second_user','third_user']){await page.locator('[name=ftp_username]').fill(username);await page.locator('[name=ftp_password]').fill('Fixture-FTP-password-123');await page.locator('[name=ftp_password_confirm]').fill('Fixture-FTP-password-123');await page.getByRole('button',{name:'FTP-fiók létrehozása'}).click();await page.getByText(username,{exact:true}).waitFor();}
  assert.equal(await page.getByRole('button',{name:'Fiók törlése'}).count(),3);assert(await page.getByRole('button',{name:'FTP-fiók létrehozása'}).isDisabled());
  page.once('dialog',dialog=>dialog.accept());await page.getByRole('button',{name:'Fiók törlése'}).first().click();await page.waitForFunction(()=>document.querySelectorAll('[data-ftp-accounts] button').length===2);assert.equal(posts.at(-1).get('action'),'ftp_delete');assert.equal(posts.at(-1).get('ftp_username'),'chosen_user');assert.deepEqual(errors,[]);
  console.log('FTP browser passed: plain FTP on port 21, three-account cap, create/delete per account, chosen credentials and setup prerequisite.');
 }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;});
