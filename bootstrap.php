<?php
// Keep this bootstrap compatible with older PHP so version errors remain readable.
namespace GameDock;

final class BootstrapErrorHandler
{
    private static $handled = false;

    public static function respond($message, $status = 503)
    {
        if (self::$handled) return;
        self::$handled = true;
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        echo $message;
    }

    public static function exception($error)
    {
        $reference = substr(hash('sha256', uniqid('', true)), 0, 12);
        error_log('[GameDock '.$reference.'] '.get_class($error).': '.$error->getMessage().' in '.$error->getFile().':'.$error->getLine());
        if ($error instanceof SetupException) {
            self::respond($error->getMessage()."\nHibaazonosító: ".$reference);
            return;
        }
        self::respond("A GameDock indítása nem sikerült. Ellenőrizd a tárhely PHP error_log naplóját.\nHibaazonosító: ".$reference, 500);
    }
}

ini_set('display_errors', '0');
set_exception_handler([BootstrapErrorHandler::class, 'exception']);
register_shutdown_function(static function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        BootstrapErrorHandler::respond('A PHP végzetes hibával leállt. Ellenőrizd a tárhely error_log naplóját és a PHP 8.2+ beállítást.', 500);
    }
});
if (PHP_VERSION_ID < 80200) {
    BootstrapErrorHandler::respond('PHP 8.2 vagy újabb szükséges. Jelenlegi verzió: '.PHP_VERSION.'. A tárhely kezelőfelületén a domainhez tartozó PHP-verziót állítsd át.');
    exit;
}
$required = [
    '/autoload.php', '/src/Config.php', '/src/SetupException.php', '/src/WebSession.php',
    '/lib/polyfill-mbstring/bootstrap.php', '/lib/polyfill-mbstring/bootstrap80.php',
    '/lib/phpseclib/phpseclib/bootstrap.php', '/lib/smarty/src/functions.php', '/lib/smarty/src/Smarty.php',
];
foreach ($required as $relative) {
    if (!is_readable(__DIR__.$relative)) {
        BootstrapErrorHandler::respond('Hiányzó vagy nem olvasható projektfájl: '.$relative.'. Töltsd fel a teljes GitHub ZIP tartalmát, és ellenőrizd a fájlok olvasási jogosultságát.');
        exit;
    }
}
require_once __DIR__.'/autoload.php';
