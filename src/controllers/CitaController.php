<?php
declare(strict_types=1);

namespace MediCore\Controllers;

use MediCore\Config\Database;
use MediCore\Core\Security;
use PDO;
use PDOException;

/**
 * Controlador de Control de Citas Médicas y Agenda (CIT)
 * Cumplimiento: RF-CIT-01 a RF-CIT-04, RN-CLI-01, RN-CLI-06, RBD-INT-02, RBD-INT-06
 */
final class CitaController
{
    /**
     * Obtiene catálogos para filtros y formularios de citas.
     */
    public function obtenerCatalogos(): array
    {
        $pdo = Database::getConnection();

        $especialidades = $pdo->query(
            "SELECT id_especialidad, codigo, nombre FROM especialidades WHERE activa = 1 ORDER BY nombre ASC"
        )->fetchAll();

        $medicos = $pdo->query(
            "SELECT m.id_medico, m.cedula_profesional, m.id_especialidad, u.nombre_completo AS medico_nombre, e.nombre AS especialidad_nombre
             FROM medicos m
             INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
             INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
             WHERE m.estado = 'ACTIVO' AND u.estado = 'ACTIVO'
             ORDER BY u.nombre_completo ASC"
        )->fetchAll();

        $consultorios = $pdo->query(
            "SELECT id_consultorio, codigo_consultorio, nombre, ubicacion FROM consultorios WHERE estado = 'DISPONIBLE' ORDER BY codigo_consultorio ASC"
        )->fetchAll();

        return [
            'especialidades' => $especialidades,
            'medicos'        => $medicos,
            'consultorios'   => $consultorios,
        ];
    }

