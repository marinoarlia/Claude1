<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__.'/classes/OctopiaOrdersApi.php';
require_once __DIR__.'/classes/OctopiaOrdersService.php';

class CdiscountOrders extends PaymentModule
{
    private static $fatalGuardRegistered = false;
    private static $fatalMemoryReserve = null;

    public function __construct()
    {
        $this->name = 'cdiscountorders';
        $this->tab = 'market_place';
        $this->version = '1.0.14';
        $this->author = 'Masterbrico';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->controllers = ['cron'];

        parent::__construct();

        $this->displayName = $this->l('Ordini Cdiscount / Octopia');
        $this->description = $this->l('Importa gli ordini Octopia in PrestaShop e invia corriere e tracking.');
        $this->confirmUninstall = $this->l('Le tabelle di sincronizzazione saranno eliminate. Gli ordini PrestaShop resteranno invariati.');
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => '8.1.99'];
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        try {
            if (!$this->installDatabase()) {
                throw new Exception('Creazione tabelle non riuscita.');
            }
            Configuration::updateValue('CDO_CRON_TOKEN', Tools::passwdGen(48));
            Configuration::updateValue('CDO_AUTO_APPROVE', '1');
            Configuration::updateValue('CDO_LOOKBACK_DAYS', '30');
            Configuration::updateValue('CDO_SALES_CHANNEL_ID', (string) (Configuration::get('CDS_SALES_CHANNEL_ID') ?: 'CDISFR'));
            // Riprende le credenziali dal vecchio modulo cdiscountsync, se presenti,
            // così chi migra non deve reinserirle. Il modulo è comunque autonomo.
            $this->seedCredentialsFromLegacy();
            Configuration::updateValue('CDO_IMPORT_CARRIER_ID', (int) Configuration::get('PS_CARRIER_DEFAULT'));
            Configuration::updateValue('CDO_FALLBACK_CARRIER', 'GLS');
            Configuration::updateValue('CDO_CARRIER_MAP', "Bartolini=BRT\nBRT Corriere Espresso=BRT");

            $idOrderState = $this->createOrderState();
            $idGenericProduct = $this->createGenericProduct();
            if ($idOrderState <= 0 || $idGenericProduct <= 0) {
                throw new Exception('Stato ordine o prodotto generico non creato.');
            }
            Configuration::updateValue('CDO_ORDER_STATE_ID', $idOrderState);
            Configuration::updateValue('CDO_GENERIC_PRODUCT_ID', $idGenericProduct);

            return $this->registerHook('actionOrderStatusPostUpdate')
                && $this->registerHook('actionObjectOrderCarrierUpdateAfter')
                && $this->registerHook('actionCronJob');
        } catch (Exception $e) {
            PrestaShopLogger::addLog('CdiscountOrders install: '.$e->getMessage(), 3);
            $this->_errors[] = $e->getMessage();
            parent::uninstall();
            return false;
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('CdiscountOrders install: '.$e->getMessage(), 3);
            $this->_errors[] = $e->getMessage();
            parent::uninstall();
            return false;
        }
    }

    public function uninstall()
    {
        $sql = [
            'DROP TABLE IF EXISTS `'._DB_PREFIX_.'cdiscountorders_log`',
            'DROP TABLE IF EXISTS `'._DB_PREFIX_.'cdiscountorders_order`',
        ];
        foreach ($sql as $query) {
            Db::getInstance()->execute($query);
        }
        foreach ([
            'CDO_CRON_TOKEN', 'CDO_AUTO_APPROVE', 'CDO_LOOKBACK_DAYS', 'CDO_IMPORT_CARRIER_ID',
            'CDO_FALLBACK_CARRIER', 'CDO_CARRIER_MAP', 'CDO_ORDER_STATE_ID', 'CDO_GENERIC_PRODUCT_ID',
            'CDO_LAST_SYNC_AT', 'CDO_LAST_SYNC_RESULT', 'CDO_LAST_SHIP_RESULT', 'CDO_PAYMENT_STATE_MIGRATED',
            'CDO_TOTALS_FIXED_103', 'CDO_TOTALS_FIXED_104', 'CDO_LAST_SYNC_ATTEMPT_AT',
            'CDO_LAST_SUCCESS_AT', 'CDO_LAST_SYNC_ERROR', 'CDO_LAST_FATAL',
            'CDO_ACTIVE_ORDER_ID', 'CDO_ACTIVE_CART_ID', 'CDO_ACTIVE_IMPORT_STEP',
            'CDO_CLIENT_ID', 'CDO_CLIENT_SECRET', 'CDO_SELLER_ID', 'CDO_SALES_CHANNEL_ID',
        ] as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitCdiscountOrdersCredentials')) {
            $output .= $this->saveCredentials();
        }
        if (Tools::isSubmit('submitCdiscountOrdersTest')) {
            // Salva quanto digitato e poi prova subito la connessione.
            $this->persistCredentials();
            $output .= $this->testCredentials();
        }
        if (Tools::isSubmit('submitCdiscountOrdersConfig')) {
            $output .= $this->saveConfiguration();
        }
        if (Tools::isSubmit('submitCdiscountOrdersSync')) {
            @set_time_limit(240);
            $this->registerFatalHandler('admin');
            try {
                $result = $this->syncOrders();
            } catch (Throwable $e) {
                $this->storeFatalError('Sincronizzazione manuale: '.$e->getMessage());
                $result = ['success' => false, 'message' => $e->getMessage()];
            }
            $output .= $result['success']
                ? $this->displayConfirmation($result['message'])
                : $this->displayError($result['message']);
        }
        if (Tools::isSubmit('submitCdiscountOrdersShip')) {
            $result = $this->syncReadyShipments();
            $output .= $result['success']
                ? $this->displayConfirmation($result['message'])
                : $this->displayError($result['message']);
        }

        if (!Configuration::get('CDO_CLIENT_ID') || !Configuration::get('CDO_CLIENT_SECRET') || !Configuration::get('CDO_SELLER_ID')) {
            $output .= $this->displayWarning($this->l('Credenziali Octopia mancanti: inseriscile e salvale nel pannello "Credenziali Octopia" qui sotto.'));
        } else {
            $output .= $this->displayConfirmation($this->l('Credenziali Octopia configurate. Usa "Verifica connessione" per un controllo rapido.'));
        }

        return $output.$this->renderCredentials().$this->renderConfiguration().$this->renderStatus().$this->renderRecentOrders().$this->renderRecentLogs();
    }

    public function syncOrders()
    {
        @set_time_limit(240);
        $this->registerFatalHandler('silent');
        $service = new OctopiaOrdersService($this);
        return $service->syncOrders();
    }

    public function syncReadyShipments($idOrder = 0)
    {
        $service = new OctopiaOrdersService($this);
        return $service->syncReadyShipments((int) $idOrder);
    }

    public function setPaymentAcceptedState($idOrder)
    {
        $idOrder = (int) $idOrder;
        $idPaymentState = (int) Configuration::get('PS_OS_PAYMENT');
        $order = new Order($idOrder);
        if ($idOrder <= 0 || $idPaymentState <= 0 || !Validate::isLoadedObject($order)) {
            return false;
        }
        if ((int) $order->current_state === $idPaymentState) {
            return true;
        }

        $history = new OrderHistory();
        $history->id_order = $idOrder;
        if (isset($this->context->employee) && Validate::isLoadedObject($this->context->employee)) {
            $history->id_employee = (int) $this->context->employee->id;
        }
        $history->changeIdOrderState($idPaymentState, $idOrder);

        // add() registra lo stato senza inviare al cliente un'email automatica.
        return (bool) $history->add();
    }

    public function upgradeTo102()
    {
        $idTechnicalState = $this->createOrderState();
        if ($idTechnicalState <= 0) {
            return false;
        }
        Configuration::updateValue('CDO_ORDER_STATE_ID', $idTechnicalState);

        $table = _DB_PREFIX_.'cdiscountorders_order';
        if (!Db::getInstance()->getValue('SHOW TABLES LIKE "'.pSQL($table).'"')) {
            return true;
        }
        $rows = Db::getInstance()->executeS(
            'SELECT co.id_order FROM `'._DB_PREFIX_.'cdiscountorders_order` co'
            .' INNER JOIN `'._DB_PREFIX_.'orders` o ON o.id_order = co.id_order'
            .' WHERE co.id_order > 0 AND o.current_state = '.(int) $idTechnicalState
        );
        foreach ((array) $rows as $row) {
            if (!$this->setPaymentAcceptedState((int) $row['id_order'])) {
                return false;
            }
        }
        Configuration::updateValue('CDO_PAYMENT_STATE_MIGRATED', '1');
        return true;
    }

    public function upgradeTo103()
    {
        try {
            if (!$this->upgradeTo102()) {
                return false;
            }
            if ((string) Configuration::get('CDO_TOTALS_FIXED_103') === '1') {
                return true;
            }
            $service = new OctopiaOrdersService($this);
            $result = $service->repairImportedOrderTotals();
            if (empty($result['success'])) {
                return false;
            }
            Configuration::updateValue('CDO_TOTALS_FIXED_103', '1');
            return true;
        } catch (Exception $e) {
            $this->logUpgradeError($e->getMessage());
            return false;
        } catch (Throwable $e) {
            $this->logUpgradeError($e->getMessage());
            return false;
        }
    }

    public function upgradeTo104()
    {
        try {
            if (!$this->upgradeTo102()) {
                return false;
            }
            if ((string) Configuration::get('CDO_TOTALS_FIXED_104') === '1') {
                return true;
            }
            $service = new OctopiaOrdersService($this);
            $result = $service->repairImportedOrderTotals();
            if (empty($result['success'])) {
                return false;
            }
            Configuration::updateValue('CDO_TOTALS_FIXED_103', '1');
            Configuration::updateValue('CDO_TOTALS_FIXED_104', '1');
            return true;
        } catch (Exception $e) {
            $this->logUpgradeError($e->getMessage());
            return false;
        } catch (Throwable $e) {
            $this->logUpgradeError($e->getMessage());
            return false;
        }
    }

    private function logUpgradeError($message)
    {
        try {
            PrestaShopLogger::addLog('CdiscountOrders upgrade: '.(string) $message, 3);
        } catch (Throwable $ignored) {
            // Un errore nel logger non deve rendere inaccessibile il modulo.
        }
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        $idOrder = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        $state = isset($params['newOrderStatus']) ? $params['newOrderStatus'] : null;
        if ($idOrder > 0 && $state instanceof OrderState && (bool) $state->shipped && $this->isOctopiaOrder($idOrder)) {
            $this->syncReadyShipments($idOrder);
        }
    }

    public function hookActionObjectOrderCarrierUpdateAfter($params)
    {
        $orderCarrier = isset($params['object']) ? $params['object'] : null;
        if (!$orderCarrier instanceof OrderCarrier || (int) $orderCarrier->id_order <= 0) {
            return;
        }
        $idOrder = (int) $orderCarrier->id_order;
        if (!$this->isOctopiaOrder($idOrder)) {
            return;
        }
        $order = new Order($idOrder);
        $state = new OrderState((int) $order->current_state);
        if (Validate::isLoadedObject($state) && (bool) $state->shipped && trim((string) $orderCarrier->tracking_number) !== '') {
            $this->syncReadyShipments($idOrder);
        }
    }

    public function hookActionCronJob($params)
    {
        $this->syncOrders();
        $this->syncReadyShipments();
    }

    public function getCronFrequency()
    {
        return ['hour' => -1, 'day' => -1, 'month' => -1, 'day_of_week' => -1];
    }

    public function addModuleLog($level, $action, $octopiaOrderId, $idOrder, $message, $payload = null)
    {
        try {
            $payloadText = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payloadText === false) {
                $payloadText = '';
            }
            // Un payload API molto grande non deve poter bloccare la gestione
            // dell'errore che stiamo cercando di registrare.
            $payloadText = Tools::substr((string) $payloadText, 0, 120000);
            Db::getInstance()->insert('cdiscountorders_log', [
                'level' => pSQL(Tools::substr((string) $level, 0, 16)),
                'action' => pSQL(Tools::substr((string) $action, 0, 32)),
                'octopia_order_id' => pSQL(Tools::substr((string) $octopiaOrderId, 0, 80)),
                'id_order' => (int) $idOrder,
                'message' => pSQL(Tools::substr((string) $message, 0, 4000), true),
                'payload' => pSQL($payloadText, true),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $ignored) {
            // Il logger del modulo non deve mai interrompere una sincronizzazione.
        }
        if ($level === 'error') {
            try {
                PrestaShopLogger::addLog('CdiscountOrders: '.$message, 3, null, 'Order', (int) $idOrder, true);
            } catch (Throwable $ignored) {
                // Anche il logger principale di PrestaShop può non essere disponibile.
            }
        }
    }

    public function registerFatalHandler($mode = 'admin')
    {
        if (self::$fatalGuardRegistered) {
            return;
        }
        self::$fatalGuardRegistered = true;
        self::$fatalMemoryReserve = str_repeat('x', 262144);
        register_shutdown_function([$this, 'handleFatalShutdown'], (string) $mode);
    }

    public function handleFatalShutdown($mode = 'admin')
    {
        self::$fatalMemoryReserve = null;
        $error = error_get_last();
        if (!is_array($error) || !in_array((int) $error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            return;
        }

        $orderId = trim((string) Configuration::get('CDO_ACTIVE_ORDER_ID'));
        $step = trim((string) Configuration::get('CDO_ACTIVE_IMPORT_STEP'));
        $message = 'Errore PHP fatale';
        if ($orderId !== '') {
            $message .= ' sull’ordine Octopia '.$orderId;
        }
        if ($step !== '') {
            $message .= ' durante '.$step;
        }
        $message .= ': '.(string) $error['message'].' (riga '.(int) $error['line'].').';
        $this->storeFatalError($message);
        $this->addModuleLog('error', 'fatal', $orderId, 0, $message);

        if ($mode === 'json') {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
            }
            echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($mode === 'admin') {
            // Nel back office mostriamo il dettaglio reale invece di una pagina
            // "Errore fatale" vuota, così la causa è subito visibile.
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
                http_response_code(500);
            }
            echo '<div style="font-family:sans-serif;max-width:900px;margin:24px auto;padding:16px 20px;'
                .'border:2px solid #c0392b;border-radius:6px;background:#fdecea;color:#7b241c;">'
                .'<h2 style="margin-top:0">Errore fatale durante la sincronizzazione Octopia</h2>'
                .'<p>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</p>'
                .'<p style="margin-bottom:0">Il dettaglio è stato salvato anche nei log del modulo '
                .'e nel riquadro diagnostica della configurazione.</p></div>';
        }
    }

    public function storeFatalError($message)
    {
        try {
            Configuration::updateValue('CDO_LAST_FATAL', Tools::substr((string) $message, 0, 2000));
            Configuration::updateValue('CDO_LAST_SYNC_ERROR', Tools::substr((string) $message, 0, 2000));
        } catch (Throwable $ignored) {
            // Ultima rete di sicurezza: nessuna eccezione deve uscire da qui.
        }
    }

    public function isValidCronToken($token)
    {
        $saved = (string) Configuration::get('CDO_CRON_TOKEN');
        return $saved !== '' && is_string($token) && hash_equals($saved, $token);
    }

    private function installDatabase()
    {
        $queries = [];
        $queries[] = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'cdiscountorders_order` (
            `id_cdiscountorders_order` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `octopia_order_id` VARCHAR(80) NOT NULL,
            `octopia_reference` VARCHAR(128) DEFAULT NULL,
            `id_order` INT UNSIGNED DEFAULT NULL,
            `octopia_status` VARCHAR(32) DEFAULT NULL,
            `sales_channel_id` VARCHAR(32) DEFAULT NULL,
            `order_line_ids` TEXT DEFAULT NULL,
            `raw_order` LONGTEXT DEFAULT NULL,
            `imported_at` DATETIME DEFAULT NULL,
            `shipment_sent_at` DATETIME DEFAULT NULL,
            `shipment_number` VARCHAR(128) DEFAULT NULL,
            `carrier_name` VARCHAR(128) DEFAULT NULL,
            `last_sync` DATETIME DEFAULT NULL,
            `last_error` TEXT DEFAULT NULL,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id_cdiscountorders_order`),
            UNIQUE KEY `octopia_order_id` (`octopia_order_id`),
            KEY `id_order` (`id_order`),
            KEY `shipment_sent_at` (`shipment_sent_at`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4;';
        $queries[] = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'cdiscountorders_log` (
            `id_cdiscountorders_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `level` VARCHAR(16) NOT NULL,
            `action` VARCHAR(32) NOT NULL,
            `octopia_order_id` VARCHAR(80) DEFAULT NULL,
            `id_order` INT UNSIGNED DEFAULT NULL,
            `message` TEXT DEFAULT NULL,
            `payload` LONGTEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_cdiscountorders_log`),
            KEY `created_at` (`created_at`),
            KEY `id_order` (`id_order`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4;';

        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }
        return true;
    }

    private function createOrderState()
    {
        $saved = (int) Configuration::get('CDO_ORDER_STATE_ID');
        if ($saved > 0 && Validate::isLoadedObject(new OrderState($saved))) {
            $state = new OrderState($saved);
            $this->configureTechnicalOrderState($state);
            return $saved;
        }
        $existing = (int) Db::getInstance()->getValue(
            'SELECT id_order_state FROM `'._DB_PREFIX_.'order_state` WHERE module_name = "'.pSQL($this->name).'" ORDER BY id_order_state ASC'
        );
        if ($existing > 0 && Validate::isLoadedObject(new OrderState($existing))) {
            $state = new OrderState($existing);
            $this->configureTechnicalOrderState($state);
            return $existing;
        }

        $state = new OrderState();
        $this->configureTechnicalOrderState($state, false);
        return $state->add() ? (int) $state->id : 0;
    }

    private function configureTechnicalOrderState($state, $update = true)
    {
        $state->color = '#4169E1';
        $state->send_email = false;
        $state->module_name = $this->name;
        $state->invoice = false;
        // Non logable evita che PrestaShop confronti i prezzi locali del carrello
        // con il totale pagato su Octopia prima che il modulo riallinei le righe.
        $state->logable = false;
        $state->paid = false;
        $state->shipped = false;
        $state->delivery = false;
        $state->hidden = true;
        $state->unremovable = false;
        foreach (Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = 'Octopia - Importazione tecnica';
        }
        if ($update) {
            return (bool) $state->update();
        }
        return true;
    }

    private function createGenericProduct()
    {
        $saved = (int) Configuration::get('CDO_GENERIC_PRODUCT_ID');
        if ($saved > 0 && Validate::isLoadedObject(new Product($saved))) {
            $product = new Product($saved);
            $product->active = true;
            $product->visibility = 'none';
            $product->available_for_order = true;
            $product->customizable = 1;
            $product->update();
            StockAvailable::setQuantity($saved, 0, 1000000, (int) Context::getContext()->shop->id);
            return $saved;
        }
        $existing = (int) Db::getInstance()->getValue(
            'SELECT id_product FROM `'._DB_PREFIX_.'product` WHERE reference = "OCTOPIA-GENERIC" ORDER BY id_product ASC'
        );
        if ($existing > 0) {
            $product = new Product($existing);
            $product->active = true;
            $product->visibility = 'none';
            $product->available_for_order = true;
            $product->customizable = 1;
            $product->update();
            StockAvailable::setQuantity($existing, 0, 1000000, (int) Context::getContext()->shop->id);
            return $existing;
        }

        $product = new Product();
        $product->reference = 'OCTOPIA-GENERIC';
        $product->price = 0;
        $product->id_tax_rules_group = 0;
        $product->active = true;
        $product->visibility = 'none';
        $product->available_for_order = true;
        $product->show_price = true;
        $product->indexed = false;
        $product->customizable = 1;
        $product->id_category_default = (int) (Configuration::get('PS_HOME_CATEGORY') ?: 2);
        foreach (Language::getLanguages(false) as $language) {
            $idLang = (int) $language['id_lang'];
            $product->name[$idLang] = 'Prodotto Octopia non collegato';
            $product->link_rewrite[$idLang] = 'prodotto-octopia-non-collegato';
            $product->description_short[$idLang] = 'Riga tecnica usata per importare ordini Octopia con SKU non presente in PrestaShop.';
        }
        if (!$product->add()) {
            return 0;
        }
        $product->addToCategories([(int) $product->id_category_default]);
        StockAvailable::setQuantity((int) $product->id, 0, 1000000, (int) Context::getContext()->shop->id);
        return (int) $product->id;
    }

    private function seedCredentialsFromLegacy()
    {
        $map = [
            'CDO_CLIENT_ID' => 'CDS_CLIENT_ID',
            'CDO_CLIENT_SECRET' => 'CDS_CLIENT_SECRET',
            'CDO_SELLER_ID' => 'CDS_SELLER_ID',
            'CDO_SALES_CHANNEL_ID' => 'CDS_SALES_CHANNEL_ID',
        ];
        foreach ($map as $newKey => $legacyKey) {
            if (trim((string) Configuration::get($newKey)) !== '') {
                continue;
            }
            $legacyValue = trim((string) Configuration::get($legacyKey));
            if ($legacyValue !== '') {
                Configuration::updateValue($newKey, $legacyValue);
            }
        }
    }

    private function persistCredentials()
    {
        $clientId = trim((string) Tools::getValue('CDO_CLIENT_ID'));
        $sellerId = trim((string) Tools::getValue('CDO_SELLER_ID'));
        $channel = trim((string) Tools::getValue('CDO_SALES_CHANNEL_ID'));
        $secret = trim((string) Tools::getValue('CDO_CLIENT_SECRET'));

        Configuration::updateValue('CDO_CLIENT_ID', $clientId);
        Configuration::updateValue('CDO_SELLER_ID', $sellerId);
        Configuration::updateValue('CDO_SALES_CHANNEL_ID', $channel !== '' ? $channel : 'CDISFR');
        // Il Client Secret viene mostrato vuoto per sicurezza: lo aggiorniamo solo
        // se compilato, così salvare le altre voci non lo cancella.
        if ($secret !== '') {
            Configuration::updateValue('CDO_CLIENT_SECRET', $secret);
        }
    }

    private function saveCredentials()
    {
        $this->persistCredentials();
        return $this->displayConfirmation($this->l('Credenziali Octopia salvate.'));
    }

    private function testCredentials()
    {
        $api = new OctopiaOrdersApi();
        $result = $api->testConnection();
        return !empty($result['success'])
            ? $this->displayConfirmation($this->l('Verifica connessione Octopia riuscita: ').(string) $result['message'])
            : $this->displayError($this->l('Verifica connessione Octopia fallita: ').(string) $result['message']);
    }

    private function renderCredentials()
    {
        $action = Tools::safeOutput(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules'));
        $clientId = Tools::safeOutput((string) Configuration::get('CDO_CLIENT_ID'));
        $sellerId = Tools::safeOutput((string) Configuration::get('CDO_SELLER_ID'));
        $channel = Tools::safeOutput((string) (Configuration::get('CDO_SALES_CHANNEL_ID') ?: 'CDISFR'));
        $hasSecret = trim((string) Configuration::get('CDO_CLIENT_SECRET')) !== '';
        $secretPlaceholder = Tools::safeOutput($hasSecret
            ? 'Salvato — lascia vuoto per non modificarlo'
            : 'Incolla qui il Client Secret Octopia');

        return '<div class="panel"><div class="panel-heading"><i class="icon-key"></i> Credenziali Octopia</div>
            <form method="post" action="'.$action.'" class="form-horizontal" autocomplete="off">
                <p class="help-block">Inserisci le credenziali del tuo account venditore Octopia. Questo modulo è autonomo e non dipende da altri moduli.</p>
                <div class="form-group"><label class="control-label col-lg-3">Client ID</label><div class="col-lg-6"><input class="form-control" type="text" name="CDO_CLIENT_ID" value="'.$clientId.'" autocomplete="off"></div></div>
                <div class="form-group"><label class="control-label col-lg-3">Client Secret</label><div class="col-lg-6"><input class="form-control" type="password" name="CDO_CLIENT_SECRET" value="" placeholder="'.$secretPlaceholder.'" autocomplete="new-password"></div></div>
                <div class="form-group"><label class="control-label col-lg-3">Seller ID</label><div class="col-lg-6"><input class="form-control" type="text" name="CDO_SELLER_ID" value="'.$sellerId.'"></div></div>
                <div class="form-group"><label class="control-label col-lg-3">Sales Channel ID</label><div class="col-lg-3"><input class="form-control" type="text" name="CDO_SALES_CHANNEL_ID" value="'.$channel.'"><p class="help-block">Cdiscount Francia = CDISFR</p></div></div>
                <div class="panel-footer">
                    <button type="submit" name="submitCdiscountOrdersTest" class="btn btn-default"><i class="icon-plug"></i> Verifica connessione</button>
                    <button type="submit" name="submitCdiscountOrdersCredentials" class="btn btn-primary pull-right"><i class="process-icon-save"></i> Salva credenziali</button>
                </div>
            </form></div>';
    }

    private function saveConfiguration()
    {
        $lookback = max(1, min(365, (int) Tools::getValue('CDO_LOOKBACK_DAYS', 30)));
        $idCarrier = (int) Tools::getValue('CDO_IMPORT_CARRIER_ID');
        if ($idCarrier <= 0 || !Validate::isLoadedObject(new Carrier($idCarrier))) {
            return $this->displayError($this->l('Seleziona un corriere PrestaShop valido.'));
        }
        Configuration::updateValue('CDO_AUTO_APPROVE', Tools::getValue('CDO_AUTO_APPROVE') ? '1' : '0');
        Configuration::updateValue('CDO_LOOKBACK_DAYS', $lookback);
        Configuration::updateValue('CDO_IMPORT_CARRIER_ID', $idCarrier);
        Configuration::updateValue('CDO_FALLBACK_CARRIER', trim((string) Tools::getValue('CDO_FALLBACK_CARRIER')));
        Configuration::updateValue('CDO_CARRIER_MAP', trim((string) Tools::getValue('CDO_CARRIER_MAP')));
        if (Tools::getValue('CDO_REGENERATE_TOKEN')) {
            Configuration::updateValue('CDO_CRON_TOKEN', Tools::passwdGen(48));
        }
        if (!Configuration::get('CDO_GENERIC_PRODUCT_ID')) {
            Configuration::updateValue('CDO_GENERIC_PRODUCT_ID', $this->createGenericProduct());
        }
        return $this->displayConfirmation($this->l('Configurazione salvata.'));
    }

    private function renderConfiguration()
    {
        $carriers = Carrier::getCarriers((int) Configuration::get('PS_LANG_DEFAULT'), true, false, false, null, Carrier::ALL_CARRIERS);
        $carrierOptions = '';
        $selectedCarrier = (int) Configuration::get('CDO_IMPORT_CARRIER_ID');
        foreach ($carriers as $carrier) {
            $selected = (int) $carrier['id_carrier'] === $selectedCarrier ? ' selected' : '';
            $carrierOptions .= '<option value="'.(int) $carrier['id_carrier'].'"'.$selected.'>'.Tools::safeOutput($carrier['name']).'</option>';
        }
        $autoApprove = Configuration::get('CDO_AUTO_APPROVE') ? ' checked' : '';
        $lookback = (int) (Configuration::get('CDO_LOOKBACK_DAYS') ?: 30);
        $fallback = Tools::safeOutput((string) Configuration::get('CDO_FALLBACK_CARRIER'));
        $map = Tools::safeOutput((string) Configuration::get('CDO_CARRIER_MAP'));
        $action = Tools::safeOutput(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules'));

        return '<div class="panel"><div class="panel-heading"><i class="icon-cogs"></i> Configurazione ordini Octopia</div>
            <form method="post" action="'.$action.'" class="form-horizontal">
                <div class="form-group"><label class="control-label col-lg-3">Accetta automaticamente</label><div class="col-lg-7"><label><input type="checkbox" name="CDO_AUTO_APPROVE" value="1"'.$autoApprove.'> Accetta gli ordini WaitingAcceptance e importa quando diventano InPreparation</label></div></div>
                <div class="form-group"><label class="control-label col-lg-3">Primo controllo</label><div class="col-lg-2"><div class="input-group"><input class="form-control" type="number" min="1" max="365" name="CDO_LOOKBACK_DAYS" value="'.$lookback.'"><span class="input-group-addon">giorni</span></div></div></div>
                <div class="form-group"><label class="control-label col-lg-3">Corriere ordine importato</label><div class="col-lg-5"><select class="form-control" name="CDO_IMPORT_CARRIER_ID">'.$carrierOptions.'</select><p class="help-block">Serve per creare l’ordine. Potrai modificarlo prima della spedizione.</p></div></div>
                <div class="form-group"><label class="control-label col-lg-3">Corriere di riserva</label><div class="col-lg-5"><input class="form-control" type="text" name="CDO_FALLBACK_CARRIER" value="'.$fallback.'"><p class="help-block">Usato solo se il corriere dell’ordine non ha un nome.</p></div></div>
                <div class="form-group"><label class="control-label col-lg-3">Mappatura corrieri</label><div class="col-lg-7"><textarea class="form-control" rows="5" name="CDO_CARRIER_MAP">'.$map.'</textarea><p class="help-block">Una riga per corriere: Nome PrestaShop=Nome Octopia. Se assente, viene inviato il nome PrestaShop.</p></div></div>
                <div class="form-group"><label class="control-label col-lg-3">Sicurezza cron</label><div class="col-lg-7"><label><input type="checkbox" name="CDO_REGENERATE_TOKEN" value="1"> Rigenera il token cron</label></div></div>
                <div class="panel-footer"><button type="submit" name="submitCdiscountOrdersConfig" class="btn btn-default pull-right"><i class="process-icon-save"></i> Salva</button></div>
            </form></div>';
    }

    private function renderStatus()
    {
        // Endpoint diretto: non passa dal Dispatcher front-office, che su
        // alcune installazioni restituisce 404 anche con fc=module.
        $cronUrl = Tools::getShopDomainSsl(true).__PS_BASE_URI__.'modules/'.$this->name.'/cron.php?'.http_build_query([
            'token' => (string) Configuration::get('CDO_CRON_TOKEN'),
        ], '', '&', PHP_QUERY_RFC3986);
        $lastSync = (string) Configuration::get('CDO_LAST_SYNC_AT');
        $lastSuccess = (string) Configuration::get('CDO_LAST_SUCCESS_AT');
        $lastError = trim((string) Configuration::get('CDO_LAST_SYNC_ERROR'));
        $lastFatal = trim((string) Configuration::get('CDO_LAST_FATAL'));
        $action = Tools::safeOutput(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules'));
        $diagnostic = '';
        if ($lastFatal !== '') {
            $diagnostic .= '<div class="alert alert-danger"><strong>Ultimo errore fatale intercettato:</strong> '.Tools::safeOutput($lastFatal).'</div>';
        } elseif ($lastError !== '') {
            $diagnostic .= '<div class="alert alert-warning"><strong>Ultimo errore:</strong> '.Tools::safeOutput($lastError).'</div>';
        }
        return '<div class="panel"><div class="panel-heading"><i class="icon-refresh"></i> Sincronizzazione automatica</div>'.$diagnostic.'
            <p><strong>Ultimo controllo:</strong> '.Tools::safeOutput($lastSync !== '' ? $lastSync.' UTC' : 'mai').'</p>
            <p><strong>Ultimo controllo completato senza errori:</strong> '.Tools::safeOutput($lastSuccess !== '' ? $lastSuccess.' UTC' : 'mai').'</p>
            <p><strong>URL cron:</strong></p><div class="input-group"><input class="form-control" readonly value="'.Tools::safeOutput($cronUrl).'"><span class="input-group-addon">ogni 10 minuti</span></div>
            <p class="help-block">Il cron importa gli ordini e invia le spedizioni già pronte. Il numero di tracking PrestaShop viene usato anche come numero spedizione Octopia.</p>
            <form method="post" action="'.$action.'" style="display:inline-block;margin-right:8px"><button class="btn btn-primary" name="submitCdiscountOrdersSync"><i class="icon-download"></i> Scarica ordini adesso</button></form>
            <form method="post" action="'.$action.'" style="display:inline-block"><button class="btn btn-default" name="submitCdiscountOrdersShip"><i class="icon-truck"></i> Invia spedizioni pronte</button></form>
        </div>';
    }

    private function renderRecentOrders()
    {
        $rows = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'cdiscountorders_order` ORDER BY id_cdiscountorders_order DESC LIMIT 30');
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-shopping-cart"></i> Ultimi ordini Octopia</div><div class="table-responsive"><table class="table"><thead><tr><th>Ordine Octopia</th><th>Ordine PrestaShop</th><th>Stato</th><th>Importato</th><th>Spedizione inviata</th><th>Errore</th></tr></thead><tbody>';
        if (!$rows) {
            $html .= '<tr><td colspan="6">Nessun ordine ancora acquisito.</td></tr>';
        }
        foreach ((array) $rows as $row) {
            $local = (int) $row['id_order'] > 0 ? '#'.(int) $row['id_order'] : '-';
            $shipment = $row['shipment_sent_at'] ? $row['shipment_sent_at'].' - '.$row['carrier_name'].' '.$row['shipment_number'] : '-';
            $html .= '<tr><td>'.Tools::safeOutput($row['octopia_order_id']).'</td><td>'.$local.'</td><td>'.Tools::safeOutput($row['octopia_status']).'</td><td>'.Tools::safeOutput((string) $row['imported_at']).'</td><td>'.Tools::safeOutput($shipment).'</td><td class="text-danger">'.Tools::safeOutput(Tools::substr((string) $row['last_error'], 0, 250)).'</td></tr>';
        }
        return $html.'</tbody></table></div></div>';
    }

    private function renderRecentLogs()
    {
        $rows = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'cdiscountorders_log` ORDER BY id_cdiscountorders_log DESC LIMIT 50');
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-list"></i> Log recenti</div><div class="table-responsive"><table class="table"><thead><tr><th>Data</th><th>Livello</th><th>Azione</th><th>Ordine</th><th>Messaggio</th></tr></thead><tbody>';
        if (!$rows) {
            $html .= '<tr><td colspan="5">Nessun log.</td></tr>';
        }
        foreach ((array) $rows as $row) {
            $class = $row['level'] === 'error' ? 'text-danger' : '';
            $html .= '<tr class="'.$class.'"><td>'.Tools::safeOutput($row['created_at']).'</td><td>'.Tools::safeOutput($row['level']).'</td><td>'.Tools::safeOutput($row['action']).'</td><td>'.Tools::safeOutput($row['octopia_order_id']).'</td><td>'.Tools::safeOutput($row['message']).'</td></tr>';
        }
        return $html.'</tbody></table></div></div>';
    }

    private function isOctopiaOrder($idOrder)
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `'._DB_PREFIX_.'cdiscountorders_order` WHERE id_order = '.(int) $idOrder
        );
    }
}
