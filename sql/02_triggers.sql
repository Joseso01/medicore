-- ============================================================================
-- MediCore Systems — Especificación ERS-MCS-DB-2026-V1.0
-- Archivo: sql/02_triggers.sql (Triggers de Integridad, Reglas Clínicas y Auditoría)
-- Cumplimiento: RBD-INT-03, RBD-INT-04, RBD-INT-05, RN-CLI-01 a RN-CLI-06
-- ============================================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

USE medicore_db;

DELIMITER $$

-- ============================================================================
-- 1. RN-CLI-01 & RN-CLI-06: Exclusión Mutua de Horarios de Citas y No Autocita
-- ============================================================================
CREATE TRIGGER trg_before_insert_citas
BEFORE INSERT ON citas_medicas
FOR EACH ROW
BEGIN
    DECLARE v_doc_medico VARCHAR(30);
    DECLARE v_doc_paciente VARCHAR(30);
    DECLARE v_rol_registro INT;
    DECLARE v_conflicto INT DEFAULT 0;

    -- RN-CLI-06: Validar que médico y paciente no sean la misma persona
    SELECT documento_identidad INTO v_doc_medico
    FROM medicos WHERE id_medico = NEW.id_medico LIMIT 1;

    SELECT documento_identidad INTO v_doc_paciente
    FROM pacientes WHERE id_paciente = NEW.id_paciente LIMIT 1;

    IF v_doc_medico = v_doc_paciente THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-06: Violación de segregación clínica. Un médico no puede agendarse una cita consigo mismo';
    END IF;

    -- RN-CLI-01: Validar intersección de intervalos abiertos-cerrados [hora_inicio, hora_fin)
    IF NEW.estado NOT IN ('CANCELADA', 'NO_ASISTIO') THEN
        SELECT COUNT(*) INTO v_conflicto
        FROM citas_medicas
        WHERE fecha_cita = NEW.fecha_cita
          AND estado NOT IN ('CANCELADA', 'NO_ASISTIO')
          AND (hora_inicio < NEW.hora_fin AND hora_fin > NEW.hora_inicio)
          AND (
              id_medico = NEW.id_medico
              OR id_paciente = NEW.id_paciente
              OR id_consultorio = NEW.id_consultorio
          );

        IF v_conflicto > 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'RN-CLI-01: Conflicto de empalme horario detectado en agenda médica, paciente o consultorio';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_before_update_citas
BEFORE UPDATE ON citas_medicas
FOR EACH ROW
BEGIN
    DECLARE v_conflicto INT DEFAULT 0;

    -- RF-CIT-02: Estados terminales ATENDIDA y CANCELADA no admiten cambios
    IF OLD.estado IN ('ATENDIDA', 'CANCELADA') THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RF-CIT-02: Una cita en estado ATENDIDA o CANCELADA es terminal y no admite modificaciones';
    END IF;

    -- RF-CIT-03: Si pasa a CANCELADA, exigir motivo_cancelacion obligatorio
    IF NEW.estado = 'CANCELADA' AND (NEW.motivo_cancelacion IS NULL OR TRIM(NEW.motivo_cancelacion) = '') THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RF-CIT-03: Es obligatorio especificar el motivo de cancelación de la cita';
    END IF;

    -- RN-CLI-01: Validar no empalme al reprogramar
    IF NEW.estado NOT IN ('CANCELADA', 'NO_ASISTIO') THEN
        SELECT COUNT(*) INTO v_conflicto
        FROM citas_medicas
        WHERE id_cita <> OLD.id_cita
          AND fecha_cita = NEW.fecha_cita
          AND estado NOT IN ('CANCELADA', 'NO_ASISTIO')
          AND (hora_inicio < NEW.hora_fin AND hora_fin > NEW.hora_inicio)
          AND (
              id_medico = NEW.id_medico
              OR id_paciente = NEW.id_paciente
              OR id_consultorio = NEW.id_consultorio
          );

        IF v_conflicto > 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'RN-CLI-01: Conflicto de empalme horario detectado en agenda médica, paciente o consultorio';
        END IF;
    END IF;
END$$

