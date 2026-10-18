<?php
declare(strict_types=1);

use MediCore\Core\Security;
use MediCore\Core\AuthMiddleware;

$tituloPagina = $tituloPagina ?? 'MediCore Systems — Gestión y Trazabilidad Hospitalaria';
$moduloActivo = $moduloActivo ?? 'DASHBOARD';
$csrfToken    = Security::getCsrfToken();
$rolNombre    = (string)($_SESSION['rol_nombre'] ?? 'Invitado');
$nombreUser   = (string)($_SESSION['nombre_completo'] ?? '');
$username     = (string)($_SESSION['username'] ?? '');

// Versión del CSS por fecha de modificación (invalida la caché del navegador tras cada despliegue)
$cssPath    = __DIR__ . '/../../assets/css/medicore.css';
$cssVersion = is_file($cssPath) ? (string)filemtime($cssPath) : '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= Security::e($csrfToken) ?>">
    <title><?= Security::e($tituloPagina) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/medicore.css?v=<?= Security::e($cssVersion) ?>" rel="stylesheet">
</head>
<body>
<a class="mc-skip-link" href="#contenidoPrincipal">Saltar al contenido principal</a>
<header>
    <nav class="navbar navbar-expand-lg navbar-dark navbar-medicore shadow-sm py-2 sticky-top" aria-label="Navegación principal">
        <div class="container-fluid px-3">
            <a class="navbar-brand d-flex align-items-center gap-2 fw-bold" href="/index.php">
                <i class="bi bi-hospital fs-4 text-info" aria-hidden="true"></i>
                <span>MediCore Systems</span>
                <span class="badge bg-info text-dark ms-1 mc-brand-badge">HMS v1.0</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMediCore"
                    aria-controls="navbarMediCore" aria-expanded="false" aria-label="Mostrar u ocultar menú">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarMediCore">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-1">
                    <li class="nav-item">
                        <a class="nav-link px-3 <?= $moduloActivo === 'DASHBOARD' ? 'active' : '' ?>" href="/index.php">
                            <i class="bi bi-speedometer2 me-1"></i>Tablero
                        </a>
                    </li>

                    <?php if (AuthMiddleware::can('PAC', 'leer')): ?>
                    <li class="nav-item">
                        <a class="nav-link px-3 <?= $moduloActivo === 'PAC' ? 'active' : '' ?>" href="/pacientes.php">
                            <i class="bi bi-person-vcard me-1"></i>Pacientes (OPD)
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (AuthMiddleware::can('CIT', 'leer')): ?>
                    <li class="nav-item">
                        <a class="nav-link px-3 <?= $moduloActivo === 'CIT' ? 'active' : '' ?>" href="/citas.php">
                            <i class="bi bi-calendar2-check me-1"></i>Agenda y Citas
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (AuthMiddleware::can('HIS', 'leer')): ?>
                    <li class="nav-item">
                        <a class="nav-link px-3 <?= $moduloActivo === 'HIS' ? 'active' : '' ?>" href="/consultas.php">
                            <i class="bi bi-file-earmark-medical me-1"></i>Historial Clínico (EHR)
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (AuthMiddleware::can('FAR', 'leer')): ?>
                    <li class="nav-item">
                        <a class="nav-link px-3 <?= $moduloActivo === 'FAR' ? 'active' : '' ?>" href="/farmacia.php">
                            <i class="bi bi-capsule-pill me-1"></i>Farmacia (FEFO)
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (AuthMiddleware::can('SEG', 'leer')): ?>
                    <li class="nav-item">
                        <a class="nav-link px-3 <?= $moduloActivo === 'SEG' ? 'active' : '' ?>" href="/usuarios.php">
                            <i class="bi bi-shield-lock me-1"></i>Seguridad y Auditoría
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>

                <div class="d-flex align-items-center gap-3 text-white">
                    <div class="text-end d-none d-md-block">
                        <div class="small fw-semibold"><?= Security::e($nombreUser) ?></div>
                        <div class="d-flex align-items-center justify-content-end gap-1">
                            <span class="badge bg-light text-dark mc-user-badge">
                                <i class="bi bi-person-badge me-1" aria-hidden="true"></i><?= Security::e($rolNombre) ?>
                            </span>
                            <span class="badge bg-secondary mc-user-badge--ttl" title="Expiración automática por inactividad: 20 min (RF-SEG-04)">
                                <i class="bi bi-clock-history me-1" aria-hidden="true"></i>20m TTL
                            </span>
                        </div>
                    </div>
                    <form action="/logout.php" method="POST" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">
                        <button type="submit" class="btn btn-outline-light btn-sm">
                            <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Salir
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>
</header>
<main id="contenidoPrincipal" class="mc-main container-fluid px-4 py-4" tabindex="-1">
