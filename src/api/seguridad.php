<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../controllers/UsuarioController.php';

use MediCore\Core\AuthMiddleware;
use MediCore\Controllers\UsuarioController;

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = $_GET['action'] ?? '';

$controller = new UsuarioController();

if ($method === 'GET') {
    AuthMiddleware::requirePermission('SEG', 'leer', true);

    if ($action === 'listar_usuarios') {
        echo json_encode([
            'ok'       => true,
            'usuarios' => $controller->listarUsuarios(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'listar_bitacora') {
        $limite = isset($_GET['limite']) ? max(10, min(200, (int)$_GET['limite'])) : 50;
        echo json_encode([
            'ok'       => true,
            'bitacora' => $controller->listarBitacora($limite),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Acción GET no reconocida en módulo SEG.']);
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
    $idAdmin = (int)($_SESSION['id_usuario'] ?? 0);

    if ($postAction === 'crear_usuario') {
        AuthMiddleware::requirePermission('SEG', 'crear', true);
        $resultado = $controller->crearUsuario($payload, $idAdmin);
        http_response_code($resultado['ok'] ? 201 : 400);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($postAction === 'cambiar_estado') {
        AuthMiddleware::requirePermission('SEG', 'actualizar', true);
        $idUsuario = (int)($payload['id_usuario'] ?? 0);
        $nuevoEstado = (string)($payload['estado'] ?? '');
        $motivo = (string)($payload['motivo'] ?? 'Actualización administrativa de estado');
        $resultado = $controller->cambiarEstadoUsuario($idUsuario, $nuevoEstado, $motivo, $idAdmin);
        http_response_code($resultado['ok'] ? 200 : 400);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($postAction === 'desbloquear_cuenta') {
        AuthMiddleware::requirePermission('SEG', 'actualizar', true);
        $idUsuario = (int)($payload['id_usuario'] ?? 0);
        $resultado = $controller->desbloquearCuenta($idUsuario, $idAdmin);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Acción POST no válida en módulo SEG.']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'mensaje' => 'Método HTTP no permitido.']);
