<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/controllers/UsuarioController.php';

use MediCore\Core\Security;
use MediCore\Core\AuthMiddleware;
use MediCore\Controllers\UsuarioController;

// RF-SEG-02 & RF-SEG-03: Exclusivo para el rol Administrador
AuthMiddleware::requirePermission('SEG', 'leer', false);

$controller = new UsuarioController();
$usuarios   = $controller->listarUsuarios();
$catalogos  = $controller->obtenerCatalogos();
$bitacora   = $controller->listarBitacora(40);

$tituloPagina = 'Seguridad, Usuarios y Auditoría Forense — MediCore Systems';
$moduloActivo = 'SEG';
require_once __DIR__ . '/views/layout/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-shield-lock text-primary me-2"></i>Módulo de Seguridad, RBAC y Auditoría Forense</h3>
        <p class="text-muted small mb-0">
            Cumplimiento de <code>RF-SEG-01</code> a <code>RF-SEG-04</code>, <code>RBD-INT-05</code> y <code>RNF-SEG-01</code> (Sin borrado físico; baja lógica con revocación inmediata de sesiones).
        </p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoUsuario">
        <i class="bi bi-person-plus-fill me-1"></i>Registrar Nuevo Usuario
    </button>
</div>

<!-- Contenedor de Alertas Dinámicas Vanilla JS -->
<div id="alertaSeguridad" class="alert d-none align-items-center justify-content-between" role="alert">
    <span id="alertaSeguridadTexto"></span>
    <button type="button" class="btn-close" onclick="document.getElementById('alertaSeguridad').classList.add('d-none')"></button>
</div>

