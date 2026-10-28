<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
$view = new GameDock\SmartyView(dirname(__DIR__));
function renderCase(GameDock\SmartyView $view, array $data): string {
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
$server = ['id'=>1,'name'=>'<script>alert(1)</script>','node_name'=>'Linux','game_name'=>'Minecraft','state'=>'active'];
$html = renderCase($view, array_replace($base, ['user'=>['email'=>'admin@example.com','is_admin'=>1], 'servers'=>[$server], 'server_count'=>1]));
check(str_contains($html, 'Összes szerver'), 'Admin heading missing');
check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'HTML escaping failed');
$html = renderCase($view, array_replace($base, ['user'=>['email'=>'admin@example.com','is_admin'=>1], 'selected'=>['id'=>1,'name'=>'Survival','game_name'=>'Minecraft','node_name'=>'Linux']]));
check(str_contains($html, 'value="restart"'), 'Server actions missing');
$html = renderCase($view, array_replace($base, ['user'=>['email'=>'test@example.com','is_admin'=>0], 'console'=>['id'=>1,'name'=>'Survival','logs'=>'<script>bad()</script>']]));
check(str_contains($html, 'name="command"') && !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'Console rendering/escaping failed');
echo "7 template checks passed.\n";

$commands = new GameDock\ServerCommand();
check($commands->build('minecraft.service','start') === "/usr/bin/sudo -n /usr/bin/systemctl start 'minecraft.service'", 'Start command mismatch');
foreach ([['x;id.service','start',''], ['minecraft.service','shell',''], ['minecraft.service','command',"list\nstop"], ['minecraft.service','command',str_repeat('a',501)]] as [$unit,$action,$input]) {
    $rejected = false;
    try { $commands->build($unit,$action,$input); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, 'Unsafe command accepted');
}
$input = 'say hello; $(id)';
check(str_ends_with($commands->build('minecraft.service','command',$input), escapeshellarg(base64_encode($input))), 'Console payload not encoded');
echo "6 SSH command policy checks passed.\n";

$installer = new GameDock\Installer(dirname(__DIR__));
$values=['db_host'=>'localhost','db_port'=>'3306','db_name'=>'webadmin','db_user'=>'webadmin','admin_email'=>'',
    'node_name'=>'Linux','ssh_host'=>'','ssh_port'=>'22','ssh_user'=>'gamedock','ssh_fingerprint'=>'',
    'server_name'=>'','game'=>'minecraft','unit_name'=>'minecraft.service'];
$installBase=['installed'=>false,'success'=>null,'error'=>'','values'=>$values,'requirements'=>[], 'csrf'=>'install-test-token','https'=>true];
$html=renderCaseForInstaller($view,$installBase);
check(str_contains($html,'name="db_password"') && str_contains($html,'name="admin_password"') && str_contains($html,'install-test-token'),'Installer form missing');
$html=renderCaseForInstaller($view,array_replace($installBase,['installed'=>true]));
check(str_contains($html,'már telepítve') && !str_contains($html,'name="db_password"'),'Installed form still visible');
$html=renderCaseForInstaller($view,array_replace($installBase,['installed'=>true,'success'=>['public_key'=>'ssh-rsa <unsafe>','has_node'=>false]]));
check(str_contains($html,'Sikeres telepítés') && str_contains($html,'&lt;unsafe&gt;') && !str_contains($html,'name="db_password"'),'Installer success escaping failed');
echo "3 installer template checks passed.\n";
function renderCaseForInstaller(GameDock\SmartyView $view,array $data): string {
    ob_start();
    try { $view->render('install.tpl',$data); return ob_get_contents(); } finally { ob_end_clean(); }
}

$adminBase=['user'=>['id'=>1,'email'=>'admin@example.com','is_admin'=>1],'csrf'=>'admin-csrf','section'=>'servers','error'=>'','notice'=>'','rows'=>[],
    'record'=>null,'users'=>[],'nodes'=>[],'public_key'=>'ssh-rsa example','startup_supported'=>true,'provision_supported'=>true,'games'=>GameDock\Validation::GAMES];
function renderTemplate(GameDock\SmartyView $view,string $template,array $data): string {
    ob_start();
    try { $view->render($template,$data); return ob_get_contents(); } finally { ob_end_clean(); }
}
$record=['id'=>'','email'=>'','is_admin'=>0,'name'=>'','ssh_host'=>'','ssh_port'=>22,'ssh_user'=>'gamedock','ssh_fingerprint'=>'', 'user_id'=>'','node_id'=>'','game'=>'minecraft','unit_name'=>'','startup_command'=>'','install_source'=>'','install_folder'=>'','provision_job'=>'','provision_state'=>'ready','provision_message'=>''];
foreach (['users','nodes','servers'] as $section) {
    $html=renderTemplate($view,'admin.tpl',array_replace($adminBase,['section'=>$section,'record'=>$record]));
    check(str_contains($html,'value="save"') && str_contains($html,'admin-csrf'),'Admin form missing');
}
$html=renderTemplate($view,'admin.tpl',array_replace($adminBase,['section'=>'setup']));
check(str_contains($html,'ssh-rsa example'),'Public key page missing');
$html=renderTemplate($view,'admin.tpl',array_replace($adminBase,['section'=>'audit']));
check(str_contains($html,'Műveleti napló'),'Admin audit missing');
$html=renderTemplate($view,'panel.tpl',array_replace($base,['user'=>['id'=>2,'email'=>'client@example.com','is_admin'=>0],'page'=>'account']));
check(str_contains($html,'name="current_password"') && !str_contains($html,'href="/admin.php"'),'Client profile/admin separation failed');
echo "6 admin/client template checks passed.\n";

$encoded=base64_encode('java -Xmx4G -jar server.jar nogui');
check(str_contains($commands->build('minecraft-panel.service','start','','java -Xmx4G -jar server.jar nogui'),'gamedock-launch') && str_contains($commands->build('minecraft-panel.service','start','','java -Xmx4G -jar server.jar nogui'),$encoded),'Custom launch not routed');
check(str_contains($commands->build('minecraft-panel.service','command','list','java -jar server.jar'),'gamedock-launch'),'Custom console not routed');
check(str_contains($commands->build('minecraft-panel.service','status','','java -jar server.jar'),'systemctl show'),'Custom status changed unexpectedly');
foreach (GameDock\Validation::GAMES as $game=>$label) {
    $custom=array_replace($record,['game'=>$game,'startup_command'=>'./start --name="<unsafe>"']);
    $html=renderTemplate($view,'admin.tpl',array_replace($adminBase,['record'=>$custom]));
    check(str_contains($html,'name="startup_command"') && !str_contains($html,'<unsafe>'),'Custom command escaping failed');
}
echo "7 startup routing/template checks passed.\n";
