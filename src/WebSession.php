<?php
declare(strict_types=1);
namespace GameDock;
final class WebSession
{
    public static function start(): void
    {
        ini_set('session.use_strict_mode', '1');
        $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $secure = Config::get('COOKIE_SECURE', $https ? '1' : '0') !== '0';
        session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Strict']);
        if (!session_start()) throw new SetupException('A PHP-munkamenet nem indítható. A tárhelyen ellenőrizd a session.save_path könyvtár írhatóságát és a szabad tárhelyet.');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'self'; style-src 'self'; frame-ancestors 'none'; form-action 'self'");
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
    }
}
