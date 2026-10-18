<?php
declare(strict_types=1);

namespace MediCore\Core;

use MediCore\Config\Database;

/**
 * Controlador Centralizado de Autorización Basada en Roles (Middleware RBAC)
 * Cumplimiento: RF-SEG-02, RN-CLI-06
 */
final class AuthMiddleware
{
    /**
     * Matriz de permisos RBAC en memoria por id_rol y módulo para interceptar
     * peticiones antes de instanciar consultas de negocio en PDO (RF-SEG-02).
     * 1 = Administrador, 2 = Médico, 3 = Recepcionista
     */
    private const RBAC_MATRIX = [
        1 => [ // Administrador
            'SEG' => ['crear' => true,  'leer' => true,  'actualizar' => true,  'eliminar' => true],
            'PAC' => ['crear' => true,  'leer' => true,  'actualizar' => true,  'eliminar' => false],
            'CIT' => ['crear' => true,  'leer' => true,  'actualizar' => true,  'eliminar' => true],
            'HIS' => ['crear' => false, 'leer' => false, 'actualizar' => false, 'eliminar' => false], // RN-CLI-06
            'FAR' => ['crear' => true,  'leer' => true,  'actualizar' => true,  'eliminar' => false],
        ],
        2 => [ // Médico (Facultativo)
            'SEG' => ['crear' => false, 'leer' => false, 'actualizar' => false, 'eliminar' => false],
            'PAC' => ['crear' => false, 'leer' => true,  'actualizar' => false, 'eliminar' => false],
            'CIT' => ['crear' => false, 'leer' => true,  'actualizar' => true,  'eliminar' => false],
            'HIS' => ['crear' => true,  'leer' => true,  'actualizar' => false, 'eliminar' => false],
            'FAR' => ['crear' => false, 'leer' => true,  'actualizar' => false, 'eliminar' => false], // RN-CLI-06
        ],
        3 => [ // Recepcionista y Admisión
            'SEG' => ['crear' => false, 'leer' => false, 'actualizar' => false, 'eliminar' => false],
            'PAC' => ['crear' => true,  'leer' => true,  'actualizar' => true,  'eliminar' => false],
            'CIT' => ['crear' => true,  'leer' => true,  'actualizar' => true,  'eliminar' => true],
            'HIS' => ['crear' => false, 'leer' => false, 'actualizar' => false, 'eliminar' => false], // RN-CLI-06
            'FAR' => ['crear' => true,  'leer' => true,  'actualizar' => false, 'eliminar' => false],
        ],
    ];

