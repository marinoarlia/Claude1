<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class CdiscountOrdersCronModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $auth = false;
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $token = (string) Tools::getValue('token');
        if (!$this->module->isValidCronToken($token)) {
            http_response_code(403);
            $this->ajaxRender(json_encode(['success' => false, 'message' => 'Token cron non valido.'], JSON_UNESCAPED_UNICODE));
            return;
        }

        @set_time_limit(240);
        @ignore_user_abort(true);
        $this->module->registerFatalHandler('json');
        try {
            $orders = $this->module->syncOrders();
            $shipments = $this->module->syncReadyShipments();
            $success = !empty($orders['success']) && !empty($shipments['success']);
            if (!$success) {
                http_response_code(500);
            }
            $this->ajaxRender(json_encode([
                'success' => $success,
                'orders' => $orders,
                'shipments' => $shipments,
                'executed_at' => gmdate('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
            http_response_code(500);
            $this->module->storeFatalError('Controller cron: '.$e->getMessage());
            $this->ajaxRender(json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
    }
}
