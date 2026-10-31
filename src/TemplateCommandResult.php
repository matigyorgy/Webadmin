<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
use JsonException;
final class TemplateCommandResult
{
    public static function parse(string|false $output,mixed $exit,string $stderr,bool $timeout=false): array
    {
        if($timeout || $output===false || $exit===null) throw new RuntimeException('Az SSH-belépés sikerült, de a Minecraft-segédprogram válasza nem érkezett meg időben. Próbáld újra az állapotlekérést.');
        if($exit!==0) {
            $error=strtolower($stderr.' '.($output ?: ''));
            $repair=' Az adott gép szerkesztésénél indítsd a Linux beállítása automatikusan műveletet a friss panelből.';
            $known=[
                'unknown operation'=>'A Linux-gépen régebbi Minecraft-helper fut, amely még nem támogatja ezt a műveletet.'.$repair,
                'another template installation is running'=>'Ezen a Linux-gépen már egy másik template telepítése fut. Várd meg a befejezését; az állapot a Telepített templatek és szabályok résznél követhető.',
                'template synchronization is running'=>'A Minecraft-katalógus szinkronizálása még fut. A törlést a letöltés/fordítás befejezése után próbáld újra.',
                'template is in use by an active copy job'=>'Erről a template-ről éppen szervermásolás vagy verzióváltás fut. Várd meg a befejezését, majd töröld a sablont.',
                'unknown template'=>'Ez a template már nincs a Linux-gép katalógusában. Frissítsd a Minecraft-templatek oldalát.',
                'catalog path mismatch'=>'A katalógus még a korábbi template-mappára hivatkozik. Futtasd a friss panel webes Linux-telepítését a /home/gamedock/templates/ mappára költöztetéshez.',
                'unsafe template publication directory'=>'A Minecraft-template könyvtár tulajdonosa vagy jogosultsága nem megfelelő.'.$repair,
                'unsafe config'=>'A Linux-oldali /etc/gamedock/servers.json tulajdonosa vagy jogosultsága nem megfelelő.'.$repair,
                'no space left on device'=>'Elfogyott a szabad hely vagy az inode a Linux-gépen. A szinkronizálás előtt szabadíts fel helyet; a helper újratelepítése ezt nem oldja meg.',
                'disk quota exceeded'=>'A Linux-gépen elfogyott a lemezkvóta. A szinkronizálás előtt szabadíts fel helyet vagy növeld a kvótát.',
                'read-only file system'=>'A Linux-gép érintett fájlrendszere csak olvasható. Ellenőrizd a kötet állapotát; a helper újratelepítése ezt nem oldja meg.',
            ];
            foreach($known as $needle=>$message) if(str_contains($error,$needle)) throw new RuntimeException($message);
            if(str_contains($error,'attributeerror') && (str_contains($error,'pidfd') || str_contains($error,'is_relative_to'))) throw new RuntimeException('A Linux-gép Python-verziója túl régi ehhez a helperhez. Python 3.9+ és pidfd-t támogató Linux szükséges.'.$repair);
            if(str_contains($error,'keyerror') || (str_contains($error,'attributeerror') && str_contains($error,"has no attribute 'get'")) || str_contains($error,'string indices must be integers')) throw new RuntimeException('A Linux-oldali katalógus vagy beállítás szerkezete nem kompatibilis a panellel. A katalógust ne töröld kézzel; a részletes Python-hiba szükséges az adatvesztés nélküli javításhoz.');
            if(str_contains($error,'sudo:') && str_contains($error,"afraid i can't do that")) throw new RuntimeException('A sudo elutasította a Minecraft-műveletet, a törlőprogram el sem indult. A panel SSH-fiókjának engedélyezni kell a gamedock-templates delete műveletet.'.$repair);
            if(str_contains($error,'password is required') || str_contains($error,'not allowed') || str_contains($error,'not in the sudoers') || str_contains($error,'no tty') || str_contains($error,'a terminal is required')) throw new RuntimeException('Az SSH-belépés működik, de a panel SSH-fiókjának nincs jelszó nélküli sudo-joga a Minecraft-segédprogramhoz.'.$repair);
            if(str_contains($error,'gamedock_runtime') && (str_contains($error,'no such file') || str_contains($error,'modulenotfound') || str_contains($error,'importerror'))) throw new RuntimeException('A Linux-segédprogram frissítése hiányos: a gamedock_runtime.py hiányzik vagy nem olvasható.'.$repair);
            if($exit===127 || str_contains($error,'command not found') || str_contains($error,'no such file or directory') || str_contains($error,'unable to execute')) throw new RuntimeException('A Linux-gépen hiányzik vagy nem indítható a /usr/local/sbin/gamedock-templates program (vagy a Python 3 értelmezője).'.$repair);
            if(str_contains($error,'syntaxerror') || str_contains($error,'modulenotfounderror') || str_contains($error,'importerror')) throw new RuntimeException('A Minecraft-segédprogram Python-futási hibával állt le. Python 3.9+ és az összes friss Linux-helper szükséges.'.$repair);
            if(str_contains($error,'permission denied') || str_contains($error,'permissionerror')) throw new RuntimeException('A Minecraft-segédprogram jogosultsági hibával állt le.'.$repair);
            if(str_contains($error,'jsondecodeerror')) throw new RuntimeException('A Linux-segédprogram JSON-beállítása vagy katalógusa sérült. Ez nem hálózati letöltési hiba; a Linux-helper és a mentett katalógus ellenőrzése szükséges.');
            $reference=bin2hex(random_bytes(6));
            $detail=$stderr!=='' ? $stderr : (string)$output;
            // Keep diagnostics in the PHP log, never send arbitrary SSH stderr to clients.
            $detail=preg_replace('/-----BEGIN[^-]*PRIVATE KEY-----.*?-----END[^-]*PRIVATE KEY-----/s','[REDACTED PRIVATE KEY]',$detail);
            $detail=preg_replace('~(https?://)[^\s/@]+:[^\s/@]+@~i','$1[REDACTED]@',$detail);
            $detail=preg_replace('/((?:password|passphrase|token|secret)\s*[=:]\s*)[^\s,;]+/i','$1[REDACTED]',$detail);
            $detail=preg_replace('/[\x00-\x1f\x7f]+/',' | ',$detail);
            error_log('GameDock Minecraft helper ['.$reference.'] exit='.(is_scalar($exit) ? (string)$exit : 'unknown').' detail='.mb_substr($detail,0,2000));
            preg_match('/\b([A-Za-z]+(?:Error|Exception))\s*:/',$stderr,$type);
            throw new RuntimeException('A Minecraft-segédprogram hibával leállt (kilépési kód: '.(is_scalar($exit) ? (string)$exit : 'ismeretlen').').'.(!empty($type[1]) ? ' Python-hiba: '.$type[1].'.' : '').' Hibaazonosító: '.$reference.'. A részlet a panel PHP error_log naplójában található ezen az azonosítón.');
        }
        try {$result=json_decode($output,true,512,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new RuntimeException('A Linux-segédprogram nem érvényes JSON-katalógust adott vissza. Frissítsd a Linux-helper fájlokat az automatikus telepítővel.');}
        if(!is_array($result) || !isset($result['state']) || !isset($result['templates']) || !is_array($result['templates'])) throw new RuntimeException('A Linux-segédprogram verziója nem kompatibilis a panellel. Frissítsd a Linux-helper fájlokat az automatikus telepítővel.');
        return $result;
    }
}
