<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class OctopiaOrdersService
{
    private $module;
    private $context;
    private $api;

    public function __construct(CdiscountOrders $module)
    {
        $this->module = $module;
        $this->context = Context::getContext();
        $this->api = new OctopiaOrdersApi();
    }

    public function syncOrders()
    {
        @set_time_limit(240);
        // La creazione ordine PrestaShop attiva gli hook di eventuali moduli
        // terzi (spedizioni, stock, ecc.) che possono saturare la memoria.
        @ini_set('memory_limit', '512M');
        $summary = [
            'success' => false,
            'read' => 0,
            'approved' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => [],
            'message' => '',
        ];
        $startedAt = gmdate('Y-m-d H:i:s');
        $previousSuccess = trim((string) Configuration::get('CDO_LAST_SUCCESS_AT'));
        $previousCheck = trim((string) Configuration::get('CDO_LAST_SYNC_AT'));
        if ($previousSuccess === '' && $previousCheck !== '') {
            $this->safeConfigurationUpdate('CDO_LAST_SUCCESS_AT', $previousCheck);
        }
        $this->safeConfigurationUpdate('CDO_LAST_SYNC_ATTEMPT_AT', $startedAt);
        $this->safeConfigurationUpdate('CDO_LAST_FATAL', '');
        if (!$this->api->isConfigured()) {
            $summary['errors'] = 1;
            $summary['message'] = 'Configura prima le credenziali nel modulo Cdiscount Sync.';
            $summary['details'][] = $summary['message'];
            $this->finalizeOrderSync($summary);
            return $summary;
        }

        if (!$this->acquireLock('cdiscountorders_sync')) {
            $summary['message'] = 'Sincronizzazione già in esecuzione.';
            $summary['details'][] = $summary['message'];
            $this->finalizeOrderSync($summary);
            return $summary;
        }

        try {
            $updatedAtMin = $this->getUpdatedAtMin();
            $page = 1;
            $pageSize = 50;
            $maxPages = 20;

            do {
                $query = [
                    'pageIndex' => $page,
                    'pageSize' => $pageSize,
                    'salesChannelId' => (string) (Configuration::get('CDO_SALES_CHANNEL_ID') ?: 'CDISFR'),
                    'supplyMode' => 'Seller',
                    'status' => 'WaitingAcceptance,Accepted,InPreparation',
                    'updatedAtMin' => $updatedAtMin,
                    'sort' => 'createdAt',
                ];
                $response = $this->api->getOrders($query);
                if (!$response['success']) {
                    throw new Exception($response['message']);
                }

                $items = isset($response['data']['items']) && is_array($response['data']['items'])
                    ? $response['data']['items']
                    : [];
                foreach ($items as $orderData) {
                    ++$summary['read'];
                    $this->processRemoteOrderSafely($orderData, $summary);
                }

                ++$page;
            } while (count($items) === $pageSize && $page <= $maxPages);

            $this->retryPendingRemoteOrders($summary);

        } catch (Exception $e) {
            ++$summary['errors'];
            $summary['details'][] = $e->getMessage();
            $this->safeModuleLog('error', 'sync', '', 0, $e->getMessage());
        } catch (Throwable $e) {
            ++$summary['errors'];
            $summary['details'][] = $e->getMessage();
            $this->safeModuleLog('error', 'sync', '', 0, $e->getMessage());
        } finally {
            $this->safeReleaseLock('cdiscountorders_sync');
            $this->clearImportCheckpoint();
            $this->finalizeOrderSync($summary);
        }

        return $summary;
    }

    private function finalizeOrderSync(array &$summary)
    {
        $summary['success'] = (int) $summary['errors'] === 0 && $summary['message'] !== 'Sincronizzazione già in esecuzione.';
        $summary['message'] = $this->formatSummary($summary);
        if (!empty($summary['details'])) {
            $summary['details'] = array_values(array_unique(array_filter(array_map('strval', $summary['details']))));
            if ($summary['details']) {
                $summary['message'] .= ' Dettaglio: '.Tools::substr(implode(' | ', $summary['details']), 0, 1200);
            }
        }

        $completedAt = gmdate('Y-m-d H:i:s');
        $this->safeConfigurationUpdate('CDO_LAST_SYNC_AT', $completedAt);
        if ($summary['success']) {
            $this->safeConfigurationUpdate('CDO_LAST_SUCCESS_AT', $completedAt);
            $this->safeConfigurationUpdate('CDO_LAST_SYNC_ERROR', '');
            $this->safeConfigurationUpdate('CDO_LAST_FATAL', '');
        } else {
            $this->safeConfigurationUpdate('CDO_LAST_SYNC_ERROR', $summary['message']);
        }
        $this->safeConfigurationUpdate('CDO_LAST_SYNC_RESULT', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->safeModuleLog($summary['success'] ? 'info' : 'error', 'sync', '', 0, $summary['message']);
    }

    private function processRemoteOrderSafely($orderData, array &$summary)
    {
        $octopiaOrderId = is_array($orderData) ? trim((string) $this->value($orderData, 'orderId', '')) : '';
        try {
            if (!is_array($orderData)) {
                throw new Exception('Riga ordine Octopia non valida.');
            }
            $this->processRemoteOrder($orderData, $summary);
        } catch (Exception $e) {
            ++$summary['errors'];
            $summary['details'][] = ($octopiaOrderId !== '' ? $octopiaOrderId.': ' : '').$e->getMessage();
            $this->safeSetRemoteError($octopiaOrderId, $e->getMessage());
            $this->safeModuleLog('error', 'import', $octopiaOrderId, 0, $e->getMessage());
        } catch (Throwable $e) {
            ++$summary['errors'];
            $summary['details'][] = ($octopiaOrderId !== '' ? $octopiaOrderId.': ' : '').$e->getMessage();
            $this->safeSetRemoteError($octopiaOrderId, $e->getMessage());
            $this->safeModuleLog('error', 'import', $octopiaOrderId, 0, $e->getMessage());
        } finally {
            $this->clearImportCheckpoint();
        }
    }

    public function syncReadyShipments($onlyOrderId = 0)
    {
        $summary = ['success' => false, 'checked' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0, 'message' => ''];
        $waitingReasons = [];
        if (!$this->api->isConfigured()) {
            $summary['errors'] = 1;
            $summary['message'] = 'Credenziali Octopia mancanti nel modulo Cdiscount Sync.';
            return $summary;
        }

        if (!$this->acquireLock('cdiscountorders_ship')) {
            $summary['message'] = 'Invio spedizioni già in esecuzione.';
            return $summary;
        }

        try {
            // Ricontrolla anche le vecchie spedizioni memorizzate senza il
            // prefisso CS. Se Octopia è già Shipped non viene reinviato nulla.
            $where = 'id_order > 0 AND (shipment_sent_at IS NULL'
                .' OR UPPER(COALESCE(shipment_number, "")) NOT LIKE "CS%")';
            if ((int) $onlyOrderId > 0) {
                $where .= ' AND id_order = '.(int) $onlyOrderId;
            }
            $rows = Db::getInstance()->executeS(
                'SELECT * FROM `'._DB_PREFIX_.'cdiscountorders_order` WHERE '.$where.' ORDER BY id_cdiscountorders_order ASC LIMIT 200'
            );

            foreach ((array) $rows as $row) {
                ++$summary['checked'];
                $result = $this->sendShipment($row);
                if ($result['status'] === 'sent') {
                    ++$summary['sent'];
                } elseif ($result['status'] === 'error') {
                    ++$summary['errors'];
                } else {
                    ++$summary['skipped'];
                    if (!empty($result['reason'])) {
                        $waitingReasons[] = (string) $result['reason'];
                    }
                }
            }

            $summary['success'] = $summary['errors'] === 0;
            $summary['message'] = 'Spedizioni controllate: '.$summary['checked'].', inviate: '.$summary['sent'].', in attesa: '.$summary['skipped'].', errori: '.$summary['errors'].'.';
            if ($waitingReasons) {
                $summary['message'] .= ' Motivo attesa: '.implode('; ', array_unique($waitingReasons));
            }
            Configuration::updateValue('CDO_LAST_SHIP_RESULT', json_encode($summary, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            ++$summary['errors'];
            $summary['message'] = $e->getMessage();
            $this->module->addModuleLog('error', 'shipment', '', (int) $onlyOrderId, $e->getMessage());
        } catch (Throwable $e) {
            ++$summary['errors'];
            $summary['message'] = $e->getMessage();
            $this->module->addModuleLog('error', 'shipment', '', (int) $onlyOrderId, $e->getMessage());
        } finally {
            $this->releaseLock('cdiscountorders_ship');
        }

        return $summary;
    }

    private function processRemoteOrder(array $orderData, array &$summary)
    {
        $octopiaOrderId = trim((string) $this->value($orderData, 'orderId', ''));
        if ($octopiaOrderId === '') {
            ++$summary['errors'];
            $summary['details'][] = 'Ordine Octopia senza orderId.';
            $this->safeModuleLog('error', 'import', '', 0, 'Ordine Octopia senza orderId.');
            return;
        }

        $this->setImportCheckpoint($octopiaOrderId, 0, 'registrazione ordine remoto');
        $status = (string) $this->value($orderData, 'status', '');
        $this->upsertRemoteRow($orderData);

        if ($this->getLocalOrderId($octopiaOrderId) > 0) {
            ++$summary['skipped'];
            return;
        }

        if ($status === 'WaitingAcceptance') {
            if ((bool) Configuration::get('CDO_AUTO_APPROVE')) {
                $response = $this->api->approveOrder($octopiaOrderId);
                if ($response['success']) {
                    ++$summary['approved'];
                    $this->safeModuleLog('info', 'approve', $octopiaOrderId, 0, 'Ordine accettato su Octopia; sarà importato quando passerà a InPreparation.');
                } else {
                    ++$summary['errors'];
                    $summary['details'][] = $octopiaOrderId.': '.$response['message'];
                    $this->safeSetRemoteError($octopiaOrderId, $response['message']);
                    $this->safeModuleLog('error', 'approve', $octopiaOrderId, 0, $response['message']);
                }
            } else {
                ++$summary['skipped'];
            }
            return;
        }

        if ($status !== 'InPreparation') {
            ++$summary['skipped'];
            return;
        }

        $detailResponse = $this->api->getOrder($octopiaOrderId);
        if (!$detailResponse['success'] || !is_array($detailResponse['data'])) {
            ++$summary['errors'];
            $message = $detailResponse['success'] ? 'Dettaglio ordine Octopia vuoto.' : $detailResponse['message'];
            $summary['details'][] = $octopiaOrderId.': '.$message;
            $this->safeSetRemoteError($octopiaOrderId, $message);
            $this->safeModuleLog('error', 'import', $octopiaOrderId, 0, $message);
            return;
        }

        try {
            $idOrder = $this->importOrder($detailResponse['data']);
            ++$summary['imported'];
            $this->safeModuleLog('info', 'import', $octopiaOrderId, $idOrder, 'Ordine importato correttamente in PrestaShop.');
        } catch (Exception $e) {
            ++$summary['errors'];
            $summary['details'][] = $octopiaOrderId.': '.$e->getMessage();
            $this->safeSetRemoteError($octopiaOrderId, $e->getMessage());
            $this->safeModuleLog('error', 'import', $octopiaOrderId, 0, $e->getMessage());
        } catch (Throwable $e) {
            ++$summary['errors'];
            $summary['details'][] = $octopiaOrderId.': '.$e->getMessage();
            $this->safeSetRemoteError($octopiaOrderId, $e->getMessage());
            $this->safeModuleLog('error', 'import', $octopiaOrderId, 0, $e->getMessage());
        }
    }

    private function importOrder(array $remote)
    {
        $octopiaOrderId = trim((string) $this->value($remote, 'orderId', ''));
        if ($octopiaOrderId === '') {
            throw new Exception('orderId Octopia mancante.');
        }
        $existingId = $this->getLocalOrderId($octopiaOrderId);
        if ($existingId > 0) {
            return $existingId;
        }

        $this->setImportCheckpoint($octopiaOrderId, 0, 'lettura dati ordine');

        $lines = $this->value($remote, 'lines', []);
        if (!is_array($lines) || !$lines) {
            throw new Exception('Ordine Octopia senza righe prodotto.');
        }

        $shippingAddress = $this->findShippingAddress($lines, $remote);
        if (!$shippingAddress) {
            throw new Exception('Indirizzo di consegna non ancora disponibile. Attendo il prossimo controllo.');
        }
        $billingAddress = $this->value($remote, 'billingAddress', []);
        if (!is_array($billingAddress) || !$billingAddress) {
            $billingAddress = $shippingAddress;
        }

        $email = trim((string) $this->value($shippingAddress, 'email', ''));
        if (!Validate::isEmail($email)) {
            $email = 'octopia.'.substr(sha1($octopiaOrderId), 0, 20).'@example.com';
        }

        $this->setImportCheckpoint($octopiaOrderId, 0, 'creazione cliente');
        $idCustomer = $this->getOrCreateCustomer($email, $shippingAddress, $octopiaOrderId);
        $this->setImportCheckpoint($octopiaOrderId, 0, 'creazione indirizzi');
        $idAddressDelivery = $this->createAddress($idCustomer, $shippingAddress, 'Octopia consegna '.Tools::substr($octopiaOrderId, -10));
        $idAddressInvoice = $this->createAddress($idCustomer, array_merge($shippingAddress, $billingAddress), 'Octopia fattura '.Tools::substr($octopiaOrderId, -10));
        $idCurrency = $this->resolveCurrency((string) $this->value($remote, 'currencyCode', 'EUR'));
        $idCarrier = $this->resolveCarrier($idAddressDelivery, $idCustomer);

        $this->setImportCheckpoint($octopiaOrderId, 0, 'creazione carrello');
        $cart = new Cart();
        $cart->id_shop_group = (int) $this->context->shop->id_shop_group;
        $cart->id_shop = (int) $this->context->shop->id;
        $cart->id_customer = $idCustomer;
        $cart->id_address_delivery = $idAddressDelivery;
        $cart->id_address_invoice = $idAddressInvoice;
        $cart->id_currency = $idCurrency;
        $cart->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $cart->id_carrier = $idCarrier;
        $cart->recyclable = 0;
        $cart->gift = 0;
        $cart->secure_key = (string) (new Customer($idCustomer))->secure_key;
        if (!$cart->add()) {
            throw new Exception('Impossibile creare il carrello PrestaShop.');
        }
        $this->setImportCheckpoint($octopiaOrderId, (int) $cart->id, 'inserimento righe prodotto');

        $lineMap = [];
        $missingSku = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $quantity = max(1, (int) $this->value($line, 'quantity', 1));
            $offer = $this->value($line, 'offer', []);
            $sku = trim((string) $this->value($offer, 'sellerProductId', ''));
            $gtin = trim((string) $this->value($offer, 'productGtin', ''));
            $productMatch = $this->findProduct($sku, $gtin);
            $idCustomization = 0;
            $isGeneric = false;
            if (!$productMatch) {
                $idProduct = (int) Configuration::get('CDO_GENERIC_PRODUCT_ID');
                $idProductAttribute = 0;
                if ($idProduct <= 0 || !Validate::isLoadedObject(new Product($idProduct))) {
                    throw new Exception('Prodotto generico Octopia non disponibile. Reinstalla o salva la configurazione del modulo.');
                }
                $idCustomization = $this->createCartCustomization($cart->id, $idProduct, $quantity, $idAddressDelivery);
                $isGeneric = true;
                $missingSku[] = $sku !== '' ? $sku : '(SKU vuoto)';
            } else {
                $idProduct = (int) $productMatch['id_product'];
                $idProductAttribute = (int) $productMatch['id_product_attribute'];
            }

            if (!$this->addProductToCart($cart, $quantity, $idProduct, $idProductAttribute, $idCustomization, $idAddressDelivery)) {
                throw new Exception('Impossibile aggiungere al carrello lo SKU '.($sku !== '' ? $sku : '(vuoto)').'.');
            }

            $lineMap[] = [
                'line' => $line,
                'id_product' => $idProduct,
                'id_product_attribute' => $idProductAttribute,
                'id_customization' => $idCustomization,
                'generic' => $isGeneric,
                'sku' => $sku,
                'gtin' => $gtin,
            ];
        }

        if (!$lineMap) {
            throw new Exception('Nessuna riga Octopia valida da importare.');
        }

        // Per gli ordini Octopia si importano esclusivamente i prodotti.
        // Le spese calcolate dal corriere PrestaShop non devono aumentare il totale.
        $paidAmount = $this->calculateRemoteProductsTotal($lineMap);
        if ($paidAmount <= 0) {
            $paidAmount = $this->money($this->value($this->value($remote, 'totalPrice', []), 'sellingPrice', 0));
        }
        if ($paidAmount <= 0) {
            throw new Exception('Totale ordine Octopia non valido.');
        }

        $idOrderState = (int) Configuration::get('CDO_ORDER_STATE_ID');
        if ($idOrderState <= 0 || !Validate::isLoadedObject(new OrderState($idOrderState))) {
            $idOrderState = (int) Configuration::get('PS_OS_PAYMENT');
        }

        $currency = new Currency($idCurrency);
        $message = 'Ordine Octopia '.$octopiaOrderId;
        if ($missingSku) {
            $message .= ' | SKU non collegati: '.implode(', ', array_unique($missingSku));
        }

        $this->setImportCheckpoint($octopiaOrderId, (int) $cart->id, 'creazione ordine PrestaShop');
        try {
            $this->module->validateOrder(
                (int) $cart->id,
                $idOrderState,
                $paidAmount,
                'Octopia / Cdiscount',
                $message,
                ['transaction_id' => $octopiaOrderId],
                (int) $currency->id,
                false,
                $cart->secure_key,
                $this->context->shop
            );
        } catch (Throwable $e) {
            // Alcuni moduli terzi lanciano un errore dai propri hook dopo che
            // PrestaShop ha già creato l'ordine. Recuperiamolo evitando duplicati.
            $createdOrderId = (int) Order::getIdByCartId((int) $cart->id);
            if ($createdOrderId <= 0) {
                throw $e;
            }
            $this->module->currentOrder = $createdOrderId;
            $this->safeModuleLog('error', 'hook', $octopiaOrderId, $createdOrderId, 'Ordine creato; errore successivo di un hook: '.$e->getMessage());
        }

        $idOrder = (int) $this->module->currentOrder;
        if ($idOrder <= 0) {
            $idOrder = (int) Order::getIdByCartId((int) $cart->id);
        }
        if ($idOrder <= 0) {
            throw new Exception('PrestaShop non ha restituito l’ID dell’ordine creato.');
        }

        // Salva subito il legame: se un passaggio successivo fallisce non verrà
        // mai creato un secondo ordine PrestaShop per lo stesso orderId Octopia.
        $this->markImported($octopiaOrderId, $idOrder, $remote);
        $this->setImportCheckpoint($octopiaOrderId, (int) $cart->id, 'allineamento prezzi e pagamento');
        $this->applyRemotePrices($idOrder, $lineMap, $paidAmount);
        $this->recordMarketplacePayment($idOrder, $paidAmount, $octopiaOrderId, $idCurrency);
        if (!$this->module->setPaymentAcceptedState($idOrder)) {
            throw new Exception('Ordine creato, ma passaggio allo stato Pagamento accettato non riuscito.');
        }
        $this->saveOrderMessage($idOrder, $octopiaOrderId, $remote, $missingSku);
        $this->clearImportCheckpoint();

        return $idOrder;
    }

    private function recordMarketplacePayment($idOrder, $paidAmount, $transactionId, $idCurrency)
    {
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        $order->valid = 1;
        $order->update();
        if (!$order->getOrderPayments()) {
            $currency = new Currency((int) $idCurrency);
            if (!$order->addOrderPayment((float) $paidAmount, 'Octopia / Cdiscount', (string) $transactionId, $currency)) {
                throw new Exception('Ordine creato, ma registrazione del pagamento Octopia non riuscita.');
            }
        }
    }

    private function applyRemotePrices($idOrder, array $lineMap, $paidAmount)
    {
        $details = Db::getInstance()->executeS(
            'SELECT * FROM `'._DB_PREFIX_.'order_detail` WHERE id_order = '.(int) $idOrder.' ORDER BY id_order_detail ASC'
        );
        $used = [];
        $productsIncl = 0.0;
        $productsExcl = 0.0;

        foreach ($lineMap as $map) {
            $line = $map['line'];
            $detail = $this->matchOrderDetail($details, $map, $used);
            if (!$detail) {
                continue;
            }
            $idOrderDetail = (int) $detail['id_order_detail'];
            $used[$idOrderDetail] = true;
            $quantity = max(1, (int) $this->value($line, 'quantity', 1));
            $sellingPrice = $this->value($line, 'sellingPrice', []);
            $unitIncl = $this->money($this->value($sellingPrice, 'unitSalesPrice', 0));
            if ($unitIncl <= 0) {
                $lineTotal = $this->money($this->value($this->value($line, 'totalPrice', []), 'sellingPrice', 0));
                $unitIncl = $lineTotal > 0 ? $lineTotal / $quantity : 0;
            }
            if ($unitIncl <= 0) {
                $unitIncl = (float) $detail['unit_price_tax_incl'];
            }

            $taxRate = $this->extractVatRate($sellingPrice);
            $unitExcl = $taxRate > 0 ? $unitIncl / (1 + ($taxRate / 100)) : $unitIncl;
            $totalIncl = $unitIncl * $quantity;
            $totalExcl = $unitExcl * $quantity;
            $productsIncl += $totalIncl;
            $productsExcl += $totalExcl;

            $offer = $this->value($line, 'offer', []);
            $title = trim((string) $this->value($offer, 'productTitle', 'Prodotto Octopia'));
            $sku = (string) $map['sku'];
            if ($map['generic']) {
                $title .= ' [SKU Octopia: '.($sku !== '' ? $sku : 'non disponibile').']';
            }

            Db::getInstance()->update('order_detail', [
                'product_name' => pSQL(Tools::substr($title, 0, 255)),
                'product_reference' => pSQL(Tools::substr($sku, 0, 64)),
                'product_ean13' => pSQL(Tools::substr((string) $map['gtin'], 0, 13)),
                'product_price' => (float) $unitExcl,
                'original_product_price' => (float) $unitExcl,
                'unit_price_tax_excl' => (float) $unitExcl,
                'unit_price_tax_incl' => (float) $unitIncl,
                'total_price_tax_excl' => (float) $totalExcl,
                'total_price_tax_incl' => (float) $totalIncl,
                'reduction_percent' => 0,
                'reduction_amount' => 0,
                'reduction_amount_tax_excl' => 0,
                'reduction_amount_tax_incl' => 0,
                'group_reduction' => 0,
                'product_quantity_discount' => 0,
                'id_tax_rules_group' => 0,
                'tax_computation_method' => 0,
            ], 'id_order_detail = '.$idOrderDetail);
        }

        $shippingIncl = 0.0;
        $shippingExcl = 0.0;
        $totalPaidExcl = $productsExcl;

        Db::getInstance()->update('orders', [
            'total_products' => (float) $productsExcl,
            'total_products_wt' => (float) $productsIncl,
            'total_shipping' => (float) $shippingIncl,
            'total_shipping_tax_excl' => (float) $shippingExcl,
            'total_shipping_tax_incl' => (float) $shippingIncl,
            'total_paid' => (float) $paidAmount,
            'total_paid_tax_excl' => (float) $totalPaidExcl,
            'total_paid_tax_incl' => (float) $paidAmount,
            'total_paid_real' => (float) $paidAmount,
        ], 'id_order = '.(int) $idOrder);

        Db::getInstance()->update('order_carrier', [
            'shipping_cost_tax_excl' => (float) $shippingExcl,
            'shipping_cost_tax_incl' => (float) $shippingIncl,
        ], 'id_order = '.(int) $idOrder);
    }

    public function repairImportedOrderTotals()
    {
        $summary = ['success' => true, 'fixed' => 0, 'errors' => 0, 'message' => ''];
        try {
            $rows = Db::getInstance()->executeS(
                'SELECT id_order, octopia_order_id, raw_order FROM `'._DB_PREFIX_.'cdiscountorders_order`'
                .' WHERE id_order > 0 ORDER BY id_cdiscountorders_order ASC'
            );
        } catch (Exception $e) {
            return $this->totalsRepairFailure($summary, '', 0, $e->getMessage());
        } catch (Throwable $e) {
            return $this->totalsRepairFailure($summary, '', 0, $e->getMessage());
        }
        foreach ((array) $rows as $row) {
            try {
                $remote = json_decode((string) $row['raw_order'], true);
                $lines = is_array($remote) ? $this->value($remote, 'lines', []) : [];
                $productTotal = $this->calculateRemoteProductsTotalFromLines((array) $lines);

                // Il totale delle righe ordine non comprende il costo del corriere PS
                // ed è quindi un fallback sicuro per gli ordini già creati.
                $detailTotals = $this->getOrderDetailTotals((int) $row['id_order']);
                if ($productTotal <= 0 && $detailTotals['incl'] > 0) {
                    $productTotal = $detailTotals['incl'];
                }
                if ($productTotal <= 0) {
                    $summary = $this->totalsRepairFailure($summary, (string) $row['octopia_order_id'], (int) $row['id_order'], 'Impossibile ricavare il totale prodotti Octopia per la correzione.');
                    continue;
                }
                if ($this->repairSingleOrderTotals((int) $row['id_order'], (string) $row['octopia_order_id'], $productTotal, $detailTotals)) {
                    ++$summary['fixed'];
                } else {
                    $summary = $this->totalsRepairFailure($summary, (string) $row['octopia_order_id'], (int) $row['id_order'], 'Aggiornamento del totale ordine non riuscito.');
                }
            } catch (Exception $e) {
                $summary = $this->totalsRepairFailure($summary, (string) $row['octopia_order_id'], (int) $row['id_order'], $e->getMessage());
            } catch (Throwable $e) {
                $summary = $this->totalsRepairFailure($summary, (string) $row['octopia_order_id'], (int) $row['id_order'], $e->getMessage());
            }
        }
        $summary['message'] = 'Totali Octopia corretti: '.$summary['fixed'].', errori: '.$summary['errors'].'.';
        return $summary;
    }

    private function repairSingleOrderTotals($idOrder, $octopiaOrderId, $productTotalIncl, array $detailTotals = [])
    {
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }
        if (!$detailTotals) {
            $detailTotals = $this->getOrderDetailTotals((int) $idOrder);
        }
        $totalExcl = $this->money(isset($detailTotals['excl']) ? $detailTotals['excl'] : 0);
        $detailTotalIncl = $this->money(isset($detailTotals['incl']) ? $detailTotals['incl'] : 0);
        if ($totalExcl <= 0) {
            $totalExcl = (float) $productTotalIncl;
        } elseif ($detailTotalIncl > 0 && abs($detailTotalIncl - $productTotalIncl) > 0.0001) {
            $totalExcl = $totalExcl * ((float) $productTotalIncl / $detailTotalIncl);
        }

        $orderValues = [
            'total_products' => (float) $totalExcl,
            'total_products_wt' => (float) $productTotalIncl,
            'total_shipping' => 0,
            'total_shipping_tax_excl' => 0,
            'total_shipping_tax_incl' => 0,
            'total_paid' => (float) $productTotalIncl,
            'total_paid_tax_excl' => (float) $totalExcl,
            'total_paid_tax_incl' => (float) $productTotalIncl,
            'total_paid_real' => (float) $productTotalIncl,
        ];
        try {
            if (!Db::getInstance()->update('orders', $orderValues, 'id_order = '.(int) $idOrder)) {
                return false;
            }
        } catch (Exception $e) {
            return false;
        } catch (Throwable $e) {
            return false;
        }
        $this->safeUpdate('order_carrier', [
            'shipping_cost_tax_excl' => 0,
            'shipping_cost_tax_incl' => 0,
        ], 'id_order = '.(int) $idOrder);
        $this->safeUpdate('order_invoice', [
            'total_products' => (float) $totalExcl,
            'total_products_wt' => (float) $productTotalIncl,
            'total_shipping_tax_excl' => 0,
            'total_shipping_tax_incl' => 0,
            'total_paid_tax_excl' => (float) $totalExcl,
            'total_paid_tax_incl' => (float) $productTotalIncl,
        ], 'id_order = '.(int) $idOrder);
        $this->safeUpdate('order_payment', [
            'amount' => (float) $productTotalIncl,
        ], 'order_reference = "'.pSQL($order->reference).'" AND payment_method = "Octopia / Cdiscount"');

        $this->safeModuleLog('info', 'totals', $octopiaOrderId, (int) $idOrder, 'Totale riallineato a '.number_format($productTotalIncl, 2, ',', '.').' €; spedizione PrestaShop impostata a 0 €.');
        return true;
    }

    private function getOrderDetailTotals($idOrder)
    {
        $sums = Db::getInstance()->getRow(
            'SELECT SUM(total_price_tax_excl) AS total_excl, SUM(total_price_tax_incl) AS total_incl'
            .' FROM `'._DB_PREFIX_.'order_detail` WHERE id_order = '.(int) $idOrder
        );
        return [
            'excl' => $this->money(isset($sums['total_excl']) ? $sums['total_excl'] : 0),
            'incl' => $this->money(isset($sums['total_incl']) ? $sums['total_incl'] : 0),
        ];
    }

    private function safeUpdate($table, array $values, $where)
    {
        try {
            return (bool) Db::getInstance()->update((string) $table, $values, (string) $where);
        } catch (Exception $e) {
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function totalsRepairFailure(array $summary, $octopiaOrderId, $idOrder, $message)
    {
        ++$summary['errors'];
        $summary['success'] = false;
        $this->safeModuleLog('error', 'totals', (string) $octopiaOrderId, (int) $idOrder, (string) $message);
        $summary['message'] = 'Totali Octopia corretti: '.$summary['fixed'].', errori: '.$summary['errors'].'.';
        return $summary;
    }

    private function safeModuleLog($level, $action, $octopiaOrderId, $idOrder, $message)
    {
        try {
            $this->module->addModuleLog($level, $action, $octopiaOrderId, $idOrder, $message);
        } catch (Throwable $ignored) {
            // Il log non deve interrompere importazione o aggiornamento.
        }
    }

    private function safeConfigurationUpdate($key, $value)
    {
        try {
            return (bool) Configuration::updateValue((string) $key, is_string($value) ? Tools::substr($value, 0, 4000) : $value);
        } catch (Throwable $ignored) {
            return false;
        }
    }

    private function safeSetRemoteError($octopiaOrderId, $message)
    {
        if (trim((string) $octopiaOrderId) === '') {
            return;
        }
        try {
            $this->setRemoteError((string) $octopiaOrderId, (string) $message);
        } catch (Throwable $ignored) {
            // Anche la scrittura del diagnostico deve essere non bloccante.
        }
    }

    private function setImportCheckpoint($octopiaOrderId, $idCart, $step)
    {
        $octopiaOrderId = Tools::substr(trim((string) $octopiaOrderId), 0, 80);
        $step = Tools::substr(trim((string) $step), 0, 180);
        $this->safeConfigurationUpdate('CDO_ACTIVE_ORDER_ID', $octopiaOrderId);
        $this->safeConfigurationUpdate('CDO_ACTIVE_CART_ID', (string) (int) $idCart);
        $this->safeConfigurationUpdate('CDO_ACTIVE_IMPORT_STEP', $step);
        if ($octopiaOrderId === '') {
            return;
        }
        try {
            Db::getInstance()->update('cdiscountorders_order', [
                'last_error' => pSQL('Importazione in corso: '.$step),
                'last_sync' => date('Y-m-d H:i:s'),
            ], 'octopia_order_id = "'.pSQL($octopiaOrderId).'"');
        } catch (Throwable $ignored) {
            // Il checkpoint serve al diagnostico, non deve bloccare l'ordine.
        }
    }

    private function clearImportCheckpoint()
    {
        $this->safeConfigurationUpdate('CDO_ACTIVE_ORDER_ID', '');
        $this->safeConfigurationUpdate('CDO_ACTIVE_CART_ID', '0');
        $this->safeConfigurationUpdate('CDO_ACTIVE_IMPORT_STEP', '');
    }

    private function safeReleaseLock($name)
    {
        try {
            $this->releaseLock((string) $name);
        } catch (Throwable $ignored) {
            // Il lock MySQL viene comunque rilasciato alla fine della connessione.
        }
    }

    private function matchOrderDetail(array $details, array $map, array $used)
    {
        foreach ($details as $detail) {
            $id = (int) $detail['id_order_detail'];
            if (isset($used[$id])) {
                continue;
            }
            if ((int) $detail['product_id'] !== (int) $map['id_product']) {
                continue;
            }
            if ((int) $detail['product_attribute_id'] !== (int) $map['id_product_attribute']) {
                continue;
            }
            if ((int) $map['id_customization'] > 0 && (int) $detail['id_customization'] !== (int) $map['id_customization']) {
                continue;
            }
            return $detail;
        }
        return null;
    }

    private function sendShipment(array $row)
    {
        $idOrder = (int) $row['id_order'];
        $octopiaOrderId = (string) $row['octopia_order_id'];
        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            $this->shipmentError($row, 'Ordine PrestaShop non trovato.');
            return ['status' => 'error'];
        }

        $state = new OrderState((int) $order->current_state);
        if (!$this->isShippedState($order, $state)) {
            $stateName = $this->getOrderStateName($state);
            return [
                'status' => 'waiting',
                'reason' => 'ordine #'.$idOrder.': stato tecnico attuale #'.(int) $order->current_state
                    .($stateName !== '' ? ' "'.$stateName.'"' : '').' non riconosciuto come Spedito',
            ];
        }

        $orderCarrier = $this->getOrderCarrier($idOrder);
        if (!$orderCarrier || trim((string) $orderCarrier['tracking_number']) === '') {
            return ['status' => 'waiting', 'reason' => 'ordine #'.$idOrder.': numero di tracking non trovato'];
        }

        $tracking = $this->formatOctopiaTracking((string) $orderCarrier['tracking_number']);
        $carrier = new Carrier((int) $orderCarrier['id_carrier']);
        $carrierName = Validate::isLoadedObject($carrier) ? trim((string) $carrier->name) : '';
        $carrierName = $this->mapCarrierName($carrierName);
        if ($carrierName === '') {
            $carrierName = trim((string) Configuration::get('CDO_FALLBACK_CARRIER'));
        }
        if ($carrierName === '') {
            $this->shipmentError($row, 'Nome corriere mancante. Configura un corriere di riserva.');
            return ['status' => 'error'];
        }

        $remoteResponse = $this->api->getOrder($octopiaOrderId);
        if (!$remoteResponse['success'] || !is_array($remoteResponse['data'])) {
            $this->shipmentError($row, $remoteResponse['success'] ? 'Dettaglio ordine Octopia vuoto.' : $remoteResponse['message']);
            return ['status' => 'error'];
        }

        $remote = $remoteResponse['data'];
        if (in_array((string) $this->value($remote, 'status', ''), ['Shipped', 'Delivered'], true)) {
            $this->markShipmentSent($row, $tracking, $carrierName, 'Già spedito su Octopia.');
            return ['status' => 'sent'];
        }

        $lineIds = [];
        foreach ((array) $this->value($remote, 'lines', []) as $line) {
            $offer = $this->value($line, 'offer', []);
            $status = (string) $this->value($line, 'status', '');
            $supplyMode = (string) $this->value($offer, 'supplyMode', '');
            $lineId = trim((string) $this->value($line, 'orderLineId', ''));
            if ($lineId !== '' && $supplyMode === 'Seller' && in_array($status, ['InPreparation', 'CancelRequest'], true)) {
                $lineIds[] = $lineId;
            }
        }
        if (!$lineIds) {
            $this->shipmentError($row, 'Nessuna riga Octopia spedibile: occorre stato InPreparation e supplyMode Seller.');
            return ['status' => 'error'];
        }

        $parcel = [
            'parcelNumber' => $tracking,
            'carrierName' => $carrierName,
            'orderLineIds' => array_values(array_unique($lineIds)),
        ];
        $trackingUrl = $this->buildTrackingUrl($carrier, $tracking);
        if ($trackingUrl !== '') {
            $parcel['trackingUrl'] = $trackingUrl;
        }

        $response = $this->api->shipOrder($octopiaOrderId, [$parcel]);
        if (!$response['success']) {
            $this->shipmentError($row, $response['message'], $parcel);
            return ['status' => 'error'];
        }

        $this->markShipmentSent($row, $tracking, $carrierName, 'Spedizione trasmessa a Octopia.', $parcel);
        return ['status' => 'sent'];
    }

    private function findProduct($sku, $gtin)
    {
        if ($sku !== '') {
            $row = Db::getInstance()->getRow(
                'SELECT p.id_product, 0 AS id_product_attribute FROM `'._DB_PREFIX_.'product` p WHERE p.reference = "'.pSQL($sku).'"'
            );
            if ($row) {
                return $row;
            }
            $row = Db::getInstance()->getRow(
                'SELECT pa.id_product, pa.id_product_attribute FROM `'._DB_PREFIX_.'product_attribute` pa WHERE pa.reference = "'.pSQL($sku).'"'
            );
            if ($row) {
                return $row;
            }
        }
        if ($gtin !== '') {
            $row = Db::getInstance()->getRow(
                'SELECT p.id_product, 0 AS id_product_attribute FROM `'._DB_PREFIX_.'product` p WHERE p.ean13 = "'.pSQL($gtin).'"'
            );
            if ($row) {
                return $row;
            }
            $row = Db::getInstance()->getRow(
                'SELECT pa.id_product, pa.id_product_attribute FROM `'._DB_PREFIX_.'product_attribute` pa WHERE pa.ean13 = "'.pSQL($gtin).'"'
            );
            if ($row) {
                return $row;
            }
        }
        return null;
    }

    private function createCartCustomization($idCart, $idProduct, $quantity, $idAddressDelivery)
    {
        // Inserimento conforme alla tabella nativa PS 8.1. Evita di dipendere
        // dall'autoload della classe Customization, che non è disponibile in
        // modo uniforme in tutte le installazioni aggiornate da PS 1.7.
        $inserted = Db::getInstance()->insert('customization', [
            'id_cart' => (int) $idCart,
            'id_product' => (int) $idProduct,
            'id_product_attribute' => 0,
            'id_address_delivery' => (int) $idAddressDelivery,
            'quantity' => (int) $quantity,
            'quantity_refunded' => 0,
            'quantity_returned' => 0,
            'in_cart' => 1,
        ]);
        $idCustomization = $inserted ? (int) Db::getInstance()->Insert_ID() : 0;
        if ($idCustomization <= 0) {
            throw new Exception('Impossibile creare la riga generica per uno SKU mancante.');
        }
        return $idCustomization;
    }

    private function addProductToCart($cart, $quantity, $idProduct, $idProductAttribute, $idCustomization, $idAddressDelivery)
    {
        $added = $cart->updateQty(
            (int) $quantity,
            (int) $idProduct,
            (int) $idProductAttribute,
            (int) $idCustomization,
            'up',
            (int) $idAddressDelivery
        );
        if ($added) {
            return true;
        }

        return Db::getInstance()->insert('cart_product', [
            'id_cart' => (int) $cart->id,
            'id_product' => (int) $idProduct,
            'id_address_delivery' => (int) $idAddressDelivery,
            'id_shop' => (int) $this->context->shop->id,
            'id_product_attribute' => (int) $idProductAttribute,
            'id_customization' => (int) $idCustomization,
            'quantity' => (int) $quantity,
            'date_add' => date('Y-m-d H:i:s'),
        ]);
    }

    private function retryPendingRemoteOrders(array &$summary)
    {
        try {
            $rows = Db::getInstance()->executeS(
                'SELECT octopia_order_id FROM `'._DB_PREFIX_.'cdiscountorders_order`'
                .' WHERE (id_order IS NULL OR id_order = 0)'
                .' AND octopia_status IN ("WaitingAcceptance", "Accepted", "InPreparation")'
                .' AND (last_sync IS NULL OR last_sync < DATE_SUB(NOW(), INTERVAL 5 MINUTE))'
                .' ORDER BY id_cdiscountorders_order ASC LIMIT 50'
            );
        } catch (Throwable $e) {
            ++$summary['errors'];
            $summary['details'][] = 'Rilettura ordini in attesa: '.$e->getMessage();
            return;
        }
        foreach ((array) $rows as $row) {
            $octopiaOrderId = (string) $row['octopia_order_id'];
            try {
                $response = $this->api->getOrder($octopiaOrderId);
                if (!$response['success'] || !is_array($response['data'])) {
                    ++$summary['errors'];
                    $message = isset($response['message']) ? (string) $response['message'] : 'Dettaglio ordine vuoto.';
                    $summary['details'][] = $octopiaOrderId.': '.$message;
                    $this->safeSetRemoteError($octopiaOrderId, $message);
                    continue;
                }
                ++$summary['read'];
                $this->processRemoteOrderSafely($response['data'], $summary);
            } catch (Throwable $e) {
                ++$summary['errors'];
                $summary['details'][] = $octopiaOrderId.': '.$e->getMessage();
                $this->safeSetRemoteError($octopiaOrderId, $e->getMessage());
                $this->safeModuleLog('error', 'retry', $octopiaOrderId, 0, $e->getMessage());
            }
        }
    }

    private function getOrCreateCustomer($email, array $address, $octopiaOrderId)
    {
        $idCustomer = (int) Db::getInstance()->getValue(
            'SELECT id_customer FROM `'._DB_PREFIX_.'customer` WHERE email = "'.pSQL($email).'" AND deleted = 0 ORDER BY id_customer DESC'
        );
        if ($idCustomer > 0) {
            return $idCustomer;
        }

        $customer = new Customer();
        $customer->id_shop_group = (int) $this->context->shop->id_shop_group;
        $customer->id_shop = (int) $this->context->shop->id;
        $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
        $customer->firstname = $this->cleanName($this->value($address, 'firstName', 'Cliente'));
        $customer->lastname = $this->cleanName($this->value($address, 'lastName', 'Octopia'));
        $customer->email = $email;
        $customer->passwd = password_hash(Tools::passwdGen(32), PASSWORD_BCRYPT);
        $customer->active = 1;
        $customer->is_guest = 0;
        $customer->note = 'Cliente creato dall’ordine Octopia '.$octopiaOrderId;
        if (!$customer->add()) {
            throw new Exception('Impossibile creare il cliente PrestaShop per l’ordine Octopia.');
        }
        $customer->addGroups([(int) Configuration::get('PS_CUSTOMER_GROUP')]);

        return (int) $customer->id;
    }

    private function createAddress($idCustomer, array $data, $alias)
    {
        $countryCode = strtoupper(trim((string) $this->value($data, 'countryCode', 'FR')));
        $idCountry = (int) Country::getByIso($countryCode);
        if ($idCountry <= 0) {
            throw new Exception('Paese '.$countryCode.' non presente in PrestaShop.');
        }

        $address = new Address();
        $address->id_customer = (int) $idCustomer;
        $address->id_country = $idCountry;
        $address->alias = Tools::substr((string) $alias, 0, 32);
        $address->firstname = $this->cleanName($this->value($data, 'firstName', 'Cliente'));
        $address->lastname = $this->cleanName($this->value($data, 'lastName', 'Octopia'));
        $address->company = Tools::substr(trim((string) $this->value($data, 'companyName', '')), 0, 64);
        $address->address1 = Tools::substr(trim((string) $this->value($data, 'addressLine1', '')), 0, 128);
        $address->address2 = Tools::substr(trim(implode(' ', array_filter([
            (string) $this->value($data, 'addressLine2', ''),
            (string) $this->value($data, 'addressLine3', ''),
            (string) $this->value($data, 'pickupName', ''),
            (string) $this->value($data, 'pickupId', ''),
        ]))), 0, 128);
        $address->postcode = Tools::substr(trim((string) $this->value($data, 'postalCode', '')), 0, 12);
        $address->city = Tools::substr(trim((string) $this->value($data, 'city', '')), 0, 64);
        $address->phone = Tools::substr(trim((string) $this->value($data, 'phone', '')), 0, 32);
        $address->phone_mobile = $address->phone;
        $address->vat_number = Tools::substr(trim((string) $this->value($data, 'companyVatNumber', '')), 0, 32);
        if ($address->address1 === '' || $address->postcode === '' || $address->city === '') {
            throw new Exception('Indirizzo Octopia incompleto: via, CAP o città mancanti.');
        }
        if (!$address->add()) {
            throw new Exception('Impossibile creare l’indirizzo PrestaShop.');
        }

        return (int) $address->id;
    }

    private function resolveCurrency($isoCode)
    {
        $idCurrency = (int) Currency::getIdByIsoCode(strtoupper(trim($isoCode)));
        if ($idCurrency <= 0) {
            $idCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        }
        return $idCurrency;
    }

    private function resolveCarrier($idAddressDelivery, $idCustomer)
    {
        $idCarrier = (int) Configuration::get('CDO_IMPORT_CARRIER_ID');
        $carrier = new Carrier($idCarrier);
        $idZone = (int) Address::getZoneById((int) $idAddressDelivery);
        $idGroup = (int) (new Customer((int) $idCustomer))->id_default_group;
        $isAvailable = $idCarrier > 0 && (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `'._DB_PREFIX_.'carrier` c'
            .' INNER JOIN `'._DB_PREFIX_.'carrier_zone` cz ON cz.id_carrier = c.id_carrier AND cz.id_zone = '.(int) $idZone
            .' INNER JOIN `'._DB_PREFIX_.'carrier_shop` cs ON cs.id_carrier = c.id_carrier AND cs.id_shop = '.(int) $this->context->shop->id
            .' INNER JOIN `'._DB_PREFIX_.'carrier_group` cg ON cg.id_carrier = c.id_carrier AND cg.id_group = '.(int) $idGroup
            .' WHERE c.id_carrier = '.(int) $idCarrier.' AND c.active = 1 AND c.deleted = 0'
        );
        if (!$isAvailable || !Validate::isLoadedObject($carrier) || (bool) $carrier->deleted) {
            $idCarrier = (int) Db::getInstance()->getValue(
                'SELECT c.id_carrier FROM `'._DB_PREFIX_.'carrier` c'
                .' INNER JOIN `'._DB_PREFIX_.'carrier_zone` cz ON cz.id_carrier = c.id_carrier AND cz.id_zone = '.(int) $idZone
                .' INNER JOIN `'._DB_PREFIX_.'carrier_shop` cs ON cs.id_carrier = c.id_carrier AND cs.id_shop = '.(int) $this->context->shop->id
                .' INNER JOIN `'._DB_PREFIX_.'carrier_group` cg ON cg.id_carrier = c.id_carrier AND cg.id_group = '.(int) $idGroup
                .' WHERE c.active = 1 AND c.deleted = 0 ORDER BY c.position ASC, c.id_carrier ASC'
            );
            $carrier = new Carrier($idCarrier);
        }
        if ($idCarrier <= 0 || !Validate::isLoadedObject($carrier) || (bool) $carrier->deleted) {
            throw new Exception('Nessun corriere PrestaShop attivo disponibile per la zona di consegna dell’ordine Octopia.');
        }
        return $idCarrier;
    }

    private function extractVatRate(array $sellingPrice)
    {
        foreach ((array) $this->value($sellingPrice, 'taxes', []) as $tax) {
            if (strtoupper((string) $this->value($tax, 'code', '')) === 'VAT') {
                return max(0, (float) $this->value($tax, 'rate', 0));
            }
        }
        return 0.0;
    }

    private function calculateRemoteProductsTotal(array $lineMap)
    {
        $lines = [];
        foreach ($lineMap as $map) {
            if (isset($map['line']) && is_array($map['line'])) {
                $lines[] = $map['line'];
            }
        }
        return $this->calculateRemoteProductsTotalFromLines($lines);
    }

    private function calculateRemoteProductsTotalFromLines(array $lines)
    {
        $total = 0.0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $quantity = max(1, (int) $this->value($line, 'quantity', 1));
            $sellingPrice = $this->value($line, 'sellingPrice', []);
            $unitPrice = $this->money($this->value($sellingPrice, 'unitSalesPrice', 0));
            if ($unitPrice > 0) {
                $total += $unitPrice * $quantity;
                continue;
            }
            $lineTotal = $this->money($this->value($this->value($line, 'totalPrice', []), 'sellingPrice', 0));
            $shipping = $this->money($this->value($sellingPrice, 'shippingCost', 0));
            $total += max(0, $lineTotal - $shipping);
        }
        return $this->money($total);
    }

    private function findShippingAddress(array $lines, array $remote = [])
    {
        foreach ($lines as $line) {
            $address = $this->value($line, 'shippingAddress', []);
            if (is_array($address) && $address) {
                return $address;
            }
        }
        // Fallback: alcune risposte Octopia espongono l'indirizzo di consegna a
        // livello di ordine e non dentro ogni riga. Senza questo controllo ogni
        // ordine InPreparation fallirebbe con "Indirizzo non ancora disponibile".
        foreach (['shippingAddress', 'deliveryAddress'] as $key) {
            $address = $this->value($remote, $key, []);
            if (is_array($address) && $address) {
                return $address;
            }
        }
        return [];
    }

    private function saveOrderMessage($idOrder, $octopiaOrderId, array $remote, array $missingSku)
    {
        $message = new Message();
        $message->id_order = (int) $idOrder;
        $message->private = 1;
        $message->message = 'Ordine Octopia: '.$octopiaOrderId
            .' | Riferimento marketplace: '.(string) $this->value($remote, 'reference', '')
            .' | Canale: '.(string) $this->value($this->value($remote, 'salesChannel', []), 'name', 'Cdiscount');
        if ($missingSku) {
            $message->message .= ' | Righe generiche per SKU: '.implode(', ', array_unique($missingSku));
        }
        $message->add();
    }

    private function upsertRemoteRow(array $remote)
    {
        $octopiaOrderId = (string) $this->value($remote, 'orderId', '');
        $row = [
            'octopia_reference' => pSQL(Tools::substr((string) $this->value($remote, 'reference', ''), 0, 128)),
            'octopia_status' => pSQL(Tools::substr((string) $this->value($remote, 'status', ''), 0, 32)),
            'sales_channel_id' => pSQL(Tools::substr((string) $this->value($this->value($remote, 'salesChannel', []), 'id', ''), 0, 32)),
            'raw_order' => pSQL(json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true),
            'last_sync' => date('Y-m-d H:i:s'),
        ];
        $exists = (int) Db::getInstance()->getValue(
            'SELECT id_cdiscountorders_order FROM `'._DB_PREFIX_.'cdiscountorders_order` WHERE octopia_order_id = "'.pSQL($octopiaOrderId).'"'
        );
        if ($exists > 0) {
            Db::getInstance()->update('cdiscountorders_order', $row, 'id_cdiscountorders_order = '.$exists);
        } else {
            $row['octopia_order_id'] = pSQL(Tools::substr($octopiaOrderId, 0, 80));
            Db::getInstance()->insert('cdiscountorders_order', $row);
        }
    }

    private function markImported($octopiaOrderId, $idOrder, array $remote)
    {
        $lineIds = [];
        foreach ((array) $this->value($remote, 'lines', []) as $line) {
            $lineId = trim((string) $this->value($line, 'orderLineId', ''));
            if ($lineId !== '') {
                $lineIds[] = $lineId;
            }
        }
        Db::getInstance()->update('cdiscountorders_order', [
            'id_order' => (int) $idOrder,
            'order_line_ids' => pSQL(json_encode(array_values(array_unique($lineIds))), true),
            'imported_at' => date('Y-m-d H:i:s'),
            'last_error' => null,
        ], 'octopia_order_id = "'.pSQL($octopiaOrderId).'"');
    }

    private function getLocalOrderId($octopiaOrderId)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT id_order FROM `'._DB_PREFIX_.'cdiscountorders_order` WHERE octopia_order_id = "'.pSQL($octopiaOrderId).'"'
        );
    }

    private function setRemoteError($octopiaOrderId, $message)
    {
        Db::getInstance()->execute(
            'UPDATE `'._DB_PREFIX_.'cdiscountorders_order` SET attempts = attempts + 1, last_error = "'.pSQL(Tools::substr($message, 0, 4000)).'", last_sync = NOW() WHERE octopia_order_id = "'.pSQL($octopiaOrderId).'"'
        );
    }

    private function getOrderCarrier($idOrder)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `'._DB_PREFIX_.'order_carrier` WHERE id_order = '.(int) $idOrder
            .' ORDER BY CASE WHEN tracking_number IS NOT NULL AND TRIM(tracking_number) <> "" THEN 0 ELSE 1 END ASC,'
            .' id_order_carrier DESC'
        );
    }

    private function isShippedState($order, $state)
    {
        if (!Validate::isLoadedObject($order) || !Validate::isLoadedObject($state)) {
            return false;
        }
        if ((bool) $state->shipped) {
            return true;
        }
        $configuredShippedState = (int) Configuration::get('PS_OS_SHIPPING');
        if ($configuredShippedState > 0 && (int) $order->current_state === $configuredShippedState) {
            return true;
        }

        $stateName = Tools::strtolower($this->getOrderStateName($state));
        if (strpos($stateName, 'spedit') !== false
            || strpos($stateName, 'shipp') !== false
            || strpos($stateName, 'expédi') !== false
            || strpos($stateName, 'expedi') !== false) {
            return true;
        }

        // Alcuni moduli di gestione spedizioni cambiano nuovamente current_state
        // subito dopo l'azione del gestore. In tal caso fa fede la cronologia:
        // se l'ordine è già passato da uno stato Spedito può essere notificato.
        $idLang = isset($this->context->language) ? (int) $this->context->language->id : (int) Configuration::get('PS_LANG_DEFAULT');
        return (bool) Db::getInstance()->getValue(
            'SELECT oh.id_order_history FROM `'._DB_PREFIX_.'order_history` oh'
            .' INNER JOIN `'._DB_PREFIX_.'order_state` os ON os.id_order_state = oh.id_order_state'
            .' LEFT JOIN `'._DB_PREFIX_.'order_state_lang` osl ON osl.id_order_state = oh.id_order_state'
            .' AND osl.id_lang = '.(int) $idLang
            .' WHERE oh.id_order = '.(int) $order->id
            .' AND (os.shipped = 1'
            .($configuredShippedState > 0 ? ' OR oh.id_order_state = '.(int) $configuredShippedState : '')
            .' OR LOWER(COALESCE(osl.name, "")) LIKE "%spedit%"'
            .' OR LOWER(COALESCE(osl.name, "")) LIKE "%shipp%"'
            .' OR LOWER(COALESCE(osl.name, "")) LIKE "%expédi%"'
            .' OR LOWER(COALESCE(osl.name, "")) LIKE "%expedi%")'
            .' ORDER BY oh.id_order_history DESC'
        );
    }

    private function getOrderStateName($state)
    {
        if (!Validate::isLoadedObject($state)) {
            return '';
        }
        $stateName = $state->name;
        if (is_array($stateName)) {
            $idLang = isset($this->context->language) ? (int) $this->context->language->id : 0;
            $stateName = isset($stateName[$idLang]) ? $stateName[$idLang] : reset($stateName);
        }
        return trim((string) $stateName);
    }

    private function mapCarrierName($carrierName)
    {
        $mapText = (string) Configuration::get('CDO_CARRIER_MAP');
        foreach (preg_split('/\r\n|\r|\n/', $mapText) as $line) {
            if (strpos($line, '=') === false) {
                continue;
            }
            list($local, $octopia) = array_map('trim', explode('=', $line, 2));
            if ($local !== '' && strcasecmp($local, $carrierName) === 0) {
                return $octopia;
            }
        }
        return $carrierName;
    }

    private function buildTrackingUrl($carrier, $tracking)
    {
        if (!Validate::isLoadedObject($carrier)) {
            return '';
        }
        $url = trim((string) $carrier->url);
        if ($url === '') {
            return '';
        }
        return strpos($url, '@') !== false ? str_replace('@', rawurlencode($tracking), $url) : $url;
    }

    private function formatOctopiaTracking($tracking)
    {
        $tracking = trim((string) $tracking);
        if ($tracking === '') {
            return '';
        }
        if (strncasecmp($tracking, 'CS', 2) === 0) {
            return 'CS'.substr($tracking, 2);
        }
        return 'CS'.$tracking;
    }

    private function markShipmentSent(array $row, $tracking, $carrierName, $message, array $payload = [])
    {
        Db::getInstance()->update('cdiscountorders_order', [
            'shipment_sent_at' => date('Y-m-d H:i:s'),
            'shipment_number' => pSQL(Tools::substr($tracking, 0, 128)),
            'carrier_name' => pSQL(Tools::substr($carrierName, 0, 128)),
            'last_error' => null,
        ], 'id_cdiscountorders_order = '.(int) $row['id_cdiscountorders_order']);
        $this->module->addModuleLog('info', 'shipment', (string) $row['octopia_order_id'], (int) $row['id_order'], $message, $payload);
    }

    private function shipmentError(array $row, $message, array $payload = [])
    {
        Db::getInstance()->execute(
            'UPDATE `'._DB_PREFIX_.'cdiscountorders_order` SET attempts = attempts + 1, last_error = "'.pSQL(Tools::substr($message, 0, 4000)).'" WHERE id_cdiscountorders_order = '.(int) $row['id_cdiscountorders_order']
        );
        $this->module->addModuleLog('error', 'shipment', (string) $row['octopia_order_id'], (int) $row['id_order'], $message, $payload);
    }

    private function getUpdatedAtMin()
    {
        // Un controllo terminato con errori non deve far avanzare la finestra:
        // l'ordine problematico deve essere riproposto al controllo successivo.
        $lastSync = trim((string) Configuration::get('CDO_LAST_SUCCESS_AT'));
        if ($lastSync === '') {
            $lastSync = trim((string) Configuration::get('CDO_LAST_SYNC_AT'));
        }
        if ($lastSync !== '') {
            $timestamp = strtotime($lastSync.' UTC');
            if ($timestamp !== false) {
                return gmdate('Y-m-d\TH:i:s\Z', $timestamp - 900);
            }
        }
        $days = max(1, min(365, (int) Configuration::get('CDO_LOOKBACK_DAYS')));
        return gmdate('Y-m-d\TH:i:s\Z', time() - ($days * 86400));
    }

    private function acquireLock($name)
    {
        return (int) Db::getInstance()->getValue('SELECT GET_LOCK("'.pSQL($name).'", 0)') === 1;
    }

    private function releaseLock($name)
    {
        Db::getInstance()->getValue('SELECT RELEASE_LOCK("'.pSQL($name).'")');
    }

    private function cleanName($value)
    {
        $value = trim(strip_tags((string) $value));
        $value = preg_replace('/[0-9!<>,;?=+()@#"{}_$%:]/u', ' ', $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        return Tools::substr($value !== '' ? $value : 'Octopia', 0, 32);
    }

    private function money($value)
    {
        return round((float) $value, 6);
    }

    private function value($array, $key, $default = null)
    {
        return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
    }

    private function formatSummary(array $summary)
    {
        return 'Ordini letti: '.$summary['read'].', accettati: '.$summary['approved'].', importati: '.$summary['imported'].', ignorati/in attesa: '.$summary['skipped'].', errori: '.$summary['errors'].'.';
    }
}
