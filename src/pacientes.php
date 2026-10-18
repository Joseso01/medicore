<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/controllers/PacienteController.php';

use MediCore\Core\Security;
use MediCore\Core\AuthMiddleware;
use MediCore\Controllers\PacienteController;

AuthMiddleware::requirePermission('PAC', 'leer', false);

$controller = new PacienteController();
$resultadoInicial = $controller->buscarPacientes('', 1, 15);
$pacientes = $resultadoInicial['pacientes'];
$catalogoAlergias = $controller->listarCatalogoAlergias();

$idRolActual = (int)($_SESSION['id_rol'] ?? 0);
$puedeCrear = AuthMiddleware::can('PAC', 'crear');
$puedeActualizar = AuthMiddleware::can('PAC', 'actualizar');

$tituloPagina = 'Gestión de Pacientes (OPD) y Alertas de Alergias — MediCore Systems';
$moduloActivo = 'PAC';
require_once __DIR__ . '/views/layout/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-person-vcard text-primary me-2"></i>Padrón Hospitalario de Pacientes (OPD)</h3>
        <p class="text-muted small mb-0">
            Cumplimiento de <code>RF-PAC-01</code> (MRN único <code>MCS-YYYY-XXXXXX</code>), <code>RF-PAC-02</code> (Alertas de Alergias), <code>RF-PAC-03</code> (Búsqueda Indexada &lt; 200 ms) y <code>RF-PAC-04</code> (Inmutabilidad Posconsulta).
        </p>
    </div>
    <?php if ($puedeCrear): ?>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoPaciente">
            <i class="bi bi-person-plus-fill me-1"></i>Empadronar Nuevo Paciente (RF-PAC-01)
        </button>
    <?php endif; ?>
</div>

<div id="alertaPacientes" class="alert d-none align-items-center justify-content-between" role="alert">
    <span id="alertaPacientesTexto"></span>
    <button type="button" class="btn-close" onclick="document.getElementById('alertaPacientes').classList.add('d-none')"></button>
</div>

<!-- Buscador Asíncrono Multicriterio Indexado con Debounce de 300 ms (RF-PAC-03) -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-3">
        <div class="row g-2 align-items-center">
            <div class="col-md-9">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-primary"></i></span>
                    <input type="search" id="inputBuscadorPacientes" class="form-control"
                           placeholder="Buscar en tiempo real por MRN (MCS-2026-...), CURP/DNI o Apellidos y Nombres (Debounce 300 ms — RF-PAC-03)...">
                </div>
            </div>
            <div class="col-md-3 text-md-end small text-muted">
                <div>Total encontrados: <strong id="lblTotalPacientes"><?= (int)$resultadoInicial['total'] ?></strong></div>
                <div>Latencia MySQL/PDO: <span class="badge bg-success-subtle text-success border font-mono" id="lblLatenciaMs"><?= Security::e((string)$resultadoInicial['tiempo_ms']) ?> ms</span></div>
            </div>
        </div>
    </div>
</div>

