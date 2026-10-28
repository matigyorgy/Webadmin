<?php
declare(strict_types=1);
// Bundled dependencies: see lib/dependencies.json and each package's license.
if (PHP_VERSION_ID < 80200) {
    throw new RuntimeException('PHP 8.2 vagy újabb szükséges.');
}
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'GameDock\\' => __DIR__ . '/src/',
        'Smarty\\' => __DIR__ . '/lib/smarty/src/',
        'phpseclib3\\' => __DIR__ . '/lib/phpseclib/phpseclib/',
        'ParagonIE\\ConstantTime\\' => __DIR__ . '/lib/constant-time/src/',
        'Symfony\\Polyfill\\Mbstring\\' => __DIR__ . '/lib/polyfill-mbstring/',
    ];
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            if (!preg_match('/\\A[A-Za-z0-9_\\\\]+\\z/', $relative)) return;
            $file = $directory . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) require $file;
            return;
        }
    }
});
require_once __DIR__ . '/lib/polyfill-mbstring/bootstrap.php';
require_once __DIR__ . '/lib/phpseclib/phpseclib/bootstrap.php';
require_once __DIR__ . '/lib/smarty/src/functions.php';
