<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class PortAllocator
{
    public static function choose(array $occupied): int
    {
        $free=array_values(array_diff(range(25000,25999),array_map('intval',$occupied)));
        if (!$free) throw new RuntimeException('A 25000–25999 porttartomány ezen a gépen megtelt.');
        return $free[random_int(0,count($free)-1)];
    }
}
