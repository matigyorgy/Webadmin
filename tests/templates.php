<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
$view = new Webadmin\SmartyView(dirname(__DIR__));
function renderCase(Webadmin\SmartyView $view, array $data): string {
    ob_start();
    try { $view->render('panel.tpl', $data); return ob_get_contents(); }
    finally { ob_end_clean(); }
}
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$base = ['user'=>false,'servers'=>[],'server_count'=>0,'csrf'=>'test-token','error'=>'','notice'=>'','console'=>null,'selected'=>null,'history'=>[],'page'=>'servers','search'=>''];
$html = renderCase($view, $base);
check(str_contains($html, 'autocomplete="current-password"'), 'Login form missing');
check(str_contains($html, 'value="test-token"'), 'CSRF field missing');
$html = renderCase($view, array_replace($base, ['user'=>['email'=>'test@example.com','is_admin'=>0]]));
check(str_contains($html, 'Még nincs hozzárendelt szerver.'), 'Empty state missing');
$server = ['id'=>1,'name'=>'<script>alert(1)</script>','node_name'=>'Linux','address'=>'games.example.test','port'=>25555,'game_name'=>'Minecraft','state'=>'ellenőrzés…','provision_state'=>'ready'];
$html = renderCase($view, array_replace($base, ['user'=>['email'=>'admin@example.com','is_admin'=>1], 'servers'=>[$server], 'server_count'=>1]));
check(str_contains($html, 'Összes szerver'), 'Admin heading missing');
check(str_contains($html,'games.example.test:25555') && str_contains($html,'Kezelés') && !str_contains($html,'Kezelés és konzol') && str_contains($html,'data-status-id="1"'), 'Server list address, management button or automatic state marker missing');
check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'HTML escaping failed');
$html = renderCase($view, array_replace($base, ['user'=>['email'=>'admin@example.com','is_admin'=>1], 'selected'=>['id'=>1,'name'=>'Survival','game_name'=>'Minecraft','node_name'=>'Linux']]));
check(str_contains($html, 'value="restart"'), 'Server actions missing');
$html = renderCase($view, array_replace($base, ['user'=>['email'=>'test@example.com','is_admin'=>0], 'console'=>['id'=>1,'name'=>'Survival','logs'=>'<script>bad()</script>']]));
check(str_contains($html, 'name="command"') && !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'Console rendering/escaping failed');
echo "7 template checks passed.\n";

$commands=new Webadmin\ServerCommand();
check(str_contains($commands->build('minecraft','start','','java -jar server.jar'),'webadmin-launch'), 'Direct launcher missing');
foreach([['x;id','start','', 'java'],['minecraft','shell','','java'],['minecraft','command',"list\nstop",'java'],['minecraft','start','','']] as [$unit,$action,$input,$startup]) {
 $rejected=false;try{$commands->build($unit,$action,$input,$startup);}catch(RuntimeException $e){$rejected=true;}check($rejected,'Unsafe/incomplete command accepted');
}
check(!str_contains($commands->build('minecraft','status'),'systemctl'),'Service dependency retained');
echo "6 direct SSH policy checks passed.\n";

$installer = new Webadmin\Installer(dirname(__DIR__));
$values=['db_host'=>'localhost','db_port'=>'3306','db_name'=>'webadmin','db_user'=>'webadmin','admin_email'=>'',
    'node_name'=>'Linux','ssh_host'=>'','ssh_port'=>'22','ssh_user'=>'webadmin','ssh_fingerprint'=>'',
    'server_name'=>'','game'=>'minecraft','unit_name'=>'minecraft.service'];
