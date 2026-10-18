<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';

use MediCore\Core\Security;
use MediCore\Core\AuthMiddleware;

Security::initSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AuthMiddleware::requireCsrf(false);
}

Security::destroyCurrentSession('LOGOUT_EXPLICITO_USUARIO');
header('Location: /login.php?logout=1');
exit;