<!-- Pestañas: 1. Ciclo de Vida de Usuarios | 2. Bitácora Forense Inmutable -->
<ul class="nav nav-tabs mb-3" id="seguridadTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active fw-semibold" id="usuarios-tab" data-bs-toggle="tab" data-bs-target="#paneUsuarios" type="button" role="tab">
            <i class="bi bi-people-fill me-1"></i>Cuentas de Usuario y Estado Operativo (<span id="contadorUsuarios"><?= count($usuarios) ?></span>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-semibold" id="bitacora-tab" data-bs-toggle="tab" data-bs-target="#paneBitacora" type="button" role="tab" onclick="recargarBitacora()">
            <i class="bi bi-journal-code me-1"></i>Bitácora de Auditoría Forense (<code>bitacora_auditoria</code>)
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- TAB 1: GESTIÓN DE USUARIOS -->
    <div class="tab-pane fade show active" id="paneUsuarios" role="tabpanel">
        <div class="card shadow-sm border-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small" id="tablaUsuarios">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Usuario / Correo</th>
                            <th>Nombre Completo</th>
                            <th>Rol RBAC</th>
                            <th>Datos Facultativo (Médico)</th>
                            <th>Sesiones Activas</th>
                            <th>Fuerza Bruta</th>
                            <th>Estado Operativo</th>
                            <th class="text-end">Acciones (RF-SEG-03 / 04)</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyUsuarios">
                        <?php foreach ($usuarios as $u): ?>
                            <tr data-id="<?= (int)$u['id_usuario'] ?>">
                                <td class="font-mono">#<?= (int)$u['id_usuario'] ?></td>
                                <td>
                                    <div class="fw-bold"><?= Security::e($u['username']) ?></div>
                                    <div class="text-muted"><?= Security::e($u['email']) ?></div>
                                </td>
                                <td><?= Security::e($u['nombre_completo']) ?></td>
                                <td>
                                    <span class="badge bg-info bg-opacity-10 text-dark border">
                                        <?= Security::e($u['rol_nombre']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($u['cedula_profesional'])): ?>
                                        <div><strong>Cédula:</strong> <code><?= Security::e($u['cedula_profesional']) ?></code></div>
                                        <div class="text-muted"><?= Security::e($u['especialidad_nombre']) ?> | ID: <?= Security::e($u['doc_medico']) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted">— No aplica —</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)$u['sesiones_activas'] > 0): ?>
                                        <span class="badge bg-success"><?= (int)$u['sesiones_activas'] ?> activa(s)</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-25 text-secondary">0 activas</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int)$u['bloqueado_activo'] === 1): ?>
                                        <span class="badge bg-danger">BLOQUEADO (<?= Security::e($u['bloqueado_hasta']) ?>)</span>
                                    <?php elseif ((int)$u['intentos_fallidos'] > 0): ?>
                                        <span class="badge bg-warning text-dark"><?= (int)$u['intentos_fallidos'] ?>/5 intentos</span>
                                    <?php else: ?>
                                        <span class="text-success"><i class="bi bi-check-circle me-1"></i>Limpio</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $estadoBadge = match ($u['estado']) {
                                        'ACTIVO'      => 'bg-success',
                                        'SUSPENDIDO'  => 'bg-warning text-dark',
                                        'BAJA_LOGICA' => 'bg-danger',
                                        default       => 'bg-secondary',
                                    };
                                    ?>
                                    <span class="badge <?= $estadoBadge ?>"><?= Security::e($u['estado']) ?></span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <?php if ((int)$u['bloqueado_activo'] === 1 || (int)$u['intentos_fallidos'] > 0): ?>
                                            <button type="button" class="btn btn-outline-info" title="Restablecer bloqueo por fuerza bruta (RF-SEG-04)"
                                                    onclick="desbloquearUsuario(<?= (int)$u['id_usuario'] ?>)">
                                                <i class="bi bi-unlock"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($u['estado'] !== 'ACTIVO'): ?>
                                            <button type="button" class="btn btn-outline-success" title="Reactivar cuenta"
                                                    onclick="cambiarEstadoUsuario(<?= (int)$u['id_usuario'] ?>, 'ACTIVO')">
                                                Activar
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($u['estado'] === 'ACTIVO' && (int)$u['id_usuario'] !== (int)$_SESSION['id_usuario']): ?>
                                            <button type="button" class="btn btn-outline-warning" title="Suspender temporalmente y cerrar sesiones"
                                                    onclick="cambiarEstadoUsuario(<?= (int)$u['id_usuario'] ?>, 'SUSPENDIDO')">
                                                Suspender
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($u['estado'] !== 'BAJA_LOGICA' && (int)$u['id_usuario'] !== (int)$_SESSION['id_usuario']): ?>
                                            <button type="button" class="btn btn-outline-danger" title="Baja lógica (RF-SEG-03: preserva integridad referencial)"
                                                    onclick="cambiarEstadoUsuario(<?= (int)$u['id_usuario'] ?>, 'BAJA_LOGICA')">
                                                Baja Lógica
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
    </div>

    <!-- TAB 2: BITÁCORA DE AUDITORÍA FORENSE -->
    <div class="tab-pane fade" id="paneBitacora" role="tabpanel">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">Registro Inmutable de Eventos y Triggers <code>AFTER UPDATE</code> (JSON_OBJECT)</h6>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="recargarBitacora()">
                    <i class="bi bi-arrow-clockwise me-1"></i>Actualizar Bitácora
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Evento #</th>
                            <th>Fecha / Hora</th>
                            <th>Tabla / Módulo</th>
                            <th>Operación</th>
                            <th>Reg. ID</th>
                            <th>Ejecutor / IP</th>
                            <th>Motivo / Detalle</th>
                            <th>Imagen Anterior (OLD) / Posterior (NEW)</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyBitacora">
                        <?php foreach ($bitacora as $ev): ?>
                            <tr>
                                <td class="font-mono">#<?= (int)$ev['id_evento'] ?></td>
                                <td class="font-mono"><?= Security::e($ev['fecha_evento']) ?></td>
                                <td><code><?= Security::e($ev['tabla_afectada']) ?></code></td>
                                <td><span class="badge bg-dark"><?= Security::e($ev['operacion']) ?></span></td>
                                <td class="font-mono"><?= Security::e($ev['id_registro_afectado'] ?? '-') ?></td>
                                <td>
                                    <div><?= Security::e($ev['usuario_ejecutor'] ?? 'Trigger MySQL') ?></div>
                                    <span class="text-muted font-mono"><?= Security::e($ev['ip_origen'] ?? 'motor-innodb') ?></span>
                                </td>
                                <td><?= Security::e($ev['motivo'] ?? 'Auditoría automática por Trigger RBD-INT-05') ?></td>
                                <td class="font-mono mc-cell-json">
                                    <?php if (!empty($ev['valores_anteriores'])): ?>
                                        <div class="text-danger text-truncate" title="<?= Security::e($ev['valores_anteriores']) ?>">
                                            <strong>OLD:</strong> <?= Security::e($ev['valores_anteriores']) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($ev['valores_nuevos'])): ?>
                                        <div class="text-success text-truncate" title="<?= Security::e($ev['valores_nuevos']) ?>">
                                            <strong>NEW:</strong> <?= Security::e($ev['valores_nuevos']) ?>
                                        </div>
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

