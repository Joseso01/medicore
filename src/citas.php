<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/controllers/CitaController.php';
require_once __DIR__ . '/controllers/PacienteController.php';

use MediCore\Core\Security;
use MediCore\Core\AuthMiddleware;
use MediCore\Controllers\CitaController;
use MediCore\Controllers\PacienteController;

AuthMiddleware::requirePermission('CIT', 'leer', false);

$controller = new CitaController();
$pacienteCtrl = new PacienteController();

$idRolActual = (int)($_SESSION['id_rol'] ?? 0);
$idMedicoSesion = isset($_SESSION['id_medico']) ? (int)$_SESSION['id_medico'] : null;

$catalogos = $controller->obtenerCatalogos();
$resultadoPacientes = $pacienteCtrl->buscarPacientes('', 1, 100);
$pacientes = $resultadoPacientes['pacientes'];

$fechaHoy = date('Y-m-d');
$citasIniciales = $controller->listarAgenda(['fecha' => $fechaHoy], $idRolActual, $idMedicoSesion);

$puedeCrear = AuthMiddleware::can('CIT', 'crear');
$puedeActualizar = AuthMiddleware::can('CIT', 'actualizar');

$tituloPagina = 'Agenda y Control de Citas Médicas — MediCore Systems';
$moduloActivo = 'CIT';
require_once __DIR__ . '/views/layout/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-calendar2-check text-primary me-2"></i>Agenda y Control de Citas Médicas (CIT)</h3>
        <p class="text-muted small mb-0">
            Cumplimiento de <code>RF-CIT-01</code> a <code>RF-CIT-04</code>, <code>RN-CLI-01</code> (No Empalme Horario con Bloqueo Pesimista) y <code>RN-CLI-06</code> (Segregación Clínica).
        </p>
    </div>
    <?php if ($puedeCrear): ?>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalProgramarCita">
            <i class="bi bi-calendar-plus me-1"></i>Programar Cita (RF-CIT-01)
        </button>
    <?php endif; ?>
</div>

<div id="alertaCitas" class="alert d-none align-items-center justify-content-between" role="alert">
    <span id="alertaCitasTexto"></span>
    <button type="button" class="btn-close" onclick="document.getElementById('alertaCitas').classList.add('d-none')"></button>
</div>