-- ============================================================================
-- 2. RN-CLI-03 & RN-CLI-06: Apertura e Inmutabilidad de Consultas Clínicas
-- ============================================================================
CREATE TRIGGER trg_before_insert_consultas
BEFORE INSERT ON consultas_clinicas
FOR EACH ROW
BEGIN
    DECLARE v_doc_medico VARCHAR(30);
    DECLARE v_doc_paciente VARCHAR(30);

    SELECT documento_identidad INTO v_doc_medico
    FROM medicos WHERE id_medico = NEW.id_medico LIMIT 1;

    SELECT documento_identidad INTO v_doc_paciente
    FROM pacientes WHERE id_paciente = NEW.id_paciente LIMIT 1;

    IF v_doc_medico = v_doc_paciente THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-06: Violación de ética médica. Un médico no puede abrir una consulta clínica para sí mismo';
    END IF;
END$$

CREATE TRIGGER trg_immutable_update_consultas
BEFORE UPDATE ON consultas_clinicas
FOR EACH ROW
BEGIN
    IF OLD.estado_consulta = 'CERRADA' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-03: Violación de inmutabilidad del Expediente Clínico Electrónico';
    END IF;
END$$

CREATE TRIGGER trg_immutable_delete_consultas
BEFORE DELETE ON consultas_clinicas
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'RN-CLI-03: Violación de inmutabilidad del Expediente Clínico Electrónico';
END$$

-- ============================================================================
-- 3. RN-CLI-03: Inmutabilidad de Signos Vitales tras Cierre de Consulta
-- ============================================================================
CREATE TRIGGER trg_immutable_update_signos
BEFORE UPDATE ON signos_vitales
FOR EACH ROW
BEGIN
    DECLARE v_estado_consulta VARCHAR(20);

    SELECT estado_consulta INTO v_estado_consulta
    FROM consultas_clinicas
    WHERE id_consulta = OLD.id_consulta
    LIMIT 1;

    IF v_estado_consulta = 'CERRADA' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-03: Violación de inmutabilidad del Expediente Clínico Electrónico';
    END IF;
END$$

CREATE TRIGGER trg_immutable_delete_signos
BEFORE DELETE ON signos_vitales
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'RN-CLI-03: Violación de inmutabilidad del Expediente Clínico Electrónico';
END$$

-- ============================================================================
-- 4. RN-CLI-02 & RN-CLI-03: Validación Previa e Inmutabilidad de Recetas Médicas
-- ============================================================================
CREATE TRIGGER trg_before_insert_recetas
BEFORE INSERT ON recetas_medicas
FOR EACH ROW
BEGIN
    DECLARE v_id_medico_consulta INT;
    DECLARE v_id_diagnostico INT;
    DECLARE v_estado_consulta VARCHAR(20);
    DECLARE v_tiene_signos INT DEFAULT 0;

    SELECT id_medico, id_diagnostico_principal, estado_consulta
    INTO v_id_medico_consulta, v_id_diagnostico, v_estado_consulta
    FROM consultas_clinicas
    WHERE id_consulta = NEW.id_consulta
    LIMIT 1;

    IF v_id_medico_consulta IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-02: No existe una consulta clínica activa asociada a la receta';
    END IF;

    IF v_estado_consulta = 'CERRADA' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-03: Violación de inmutabilidad del Expediente Clínico Electrónico';
    END IF;

    IF v_id_medico_consulta <> NEW.id_medico_emisor THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-02: El médico emisor de la receta no coincide con el facultativo titular de la consulta';
    END IF;

    SELECT COUNT(*) INTO v_tiene_signos
    FROM signos_vitales
    WHERE id_consulta = NEW.id_consulta;

    IF v_tiene_signos = 0 OR v_id_diagnostico IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-02: La consulta debe contar con signos vitales y diagnóstico registrado antes de emitir receta';
    END IF;
END$$

