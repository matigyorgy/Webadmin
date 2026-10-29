<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';

$expected = [
    'start'=>'Szerver indítása', 'stop'=>'Szerver leállítása',
    'restart'=>'Szerver újraindítása', 'status'=>'Állapot lekérdezése',
    'logs'=>'Konzolnapló lekérdezése', 'command'=>'Konzolparancs küldése',
    'account_update'=>'Saját fiók módosítása', 'version_change'=>'Verzióváltás',
    'server_rename'=>'Szerver átnevezése', 'provision_retry'=>'Fájlmásolás újrapróbálása',
    'game_save'=>'Játéktípus mentése', 'game_delete'=>'Játéktípus törlése',
    'ftp_create'=>'FTP-fiók létrehozása', 'ftp_delete'=>'FTP-fiók törlése',
    'subaccount_save'=>'Alfiók és jogosultságok mentése', 'subaccount_delete'=>'Alfiók törlése',
    'users_save:12'=>'Felhasználó mentése (#12)', 'nodes_delete:3'=>'Linux-gép törlése (#3)',
    'servers_save:7'=>'Játékszerver mentése (#7)', 'node_test:3'=>'SSH-kapcsolat ellenőrzése (#3)',
    'unknown_action'=>'Egyéb művelet',
];
$rows=[];
foreach($expected as $action=>$label)$rows[]=['action'=>$action,'outcome'=>'accepted','created_at'=>'2026-10-29 15:56:52'];
foreach(GameDock\AuditText::rows($rows) as $row){
    if($row['action_label']!==$expected[$row['action']] || $row['outcome_label']!=='Sikeres' || $row['created_at_label']!=='2026.10.29 15:56:52')throw new RuntimeException('Hibás magyar naplófelirat.');
}
foreach(['requested'=>'Kérés rögzítve','failed'=>'Sikertelen','denied'=>'Elutasítva','ready'=>'Elkészült','queued'=>'Várakozik','running'=>'Folyamatban','unknown'=>'Ismeretlen eredmény'] as $code=>$label){
    $row=GameDock\AuditText::rows([['action'=>'start','outcome'=>$code,'created_at'=>'']])[0];
    if($row['outcome_label']!==$label)throw new RuntimeException('Hibás magyar eredmény.');
}
$view=new GameDock\SmartyView(dirname(__DIR__));
foreach(['admin/audit.tpl'=>'rows','partials/history.tpl'=>'history'] as $template=>$key){
    ob_start();
    try{$view->render($template,[$key=>GameDock\AuditText::rows([['action'=>'account_update','outcome'=>'requested','created_at'=>'2026-10-29 15:56:52','user_id'=>1,'server_id'=>0,'email'=>'teszt@example.com']])]);$html=ob_get_contents();}finally{ob_end_clean();}
    if(str_contains($html,'account_update') || str_contains($html,'requested') || !str_contains($html,'Saját fiók módosítása') || !str_contains($html,'Kérés rögzítve'))throw new RuntimeException('A naplósablon nyers kódot jelenít meg.');
}
echo "Magyar naplófeliratok és mindkét naplónézet rendben.\n";
