<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../controllers/FarmaciaController.php';

use MediCore\Core\AuthMiddleware;
use MediCore\Controllers\FarmaciaController;

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = $_GET['action'] ?? '';
$controller = new FarmaciaController();

if ($method === 'GET') {
    AuthMiddleware::requirePermission('FAR', 'leer', true);

    if ($action === 'recetas_pendientes') {
        $q = (string)($_GET['q'] ?? '');
        echo json_encode([
            'ok'      => true,
            'recetas' => $controller->buscarRecetasPendientesFefo($q),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'monitor_inventario') {
        echo json_encode([
            'ok'           => true,
            'medicamentos' => $controller->listarMedicamentosYAlertasStock(),
            'lotes'        => $controller->listarLotesSemaforo(),
            'kardex'       => $controller->listarMovimientosKardex(60),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Acción GET inválida en módulo FAR.']);
    exit;
}

if ($method === 'POST') {
    AuthMiddleware::requireCsrf(true);
    // RN-CLI-06: El rol Médico tiene bloqueado crear/actualizar en FAR (devuelve 403 Forbidden)
    AuthMiddleware::requirePermission('FAR', 'crear', true);

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '{}', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $postAction = (string)($payload['action'] ?? $action);
    $idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
    $idRol     = (int)($_SESSION['id_rol'] ?? 0);

    if ($postAction === 'dispensar_receta') {
        $idReceta = (int)($payload['id_receta'] ?? 0);
        $notas = (string)($payload['notas_despacho'] ?? '');
        $res = $controller->dispensarRecetaFefo($idReceta, $notas, $idUsuario);
        http_response_code($res['ok'] ? 200 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($postAction === 'recepcionar_lote') {
        // RF-FAR-01: Exclusivo para el rol Administrador
        AuthMiddleware::requirePermission('FAR', 'actualizar', true);
        $res = $controller->recepcionarLote($payload, $idUsuario);
        http_response_code($res['ok'] ? 201 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($postAction === 'crear_medicamento') {
        // RF-FAR-01: Exclusivo para el rol Administrador
        AuthMiddleware::requirePermission('FAR', 'actualizar', true);
        $res = $controller->crearMedicamento($payload, $idUsuario);
        http_response_code($res['ok'] ? 201 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Acción POST no reconocida en módulo FAR.']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'mensaje' => 'Método HTTP no permitido.']);