$installBase=['installed'=>false,'success'=>null,'error'=>'','values'=>$values,'requirements'=>[], 'csrf'=>'install-test-token','https'=>true];
$html=renderCaseForInstaller($view,$installBase);
check(str_contains($html,'name="db_password"') && str_contains($html,'name="admin_password"') && str_contains($html,'install-test-token'),'Installer form missing');
$html=renderCaseForInstaller($view,array_replace($installBase,['installed'=>true]));
check(str_contains($html,'már telepítve') && !str_contains($html,'name="db_password"'),'Installed form still visible');
$html=renderCaseForInstaller($view,array_replace($installBase,['installed'=>true,'success'=>['public_key'=>'ssh-rsa <unsafe>','has_node'=>false]]));
check(str_contains($html,'Sikeres telepítés') && str_contains($html,'&lt;unsafe&gt;') && !str_contains($html,'name="db_password"'),'Installer success escaping failed');
echo "3 installer template checks passed.\n";
function renderCaseForInstaller(Webadmin\SmartyView $view,array $data): string {
    ob_start();
    try { $view->render('install.tpl',$data); return ob_get_contents(); } finally { ob_end_clean(); }
}

$adminBase=['user'=>['id'=>1,'email'=>'admin@example.com','is_admin'=>1],'csrf'=>'admin-csrf','section'=>'servers','error'=>'','notice'=>'','rows'=>[],
    'record'=>null,'users'=>[],'nodes'=>[],'public_key'=>'ssh-rsa example','startup_supported'=>true,'provision_supported'=>true,'automation_supported'=>true,'storage_supported'=>true,'profiles'=>Webadmin\GameRegistry::defaults(),'catalog'=>['templates'=>[],'state'=>'idle','message'=>''],'selected_node'=>null,'games'=>Webadmin\Validation::GAMES];
function renderTemplate(Webadmin\SmartyView $view,string $template,array $data): string {
    ob_start();
    try { $view->render($template,$data); return ob_get_contents(); } finally { ob_end_clean(); }
}
$html=renderTemplate($view,'admin.tpl',array_replace($adminBase,['section'=>'servers','provision_supported'=>true,'automation_supported'=>false]));
check(str_contains($html,'value="upgrade_startup"'),'Automation upgrade hidden behind legacy provisioning readiness');
$html=renderTemplate($view,'admin.tpl',array_replace($adminBase,['section'=>'servers','provision_supported'=>true,'automation_supported'=>true]));
check(!str_contains($html,'value="upgrade_startup"'),'Upgrade banner remained after automation migration');
$html=renderTemplate($view,'admin.tpl',array_replace($adminBase,['section'=>'servers','storage_supported'=>false]));
check(str_contains($html,'value="upgrade_startup"'),'Storage migration button hidden on older schemas');
$record=['id'=>'','email'=>'','is_admin'=>0,'name'=>'','ssh_host'=>'','ssh_port'=>22,'ssh_user'=>'webadmin','ssh_fingerprint'=>'', 'user_id'=>'','node_id'=>'','game'=>'minecraft','unit_name'=>'','startup_command'=>'','install_source'=>'','install_folder'=>'','provision_job'=>'','provision_state'=>'ready','provision_message'=>'','slug'=>'','existing_slug'=>'','template_path'=>'','provider'=>'folder','port'=>null];
foreach (['users','nodes','servers'] as $section) {
    $html=renderTemplate($view,'admin.tpl',array_replace($adminBase,['section'=>$section,'record'=>$record]));
    check(str_contains($html,'value="save"') && str_contains($html,'admin-csrf'),'Admin form missing');
}
$failedProvision=array_replace($record,['id'=>42,'provision_job'=>str_repeat('a',32),'provision_state'=>'failed']);
$html=renderTemplate($view,'admin.tpl',array_replace($adminBase,['section'=>'servers','record'=>$failedProvision]));
check(str_contains($html,'/var/lib/webadmin/provision/jobs/'.str_repeat('a',32).'.log') && str_contains($html,'Másolási kérés újrapróbálása'),'Provisioning diagnostics or retry guidance missing.');
$html=renderTemplate($view,'admin.tpl',array_replace($adminBase,['section'=>'setup']));
check(str_contains($html,'ssh-rsa example'),'Public key page missing');
$html=renderTemplate($view,'admin.tpl',array_replace($adminBase,['section'=>'audit']));
check(str_contains($html,'Műveleti napló'),'Admin audit missing');
$html=renderTemplate($view,'panel.tpl',array_replace($base,['user'=>['id'=>2,'email'=>'client@example.com','is_admin'=>0],'page'=>'account']));
check(str_contains($html,'name="current_password"') && !str_contains($html,'href="/admin.php"'),'Client profile/admin separation failed');
echo "6 admin/client template checks passed.\n";

