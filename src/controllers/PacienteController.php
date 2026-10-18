<?php
declare(strict_types=1);

namespace MediCore\Controllers;

use MediCore\Config\Database;
use MediCore\Core\Security;
use PDO;
use PDOException;

/**
 * Controlador del Submódulo de Gestión de Pacientes y Alergias (OPD)
 * Cumplimiento: RF-PAC-01, RF-PAC-02, RF-PAC-03, RF-PAC-04, RBD-INT-02, RBD-INT-05
 */
final class PacienteController
{
    /**
     * RF-PAC-03: Búsqueda asíncrona multicriterio indexada con paginación y medición de latencia (< 200 ms).
     */
    public function buscarPacientes(string $termino = '', int $pagina = 1, int $porPagina = 15): array
    {
        $inicio = microtime(true);
        $pdo = Database::getConnection();
        $termino = trim($termino);
        $offset = max(0, ($pagina - 1) * $porPagina);

        if ($termino === '') {
            $total = (int)$pdo->query("SELECT COUNT(*) FROM pacientes")->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT p.id_paciente, p.mrn, p.documento_identidad, p.nombres, p.apellidos,
                        p.nombre_completo, p.fecha_nacimiento, p.sexo_biologico, p.grupo_sanguineo,
                        p.telefono, p.email, p.direccion, p.estado,
                        TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) AS edad,
                        c.nombre_contacto, c.parentesco, c.telefono_emergencia,
                        (SELECT COUNT(*) FROM consultas_clinicas cc WHERE cc.id_paciente = p.id_paciente AND cc.estado_consulta = 'CERRADA') AS consultas_cerradas
                 FROM pacientes p
                 LEFT JOIN contactos_emergencia c ON c.id_paciente = p.id_paciente AND c.es_principal = 1
                 ORDER BY p.id_paciente DESC
                 LIMIT :lim OFFSET :off"
            );
            $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $pacientes = $stmt->fetchAll();
        } else {
            // Búsqueda por prefijo indexado B-Tree sobre mrn, documento_identidad, apellidos y nombres
            $prefijo = $termino . '%';
            $contiene = '%' . $termino . '%';

            $stmtCount = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM pacientes p
                 WHERE p.mrn LIKE :pref1
                    OR p.documento_identidad LIKE :pref2
                    OR p.apellidos LIKE :pref3
                    OR p.nombres LIKE :pref4
                    OR p.nombre_completo LIKE :cont"
            );
            $stmtCount->execute([
                ':pref1' => $prefijo,
                ':pref2' => $prefijo,
                ':pref3' => $prefijo,
                ':pref4' => $prefijo,
                ':cont'  => $contiene,
            ]);
            $total = (int)$stmtCount->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT p.id_paciente, p.mrn, p.documento_identidad, p.nombres, p.apellidos,
                        p.nombre_completo, p.fecha_nacimiento, p.sexo_biologico, p.grupo_sanguineo,
                        p.telefono, p.email, p.direccion, p.estado,
                        TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) AS edad,
                        c.nombre_contacto, c.parentesco, c.telefono_emergencia,
                        (SELECT COUNT(*) FROM consultas_clinicas cc WHERE cc.id_paciente = p.id_paciente AND cc.estado_consulta = 'CERRADA') AS consultas_cerradas
                 FROM pacientes p
                 LEFT JOIN contactos_emergencia c ON c.id_paciente = p.id_paciente AND c.es_principal = 1
                 WHERE p.mrn LIKE :pref1
                    OR p.documento_identidad LIKE :pref2
                    OR p.apellidos LIKE :pref3
                    OR p.nombres LIKE :pref4
                    OR p.nombre_completo LIKE :cont
                 ORDER BY p.apellidos ASC, p.nombres ASC
                 LIMIT :lim OFFSET :off"
            );
            $stmt->bindValue(':pref1', $prefijo, PDO::PARAM_STR);
            $stmt->bindValue(':pref2', $prefijo, PDO::PARAM_STR);
            $stmt->bindValue(':pref3', $prefijo, PDO::PARAM_STR);
            $stmt->bindValue(':pref4', $prefijo, PDO::PARAM_STR);
            $stmt->bindValue(':cont',  $contiene, PDO::PARAM_STR);
            $stmt->bindValue(':lim',   $porPagina, PDO::PARAM_INT);
            $stmt->bindValue(':off',   $offset, PDO::PARAM_INT);
            $stmt->execute();
            $pacientes = $stmt->fetchAll();
        }

        // Cargar las alergias activas de los pacientes listados (RF-PAC-02)
        if (!empty($pacientes)) {
            $ids = array_map(static fn(array $r): int => (int)$r['id_paciente'], $pacientes);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmtAlg = $pdo->prepare(
                "SELECT pa.id_paciente_alergia, pa.id_paciente, pa.id_alergeno,
                        pa.severidad, pa.reaccion_descrita, pa.fecha_deteccion,
                        ca.codigo_alergeno, ca.principio_activo_o_familia
                 FROM paciente_alergias pa
                 INNER JOIN catalogo_alergias ca ON ca.id_alergeno = pa.id_alergeno
                 WHERE pa.activa = 1 AND pa.id_paciente IN ({$placeholders})
                 ORDER BY FIELD(pa.severidad, 'ANAFILAXIA_SEVERA', 'MODERADA', 'LEVE')"
            );
            $stmtAlg->execute($ids);
            $alergiasRows = $stmtAlg->fetchAll();

            $mapaAlergias = [];
            foreach ($alergiasRows as $alg) {
                $mapaAlergias[(int)$alg['id_paciente']][] = $alg;
            }

            foreach ($pacientes as &$pac) {
                $pac['alergias'] = $mapaAlergias[(int)$pac['id_paciente']] ?? [];
            }
            unset($pac);
        }

        $tiempoMs = round((microtime(true) - $inicio) * 1000, 2);

        return [
            'ok'          => true,
            'pacientes'   => $pacientes,
            'total'       => $total,
            'pagina'      => $pagina,
            'por_pagina'  => $porPagina,
            'tiempo_ms'   => $tiempoMs,
        ];
    }

    /**
     * Obtiene el detalle completo de un paciente por ID (incluyendo contacto de emergencia y alergias).
     */
    public function obtenerPaciente(int $idPaciente): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT p.*,
                    TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) AS edad,
                    c.nombre_contacto, c.parentesco, c.telefono_emergencia,
                    (SELECT COUNT(*) FROM consultas_clinicas cc WHERE cc.id_paciente = p.id_paciente AND cc.estado_consulta = 'CERRADA') AS consultas_cerradas
             FROM pacientes p
             LEFT JOIN contactos_emergencia c ON c.id_paciente = p.id_paciente AND c.es_principal = 1
             WHERE p.id_paciente = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $idPaciente]);
        $paciente = $stmt->fetch();

        if (!$paciente) {
            return null;
        }

        $stmtAlg = $pdo->prepare(
            "SELECT pa.id_paciente_alergia, pa.id_alergeno, pa.severidad,
                    pa.reaccion_descrita, pa.fecha_deteccion, pa.activa,
                    ca.codigo_alergeno, ca.principio_activo_o_familia
             FROM paciente_alergias pa
             INNER JOIN catalogo_alergias ca ON ca.id_alergeno = pa.id_alergeno
             WHERE pa.id_paciente = :id AND pa.activa = 1
             ORDER BY FIELD(pa.severidad, 'ANAFILAXIA_SEVERA', 'MODERADA', 'LEVE')"
        );
        $stmtAlg->execute([':id' => $idPaciente]);
        $paciente['alergias'] = $stmtAlg->fetchAll();

        return $paciente;
    }

    /**
     * RF-PAC-01: Empadronamiento único y generación determinista y transaccional de MRN (MCS-YYYY-XXXXXX).
     */
    public function registrarPaciente(array $datos, int $idUsuarioEjecutor): array
    {
        $nombres   = trim((string)($datos['nombres'] ?? ''));
        $apellidos = trim((string)($datos['apellidos'] ?? ''));
        $documento = strtoupper(trim((string)($datos['documento_identidad'] ?? '')));
        $fechaNac  = trim((string)($datos['fecha_nacimiento'] ?? ''));
        $sexo      = trim((string)($datos['sexo_biologico'] ?? ''));
        $sangre    = trim((string)($datos['grupo_sanguineo'] ?? ''));
        $telefono  = trim((string)($datos['telefono'] ?? ''));
        $email     = trim((string)($datos['email'] ?? ''));
        $direccion = trim((string)($datos['direccion'] ?? ''));

        $nombreContacto   = trim((string)($datos['nombre_contacto'] ?? ''));
        $parentesco       = trim((string)($datos['parentesco'] ?? ''));
        $telEmergencia    = trim((string)($datos['telefono_emergencia'] ?? ''));

        if ($nombres === '' || $apellidos === '' || $documento === '' || $fechaNac === '' ||
            !in_array($sexo, ['MASCULINO', 'FEMENINO'], true) ||
            !in_array($sangre, ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], true) ||
            $nombreContacto === '' || $parentesco === '' || $telEmergencia === '') {
            return [
                'ok'      => false,
                'mensaje' => 'Todos los campos obligatorios de identidad, grupo sanguíneo y contacto de emergencia deben ser completados (RF-PAC-01).',
            ];
        }

        if (strtotime($fechaNac) === false || $fechaNac > date('Y-m-d')) {
            return [
                'ok'      => false,
                'mensaje' => 'La fecha de nacimiento no es válida o es futura.',
            ];
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // RF-PAC-01 & RBD-INT-02: Generar MRN determinista con bloqueo pesimista FOR UPDATE
            $anio = date('Y');
            $prefijoMrn = "MCS-{$anio}-";

            $stmtLock = $pdo->prepare(
                "SELECT mrn FROM pacientes
                 WHERE mrn LIKE :pref
                 ORDER BY mrn DESC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmtLock->execute([':pref' => $prefijoMrn . '%']);
            $ultimoMrn = $stmtLock->fetchColumn();

            $consecutivo = 1;
            if (is_string($ultimoMrn) && preg_match('/^MCS-\d{4}-(\d{6})$/', $ultimoMrn, $matches)) {
                $consecutivo = ((int)$matches[1]) + 1;
            }
            $nuevoMrn = sprintf('MCS-%s-%06d', $anio, $consecutivo);
            $nombreCompleto = $nombres . ' ' . $apellidos;

            $stmtPac = $pdo->prepare(
                "INSERT INTO pacientes (
                    mrn, documento_identidad, nombres, apellidos, nombre_completo,
                    fecha_nacimiento, sexo_biologico, grupo_sanguineo,
                    telefono, email, direccion, estado
                 ) VALUES (
                    :mrn, :doc, :nom, :ape, :nom_comp,
                    :fnac, :sexo, :sangre,
                    :tel, :email, :dir, 'ACTIVO'
                 )"
            );
            $stmtPac->execute([
                ':mrn'      => $nuevoMrn,
                ':doc'      => $documento,
                ':nom'      => $nombres,
                ':ape'      => $apellidos,
                ':nom_comp' => $nombreCompleto,
                ':fnac'     => $fechaNac,
                ':sexo'     => $sexo,
                ':sangre'   => $sangre,
                ':tel'      => $telefono !== '' ? $telefono : null,
                ':email'    => $email !== '' ? $email : null,
                ':dir'      => $direccion !== '' ? $direccion : null,
            ]);

            $idPaciente = (int)$pdo->lastInsertId();

            // Insertar Contacto de Emergencia Principal
            $stmtCont = $pdo->prepare(
                "INSERT INTO contactos_emergencia (id_paciente, nombre_contacto, parentesco, telefono_emergencia, es_principal)
                 VALUES (:id_pac, :nom, :par, :tel, 1)"
            );
            $stmtCont->execute([
                ':id_pac' => $idPaciente,
                ':nom'    => $nombreContacto,
                ':par'    => $parentesco,
                ':tel'    => $telEmergencia,
            ]);

            // Registrar evento en bitacora_auditoria
            $stmtAudit = $pdo->prepare(
                "INSERT INTO bitacora_auditoria (
                    tabla_afectada, operacion, id_registro_afectado, id_usuario_ejecutor, motivo, valores_nuevos, ip_origen
                 ) VALUES (
                    'pacientes', 'INSERT', :id_reg, :uid, :motivo, :nuevos, :ip
                 )"
            );
            $stmtAudit->execute([
                ':id_reg' => (string)$idPaciente,
                ':uid'    => $idUsuarioEjecutor,
                ':motivo' => "Empadronamiento único OPD con MRN {$nuevoMrn}",
                ':nuevos' => json_encode([
                    'mrn'                 => $nuevoMrn,
                    'documento_identidad' => $documento,
                    'nombre_completo'     => $nombreCompleto,
                    'grupo_sanguineo'     => $sangre,
                ], JSON_UNESCAPED_UNICODE),
                ':ip'     => Security::getClientIp(),
            ]);

            $pdo->commit();

            return [
                'ok'          => true,
                'id_paciente' => $idPaciente,
                'mrn'         => $nuevoMrn,
                'mensaje'     => "Paciente empadronado exitosamente. Número de Expediente Médico asignado: {$nuevoMrn}.",
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                return [
                    'ok'      => false,
                    'mensaje' => "RF-PAC-01: Rechazo por duplicidad. El documento oficial de identidad [{$documento}] ya cuenta con un expediente registrado en el hospital.",
                ];
            }
            return [
                'ok'      => false,
                'mensaje' => 'Error al empadronar paciente: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * RF-PAC-04: Actualización auditada de datos demográficos e inmutabilidad de identidad.
     */
    public function actualizarPaciente(array $datos, int $idUsuarioEjecutor, int $idRolEjecutor): array
    {
        $idPaciente = (int)($datos['id_paciente'] ?? 0);
        if ($idPaciente <= 0) {
            return ['ok' => false, 'mensaje' => 'ID de paciente inválido.'];
        }

        $actual = $this->obtenerPaciente($idPaciente);
        if (!$actual) {
            return ['ok' => false, 'mensaje' => 'El expediente del paciente no existe.'];
        }

        $tieneConsultasCerradas = ((int)$actual['consultas_cerradas']) > 0;

        $telefono  = trim((string)($datos['telefono'] ?? ''));
        $email     = trim((string)($datos['email'] ?? ''));
        $direccion = trim((string)($datos['direccion'] ?? ''));
        $nombreContacto = trim((string)($datos['nombre_contacto'] ?? ''));
        $parentesco     = trim((string)($datos['parentesco'] ?? ''));
        $telEmergencia  = trim((string)($datos['telefono_emergencia'] ?? ''));

        // Campos críticos de identidad
        $nuevosNombres   = isset($datos['nombres']) ? trim((string)$datos['nombres']) : (string)$actual['nombres'];
        $nuevosApellidos = isset($datos['apellidos']) ? trim((string)$datos['apellidos']) : (string)$actual['apellidos'];
        $nuevoDoc        = isset($datos['documento_identidad']) ? strtoupper(trim((string)$datos['documento_identidad'])) : (string)$actual['documento_identidad'];
        $nuevaFechaNac   = isset($datos['fecha_nacimiento']) ? trim((string)$datos['fecha_nacimiento']) : (string)$actual['fecha_nacimiento'];
        $nuevoSexo       = isset($datos['sexo_biologico']) ? trim((string)$datos['sexo_biologico']) : (string)$actual['sexo_biologico'];
        $nuevaSangre     = isset($datos['grupo_sanguineo']) ? trim((string)$datos['grupo_sanguineo']) : (string)$actual['grupo_sanguineo'];
        $motivoAuditoria = trim((string)($datos['motivo_auditoria'] ?? ''));

        $cambioCritico = (
            $nuevosNombres !== (string)$actual['nombres'] ||
            $nuevosApellidos !== (string)$actual['apellidos'] ||
            $nuevoDoc !== (string)$actual['documento_identidad'] ||
            $nuevaFechaNac !== (string)$actual['fecha_nacimiento'] ||
            $nuevoSexo !== (string)$actual['sexo_biologico'] ||
            $nuevaSangre !== (string)$actual['grupo_sanguineo']
        );

        // RF-PAC-04: Bloqueo de campos críticos si ya cuenta con al menos una consulta clínica cerrada
        if ($tieneConsultasCerradas && $cambioCritico) {
            if ($idRolEjecutor !== 1) {
                return [
                    'ok'      => false,
                    'mensaje' => 'RF-PAC-04: Inmutabilidad de Identidad. El paciente ya cuenta con consultas clínicas cerradas; el perfil Recepcionista solo puede actualizar datos de contacto (teléfono, correo, domicilio y contacto de emergencia).',
                ];
            }

            if ($motivoAuditoria === '') {
                return [
                    'ok'      => false,
                    'mensaje' => 'RF-PAC-04: Como Administrador, para corregir campos críticos de identidad en un paciente con historial clínico cerrado es obligatorio especificar el motivo de auditoría.',
                ];
            }
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $nombreCompleto = $nuevosNombres . ' ' . $nuevosApellidos;

            // El trigger trg_audit_update_pacientes registrará automáticamente OLD.* y NEW.* en bitacora_auditoria (RBD-INT-05)
            $stmtUpd = $pdo->prepare(
                "UPDATE pacientes
                 SET documento_identidad = :doc,
                     nombres = :nom,
                     apellidos = :ape,
                     nombre_completo = :nom_comp,
                     fecha_nacimiento = :fnac,
                     sexo_biologico = :sexo,
                     grupo_sanguineo = :sangre,
                     telefono = :tel,
                     email = :email,
                     direccion = :dir
                 WHERE id_paciente = :id"
            );
            $stmtUpd->execute([
                ':doc'      => $nuevoDoc,
                ':nom'      => $nuevosNombres,
                ':ape'      => $nuevosApellidos,
                ':nom_comp' => $nombreCompleto,
                ':fnac'     => $nuevaFechaNac,
                ':sexo'     => $nuevoSexo,
                ':sangre'   => $nuevaSangre,
                ':tel'      => $telefono !== '' ? $telefono : null,
                ':email'    => $email !== '' ? $email : null,
                ':dir'      => $direccion !== '' ? $direccion : null,
                ':id'       => $idPaciente,
            ]);

            if ($nombreContacto !== '' && $parentesco !== '' && $telEmergencia !== '') {
                $stmtCheckCont = $pdo->prepare("SELECT id_contacto FROM contactos_emergencia WHERE id_paciente = :id AND es_principal = 1 LIMIT 1");
                $stmtCheckCont->execute([':id' => $idPaciente]);
                $idCont = $stmtCheckCont->fetchColumn();

                if ($idCont) {
                    $stmtCont = $pdo->prepare(
                        "UPDATE contactos_emergencia
                         SET nombre_contacto = :nom, parentesco = :par, telefono_emergencia = :tel
                         WHERE id_contacto = :id_cont"
                    );
                    $stmtCont->execute([
                        ':nom'     => $nombreContacto,
                        ':par'     => $parentesco,
                        ':tel'     => $telEmergencia,
                        ':id_cont' => (int)$idCont,
                    ]);
                } else {
                    $stmtCont = $pdo->prepare(
                        "INSERT INTO contactos_emergencia (id_paciente, nombre_contacto, parentesco, telefono_emergencia, es_principal)
                         VALUES (:id_pac, :nom, :par, :tel, 1)"
                    );
                    $stmtCont->execute([
                        ':id_pac' => $idPaciente,
                        ':nom'    => $nombreContacto,
                        ':par'    => $parentesco,
                        ':tel'    => $telEmergencia,
                    ]);
                }
            }

            $stmtMotivo = $pdo->prepare(
                "UPDATE bitacora_auditoria
                 SET id_usuario_ejecutor = :uid,
                     motivo = :motivo,
                     ip_origen = :ip
                 WHERE tabla_afectada = 'pacientes'
                   AND id_registro_afectado = :id_reg
                 ORDER BY id_evento DESC
                 LIMIT 1"
            );
            $stmtMotivo->execute([
                ':uid'    => $idUsuarioEjecutor,
                ':motivo' => $motivoAuditoria !== '' ? "RF-PAC-04: {$motivoAuditoria}" : 'Actualización de datos de contacto de paciente',
                ':ip'     => Security::getClientIp(),
                ':id_reg' => (string)$idPaciente,
            ]);

            $pdo->commit();

            return [
                'ok'      => true,
                'mensaje' => "Expediente {$actual['mrn']} actualizado correctamente y auditado en bitácora.",
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'ok'      => false,
                'mensaje' => 'Error al actualizar expediente: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Obtiene el catálogo maestro de principios activos y alérgenos (catalogo_alergias).
     */
    public function listarCatalogoAlergias(): array
    {
        $pdo = Database::getConnection();
        return $pdo->query("SELECT id_alergeno, codigo_alergeno, principio_activo_o_familia, descripcion FROM catalogo_alergias ORDER BY principio_activo_o_familia ASC")->fetchAll();
    }

    /**
     * RF-PAC-02: Asocia una alerta de alergia farmacológica a un paciente especificando severidad y fecha de detección.
     */
    public function registrarAlergiaPaciente(array $datos, int $idUsuarioEjecutor): array
    {
        $idPaciente = (int)($datos['id_paciente'] ?? 0);
        $idAlergeno = (int)($datos['id_alergeno'] ?? 0);
        $severidad  = trim((string)($datos['severidad'] ?? ''));
        $reaccion   = trim((string)($datos['reaccion_descrita'] ?? ''));
        $fechaDet   = trim((string)($datos['fecha_deteccion'] ?? date('Y-m-d')));

        if ($idPaciente <= 0 || $idAlergeno <= 0 || !in_array($severidad, ['LEVE', 'MODERADA', 'ANAFILAXIA_SEVERA'], true)) {
            return [
                'ok'      => false,
                'mensaje' => 'Debe seleccionar un paciente, un principio activo/alérgeno del catálogo y un nivel de severidad válido (RF-PAC-02).',
            ];
        }

        $pdo = Database::getConnection();

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO paciente_alergias (id_paciente, id_alergeno, severidad, reaccion_descrita, fecha_deteccion, activa)
                 VALUES (:id_pac, :id_alg, :sev, :reac, :fdet, 1)
                 ON DUPLICATE KEY UPDATE
                    severidad = VALUES(severidad),
                    reaccion_descrita = VALUES(reaccion_descrita),
                    fecha_deteccion = VALUES(fecha_deteccion),
                    activa = 1"
            );
            $stmt->execute([
                ':id_pac' => $idPaciente,
                ':id_alg' => $idAlergeno,
                ':sev'    => $severidad,
                ':reac'   => $reaccion !== '' ? $reaccion : null,
                ':fdet'   => $fechaDet !== '' ? $fechaDet : date('Y-m-d'),
            ]);

            return [
                'ok'      => true,
                'mensaje' => "Alerta clínica de alergia ({$severidad}) vinculada al expediente exitosamente (RF-PAC-02 / RN-CLI-05).",
            ];
        } catch (PDOException $e) {
            return [
                'ok'      => false,
                'mensaje' => 'Error al registrar alergia: ' . $e->getMessage(),
            ];
        }
    }
}