    /**
     * RF-CIT-04: Lista y filtra la agenda de citas por fecha, especialidad, médico y estado.
     * Si el usuario en sesión es Médico (idRol=2), se fuerza el filtro estricto por su id_medico.
     */
    public function listarAgenda(array $filtros, int $idRol, ?int $idMedicoSesion): array
    {
        $pdo = Database::getConnection();

        $fecha = !empty($filtros['fecha']) ? trim((string)$filtros['fecha']) : date('Y-m-d');
        $idEspecialidad = !empty($filtros['id_especialidad']) ? (int)$filtros['id_especialidad'] : null;
        $idMedico = !empty($filtros['id_medico']) ? (int)$filtros['id_medico'] : null;
        $estado = !empty($filtros['estado']) ? trim((string)$filtros['estado']) : null;

        // RF-CIT-04: Filtrado estricto si el rol es Médico
        if ($idRol === 2) {
            $idMedico = $idMedicoSesion;
        }

        $sql = "SELECT c.id_cita, c.fecha_cita, c.hora_inicio, c.hora_fin, c.motivo_consulta, c.estado,
                       c.motivo_cancelacion, c.fecha_cancelacion,
                       p.id_paciente, p.mrn, p.nombre_completo AS paciente_nombre, p.grupo_sanguineo, p.telefono AS paciente_telefono,
                       m.id_medico, u_med.nombre_completo AS medico_nombre, e.nombre AS especialidad_nombre,
                       con.id_consultorio, con.codigo_consultorio, con.nombre AS consultorio_nombre,
                       u_reg.nombre_completo AS registrado_por,
                       (SELECT COUNT(*) FROM paciente_alergias pa WHERE pa.id_paciente = p.id_paciente AND pa.activa = 1 AND pa.severidad IN ('MODERADA', 'ANAFILAXIA_SEVERA')) AS tiene_alergias_severas
                FROM citas_medicas c
                INNER JOIN pacientes p ON p.id_paciente = c.id_paciente
                INNER JOIN medicos m ON m.id_medico = c.id_medico
                INNER JOIN usuarios u_med ON u_med.id_usuario = m.id_usuario
                INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
                INNER JOIN consultorios con ON con.id_consultorio = c.id_consultorio
                INNER JOIN usuarios u_reg ON u_reg.id_usuario = c.id_usuario_registro
                WHERE 1 = 1";

        $params = [];

        if ($fecha !== 'TODAS') {
            $sql .= " AND c.fecha_cita = :fecha";
            $params[':fecha'] = $fecha;
        }

        if ($idMedico !== null && $idMedico > 0) {
            $sql .= " AND c.id_medico = :medico";
            $params[':medico'] = $idMedico;
        }

        if ($idEspecialidad !== null && $idEspecialidad > 0) {
            $sql .= " AND m.id_especialidad = :especialidad";
            $params[':especialidad'] = $idEspecialidad;
        }

        if ($estado !== null && $estado !== '' && $estado !== 'TODOS') {
            $sql .= " AND c.estado = :estado";
            $params[':estado'] = $estado;
        }

        $sql .= " ORDER BY c.fecha_cita ASC, c.hora_inicio ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * RF-CIT-01: Programa una nueva cita médica con validación de jornada laboral y exclusión mutua de horario.
     */
    public function programarCita(array $datos, int $idUsuarioEjecutor): array
    {
        $idPaciente    = (int)($datos['id_paciente'] ?? 0);
        $idMedico      = (int)($datos['id_medico'] ?? 0);
        $idConsultorio = (int)($datos['id_consultorio'] ?? 0);
        $fechaCita     = trim((string)($datos['fecha_cita'] ?? ''));
        $horaInicio    = trim((string)($datos['hora_inicio'] ?? ''));
        $duracion      = isset($datos['duracion_minutos']) ? max(15, min(120, (int)$datos['duracion_minutos'])) : 30;
        $motivo        = trim((string)($datos['motivo_consulta'] ?? ''));

        if ($idPaciente <= 0 || $idMedico <= 0 || $idConsultorio <= 0 || $fechaCita === '' || $horaInicio === '' || $motivo === '') {
            return [
                'ok'      => false,
                'mensaje' => 'Todos los datos de la cita (paciente, médico, consultorio, fecha, hora y motivo) son obligatorios.',
            ];
        }

        if (strtotime($fechaCita) === false) {
            return [
                'ok'      => false,
                'mensaje' => 'La fecha de la cita no es válida.',
            ];
        }

        $horaInicioFormato = date('H:i:s', strtotime($horaInicio));
        $horaFinFormato    = date('H:i:s', strtotime("+{$duracion} minutes", strtotime($horaInicioFormato)));

        if ($horaFinFormato <= $horaInicioFormato) {
            return [
                'ok'      => false,
                'mensaje' => 'La hora de fin debe ser posterior a la hora de inicio.',
            ];
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // RN-CLI-06: Validar que el médico no se auto-agende a sí mismo
            $stmtDocMed = $pdo->prepare("SELECT documento_identidad FROM medicos WHERE id_medico = :med LIMIT 1");
            $stmtDocMed->execute([':med' => $idMedico]);
            $docMed = $stmtDocMed->fetchColumn();

            $stmtDocPac = $pdo->prepare("SELECT documento_identidad FROM pacientes WHERE id_paciente = :pac LIMIT 1");
            $stmtDocPac->execute([':pac' => $idPaciente]);
            $docPac = $stmtDocPac->fetchColumn();

            if ($docMed && $docPac && $docMed === $docPac) {
                $pdo->rollBack();
                return [
                    'ok'      => false,
                    'mensaje' => 'RN-CLI-06: Violación de segregación clínica. Un médico facultativo no puede agendarse una cita a sí mismo.',
                ];
            }

            // RF-CIT-01: Validar que el horario esté dentro de la jornada laboral configurada del médico
            $diaSemana = (int)date('N', strtotime($fechaCita)); // 1=Lunes .. 7=Domingo
            $stmtJornada = $pdo->prepare(
                "SELECT hora_inicio_jornada, hora_fin_jornada
                 FROM horarios_medicos
                 WHERE id_medico = :med AND dia_semana = :dia AND activo = 1
                 LIMIT 1"
            );
            $stmtJornada->execute([':med' => $idMedico, ':dia' => $diaSemana]);
            $jornada = $stmtJornada->fetch();

            if (!$jornada) {
                $pdo->rollBack();
                return [
                    'ok'      => false,
                    'mensaje' => 'RF-CIT-01: El médico seleccionado no tiene jornada laboral configurada ni disponible para ese día de la semana.',
                ];
            }

            if ($horaInicioFormato < $jornada['hora_inicio_jornada'] || $horaFinFormato > $jornada['hora_fin_jornada']) {
                $pdo->rollBack();
                return [
                    'ok'      => false,
                    'mensaje' => "RF-CIT-01: El horario solicitado ({$horaInicioFormato} - {$horaFinFormato}) excede la jornada laboral del médico (" . substr($jornada['hora_inicio_jornada'], 0, 5) . " a " . substr($jornada['hora_fin_jornada'], 0, 5) . ").",
                ];
            }

            // RN-CLI-01 & RBD-INT-02: Control de concurrencia pesimista con SELECT ... FOR UPDATE
            // Condición: tinicio_1 < tfin_2 AND tfin_1 > tinicio_2
            $stmtConflicto = $pdo->prepare(
                "SELECT id_cita, id_medico, id_paciente, id_consultorio
                 FROM citas_medicas
                 WHERE fecha_cita = :fecha
                   AND estado NOT IN ('CANCELADA', 'NO_ASISTIO')
                   AND (hora_inicio < :fin AND hora_fin > :inicio)
                   AND (id_medico = :med OR id_paciente = :pac OR id_consultorio = :cons)
                 FOR UPDATE"
            );
            $stmtConflicto->execute([
                ':fecha'  => $fechaCita,
                ':inicio' => $horaInicioFormato,
                ':fin'    => $horaFinFormato,
                ':med'    => $idMedico,
                ':pac'    => $idPaciente,
                ':cons'   => $idConsultorio,
            ]);
            $conflicto = $stmtConflicto->fetch();

            if ($conflicto) {
                $pdo->rollBack();
                $motivoConflicto = 'horario no disponible';
                if ((int)$conflicto['id_medico'] === $idMedico) {
                    $motivoConflicto = 'el médico ya tiene otra cita en ese intervalo';
                } elseif ((int)$conflicto['id_paciente'] === $idPaciente) {
                    $motivoConflicto = 'el paciente ya tiene otra cita programada en ese horario';
                } elseif ((int)$conflicto['id_consultorio'] === $idConsultorio) {
                    $motivoConflicto = 'el consultorio físico se encuentra ocupado en ese intervalo';
                }

                return [
                    'ok'      => false,
                    'mensaje' => "RN-CLI-01: Conflicto de empalme de horarios detectado ({$motivoConflicto}).",
                ];
            }

            // Inserción en citas_medicas
            $stmtInsert = $pdo->prepare(
                "INSERT INTO citas_medicas (
                    id_paciente, id_medico, id_consultorio, id_usuario_registro,
                    fecha_cita, hora_inicio, hora_fin, motivo_consulta, estado
                 ) VALUES (
                    :pac, :med, :cons, :reg,
                    :fecha, :inicio, :fin, :motivo, 'PROGRAMADA'
                 )"
            );
            $stmtInsert->execute([
                ':pac'    => $idPaciente,
                ':med'    => $idMedico,
                ':cons'   => $idConsultorio,
                ':reg'    => $idUsuarioEjecutor,
                ':fecha'  => $fechaCita,
                ':inicio' => $horaInicioFormato,
                ':fin'    => $horaFinFormato,
                ':motivo' => $motivo,
            ]);

            $idCita = (int)$pdo->lastInsertId();

            // Auditoría
            $stmtAudit = $pdo->prepare(
                "INSERT INTO bitacora_auditoria (tabla_afectada, operacion, id_registro_afectado, id_usuario_ejecutor, motivo, valores_nuevos, ip_origen)
                 VALUES ('citas_medicas', 'INSERT', :id_reg, :uid, :motivo, :nuevos, :ip)"
            );
            $stmtAudit->execute([
                ':id_reg' => (string)$idCita,
                ':uid'    => $idUsuarioEjecutor,
                ':motivo' => "Programación de cita #{$idCita} para el {$fechaCita} a las {$horaInicioFormato}",
                ':nuevos' => json_encode([
                    'id_paciente'    => $idPaciente,
                    'id_medico'      => $idMedico,
                    'id_consultorio' => $idConsultorio,
                    'fecha_cita'     => $fechaCita,
                    'hora_inicio'    => $horaInicioFormato,
                    'hora_fin'       => $horaFinFormato,
                ], JSON_UNESCAPED_UNICODE),
                ':ip'     => Security::getClientIp(),
            ]);

            $pdo->commit();

            return [
                'ok'      => true,
                'id_cita' => $idCita,
                'mensaje' => "Cita médica programada exitosamente para el {$fechaCita} de {$horaInicioFormato} a {$horaFinFormato}.",
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Si el trigger trg_before_insert_citas de MySQL lanzó una señal:
            $mensaje = $e->getMessage();
            if (str_contains($mensaje, 'RN-CLI-01')) {
                return ['ok' => false, 'mensaje' => 'RN-CLI-01: Conflicto de empalme horario detectado por el motor de base de datos.'];
            }
            if (str_contains($mensaje, 'RN-CLI-06')) {
                return ['ok' => false, 'mensaje' => 'RN-CLI-06: Un médico no puede agendarse una cita consigo mismo.'];
            }
            return ['ok' => false, 'mensaje' => 'Error al programar cita: ' . $mensaje];
        }
    }

    /**
     * RF-CIT-02 y RF-CIT-03: Cambio de estado de cita médica con máquina de estados estricta y cancelación con motivo.
     */
    public function cambiarEstado(int $idCita, string $nuevoEstado, ?string $motivoCancelacion, int $idUsuarioEjecutor): array
    {
        $estadosValidos = ['PROGRAMADA', 'CONFIRMADA', 'EN_CURSO', 'ATENDIDA', 'CANCELADA', 'NO_ASISTIO'];
        if (!in_array($nuevoEstado, $estadosValidos, true)) {
            return ['ok' => false, 'mensaje' => 'Estado de cita no reconocido.'];
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM citas_medicas WHERE id_cita = :id FOR UPDATE");
            $stmt->execute([':id' => $idCita]);
            $cita = $stmt->fetch();

            if (!$cita) {
                $pdo->rollBack();
                return ['ok' => false, 'mensaje' => 'Cita no encontrada.'];
            }

            $estadoActual = (string)$cita['estado'];

            // RF-CIT-02: Estados terminales
            if (in_array($estadoActual, ['ATENDIDA', 'CANCELADA'], true)) {
                $pdo->rollBack();
                return [
                    'ok'      => false,
                    'mensaje' => "RF-CIT-02: Una cita en estado [{$estadoActual}] es terminal y no admite modificaciones posteriores.",
                ];
            }

            // RF-CIT-03: Cancelación exige motivo obligatorio
            if ($nuevoEstado === 'CANCELADA') {
                $motivoCancelacion = trim((string)$motivoCancelacion);
                if ($motivoCancelacion === '') {
                    $pdo->rollBack();
                    return [
                        'ok'      => false,
                        'mensaje' => 'RF-CIT-03: Para cancelar una cita es obligatorio proporcionar el motivo de cancelación.',
                    ];
                }

                $stmtUpd = $pdo->prepare(
                    "UPDATE citas_medicas
                     SET estado = 'CANCELADA',
                         motivo_cancelacion = :motivo,
                         id_usuario_cancelacion = :uid,
                         fecha_cancelacion = NOW()
                     WHERE id_cita = :id"
                );
                $stmtUpd->execute([
                    ':motivo' => $motivoCancelacion,
                    ':uid'    => $idUsuarioEjecutor,
                    ':id'     => $idCita,
                ]);
            } else {
                $stmtUpd = $pdo->prepare(
                    "UPDATE citas_medicas
                     SET estado = :estado
                     WHERE id_cita = :id"
                );
                $stmtUpd->execute([
                    ':estado' => $nuevoEstado,
                    ':id'     => $idCita,
                ]);
            }

            // Bitácora
            $stmtAudit = $pdo->prepare(
                "INSERT INTO bitacora_auditoria (tabla_afectada, operacion, id_registro_afectado, id_usuario_ejecutor, motivo, valores_anteriores, valores_nuevos, ip_origen)
                 VALUES ('citas_medicas', 'UPDATE', :id_reg, :uid, :motivo, :old, :new, :ip)"
            );
            $stmtAudit->execute([
                ':id_reg' => (string)$idCita,
                ':uid'    => $idUsuarioEjecutor,
                ':motivo' => "Transición de estado de cita #{$idCita}: [{$estadoActual}] -> [{$nuevoEstado}]" . ($nuevoEstado === 'CANCELADA' ? " (Motivo: {$motivoCancelacion})" : ''),
                ':old'    => json_encode(['estado' => $estadoActual], JSON_UNESCAPED_UNICODE),
                ':new'    => json_encode(['estado' => $nuevoEstado, 'motivo_cancelacion' => $motivoCancelacion], JSON_UNESCAPED_UNICODE),
                ':ip'     => Security::getClientIp(),
            ]);

            $pdo->commit();

            return [
                'ok'      => true,
                'mensaje' => "Estado de la cita #{$idCita} actualizado a [{$nuevoEstado}].",
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'mensaje' => 'Error al actualizar estado: ' . $e->getMessage()];
        }
    }

    /**
     * RF-CIT-03: Reprogramación de citas con validación de no empalme (RN-CLI-01).
     */
    public function reprogramarCita(array $datos, int $idUsuarioEjecutor): array
    {
        $idCita        = (int)($datos['id_cita'] ?? 0);
        $nuevaFecha    = trim((string)($datos['fecha_cita'] ?? ''));
        $nuevaHora     = trim((string)($datos['hora_inicio'] ?? ''));
        $duracion      = isset($datos['duracion_minutos']) ? max(15, min(120, (int)$datos['duracion_minutos'])) : 30;
        $idConsultorio = !empty($datos['id_consultorio']) ? (int)$datos['id_consultorio'] : null;

        if ($idCita <= 0 || $nuevaFecha === '' || $nuevaHora === '') {
            return ['ok' => false, 'mensaje' => 'Debe indicar la cita, la nueva fecha y la nueva hora de inicio.'];
        }

        $nuevaHoraInicio = date('H:i:s', strtotime($nuevaHora));
        $nuevaHoraFin    = date('H:i:s', strtotime("+{$duracion} minutes", strtotime($nuevaHoraInicio)));

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM citas_medicas WHERE id_cita = :id FOR UPDATE");
            $stmt->execute([':id' => $idCita]);
            $cita = $stmt->fetch();

            if (!$cita) {
                $pdo->rollBack();
                return ['ok' => false, 'mensaje' => 'La cita no existe.'];
            }

            if (!in_array($cita['estado'], ['PROGRAMADA', 'CONFIRMADA'], true)) {
                $pdo->rollBack();
                return [
                    'ok'      => false,
                    'mensaje' => "RF-CIT-03: Solo pueden reprogramarse citas en estado PROGRAMADA o CONFIRMADA (estado actual: {$cita['estado']}).",
                ];
            }

            $idMed = (int)$cita['id_medico'];
            $idPac = (int)$cita['id_paciente'];
            $idCons = $idConsultorio ?: (int)$cita['id_consultorio'];

            // Validar jornada del médico
            $diaSemana = (int)date('N', strtotime($nuevaFecha));
            $stmtJornada = $pdo->prepare(
                "SELECT hora_inicio_jornada, hora_fin_jornada
                 FROM horarios_medicos
                 WHERE id_medico = :med AND dia_semana = :dia AND activo = 1
                 LIMIT 1"
            );
            $stmtJornada->execute([':med' => $idMed, ':dia' => $diaSemana]);
            $jornada = $stmtJornada->fetch();

            if (!$jornada || $nuevaHoraInicio < $jornada['hora_inicio_jornada'] || $nuevaHoraFin > $jornada['hora_fin_jornada']) {
                $pdo->rollBack();
                return ['ok' => false, 'mensaje' => 'El horario solicitado no se encuentra dentro de la jornada laboral disponible del médico.'];
            }

            // Validar empalme (RN-CLI-01) excluyendo la misma cita
            $stmtEmpalme = $pdo->prepare(
                "SELECT id_cita
                 FROM citas_medicas
                 WHERE id_cita <> :id
                   AND fecha_cita = :fecha
                   AND estado NOT IN ('CANCELADA', 'NO_ASISTIO')
                   AND (hora_inicio < :fin AND hora_fin > :inicio)
                   AND (id_medico = :med OR id_paciente = :pac OR id_consultorio = :cons)
                 FOR UPDATE"
            );
            $stmtEmpalme->execute([
                ':id'     => $idCita,
                ':fecha'  => $nuevaFecha,
                ':inicio' => $nuevaHoraInicio,
                ':fin'    => $nuevaHoraFin,
                ':med'    => $idMed,
                ':pac'    => $idPac,
                ':cons'   => $idCons,
            ]);

            if ($stmtEmpalme->fetch()) {
                $pdo->rollBack();
                return ['ok' => false, 'mensaje' => 'RN-CLI-01: Existe colisión de horario en el intervalo seleccionado para el médico, paciente o consultorio.'];
            }

            $stmtUpd = $pdo->prepare(
                "UPDATE citas_medicas
                 SET fecha_cita = :fecha,
                     hora_inicio = :inicio,
                     hora_fin = :fin,
                     id_consultorio = :cons
                 WHERE id_cita = :id"
            );
            $stmtUpd->execute([
                ':fecha'  => $nuevaFecha,
                ':inicio' => $nuevaHoraInicio,
                ':fin'    => $nuevaHoraFin,
                ':cons'   => $idCons,
                ':id'     => $idCita,
            ]);

            $stmtAudit = $pdo->prepare(
                "INSERT INTO bitacora_auditoria (tabla_afectada, operacion, id_registro_afectado, id_usuario_ejecutor, motivo, valores_anteriores, valores_nuevos, ip_origen)
                 VALUES ('citas_medicas', 'UPDATE', :id_reg, :uid, :motivo, :old, :new, :ip)"
            );
            $stmtAudit->execute([
                ':id_reg' => (string)$idCita,
                ':uid'    => $idUsuarioEjecutor,
                ':motivo' => "RF-CIT-03: Reprogramación de cita #{$idCita} para el {$nuevaFecha} {$nuevaHoraInicio}",
                ':old'    => json_encode(['fecha_cita' => $cita['fecha_cita'], 'hora_inicio' => $cita['hora_inicio'], 'hora_fin' => $cita['hora_fin']], JSON_UNESCAPED_UNICODE),
                ':new'    => json_encode(['fecha_cita' => $nuevaFecha, 'hora_inicio' => $nuevaHoraInicio, 'hora_fin' => $nuevaHoraFin], JSON_UNESCAPED_UNICODE),
                ':ip'     => Security::getClientIp(),
            ]);

            $pdo->commit();

            return [
                'ok'      => true,
                'mensaje' => "Cita #{$idCita} reprogramada exitosamente para el {$nuevaFecha} a las " . substr($nuevaHoraInicio, 0, 5) . ".",
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'mensaje' => 'Error al reprogramar cita: ' . $e->getMessage()];
        }
    }
}
