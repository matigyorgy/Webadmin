<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
function verify(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
verify(!class_exists('Composer\Autoload\ClassLoader', false), 'Unexpected Composer autoloader');
verify(class_exists(Smarty\Smarty::class), 'Smarty autoload failed');
$ssh = new phpseclib3\Net\SSH2('127.0.0.1', 22, 1);
verify($ssh instanceof phpseclib3\Net\SSH2, 'SSH2 autoload failed');
$key = phpseclib3\Crypt\RSA::createKey(2048);
$loaded = phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($key->toString('PKCS8'));
$signature = $loaded->sign('GameDock dependency test');
verify($loaded->getPublicKey()->verify('GameDock dependency test', $signature), 'SSH key dependency failed');
verify(ParagonIE\ConstantTime\Base64::encode('GameDock') === base64_encode('GameDock'), 'Constant-time dependency failed');
verify(mb_strtolower('ÁRVÍZ', 'UTF-8') === 'árvíz', 'Mbstring dependency failed');
echo "6 bundled dependency checks passed without Composer.\n";
