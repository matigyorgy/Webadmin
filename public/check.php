<?php
// Minimal diagnostic: no config, database, autoload or secret values are read.
ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$session = @session_start();
if ($session) session_write_close();
$root = dirname(__DIR__);
echo "GameDock — indulási ellenőrzés\n\n";
echo 'Webes PHP-verzió: '.PHP_VERSION."\n";
echo 'PHP 8.2+: '.(version_compare(PHP_VERSION, '8.2.0', '>=') ? 'OK' : 'HIÁNYZIK')."\n";
echo 'PDO MySQL: '.(extension_loaded('pdo_mysql') ? 'OK' : 'HIÁNYZIK')."\n";
$files = array('bootstrap.php', 'autoload.php', 'src/Config.php', 'src/WebSession.php', 'src/Installer.php', 'src/SetupException.php', 'templates/install.tpl', 'lib/smarty/src/Smarty.php', 'lib/smarty/src/functions.php', 'lib/phpseclib/phpseclib/Net/SSH2.php', 'lib/polyfill-mbstring/bootstrap.php');
foreach ($files as $file) echo $file.': '.(is_readable($root.'/'.$file) ? 'OLVASHATÓ' : 'HIÁNYZIK/NEM OLVASHATÓ')."\n";
echo 'config könyvtár írható: '.(is_writable($root.'/config') ? 'IGEN' : 'NEM')."\n";
echo 'var könyvtár írható: '.(is_writable($root.'/var') ? 'IGEN' : 'NEM')."\n";
$compile=$root.'/var/smarty/compile';
echo 'Smarty compile: '.(is_dir($compile) ? (is_writable($compile) ? 'ÍRHATÓ' : 'NEM ÍRHATÓ') : 'MÉG NINCS LÉTREHOZVA')."\n";
echo 'PHP session: '.($session ? 'OK' : 'NEM INDÍTHATÓ')."\n";
echo "\nAz ellenőrzés nem módosít adatbázist és nem jelenít meg jelszót vagy privát kulcsot.\n";
