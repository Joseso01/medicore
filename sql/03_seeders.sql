-- ============================================================================
-- MediCore Systems — Especificación ERS-MCS-DB-2026-V1.0
-- Archivo: sql/03_seeders.sql (Datos Semilla Iniciales)
-- Cumplimiento: RNF-ENT-01, RNF-SEG-01 (Bcrypt cost=12), RF-SEG, RF-PAC, RF-CIT, RF-HIS, RF-FAR
-- ============================================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

USE medicore_db;

-- 1. ROLES INSTITUCIONALES (RBAC)
INSERT INTO roles (id_rol, nombre, descripcion) VALUES
(1, 'Administrador', 'Gestión de usuarios, catálogos maestros, recepción inicial de lotes y auditoría de trazabilidad'),
(2, 'Médico', 'Atención clínica en consulta externa (OPD), visualización de EHR y emisión de recetas electrónicas'),
(3, 'Recepcionista', 'Empadronamiento de pacientes, control de agenda de citas y despacho en ventanilla de farmacia');

-- 2. MATRIZ DE PERMISOS POR MÓDULO (RF-SEG-02)
INSERT INTO permisos_modulo (id_rol, modulo, puede_crear, puede_leer, puede_actualizar, puede_eliminar_logico) VALUES
-- Administrador
(1, 'SEG', 1, 1, 1, 1),
(1, 'PAC', 1, 1, 1, 0),
(1, 'CIT', 1, 1, 1, 1),
(1, 'HIS', 0, 0, 0, 0), -- Prohibido redactar o leer notas confidenciales de EHR (RN-CLI-06)
(1, 'FAR', 1, 1, 1, 0),
-- Médico (Facultativo)
(2, 'SEG', 0, 0, 0, 0),
(2, 'PAC', 0, 1, 0, 0),
(2, 'CIT', 0, 1, 1, 0),
(2, 'HIS', 1, 1, 0, 0), -- Inmutabilidad poscierre (RN-CLI-03)
(2, 'FAR', 0, 1, 0, 0), -- Prohibido despachar recetas o ajustar lotes (RN-CLI-06)
-- Recepcionista y Admisión
(3, 'SEG', 0, 0, 0, 0),
(3, 'PAC', 1, 1, 1, 0),
(3, 'CIT', 1, 1, 1, 1),
(3, 'HIS', 0, 0, 0, 0), -- Estrictamente prohibido leer o escribir notas del EHR (RN-CLI-06)
(3, 'FAR', 1, 1, 0, 0);

-- 3. USUARIOS INICIALES (RNF-SEG-01: Contraseñas con Bcrypt cost=12)
-- Credenciales de acceso para pruebas:
--   admin         / Admin2026*
--   dr.mendoza    / Medico2026*
--   dra.salazar   / Medico2026*
--   recepcion     / Recep2026*
INSERT INTO usuarios (id_usuario, username, email, nombre_completo, password_hash, id_rol, estado) VALUES
(1, 'admin', 'admin@medicore.hospital.org', 'Ing. Carlos Ramírez (Administrador TI)', '$2y$12$p.oK9gNCbTmwAUo2s7fkve1v5FNffDhKoYPkTi4ijltKXAI.yEJv.', 1, 'ACTIVO'),
(2, 'dr.mendoza', 'a.mendoza@medicore.hospital.org', 'Dr. Alejandro Mendoza Vargas', '$2y$12$Xl/JIclLeTmpv1w8FE/uA.VT0N2haofDxpqBpXBxe9OtYf0YQVoSu', 2, 'ACTIVO'),
(3, 'dra.salazar', 'v.salazar@medicore.hospital.org', 'Dra. Valeria Salazar Ortiz', '$2y$12$Xl/JIclLeTmpv1w8FE/uA.VT0N2haofDxpqBpXBxe9OtYf0YQVoSu', 2, 'ACTIVO'),
(4, 'recepcion', 'admision@medicore.hospital.org', 'Lic. Laura Gómez (Admisión y Farmacia)', '$2y$12$4DKdEHUt26xLxdW/dPl2L.c2UkiV9DQybjOCcZ2NBtD16gz.v8PK6', 3, 'ACTIVO');

-- 4. ESPECIALIDADES MÉDICAS Y CONSULTORIOS
INSERT INTO especialidades (id_especialidad, codigo, nombre, descripcion) VALUES
(1, 'MED-GEN', 'Medicina General', 'Atención primaria y evaluación clínica integral ambulatoria'),
(2, 'MED-INT', 'Medicina Interna', 'Diagnóstico y manejo no quirúrgico de patologías crónicas del adulto'),
(3, 'CARDIO', 'Cardiología Clínica', 'Prevención, diagnóstico y tratamiento de enfermedades cardiovasculares');

INSERT INTO consultorios (id_consultorio, codigo_consultorio, nombre, ubicacion, estado) VALUES
(1, 'CONS-101', 'Consultorio 101 - Medicina General', 'Planta Baja, Ala Norte', 'DISPONIBLE'),
(2, 'CONS-102', 'Consultorio 102 - Medicina Interna', 'Planta Baja, Ala Sur', 'DISPONIBLE'),
(3, 'CONS-201', 'Consultorio 201 - Cardiología', 'Primer Piso, Área de Especialidades', 'DISPONIBLE');