CREATE TRIGGER trg_immutable_update_recetas
BEFORE UPDATE ON recetas_medicas
FOR EACH ROW
BEGIN
    DECLARE v_estado_consulta VARCHAR(20);

    SELECT estado_consulta INTO v_estado_consulta
    FROM consultas_clinicas
    WHERE id_consulta = OLD.id_consulta
    LIMIT 1;

    -- Si la consulta está cerrada, solo se permite la transición del estado_receta por dispensación farmacéutica
    IF v_estado_consulta = 'CERRADA' THEN
        IF OLD.folio_receta <> NEW.folio_receta
           OR OLD.id_consulta <> NEW.id_consulta
           OR OLD.id_medico_emisor <> NEW.id_medico_emisor
           OR IFNULL(OLD.observaciones_generales, '') <> IFNULL(NEW.observaciones_generales, '') THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'RN-CLI-03: Violación de inmutabilidad del Expediente Clínico Electrónico';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_immutable_delete_recetas
BEFORE DELETE ON recetas_medicas
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'RN-CLI-03: Violación de inmutabilidad del Expediente Clínico Electrónico';
END$$

-- ============================================================================
-- 5. RN-CLI-05 & RN-CLI-03: Contraindicación por Alergia Severa e Inmutabilidad
-- ============================================================================
CREATE TRIGGER trg_before_insert_receta_detalle
BEFORE INSERT ON receta_detalle
FOR EACH ROW
BEGIN
    DECLARE v_estado_consulta VARCHAR(20);
    DECLARE v_alergia_detectada INT DEFAULT 0;

    SELECT c.estado_consulta INTO v_estado_consulta
    FROM recetas_medicas r
    INNER JOIN consultas_clinicas c ON c.id_consulta = r.id_consulta
    WHERE r.id_receta = NEW.id_receta
    LIMIT 1;

    IF v_estado_consulta = 'CERRADA' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-03: Violación de inmutabilidad del Expediente Clínico Electrónico';
    END IF;

    -- RN-CLI-05: Cruce relacional (INNER JOIN) entre medicamento, consulta y alergias del paciente
    SELECT COUNT(*) INTO v_alergia_detectada
    FROM recetas_medicas r
    INNER JOIN consultas_clinicas c ON c.id_consulta = r.id_consulta
    INNER JOIN medicamentos m ON m.id_medicamento = NEW.id_medicamento
    INNER JOIN paciente_alergias pa ON pa.id_paciente = c.id_paciente
    INNER JOIN catalogo_alergias ca ON ca.id_alergeno = pa.id_alergeno
    WHERE r.id_receta = NEW.id_receta
      AND pa.activa = 1
      AND pa.severidad IN ('MODERADA', 'ANAFILAXIA_SEVERA')
      AND (
          m.id_alergeno = pa.id_alergeno
          OR LOWER(m.principio_activo) LIKE CONCAT('%', LOWER(ca.principio_activo_o_familia), '%')
      );

    IF v_alergia_detectada > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-05: Alerta de seguridad clínica. El paciente presenta alergia registrada al principio activo prescrito';
    END IF;
END$$

CREATE TRIGGER trg_immutable_update_receta_detalle
BEFORE UPDATE ON receta_detalle
FOR EACH ROW
BEGIN
    DECLARE v_estado_consulta VARCHAR(20);

    SELECT c.estado_consulta INTO v_estado_consulta
    FROM recetas_medicas r
    INNER JOIN consultas_clinicas c ON c.id_consulta = r.id_consulta
    WHERE r.id_receta = OLD.id_receta
    LIMIT 1;

    -- Una vez cerrada la consulta, solo se permite actualizar cantidad_surtida durante dispensación
    IF v_estado_consulta = 'CERRADA' THEN
        IF OLD.id_receta <> NEW.id_receta
           OR OLD.id_medicamento <> NEW.id_medicamento
           OR OLD.dosis <> NEW.dosis
           OR OLD.via_administracion <> NEW.via_administracion
           OR OLD.frecuencia_horaria <> NEW.frecuencia_horaria
           OR OLD.duracion_tratamiento <> NEW.duracion_tratamiento
           OR OLD.cantidad_prescrita <> NEW.cantidad_prescrita THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'RN-CLI-03: Violación de inmutabilidad del Expediente Clínico Electrónico';
        END IF;
    END IF;
END$$

