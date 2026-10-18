<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';

use MediCore\Config\Database;
use MediCore\Core\Security;
use MediCore\Core\AuthMiddleware;

AuthMiddleware::requireAuth(false);
if (!Security::verifyActiveDatabaseSession()) {
    header('Location: /login.php?revoked=1');
    exit;
}

$pdo = Database::getConnection();
$idRol = (int)$_SESSION['id_rol'];
$idMedico = isset($_SESSION['id_medico']) ? (int)$_SESSION['id_medico'] : null;

// KPIs operativos en tiempo real desde MySQL 8.0 (InnoDB)
$totalPacientes = (int)$pdo->query("SELECT COUNT(*) FROM pacientes WHERE estado = 'ACTIVO'")->fetchColumn();

if ($idRol === 2 && $idMedico !== null) {
    $stmtCitasHoy = $pdo->prepare(
        "SELECT COUNT(*) FROM citas_medicas WHERE fecha_cita = CURDATE() AND id_medico = :med AND estado NOT IN ('CANCELADA', 'NO_ASISTIO')"
    );
    $stmtCitasHoy->execute([':med' => $idMedico]);
    $citasHoy = (int)$stmtCitasHoy->fetchColumn();
} else {
    $citasHoy = (int)$pdo->query(
        "SELECT COUNT(*) FROM citas_medicas WHERE fecha_cita = CURDATE() AND estado NOT IN ('CANCELADA', 'NO_ASISTIO')"
    )->fetchColumn();
}

$recetasPendientes = (int)$pdo->query(
    "SELECT COUNT(*) FROM recetas_medicas WHERE estado_receta IN ('PENDIENTE_SURTIR', 'SURTIDA_PARCIAL')"
)->fetchColumn();

$alertasStockCritico = (int)$pdo->query(
    "SELECT COUNT(*) FROM medicamentos WHERE activo = 1 AND stock_global_actual <= stock_minimo"
)->fetchColumn();

// Citas programadas del día en curso
if ($idRol === 2 && $idMedico !== null) {
    $stmtAgenda = $pdo->prepare(
        "SELECT c.id_cita, c.hora_inicio, c.hora_fin, c.motivo_consulta, c.estado,
                p.mrn, p.nombre_completo AS paciente_nombre, p.grupo_sanguineo,
                u.nombre_completo AS medico_nombre, con.codigo_consultorio
         FROM citas_medicas c
         INNER JOIN pacientes p ON p.id_paciente = c.id_paciente
         INNER JOIN medicos m ON m.id_medico = c.id_medico
         INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
         INNER JOIN consultorios con ON con.id_consultorio = c.id_consultorio
         WHERE c.fecha_cita = CURDATE() AND c.id_medico = :med
         ORDER BY c.hora_inicio ASC"
    );
    $stmtAgenda->execute([':med' => $idMedico]);
    $agendaHoy = $stmtAgenda->fetchAll();
} else {
    $agendaHoy = $pdo->query(
        "SELECT c.id_cita, c.hora_inicio, c.hora_fin, c.motivo_consulta, c.estado,
                p.mrn, p.nombre_completo AS paciente_nombre, p.grupo_sanguineo,
                u.nombre_completo AS medico_nombre, con.codigo_consultorio
         FROM citas_medicas c
         INNER JOIN pacientes p ON p.id_paciente = c.id_paciente
         INNER JOIN medicos m ON m.id_medico = c.id_medico
         INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
         INNER JOIN consultorios con ON con.id_consultorio = c.id_consultorio
         WHERE c.fecha_cita = CURDATE()
         ORDER BY c.hora_inicio ASC"
    )->fetchAll();
}

