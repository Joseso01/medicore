<?php
declare(strict_types=1);

namespace MediCore\Controllers;

use MediCore\Config\Database;
use MediCore\Core\Security;
use PDO;
use PDOException;

/**
 * Controlador del Submódulo de Historial Clínico Electrónico (EHR) y Recetas (HIS)
 * Cumplimiento: RF-HIS-01 a RF-HIS-04, RN-CLI-02, RN-CLI-03, RN-CLI-05, RN-CLI-06, RBD-INT-02
 */
final class ConsultaController
{
    /**
     * Obtiene los catálogos clínicos necesarios durante el acto médico:
     * - Diagnósticos CIE-10 activos
     * - Medicamentos activos con su stock vigente no caducado en lotes_inventario (RF-HIS-03)
     */
    public function obtenerCatalogosClinicos(): array
    {
        $pdo = Database::getConnection();

        $diagnosticos = $pdo->query(
            "SELECT id_diagnostico, codigo_cie, descripcion, categoria
             FROM diagnosticos_cie
             WHERE activo = 1
             ORDER BY codigo_cie ASC"
        )->fetchAll();

        $medicamentos = $pdo->query(
            "SELECT m.id_medicamento, m.codigo_sku, m.nombre_generico, m.nombre_comercial,
                    m.principio_activo, m.id_alergeno, m.forma_farmaceutica, m.concentracion,
                    ca.principio_activo_o_familia AS alergeno_familia,
                    COALESCE((
                        SELECT SUM(li.cantidad_actual)
                        FROM lotes_inventario li
                        WHERE li.id_medicamento = m.id_medicamento
                          AND li.estado_lote = 'ACTIVO'
                          AND li.fecha_caducidad > CURDATE()
                    ), 0) AS stock_vigente_disponible
             FROM medicamentos m
             LEFT JOIN catalogo_alergias ca ON ca.id_alergeno = m.id_alergeno
             WHERE m.activo = 1
             ORDER BY m.nombre_generico ASC"
        )->fetchAll();

        return [
            'diagnosticos' => $diagnosticos,
            'medicamentos' => $medicamentos,
        ];
    }