CREATE TRIGGER trg_immutable_delete_receta_detalle
BEFORE DELETE ON receta_detalle
FOR EACH ROW
BEGIN
    DECLARE v_estado_consulta VARCHAR(20);

    SELECT c.estado_consulta INTO v_estado_consulta
    FROM recetas_medicas r
    INNER JOIN consultas_clinicas c ON c.id_consulta = r.id_consulta
    WHERE r.id_receta = OLD.id_receta
    LIMIT 1;

    IF v_estado_consulta = 'CERRADA' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-03: Violación de inmutabilidad del Expediente Clínico Electrónico';
    END IF;
END$$

-- ============================================================================
-- 6. RF-FAR-01: Recepción de Lotes -> Stock Global y Registro en Kárdex
-- ============================================================================
CREATE TRIGGER trg_before_insert_lote_inventario
BEFORE INSERT ON lotes_inventario
FOR EACH ROW
BEGIN
    DECLARE v_id_rol INT;

    -- RN-CLI-06: Ningún Médico (id_rol = 2) puede registrar o ajustar lotes de inventario
    SELECT id_rol INTO v_id_rol
    FROM usuarios WHERE id_usuario = NEW.id_usuario_recepcion LIMIT 1;

    IF v_id_rol = 2 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-06: Violación de segregación de funciones. Un Médico no puede realizar ingresos o ajustes de lotes';
    END IF;

    IF NEW.fecha_caducidad <= CURDATE() THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RF-FAR-01: No se permite recepcionar un lote con fecha de caducidad vencida';
    END IF;
END$$

CREATE TRIGGER trg_after_insert_lote_inventario
AFTER INSERT ON lotes_inventario
FOR EACH ROW
BEGIN
    UPDATE medicamentos
    SET stock_global_actual = stock_global_actual + NEW.cantidad_actual
    WHERE id_medicamento = NEW.id_medicamento;

    INSERT INTO movimientos_kardex (
        id_lote,
        id_medicamento,
        tipo_movimiento,
        cantidad_previa,
        cantidad_movimiento,
        saldo_resultante,
        referencia_receta,
        id_usuario_ejecutor
    ) VALUES (
        NEW.id_lote,
        NEW.id_medicamento,
        'ENTRADA_LOTE',
        0,
        NEW.cantidad_inicial,
        NEW.cantidad_actual,
        CONCAT('LOTE:', NEW.numero_lote),
        NEW.id_usuario_recepcion
    );
END$$

-- ============================================================================
-- 7. RN-CLI-06, RBD-INT-03, RBD-INT-04 & RN-CLI-04: Dispensación y Kárdex
-- ============================================================================
CREATE TRIGGER trg_before_insert_dispensacion_cabecera
BEFORE INSERT ON dispensaciones
FOR EACH ROW
BEGIN
    DECLARE v_id_rol INT;

    SELECT id_rol INTO v_id_rol
    FROM usuarios WHERE id_usuario = NEW.id_usuario_despacha LIMIT 1;

    IF v_id_rol = 2 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-06: Violación de segregación de funciones. Un Médico no está autorizado para despachar recetas en farmacia';
    END IF;
END$$

CREATE TRIGGER trg_before_insert_dispensacion
BEFORE INSERT ON dispensacion_detalle
FOR EACH ROW
BEGIN
    DECLARE v_fecha_caducidad DATE;
    DECLARE v_cantidad_actual INT;
    DECLARE v_estado_lote VARCHAR(20);
    DECLARE v_id_med_lote INT;
    DECLARE v_id_med_receta INT;
    DECLARE v_cant_prescrita INT;
    DECLARE v_cant_surtida INT;

    SELECT fecha_caducidad, cantidad_actual, estado_lote, id_medicamento
    INTO v_fecha_caducidad, v_cantidad_actual, v_estado_lote, v_id_med_lote
    FROM lotes_inventario
    WHERE id_lote = NEW.id_lote
    LIMIT 1;

    -- RBD-INT-03 (1): Validar caducidad del lote
    IF v_fecha_caducidad <= CURDATE() OR v_estado_lote = 'CADUCADO' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ERR_FAR_LOTE_CADUCADO: No se permite dispensar un lote vencido';
    END IF;

    -- RBD-INT-03 (2): Validar suficiencia de stock en el lote
    IF NEW.cantidad_dispensada > v_cantidad_actual THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'ERR_FAR_STOCK_INSUFICIENTE: Existencias insuficientes en el lote seleccionado';
    END IF;

    -- RN-CLI-04: Validar correspondencia de medicamento y no exceder cantidad_prescrita
    SELECT id_medicamento, cantidad_prescrita, cantidad_surtida
    INTO v_id_med_receta, v_cant_prescrita, v_cant_surtida
    FROM receta_detalle
    WHERE id_receta_detalle = NEW.id_receta_detalle
    LIMIT 1;

    IF v_id_med_lote <> v_id_med_receta THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-04: El lote seleccionado no corresponde al medicamento prescrito en el renglón de receta';
    END IF;

    IF (v_cant_surtida + NEW.cantidad_dispensada) > v_cant_prescrita THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'RN-CLI-04: La cantidad acumulada dispensada supera la cantidad prescrita por el facultativo';
    END IF;
