-- ============================================================================
-- MediCore Systems — Especificación ERS-MCS-DB-2026-V1.0
-- Archivo: sql/01_init.sql (Esquema Físico DDL en 3FN/BCNF, Restricciones e Índices)
-- Motor: MySQL 8.0 (ENGINE=InnoDB, CHARSET=utf8mb4, COLLATE=utf8mb4_unicode_ci)
-- Cumplimiento: RBD-INT-01, RBD-INT-06
-- ============================================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS medicore_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE medicore_db;

-- ============================================================================
-- MÓDULO 1: SEGURIDAD Y AUDITORÍA (SEG)
-- Entidades: roles, permisos_modulo, usuarios, sesiones_activas, bitacora_auditoria
-- ============================================================================

CREATE TABLE IF NOT EXISTS roles (
    id_rol INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    descripcion VARCHAR(200) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permisos_modulo (
    id_permiso INT AUTO_INCREMENT PRIMARY KEY,
    id_rol INT NOT NULL,
    modulo ENUM('SEG', 'PAC', 'CIT', 'HIS', 'FAR') NOT NULL,
    puede_crear TINYINT(1) NOT NULL DEFAULT 0,
    puede_leer TINYINT(1) NOT NULL DEFAULT 0,
    puede_actualizar TINYINT(1) NOT NULL DEFAULT 0,
    puede_eliminar_logico TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uk_rol_modulo (id_rol, modulo),
    CONSTRAINT fk_permisos_rol FOREIGN KEY (id_rol)
        REFERENCES roles(id_rol) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(120) NOT NULL UNIQUE,
    nombre_completo VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    id_rol INT NOT NULL,
    estado ENUM('ACTIVO', 'SUSPENDIDO', 'BAJA_LOGICA') NOT NULL DEFAULT 'ACTIVO',
    intentos_fallidos INT NOT NULL DEFAULT 0,
    ultimo_intento_fallido DATETIME NULL,
    bloqueado_hasta DATETIME NULL,
    ultimo_acceso DATETIME NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_usuarios_intentos CHECK (intentos_fallidos >= 0),
    CONSTRAINT fk_usuarios_roles FOREIGN KEY (id_rol)
        REFERENCES roles(id_rol) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sesiones_activas (
    id_sesion VARCHAR(128) PRIMARY KEY,
    id_usuario INT NOT NULL,
    ip_origen VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    csrf_token VARCHAR(64) NOT NULL,
    iniciada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultima_actividad DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_sesiones_usuario_activa (id_usuario, activa),
    CONSTRAINT fk_sesiones_usuario FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bitacora_auditoria (
    id_evento BIGINT AUTO_INCREMENT PRIMARY KEY,
    tabla_afectada VARCHAR(64) NOT NULL,
    operacion ENUM('INSERT', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'ACCESO_DENEGADO', 'BLOQUEO_SEGURIDAD') NOT NULL,
    id_registro_afectado VARCHAR(50) NULL,
    id_usuario_ejecutor INT NULL,
    motivo VARCHAR(255) NULL,
    valores_anteriores JSON NULL,
    valores_nuevos JSON NULL,
    ip_origen VARCHAR(45) NULL,
    fecha_evento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bitacora_tabla_fecha (tabla_afectada, fecha_evento),
    CONSTRAINT fk_bitacora_usuario FOREIGN KEY (id_usuario_ejecutor)
        REFERENCES usuarios(id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MÓDULO 2: GESTIÓN DE PACIENTES (PAC)
-- Entidades: pacientes, contactos_emergencia, catalogo_alergias, paciente_alergias
-- ============================================================================

CREATE TABLE IF NOT EXISTS pacientes (
    id_paciente INT AUTO_INCREMENT PRIMARY KEY,
    mrn VARCHAR(20) NOT NULL UNIQUE COMMENT 'Formato MCS-YYYY-XXXXXX (RF-PAC-01)',
    documento_identidad VARCHAR(30) NOT NULL UNIQUE COMMENT 'CURP/DNI/Pasaporte único',
    nombres VARCHAR(80) NOT NULL,
    apellidos VARCHAR(80) NOT NULL,
    nombre_completo VARCHAR(165) NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    sexo_biologico ENUM('MASCULINO', 'FEMENINO') NOT NULL,
    grupo_sanguineo ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NOT NULL,
    telefono VARCHAR(25) NULL,
    email VARCHAR(120) NULL,
    direccion VARCHAR(255) NULL,
    estado ENUM('ACTIVO', 'INACTIVO') NOT NULL DEFAULT 'ACTIVO',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pacientes_apellidos_nombres (apellidos, nombres),
    FULLTEXT INDEX idx_ft_pacientes_nombre (nombres, apellidos, nombre_completo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contactos_emergencia (
    id_contacto INT AUTO_INCREMENT PRIMARY KEY,
    id_paciente INT NOT NULL,
    nombre_contacto VARCHAR(150) NOT NULL,
    parentesco VARCHAR(50) NOT NULL,
    telefono_emergencia VARCHAR(25) NOT NULL,
    es_principal TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_contacto_paciente (id_paciente),
    CONSTRAINT fk_contacto_paciente FOREIGN KEY (id_paciente)
        REFERENCES pacientes(id_paciente) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogo_alergias (
    id_alergeno INT AUTO_INCREMENT PRIMARY KEY,
    codigo_alergeno VARCHAR(30) NOT NULL UNIQUE,
    principio_activo_o_familia VARCHAR(120) NOT NULL UNIQUE,
    descripcion VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS paciente_alergias (
    id_paciente_alergia INT AUTO_INCREMENT PRIMARY KEY,
    id_paciente INT NOT NULL,
    id_alergeno INT NOT NULL,
    severidad ENUM('LEVE', 'MODERADA', 'ANAFILAXIA_SEVERA') NOT NULL,
    reaccion_descrita VARCHAR(255) NULL,
    fecha_deteccion DATE NOT NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uk_paciente_alergeno (id_paciente, id_alergeno),
    CONSTRAINT fk_pacalergia_paciente FOREIGN KEY (id_paciente)
        REFERENCES pacientes(id_paciente) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_pacalergia_alergeno FOREIGN KEY (id_alergeno)
        REFERENCES catalogo_alergias(id_alergeno) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MÓDULO 3: CONTROL DE CITAS MÉDICAS (CIT)
-- Entidades: especialidades, consultorios, medicos, horarios_medicos, citas_medicas
-- ============================================================================

CREATE TABLE IF NOT EXISTS especialidades (
    id_especialidad INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(20) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    descripcion VARCHAR(255) NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultorios (
    id_consultorio INT AUTO_INCREMENT PRIMARY KEY,
    codigo_consultorio VARCHAR(20) NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    ubicacion VARCHAR(150) NOT NULL,
    estado ENUM('DISPONIBLE', 'MANTENIMIENTO', 'INACTIVO') NOT NULL DEFAULT 'DISPONIBLE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS medicos (
    id_medico INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL UNIQUE,
    id_especialidad INT NOT NULL,
    cedula_profesional VARCHAR(30) NOT NULL UNIQUE,
    documento_identidad VARCHAR(30) NOT NULL UNIQUE COMMENT 'Para validación RN-CLI-06 contra pacientes',
    telefono_consultorio VARCHAR(25) NULL,
    estado ENUM('ACTIVO', 'INACTIVO') NOT NULL DEFAULT 'ACTIVO',
    CONSTRAINT fk_medicos_usuario FOREIGN KEY (id_usuario)
        REFERENCES usuarios(id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_medicos_especialidad FOREIGN KEY (id_especialidad)
        REFERENCES especialidades(id_especialidad) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS horarios_medicos (
    id_horario INT AUTO_INCREMENT PRIMARY KEY,
    id_medico INT NOT NULL,
    id_consultorio INT NOT NULL,
    dia_semana TINYINT NOT NULL COMMENT '1=Lunes, 2=Martes, 3=Miércoles, 4=Jueves, 5=Viernes, 6=Sábado, 7=Domingo',
    hora_inicio_jornada TIME NOT NULL,
    hora_fin_jornada TIME NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT chk_horario_dia CHECK (dia_semana BETWEEN 1 AND 7),
    CONSTRAINT chk_horario_jornada CHECK (hora_fin_jornada > hora_inicio_jornada),
    INDEX idx_horario_medico_dia (id_medico, dia_semana, activo),
    CONSTRAINT fk_horarios_medico FOREIGN KEY (id_medico)
        REFERENCES medicos(id_medico) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_horarios_consultorio FOREIGN KEY (id_consultorio)
        REFERENCES consultorios(id_consultorio) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS citas_medicas (
    id_cita INT AUTO_INCREMENT PRIMARY KEY,
    id_paciente INT NOT NULL,
    id_medico INT NOT NULL,
    id_consultorio INT NOT NULL,
    id_usuario_registro INT NOT NULL,
    fecha_cita DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    motivo_consulta VARCHAR(255) NOT NULL,
    estado ENUM('PROGRAMADA', 'CONFIRMADA', 'EN_CURSO', 'ATENDIDA', 'CANCELADA', 'NO_ASISTIO') NOT NULL DEFAULT 'PROGRAMADA',
    motivo_cancelacion VARCHAR(255) NULL,
    id_usuario_cancelacion INT NULL,
    fecha_cancelacion DATETIME NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_citas_horas CHECK (hora_fin > hora_inicio),
    INDEX idx_citas_medico_fecha (id_medico, fecha_cita, estado),
    INDEX idx_citas_paciente_fecha (id_paciente, fecha_cita),
    INDEX idx_citas_consultorio_fecha (id_consultorio, fecha_cita, estado),
    CONSTRAINT fk_citas_paciente FOREIGN KEY (id_paciente)
        REFERENCES pacientes(id_paciente) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_citas_medico FOREIGN KEY (id_medico)
        REFERENCES medicos(id_medico) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_citas_consultorio FOREIGN KEY (id_consultorio)
        REFERENCES consultorios(id_consultorio) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_citas_usuario_reg FOREIGN KEY (id_usuario_registro)
        REFERENCES usuarios(id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_citas_usuario_canc FOREIGN KEY (id_usuario_cancelacion)
        REFERENCES usuarios(id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MÓDULO 4: HISTORIAL CLÍNICO ELECTRÓNICO (HIS)
-- Entidades: diagnosticos_cie, consultas_clinicas, signos_vitales, notas_adenda_clinica
-- ============================================================================

CREATE TABLE IF NOT EXISTS diagnosticos_cie (
    id_diagnostico INT AUTO_INCREMENT PRIMARY KEY,
    codigo_cie VARCHAR(10) NOT NULL UNIQUE,
    descripcion VARCHAR(255) NOT NULL,
    categoria VARCHAR(100) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultas_clinicas (
    id_consulta INT AUTO_INCREMENT PRIMARY KEY,
    id_cita INT NOT NULL UNIQUE COMMENT 'RF-HIS-01: UNIQUE KEY (id_cita)',
    id_paciente INT NOT NULL,
    id_medico INT NOT NULL,
    id_diagnostico_principal INT NULL,
    nota_subjetiva TEXT NULL,
    nota_objetiva TEXT NULL,
    nota_analisis TEXT NULL,
    nota_plan TEXT NULL,
    estado_consulta ENUM('ABIERTA', 'CERRADA') NOT NULL DEFAULT 'ABIERTA',
    firma_digital_hash VARCHAR(64) NULL,
    fecha_hora_inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_hora_cierre DATETIME NULL,
    INDEX idx_consultas_paciente_fecha (id_paciente, fecha_hora_inicio),
    CONSTRAINT fk_consultas_cita FOREIGN KEY (id_cita)
        REFERENCES citas_medicas(id_cita) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_consultas_paciente FOREIGN KEY (id_paciente)
        REFERENCES pacientes(id_paciente) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_consultas_medico FOREIGN KEY (id_medico)
        REFERENCES medicos(id_medico) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_consultas_diagnostico FOREIGN KEY (id_diagnostico_principal)
        REFERENCES diagnosticos_cie(id_diagnostico) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signos_vitales (
    id_signo_vital INT AUTO_INCREMENT PRIMARY KEY,
    id_consulta INT NOT NULL UNIQUE,
    presion_sistolica SMALLINT NOT NULL COMMENT '50-260 mmHg',
    presion_diastolica SMALLINT NOT NULL COMMENT '30-160 mmHg',
    frecuencia_cardiaca SMALLINT NOT NULL COMMENT '30-240 lpm',
    temperatura_c DECIMAL(4,1) NOT NULL COMMENT '34.0-43.0 °C',
    saturacion_o2 TINYINT NOT NULL COMMENT '50-100 %',
    peso_kg DECIMAL(5,2) NOT NULL,
    talla_cm DECIMAL(5,2) NOT NULL,
    imc DECIMAL(5,2) NOT NULL,
    registrado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_sv_sistolica CHECK (presion_sistolica BETWEEN 50 AND 260),
    CONSTRAINT chk_sv_diastolica CHECK (presion_diastolica BETWEEN 30 AND 160),
    CONSTRAINT chk_sv_fc CHECK (frecuencia_cardiaca BETWEEN 30 AND 240),
    CONSTRAINT chk_sv_temp CHECK (temperatura_c BETWEEN 34.0 AND 43.0),
    CONSTRAINT chk_sv_spo2 CHECK (saturacion_o2 BETWEEN 50 AND 100),
    CONSTRAINT chk_sv_peso CHECK (peso_kg > 0 AND peso_kg <= 500),
    CONSTRAINT chk_sv_talla CHECK (talla_cm >= 30 AND talla_cm <= 260),
    CONSTRAINT chk_sv_imc CHECK (imc > 0),
    CONSTRAINT fk_signos_consulta FOREIGN KEY (id_consulta)
        REFERENCES consultas_clinicas(id_consulta) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notas_adenda_clinica (
    id_adenda INT AUTO_INCREMENT PRIMARY KEY,
    id_consulta INT NOT NULL,
    id_medico_autor INT NOT NULL,
    texto_aclaracion TEXT NOT NULL,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_adenda_consulta (id_consulta),
    CONSTRAINT fk_adenda_consulta FOREIGN KEY (id_consulta)
        REFERENCES consultas_clinicas(id_consulta) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_adenda_medico FOREIGN KEY (id_medico_autor)
        REFERENCES medicos(id_medico) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MÓDULO 5: FARMACIA E INVENTARIO (FAR) Y RECETAS MÉDICAS (HIS/FAR)
-- Entidades: medicamentos, lotes_inventario, recetas_medicas, receta_detalle,
--            dispensaciones, dispensacion_detalle, movimientos_kardex
-- ============================================================================

CREATE TABLE IF NOT EXISTS medicamentos (
    id_medicamento INT AUTO_INCREMENT PRIMARY KEY,
    codigo_sku VARCHAR(30) NOT NULL UNIQUE,
    nombre_generico VARCHAR(120) NOT NULL,
    nombre_comercial VARCHAR(120) NOT NULL,
    principio_activo VARCHAR(120) NOT NULL,
    id_alergeno INT NULL COMMENT 'Vínculo con catalogo_alergias para validación RN-CLI-05',
    forma_farmaceutica VARCHAR(60) NOT NULL,
    concentracion VARCHAR(60) NOT NULL,
    stock_minimo INT NOT NULL DEFAULT 10,
    stock_global_actual INT NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT chk_med_stock_min CHECK (stock_minimo >= 0),
    CONSTRAINT chk_med_stock_global CHECK (stock_global_actual >= 0),
    CONSTRAINT fk_medicamento_alergeno FOREIGN KEY (id_alergeno)
        REFERENCES catalogo_alergias(id_alergeno) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lotes_inventario (
    id_lote INT AUTO_INCREMENT PRIMARY KEY,
    id_medicamento INT NOT NULL,
    numero_lote VARCHAR(50) NOT NULL,
    fecha_fabricacion DATE NOT NULL,
    fecha_caducidad DATE NOT NULL,
    cantidad_inicial INT NOT NULL,
    cantidad_actual INT NOT NULL,
    estado_lote ENUM('ACTIVO', 'AGOTADO', 'CADUCADO', 'BLOQUEADO') NOT NULL DEFAULT 'ACTIVO',
    id_usuario_recepcion INT NOT NULL,
    fecha_recepcion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_med_num_lote (id_medicamento, numero_lote),
    CONSTRAINT chk_lote_cant_inicial CHECK (cantidad_inicial > 0),
    CONSTRAINT chk_lote_cant_actual CHECK (cantidad_actual >= 0 AND cantidad_actual <= cantidad_inicial),
    CONSTRAINT chk_lote_fechas CHECK (fecha_caducidad > fecha_fabricacion),
    INDEX idx_lotes_fefo (id_medicamento, estado_lote, fecha_caducidad),
    CONSTRAINT fk_lotes_medicamento FOREIGN KEY (id_medicamento)
        REFERENCES medicamentos(id_medicamento) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lotes_usuario FOREIGN KEY (id_usuario_recepcion)
        REFERENCES usuarios(id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recetas_medicas (
    id_receta INT AUTO_INCREMENT PRIMARY KEY,
    folio_receta VARCHAR(25) NOT NULL UNIQUE,
    id_consulta INT NOT NULL UNIQUE COMMENT 'RN-CLI-02: NOT NULL y UNIQUE',
    id_medico_emisor INT NOT NULL,
    estado_receta ENUM('BORRADOR', 'PENDIENTE_SURTIR', 'SURTIDA_PARCIAL', 'SURTIDA_COMPLETA', 'CANCELADA') NOT NULL DEFAULT 'PENDIENTE_SURTIR',
    observaciones_generales VARCHAR(255) NULL,
    fecha_emision TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_recetas_consulta FOREIGN KEY (id_consulta)
        REFERENCES consultas_clinicas(id_consulta) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_recetas_medico FOREIGN KEY (id_medico_emisor)
        REFERENCES medicos(id_medico) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receta_detalle (
    id_receta_detalle INT AUTO_INCREMENT PRIMARY KEY,
    id_receta INT NOT NULL,
    id_medicamento INT NOT NULL,
    dosis VARCHAR(80) NOT NULL,
    via_administracion ENUM('ORAL', 'INTRAVENOSA', 'INTRAMUSCULAR', 'SUBCUTANEA', 'TOPICA', 'INHALATORIA', 'OFTALMICA', 'OTRA') NOT NULL DEFAULT 'ORAL',
    frecuencia_horaria VARCHAR(80) NOT NULL,
    duracion_tratamiento VARCHAR(80) NOT NULL,
    cantidad_prescrita INT NOT NULL,
    cantidad_surtida INT NOT NULL DEFAULT 0,
    UNIQUE KEY uk_receta_medicamento (id_receta, id_medicamento),
    CONSTRAINT chk_recdet_cant_prescrita CHECK (cantidad_prescrita > 0),
    CONSTRAINT chk_recdet_cant_surtida CHECK (cantidad_surtida >= 0 AND cantidad_surtida <= cantidad_prescrita),
    CONSTRAINT fk_recdet_receta FOREIGN KEY (id_receta)
        REFERENCES recetas_medicas(id_receta) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_recdet_medicamento FOREIGN KEY (id_medicamento)
        REFERENCES medicamentos(id_medicamento) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dispensaciones (
    id_dispensacion INT AUTO_INCREMENT PRIMARY KEY,
    id_receta INT NOT NULL,
    id_usuario_despacha INT NOT NULL,
    fecha_dispensacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notas_despacho VARCHAR(255) NULL,
    INDEX idx_dispensacion_receta (id_receta),
    CONSTRAINT fk_dispensacion_receta FOREIGN KEY (id_receta)
        REFERENCES recetas_medicas(id_receta) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_dispensacion_usuario FOREIGN KEY (id_usuario_despacha)
        REFERENCES usuarios(id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dispensacion_detalle (
    id_dispensacion_detalle INT AUTO_INCREMENT PRIMARY KEY,
    id_dispensacion INT NOT NULL,
    id_receta_detalle INT NOT NULL,
    id_lote INT NOT NULL,
    cantidad_dispensada INT NOT NULL,
    CONSTRAINT chk_dispdet_cantidad CHECK (cantidad_dispensada > 0),
    CONSTRAINT fk_dispdet_dispensacion FOREIGN KEY (id_dispensacion)
        REFERENCES dispensaciones(id_dispensacion) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_dispdet_recdet FOREIGN KEY (id_receta_detalle)
        REFERENCES receta_detalle(id_receta_detalle) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_dispdet_lote FOREIGN KEY (id_lote)
        REFERENCES lotes_inventario(id_lote) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS movimientos_kardex (
    id_movimiento BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_lote INT NOT NULL,
    id_medicamento INT NOT NULL,
    tipo_movimiento ENUM('ENTRADA_LOTE', 'SALIDA_DISPENSACION', 'SALIDA_POR_RECETA', 'BLOQUEO_CADUCIDAD') NOT NULL,
    cantidad_previa INT NOT NULL,
    cantidad_movimiento INT NOT NULL,
    saldo_resultante INT NOT NULL,
    referencia_receta VARCHAR(50) NULL,
    id_usuario_ejecutor INT NULL,
    fecha_movimiento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_kardex_med_fecha (id_medicamento, fecha_movimiento),
    INDEX idx_kardex_lote_fecha (id_lote, fecha_movimiento),
    CONSTRAINT fk_kardex_lote FOREIGN KEY (id_lote)
        REFERENCES lotes_inventario(id_lote) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_kardex_medicamento FOREIGN KEY (id_medicamento)
        REFERENCES medicamentos(id_medicamento) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_kardex_usuario FOREIGN KEY (id_usuario_ejecutor)
        REFERENCES usuarios(id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
