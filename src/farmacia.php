<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/controllers/FarmaciaController.php';
require_once __DIR__ . '/controllers/PacienteController.php';

use MediCore\Core\Security;
use MediCore\Core\AuthMiddleware;
use MediCore\Controllers\FarmaciaController;
use MediCore\Controllers\PacienteController;

AuthMiddleware::requirePermission('FAR', 'leer', false);

$controller = new FarmaciaController();
$pacController = new PacienteController();

$medicamentos = $controller->listarMedicamentosYAlertasStock();
$lotes        = $controller->listarLotesSemaforo();
$recetasPend  = $controller->buscarRecetasPendientesFefo('');
$kardex       = $controller->listarMovimientosKardex(60);
$alergenos    = $pacController->listarCatalogoAlergias();

$idRolActual = (int)($_SESSION['id_rol'] ?? 0);
$puedeDispensar = AuthMiddleware::can('FAR', 'crear');       // Recepcionista y Administrador
$esAdminCatalogo = AuthMiddleware::can('FAR', 'actualizar'); // Exclusivo Administrador (RF-FAR-01)

$totalStockCritico = count(array_filter($medicamentos, static fn(array $m): bool => !empty($m['alerta_stock_critico'])));
$totalLotesAmbar   = count(array_filter($lotes, static fn(array $l): bool => $l['semaforo_caducidad'] === 'AMBAR'));
$totalLotesRojo    = count(array_filter($lotes, static fn(array $l): bool => $l['semaforo_caducidad'] === 'ROJO'));

$tituloPagina = 'Farmacia, Despacho FEFO y Kárdex Inmutable — MediCore Systems';
$moduloActivo = 'FAR';
require_once __DIR__ . '/views/layout/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-capsule-pill text-primary me-2"></i>Módulo de Farmacia, Trazabilidad FEFO y Kárdex</h3>
        <p class="text-muted small mb-0">
            Cumplimiento de <code>RF-FAR-01</code> a <code>RF-FAR-04</code>, <code>RBD-INT-03/04/05</code> y <code>RN-CLI-04</code> (Primero en Caducar, Primero en Salir).
        </p>
    </div>
    <?php if ($esAdminCatalogo): ?>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNuevoMedicamento">
                <i class="bi bi-plus-square me-1"></i>Nuevo Medicamento (SKU)
            </button>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalRecepcionarLote">
                <i class="bi bi-box-seam me-1"></i>Recepcionar Lote de Inventario (RF-FAR-01)
            </button>
        </div>
    <?php endif; ?>
</div>

<div id="alertaFarmacia" class="alert d-none align-items-center justify-content-between" role="alert">
    <span id="alertaFarmaciaTexto"></span>
    <button type="button" class="btn-close" onclick="document.getElementById('alertaFarmacia').classList.add('d-none')"></button>
</div>

<!-- Tarjetas de Resumen del Monitor Analítico (RF-FAR-04) -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card card-kpi card-kpi--info h-100">
            <div class="card-body py-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="small text-muted text-uppercase fw-semibold">Recetas Pendientes</div>
                    <div class="fs-3 fw-bold text-info"><?= count($recetasPend) ?></div>
                    <div class="small text-secondary">Listas para despacho FEFO</div>
                </div>
                <i class="bi bi-prescription2 fs-1 text-info opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-kpi card-kpi--danger h-100">
            <div class="card-body py-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="small text-muted text-uppercase fw-semibold">Stock Crítico (&le; Mínimo)</div>
                    <div class="fs-3 fw-bold text-danger"><?= $totalStockCritico ?></div>
                    <div class="small text-secondary">Medicamentos por reabastecer</div>
                </div>
                <i class="bi bi-exclamation-octagon fs-1 text-danger opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-kpi card-kpi--warning h-100">
            <div class="card-body py-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="small text-muted text-uppercase fw-semibold">Semáforo Ámbar (&le; 90d)</div>
                    <div class="fs-3 fw-bold text-warning"><?= $totalLotesAmbar ?></div>
                    <div class="small text-secondary">Prioridad de salida FEFO</div>
                </div>
                <i class="bi bi-hourglass-split fs-1 text-warning opacity-50"></i>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-kpi card-kpi--success h-100">
            <div class="card-body py-3 d-flex justify-content-between align-items-center">
                <div>
                    <div class="small text-muted text-uppercase fw-semibold">Movimientos en Kárdex</div>
                    <div class="fs-3 fw-bold text-success"><?= count($kardex) ?></div>
                    <div class="small text-secondary">Trazabilidad inmutable</div>
                </div>
                <i class="bi bi-journal-check fs-1 text-success opacity-50"></i>
            </div>
        </div>
    </div>
