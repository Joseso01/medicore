<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../controllers/ConsultaController.php';

use MediCore\Core\AuthMiddleware;
use MediCore\Controllers\ConsultaController;

header('Content-Type: application/json; charset=utf-8');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = $_GET['action'] ?? '';
$controller = new ConsultaController();

// RN-CLI-06 & RF-SEG-02: Exclusivamente el rol Médico posee acceso de lectura y escritura al EHR
AuthMiddleware::requirePermission('HIS', 'leer', true);

$idMedicoSesion = isset($_SESSION['id_medico']) ? (int)$_SESSION['id_medico'] : 0;
$idUsuarioEjecutor = (int)($_SESSION['id_usuario'] ?? 0);

if ($idMedicoSesion <= 0) {
    http_response_code(403);
    echo json_encode([
        'ok'      => false,
        'mensaje' => 'RN-CLI-06: Su cuenta de usuario no tiene un perfil de Médico Facultativo activo asociado.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET') {
    if ($action === 'cargar_cita') {
        $idCita = (int)($_GET['id_cita'] ?? 0);
        $res = $controller->abrirOCargarConsultaPorCita($idCita, $idMedicoSesion, $idUsuarioEjecutor);
        http_response_code($res['ok'] ? 200 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'cargar_consulta') {
        $idConsulta = (int)($_GET['id_consulta'] ?? 0);
        $res = $controller->armarExpedienteConsulta($idConsulta);
        http_response_code($res['ok'] ? 200 : 404);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'historial_paciente') {
        $idPaciente = (int)($_GET['id_paciente'] ?? 0);
        echo json_encode([
            'ok'        => true,
            'historial' => $controller->obtenerHistorialLongitudinal($idPaciente),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Acción GET inválida en módulo HIS.']);
    exit;
}

if ($method === 'POST') {
    AuthMiddleware::requireCsrf(true);
    AuthMiddleware::requirePermission('HIS', 'crear', true);

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?: '{}', true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $postAction = (string)($payload['action'] ?? $action);

    if ($postAction === 'guardar_consulta' || $postAction === 'cerrar_consulta') {
        $payload['cerrar_consulta'] = ($postAction === 'cerrar_consulta');
        $res = $controller->guardarOCerrarConsulta($payload, $idMedicoSesion, $idUsuarioEjecutor);
        http_response_code($res['ok'] ? 200 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($postAction === 'registrar_adenda') {
        $idConsulta = (int)($payload['id_consulta'] ?? 0);
        $texto = (string)($payload['texto_aclaracion'] ?? '');
        $res = $controller->registrarAdendaClinica($idConsulta, $texto, $idMedicoSesion, $idUsuarioEjecutor);
        http_response_code($res['ok'] ? 201 : 400);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'mensaje' => 'Acción POST no reconocida en módulo HIS.']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'mensaje' => 'Método HTTP no permitido.']);
