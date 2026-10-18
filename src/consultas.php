<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/controllers/ConsultaController.php';

use MediCore\Core\Security;
use MediCore\Core\AuthMiddleware;
use MediCore\Controllers\ConsultaController;

// RN-CLI-06 & RF-SEG-02: Exclusivamente el rol Médico puede acceder al EHR
AuthMiddleware::requirePermission('HIS', 'leer', false);

$idMedicoSesion = isset($_SESSION['id_medico']) ? (int)$_SESSION['id_medico'] : 0;
$idUsuarioEjecutor = (int)($_SESSION['id_usuario'] ?? 0);

$controller = new ConsultaController();
$catalogos  = $controller->obtenerCatalogosClinicos();
$citasMedico = $idMedicoSesion > 0 ? $controller->listarCitasYConsultasMedico($idMedicoSesion) : [];

$idCitaInicial = isset($_GET['id_cita']) ? (int)$_GET['id_cita'] : 0;

$tituloPagina = 'Historial Clínico Electrónico (EHR) y Receta — MediCore Systems';
$moduloActivo = 'HIS';
require_once __DIR__ . '/views/layout/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-file-earmark-medical text-primary me-2"></i>Historial Clínico Electrónico (EHR) y Prescripción</h3>
        <p class="text-muted small mb-0">
            Cumplimiento de <code>RF-HIS-01</code> a <code>RF-HIS-04</code>, <code>RN-CLI-02</code> (Receta vinculada a consulta), <code>RN-CLI-03</code> (Inmutabilidad posfirma y Adendas) y <code>RN-CLI-05</code> (Bloqueo por Alergia Severa).
        </p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-primary-subtle text-primary border px-3 py-2">
            <i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Facultativo Titular: <?= Security::e($_SESSION['nombre_completo']) ?>
        </span>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()" title="Imprimir Expediente / Receta">
            <i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir
        </button>
    </div>
</div>

<div id="alertaEhr" class="alert d-none align-items-center justify-content-between" role="alert">
    <span id="alertaEhrTexto"></span>
    <button type="button" class="btn-close" onclick="document.getElementById('alertaEhr').classList.add('d-none')"></button>
</div>

