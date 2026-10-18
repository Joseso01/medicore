<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../controllers/CitaController.php';

use MediCore\Core\AuthMiddleware;
use MediCore\Controllers\CitaController;

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = $_GET['action'] ?? '';
$controller = new CitaController();

$idRol = (int)($_SESSION['id_rol'] ?? 0);
$idMedicoSesion = isset($_SESSION['id_medico']) ? (int)$_SESSION['id_medico'] : null;

if ($method === 'GET') {
    AuthMiddleware::requirePermission('CIT', 'leer', true);

    if ($action === 'listar') {
        $filtros = [
            'fecha'           => $_GET['fecha'] ?? date('Y-m-d'),
            'id_especialidad' => $_GET['id_especialidad'] ?? null,
            'id_medico'       => $_GET['id_medico'] ?? null,
            'estado'          => $_GET['estado'] ?? null,
        ];
        $citas = $controller->listarAgenda($filtros, $idRol, $idMedicoSesion);
        echo json_encode(['ok' => true, 'citas' => $citas], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'catalogos') {
        $catalogos = $controller->obtenerCatalogos();
        echo json_encode(['ok' => true, 'catalogos' => $catalogos], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Acción GET inválida en módulo CIT.']);
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
    $idUsuarioEjecutor = (int)($_SESSION['id_usuario'] ?? 0);

    if ($postAction === 'programar') {
        AuthMiddleware::requirePermission('CIT', 'crear', true);
        $res = $controller->programarCita($payload, $idUsuarioEjecutor);
        http_response_code($res['ok'] ? 201 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($postAction === 'cambiar_estado') {
        AuthMiddleware::requirePermission('CIT', 'actualizar', true);
        $idCita = (int)($payload['id_cita'] ?? 0);
        $nuevoEstado = (string)($payload['estado'] ?? '');
        $motivo = isset($payload['motivo_cancelacion']) ? (string)$payload['motivo_cancelacion'] : null;
        $res = $controller->cambiarEstado($idCita, $nuevoEstado, $motivo, $idUsuarioEjecutor);
        http_response_code($res['ok'] ? 200 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($postAction === 'reprogramar') {
        AuthMiddleware::requirePermission('CIT', 'actualizar', true);
        $res = $controller->reprogramarCita($payload, $idUsuarioEjecutor);
        http_response_code($res['ok'] ? 200 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Acción POST no reconocida en módulo CIT.']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'mensaje' => 'Método HTTP no permitido.']);
