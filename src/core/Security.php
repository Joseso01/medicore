<?php
declare(strict_types=1);

namespace MediCore\Core;

use MediCore\Config\Database;
use PDO;

/**
 * Núcleo de Seguridad Criptográfica, Sesiones, XSS y CSRF — MediCore Systems
 * Cumplimiento: RF-SEG-01, RF-SEG-03, RF-SEG-04, RNF-SEG-01, RNF-SEG-03
 */
final class Security
{
    public const SESSION_TIMEOUT_SECONDS = 1200; // 20 minutos (RF-SEG-04)

    /**
     * Inicializa y endurece la sesión PHP (RNF-SEG-03 y RF-SEG-04).
     */
    public static function initSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.gc_maxlifetime', (string)self::SESSION_TIMEOUT_SECONDS);

            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);

            session_start();
        }

        // Asegurar generación de token anti-CSRF con random_bytes(32) (RNF-SEG-03)
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // Verificar expiración por inactividad de 20 minutos (RF-SEG-04)
        if (!empty($_SESSION['id_usuario'])) {
            $now = time();
            if (isset($_SESSION['ultima_actividad']) && ($now - (int)$_SESSION['ultima_actividad']) > self::SESSION_TIMEOUT_SECONDS) {
                self::destroyCurrentSession('EXPIRACION_INACTIVIDAD');
                return;
            }
            $_SESSION['ultima_actividad'] = $now;
        }
    }

    /**
     * Destruye la sesión actual en memoria y la invalida en sesiones_activas (RF-SEG-03 / RF-SEG-04).
     */
    public static function destroyCurrentSession(string $motivo = 'LOGOUT'): void
    {
        $sessionId = session_id();
        $idUsuario = $_SESSION['id_usuario'] ?? null;

        if ($sessionId !== '' && $idUsuario !== null) {
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare(
                    "UPDATE sesiones_activas
                     SET activa = 0, ultima_actividad = NOW()
                     WHERE id_sesion = :sid"
                );
                $stmt->execute([':sid' => $sessionId]);

                $stmtLog = $pdo->prepare(
                    "INSERT INTO bitacora_auditoria (tabla_afectada, operacion, id_registro_afectado, id_usuario_ejecutor, motivo, ip_origen)
                     VALUES ('sesiones_activas', 'LOGOUT', :sid, :uid, :motivo, :ip)"
                );
                $stmtLog->execute([
                    ':sid'    => $sessionId,
                    ':uid'    => (int)$idUsuario,
                    ':motivo' => $motivo,
                    ':ip'     => self::getClientIp(),
                ]);
            } catch (\Throwable $e) {
                error_log('[MediCore Session Destroy] ' . $e->getMessage());
            }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'] ?? '',
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Verifica que la sesión en BD siga activa y que el usuario no haya sido SUSPENDIDO o dado de BAJA_LOGICA (RF-SEG-03).
     */
    public static function verifyActiveDatabaseSession(): bool
    {
        if (empty($_SESSION['id_usuario'])) {
            return false;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT s.activa, u.estado, u.nombre_completo, r.nombre AS rol_nombre
             FROM sesiones_activas s
             INNER JOIN usuarios u ON u.id_usuario = s.id_usuario
             INNER JOIN roles r ON r.id_rol = u.id_rol
             WHERE s.id_sesion = :sid AND s.id_usuario = :uid
             LIMIT 1"
        );
        $stmt->execute([
            ':sid' => session_id(),
            ':uid' => (int)$_SESSION['id_usuario'],
        ]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['activa'] !== 1 || $row['estado'] !== 'ACTIVO') {
            self::destroyCurrentSession('SESION_REVOCADA_O_USUARIO_INACTIVO');
            return false;
        }

        $_SESSION['nombre_completo'] = (string)$row['nombre_completo'];
        $_SESSION['rol_nombre']      = (string)$row['rol_nombre'];

        $stmtUpd = $pdo->prepare(
            "UPDATE sesiones_activas SET ultima_actividad = NOW() WHERE id_sesion = :sid"
        );
        $stmtUpd->execute([':sid' => session_id()]);

        return true;
    }

    /**
     * Retorna el token anti-CSRF de la sesión actual (RNF-SEG-03).
     */
    public static function getCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }

    /**
     * Valida de forma segura en tiempo constante el token anti-CSRF (RNF-SEG-03).
     */
    public static function validateCsrfToken(?string $token): bool
    {
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals((string)$_SESSION['csrf_token'], $token);
    }

    /**
     * Sanitización obligatoria contra XSS en servidor (RNF-SEG-03).
     */
    public static function e(mixed $valor): string
    {
        return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Hasheo robusto de contraseñas con Argon2id o Bcrypt cost >= 12 (RNF-SEG-01).
     */
    public static function hashPassword(string $plainPassword): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($plainPassword, PASSWORD_ARGON2ID);
        }
        return password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Obtiene la dirección IP del cliente.
     */
    public static function getClientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return substr((string)$ip, 0, 45);
    }
}