<!-- Tabla Principal de Expedientes OPD -->
<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small" id="tablaPacientes"
               data-rol="<?= $idRolActual ?>"
               data-puede-editar="<?= $puedeActualizar ? '1' : '0' ?>">
            <thead class="table-light">
                <tr>
                    <th>MRN</th>
                    <th>Paciente / Identidad</th>
                    <th>Edad / Sexo</th>
                    <th>Grupo Rh</th>
                    <th>Alertas Clínicas de Alergias (RF-PAC-02)</th>
                    <th>Contacto y Emergencia</th>
                    <th>Consultas Cerradas</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody id="tbodyPacientes">
                <?php foreach ($pacientes as $p): ?>
                    <tr>
                        <td><code class="fw-bold fs-6 text-primary"><?= Security::e($p['mrn']) ?></code></td>
                        <td>
                            <div class="fw-bold"><?= Security::e($p['nombre_completo']) ?></div>
                            <div class="text-muted font-mono">ID: <?= Security::e($p['documento_identidad']) ?></div>
                        </td>
                        <td>
                            <div><?= (int)$p['edad'] ?> años (<?= Security::e($p['fecha_nacimiento']) ?>)</div>
                            <span class="text-muted"><?= Security::e($p['sexo_biologico']) ?></span>
                        </td>
                        <td>
                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle fs-6">
                                <?= Security::e($p['grupo_sanguineo']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (empty($p['alergias'])): ?>
                                <span class="badge bg-success-subtle text-success border">Sin alergias registradas</span>
                            <?php else: ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($p['alergias'] as $alg): ?>
                                        <span class="badge bg-danger" title="<?= Security::e($alg['reaccion_descrita'] ?? '') ?>">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                            <?= Security::e($alg['principio_activo_o_familia']) ?> (<?= Security::e($alg['severidad']) ?>)
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><i class="bi bi-telephone me-1"></i><?= Security::e($p['telefono'] ?? 'N/D') ?></div>
                            <?php if (!empty($p['nombre_contacto'])): ?>
                                <div class="text-muted">
                                    <strong>Emergencia:</strong> <?= Security::e($p['nombre_contacto']) ?> (<?= Security::e($p['parentesco']) ?>: <?= Security::e($p['telefono_emergencia']) ?>)
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$p['consultas_cerradas'] > 0): ?>
                                <span class="badge bg-secondary" title="RF-PAC-04: Campos críticos de identidad protegidos por inmutabilidad">
                                    <i class="bi bi-lock-fill me-1"></i><?= (int)$p['consultas_cerradas'] ?> cerrada(s)
                                </span>
                            <?php else: ?>
                                <span class="text-muted">0 cerradas</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-danger" title="Registrar Alerta de Alergia Farmacológica (RF-PAC-02)"
                                        data-id-paciente="<?= (int)$p['id_paciente'] ?>"
                                        data-mrn="<?= Security::e($p['mrn']) ?>"
                                        data-nombre="<?= Security::e($p['nombre_completo']) ?>"
                                        onclick="abrirModalAlergia(Number(this.dataset.idPaciente), this.dataset.mrn, this.dataset.nombre)">
                                    <i class="bi bi-bandaid" aria-hidden="true"></i> Alergias
                                </button>
                                <?php if ($puedeActualizar): ?>
                                    <button type="button" class="btn btn-outline-primary" title="Actualizar Datos / Contacto (RF-PAC-04)"
                                            onclick="abrirModalEditarPaciente(<?= (int)$p['id_paciente'] ?>)">
                                        <i class="bi bi-pencil-square"></i> Editar
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL 1: Empadronar Nuevo Paciente (RF-PAC-01) -->
<div class="modal fade" id="modalNuevoPaciente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="formNuevoPaciente">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-2 text-primary"></i>Empadronamiento Único de Paciente (RF-PAC-01)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small py-2 mb-3">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    El sistema asignará de forma determinista y transaccional un <strong>Número de Expediente Médico (MRN)</strong> con formato <code>MCS-<?= date('Y') ?>-XXXXXX</code>.
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nombre(s) *</label>
                        <input type="text" class="form-control" name="nombres" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Apellidos *</label>
                        <input type="text" class="form-control" name="apellidos" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Documento Oficial (CURP/DNI/Pasaporte) *</label>
                        <input type="text" class="form-control font-mono" name="documento_identidad" required placeholder="Único en el padrón">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Fecha de Nacimiento *</label>
                        <input type="date" class="form-control" name="fecha_nacimiento" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Sexo Biológico *</label>
                        <select class="form-select" name="sexo_biologico" required>
                            <option value="MASCULINO">MASCULINO</option>
                            <option value="FEMENINO">FEMENINO</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Grupo y Rh *</label>
                        <select class="form-select" name="grupo_sanguineo" required>
                            <?php foreach (['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'] as $rh): ?>
                                <option value="<?= $rh ?>"><?= $rh ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Teléfono de Contacto</label>
                        <input type="text" class="form-control" name="telefono" placeholder="ej. 555-123-4567">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Correo Electrónico</label>
                        <input type="email" class="form-control" name="email" placeholder="paciente@correo.mx">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Domicilio Completo</label>
                        <input type="text" class="form-control" name="direccion" placeholder="Calle, Número, Colonia, Ciudad">
                    </div>
                </div>

                <hr class="my-3">
                <h6 class="fw-bold text-primary mb-2"><i class="bi bi-telephone-inbound me-1"></i>Contacto de Emergencia Obligatorio (RF-PAC-01)</h6>
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Nombre del Contacto *</label>
                        <input type="text" class="form-control" name="nombre_contacto" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Parentesco *</label>
                        <input type="text" class="form-control" name="parentesco" required placeholder="Madre, Esposo(a), etc.">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Teléfono de Emergencia *</label>
                        <input type="text" class="form-control" name="telefono_emergencia" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Generar MRN y Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Actualizar Paciente e Inmutabilidad de Identidad (RF-PAC-04) -->