$tituloPagina = 'Tablero Operativo — MediCore Systems';
$moduloActivo = 'DASHBOARD';
require_once __DIR__ . '/views/layout/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">Tablero de Control Hospitalario</h3>
        <p class="text-muted small mb-0">
            Bienvenido, <strong><?= Security::e($_SESSION['nombre_completo']) ?></strong>
            — Perfil RBAC activo: <span class="badge bg-primary"><?= Security::e($_SESSION['rol_nombre']) ?></span>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if (AuthMiddleware::can('SEG', 'leer')): ?>
            <a href="/usuarios.php" class="btn btn-primary btn-sm">
                <i class="bi bi-shield-lock me-1"></i>Gestión de Usuarios y Auditoría (RF-SEG)
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Tarjetas de Indicadores Operativos en Tiempo Real -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card card-kpi h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small text-uppercase fw-semibold">Padrón OPD Activo</div>
                    <div class="fs-2 fw-bold text-dark"><?= $totalPacientes ?></div>
                    <div class="small text-secondary">Expedientes MRN únicos</div>
                </div>
                <i class="bi bi-people fs-1 text-primary opacity-50"></i>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card card-kpi card-kpi--success h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small text-uppercase fw-semibold">Citas Activas Hoy</div>
                    <div class="fs-2 fw-bold text-success"><?= $citasHoy ?></div>
                    <div class="small text-secondary"><?= $idRol === 2 ? 'Asignadas a su agenda' : 'Total en consultorios' ?></div>
                </div>
                <i class="bi bi-calendar2-check fs-1 text-success opacity-50"></i>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card card-kpi card-kpi--info h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small text-uppercase fw-semibold">Recetas por Surtir</div>
                    <div class="fs-2 fw-bold text-info"><?= $recetasPendientes ?></div>
                    <div class="small text-secondary">En ventanilla FEFO</div>
                </div>
                <i class="bi bi-prescription2 fs-1 text-info opacity-50"></i>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card card-kpi card-kpi--danger h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small text-uppercase fw-semibold">Alertas Stock Crítico</div>
                    <div class="fs-2 fw-bold text-danger"><?= $alertasStockCritico ?></div>
                    <div class="small text-secondary">Fármacos &le; Stock mínimo</div>
                </div>
                <i class="bi bi-exclamation-octagon fs-1 text-danger opacity-50"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0"><i class="bi bi-clock-history text-primary me-2"></i>Agenda de Citas del Día (<?= Security::e(date('Y-m-d')) ?>)</h6>
                <span class="badge bg-light text-dark border"><?= count($agendaHoy) ?> registros</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Horario</th>
                            <th>MRN</th>
                            <th>Paciente</th>
                            <th>Médico / Consultorio</th>
                            <th>Motivo</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($agendaHoy)): ?>
                            <tr>
                                <td colspan="6" class="mc-empty-state"><i class="bi bi-calendar-x" aria-hidden="true"></i>No hay citas registradas para el día de hoy.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($agendaHoy as $cita): ?>
                                <tr>
                                    <td class="fw-semibold font-mono">
                                        <?= Security::e(substr((string)$cita['hora_inicio'], 0, 5)) ?> - <?= Security::e(substr((string)$cita['hora_fin'], 0, 5)) ?>
                                    </td>
                                    <td><code class="fw-bold"><?= Security::e($cita['mrn']) ?></code></td>
                                    <td>
                                        <?= Security::e($cita['paciente_nombre']) ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger ms-1"><?= Security::e($cita['grupo_sanguineo']) ?></span>
                                    </td>
                                    <td>
                                        <div><?= Security::e($cita['medico_nombre']) ?></div>
                                        <span class="text-muted"><?= Security::e($cita['codigo_consultorio']) ?></span>
                                    </td>
                                    <td><?= Security::e($cita['motivo_consulta']) ?></td>
                                    <td>
                                        <?php
                                        $badgeClass = match ($cita['estado']) {
                                            'CONFIRMADA' => 'bg-primary',
                                            'EN_CURSO'   => 'bg-warning text-dark',
                                            'ATENDIDA'   => 'bg-success',
                                            'CANCELADA'  => 'bg-danger',
                                            default      => 'bg-secondary',
                                        };
                                        ?>
                                        <span class="badge <?= $badgeClass ?>"><?= Security::e($cita['estado']) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-key text-primary me-2" aria-hidden="true"></i>Matriz de Privilegios de su Rol (RBAC)</h6>
            </div>
            <div class="card-body small">
                <p class="text-muted mb-3">
                    De acuerdo con <strong>RF-SEG-02</strong> y <strong>RN-CLI-06</strong>, toda petición HTTP es validada por <code>AuthMiddleware</code> antes de ejecutar sentencias PDO:
                </p>
                <ul class="list-group list-group-flush">
                    <?php
                    $modulosInfo = [
                        'SEG' => '1. Seguridad y Auditoría',
                        'PAC' => '2. Gestión de Pacientes (OPD)',
                        'CIT' => '3. Control de Citas Médicas',
                        'HIS' => '4. Historial Clínico (EHR)',
                        'FAR' => '5. Farmacia e Inventario (FEFO)',
                    ];
                    foreach ($modulosInfo as $cod => $label):
                        $puedeLeer = AuthMiddleware::can($cod, 'leer');
                        $puedeCrear = AuthMiddleware::can($cod, 'crear');
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span><?= Security::e($label) ?></span>
                            <span>
                                <?php if ($puedeLeer): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">READ</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">BLOQUEADO (403)</span>
                                <?php endif; ?>
                                <?php if ($puedeCrear): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">WRITE</span>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/views/layout/footer.php'; ?>