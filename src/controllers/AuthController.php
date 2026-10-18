<?php
declare(strict_types=1);

namespace MediCore\Controllers;

use MediCore\Config\Database;
use MediCore\Core\Security;
use PDO;

/**
 * Controlador de Autenticación Criptográfica y Protección contra Fuerza Bruta
 * Cumplimiento: RF-SEG-01, RF-SEG-03, RF-SEG-04, RNF-SEG-01, RNF-SEG-02
 */
final class AuthController
{
    /**
     * Autentica un usuario por username o email institucional y contraseña (RF-SEG-01 / RF-SEG-04).
     */
    public function login(string $identificador, string $password): array
    {
        $identificador = trim($identificador);
        if ($identificador === '' || $password === '') {
            return [
                'ok'      => false,
                'mensaje' => 'Debe ingresar su usuario o correo institucional y su contraseña.',
            ];
        }

        $pdo = Database::getConnection();

        // RF-SEG-01: Sentencia preparada nativa PDO
        $stmt = $pdo->prepare(
            "SELECT u.id_usuario, u.username, u.email, u.nombre_completo, u.password_hash,
                    u.id_rol, u.estado, u.intentos_fallidos, u.ultimo_intento_fallido,
                    u.bloqueado_hasta,
                    (u.bloqueado_hasta IS NOT NULL AND u.bloqueado_hasta > NOW()) AS esta_bloqueado,
                    TIMESTAMPDIFF(MINUTE, NOW(), u.bloqueado_hasta) AS minutos_restantes,
                    r.nombre AS rol_nombre,
                    m.id_medico, m.cedula_profesional
             FROM usuarios u
             INNER JOIN roles r ON r.id_rol = u.id_rol
             LEFT JOIN medicos m ON m.id_usuario = u.id_usuario
             WHERE u.username = :user OR u.email = :email
             LIMIT 1"
        );
        $stmt->execute([
            ':user'  => $identificador,
            ':email' => $identificador,
        ]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            return [
                'ok'      => false,
                'mensaje' => 'Credenciales inválidas. Verifique su usuario y contraseña.',
            ];
        }

        $idUsuario = (int)$usuario['id_usuario'];

        // RF-SEG-03: Impedir acceso si el usuario está en SUSPENDIDO o BAJA_LOGICA
        if ($usuario['estado'] !== 'ACTIVO') {
            $this->registrarAuditoria($pdo, 'LOGIN', (string)$idUsuario, $idUsuario, "Intento de inicio de sesión en cuenta con estado {$usuario['estado']}");
            return [
                'ok'      => false,
                'mensaje' => "Acceso denegado: Su cuenta se encuentra en estado [{$usuario['estado']}]. Contacte al Administrador del Sistema.",
            ];
        }

        // RF-SEG-04: Verificar si la cuenta está bloqueada temporalmente por fuerza bruta
        if ((int)$usuario['esta_bloqueado'] === 1) {
            $minRestantes = max(1, (int)$usuario['minutos_restantes']);
            return [
                'ok'      => false,
                'mensaje' => "Cuenta bloqueada temporalmente por seguridad (RF-SEG-04) tras múltiples intentos fallidos. Intente de nuevo en {$minRestantes} minuto(s) o solicite desbloqueo al Administrador.",
            ];
        }

        // RF-SEG-01: Verificación criptográfica estricta mediante password_verify()
        if (!password_verify($password, (string)$usuario['password_hash'])) {
            return $this->procesarIntentoFallido($pdo, $idUsuario);
        }

        // Credenciales válidas y estado = 'ACTIVO' -> Limpiar contadores de fuerza bruta
        $stmtReset = $pdo->prepare(
            "UPDATE usuarios
             SET intentos_fallidos = 0,
                 ultimo_intento_fallido = NULL,
                 bloqueado_hasta = NULL,
                 ultimo_acceso = NOW()
             WHERE id_usuario = :uid"
        );
        $stmtReset->execute([':uid' => $idUsuario]);

        // RF-SEG-01: Regenerar identificador de sesión para prevenir Session Fixation
        Security::initSession();
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['id_usuario'] = $idUsuario;
        $_SESSION['username']   = (string)$usuario['username'];
        $_SESSION['nombre_completo'] = (string)$usuario['nombre_completo'];
        $_SESSION['id_rol']     = (int)$usuario['id_rol'];
        $_SESSION['rol_nombre'] = (string)$usuario['rol_nombre'];
        $_SESSION['id_medico']  = $usuario['id_medico'] !== null ? (int)$usuario['id_medico'] : null;
        $_SESSION['ultima_actividad'] = time();

        // Registrar sesión en sesiones_activas (RF-SEG-01 y RF-SEG-03)
        $stmtSesion = $pdo->prepare(
            "INSERT INTO sesiones_activas (id_sesion, id_usuario, ip_origen, user_agent, csrf_token, iniciada_en, ultima_actividad, activa)
             VALUES (:sid, :uid, :ip, :ua, :csrf, NOW(), NOW(), 1)
             ON DUPLICATE KEY UPDATE
                id_usuario = VALUES(id_usuario),
                ip_origen = VALUES(ip_origen),
                csrf_token = VALUES(csrf_token),
                ultima_actividad = NOW(),
                activa = 1"
        );
        $stmtSesion->execute([
            ':sid'  => session_id(),
            ':uid'  => $idUsuario,
            ':ip'   => Security::getClientIp(),
            ':ua'   => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Navegador Web'), 0, 255),
            ':csrf' => $_SESSION['csrf_token'],
        ]);

        // RF-SEG-01: Registrar evento de ingreso en bitacora_auditoria
        $this->registrarAuditoria($pdo, 'LOGIN', (string)$idUsuario, $idUsuario, "Inicio de sesión exitoso ({$usuario['rol_nombre']})");

        return [
            'ok'      => true,
            'mensaje' => 'Autenticación exitosa.',
        ];
    }