<div class="modal fade" id="modalEditarPaciente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="formEditarPaciente">
            <input type="hidden" name="id_paciente" id="edit_id_paciente">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-pencil-square me-2 text-primary"></i>Actualización de Expediente <code id="edit_mrn_display"></code> (RF-PAC-04)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="avisoInmutabilidad" class="alert alert-warning small py-2 d-none">
                    <i class="bi bi-shield-exclamation me-1"></i>
                    <strong>RF-PAC-04 (Inmutabilidad Posconsulta):</strong> Este paciente ya cuenta con consultas clínicas cerradas.
                    <?php if ($idRolActual !== 1): ?>
                        Su perfil (<strong>Recepcionista</strong>) tiene bloqueada la edición de identidad crítica; solo puede actualizar datos de contacto.
                    <?php else: ?>
                        Como <strong>Administrador</strong>, cualquier corrección sobre campos críticos requiere capturar un motivo obligatorio en bitácora.
                    <?php endif; ?>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nombre(s) *</label>
                        <input type="text" class="form-control campo-critico" name="nombres" id="edit_nombres" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Apellidos *</label>
                        <input type="text" class="form-control campo-critico" name="apellidos" id="edit_apellidos" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Documento Oficial *</label>
                        <input type="text" class="form-control font-mono campo-critico" name="documento_identidad" id="edit_documento" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Fecha de Nacimiento *</label>
                        <input type="date" class="form-control campo-critico" name="fecha_nacimiento" id="edit_fecha_nacimiento" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Sexo *</label>
                        <select class="form-select campo-critico" name="sexo_biologico" id="edit_sexo" required>
                            <option value="MASCULINO">MASCULINO</option>
                            <option value="FEMENINO">FEMENINO</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Grupo Rh *</label>
                        <select class="form-select campo-critico" name="grupo_sanguineo" id="edit_sangre" required>
                            <?php foreach (['O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'] as $rh): ?>
                                <option value="<?= $rh ?>"><?= $rh ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Teléfono de Contacto</label>
                        <input type="text" class="form-control" name="telefono" id="edit_telefono">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Correo Electrónico</label>
                        <input type="email" class="form-control" name="email" id="edit_email">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Domicilio Completo</label>
                        <input type="text" class="form-control" name="direccion" id="edit_direccion">
                    </div>

                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Contacto de Emergencia *</label>
                        <input type="text" class="form-control" name="nombre_contacto" id="edit_nombre_contacto" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Parentesco *</label>
                        <input type="text" class="form-control" name="parentesco" id="edit_parentesco" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Teléfono Emergencia *</label>
                        <input type="text" class="form-control" name="telefono_emergencia" id="edit_tel_emergencia" required>
                    </div>

                    <div class="col-md-12" id="contenedorMotivoAuditoria">
                        <label class="form-label small fw-semibold text-danger">Motivo de Corrección para Bitácora Forense (RF-PAC-04)</label>
                        <input type="text" class="form-control" name="motivo_auditoria" id="edit_motivo_auditoria"
                               placeholder="Obligatorio si el Administrador corrige campos críticos de un paciente con consultas cerradas">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar Cambios Auditados</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: Asociar Alerta de Alergia Farmacológica (RF-PAC-02 / RN-CLI-05) -->
<div class="modal fade" id="modalAlergiaPaciente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="formAlergiaPaciente">
            <input type="hidden" name="id_paciente" id="alg_id_paciente">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle-fill me-2"></i>Registrar Alergia Farmacológica (RF-PAC-02)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    Paciente: <strong id="alg_paciente_nombre"></strong> (<code id="alg_paciente_mrn"></code>).<br>
                    Las alergias <code>MODERADA</code> o <code>ANAFILAXIA_SEVERA</code> bloquearán automáticamente la prescripción en receta (<strong>RN-CLI-05</strong>).
                </p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Principio Activo o Familia Alergénica *</label>
                    <select class="form-select" name="id_alergeno" required>
                        <?php foreach ($catalogoAlergias as $alg): ?>
                            <option value="<?= (int)$alg['id_alergeno'] ?>">
                                <?= Security::e($alg['principio_activo_o_familia']) ?> (<?= Security::e($alg['codigo_alergeno']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Nivel de Severidad *</label>
                    <select class="form-select" name="severidad" required>
                        <option value="ANAFILAXIA_SEVERA">ANAFILAXIA_SEVERA (Bloqueo estricto RN-CLI-05)</option>
                        <option value="MODERADA">MODERADA (Bloqueo estricto RN-CLI-05)</option>
                        <option value="LEVE">LEVE (Alerta preventiva)</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Fecha de Detección *</label>
                    <input type="date" class="form-control" name="fecha_deteccion" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Reacción Clínica Documentada</label>
                    <textarea class="form-control" name="reaccion_descrita" rows="2" placeholder="ej. Broncoespasmo, urticaria generalizada, angioedema..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-shield-fill-exclamation me-1"></i>Vincular Alerta al Expediente</button>
            </div>
        </form>
    </div>
</div>

<script src="/assets/js/pacientes.js"></script>
<?php require_once __DIR__ . '/views/layout/footer.php'; ?>