END$$

CREATE TRIGGER trg_after_insert_dispensacion
AFTER INSERT ON dispensacion_detalle
FOR EACH ROW
BEGIN
    DECLARE v_id_medicamento INT;
    DECLARE v_cant_previa INT;
    DECLARE v_saldo_resultante INT;
    DECLARE v_id_receta INT;
    DECLARE v_folio_receta VARCHAR(25);
    DECLARE v_id_usuario INT;
    DECLARE v_pendientes INT DEFAULT 0;

    SELECT id_medicamento, cantidad_actual
    INTO v_id_medicamento, v_cant_previa
    FROM lotes_inventario
    WHERE id_lote = NEW.id_lote
    LIMIT 1;

    SET v_saldo_resultante = v_cant_previa - NEW.cantidad_dispensada;

    -- RBD-INT-04 (1): Descontar existencias del lote y cambiar estado a AGOTADO si llega a 0
    UPDATE lotes_inventario
    SET cantidad_actual = v_saldo_resultante,
        estado_lote = CASE WHEN v_saldo_resultante = 0 THEN 'AGOTADO' ELSE estado_lote END
    WHERE id_lote = NEW.id_lote;

    -- Actualizar stock global del medicamento
    UPDATE medicamentos
    SET stock_global_actual = stock_global_actual - NEW.cantidad_dispensada
    WHERE id_medicamento = v_id_medicamento;

    -- Actualizar cantidad_surtida en receta_detalle
    UPDATE receta_detalle
    SET cantidad_surtida = cantidad_surtida + NEW.cantidad_dispensada
    WHERE id_receta_detalle = NEW.id_receta_detalle;

    -- Obtener datos de receta y usuario que despacha
    SELECT d.id_receta, r.folio_receta, d.id_usuario_despacha
    INTO v_id_receta, v_folio_receta, v_id_usuario
    FROM dispensaciones d
    INNER JOIN recetas_medicas r ON r.id_receta = d.id_receta
    WHERE d.id_dispensacion = NEW.id_dispensacion
    LIMIT 1;

    -- RBD-INT-04 (2): Insertar movimiento inmutable en movimientos_kardex
    INSERT INTO movimientos_kardex (
        id_lote,
        id_medicamento,
        tipo_movimiento,
        cantidad_previa,
        cantidad_movimiento,
        saldo_resultante,
        referencia_receta,
        id_usuario_ejecutor
    ) VALUES (
        NEW.id_lote,
        v_id_medicamento,
        'SALIDA_DISPENSACION',
        v_cant_previa,
        NEW.cantidad_dispensada,
        v_saldo_resultante,
        v_folio_receta,
        v_id_usuario
    );

    -- RF-FAR-03: Actualizar estado de la receta a SURTIDA_COMPLETA o SURTIDA_PARCIAL
    SELECT COUNT(*) INTO v_pendientes
    FROM receta_detalle
    WHERE id_receta = v_id_receta
      AND cantidad_surtida < cantidad_prescrita;

    UPDATE recetas_medicas
    SET estado_receta = CASE WHEN v_pendientes = 0 THEN 'SURTIDA_COMPLETA' ELSE 'SURTIDA_PARCIAL' END
    WHERE id_receta = v_id_receta;
