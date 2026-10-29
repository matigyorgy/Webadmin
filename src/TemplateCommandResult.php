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
            if(str_contains($error,'password is required') || str_contains($error,'not allowed') || str_contains($error,'not in the sudoers') || str_contains($error,'no tty') || str_contains($error,'a terminal is required')) throw new RuntimeException('Az SSH-belépés működik, de a panel SSH-fiókjának nincs jelszó nélküli sudo-joga a Minecraft-segédprogramhoz.'.$repair);
            if(str_contains($error,'gamedock_runtime') && (str_contains($error,'no such file') || str_contains($error,'modulenotfound') || str_contains($error,'importerror'))) throw new RuntimeException('A Linux-segédprogram frissítése hiányos: a gamedock_runtime.py hiányzik vagy nem olvasható.'.$repair);
            if($exit===127 || str_contains($error,'command not found') || str_contains($error,'no such file or directory') || str_contains($error,'unable to execute')) throw new RuntimeException('A Linux-gépen hiányzik vagy nem indítható a /usr/local/sbin/gamedock-templates program (vagy a Python 3 értelmezője).'.$repair);
            if(str_contains($error,'syntaxerror') || str_contains($error,'modulenotfounderror') || str_contains($error,'importerror')) throw new RuntimeException('A Minecraft-segédprogram Python-futási hibával állt le. Python 3.9+ és az összes friss Linux-helper szükséges.'.$repair);
            if(str_contains($error,'permission denied') || str_contains($error,'permissionerror')) throw new RuntimeException('A Minecraft-segédprogram jogosultsági hibával állt le.'.$repair);
            if(str_contains($error,'jsondecodeerror')) throw new RuntimeException('A Linux-segédprogram JSON-beállítása vagy katalógusa sérült. Ez nem hálózati letöltési hiba; a Linux-helper és a mentett katalógus ellenőrzése szükséges.');
            throw new RuntimeException('A Minecraft-segédprogram elindult, de hibával leállt (kilépési kód: '.(is_scalar($exit) ? (string)$exit : 'ismeretlen').').'.$repair);
        }
        try {$result=json_decode($output,true,512,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new RuntimeException('A Linux-segédprogram nem érvényes JSON-katalógust adott vissza. Frissítsd a Linux-helper fájlokat az automatikus telepítővel.');}
        if(!is_array($result) || !isset($result['state']) || !isset($result['templates']) || !is_array($result['templates'])) throw new RuntimeException('A Linux-segédprogram verziója nem kompatibilis a panellel. Frissítsd a Linux-helper fájlokat az automatikus telepítővel.');
        return $result;
    }
}