-- 5. MÉDICOS FACULTATIVOS Y HORARIOS LABORALES (Lunes a Domingo 07:00 a 22:00 para pruebas operativas continuas)
INSERT INTO medicos (id_medico, id_usuario, id_especialidad, cedula_profesional, documento_identidad, telefono_consultorio, estado) VALUES
(1, 2, 1, 'CED-7845120', 'MENV800514HDFM01', '555-0101', 'ACTIVO'),
(2, 3, 2, 'CED-9632587', 'SAOV850922MDFR02', '555-0102', 'ACTIVO');

INSERT INTO horarios_medicos (id_medico, id_consultorio, dia_semana, hora_inicio_jornada, hora_fin_jornada, activo) VALUES
(1, 1, 1, '07:00:00', '22:00:00', 1),
(1, 1, 2, '07:00:00', '22:00:00', 1),
(1, 1, 3, '07:00:00', '22:00:00', 1),
(1, 1, 4, '07:00:00', '22:00:00', 1),
(1, 1, 5, '07:00:00', '22:00:00', 1),
(1, 1, 6, '07:00:00', '22:00:00', 1),
(1, 1, 7, '07:00:00', '22:00:00', 1),
(2, 2, 1, '07:00:00', '22:00:00', 1),
(2, 2, 2, '07:00:00', '22:00:00', 1),
(2, 2, 3, '07:00:00', '22:00:00', 1),
(2, 2, 4, '07:00:00', '22:00:00', 1),
(2, 2, 5, '07:00:00', '22:00:00', 1),
(2, 2, 6, '07:00:00', '22:00:00', 1),
(2, 2, 7, '07:00:00', '22:00:00', 1);

-- 6. CATÁLOGO DE ALERGIAS FARMACOLÓGICAS Y CIE-10
INSERT INTO catalogo_alergias (id_alergeno, codigo_alergeno, principio_activo_o_familia, descripcion) VALUES
(1, 'ALG-PEN', 'Penicilina / Betalactámicos', 'Antibióticos betalactámicos (Amoxicilina, Ampicilina, Bencilpenicilina)'),
(2, 'ALG-AINE', 'AINES / Ketorolaco / Ibuprofeno', 'Antiinflamatorios no esteroideos e inhibidores de COX'),
(3, 'ALG-SUL', 'Sulfonamidas', 'Antimicrobianos derivados de sulfas (Trimetoprima/Sulfametoxazol)'),
(4, 'ALG-MET', 'Metamizol Sódico (Dipirona)', 'Pirazolonas analgésicas y antipiréticas');

INSERT INTO diagnosticos_cie (id_diagnostico, codigo_cie, descripcion, categoria) VALUES
(1, 'J02.9', 'Faringitis aguda, no especificada', 'Infecciones respiratorias agudas'),
(2, 'I10.X', 'Hipertensión esencial (primaria)', 'Enfermedades hipertensivas'),
(3, 'E11.9', 'Diabetes mellitus tipo 2 sin complicaciones', 'Trastornos metabólicos'),
(4, 'K29.7', 'Gastritis, no especificada', 'Enfermedades del esófago, estómago y duodeno'),
(5, 'M54.5', 'Lumbago no especificado', 'Dorsopatías');

-- 7. PACIENTES EMPADRONADOS (RF-PAC-01 y RF-PAC-02)
INSERT INTO pacientes (id_paciente, mrn, documento_identidad, nombres, apellidos, nombre_completo, fecha_nacimiento, sexo_biologico, grupo_sanguineo, telefono, email, direccion, estado) VALUES
(1, 'MCS-2026-000001', 'HEGJ900315HDFR08', 'Juan Carlos', 'Hernández García', 'Juan Carlos Hernández García', '1990-03-15', 'MASCULINO', 'O+', '555-234-5678', 'jc.hernandez@correo.mx', 'Av. Insurgentes Sur 1450, Ciudad de México', 'ACTIVO'),
(2, 'MCS-2026-000002', 'LOMM881104MDFN05', 'María Fernanda', 'López Martínez', 'María Fernanda López Martínez', '1988-11-04', 'FEMENINO', 'A+', '555-876-5432', 'mf.lopez@correo.mx', 'Calle Reforma 320, Col. Juárez', 'ACTIVO'),
(3, 'MCS-2026-000003', 'ROPR750620HDFP09', 'Roberto', 'Rodríguez Pérez', 'Roberto Rodríguez Pérez', '1975-06-20', 'MASCULINO', 'B+', '555-345-6789', 'r.rodriguez@correo.mx', 'Calz. de Tlalpan 890, Col. Narvarte', 'ACTIVO');

INSERT INTO contactos_emergencia (id_paciente, nombre_contacto, parentesco, telefono_emergencia, es_principal) VALUES
(1, 'Elena García Vda. de Hernández', 'Madre', '555-111-2233', 1),
(2, 'Jorge Martínez', 'Esposo', '555-444-5566', 1),
(3, 'Patricia Pérez', 'Hermana', '555-777-8899', 1);