    /**
     * Lista las citas asignadas al médico en sesión para iniciar o continuar el acto clínico,
     * así como las consultas clínicas recientes del facultativo.
     */
    public function listarCitasYConsultasMedico(int $idMedico): array
    {
        $pdo = Database::getConnection();

        $stmtCitas = $pdo->prepare(
            "SELECT c.id_cita, c.fecha_cita, c.hora_inicio, c.hora_fin, c.motivo_consulta, c.estado,
                    p.id_paciente, p.mrn, p.nombre_completo AS paciente_nombre, p.grupo_sanguineo,
                    TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) AS edad,
                    con.codigo_consultorio,
                    cc.id_consulta, cc.estado_consulta
             FROM citas_medicas c
             INNER JOIN pacientes p ON p.id_paciente = c.id_paciente
             INNER JOIN consultorios con ON con.id_consultorio = c.id_consultorio
             LEFT JOIN consultas_clinicas cc ON cc.id_cita = c.id_cita
             WHERE c.id_medico = :med
               AND c.estado NOT IN ('CANCELADA', 'NO_ASISTIO')
             ORDER BY c.fecha_cita DESC, c.hora_inicio ASC
             LIMIT 50"
        );
        $stmtCitas->execute([':med' => $idMedico]);
        return $stmtCitas->fetchAll();
    }

    /**
     * RF-HIS-01: Abre una consulta clínica vinculada a una cita activa (o recupera la consulta si ya fue abierta)
     * dentro de una transacción ACID en PDO, cambiando el estado de la cita a EN_CURSO.
     */
    public function abrirOCargarConsultaPorCita(int $idCita, int $idMedicoSesion, int $idUsuarioEjecutor): array
    {
        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmtCita = $pdo->prepare(
                "SELECT c.*, p.mrn, p.nombre_completo AS paciente_nombre, p.documento_identidad AS doc_paciente,
                        p.fecha_nacimiento, p.sexo_biologico, p.grupo_sanguineo,
                        TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) AS edad,
                        con.codigo_consultorio, con.nombre AS consultorio_nombre
                 FROM citas_medicas c
                 INNER JOIN pacientes p ON p.id_paciente = c.id_paciente
                 INNER JOIN consultorios con ON con.id_consultorio = c.id_consultorio
                 WHERE c.id_cita = :id_cita
                 FOR UPDATE"
            );
            $stmtCita->execute([':id_cita' => $idCita]);
            $cita = $stmtCita->fetch();

            if (!$cita) {
                $pdo->rollBack();
                return ['ok' => false, 'mensaje' => 'La cita médica especificada no existe.'];
            }

            // RF-HIS-01: Validar que el médico en sesión sea el titular de la cita
            if ((int)$cita['id_medico'] !== $idMedicoSesion) {
                $pdo->rollBack();
                return [
                    'ok'      => false,
                    'mensaje' => 'RF-HIS-01 / RN-CLI-06: Acceso denegado. Solo el médico facultativo titular asignado a la cita puede abrir o consultar este acto clínico.',
                ];
            }

            // Verificar si ya existe registro en consultas_clinicas para esta cita (UNIQUE KEY id_cita)
            $stmtCons = $pdo->prepare(
                "SELECT * FROM consultas_clinicas WHERE id_cita = :id_cita LIMIT 1"
            );
            $stmtCons->execute([':id_cita' => $idCita]);
            $consulta = $stmtCons->fetch();

            if (!$consulta) {
                if (!in_array($cita['estado'], ['PROGRAMADA', 'CONFIRMADA'], true)) {
                    $pdo->rollBack();
                    return [
                        'ok'      => false,
                        'mensaje' => "RF-HIS-01: No se puede abrir una consulta a partir de una cita en estado [{$cita['estado']}].",
                    ];
                }

                // Actualizar estado de la cita a EN_CURSO (RF-HIS-01 / RF-CIT-02)
                $stmtUpdCita = $pdo->prepare(
                    "UPDATE citas_medicas SET estado = 'EN_CURSO' WHERE id_cita = :id_cita"
                );
                $stmtUpdCita->execute([':id_cita' => $idCita]);
                $cita['estado'] = 'EN_CURSO';

                // Insertar registro en consultas_clinicas con fecha_hora_inicio = NOW()
                $stmtInsCons = $pdo->prepare(
                    "INSERT INTO consultas_clinicas (
                        id_cita, id_paciente, id_medico, estado_consulta, fecha_hora_inicio
                     ) VALUES (
                        :id_cita, :id_pac, :id_med, 'ABIERTA', NOW()
                     )"
                );
                $stmtInsCons->execute([
                    ':id_cita' => $idCita,
                    ':id_pac'  => (int)$cita['id_paciente'],
                    ':id_med'  => $idMedicoSesion,
                ]);

                $idConsulta = (int)$pdo->lastInsertId();

                // Registrar en bitácora
                $stmtAudit = $pdo->prepare(
                    "INSERT INTO bitacora_auditoria (tabla_afectada, operacion, id_registro_afectado, id_usuario_ejecutor, motivo, ip_origen)
                     VALUES ('consultas_clinicas', 'INSERT', :id_reg, :uid, :motivo, :ip)"
                );
                $stmtAudit->execute([
                    ':id_reg' => (string)$idConsulta,
                    ':uid'    => $idUsuarioEjecutor,
                    ':motivo' => "RF-HIS-01: Apertura de consulta clínica #{$idConsulta} vinculada a cita #{$idCita}",
                    ':ip'     => Security::getClientIp(),
                ]);

                $stmtCons->execute([':id_cita' => $idCita]);
                $consulta = $stmtCons->fetch();
            }

            $pdo->commit();

            return $this->armarExpedienteConsulta((int)$consulta['id_consulta'], $cita);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return [
                'ok'      => false,
                'mensaje' => 'Error al abrir la consulta clínica: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Obtiene toda la información estructurada de una consulta (signos vitales, alergias del paciente,
     * receta asociada, adendas y el historial longitudinal completo del EHR del paciente).
     */
    public function armarExpedienteConsulta(int $idConsulta, ?array $citaPrevia = null): array
    {
        $pdo = Database::getConnection();

        $stmtCons = $pdo->prepare(
            "SELECT cc.*, d.codigo_cie, d.descripcion AS diagnostico_descripcion,
                    u.nombre_completo AS medico_nombre, m.cedula_profesional, e.nombre AS especialidad_nombre
             FROM consultas_clinicas cc
             INNER JOIN medicos m ON m.id_medico = cc.id_medico
             INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
             INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
             LEFT JOIN diagnosticos_cie d ON d.id_diagnostico = cc.id_diagnostico_principal
             WHERE cc.id_consulta = :id
             LIMIT 1"
        );
        $stmtCons->execute([':id' => $idConsulta]);
        $consulta = $stmtCons->fetch();

        if (!$consulta) {
            return ['ok' => false, 'mensaje' => 'Consulta clínica no encontrada.'];
        }

        $idPaciente = (int)$consulta['id_paciente'];

        if (!$citaPrevia) {
            $stmtCita = $pdo->prepare(
                "SELECT c.*, p.mrn, p.nombre_completo AS paciente_nombre, p.documento_identidad AS doc_paciente,
                        p.fecha_nacimiento, p.sexo_biologico, p.grupo_sanguineo,
                        TIMESTAMPDIFF(YEAR, p.fecha_nacimiento, CURDATE()) AS edad,
                        con.codigo_consultorio, con.nombre AS consultorio_nombre
                 FROM citas_medicas c
                 INNER JOIN pacientes p ON p.id_paciente = c.id_paciente
                 INNER JOIN consultorios con ON con.id_consultorio = c.id_consultorio
                 WHERE c.id_cita = :id_cita
                 LIMIT 1"
            );
            $stmtCita->execute([':id_cita' => (int)$consulta['id_cita']]);
            $citaPrevia = $stmtCita->fetch();
        }

        // Alertas de alergias del paciente (RF-PAC-02 / RN-CLI-05)
        $stmtAlg = $pdo->prepare(
            "SELECT pa.id_paciente_alergia, pa.id_alergeno, pa.severidad, pa.reaccion_descrita, pa.fecha_deteccion,
                    ca.codigo_alergeno, ca.principio_activo_o_familia
             FROM paciente_alergias pa
             INNER JOIN catalogo_alergias ca ON ca.id_alergeno = pa.id_alergeno
             WHERE pa.id_paciente = :id_pac AND pa.activa = 1
             ORDER BY FIELD(pa.severidad, 'ANAFILAXIA_SEVERA', 'MODERADA', 'LEVE')"
        );
        $stmtAlg->execute([':id_pac' => $idPaciente]);
        $alergias = $stmtAlg->fetchAll();

        // Signos vitales de esta consulta
        $stmtSv = $pdo->prepare("SELECT * FROM signos_vitales WHERE id_consulta = :id LIMIT 1");
        $stmtSv->execute([':id' => $idConsulta]);
        $signos = $stmtSv->fetch() ?: null;

        // Receta médica y detalle de esta consulta
        $stmtRec = $pdo->prepare("SELECT * FROM recetas_medicas WHERE id_consulta = :id LIMIT 1");
        $stmtRec->execute([':id' => $idConsulta]);
        $receta = $stmtRec->fetch() ?: null;

        $recetaDetalle = [];
        if ($receta) {
            $stmtDet = $pdo->prepare(
                "SELECT rd.*, m.codigo_sku, m.nombre_generico, m.nombre_comercial, m.forma_farmaceutica, m.concentracion
                 FROM receta_detalle rd
                 INNER JOIN medicamentos m ON m.id_medicamento = rd.id_medicamento
                 WHERE rd.id_receta = :id_rec"
            );
            $stmtDet->execute([':id_rec' => (int)$receta['id_receta']]);
            $recetaDetalle = $stmtDet->fetchAll();
        }

        // Adendas clínicas de esta consulta (RN-CLI-03)
        $stmtAd = $pdo->prepare(
            "SELECT na.*, u.nombre_completo AS medico_autor_nombre, m.cedula_profesional
             FROM notas_adenda_clinica na
             INNER JOIN medicos m ON m.id_medico = na.id_medico_autor
             INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
             WHERE na.id_consulta = :id
             ORDER BY na.fecha_registro ASC"
        );
        $stmtAd->execute([':id' => $idConsulta]);
        $adendas = $stmtAd->fetchAll();

        // Historial Clínico Longitudinal completo del paciente (RF-HIS-04)
        $historialLongitudinal = $this->obtenerHistorialLongitudinal($idPaciente);

        return [
            'ok'                     => true,
            'cita'                   => $citaPrevia,
            'consulta'               => $consulta,
            'alergias'               => $alergias,
            'signos_vitales'         => $signos,
            'receta'                 => $receta,
            'receta_detalle'         => $recetaDetalle,
            'adendas'                => $adendas,
            'historial_longitudinal' => $historialLongitudinal,
        ];
    }

    /**
     * RF-HIS-04: Consulta longitudinal cronológica de solo lectura de todas las consultas,
     * signos vitales, diagnósticos y recetas previas de un paciente.
     */
    public function obtenerHistorialLongitudinal(int $idPaciente): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "SELECT cc.id_consulta, cc.fecha_hora_inicio, cc.fecha_hora_cierre, cc.estado_consulta,
                    cc.nota_subjetiva, cc.nota_objetiva, cc.nota_analisis, cc.nota_plan, cc.firma_digital_hash,
                    d.codigo_cie, d.descripcion AS diagnostico_descripcion,
                    u.nombre_completo AS medico_nombre, e.nombre AS especialidad_nombre,
                    sv.presion_sistolica, sv.presion_diastolica, sv.frecuencia_cardiaca,
                    sv.temperatura_c, sv.saturacion_o2, sv.peso_kg, sv.talla_cm, sv.imc,
                    r.id_receta, r.folio_receta, r.estado_receta
             FROM consultas_clinicas cc
             INNER JOIN medicos m ON m.id_medico = cc.id_medico
             INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
             INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
             LEFT JOIN diagnosticos_cie d ON d.id_diagnostico = cc.id_diagnostico_principal
             LEFT JOIN signos_vitales sv ON sv.id_consulta = cc.id_consulta
             LEFT JOIN recetas_medicas r ON r.id_consulta = cc.id_consulta
             WHERE cc.id_paciente = :id_pac
             ORDER BY cc.fecha_hora_inicio DESC"
        );
        $stmt->execute([':id_pac' => $idPaciente]);
        return $stmt->fetchAll();
    }

    /**
     * RF-HIS-02, RF-HIS-03, RF-HIS-04, RN-CLI-02, RN-CLI-03, RN-CLI-05:
     * Guarda o cierra de forma transaccional (ACID) la consulta clínica:
     * 1. Valida rangos fisiológicos de signos vitales y recalcula IMC en servidor.
     * 2. Registra signos_vitales y nota SOAP + diagnóstico CIE-10 en consultas_clinicas.
     * 3. Si incluye renglones de prescripción, valida alergias (RN-CLI-05) y stock vigente (RF-HIS-03),
     *    emitiendo la receta electrónica en recetas_medicas y receta_detalle.
     * 4. Si cerrar_consulta = true, sella la consulta (CERRADA + firma SHA-256) y cambia la cita a ATENDIDA.
     */
    public function guardarOCerrarConsulta(array $payload, int $idMedicoSesion, int $idUsuarioEjecutor): array
    {
        $idConsulta     = (int)($payload['id_consulta'] ?? 0);
        $cerrarConsulta = !empty($payload['cerrar_consulta']);

        // Signos vitales (RF-HIS-02)
        $sistolica   = (int)($payload['presion_sistolica'] ?? 0);
        $diastolica  = (int)($payload['presion_diastolica'] ?? 0);
        $fc          = (int)($payload['frecuencia_cardiaca'] ?? 0);
        $temp        = (float)($payload['temperatura_c'] ?? 0);
        $spo2        = (int)($payload['saturacion_o2'] ?? 0);
        $pesoKg      = (float)($payload['peso_kg'] ?? 0);
        $tallaCm     = (float)($payload['talla_cm'] ?? 0);

        // Nota SOAP y Diagnóstico CIE-10 (RF-HIS-02)
        $idDiagnostico = (int)($payload['id_diagnostico_principal'] ?? 0);
        $notaSubjetiva = trim((string)($payload['nota_subjetiva'] ?? ''));
        $notaObjetiva  = trim((string)($payload['nota_objetiva'] ?? ''));
        $notaAnalisis  = trim((string)($payload['nota_analisis'] ?? ''));
        $notaPlan      = trim((string)($payload['nota_plan'] ?? ''));

        // Receta electrónica opcional o requerida (RF-HIS-03)
        $observacionesReceta = trim((string)($payload['observaciones_receta'] ?? ''));
        $renglonesReceta = is_array($payload['renglones_receta'] ?? null) ? $payload['renglones_receta'] : [];

        // Validaciones fisiológicas en PHP (doble barrera junto con CHECK constraints de MySQL)
        if ($sistolica < 50 || $sistolica > 260 || $diastolica < 30 || $diastolica > 160) {
            return ['ok' => false, 'mensaje' => 'RF-HIS-02: Presión arterial fuera de rango fisiológico válido (Sistólica: 50-260 mmHg, Diastólica: 30-160 mmHg).'];
        }
        if ($fc < 30 || $fc > 240) {
            return ['ok' => false, 'mensaje' => 'RF-HIS-02: Frecuencia cardíaca fuera de rango válido (30-240 lpm).'];
        }
        if ($temp < 34.0 || $temp > 43.0) {
            return ['ok' => false, 'mensaje' => 'RF-HIS-02: Temperatura corporal fuera de rango válido (34.0-43.0 °C).'];
        }
        if ($spo2 < 50 || $spo2 > 100) {
            return ['ok' => false, 'mensaje' => 'RF-HIS-02: Saturación de oxígeno (SpO2) fuera de rango válido (50-100%).'];
        }
        if ($pesoKg <= 0 || $pesoKg > 500 || $tallaCm < 30 || $tallaCm > 260) {
            return ['ok' => false, 'mensaje' => 'RF-HIS-02: Peso (1-500 kg) o talla (30-260 cm) fuera de rango fisiológico válido.'];
        }

        // Cálculo y validación de IMC en servidor: IMC = peso / (talla_m)^2
        $tallaMetros = $tallaCm / 100.0;
        $imcCalculado = round($pesoKg / ($tallaMetros * $tallaMetros), 2);

        if ($idDiagnostico <= 0 || $notaSubjetiva === '' || $notaObjetiva === '' || $notaPlan === '') {
            return [
                'ok'      => false,
                'mensaje' => 'RF-HIS-02: Es obligatorio capturar la nota clínica estructurada (Subjetivo, Objetivo, Plan) y seleccionar un Diagnóstico CIE-10 codificado.',
            ];
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmtCons = $pdo->prepare("SELECT * FROM consultas_clinicas WHERE id_consulta = :id FOR UPDATE");
            $stmtCons->execute([':id' => $idConsulta]);
            $consulta = $stmtCons->fetch();

            if (!$consulta) {
                $pdo->rollBack();
                return ['ok' => false, 'mensaje' => 'La consulta clínica no existe.'];
            }

            if ((int)$consulta['id_medico'] !== $idMedicoSesion) {
                $pdo->rollBack();
                return ['ok' => false, 'mensaje' => 'RN-CLI-06: Solo el médico titular de la consulta puede editarla o cerrarla.'];
            }

            if ($consulta['estado_consulta'] === 'CERRADA') {
                $pdo->rollBack();
                return [
                    'ok'      => false,
                    'mensaje' => 'RN-CLI-03: Violación de inmutabilidad del Expediente Clínico Electrónico. La consulta ya se encuentra CERRADA; utilice el mecanismo de Adenda Clínica.',
                ];
            }

            $idPaciente = (int)$consulta['id_paciente'];
            $idCita     = (int)$consulta['id_cita'];

            // 1. Insertar o actualizar signos_vitales (antes de emitir receta para cumplir RN-CLI-02)
            $stmtSv = $pdo->prepare(
                "INSERT INTO signos_vitales (
                    id_consulta, presion_sistolica, presion_diastolica, frecuencia_cardiaca,
                    temperatura_c, saturacion_o2, peso_kg, talla_cm, imc
                 ) VALUES (
                    :id_cons, :sis, :dia, :fc, :temp, :spo2, :peso, :talla, :imc
                 )
                 ON DUPLICATE KEY UPDATE
                    presion_sistolica = VALUES(presion_sistolica),
                    presion_diastolica = VALUES(presion_diastolica),
                    frecuencia_cardiaca = VALUES(frecuencia_cardiaca),
                    temperatura_c = VALUES(temperatura_c),
                    saturacion_o2 = VALUES(saturacion_o2),
                    peso_kg = VALUES(peso_kg),
                    talla_cm = VALUES(talla_cm),
                    imc = VALUES(imc)"
            );
            $stmtSv->execute([
                ':id_cons' => $idConsulta,
                ':sis'     => $sistolica,
                ':dia'     => $diastolica,
                ':fc'      => $fc,
                ':temp'    => $temp,
                ':spo2'    => $spo2,
                ':peso'    => $pesoKg,
                ':talla'   => $tallaCm,
                ':imc'     => $imcCalculado,
            ]);

            // 2. Actualizar nota SOAP y diagnóstico principal en consultas_clinicas (mientras sigue ABIERTA)
            $stmtUpdCons = $pdo->prepare(
                "UPDATE consultas_clinicas
                 SET id_diagnostico_principal = :diag,
                     nota_subjetiva = :sub,
                     nota_objetiva = :obj,
                     nota_analisis = :ana,
                     nota_plan = :plan
                 WHERE id_consulta = :id"
            );
            $stmtUpdCons->execute([
                ':diag' => $idDiagnostico,
                ':sub'  => $notaSubjetiva,
                ':obj'  => $notaObjetiva,
                ':ana'  => $notaAnalisis !== '' ? $notaAnalisis : null,
                ':plan' => $notaPlan,
                ':id'   => $idConsulta,
            ]);

            // 3. Procesar Receta Electrónica si hay medicamentos prescritos (RF-HIS-03 / RN-CLI-02 / RN-CLI-05)
            $folioGenerado = null;
            if (!empty($renglonesReceta)) {
                $stmtRecExist = $pdo->prepare("SELECT id_receta, folio_receta FROM recetas_medicas WHERE id_consulta = :id LIMIT 1");
                $stmtRecExist->execute([':id' => $idConsulta]);
                $recExist = $stmtRecExist->fetch();

                if ($recExist) {
                    $idReceta = (int)$recExist['id_receta'];
                    $folioGenerado = (string)$recExist['folio_receta'];
                    $stmtUpdRec = $pdo->prepare(
                        "UPDATE recetas_medicas
                         SET observaciones_generales = :obs,
                             estado_receta = 'PENDIENTE_SURTIR'
                         WHERE id_receta = :id_rec"
                    );
                    $stmtUpdRec->execute([
                        ':obs'    => $observacionesReceta !== '' ? $observacionesReceta : null,
                        ':id_rec' => $idReceta,
                    ]);

                    // Limpiar renglones previos mientras la consulta sigue ABIERTA
                    $stmtDelDet = $pdo->prepare("DELETE FROM receta_detalle WHERE id_receta = :id_rec");
                    $stmtDelDet->execute([':id_rec' => $idReceta]);
                } else {
                    $folioGenerado = sprintf('REC-%s-%06d', date('Y'), $idConsulta);
                    $stmtInsRec = $pdo->prepare(
                        "INSERT INTO recetas_medicas (
                            folio_receta, id_consulta, id_medico_emisor, estado_receta, observaciones_generales
                         ) VALUES (
                            :folio, :id_cons, :id_med, 'PENDIENTE_SURTIR', :obs
                         )"
                    );
                    $stmtInsRec->execute([
                        ':folio'   => $folioGenerado,
                        ':id_cons' => $idConsulta,
                        ':id_med'  => $idMedicoSesion,
                        ':obs'     => $observacionesReceta !== '' ? $observacionesReceta : null,
                    ]);
                    $idReceta = (int)$pdo->lastInsertId();
                }

                // Validar cada renglón de medicamento: (1) Alergias RN-CLI-05 y (2) Stock vigente RF-HIS-03
                $stmtCheckAlergia = $pdo->prepare(
                    "SELECT ca.principio_activo_o_familia, pa.severidad
                     FROM medicamentos m
                     INNER JOIN paciente_alergias pa ON pa.id_paciente = :id_pac AND pa.activa = 1
                     INNER JOIN catalogo_alergias ca ON ca.id_alergeno = pa.id_alergeno
                     WHERE m.id_medicamento = :id_med
                       AND pa.severidad IN ('MODERADA', 'ANAFILAXIA_SEVERA')
                       AND (
                           m.id_alergeno = pa.id_alergeno
                           OR LOWER(m.principio_activo) LIKE CONCAT('%', LOWER(ca.principio_activo_o_familia), '%')
                       )
                     LIMIT 1"
                );

                $stmtCheckStock = $pdo->prepare(
                    "SELECT m.nombre_generico,
                            COALESCE(SUM(li.cantidad_actual), 0) AS stock_vigente
                     FROM medicamentos m
                     LEFT JOIN lotes_inventario li
                       ON li.id_medicamento = m.id_medicamento
                      AND li.estado_lote = 'ACTIVO'
                      AND li.fecha_caducidad > CURDATE()
                     WHERE m.id_medicamento = :id_med
                     GROUP BY m.id_medicamento, m.nombre_generico"
                );

                $stmtInsDet = $pdo->prepare(
                    "INSERT INTO receta_detalle (
                        id_receta, id_medicamento, dosis, via_administracion,
                        frecuencia_horaria, duracion_tratamiento, cantidad_prescrita, cantidad_surtida
                     ) VALUES (
                        :id_rec, :id_med, :dosis, :via, :frec, :dur, :cant, 0
                     )"
                );

                foreach ($renglonesReceta as $renglon) {
                    $idMed  = (int)($renglon['id_medicamento'] ?? 0);
                    $dosis  = trim((string)($renglon['dosis'] ?? ''));
                    $via    = trim((string)($renglon['via_administracion'] ?? 'ORAL'));
                    $frec   = trim((string)($renglon['frecuencia_horaria'] ?? ''));
                    $dur    = trim((string)($renglon['duracion_tratamiento'] ?? ''));
                    $cant   = (int)($renglon['cantidad_prescrita'] ?? 0);

                    if ($idMed <= 0 || $dosis === '' || $frec === '' || $dur === '' || $cant <= 0) {
                        $pdo->rollBack();
                        return ['ok' => false, 'mensaje' => 'RF-HIS-03: Todos los renglones de la receta deben especificar medicamento, dosis, vía, frecuencia, duración y cantidad > 0.'];
                    }

                    // Validación preventiva en PHP de RN-CLI-05 (además del Trigger trg_before_insert_receta_detalle)
                    $stmtCheckAlergia->execute([':id_pac' => $idPaciente, ':id_med' => $idMed]);
                    $alergiaMatch = $stmtCheckAlergia->fetch();
                    if ($alergiaMatch) {
                        $pdo->rollBack();
                        return [
                            'ok'      => false,
                            'mensaje' => "RN-CLI-05: Alerta de seguridad clínica. El paciente presenta alergia registrada ({$alergiaMatch['severidad']}) al principio activo prescrito [{$alergiaMatch['principio_activo_o_familia']}]. Prescripción bloqueada.",
                        ];
                    }

                    // Validación de existencias vigentes no caducadas en lotes_inventario (RF-HIS-03)
                    $stmtCheckStock->execute([':id_med' => $idMed]);
                    $stockRow = $stmtCheckStock->fetch();
                    $stockVigente = (int)($stockRow['stock_vigente'] ?? 0);
                    $nomMed = (string)($stockRow['nombre_generico'] ?? "ID #{$idMed}");

                    if ($cant > $stockVigente) {
                        $pdo->rollBack();
                        return [
                            'ok'      => false,
                            'mensaje' => "RF-HIS-03: Stock vigente insuficiente para [{$nomMed}]. Cantidad solicitada: {$cant}, existencias vigentes no caducadas en lotes: {$stockVigente}.",
                        ];
                    }

                    $stmtInsDet->execute([
                        ':id_rec' => $idReceta,
                        ':id_med' => $idMed,
                        ':dosis'  => $dosis,
                        ':via'    => $via,
                        ':frec'   => $frec,
                        ':dur'    => $dur,
                        ':cant'   => $cant,
                    ]);
                }
            }

            // 4. Si se solicitó el Cierre y Firma Digital de la consulta (RF-HIS-04 y RN-CLI-03)
            if ($cerrarConsulta) {
                $firmaHash = hash(
                    'sha256',
                    implode('|', [$idConsulta, $idPaciente, $idMedicoSesion, $idDiagnostico, $notaSubjetiva, $notaObjetiva, $notaPlan, date('c')])
                );

                $stmtCerrar = $pdo->prepare(
                    "UPDATE consultas_clinicas
                     SET estado_consulta = 'CERRADA',
                         firma_digital_hash = :hash,
                         fecha_hora_cierre = NOW()
                     WHERE id_consulta = :id"
                );
                $stmtCerrar->execute([
                    ':hash' => $firmaHash,
                    ':id'   => $idConsulta,
                ]);

                // Actualizar estado de la cita médica a ATENDIDA (RF-HIS-04 / RF-CIT-02)
                $stmtCitaAtendida = $pdo->prepare(
                    "UPDATE citas_medicas SET estado = 'ATENDIDA' WHERE id_cita = :id_cita"
                );
                $stmtCitaAtendida->execute([':id_cita' => $idCita]);

                // Registrar cierre en bitacora_auditoria
                $stmtAudit = $pdo->prepare(
                    "INSERT INTO bitacora_auditoria (tabla_afectada, operacion, id_registro_afectado, id_usuario_ejecutor, motivo, valores_nuevos, ip_origen)
                     VALUES ('consultas_clinicas', 'UPDATE', :id_reg, :uid, :motivo, :nuevos, :ip)"
                );
                $stmtAudit->execute([
                    ':id_reg' => (string)$idConsulta,
                    ':uid'    => $idUsuarioEjecutor,
                    ':motivo' => "RF-HIS-04 / RN-CLI-03: Cierre transaccional y sellado inmutable de consulta #{$idConsulta}" . ($folioGenerado ? " con receta {$folioGenerado}" : ''),
                    ':nuevos' => json_encode([
                        'estado_consulta'    => 'CERRADA',
                        'firma_digital_hash' => $firmaHash,
                        'folio_receta'       => $folioGenerado,
                    ], JSON_UNESCAPED_UNICODE),
                    ':ip'     => Security::getClientIp(),
                ]);
            }

            $pdo->commit();

            return [
                'ok'           => true,
                'id_consulta'  => $idConsulta,
                'folio_receta' => $folioGenerado,
                'imc'          => $imcCalculado,
                'mensaje'      => $cerrarConsulta
                    ? "Consulta clínica #{$idConsulta} cerrada, firmada digitalmente y sellada contra ediciones (RN-CLI-03)." . ($folioGenerado ? " Receta emitida: {$folioGenerado}." : '')
                    : "Avance de consulta clínica #{$idConsulta} guardado correctamente (IMC calculado: {$imcCalculado}).",
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = $e->getMessage();
            if (str_contains($msg, 'RN-CLI-05')) {
                return ['ok' => false, 'mensaje' => 'RN-CLI-05: Alerta de seguridad clínica. El paciente presenta alergia registrada al principio activo prescrito.'];
            }
            if (str_contains($msg, 'RN-CLI-03')) {
                return ['ok' => false, 'mensaje' => 'RN-CLI-03: Violación de inmutabilidad del Expediente Clínico Electrónico.'];
            }
            if (str_contains($msg, 'RN-CLI-02')) {
                return ['ok' => false, 'mensaje' => 'RN-CLI-02: No se puede emitir receta sin consulta activa con signos vitales y diagnóstico.'];
            }
            return ['ok' => false, 'mensaje' => 'Error transaccional en consulta: ' . $msg];
        }
    }

    /**
     * RN-CLI-03: Registra una Nota de Adenda Clínica sobre una consulta previamente CERRADA,
     * conservando intacto el registro primario inmutable.
     */
    public function registrarAdendaClinica(int $idConsulta, string $textoAclaracion, int $idMedicoAutor, int $idUsuarioEjecutor): array
    {
        $textoAclaracion = trim($textoAclaracion);
        if ($idConsulta <= 0 || $textoAclaracion === '') {
            return ['ok' => false, 'mensaje' => 'Debe escribir el texto de aclaración o evolución clínica para la adenda (RN-CLI-03).'];
        }

        $pdo = Database::getConnection();

        try {
            $stmtCons = $pdo->prepare("SELECT estado_consulta FROM consultas_clinicas WHERE id_consulta = :id LIMIT 1");
            $stmtCons->execute([':id' => $idConsulta]);
            $estado = $stmtCons->fetchColumn();

            if ($estado !== 'CERRADA') {
                return [
                    'ok'      => false,
                    'mensaje' => 'RN-CLI-03: Las notas de adenda solo aplican a consultas clínicas cuyo estado sea CERRADA.',
                ];
            }

            $stmtIns = $pdo->prepare(
                "INSERT INTO notas_adenda_clinica (id_consulta, id_medico_autor, texto_aclaracion)
                 VALUES (:id_cons, :id_med, :texto)"
            );
            $stmtIns->execute([
                ':id_cons' => $idConsulta,
                ':id_med'  => $idMedicoAutor,
                ':texto'   => $textoAclaracion,
            ]);

            $idAdenda = (int)$pdo->lastInsertId();

            $stmtAudit = $pdo->prepare(
                "INSERT INTO bitacora_auditoria (tabla_afectada, operacion, id_registro_afectado, id_usuario_ejecutor, motivo, ip_origen)
                 VALUES ('notas_adenda_clinica', 'INSERT', :id_reg, :uid, :motivo, :ip)"
            );
            $stmtAudit->execute([
                ':id_reg' => (string)$idAdenda,
                ':uid'    => $idUsuarioEjecutor,
                ':motivo' => "RN-CLI-03: Registro de adenda clínica #{$idAdenda} anexada a consulta cerrada #{$idConsulta}",
                ':ip'     => Security::getClientIp(),
            ]);

            return [
                'ok'        => true,
                'id_adenda' => $idAdenda,
                'mensaje'   => "Nota de adenda clínica #{$idAdenda} anexada exitosamente sin alterar el registro primario (RN-CLI-03).",
            ];
        } catch (PDOException $e) {
            return ['ok' => false, 'mensaje' => 'Error al registrar adenda clínica: ' . $e->getMessage()];
        }
    }
}