$encoded=base64_encode('java -Xmx4G -jar server.jar nogui');
check(str_contains($commands->build('minecraft-panel.service','start','','java -Xmx4G -jar server.jar nogui'),'webadmin-launch') && str_contains($commands->build('minecraft-panel.service','start','','java -Xmx4G -jar server.jar nogui'),$encoded),'Custom launch not routed');
check(str_contains($commands->build('minecraft-panel.service','command','list','java -jar server.jar'),'webadmin-launch'),'Custom console not routed');
check(str_contains($commands->build('minecraft-panel.service','status','','java -jar server.jar'),'webadmin-launch'),'Custom status changed unexpectedly');
foreach (Webadmin\Validation::GAMES as $game=>$label) {
    $custom=array_replace($record,['game'=>$game,'startup_command'=>'./start --name="<unsafe>"']);
    $html=renderTemplate($view,'admin.tpl',array_replace($adminBase,['record'=>$custom]));
    check(str_contains($html,'name="startup_command"') && !str_contains($html,'<unsafe>'),'Custom command escaping failed');
}
echo "7 startup routing/template checks passed.\n";

$helperFailures=[
 [false,null,'',true,'időben'],
 ['',1,'sudo: a password is required',false,'sudo-joga'],
 ['',1,'sudo: user is not allowed to execute',false,'sudo-joga'],
 ['',1,'FileNotFoundError: webadmin_runtime.py No such file',false,'webadmin_runtime.py'],
 ['',127,'sudo: /usr/local/sbin/webadmin-templates: command not found',false,'hiányzik'],
 ['',1,'SyntaxError: invalid syntax',false,'Python'],
 ['',1,'PermissionError: permission denied',false,'jogosultsági'],
 ['not json',0,'',false,'JSON'],
 ['{}',0,'',false,'kompatibilis'],
];
foreach($helperFailures as [$stdout,$exit,$stderr,$timeout,$expected]) {
 $caught=false;try{Webadmin\TemplateCommandResult::parse($stdout,$exit,$stderr,$timeout);}catch(RuntimeException $e){$caught=str_contains($e->getMessage(),$expected);}
 check($caught,'Template helper failure misdiagnosed: '.$expected);
}
check(Webadmin\TemplateCommandResult::parse('{"state":"idle","templates":[]}',0,'')['state']==='idle','Empty valid catalog rejected');
echo "10 template helper diagnostic checks passed.\n";

check(Webadmin\ResourceLimits::validate(['memory_limit_mb'=>'2048','cpu_limit_percent'=>'150'])===['memory_limit_mb'=>2048,'cpu_limit_percent'=>150],'Resource limits lost');
foreach (['-1','1.5','10;id',1048577,[],true] as $bad) {
 $caught=false;try {Webadmin\ResourceLimits::validate(['memory_limit_mb'=>$bad]);} catch(RuntimeException $e) {$caught=true;}check($caught,'Invalid limit accepted');
}
$launch=$commands->build('example','start','','java -jar server.jar',['memory_limit_mb'=>4096,'cpu_limit_percent'=>200]);
check(str_contains($launch,base64_encode('{"memory_limit_mb":4096,"cpu_limit_percent":200}')),'Limits missing from launch command');
$occupied=range(25000,25999);unset($occupied[523]);
check(Webadmin\PortAllocator::choose($occupied)===25523,'Allocator did not select only free port');
$caught=false;try{Webadmin\PortAllocator::choose(range(25000,25999));}catch(RuntimeException $e){$caught=true;}check($caught,'Full range accepted');
$occupied=[25000,25999];
for($i=0;$i<100;$i++){$port=Webadmin\PortAllocator::choose($occupied);check($port>=25000 && $port<=25999 && !in_array($port,$occupied,true),'Port collided');$occupied[]=$port;}
echo "Resource limits and 102 port allocation checks passed.\n";