-- Paciente 1 tiene alergia severa a Penicilina / Betalactámicos (para probar RN-CLI-05)
INSERT INTO paciente_alergias (id_paciente, id_alergeno, severidad, reaccion_descrita, fecha_deteccion, activa) VALUES
(1, 1, 'ANAFILAXIA_SEVERA', 'Broncoespasmo severo y edema glótico documentado tras administración de Amoxicilina', '2023-05-10', 1),
(2, 2, 'MODERADA', 'Urticaria generalizada tras ingesta de Ketorolaco', '2024-02-18', 1);

-- 8. CATÁLOGO DE MEDICAMENTOS Y LOTES DE INVENTARIO (RF-FAR-01, RF-FAR-02, RF-FAR-04)
INSERT INTO medicamentos (id_medicamento, codigo_sku, nombre_generico, nombre_comercial, principio_activo, id_alergeno, forma_farmaceutica, concentracion, stock_minimo, stock_global_actual, activo) VALUES
(1, 'MED-PAR-500', 'Paracetamol', 'Tempra Forte', 'Paracetamol', NULL, 'Tabletas (Caja c/20)', '500 mg', 20, 0, 1),
(2, 'MED-AMO-500', 'Amoxicilina', 'Amoxil', 'Amoxicilina (Penicilina)', 1, 'Cápsulas (Caja c/12)', '500 mg', 15, 0, 1),
(3, 'MED-AZI-500', 'Azitromicina', 'Zithromax', 'Azitromicina', NULL, 'Tabletas (Caja c/3)', '500 mg', 10, 0, 1),
(4, 'MED-LOS-050', 'Losartán Potásico', 'Cozaar', 'Losartán', NULL, 'Comprimidos (Caja c/30)', '50 mg', 25, 0, 1),
(5, 'MED-KET-010', 'Ketorolaco Trometamina', 'Dolac', 'Ketorolaco (AINE)', 2, 'Tabletas (Caja c/10)', '10 mg', 15, 0, 1);

-- Lotes con múltiples fechas de caducidad para demostrar FEFO (RF-FAR-02) y Semáforo de Caducidad (RF-FAR-04)
-- El trigger trg_after_insert_lote_inventario actualizará stock_global_actual y registrará ENTRADA_LOTE en movimientos_kardex
INSERT INTO lotes_inventario (id_medicamento, numero_lote, fecha_fabricacion, fecha_caducidad, cantidad_inicial, cantidad_actual, estado_lote, id_usuario_recepcion) VALUES
-- Paracetamol: dos lotes vigentes (uno próximo a vencer en 45 días -> Ámbar y primero en salir por FEFO; otro en 300 días -> Verde)
(1, 'LOT-PAR-2026A', DATE_SUB(CURDATE(), INTERVAL 180 DAY), DATE_ADD(CURDATE(), INTERVAL 45 DAY), 30, 30, 'ACTIVO', 1),
(1, 'LOT-PAR-2026B', DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_ADD(CURDATE(), INTERVAL 300 DAY), 80, 80, 'ACTIVO', 1),
-- Amoxicilina: lote vigente en Verde
(2, 'LOT-AMO-2026A', DATE_SUB(CURDATE(), INTERVAL 90 DAY), DATE_ADD(CURDATE(), INTERVAL 240 DAY), 50, 50, 'ACTIVO', 1),
-- Azitromicina: stock crítico (8 unidades <= stock_minimo de 10) para probar alerta de stock crítico (RF-FAR-04)
(3, 'LOT-AZI-2026A', DATE_SUB(CURDATE(), INTERVAL 120 DAY), DATE_ADD(CURDATE(), INTERVAL 60 DAY), 8, 8, 'ACTIVO', 1),
-- Losartán: lote vigente en Verde
(4, 'LOT-LOS-2026A', DATE_SUB(CURDATE(), INTERVAL 45 DAY), DATE_ADD(CURDATE(), INTERVAL 365 DAY), 100, 100, 'ACTIVO', 1),
-- Ketorolaco: lote vigente
(5, 'LOT-KET-2026A', DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_ADD(CURDATE(), INTERVAL 180 DAY), 40, 40, 'ACTIVO', 1);

-- 9. CITAS MÉDICAS DEL DÍA EN CURSO (RF-CIT-01, RF-CIT-04)
INSERT INTO citas_medicas (id_paciente, id_medico, id_consultorio, id_usuario_registro, fecha_cita, hora_inicio, hora_fin, motivo_consulta, estado) VALUES
(1, 1, 1, 4, CURDATE(), '09:00:00', '09:30:00', 'Dolor faríngeo agudo y fiebre de 38.5 °C de 24 horas de evolución', 'CONFIRMADA'),
(2, 1, 1, 4, CURDATE(), '10:00:00', '10:30:00', 'Cefalea tensional y control de signos vitales', 'PROGRAMADA'),
(3, 2, 2, 4, CURDATE(), '11:00:00', '11:30:00', 'Seguimiento ambulatorio de hipertensión arterial sistémica', 'CONFIRMADA');