END$$

-- ============================================================================
-- 8. RBD-INT-05: Inmutabilidad de Kárdex y Auditoría Forense (JSON_OBJECT)
-- ============================================================================
CREATE TRIGGER trg_immutable_update_kardex
BEFORE UPDATE ON movimientos_kardex
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'RBD-INT-05: Los movimientos de kárdex son inmutables y no pueden ser modificados';
END$$

CREATE TRIGGER trg_immutable_delete_kardex
BEFORE DELETE ON movimientos_kardex
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'RBD-INT-05: Los movimientos de kárdex son inmutables y no pueden ser eliminados';
END$$

CREATE TRIGGER trg_audit_update_usuarios
AFTER UPDATE ON usuarios
FOR EACH ROW
BEGIN
    INSERT INTO bitacora_auditoria (
        tabla_afectada,
        operacion,
        id_registro_afectado,
        valores_anteriores,
        valores_nuevos
    ) VALUES (
        'usuarios',
        'UPDATE',
        CAST(OLD.id_usuario AS CHAR),
        JSON_OBJECT(
            'username', OLD.username,
            'email', OLD.email,
            'id_rol', OLD.id_rol,
            'estado', OLD.estado,
            'intentos_fallidos', OLD.intentos_fallidos,
            'bloqueado_hasta', OLD.bloqueado_hasta
        ),
        JSON_OBJECT(
            'username', NEW.username,
            'email', NEW.email,
            'id_rol', NEW.id_rol,
            'estado', NEW.estado,
            'intentos_fallidos', NEW.intentos_fallidos,
            'bloqueado_hasta', NEW.bloqueado_hasta
        )
    );
END$$

CREATE TRIGGER trg_audit_update_pacientes
AFTER UPDATE ON pacientes
FOR EACH ROW
BEGIN
    INSERT INTO bitacora_auditoria (
        tabla_afectada,
        operacion,
        id_registro_afectado,
        valores_anteriores,
        valores_nuevos
    ) VALUES (
        'pacientes',
        'UPDATE',
        CAST(OLD.id_paciente AS CHAR),
        JSON_OBJECT(
            'mrn', OLD.mrn,
            'documento_identidad', OLD.documento_identidad,
            'nombre_completo', OLD.nombre_completo,
            'fecha_nacimiento', OLD.fecha_nacimiento,
            'sexo_biologico', OLD.sexo_biologico,
            'grupo_sanguineo', OLD.grupo_sanguineo,
            'telefono', OLD.telefono,
            'direccion', OLD.direccion,
            'estado', OLD.estado
        ),
        JSON_OBJECT(
            'mrn', NEW.mrn,
            'documento_identidad', NEW.documento_identidad,
            'nombre_completo', NEW.nombre_completo,
            'fecha_nacimiento', NEW.fecha_nacimiento,
            'sexo_biologico', NEW.sexo_biologico,
            'grupo_sanguineo', NEW.grupo_sanguineo,
            'telefono', NEW.telefono,
            'direccion', NEW.direccion,
            'estado', NEW.estado
        )
    );
END$$

CREATE TRIGGER trg_audit_update_lotes
AFTER UPDATE ON lotes_inventario
FOR EACH ROW
BEGIN
    INSERT INTO bitacora_auditoria (
        tabla_afectada,
        operacion,
        id_registro_afectado,
        valores_anteriores,
        valores_nuevos
    ) VALUES (
        'lotes_inventario',
        'UPDATE',
        CAST(OLD.id_lote AS CHAR),
        JSON_OBJECT(
            'numero_lote', OLD.numero_lote,
            'cantidad_actual', OLD.cantidad_actual,
            'estado_lote', OLD.estado_lote,
            'fecha_caducidad', OLD.fecha_caducidad
        ),
        JSON_OBJECT(
            'numero_lote', NEW.numero_lote,
            'cantidad_actual', NEW.cantidad_actual,
            'estado_lote', NEW.estado_lote,
            'fecha_caducidad', NEW.fecha_caducidad
        )
    );
END$$

DELIMITER ;
