<?php
declare(strict_types=1);

namespace MediCore\Config;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Capa de Conexión PDO Estricta — MediCore Systems
 * Cumplimiento: RNF-SEG-02, RNF-REN-02, RBD-INT-02
 */
final class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}

    /**
     * Obtiene la instancia activa de conexión PDO con preparación nativa en MySQL.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: 'db_mysql';
            $db   = getenv('DB_NAME') ?: 'medicore_db';
            $user = getenv('DB_USER') ?: 'medicore_user';
            $pass = getenv('DB_PASS') ?: 'medicore_pass_user';
            $charset = 'utf8mb4';

            $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

            // RNF-SEG-02: Atributos obligatorios de seguridad PDO
            $options = [
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
                // RBD-INT-02: Asegurar nivel de aislamiento REPEATABLE READ
                self::$instance->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
            } catch (PDOException $e) {
                error_log('[MediCore DB Error] ' . $e->getMessage());
                throw new RuntimeException('Error interno de comunicación con el servidor de base de datos.');
            }
        }

        return self::$instance;
    }

    /**
     * Cierra explícitamente la conexión PDO al concluir el ciclo HTTP (RNF-REN-02).
     */
    public static function disconnect(): void
    {
        self::$instance = null;
    }
}
