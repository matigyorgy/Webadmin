<?php
declare(strict_types=1);
namespace GameDock;
final class StartupCommand
{
    public static function prepare(string $command,array $limits): string
    {
        $limits=ResourceLimits::validate($limits);
        $command=preg_replace('/\Aexport GAMEDOCK_MEMORY_MB=[0-9]+ GAMEDOCK_CPU_PERCENT=[0-9]+; /','',$command);
        $memory=$limits['memory_limit_mb'];$cpu=$limits['cpu_limit_percent'];
        $java="/\\A(?:[A-Za-z_][A-Za-z0-9_]*=(?:'[^']*'|\"[^\"]*\"|[^\\s]+)\\s+)*\\K(java|\\/[A-Za-z0-9_.\\/-]+\\/java)(?=\\s)/";
        if (preg_match($java,$command)) {
            if ($memory>0) {
                $heap=max(1,(int)floor($memory*0.8));
                $command=preg_replace('/(?<!\S)-Xm[xs][0-9]+[kKmMgG]?(?:[ \t]+|$)/','',$command);
                $command=preg_replace($java,'$1 -Xms'.min(256,$heap).'M -Xmx'.$heap.'M',$command,1);
            }
            $command=preg_replace('/(?<!\S)-XX:ActiveProcessorCount=[0-9]+(?:[ \t]+|$)/','',$command);
            if ($cpu>0) $command=preg_replace($java,'$1 -XX:ActiveProcessorCount='.(int)ceil($cpu/100),$command,1);
        }
        return Validation::startup('export GAMEDOCK_MEMORY_MB='.$memory.' GAMEDOCK_CPU_PERCENT='.$cpu.'; '.$command);
    }
}
