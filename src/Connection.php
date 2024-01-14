<?php

namespace Hexlet\Code;

final class Connection {

    private static ?Connection $conn = null;

    public function connect()
    {
        $opt = array(
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, 
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        );
    
        $databaseUrl = parse_url(getenv('DATABASE_URL', true));
    
        $host = $databaseUrl['host'];
        $port = $databaseUrl['port'];
        $user = $databaseUrl['user'];
        $pass = $databaseUrl['pass'];
        $dbName = ltrim($databaseUrl['path'], '/');

        $pdo = new \PDO("pgsql:host=$host;port=$port;dbname=$dbName", $user, $pass, $opt);
        return $pdo;
    }

    public static function get()
    {
        if (null === static::$conn) {
            static::$conn = new self();
        }
        return static::$conn;
    }

    protected function __construct()
    {

    }
}
