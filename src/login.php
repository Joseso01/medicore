<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/controllers/AuthController.php';

use MediCore\Core\Security;
use MediCore\Controllers\AuthController;

Security::initSession();

if (!empty($_SESSION['id_usuario'])) {
    header('Location: /index.php');
    exit;
}

$errorMensaje = '';
$infoMensaje  = '';

if (isset($_GET['timeout'])) {
    $infoMensaje = 'Su sesión expiró automáticamente tras 20 minutos de inactividad (RF-SEG-04).';
} elseif (isset($_GET['revoked'])) {
    $errorMensaje = 'Su sesión fue invalidada porque el estado de la cuenta cambió a SUSPENDIDO o BAJA_LOGICA (RF-SEG-03).';
} elseif (isset($_GET['logout'])) {
    $infoMensaje = 'Ha cerrado su sesión institucional de forma segura.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!Security::validateCsrfToken(is_string($token) ? $token : null)) {
        $errorMensaje = 'Error de seguridad: Token anti-CSRF inválido (RNF-SEG-03). Intente nuevamente.';
    } else {
        $identificador = (string)($_POST['identificador'] ?? '');
        $password      = (string)($_POST['password'] ?? '');

        $auth = new AuthController();
        $resultado = $auth->login($identificador, $password);

        if ($resultado['ok']) {
            header('Location: /index.php');
            exit;
        }
        $errorMensaje = (string)$resultado['mensaje'];
    }
}

$csrfToken = Security::getCsrfToken();

$cssPath    = __DIR__ . '/assets/css/medicore.css';
$cssVersion = is_file($cssPath) ? (string)filemtime($cssPath) : '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Institucional — MediCore Systems</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/medicore.css?v=<?= Security::e($cssVersion) ?>" rel="stylesheet">
</head>
<body class="mc-login-body p-3">
    <main class="card mc-login-card">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-10 text-primary rounded-circle mb-2 mc-login-icon">
                    <i class="bi bi-hospital fs-2" aria-hidden="true"></i>
                </div>
                <h1 class="h4 fw-bold mb-1">MediCore Systems</h1>
                <p class="text-muted small mb-0">Sistema de Gestión y Trazabilidad Hospitalaria (HMS)</p>
                <span class="badge bg-secondary bg-opacity-10 text-secondary mt-1">ERS-MCS-DB-2026-V1.0</span>
            </div>

            <?php if ($errorMensaje !== ''): ?>
                <div class="alert alert-danger d-flex align-items-start gap-2 small py-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
                    <div><?= Security::e($errorMensaje) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($infoMensaje !== ''): ?>
                <div class="alert alert-info d-flex align-items-start gap-2 small py-2" role="status">
                    <i class="bi bi-info-circle-fill mt-1" aria-hidden="true"></i>
                    <div><?= Security::e($infoMensaje) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="/login.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= Security::e($csrfToken) ?>">

                <div class="mb-3">
                    <label for="identificador" class="form-label small fw-semibold">Usuario o Correo Institucional</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span>
                        <input type="text" class="form-control" id="identificador" name="identificador"
                               placeholder="usuario o correo@institucion" required autofocus>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label small fw-semibold">Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-key"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="••••••••" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 fw-semibold py-2">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Iniciar Sesión Segura
                </button>
            </form>

            <div class="text-center mt-4 pt-2 border-top small text-muted">
                <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Acceso exclusivo para personal autorizado.<br>
                Todos los intentos de ingreso son auditados (<code>RF-SEG-01</code> / <code>RF-SEG-04</code>).
            </div>
        </div>
    </main>
</body>
</html>