$selected=['id'=>1,'name'=>'Survival','game_name'=>'Minecraft','node_name'=>'Linux','address'=>'[2001:db8::1]','port'=>25523,'memory_limit_mb'=>2048,'cpu_limit_percent'=>150];
$html=renderTemplate($view,'panel.tpl',array_replace($base,['user'=>['id'=>2,'email'=>'client@example.com','is_admin'=>0],'selected'=>$selected]));
check(str_contains($html,'[2001:db8::1]:25523') && str_contains($html,'2048 MiB') && !str_contains($html,'150%') && str_contains($html,'data-memory'),'Connection/resource view missing');
echo "Connection address and resource view passed.\n";

$prepared=Webadmin\StartupCommand::prepare('java -Xms1G -Xmx4G -jar server.jar nogui',['memory_limit_mb'=>2048,'cpu_limit_percent'=>150]);
check(str_contains($prepared,'WEBADMIN_MEMORY_MB=2048 WEBADMIN_CPU_PERCENT=150') && str_contains($prepared,'-Xmx1638M') && str_contains($prepared,'-XX:ActiveProcessorCount=2'),'Startup limits not applied');
$prepared=Webadmin\StartupCommand::prepare($prepared,['memory_limit_mb'=>1024,'cpu_limit_percent'=>100]);
check(substr_count($prepared,'export WEBADMIN_MEMORY_MB=')===1 && str_contains($prepared,'-Xmx819M') && !str_contains($prepared,'ActiveProcessorCount=2'),'Updated startup limits are stale');
echo "Startup command limit checks passed.\n";

foreach ([
 "sudo: I'm sorry webadmin. I'm afraid I can't do that"=>'sudo elutasította',
 'ValueError: SteamCMD 32-bit runtime missing'=>'32 bites futtatókönyvtárai hiányoznak',
 'ValueError: Another template installation is running'=>'másik template telepítése fut',
 'ValueError: Unknown operation'=>'régebbi Minecraft-helper',
 'ValueError: Template synchronization is running'=>'szinkronizálása még fut',
 'ValueError: Template is in use by an active copy job'=>'verzióváltás fut',
 'OSError: [Errno 30] Read-only file system'=>'csak olvasható',
 'ValueError: Unsafe template publication directory'=>'könyvtár tulajdonosa',
 "AttributeError: module os has no attribute pidfd_open"=>'Python-verziója túl régi',
 "KeyError: 'templates'"=>'szerkezete nem kompatibilis',
] as $stderr=>$expected) {
 $caught=false;try{Webadmin\TemplateCommandResult::parse('',1,$stderr);}catch(RuntimeException $error){$caught=str_contains($error->getMessage(),$expected);}check($caught,'Specific helper diagnosis missing');
}
$log=tempnam(sys_get_temp_dir(),'webadmin-errors-');$oldLog=ini_get('error_log');ini_set('error_log',$log);
try {
 try {Webadmin\TemplateCommandResult::parse('',1,'RuntimeError: unusual failure password=fixture-secret https://user:fixture-password@example.com');}catch(RuntimeException $error){check(str_contains($error->getMessage(),'Hibaazonosító:') && !str_contains($error->getMessage(),'fixture-secret'),'Unknown helper error leaked to UI');}
 $diagnostic=file_get_contents($log);check(str_contains($diagnostic,'unusual failure') && !str_contains($diagnostic,'fixture-secret') && !str_contains($diagnostic,'fixture-password'),'Diagnostic redaction failed');
} finally {ini_set('error_log',$oldLog);unlink($log);}
echo "Specific Linux helper errors and redacted diagnostic logging passed.\n";