<!-- Modal para Crear Nuevo Usuario Institucional (RF-SEG-03) -->
<div class="modal fade" id="modalNuevoUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="formNuevoUsuario">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-2 text-primary"></i>Alta de Usuario Institucional (RF-SEG-03)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Nombre de Usuario (Username) *</label>
                        <input type="text" class="form-control" name="username" required placeholder="ej. dr.castillo">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Correo Institucional *</label>
                        <input type="email" class="form-control" name="email" required placeholder="ej. r.castillo@medicore.hospital.org">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small fw-semibold">Nombre Completo *</label>
                        <input type="text" class="form-control" name="nombre_completo" required placeholder="ej. Dr. Ricardo Castillo Morales">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Rol RBAC *</label>
                        <select class="form-select" name="id_rol" id="selectRolUsuario" required onchange="toggleCamposMedico()">
                            <?php foreach ($catalogos['roles'] as $rol): ?>
                                <option value="<?= (int)$rol['id_rol'] ?>"><?= Security::e($rol['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Contraseña Inicial (Se hasheará con Argon2id / Bcrypt cost &ge; 12) *</label>
                        <input type="password" class="form-control" name="password" minlength="8" required placeholder="Mínimo 8 caracteres">
                    </div>
                </div>

                <!-- Sección adicional cuando el rol seleccionado es Médico (id_rol = 2) -->
                <div id="seccionDatosMedico" class="border rounded p-3 mt-3 bg-light d-none">
                    <h6 class="fw-bold text-primary mb-2"><i class="bi bi-heart-pulse me-1"></i>Vinculación Obligatoria de Facultativo (RF-SEG-03 / RN-CLI-06)</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Cédula Profesional *</label>
                            <input type="text" class="form-control" name="cedula_profesional" placeholder="ej. CED-5544332">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Documento Oficial de Identidad (CURP/DNI) *</label>
                            <input type="text" class="form-control" name="documento_identidad" placeholder="Para validación anti-autoprescripción RN-CLI-06">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Especialidad Médica *</label>
                            <select class="form-select" name="id_especialidad">
                                <?php foreach ($catalogos['especialidades'] as $esp): ?>
                                    <option value="<?= (int)$esp['id_especialidad'] ?>"><?= Security::e($esp['nombre']) ?> (<?= Security::e($esp['codigo']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Consultorio Base Asignado *</label>
                            <select class="form-select" name="id_consultorio">
                                <?php foreach ($catalogos['consultorios'] as $con): ?>
                                    <option value="<?= (int)$con['id_consultorio'] ?>"><?= Security::e($con['codigo_consultorio']) ?> — <?= Security::e($con['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>Guardar Usuario
                </button>
            </div>
        </form>
    </div>
</div>

<script src="/assets/js/seguridad.js"></script>
<?php require_once __DIR__ . '/views/layout/footer.php'; ?>
