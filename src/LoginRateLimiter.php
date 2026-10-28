<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class LoginRateLimiter
{
    public function consume(string $email, string $address): void
    {
        $this->bucket('ip:'.$address, 40);
        $this->bucket('account:'.strtolower($email), 10);
    }
    public function clearAccount(string $email): void
    {
        $path=Config::root().'/var/login/'.hash('sha256','account:'.strtolower($email)).'.json';
        $handle=@fopen($path,'c+');
        if ($handle && flock($handle,LOCK_EX)) {
            rewind($handle); ftruncate($handle,0); fwrite($handle,'[]'); flock($handle,LOCK_UN);
        }
        if ($handle) fclose($handle);
    }
    private function bucket(string $identity, int $limit): void
    {
        $directory=Config::root().'/var/login';
        if (!is_dir($directory) && !@mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('A bejelentkezési védelem könyvtára nem írható.');
        $handle=fopen($directory.'/'.hash('sha256',$identity).'.json','c+');
        if (!$handle || !flock($handle,LOCK_EX)) throw new RuntimeException('A bejelentkezési védelem nem érhető el.');
        try {
            $attempts=json_decode(stream_get_contents($handle),true);
            $attempts=is_array($attempts) ? array_values(array_filter($attempts,static fn($time)=>is_int($time) && $time>time()-900)) : [];
            if (count($attempts)>=$limit) throw new RuntimeException('Túl sok bejelentkezési próbálkozás. Próbáld újra 15 perc múlva.');
            $attempts[]=time(); rewind($handle); ftruncate($handle,0);
            if (fwrite($handle,json_encode($attempts,JSON_THROW_ON_ERROR))===false) throw new RuntimeException('A bejelentkezési védelem nem menthető.');
        } finally { flock($handle,LOCK_UN); fclose($handle); }
    }
}