<div class="row g-4" id="contenedorModuloHis" data-id-cita-inicial="<?= $idCitaInicial ?>">
    <!-- COLUMNA IZQUIERDA: Citas del Médico para Iniciar / Continuar Acto Clínico -->
    <div class="col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0"><i class="bi bi-calendar2-heart text-primary me-1"></i>Pacientes Citados</h6>
                <span class="badge bg-light text-dark border"><?= count($citasMedico) ?></span>
            </div>
            <div id="listaCitasMedico" class="list-group list-group-flush small mc-scroll-panel">
                <?php if (empty($citasMedico)): ?>
                    <div class="p-4 text-center text-muted">
                        No tiene citas asignadas en su agenda médica.
                    </div>
                <?php else: ?>
                    <?php foreach ($citasMedico as $cm): ?>
                        <?php
                        $badgeEstado = match ($cm['estado']) {
                            'CONFIRMADA' => 'bg-primary',
                            'EN_CURSO'   => 'bg-warning text-dark',
                            'ATENDIDA'   => 'bg-success',
                            default      => 'bg-secondary',
                        };
                        ?>
                        <button type="button"
                                class="list-group-item list-group-item-action py-3 item-cita-ehr <?= $idCitaInicial === (int)$cm['id_cita'] ? 'active' : '' ?>"
                                data-id-cita="<?= (int)$cm['id_cita'] ?>"
                                onclick="cargarConsultaDesdeCita(<?= (int)$cm['id_cita'] ?>)">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="font-mono fw-bold"><?= Security::e($cm['fecha_cita']) ?> <?= Security::e(substr((string)$cm['hora_inicio'], 0, 5)) ?></span>
                                <span class="badge <?= $badgeEstado ?>"><?= Security::e($cm['estado']) ?></span>
                            </div>
                            <div class="fw-bold"><?= Security::e($cm['paciente_nombre']) ?></div>
                            <div class="d-flex justify-content-between align-items-center mt-1 opacity-75">
                                <code><?= Security::e($cm['mrn']) ?></code>
                                <span><?= (int)$cm['edad'] ?> años | Rh: <?= Security::e($cm['grupo_sanguineo']) ?></span>
                            </div>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- COLUMNA DERECHA: Área de Trabajo del Expediente Clínico Electrónico -->
    <div class="col-lg-9">
        <!-- Estado Inicial (Sin cita seleccionada) -->
        <div id="panelSinSeleccion" class="card shadow-sm border-0 p-5 text-center <?= $idCitaInicial > 0 ? 'd-none' : '' ?>">
            <div class="py-4">
                <i class="bi bi-clipboard2-pulse text-primary display-4 mb-3 d-block"></i>
                <h5 class="fw-bold">Seleccione un paciente de su agenda para abrir o consultar su EHR</h5>
                <p class="text-muted small mb-0">
                    De acuerdo con <strong>RF-HIS-01</strong> y <strong>RN-CLI-02</strong>, ningún acto médico ni receta electrónica puede originarse sin una cita activa asignada a su perfil facultativo.
                </p>
            </div>
        </div>

        <!-- Panel Principal del Acto Clínico (Visible al cargar una cita) -->
        <div id="panelExpedienteActivo" class="d-none">
            <!-- CABECERA DEL EXPEDIENTE Y ALERTAS ROJAS DE ALERGIAS (RF-PAC-02 / RN-CLI-05) -->
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <code class="fs-5 fw-bold text-primary" id="ehr_mrn"></code>
                                <h4 class="fw-bold mb-0" id="ehr_paciente_nombre"></h4>
                                <span class="badge bg-danger fs-6" id="ehr_grupo_sanguineo"></span>
                            </div>
                            <div class="small text-muted">
                                <span id="ehr_edad_sexo"></span> |
                                <strong>Consultorio:</strong> <span id="ehr_consultorio"></span> |
                                <strong>Motivo de Cita:</strong> <span id="ehr_motivo_cita"></span>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="badge fs-6" id="ehr_badge_estado_consulta"></span>
                            <div class="small text-muted font-mono mt-1" id="ehr_fechas_consulta"></div>
                        </div>
                    </div>

                    <!-- Contenedor Prominente de Alertas de Alergias (RF-PAC-02: badge bg-danger) -->
                    <div class="mt-3 pt-2 border-top d-flex flex-wrap align-items-center gap-2" id="ehr_contenedor_alergias">
                    </div>

                    <!-- Sello de Inmutabilidad SHA-256 cuando la consulta está CERRADA (RN-CLI-03) -->
                    <div id="ehr_sello_inmutabilidad" class="alert alert-secondary small py-2 mt-3 mb-0 d-none">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div>
                                <i class="bi bi-lock-fill text-dark me-1"></i>
                                <strong>RN-CLI-03 (Documento Médico-Legal Inmutable):</strong> Esta consulta fue cerrada y sellada contra modificaciones (Triggers <code>BEFORE UPDATE/DELETE</code> activos).
                            </div>
                            <code class="small" id="ehr_firma_hash"></code>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PESTAÑAS DEL EHR: 1. Acto Clínico Actual (SOAP + Signos + Receta) | 2. Adendas Clínicas | 3. Vista Longitudinal -->
            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-semibold" id="tab-acto-btn" data-bs-toggle="tab" data-bs-target="#paneActoClinico" type="button" role="tab">
                        <i class="bi bi-heart-pulse me-1"></i>1. Signos Vitales, Nota SOAP y Receta Electrónica
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-semibold" id="tab-adendas-btn" data-bs-toggle="tab" data-bs-target="#paneAdendas" type="button" role="tab">
                        <i class="bi bi-journal-plus me-1"></i>2. Notas de Adenda Clínica (<span id="ehr_count_adendas">0</span>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-semibold" id="tab-longitudinal-btn" data-bs-toggle="tab" data-bs-target="#paneLongitudinal" type="button" role="tab">
                        <i class="bi bi-clock-history me-1"></i>3. Historial Longitudinal del EHR (<span id="ehr_count_historial">0</span>)
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- PESTAÑA 1: ACTO CLÍNICO (SIGNOS VITALES + SOAP + RECETA) -->
                <div class="tab-pane fade show active" id="paneActoClinico" role="tabpanel">
                    <form id="formActoClinico">
                        <input type="hidden" name="id_consulta" id="input_id_consulta">

                        <!-- SECCIÓN A: Somatometría y Signos Vitales (RF-HIS-02) -->
                        <div class="card shadow-sm border-0 mb-3">
                            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-activity me-1"></i>A. Somatometría y Signos Vitales Validados (RF-HIS-02)</h6>
                                <span class="badge bg-info text-dark" id="badgeClasificacionImc">IMC pendiente</span>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold">P.A. Sistólica (50–260 mmHg) *</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control campo-ehr" name="presion_sistolica" id="sv_sistolica" min="50" max="260" required placeholder="120">
                                            <span class="input-group-text">mmHg</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold">P.A. Diastólica (30–160 mmHg) *</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control campo-ehr" name="presion_diastolica" id="sv_diastolica" min="30" max="160" required placeholder="80">
                                            <span class="input-group-text">mmHg</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold">Frec. Cardíaca (30–240 lpm) *</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control campo-ehr" name="frecuencia_cardiaca" id="sv_fc" min="30" max="240" required placeholder="72">
                                            <span class="input-group-text">lpm</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold">Temperatura (34.0–43.0 °C) *</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.1" class="form-control campo-ehr" name="temperatura_c" id="sv_temp" min="34.0" max="43.0" required placeholder="36.6">
                                            <span class="input-group-text">°C</span>
                                        </div>
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold">Saturación SpO2 (50–100%) *</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" class="form-control campo-ehr" name="saturacion_o2" id="sv_spo2" min="50" max="100" required placeholder="98">
                                            <span class="input-group-text">%</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold">Peso Corporal (kg) *</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.1" class="form-control campo-ehr" name="peso_kg" id="sv_peso" min="1" max="500" required placeholder="70.5" oninput="calcularImcAutomatico()">
                                            <span class="input-group-text">kg</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold">Talla (cm) *</label>
                                        <div class="input-group input-group-sm">
                                            <input type="number" step="0.1" class="form-control campo-ehr" name="talla_cm" id="sv_talla" min="30" max="260" required placeholder="170" oninput="calcularImcAutomatico()">
                                            <span class="input-group-text">cm</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold">IMC Calculado (kg/m²)</label>
                                        <div class="input-group input-group-sm">
                                            <input type="text" class="form-control bg-light fw-bold font-mono" id="sv_imc" readonly placeholder="0.00">
                                            <span class="input-group-text">kg/m²</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SECCIÓN B: Nota Clínica Evolutiva Estructurada SOAP y CIE-10 (RF-HIS-02) -->
                        <div class="card shadow-sm border-0 mb-3">
                            <div class="card-header bg-white py-2">
                                <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-journal-medical me-1"></i>B. Nota Clínica Estructurada (SOAP) y Diagnóstico CIE-10</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label small fw-semibold">Diagnóstico Clínico Codificado (Catálogo CIE-10) *</label>
                                        <select class="form-select campo-ehr" name="id_diagnostico_principal" id="soap_diagnostico" required>
                                            <option value="">-- Seleccionar Diagnóstico CIE-10 --</option>
                                            <?php foreach ($catalogos['diagnosticos'] as $cie): ?>
                                                <option value="<?= (int)$cie['id_diagnostico'] ?>">
                                                    [<?= Security::e($cie['codigo_cie']) ?>] <?= Security::e($cie['descripcion']) ?> (<?= Security::e($cie['categoria']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold">S — Subjetivo (Padecimiento actual y síntomas referidos) *</label>
                                        <textarea class="form-control campo-ehr" name="nota_subjetiva" id="soap_subjetivo" rows="3" required></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold">O — Objetivo (Exploración física e inspección clínica) *</label>
                                        <textarea class="form-control campo-ehr" name="nota_objetiva" id="soap_objetivo" rows="3" required></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold">A — Análisis Clínico e Interpretación Diagnóstica</label>
                                        <textarea class="form-control campo-ehr" name="nota_analisis" id="soap_analisis" rows="2"></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-semibold">P — Plan Terapéutico y Recomendaciones No Farmacológicas *</label>
                                        <textarea class="form-control campo-ehr" name="nota_plan" id="soap_plan" rows="2" required></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SECCIÓN C: Emisión de Receta Electrónica con Validación de Alergias y Stock (RF-HIS-03 / RN-CLI-05) -->
                        <div class="card shadow-sm border-0 mb-3">
                            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0 text-primary">
                                    <i class="bi bi-prescription2 me-1"></i>C. Prescripción Terapéutica Electrónica (RF-HIS-03 / RN-CLI-05)
                                    <code class="ms-2" id="ehr_folio_receta_badge"></code>
                                </h6>
                                <button type="button" class="btn btn-outline-primary btn-sm" id="btnAgregarRenglonReceta" onclick="agregarRenglonRecetaUI()">
                                    <i class="bi bi-plus-circle me-1"></i>Agregar Medicamento a la Receta
                                </button>
                            </div>
                            <div class="card-body">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Indicaciones Generales de la Receta</label>
                                    <input type="text" class="form-control form-control-sm campo-ehr" id="receta_observaciones" placeholder="ej. Tomar con abundante agua; acudir a urgencias en caso de reacción adversa.">
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-bordered align-middle small mb-0 mc-table-receta">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Medicamento (Stock Vigente FEFO)</th>
                                                <th>Dosis</th>
                                                <th>Vía</th>
                                                <th>Frecuencia</th>
                                                <th>Duración</th>
                                                <th>Cant.</th>
                                                <th><span class="visually-hidden">Acciones</span></th>
                                            </tr>
                                        </thead>
                                        <tbody id="tbodyRenglonesReceta">
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Botones de Acción Transaccional (Guardar Borrador vs Cerrar y Firmar Consulta) -->
                        <div class="d-flex justify-content-end gap-2 mb-4" id="barraBotonesActoClinico">
                            <button type="button" class="btn btn-outline-primary" onclick="enviarActoClinico(false)">
                                <i class="bi bi-floppy me-1"></i>Guardar Avance en Curso
                            </button>
                            <button type="button" class="btn btn-success fw-semibold" onclick="enviarActoClinico(true)">
                                <i class="bi bi-shield-lock-fill me-1"></i>Cerrar, Firmar y Sellar Consulta (RF-HIS-04)
                            </button>
                        </div>
                    </form>
                </div>

                <!-- PESTAÑA 2: NOTAS DE ADENDA CLÍNICA (RN-CLI-03) -->
                <div class="tab-pane fade" id="paneAdendas" role="tabpanel">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white py-3">
                            <h6 class="fw-bold mb-0"><i class="bi bi-journal-plus text-primary me-2"></i>Mecanismo Legal de Adenda Clínica (<code>notas_adenda_clinica</code> — RN-CLI-03)</h6>
                        </div>
                        <div class="card-body">
                            <div id="listaAdendasClinicas" class="mb-3"></div>

                            <form id="formNuevaAdenda" class="d-none border-top pt-3">
                                <label class="form-label small fw-semibold">Asentar Aclaración o Evolución Posterior sobre Consulta Cerrada *</label>
                                <textarea class="form-control mb-2" id="adenda_texto" rows="3" required
                                          placeholder="El registro original permanece inmutable; esta nota complementaria se anexará con marca de tiempo exacta y su firma facultativa..."></textarea>
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="bi bi-plus-square me-1"></i>Registrar Adenda Inmutable (RN-CLI-03)
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- PESTAÑA 3: CONSULTA LONGITUDINAL DEL EHR (RF-HIS-04) -->
                <div class="tab-pane fade" id="paneLongitudinal" role="tabpanel">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white py-3">
                            <h6 class="fw-bold mb-0"><i class="bi bi-clock-history text-primary me-2"></i>Vista Cronológica Longitudinal del Expediente Clínico (RF-HIS-04)</h6>
                        </div>
                        <div class="card-body" id="contenedorHistorialLongitudinal">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Catálogo de medicamentos inyectado como datos JSON seguros para el constructor de recetas -->
<script id="catalogoMedicamentosJson" type="application/json">
<?= json_encode($catalogos['medicamentos'], JSON_UNESCAPED_UNICODE) ?>
</script>
<script src="/assets/js/consultas.js"></script>
<?php require_once __DIR__ . '/views/layout/footer.php'; ?>
