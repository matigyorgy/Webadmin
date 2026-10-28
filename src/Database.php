<?php
declare(strict_types=1);
namespace GameDock;
use PDO;
use PDOStatement;
final class Database
{
    private ?PDO $connection = null;
    public function query(string $sql, array $params = []): PDOStatement
    {
        $this->connection ??= new PDO(
            Config::get('DB_DSN', 'mysql:host=127.0.0.1;dbname=webadmin;charset=utf8mb4'),
            Config::get('DB_USER', 'webadmin'), Config::get('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $statement = $this->connection->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
    public function lastInsertId(): string { return $this->connection->lastInsertId(); }
}
