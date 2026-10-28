<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$email = $argv[1] ?? '';
$password = getenv('NEW_USER_PASSWORD') ?: '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password)<12) {
 fwrite(STDERR,"Használat: NEW_USER_PASSWORD környezeti változó (legalább 12 karakter), php bin/create-user.php email [--admin]\n"); exit(1);
}
require dirname(__DIR__) . '/autoload.php';
$db = new GameDock\Database();
$db->query('INSERT INTO users(email,password_hash,is_admin) VALUES(?,?,?)', [$email,password_hash($password,PASSWORD_DEFAULT),in_array('--admin',$argv,true)?1:0]);
echo "Felhasználó létrehozva.\n";
