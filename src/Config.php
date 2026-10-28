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
        $settings = json_decode(file_get_contents(self::path()), true);
        if (!is_array($settings)) throw new RuntimeException('Nem olvasható a helyi konfiguráció.');
        return (string)($settings[$name] ?? $default);
    }
}
