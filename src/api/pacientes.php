<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../controllers/PacienteController.php';

use MediCore\Core\AuthMiddleware;
use MediCore\Controllers\PacienteController;

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = $_GET['action'] ?? '';
$controller = new PacienteController();

if ($method === 'GET') {
    AuthMiddleware::requirePermission('PAC', 'leer', true);

    if ($action === 'buscar') {
        $q = (string)($_GET['q'] ?? '');
        $pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
        echo json_encode($controller->buscarPacientes($q, $pagina, 15), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'detalle') {
        $idPaciente = (int)($_GET['id_paciente'] ?? 0);
        $paciente = $controller->obtenerPaciente($idPaciente);
        if (!$paciente) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'mensaje' => 'Paciente no encontrado.']);
            exit;
        }
        echo json_encode(['ok' => true, 'paciente' => $paciente], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Acción GET inválida en módulo PAC.']);
    exit;
}

if ($method === 'POST') {
    AuthMiddleware::requireCsrf(true);

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '{}', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $postAction = (string)($payload['action'] ?? $action);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idRol     = (int)($_SESSION['id_rol'] ?? 0);

    if ($postAction === 'registrar') {
        AuthMiddleware::requirePermission('PAC', 'crear', true);
        $res = $controller->registrarPaciente($payload, $idUsuario);
        http_response_code($res['ok'] ? 201 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($postAction === 'actualizar') {
        AuthMiddleware::requirePermission('PAC', 'actualizar', true);
        $res = $controller->actualizarPaciente($payload, $idUsuario, $idRol);
        http_response_code($res['ok'] ? 200 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($postAction === 'registrar_alergia') {
        AuthMiddleware::requireAuth(true);
        $res = $controller->registrarAlergiaPaciente($payload, $idUsuario);
        http_response_code($res['ok'] ? 201 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Acción POST no reconocida en módulo PAC.']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'mensaje' => 'Método HTTP no permitido.']);