    /**
     * Contabiliza intentos fallidos en ventana de 15 minutos y bloquea por 30 minutos al 5to intento (RF-SEG-04).
     */
    private function procesarIntentoFallido(PDO $pdo, int $idUsuario): array
    {
        // Si el último intento fallido fue hace más de 15 minutos, reiniciamos el contador a 1
        $stmtUpd = $pdo->prepare(
            "UPDATE usuarios
             SET intentos_fallidos = CASE
                    WHEN ultimo_intento_fallido IS NULL
                         OR ultimo_intento_fallido < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                    THEN 1
                    ELSE intentos_fallidos + 1
                 END,
                 ultimo_intento_fallido = NOW(),
                 bloqueado_hasta = CASE
                    WHEN (
                        CASE
                            WHEN ultimo_intento_fallido IS NULL
                                 OR ultimo_intento_fallido < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                            THEN 1
                            ELSE intentos_fallidos + 1
                        END
                    ) >= 5
                    THEN DATE_ADD(NOW(), INTERVAL 30 MINUTE)
                    ELSE bloqueado_hasta
                 END
             WHERE id_usuario = :uid"
        );
        $stmtUpd->execute([':uid' => $idUsuario]);

        $stmtCheck = $pdo->prepare(
            "SELECT intentos_fallidos, bloqueado_hasta FROM usuarios WHERE id_usuario = :uid LIMIT 1"
        );
        $stmtCheck->execute([':uid' => $idUsuario]);
        $estado = $stmtCheck->fetch();

        $intentos = (int)($estado['intentos_fallidos'] ?? 1);
        if ($intentos >= 5 && !empty($estado['bloqueado_hasta'])) {
            $this->registrarAuditoria(
                $pdo,
                'BLOQUEO_SEGURIDAD',
                (string)$idUsuario,
                $idUsuario,
                'RF-SEG-04: Cuenta bloqueada automáticamente por 30 minutos tras alcanzar 5 intentos fallidos en 15 minutos'
            );
            return [
                'ok'      => false,
                'mensaje' => 'Se alcanzaron 5 intentos fallidos consecutivos. Por seguridad (RF-SEG-04), la cuenta ha sido bloqueada durante 30 minutos.',
            ];
        }

        $restantes = max(0, 5 - $intentos);
        return [
            'ok'      => false,
            'mensaje' => "Credenciales inválidas. Intentos acumulados: {$intentos}/5 (Restan {$restantes} intentos antes del bloqueo temporal de 30 minutos).",
        ];
    }

    private function registrarAuditoria(PDO $pdo, string $operacion, string $idRegistro, int $idUsuario, string $motivo): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO bitacora_auditoria (tabla_afectada, operacion, id_registro_afectado, id_usuario_ejecutor, motivo, ip_origen)
             VALUES ('usuarios', :op, :id_reg, :uid, :motivo, :ip)"
        );
        $stmt->execute([
            ':op'     => $operacion,
            ':id_reg' => $idRegistro,
            ':uid'    => $idUsuario,
            ':motivo' => $motivo,
            ':ip'     => Security::getClientIp(),
        ]);
    }
}
