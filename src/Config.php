<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class Config
{
    public static function root(): string { return dirname(__DIR__); }
    public static function path(): string { return self::root() . '/config/local.json'; }
    public static function installed(): bool { return is_file(self::path()) || (bool)getenv('DB_DSN'); }
    public static function get(string $name, string $default = ''): string
    {
        $environment = getenv($name);
        if ($environment !== false) return $environment;
        if (!is_file(self::path())) return $default;
        if (!is_readable(self::path())) throw new SetupException('A config/local.json nem olvasható a PHP-folyamat számára. Ellenőrizd a fájl tulajdonosát és olvasási jogosultságát.');
        $contents = file_get_contents(self::path());
        if ($contents === false) throw new SetupException('A config/local.json nem olvasható a PHP-folyamat számára. Ellenőrizd a fájl tulajdonosát és olvasási jogosultságát.');
        $settings = json_decode($contents, true);
        if (!is_array($settings)) throw new SetupException('A config/local.json hibás JSON-t tartalmaz. Állítsd vissza a saját konfigurációd mentését; a telepítőt ne futtasd újra a meglévő adatbázison.');
        return (string)($settings[$name] ?? $default);
    }
}
