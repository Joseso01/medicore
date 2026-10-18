# MediCore Systems — Contexto Arquitectónico y Reglas de Ingeniería (`ERS-MCS-DB-2026-V1.0`)

Este archivo define las directrices técnicas, arquitectónicas y transaccionales obligatorias del proyecto **MediCore Systems** basadas en la especificación oficial [ERS-MCS-DB-2026-V1.0](file:///c:/Users/tezoc/medicore-systems/Documentacion/ERS-MCS-DB-2026-V1.0.md).

## 1. Stack y Restricciones Arquitectónicas Innegociables
* **Backend:** PHP 8.2+ (Apache) con patrón MVC ligero y **PDO nativo puro** (`pdo_mysql`).
  * **Prohibido el uso de ORMs** (Eloquent, Doctrine, etc.).
  * Configuración obligatoria de PDO (`RNF-SEG-02`):
    * `PDO::ATTR_EMULATE_PREPARES => false`
    * `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`
    * `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`
  * Prohibida la interpolación o concatenación de variables en SQL; el 100% de las consultas con parámetros debe usar sentencias preparadas (`prepare` + `execute`).
* **Base de Datos:** MySQL 8.0 (`ENGINE=InnoDB`, `CHARSET=utf8mb4`, `COLLATE=utf8mb4_unicode_ci`).
  * `sql_mode = STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`
  * Normalización 3FN/BCNF (`RBD-INT-01`).
  * **Prohibido `ON DELETE CASCADE` o `ON DELETE SET NULL`** en tablas maestras o transaccionales. Todas las relaciones padre-hijo deben usar `ON DELETE RESTRICT ON UPDATE CASCADE`.
* **Frontend:** HTML5 semántico, Bootstrap 5 y Vanilla JavaScript (ES6+, Fetch API).
  * Prohibidos frameworks JS pesados (React, Vue, Angular).
  * Sanitización XSS obligatoria (`RNF-SEG-03`): `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` en PHP y `textContent` en JS.
  * Protección CSRF con `random_bytes(32)` en toda petición `POST`/`PUT`/`DELETE`.

## 2. Módulos del Sistema
1. **Seguridad y Auditoría (`SEG`):** `roles`, `permisos_modulo`, `usuarios`, `sesiones_activas`, `bitacora_auditoria`.
2. **Gestión de Pacientes (`PAC`):** `pacientes` (con MRN formato `MCS-YYYY-XXXXXX`), `catalogo_alergias`, `paciente_alergias`, `contactos_emergencia`.
3. **Control de Citas (`CIT`):** `especialidades`, `consultorios`, `medicos`, `horarios_medicos`, `citas_medicas`.
4. **Historial Clínico Electrónico (`HIS`):** `diagnosticos_cie`, `consultas_clinicas`, `signos_vitales`, `notas_adenda_clinica`, `recetas_medicas`, `receta_detalle`.
5. **Farmacia e Inventario (`FAR`):** `medicamentos`, `lotes_inventario`, `dispensaciones`, `dispensacion_detalle`, `movimientos_kardex`.

## 3. Reglas de Negocio y Triggers en Base de Datos (Defensa en Profundidad)
* **`RN-CLI-01` (No empalme de citas):** Ni médico, ni paciente, ni consultorio pueden tener citas superpuestas (`hora_inicio_1 < hora_fin_2 AND hora_fin_1 > hora_inicio_2`) en estado activo (`PROGRAMADA`, `CONFIRMADA`, `EN_CURSO`). Validado con `SELECT ... FOR UPDATE` en PDO y Triggers `BEFORE INSERT/UPDATE` en `citas_medicas`.
* **`RN-CLI-02` (Receta vinculada a consulta activa):** `id_consulta` en `recetas_medicas` es `NOT NULL UNIQUE`. Trigger `BEFORE INSERT` valida que la consulta exista, que el médico coincida, y que tenga signos vitales y diagnóstico registrados.
* **`RN-CLI-03` (Inmutabilidad del EHR):** Triggers `BEFORE UPDATE` y `BEFORE DELETE` en `consultas_clinicas`, `signos_vitales`, `recetas_medicas` y `receta_detalle` impiden cualquier modificación o borrado una vez que la consulta está `CERRADA`. Aclaraciones posteriores solo mediante `notas_adenda_clinica`.
* **`RN-CLI-04` / `RBD-INT-03` / `RBD-INT-04` (Farmacia FEFO, Caducidad y Kárdex):**
  * `trg_before_insert_dispensacion`: Bloquea lotes vencidos (`ERR_FAR_LOTE_CADUCADO`), stock insuficiente (`ERR_FAR_STOCK_INSUFICIENTE`) o exceso sobre la cantidad prescrita.
  * `trg_after_insert_dispensacion`: Descuenta `cantidad_actual` en `lotes_inventario` (cambia a `AGOTADO` si llega a `0`) e inserta movimiento inmutable `SALIDA_DISPENSACION` en `movimientos_kardex`.
* **`RN-CLI-05` (Contraindicación por Alergia):** Trigger `BEFORE INSERT` en `receta_detalle` cruza el medicamento prescrito con `paciente_alergias` (`MODERADA` o `ANAFILAXIA_SEVERA`) y aborta con `RN-CLI-05` si hay coincidencia.
* **`RN-CLI-06` (Segregación de funciones y No Autoprescripción):** Un médico no puede agendarse ni atenderse/recetarse a sí mismo (`documento_identidad` o `id_paciente` distinto); médicos no despachan farmacia; recepcionistas y administradores no leen ni escriben notas clínicas confidenciales del EHR.
* **`RBD-INT-05` (Auditoría Forense e Inmutabilidad de Kárdex):** Prohibido `UPDATE`/`DELETE` en `movimientos_kardex`. Triggers `AFTER UPDATE` en `usuarios`, `pacientes` y `lotes_inventario` registran `OLD.*` y `NEW.*` con `JSON_OBJECT` en `bitacora_auditoria`.
