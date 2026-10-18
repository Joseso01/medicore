<?php
declare(strict_types=1);

/**
 * Bootstrap y Autoloader PSR-4 Ligero — MediCore Systems
 */
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/AuthMiddleware.php';

// Registrar cierre explícito de conexión PDO al finalizar cada ciclo HTTP (RNF-REN-02)
register_shutdown_function(static function (): void {
    \MediCore\Config\Database::disconnect();
});