</div>

<!-- PESTAÑAS DEL MÓDULO DE FARMACIA -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active fw-semibold" data-bs-toggle="tab" data-bs-target="#paneDispensacion" type="button" role="tab">
            <i class="bi bi-bag-check me-1"></i>1. Ventanilla de Dispensación FEFO (<code>RF-FAR-02 / 03</code>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#paneMonitor" type="button" role="tab">
            <i class="bi bi-traffic-light me-1"></i>2. Monitor de Stock Crítico y Semáforo de Caducidad (<code>RF-FAR-04</code>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#paneKardex" type="button" role="tab">
            <i class="bi bi-list-columns-reverse me-1"></i>3. Kárdex Inmutable (<code>movimientos_kardex</code> — <code>RBD-INT-04 / 05</code>)
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- TAB 1: VENTANILLA DE DISPENSACIÓN VINCULADA A RECETA BAJO REGLA FEFO -->
    <div class="tab-pane fade show active" id="paneDispensacion" role="tabpanel">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-body py-3">
                <form class="row g-2 align-items-center" onsubmit="event.preventDefault(); buscarRecetasFefo();">
                    <div class="col-md-9">
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-search text-primary"></i></span>
                            <input type="search" id="inputBuscarReceta" class="form-control"
                                   placeholder="Buscar receta pendiente por Folio (ej. REC-2026-...) o MRN del paciente (MCS-2026-...) (RF-FAR-02)...">
                        </div>
                    </div>
                    <div class="col-md-3 d-grid">
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="bi bi-funnel me-1"></i>Consultar Recetas Pendientes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div id="contenedorRecetasPendientes" data-puede-dispensar="<?= $puedeDispensar ? '1' : '0' ?>">
            <?php if (empty($recetasPend)): ?>
                <div class="card shadow-sm border-0 mc-empty-state">
                    <i class="bi bi-inbox" aria-hidden="true"></i>
                    No existen recetas en estado <code>PENDIENTE_SURTIR</code> o <code>SURTIDA_PARCIAL</code> en este momento.
                    Cuando un Médico cierre una consulta con prescripción en el módulo EHR, aparecerá aquí automáticamente.
                </div>
            <?php else: ?>
                <?php foreach ($recetasPend as $rec): ?>
                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <code class="fs-5 fw-bold text-primary me-2"><?= Security::e($rec['folio_receta']) ?></code>
                                <span class="badge bg-warning text-dark"><?= Security::e($rec['estado_receta']) ?></span>
                                <span class="ms-2 small text-muted">Emitida: <?= Security::e($rec['fecha_emision']) ?></span>
                            </div>
                            <div class="small">
                                <strong>Paciente:</strong> <?= Security::e($rec['paciente_nombre']) ?> (<code><?= Security::e($rec['mrn']) ?></code>) |
                                <strong>Prescriptor:</strong> <?= Security::e($rec['medico_nombre']) ?> (Cédula: <?= Security::e($rec['cedula_profesional']) ?>)
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle small mb-3">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Medicamento Prescrito</th>
                                            <th>Posología (Dosis / Vía / Frecuencia / Duración)</th>
                                            <th>Prescrito</th>
                                            <th>Surtido</th>
                                            <th>Pendiente</th>
                                            <th>Lote(s) Sugeridos por Regla FEFO (<code>ORDER BY fecha_caducidad ASC</code>)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rec['renglones'] as $r): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold"><?= Security::e($r['nombre_generico']) ?> (<?= Security::e($r['concentracion']) ?>)</div>
                                                    <code class="text-muted"><?= Security::e($r['codigo_sku']) ?></code> — <?= Security::e($r['forma_farmaceutica']) ?>
                                                </td>
                                                <td>
                                                    <?= Security::e($r['dosis']) ?> | <?= Security::e($r['via_administracion']) ?> |
                                                    <?= Security::e($r['frecuencia_horaria']) ?> por <?= Security::e($r['duracion_tratamiento']) ?>
                                                </td>
                                                <td class="fw-bold text-center"><?= (int)$r['cantidad_prescrita'] ?></td>
                                                <td class="text-center text-success"><?= (int)$r['cantidad_surtida'] ?></td>
                                                <td class="fw-bold text-center text-danger"><?= (int)$r['cantidad_pendiente'] ?></td>
                                                <td>
                                                    <?php if (empty($r['asignacion_fefo'])): ?>
                                                        <span class="badge bg-danger">Sin lotes vigentes disponibles</span>
                                                    <?php else: ?>
                                                        <?php foreach ($r['asignacion_fefo'] as $asig): ?>
                                                            <div class="mb-1">
                                                                <span class="badge bg-info text-dark">
                                                                    Lote: <?= Security::e($asig['numero_lote']) ?>
                                                                </span>
                                                                <span>Vence: <strong><?= Security::e($asig['fecha_caducidad']) ?></strong> (<?= (int)$asig['dias_vigencia'] ?>d)</span>
                                                                <span class="badge bg-success ms-1">Tomar <?= (int)$asig['cantidad_sugerida'] ?> de <?= (int)$asig['disponible_en_lote'] ?> u.</span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($puedeDispensar): ?>
                                <div class="d-flex justify-content-end">
                                    <button type="button" class="btn btn-success btn-sm fw-semibold js-dispensar"
                                            data-id-receta="<?= (int)$rec['id_receta'] ?>"
                                            data-folio="<?= Security::e($rec['folio_receta']) ?>">
                                        <i class="bi bi-check2-all me-1" aria-hidden="true"></i>Confirmar Despacho Atómico FEFO y Registrar en Kárdex (RF-FAR-03)
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="text-end small text-muted">
                                    <i class="bi bi-shield-lock me-1"></i>RN-CLI-06: El rol Médico tiene restringida la ejecución de despachos en ventanilla de farmacia.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB 2: MONITOR DE ALERTAS DE STOCK CRÍTICO Y SEMÁFORO DE CADUCIDAD (RF-FAR-04) -->
    <div class="tab-pane fade" id="paneMonitor" role="tabpanel">
        <div class="row g-4">
            <!-- Catálogo Maestro y Alertas de Stock Crítico -->
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0"><i class="bi bi-exclamation-triangle text-danger me-2"></i>1. Catálogo Maestro y Monitor de Stock Crítico (RF-FAR-01 / 04)</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>SKU</th>
                                    <th>Medicamento / Presentación</th>
                                    <th>Alérgeno (RN-CLI-05)</th>
                                    <th class="text-center">Mínimo</th>
                                    <th class="text-center">Vigente</th>
                                    <th>Estado Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($medicamentos as $m): ?>
                                    <tr class="<?= !empty($m['alerta_stock_critico']) ? 'table-danger' : '' ?>">
                                        <td class="font-mono fw-bold"><?= Security::e($m['codigo_sku']) ?></td>
                                        <td>
                                            <div class="fw-bold"><?= Security::e($m['nombre_generico']) ?> (<?= Security::e($m['concentracion']) ?>)</div>
                                            <div class="text-muted"><?= Security::e($m['nombre_comercial']) ?> — <?= Security::e($m['forma_farmaceutica']) ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($m['alergeno_nombre'])): ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border"><?= Security::e($m['alergeno_nombre']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center font-mono"><?= (int)$m['stock_minimo'] ?></td>
                                        <td class="text-center font-mono fw-bold fs-6"><?= (int)$m['existencias_vigentes'] ?></td>
                                        <td>
                                            <?php if (!empty($m['alerta_stock_critico'])): ?>
                                                <span class="badge bg-danger"><i class="bi bi-bell-fill me-1"></i>STOCK CRÍTICO</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">ÓPTIMO</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Semáforo de Caducidad de Lotes (Rojo / Ámbar / Verde) -->
            <div class="col-lg-6">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0"><i class="bi bi-stoplights text-warning me-2"></i>2. Semáforo de Caducidad de Lotes y Orden FEFO (RF-FAR-04)</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Número de Lote</th>
                                    <th>Medicamento</th>
                                    <th>Caducidad (Días)</th>
                                    <th class="text-center">Saldo / Inicial</th>
                                    <th>Semáforo (RF-FAR-04)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lotes as $l): ?>
                                    <tr class="<?= $l['semaforo_caducidad'] === 'ROJO' ? 'table-danger' : '' ?>">
                                        <td class="font-mono fw-bold"><?= Security::e($l['numero_lote']) ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= Security::e($l['nombre_generico']) ?></div>
                                            <code class="text-muted"><?= Security::e($l['codigo_sku']) ?></code>
                                        </td>
                                        <td class="font-mono">
                                            <div><?= Security::e($l['fecha_caducidad']) ?></div>
                                            <span class="text-muted">(<?= (int)$l['dias_vigencia'] ?> días)</span>
                                        </td>
                                        <td class="text-center font-mono">
                                            <strong><?= (int)$l['cantidad_actual'] ?></strong> / <?= (int)$l['cantidad_inicial'] ?>
                                        </td>
                                        <td>
                                            <?php if ($l['semaforo_caducidad'] === 'ROJO'): ?>
                                                <span class="mc-semaforo mc-semaforo--rojo">CADUCADO — Bloqueado</span>
                                            <?php elseif ($l['semaforo_caducidad'] === 'AMBAR'): ?>
                                                <span class="mc-semaforo mc-semaforo--ambar">Próximo a vencer (&le; 90d)</span>
                                            <?php else: ?>
                                                <span class="mc-semaforo mc-semaforo--verde">Vigente (&gt; 90d)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TAB 3: KÁRDEX LÓGICO INMUTABLE (RBD-INT-04 / RBD-INT-05) -->
    <div class="tab-pane fade" id="paneKardex" role="tabpanel">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">
                    <i class="bi bi-shield-check text-success me-2"></i>Bitácora de Movimientos de Kárdex (Protegida por Triggers <code>trg_immutable_*_kardex</code>)
                </h6>
                <span class="badge bg-light text-dark border"><?= count($kardex) ?> movimientos</span>
            </div>
            <div class="table-responsive mc-table-scroll">
                <table class="table table-striped table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Mov. #</th>
                            <th>Fecha / Hora</th>
                            <th>Tipo de Movimiento</th>
                            <th>Medicamento (SKU)</th>
                            <th>Lote</th>
                            <th class="text-center">Saldo Previo</th>
                            <th class="text-center">Cantidad</th>
                            <th class="text-center">Saldo Resultante</th>
                            <th>Referencia (Receta / Lote)</th>
                            <th>Ejecutor</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($kardex as $k): ?>
                            <?php $esEntrada = str_starts_with((string)$k['tipo_movimiento'], 'ENTRADA'); ?>
                            <tr>
                                <td class="font-mono">#<?= (int)$k['id_movimiento'] ?></td>
                                <td class="font-mono"><?= Security::e($k['fecha_movimiento']) ?></td>
                                <td>
                                    <span class="badge <?= $esEntrada ? 'bg-success' : 'bg-primary' ?>"><?= Security::e($k['tipo_movimiento']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= Security::e($k['nombre_generico']) ?> (<?= Security::e($k['concentracion']) ?>)</div>
                                    <code><?= Security::e($k['codigo_sku']) ?></code>
                                </td>
                                <td class="font-mono"><?= Security::e($k['numero_lote']) ?></td>
                                <td class="text-center font-mono"><?= (int)$k['cantidad_previa'] ?></td>
                                <td class="text-center font-mono fw-bold <?= $esEntrada ? 'mc-kardex-entrada' : 'mc-kardex-salida' ?>">
                                    <?= $esEntrada ? '+' : '−' ?><?= (int)$k['cantidad_movimiento'] ?>
                                </td>
                                <td class="text-center font-mono fw-bold text-primary"><?= (int)$k['saldo_resultante'] ?></td>
                                <td><code><?= Security::e($k['referencia_receta'] ?? '-') ?></code></td>
                                <td><?= Security::e($k['usuario_ejecutor'] ?? 'Sistema') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($esAdminCatalogo): ?>
<!-- MODAL 1: Alta de Nuevo Medicamento en Catálogo Maestro (RF-FAR-01) -->
<div class="modal fade" id="modalNuevoMedicamento" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="formNuevoMedicamento">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold"><i class="bi bi-capsule me-2 text-primary"></i>Registrar Medicamento en Catálogo Maestro (RF-FAR-01)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Código SKU Interno *</label>
                        <input type="text" class="form-control font-mono" name="codigo_sku" required placeholder="ej. MED-IBU-400">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Nombre Genérico *</label>
                        <input type="text" class="form-control" name="nombre_generico" required placeholder="ej. Ibuprofeno">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Nombre Comercial *</label>
                        <input type="text" class="form-control" name="nombre_comercial" required placeholder="ej. Motrin">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Principio Activo *</label>
                        <input type="text" class="form-control" name="principio_activo" required placeholder="ej. Ibuprofeno (AINE)">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Familia Alergénica Asociada (Para bloqueo RN-CLI-05)</label>
                        <select class="form-select" name="id_alergeno">
                            <option value="">-- Sin alérgeno específico --</option>
                            <?php foreach ($alergenos as $alg): ?>
                                <option value="<?= (int)$alg['id_alergeno'] ?>"><?= Security::e($alg['principio_activo_o_familia']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Forma Farmacéutica *</label>
                        <input type="text" class="form-control" name="forma_farmaceutica" required placeholder="ej. Cápsulas (Caja c/20)">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Concentración *</label>
                        <input type="text" class="form-control" name="concentracion" required placeholder="ej. 400 mg">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Stock Mínimo *</label>
                        <input type="number" class="form-control" name="stock_minimo" value="15" min="1" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar en Catálogo</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Recepción de Nuevo Lote de Inventario (RF-FAR-01) -->
<div class="modal fade" id="modalRecepcionarLote" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="formRecepcionarLote">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold"><i class="bi bi-box-seam me-2 text-primary"></i>Recepción de Lote en Inventario (RF-FAR-01)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small py-2 mb-3">
                    El ingreso de existencias se realiza exclusivamente por lote con fecha de caducidad vigente. El Trigger <code>trg_after_insert_lote_inventario</code> actualizará el stock y el Kárdex.
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Medicamento del Catálogo *</label>
                    <select class="form-select" name="id_medicamento" required>
                        <?php foreach ($medicamentos as $m): ?>
                            <option value="<?= (int)$m['id_medicamento'] ?>">
                                [<?= Security::e($m['codigo_sku']) ?>] <?= Security::e($m['nombre_generico']) ?> (<?= Security::e($m['concentracion']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Número de Lote del Fabricante *</label>
                    <input type="text" class="form-control font-mono" name="numero_lote" required placeholder="ej. LOT-AZI-2026B">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Fecha de Fabricación *</label>
                        <input type="date" class="form-control" name="fecha_fabricacion" value="<?= date('Y-m-d', strtotime('-30 days')) ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold">Fecha de Caducidad *</label>
                        <input type="date" class="form-control" name="fecha_caducidad" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Cantidad Inicial Recibida (Unidades) *</label>
                    <input type="number" class="form-control" name="cantidad_inicial" min="1" value="50" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Ingresar Lote y Registrar en Kárdex</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="/assets/js/farmacia.js"></script>
<?php require_once __DIR__ . '/views/layout/footer.php'; ?>
