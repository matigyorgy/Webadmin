<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class ResourceLimits
{
    public static function validate(array $data): array
    {
        $result=[];
        foreach (['memory_limit_mb'=>1048576,'cpu_limit_percent'=>102400] as $key=>$max) {
            $value=$data[$key] ?? 0;
            if ($value==='') $value=0;
            if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[0-9]{1,7}\z/',(string)$value) || (int)$value>$max) throw new RuntimeException('Érvénytelen CPU- vagy memórialimit. Csak nem negatív egész szám adható meg.');
            $result[$key]=(int)$value;
        }
        return $result;
    }
}
