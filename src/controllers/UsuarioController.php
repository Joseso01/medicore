<?php
declare(strict_types=1);

namespace MediCore\Controllers;

use MediCore\Config\Database;
use MediCore\Core\Security;
use PDO;
use PDOException;

/**
 * Controlador de Administración del Ciclo de Vida de Usuarios, Baja Lógica y Bitácora
 * Cumplimiento: RF-SEG-03, RF-SEG-04, RBD-INT-02, RBD-INT-05, RNF-SEG-01
 */
final class UsuarioController
{
    /**
     * Lista todos los usuarios con su rol, datos de médico facultativo (si aplica) y sesiones activas.
     */
    public function listarUsuarios(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query(
            "SELECT u.id_usuario, u.username, u.email, u.nombre_completo, u.id_rol,
                    r.nombre AS rol_nombre, u.estado, u.intentos_fallidos,
                    u.bloqueado_hasta,
                    (u.bloqueado_hasta IS NOT NULL AND u.bloqueado_hasta > NOW()) AS bloqueado_activo,
                    u.ultimo_acceso, u.creado_en,
                    m.id_medico, m.cedula_profesional, m.documento_identidad AS doc_medico,
                    e.nombre AS especialidad_nombre,
                    (SELECT COUNT(*) FROM sesiones_activas s WHERE s.id_usuario = u.id_usuario AND s.activa = 1) AS sesiones_activas
             FROM usuarios u
             INNER JOIN roles r ON r.id_rol = u.id_rol
             LEFT JOIN medicos m ON m.id_usuario = u.id_usuario
             LEFT JOIN especialidades e ON e.id_especialidad = m.id_especialidad
             ORDER BY u.id_usuario ASC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Obtiene catálogos de roles y especialidades para el formulario de creación de usuarios.
     */
    public function obtenerCatalogos(): array
    {
        $pdo = Database::getConnection();
        $roles = $pdo->query("SELECT id_rol, nombre, descripcion FROM roles ORDER BY id_rol ASC")->fetchAll();
        $especialidades = $pdo->query("SELECT id_especialidad, codigo, nombre FROM especialidades WHERE activa = 1 ORDER BY nombre ASC")->fetchAll();
        $consultorios = $pdo->query("SELECT id_consultorio, codigo_consultorio, nombre FROM consultorios WHERE estado = 'DISPONIBLE' ORDER BY codigo_consultorio ASC")->fetchAll();

        return [
            'roles'          => $roles,
            'especialidades' => $especialidades,
            'consultorios'   => $consultorios,
        ];
    }

    /**
     * Crea un nuevo usuario y, si el rol es Médico (id_rol=2), vincula atómicamente su registro de facultativo y horarios (RF-SEG-03).
     */
    public function crearUsuario(array $datos, int $idAdminEjecutor): array
    {
        $username = trim((string)($datos['username'] ?? ''));
        $email    = trim((string)($datos['email'] ?? ''));
        $nombre   = trim((string)($datos['nombre_completo'] ?? ''));
        $password = (string)($datos['password'] ?? '');
        $idRol    = (int)($datos['id_rol'] ?? 0);

        if ($username === '' || $email === '' || $nombre === '' || strlen($password) < 8 || !in_array($idRol, [1, 2, 3], true)) {
            return [
                'ok'      => false,
                'mensaje' => 'Datos incompletos o inválidos. La contraseña debe tener al menos 8 caracteres y debe elegir un rol válido.',
            ];
        }

        // Si el rol es Médico (2), exigir cédula profesional, especialidad y documento de identidad (RN-CLI-06)
        $cedula = trim((string)($datos['cedula_profesional'] ?? ''));
        $docMedico = strtoupper(trim((string)($datos['documento_identidad'] ?? '')));
        $idEspecialidad = (int)($datos['id_especialidad'] ?? 0);
        $idConsultorio = (int)($datos['id_consultorio'] ?? 1);
        $telefonoCons = trim((string)($datos['telefono_consultorio'] ?? ''));

        if ($idRol === 2 && ($cedula === '' || $docMedico === '' || $idEspecialidad <= 0)) {
            return [
                'ok'      => false,
                'mensaje' => 'Para el rol Médico (Facultativo) es obligatorio capturar Cédula Profesional, Documento Oficial de Identidad (CURP/DNI) y Especialidad (RF-SEG-03 / RN-CLI-06).',
            ];
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // RNF-SEG-01: Hashear contraseña con Argon2id o Bcrypt cost >= 12
            $passwordHash = Security::hashPassword($password);

            $stmtUser = $pdo->prepare(
                "INSERT INTO usuarios (username, email, nombre_completo, password_hash, id_rol, estado)
                 VALUES (:username, :email, :nombre, :hash, :id_rol, 'ACTIVO')"
            );
            $stmtUser->execute([
                ':username' => $username,
                ':email'    => $email,
                ':nombre'   => $nombre,
                ':hash'     => $passwordHash,
                ':id_rol'   => $idRol,
            ]);

            $nuevoIdUsuario = (int)$pdo->lastInsertId();

            if ($idRol === 2) {
                $stmtMed = $pdo->prepare(
                    "INSERT INTO medicos (id_usuario, id_especialidad, cedula_profesional, documento_identidad, telefono_consultorio, estado)
                     VALUES (:uid, :esp, :ced, :doc, :tel, 'ACTIVO')"
                );
                $stmtMed->execute([
                    ':uid' => $nuevoIdUsuario,
                    ':esp' => $idEspecialidad,
                    ':ced' => $cedula,
                    ':doc' => $docMedico,
                    ':tel' => $telefonoCons !== '' ? $telefonoCons : null,
                ]);

                $nuevoIdMedico = (int)$pdo->lastInsertId();

                // Asignar horario base de Lunes a Viernes (08:00 a 20:00) en el consultorio elegido
                $stmtHor = $pdo->prepare(
                    "INSERT INTO horarios_medicos (id_medico, id_consultorio, dia_semana, hora_inicio_jornada, hora_fin_jornada, activo)
                     VALUES (:med, :cons, :dia, '08:00:00', '20:00:00', 1)"
                );
                for ($dia = 1; $dia <= 6; $dia++) {
                    $stmtHor->execute([
                        ':med'  => $nuevoIdMedico,
                        ':cons' => $idConsultorio > 0 ? $idConsultorio : 1,
                        ':dia'  => $dia,
                    ]);
                }
            }

            // Registrar en bitácora de auditoría
            $stmtAudit = $pdo->prepare(
                "INSERT INTO bitacora_auditoria (tabla_afectada, operacion, id_registro_afectado, id_usuario_ejecutor, motivo, valores_nuevos, ip_origen)
                 VALUES ('usuarios', 'INSERT', :id_reg, :uid_exec, :motivo, :nuevos, :ip)"
            );
            $stmtAudit->execute([
                ':id_reg'   => (string)$nuevoIdUsuario,
                ':uid_exec' => $idAdminEjecutor,
                ':motivo'   => "Alta de usuario institucional [{$username}] con rol ID {$idRol}",
                ':nuevos'   => json_encode([
                    'username' => $username,
                    'email'    => $email,
                    'id_rol'   => $idRol,
                    'cedula'   => $idRol === 2 ? $cedula : null,
                ], JSON_UNESCAPED_UNICODE),
                ':ip'       => Security::getClientIp(),
            ]);

            $pdo->commit();

            return [
                'ok'         => true,
                'id_usuario' => $nuevoIdUsuario,
                'mensaje'    => "Usuario [{$username}] registrado exitosamente con hash criptográfico robusto.",
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                return [
                    'ok'      => false,
                    'mensaje' => 'Error de duplicidad: El nombre de usuario, correo electrónico, cédula profesional o documento de identidad ya existe en el padrón.',
                ];
            }
            return [
                'ok'      => false,
                'mensaje' => 'Error al crear el usuario: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Modifica el estado operativo de un usuario (ACTIVO, SUSPENDIDO, BAJA_LOGICA) y destruye sus sesiones activas (RF-SEG-03).
     * El trigger trg_audit_update_usuarios registrará automáticamente OLD.* y NEW.* en bitacora_auditoria (RBD-INT-05).
     */
    public function cambiarEstadoUsuario(int $idUsuarioObjetivo, string $nuevoEstado, string $motivo, int $idAdminEjecutor): array
    {
        if (!in_array($nuevoEstado, ['ACTIVO', 'SUSPENDIDO', 'BAJA_LOGICA'], true)) {
            return [
                'ok'      => false,
                'mensaje' => 'Estado operativo inválido.',
            ];
        }

        if ($idUsuarioObjetivo === $idAdminEjecutor && $nuevoEstado !== 'ACTIVO') {
            return [
                'ok'      => false,
                'mensaje' => 'No puede suspender o dar de baja lógica su propia cuenta de Administrador en sesión.',
            ];
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "UPDATE usuarios
                 SET estado = :estado,
                     intentos_fallidos = CASE WHEN :estado_reset = 'ACTIVO' THEN 0 ELSE intentos_fallidos END,
                     bloqueado_hasta = CASE WHEN :estado_unlock = 'ACTIVO' THEN NULL ELSE bloqueado_hasta END
                 WHERE id_usuario = :uid"
            );
            $stmt->execute([
                ':estado'        => $nuevoEstado,
                ':estado_reset'  => $nuevoEstado,
                ':estado_unlock' => $nuevoEstado,
                ':uid'           => $idUsuarioObjetivo,
            ]);

            $sesionesCerradas = 0;
            // RF-SEG-03: Al cambiar a SUSPENDIDO o BAJA_LOGICA, destruir de inmediato cualquier sesión activa asociada
            if (in_array($nuevoEstado, ['SUSPENDIDO', 'BAJA_LOGICA'], true)) {
                $stmtSes = $pdo->prepare(
                    "UPDATE sesiones_activas
                     SET activa = 0, ultima_actividad = NOW()
                     WHERE id_usuario = :uid AND activa = 1"
                );
                $stmtSes->execute([':uid' => $idUsuarioObjetivo]);
                $sesionesCerradas = $stmtSes->rowCount();

                // También reflejar estado en tabla medicos si aplica
                $stmtMed = $pdo->prepare(
                    "UPDATE medicos SET estado = 'INACTIVO' WHERE id_usuario = :uid"
                );
                $stmtMed->execute([':uid' => $idUsuarioObjetivo]);
            } else {
                $stmtMed = $pdo->prepare(
                    "UPDATE medicos SET estado = 'ACTIVO' WHERE id_usuario = :uid"
                );
                $stmtMed->execute([':uid' => $idUsuarioObjetivo]);
            }

            $pdo->commit();

            return [
                'ok'      => true,
                'mensaje' => "Estado del usuario #{$idUsuarioObjetivo} actualizado a [{$nuevoEstado}]. Sesiones activas revocadas: {$sesionesCerradas}. Registro forense guardado en bitácora.",
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'ok'      => false,
                'mensaje' => 'Error al cambiar estado del usuario: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Restablece el bloqueo por fuerza bruta de una cuenta de usuario (RF-SEG-04).
     */
    public function desbloquearCuenta(int $idUsuarioObjetivo, int $idAdminEjecutor): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "UPDATE usuarios
             SET intentos_fallidos = 0,
                 ultimo_intento_fallido = NULL,
                 bloqueado_hasta = NULL
             WHERE id_usuario = :uid"
        );
        $stmt->execute([':uid' => $idUsuarioObjetivo]);

        return [
            'ok'      => true,
            'mensaje' => "Bloqueo por fuerza bruta restablecido exitosamente para el usuario #{$idUsuarioObjetivo}.",
        ];
    }

    /**
     * Consulta los últimos eventos inmutables de bitacora_auditoria (RBD-INT-05).
     */
    public function listarBitacora(int $limite = 50): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT b.id_evento, b.tabla_afectada, b.operacion, b.id_registro_afectado,
                    b.id_usuario_ejecutor, u.username AS usuario_ejecutor,
                    b.motivo, b.valores_anteriores, b.valores_nuevos, b.ip_origen, b.fecha_evento
             FROM bitacora_auditoria b
             LEFT JOIN usuarios u ON u.id_usuario = b.id_usuario_ejecutor
             ORDER BY b.id_evento DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