<!-- Barra de Filtros Interactivos (RF-CIT-04) -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body py-3">
        <form id="formFiltrosCitas" class="row g-2 align-items-end" onsubmit="event.preventDefault(); aplicarFiltrosCitas();">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Fecha de la Agenda</label>
                <div class="input-group input-group-sm">
                    <input type="date" id="filtroFecha" class="form-control" value="<?= Security::e($fechaHoy) ?>">
                    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('filtroFecha').value='<?= $fechaHoy ?>'; aplicarFiltrosCitas();">Hoy</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('filtroFecha').value='TODAS'; aplicarFiltrosCitas();">Todas</button>
                </div>
            </div>

            <?php if ($idRolActual !== 2): ?>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Especialidad</label>
                    <select id="filtroEspecialidad" class="form-select form-select-sm" onchange="filtrarMedicosPorEspecialidad(); aplicarFiltrosCitas();">
                        <option value="">-- Todas las especialidades --</option>
                        <?php foreach ($catalogos['especialidades'] as $esp): ?>
                            <option value="<?= (int)$esp['id_especialidad'] ?>"><?= Security::e($esp['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Médico Facultativo</label>
                    <select id="filtroMedico" class="form-select form-select-sm" onchange="aplicarFiltrosCitas();">
                        <option value="">-- Todos los médicos --</option>
                        <?php foreach ($catalogos['medicos'] as $med): ?>
                            <option value="<?= (int)$med['id_medico'] ?>" data-esp="<?= (int)$med['id_especialidad'] ?>">
                                <?= Security::e($med['medico_nombre']) ?> (<?= Security::e($med['especialidad_nombre']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Agenda Asignada (RF-CIT-04)</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light text-primary"><i class="bi bi-person-check-fill me-1"></i>Facultativo:</span>
                        <input type="text" class="form-control bg-light" value="<?= Security::e($_SESSION['nombre_completo']) ?>" readonly>
                    </div>
                </div>
            <?php endif; ?>

            <div class="col-md-2">
                <label class="form-label small fw-semibold">Estado</label>
                <select id="filtroEstado" class="form-select form-select-sm" onchange="aplicarFiltrosCitas();">
                    <option value="TODOS">-- Todos los estados --</option>
                    <option value="PROGRAMADA">PROGRAMADA</option>
                    <option value="CONFIRMADA">CONFIRMADA</option>
                    <option value="EN_CURSO">EN_CURSO</option>
                    <option value="ATENDIDA">ATENDIDA</option>
                    <option value="CANCELADA">CANCELADA</option>
                    <option value="NO_ASISTIO">NO_ASISTIO</option>
                </select>
            </div>

            <div class="col-md-1 text-end">
                <button type="submit" class="btn btn-outline-primary btn-sm w-100" title="Actualizar Agenda">
                    <i class="bi bi-arrow-repeat"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Tablero de Agenda Diaria -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">
            <i class="bi bi-clock-history text-primary me-2"></i>Citas Programadas en el Tablero
            <span class="badge bg-light text-dark border ms-2" id="lblTotalCitas"><?= count($citasIniciales) ?> registro(s)</span>
        </h6>
        <div class="small text-muted">
            <span class="badge bg-primary bg-opacity-10 text-primary me-1">PROGRAMADA / CONFIRMADA</span>
            <span class="badge bg-warning bg-opacity-25 text-dark me-1">EN_CURSO</span>
            <span class="badge bg-success bg-opacity-10 text-success me-1">ATENDIDA</span>
            <span class="badge bg-danger bg-opacity-10 text-danger">CANCELADA</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small" id="tablaCitas"
               data-rol="<?= $idRolActual ?>"
               data-id-medico="<?= $idMedicoSesion ?? 0 ?>"
               data-puede-editar="<?= $puedeActualizar ? '1' : '0' ?>">
            <thead class="table-light">
                <tr>
                    <th>Fecha / Horario</th>
                    <th>Expediente / Paciente</th>
                    <th>Médico / Especialidad</th>
                    <th>Consultorio</th>
                    <th>Motivo de Consulta</th>
                    <th>Estado de la Cita</th>
                    <th class="text-end">Acciones (RF-CIT-02 / 03)</th>
                </tr>
            </thead>
            <tbody id="tbodyCitas">
                <?php if (empty($citasIniciales)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No se encontraron citas para los criterios seleccionados.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($citasIniciales as $c): ?>
                        <tr>
                            <td>
                                <div class="fw-bold font-mono"><?= Security::e(substr((string)$c['hora_inicio'], 0, 5)) ?> - <?= Security::e(substr((string)$c['hora_fin'], 0, 5)) ?></div>
                                <div class="text-muted font-mono mc-fs-xs"><?= Security::e($c['fecha_cita']) ?></div>
                            </td>
                            <td>
                                <div><code class="fw-bold text-primary"><?= Security::e($c['mrn']) ?></code></div>
                                <div class="fw-semibold"><?= Security::e($c['paciente_nombre']) ?></div>
                                <div class="small">
                                    <span class="badge bg-danger bg-opacity-10 text-danger"><?= Security::e($c['grupo_sanguineo']) ?></span>
                                    <?php if ((int)$c['tiene_alergias_severas'] > 0): ?>
                                        <span class="badge bg-danger" title="Paciente con alergias severas registradas"><i class="bi bi-exclamation-triangle-fill"></i> Alergia Severa</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= Security::e($c['medico_nombre']) ?></div>
                                <span class="text-muted"><?= Security::e($c['especialidad_nombre']) ?></span>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">
                                    <i class="bi bi-door-open me-1"></i><?= Security::e($c['codigo_consultorio']) ?>
                                </span>
                            </td>
                            <td>
                                <div><?= Security::e($c['motivo_consulta']) ?></div>
                                <?php if ($c['estado'] === 'CANCELADA' && !empty($c['motivo_cancelacion'])): ?>
                                    <div class="text-danger small mt-1">
                                        <i class="bi bi-x-circle me-1"></i><strong>Motivo cancelación:</strong> <?= Security::e($c['motivo_cancelacion']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $badgeClass = match ($c['estado']) {
                                    'PROGRAMADA' => 'bg-secondary',
                                    'CONFIRMADA' => 'bg-primary',
                                    'EN_CURSO'   => 'bg-warning text-dark',
                                    'ATENDIDA'   => 'bg-success',
                                    'CANCELADA'  => 'bg-danger',
                                    'NO_ASISTIO' => 'bg-dark',
                                    default      => 'bg-secondary',
                                };
                                ?>
                                <span class="badge <?= $badgeClass ?> fs-7"><?= Security::e($c['estado']) ?></span>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <?php if ($idRolActual === 2 && in_array($c['estado'], ['PROGRAMADA', 'CONFIRMADA', 'EN_CURSO'], true)): ?>
                                        <!-- Botón para iniciar o continuar consulta médica en EHR (Fase 5) -->
                                        <a href="/consultas.php?id_cita=<?= (int)$c['id_cita'] ?>" class="btn btn-outline-success fw-semibold">
                                            <i class="bi bi-file-earmark-medical me-1"></i>Atender
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($puedeActualizar && in_array($c['estado'], ['PROGRAMADA', 'CONFIRMADA'], true)): ?>
                                        <?php if ($c['estado'] === 'PROGRAMADA'): ?>
                                            <button type="button" class="btn btn-outline-primary" title="Confirmar Cita"
                                                    onclick="cambiarEstadoCita(<?= (int)$c['id_cita'] ?>, 'CONFIRMADA')">
                                                Confirmar
                                            </button>
                                        <?php endif; ?>

                                        <button type="button" class="btn btn-outline-info" title="Reprogramar horario (RF-CIT-03)" aria-label="Reprogramar horario"
                                                data-id-cita="<?= (int)$c['id_cita'] ?>"
                                                data-fecha="<?= Security::e($c['fecha_cita']) ?>"
                                                data-hora="<?= Security::e($c['hora_inicio']) ?>"
                                                data-id-consultorio="<?= (int)$c['id_consultorio'] ?>"
                                                onclick="abrirModalReprogramar(Number(this.dataset.idCita), this.dataset.fecha, this.dataset.hora, Number(this.dataset.idConsultorio))">
                                            <i class="bi bi-clock-history" aria-hidden="true"></i>
                                        </button>

                                        <button type="button" class="btn btn-outline-warning text-dark" title="Marcar No Asistió"
                                                onclick="cambiarEstadoCita(<?= (int)$c['id_cita'] ?>, 'NO_ASISTIO')">
                                            No Asistió
                                        </button>

                                        <button type="button" class="btn btn-outline-danger" title="Cancelar con motivo obligatorio (RF-CIT-03)"
                                                onclick="abrirModalCancelar(<?= (int)$c['id_cita'] ?>)">
                                            Cancelar
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL 1: Programar Nueva Cita (RF-CIT-01) -->
<div class="modal fade" id="modalProgramarCita" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="formProgramarCita">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold"><i class="bi bi-calendar-plus me-2 text-primary"></i>Programación de Cita Médica (RF-CIT-01)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small py-2 mb-3">
                    <i class="bi bi-info-circle-fill me-1"></i>
                    Se validará en tiempo real la jornada laboral del médico y que no exista empalme horario con el paciente, el médico o el consultorio (<strong>RN-CLI-01</strong> con bloqueo <code>SELECT ... FOR UPDATE</code>).
                </div>
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Paciente Empadronado (OPD) *</label>
                        <select class="form-select select2-pacientes" name="id_paciente" required>
                            <option value="">-- Seleccionar Paciente --</option>
                            <?php foreach ($pacientes as $pac): ?>
                                <option value="<?= (int)$pac['id_paciente'] ?>">
                                    <?= Security::e($pac['mrn']) ?> — <?= Security::e($pac['nombre_completo']) ?> (ID: <?= Security::e($pac['documento_identidad']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Médico Facultativo *</label>
                        <select class="form-select" name="id_medico" id="selectMedicoCita" required>
                            <option value="">-- Seleccionar Médico --</option>
                            <?php foreach ($catalogos['medicos'] as $m): ?>
                                <option value="<?= (int)$m['id_medico'] ?>">
                                    <?= Security::e($m['medico_nombre']) ?> (<?= Security::e($m['especialidad_nombre']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Consultorio Físico Asignado *</label>
                        <select class="form-select" name="id_consultorio" required>
                            <option value="">-- Seleccionar Consultorio --</option>
                            <?php foreach ($catalogos['consultorios'] as $c): ?>
                                <option value="<?= (int)$c['id_consultorio'] ?>">
                                    <?= Security::e($c['codigo_consultorio']) ?> — <?= Security::e($c['nombre']) ?> (<?= Security::e($c['ubicacion']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Fecha de la Consulta *</label>
                        <input type="date" class="form-control" name="fecha_cita" value="<?= $fechaHoy ?>" min="<?= $fechaHoy ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Hora de Inicio *</label>
                        <input type="time" class="form-control" name="hora_inicio" value="09:00" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Duración Estimada *</label>
                        <select class="form-select" name="duracion_minutos" required>
                            <option value="15">15 minutos</option>
                            <option value="30" selected>30 minutos (Por defecto)</option>
                            <option value="45">45 minutos</option>
                            <option value="60">60 minutos</option>
                        </select>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Motivo de Consulta Médica *</label>
                        <textarea class="form-control" name="motivo_consulta" rows="2" placeholder="ej. Consulta de valoración de hipertensión arterial o sintomatología respiratoria" required></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-calendar-check me-1"></i>Validar Disponibilidad y Agendar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Cancelar Cita con Motivo Obligatorio (RF-CIT-03) -->
<div class="modal fade" id="modalCancelarCita" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="formCancelarCita">
            <input type="hidden" name="id_cita" id="cancelar_id_cita">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-calendar-x me-2"></i>Cancelar Cita Médica (RF-CIT-03)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    La cancelación liberará de inmediato el intervalo horario en la agenda para reasignarlo, preservando el registro histórico inmutable para fines de auditoría.
                </p>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Motivo Obligatorio de Cancelación *</label>
                    <textarea class="form-control" name="motivo_cancelacion" id="cancelar_motivo" rows="3"
                              placeholder="Especifique la causa (ej. Paciente canceló por imprevisto familiar, reprogramación solicitada...)" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Confirmar Cancelación</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: Reprogramar Cita (RF-CIT-03) -->
<div class="modal fade" id="modalReprogramarCita" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="formReprogramarCita">
            <input type="hidden" name="id_cita" id="reprog_id_cita">
            <div class="modal-header bg-info bg-opacity-10">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-clock-history me-2 text-info"></i>Reprogramar Cita Médica (RF-CIT-03)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nueva Fecha *</label>
                        <input type="date" class="form-control" name="fecha_cita" id="reprog_fecha" min="<?= $fechaHoy ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nueva Hora de Inicio *</label>
                        <input type="time" class="form-control" name="hora_inicio" id="reprog_hora" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Duración *</label>
                        <select class="form-select" name="duracion_minutos">
                            <option value="15">15 min</option>
                            <option value="30" selected>30 min</option>
                            <option value="45">45 min</option>
                            <option value="60">60 min</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Consultorio</label>
                        <select class="form-select" name="id_consultorio" id="reprog_consultorio">
                            <?php foreach ($catalogos['consultorios'] as $c): ?>
                                <option value="<?= (int)$c['id_consultorio'] ?>"><?= Security::e($c['codigo_consultorio']) ?> — <?= Security::e($c['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Reprogramar y Validar</button>
            </div>
        </form>
    </div>
</div>

<script src="/assets/js/citas.js"></script>
<?php require_once __DIR__ . '/views/layout/footer.php'; ?>