    /**
     * Verifica que exista una sesión autenticada válida.
     */
    public static function requireAuth(bool $isJson = false): void
    {
        Security::initSession();

        if (empty($_SESSION['id_usuario']) || empty($_SESSION['id_rol'])) {
            if ($isJson) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'      => false,
                    'error'   => 'SESION_EXPIRADA',
                    'mensaje' => 'Su sesión no está activa o ha expirado por inactividad (20 minutos). Por favor inicie sesión nuevamente.',
                ]);
                exit;
            }
            header('Location: /login.php?timeout=1');
            exit;
        }
    }

    /**
     * Verifica permisos RBAC sobre un módulo y acción ANTES de ejecutar lógica PDO de negocio (RF-SEG-02).
     * Si el acceso no está autorizado, devuelve HTTP 403 Forbidden y registra una alerta en bitacora_auditoria.
     */
    public static function requirePermission(string $modulo, string $accion = 'leer', bool $isJson = false): void
    {
        self::requireAuth($isJson);

        $idRol = (int)$_SESSION['id_rol'];
        $autorizado = self::RBAC_MATRIX[$idRol][$modulo][$accion] ?? false;

        if (!$autorizado) {
            self::denyAccess($modulo, $accion, $isJson);
        }

        // Una vez autorizado en el filtro RBAC, validamos vigencia de sesión en BD (RF-SEG-03)
        if (!Security::verifyActiveDatabaseSession()) {
            if ($isJson) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'      => false,
                    'error'   => 'SESION_INVALIDADA',
                    'mensaje' => 'La cuenta o sesión fue suspendida por un Administrador.',
                ]);
                exit;
            }
            header('Location: /login.php?revoked=1');
            exit;
        }
    }

    /**
     * Verifica si el rol actual tiene permiso para mostrar elementos en la UI.
     */
    public static function can(string $modulo, string $accion = 'leer'): bool
    {
        $idRol = (int)($_SESSION['id_rol'] ?? 0);
        return self::RBAC_MATRIX[$idRol][$modulo][$accion] ?? false;
    }

    /**
     * Exige validación de token anti-CSRF en toda petición POST, PUT o DELETE (RNF-SEG-03).
     */
    public static function requireCsrf(bool $isJson = true): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
            return;
        }

        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
        if (!Security::validateCsrfToken(is_string($token) ? $token : null)) {
            http_response_code(403);
            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'      => false,
                    'error'   => 'CSRF_INVALIDO',
                    'mensaje' => 'Token de seguridad anti-CSRF inválido o ausente (RNF-SEG-03). Recargue la página.',
                ]);
                exit;
            }
            die('Error 403: Token anti-CSRF inválido.');
        }
    }

    /**
     * Registra el intento de acceso no autorizado y aborta con HTTP 403 Forbidden (RF-SEG-02).
     */
    private static function denyAccess(string $modulo, string $accion, bool $isJson): never
    {
        http_response_code(403);
        $idUsuario = isset($_SESSION['id_usuario']) ? (int)$_SESSION['id_usuario'] : null;
        $rolNombre = (string)($_SESSION['rol_nombre'] ?? 'Desconocido');
        $ruta = (string)($_SERVER['REQUEST_URI'] ?? '');

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                "INSERT INTO bitacora_auditoria (
                    tabla_afectada, operacion, id_registro_afectado, id_usuario_ejecutor, motivo, ip_origen
                 ) VALUES (
                    :modulo, 'ACCESO_DENEGADO', :accion, :uid, :motivo, :ip
                 )"
            );
            $stmt->execute([
                ':modulo' => $modulo,
                ':accion' => strtoupper($accion),
                ':uid'    => $idUsuario,
                ':motivo' => "RF-SEG-02/RN-CLI-06: Rol [{$rolNombre}] intentó acción [{$accion}] en módulo [{$modulo}] ({$ruta})",
                ':ip'     => Security::getClientIp(),
            ]);
            Database::disconnect();
        } catch (\Throwable $e) {
            error_log('[MediCore RBAC Audit Error] ' . $e->getMessage());
        }

        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'      => false,
                'error'   => 'HTTP_403_FORBIDDEN',
                'mensaje' => "Acceso Denegado (403 Forbidden): Su perfil ({$rolNombre}) no posee privilegios para ejecutar [{$accion}] en el módulo [{$modulo}]. Este incidente ha sido registrado en la bitácora de auditoría.",
            ]);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>403 Acceso Denegado — MediCore Systems</title>';
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">';
        echo '<link href="/assets/css/medicore.css" rel="stylesheet"></head>';
        echo '<body class="bg-light d-flex align-items-center justify-content-center vh-100 p-3">';
        echo '<main class="card border-danger shadow-sm mc-max-w-540">';
        echo '<div class="card-header bg-danger text-white fw-bold"><i class="bi bi-shield-exclamation me-2" aria-hidden="true"></i>HTTP 403 Forbidden — Violación de Política RBAC (RF-SEG-02)</div>';
        echo '<div class="card-body">';
        echo '<p class="mb-2">Su perfil institucional (<strong>' . Security::e($rolNombre) . '</strong>) tiene estrictamente restringido el acceso al módulo <code>' . Security::e($modulo) . '</code> (' . Security::e($accion) . ').</p>';
        echo '<p class="text-muted small mb-3">En cumplimiento de <strong>RF-SEG-02</strong> y <strong>RN-CLI-06</strong>, este intento ha sido registrado de forma inmutable en <code>bitacora_auditoria</code>.</p>';
        echo '<a href="/index.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al Tablero Principal</a>';
        echo '</div></main></body></html>';
        exit;
    }
}
