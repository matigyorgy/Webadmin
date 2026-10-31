<?php
declare(strict_types=1);
namespace GameDock;
final class AuditText
{
    public static function rows(array $rows): array
    {
        foreach($rows as &$row){
            $row['action_label']=self::action((string)($row['action'] ?? ''));
            $row['outcome_label']=['requested'=>'Kérés rögzítve','accepted'=>'Sikeres','failed'=>'Sikertelen','denied'=>'Elutasítva','ready'=>'Elkészült','queued'=>'Várakozik','running'=>'Folyamatban'][$row['outcome'] ?? ''] ?? 'Ismeretlen eredmény';
            $row['created_at_label']=str_replace('-','.',(string)($row['created_at'] ?? ''));
        }return $rows;
    }
    public static function action(string $action): string
    {
        $labels=['automation_schedule_add'=>'Időzítés létrehozása','automation_schedule_delete'=>'Időzítés törlése','automation_backup'=>'Tömörített mentés indítása','automation_restore'=>'Biztonsági mentés visszaállítása','automation_backup_delete'=>'Biztonsági mentés törlése','account_update'=>'Saját fiók módosítása','start'=>'Szerver indítása','stop'=>'Szerver leállítása','restart'=>'Szerver újraindítása','status'=>'Állapot lekérdezése','logs'=>'Konzolnapló lekérdezése','command'=>'Konzolparancs küldése','version_change'=>'Verzióváltás','server_rename'=>'Szerver átnevezése','provision_retry'=>'Fájlmásolás újrapróbálása','game_save'=>'Játéktípus mentése','game_delete'=>'Játéktípus törlése','ftp_create'=>'FTP-fiók létrehozása','ftp_delete'=>'FTP-fiók törlése','subaccount_save'=>'Alfiók és jogosultságok mentése','subaccount_delete'=>'Alfiók törlése'];
        if(isset($labels[$action]))return $labels[$action];
        if(preg_match('/\A(users|nodes|servers)_(save|delete):([0-9]+)\z/',$action,$m))return ['users'=>'Felhasználó','nodes'=>'Linux-gép','servers'=>'Játékszerver'][$m[1]].($m[2]==='save' ? ' mentése' : ' törlése').' (#'.$m[3].')';
        if(preg_match('/\Anode_test:([0-9]+)\z/',$action,$m))return 'SSH-kapcsolat ellenőrzése (#'.$m[1].')';
        return 'Egyéb művelet';
    }
}
