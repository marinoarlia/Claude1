<?php

ob_start();
require_once dirname(__FILE__).'/../../config/config.inc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$module = Module::getInstanceByName('cdiscountorders');
if (!$module || !Module::isInstalled('cdiscountorders')) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Modulo Cdiscount Orders non installato o non caricabile.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = (string) Tools::getValue('token');
if (!$module->isValidCronToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token cron non valido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

@set_time_limit(240);
@ignore_user_abort(true);
$module->registerFatalHandler('json');

try {
    $orders = $module->syncOrders();
    $shipments = $module->syncReadyShipments();
    $success = !empty($orders['success']) && !empty($shipments['success']);
    if (!$success) {
        http_response_code(500);
    }
    $output = json_encode([
        'success' => $success,
        'orders' => $orders,
        'shipments' => $shipments,
        'executed_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo $output;
} catch (Exception $e) {
    http_response_code(500);
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    if (ob_get_level() > 0) {
        ob_clean();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
