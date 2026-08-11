<?php
declare(strict_types=1);

final class EbayClient
{
    /**
     * Nome dell'Item Specific con cui eBay conserva il GTIN-13 sui siti europei
     * (site ID 101 = eBay Italia). E' il campo effettivamente memorizzato e
     * restituito da GetItem, a differenza di ProductListingDetails.EAN.
     */
    public const EAN_SPECIFIC_NAME = 'EAN';

    private array $settings;
    private array $config;
    private array $itemCache = [];
    private array $lastTradingWarnings = [];

    public function __construct(array $settings, array $config)
    {
        $this->settings = $settings;
        $this->config = $config;
        if (!function_exists('curl_init') || !function_exists('simplexml_load_string')) {
            throw new RuntimeException('Sul server servono le estensioni PHP cURL e SimpleXML.');
        }
    }

    public function testConnection(): array
    {
        $xml = $this->call('GetUser', '<GetUserRequest xmlns="urn:ebay:apis:eBLBaseComponents"><DetailLevel>ReturnAll</DetailLevel></GetUserRequest>');
        $user = $this->firstText($xml, '//*[local-name()="UserID"]');
        return ['user' => $user ?: 'Account autenticato'];
    }

    /**
     * Legge sempre la risposta corrente di eBay quando $forceRefresh e' true.
     * Non usare il valore in cache dopo una revisione: eBay puo' accettare una
     * chiamata senza applicare il GTIN alla scheda.
     */
    public function inspectItem(string $itemId, string $sku, bool $forceRefresh = false, bool $includeRevisionData = false): array
    {
        if (!$forceRefresh && isset($this->itemCache[$itemId])) {
            $xml = $this->itemCache[$itemId];
        } else {
            $body = '<GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
                . '<DetailLevel>ReturnAll</DetailLevel><IncludeItemSpecifics>true</IncludeItemSpecifics>'
                . '<ItemID>' . self::x($itemId) . '</ItemID></GetItemRequest>';
            $xml = $this->call('GetItem', $body);
            $this->itemCache[$itemId] = $xml;
        }
        $items = $xml->xpath('//*[local-name()="Item"]');
        if (!$items) throw new RuntimeException('Item ID non trovato su eBay.');
        $item = $items[0];
        $title = $this->relativeText($item, './*[local-name()="Title"]');
        $categoryId = $this->relativeText($item, './*[local-name()="PrimaryCategory"]/*[local-name()="CategoryID"]');
        $variations = $item->xpath('./*[local-name()="Variations"]/*[local-name()="Variation"]') ?: [];

        if ($variations) {
            foreach ($variations as $variation) {
                $remoteSku = trim($this->relativeText($variation, './*[local-name()="SKU"]'));
                if ($remoteSku !== $sku) continue;
                $ean = $this->relativeText($variation, './*[local-name()="VariationProductListingDetails"]/*[local-name()="EAN"]');
                $specifics = [];
                foreach ($variation->xpath('./*[local-name()="VariationSpecifics"]/*[local-name()="NameValueList"]') ?: [] as $nvl) {
                    $name = $this->relativeText($nvl, './*[local-name()="Name"]');
                    $values = [];
                    foreach ($nvl->xpath('./*[local-name()="Value"]') ?: [] as $v) $values[] = (string)$v;
                    if ($name !== '') $specifics[] = ['name' => $name, 'values' => $values];
                }
                $ean = trim($ean);
                if (self::eanMissing($ean)) $ean = '';
                return [
                    'title' => $title,
                    'type' => 'variation',
                    'remote_sku' => $remoteSku,
                    'category_id' => $categoryId,
                    'existing_ean' => $ean,
                    'specific_ean' => '',
                    'ean_source' => $ean !== '' ? 'variation' : '',
                    'variation' => [
                        'sku' => $remoteSku,
                        'quantity' => max(0, (int)$this->relativeText($variation, './*[local-name()="Quantity"]')
                            - (int)$this->relativeText($variation, './*[local-name()="SellingStatus"]/*[local-name()="QuantitySold"]')),
                        'start_price' => $this->relativeText($variation, './*[local-name()="StartPrice"]'),
                        'specifics' => $specifics,
                    ],
                ];
            }
            throw new RuntimeException('SKU variante non trovato nell’Item ID indicato.');
        }

        $remoteSku = trim($this->relativeText($item, './*[local-name()="SKU"]'));
        if ($remoteSku !== '' && $remoteSku !== $sku) {
            throw new RuntimeException('Lo SKU del file non corrisponde allo SKU dell’inserzione eBay.');
        }
        $hasItemSpecifics = (bool)($item->xpath('./*[local-name()="ItemSpecifics"]') ?: []);
        $itemSpecifics = $this->nameValueList(
            $item,
            './*[local-name()="ItemSpecifics"]/*[local-name()="NameValueList"]'
        );
        // Le due sedi dell'EAN vanno lette separatamente, perche' eBay le tratta
        // come dati distinti: ProductListingDetails.EAN e' l'identificatore di
        // prodotto (la colonna "P:EAN" dei report venditore), mentre la specifica
        // oggetto "EAN" e' una caratteristica dell'inserzione. Fonderle, come
        // faceva la v1.0.9, faceva risultare "EAN gia' presente" inserzioni il
        // cui identificatore di prodotto su eBay era in realta' vuoto.
        $productEan = trim($this->relativeText($item, './*[local-name()="ProductListingDetails"]/*[local-name()="EAN"]'));
        if (self::eanMissing($productEan)) $productEan = '';
        $specificEan = '';
        foreach ($itemSpecifics as $specific) {
            if (strcasecmp(trim((string)$specific['name']), self::EAN_SPECIFIC_NAME) !== 0) continue;
            foreach ($specific['values'] as $value) {
                $value = trim((string)$value);
                if (!self::eanMissing($value)) {
                    $specificEan = $value;
                    break 2;
                }
            }
        }

        $result = [
            'title' => $title,
            'type' => 'single',
            'remote_sku' => $remoteSku,
            'category_id' => $categoryId,
            'existing_ean' => $productEan,
            'specific_ean' => $specificEan,
            'ean_source' => $productEan !== '' ? 'product' : ($specificEan !== '' ? 'specific' : ''),
            'variation' => null,
        ];
        if ($includeRevisionData) {
            $result['revision_data'] = [
                'title' => $title,
                'category_id' => $categoryId,
                'item_specifics' => $itemSpecifics,
                'has_item_specifics' => $hasItemSpecifics,
                'product_details' => [
                    'brand' => $this->relativeText($item, './*[local-name()="ProductListingDetails"]/*[local-name()="BrandMPN"]/*[local-name()="Brand"]'),
                    'mpn' => $this->relativeText($item, './*[local-name()="ProductListingDetails"]/*[local-name()="BrandMPN"]/*[local-name()="MPN"]'),
                    'isbn' => $this->relativeText($item, './*[local-name()="ProductListingDetails"]/*[local-name()="ISBN"]'),
                    'product_reference_id' => $this->relativeText($item, './*[local-name()="ProductListingDetails"]/*[local-name()="ProductReferenceID"]'),
                    'upc' => $this->relativeText($item, './*[local-name()="ProductListingDetails"]/*[local-name()="UPC"]'),
                ],
            ];
        }
        return $result;
    }

    /**
     * Inserisce l'EAN su una inserzione Trading senza varianti.
     *
     * ProductListingDetails.EAN NON e' il campo in cui eBay memorizza il GTIN:
     * serve unicamente a cercare un prodotto nel catalogo eBay. Se il match non
     * avviene - e con IncludeeBayProductDetails=false non viene neppure tentato -
     * il valore viene scartato, la chiamata torna comunque Ack=Success/Warning e
     * GetItem non restituisce alcun ProductListingDetails. Era esattamente il
     * caso in cui l'app segnalava "eBay ha accettato la richiesta ma non ha
     * salvato l'EAN".
     *
     * La documentazione eBay indica gli Item Specifics come canale corretto:
     * i product identifier (GTIN, Brand, MPN) vanno passati in
     * ItemSpecifics.NameValueList, perche' i valori inviati solo tramite
     * ProductListingDetails vengono ignorati senza abbinamento al catalogo.
     * Inviamo quindi l'EAN come Item Specific "EAN" e lo ripetiamo, identico,
     * in ProductListingDetails per le categorie che lo usano ancora.
     *
     * ItemSpecifics in revisione sostituisce l'intero blocco: rimandiamo tutte
     * le specifiche lette un istante prima piu' la nuova coppia EAN.
     *
     * @return string[] eventuali warning non bloccanti restituiti da eBay
     */
    public function reviseSingle(string $itemId, string $ean, array $revisionData): array
    {
        $itemSpecifics = is_array($revisionData['item_specifics'] ?? null) ? $revisionData['item_specifics'] : [];
        $productDetails = is_array($revisionData['product_details'] ?? null) ? $revisionData['product_details'] : [];

        // Se eBay ha restituito il contenitore ItemSpecifics ma la lettura non ha
        // prodotto alcuna coppia, i dati sono incompleti: reinviarli cancellerebbe
        // le specifiche dell'inserzione, perche' la revisione le sostituisce tutte.
        if (($revisionData['has_item_specifics'] ?? false) && !$itemSpecifics) {
            throw new RuntimeException('Specifiche dell’inserzione non rilette correttamente: aggiornamento EAN bloccato per sicurezza.');
        }
        // Non usare GetCategoryFeatures qui: eBay ha spostato la verifica
        // degli identificatori di prodotto sulla Taxonomy API e il vecchio
        // controllo puo' rispondere HTTP 410. ReviseFixedPriceItem resta la
        // fonte autorevole: se la categoria non accetta l'EAN, restituisce un
        // errore Trading esplicito che viene mostrato nel report.

        $specificsXml = '';
        foreach ($itemSpecifics as $specific) {
            $name = trim((string)($specific['name'] ?? ''));
            $values = is_array($specific['values'] ?? null) ? $specific['values'] : [];
            if ($name === '' || !$values) continue;
            // L'EAN viene riscritto sotto: salta l'eventuale vecchia coppia
            // "EAN: Non applicabile" per non duplicare la specifica.
            if (strcasecmp($name, self::EAN_SPECIFIC_NAME) === 0) continue;
            $specificsXml .= '<NameValueList><Name>' . self::x($name) . '</Name>';
            foreach ($values as $value) $specificsXml .= '<Value>' . self::x((string)$value) . '</Value>';
            $specificsXml .= '</NameValueList>';
        }
        $specificsXml .= '<NameValueList><Name>' . self::EAN_SPECIFIC_NAME . '</Name>'
            . '<Value>' . self::x($ean) . '</Value></NameValueList>';

        // Nessun altro campo dell'inserzione viene rimandato: titolo, descrizione,
        // immagini e categoria restano quelli di eBay e non rischiano alterazioni.
        // Ordine conforme allo schema ItemType e ProductListingDetailsType.
        $body = '<ReviseFixedPriceItemRequest xmlns="urn:ebay:apis:eBLBaseComponents"><Item>'
            . '<ItemID>' . self::x($itemId) . '</ItemID>'
            . '<ItemSpecifics>' . $specificsXml . '</ItemSpecifics>'
            . '<ProductListingDetails>';
        $brand = trim((string)($productDetails['brand'] ?? ''));
        $mpn = trim((string)($productDetails['mpn'] ?? ''));
        if ($brand !== '' && $mpn !== '') {
            $body .= '<BrandMPN><Brand>' . self::x($brand) . '</Brand><MPN>' . self::x($mpn) . '</MPN></BrandMPN>';
        }
        $body .= '<EAN>' . self::x($ean) . '</EAN>';
        $isbn = trim((string)($productDetails['isbn'] ?? ''));
        if ($isbn !== '') $body .= '<ISBN>' . self::x($isbn) . '</ISBN>';
        $body .= '<IncludeeBayProductDetails>false</IncludeeBayProductDetails>';
        $productReferenceId = trim((string)($productDetails['product_reference_id'] ?? ''));
        if ($productReferenceId !== '') $body .= '<ProductReferenceID>' . self::x($productReferenceId) . '</ProductReferenceID>';
        $upc = trim((string)($productDetails['upc'] ?? ''));
        if ($upc !== '') $body .= '<UPC>' . self::x($upc) . '</UPC>';
        $body .= '</ProductListingDetails></Item></ReviseFixedPriceItemRequest>';
        $this->call('ReviseFixedPriceItem', $body);
        return $this->lastTradingWarnings;
    }

    public function reviseVariations(string $itemId, array $rows): void
    {
        $vxml = '';
        foreach ($rows as $row) {
            $v = $row['remote']['variation'] ?? null;
            if (!$v || ($v['start_price'] ?? '') === '' || !array_key_exists('quantity', $v) || empty($v['specifics'])) {
                throw new RuntimeException('Dati variante incompleti: aggiornamento bloccato per sicurezza.');
            }
            // Ordine conforme a VariationType: Quantity, SKU, StartPrice,
            // VariationProductListingDetails, VariationSpecifics.
            $vxml .= '<Variation><Quantity>' . (int)$v['quantity'] . '</Quantity>'
                . '<SKU>' . self::x((string)$v['sku']) . '</SKU>'
                . '<StartPrice>' . self::x((string)$v['start_price']) . '</StartPrice>'
                . '<VariationProductListingDetails><EAN>' . self::x((string)$row['ean'])
                . '</EAN></VariationProductListingDetails><VariationSpecifics>';
            foreach ($v['specifics'] as $spec) {
                $vxml .= '<NameValueList><Name>' . self::x((string)$spec['name']) . '</Name>';
                foreach ($spec['values'] as $value) $vxml .= '<Value>' . self::x((string)$value) . '</Value>';
                $vxml .= '</NameValueList>';
            }
            $vxml .= '</VariationSpecifics></Variation>';
        }
        $body = '<ReviseFixedPriceItemRequest xmlns="urn:ebay:apis:eBLBaseComponents"><Item>'
            . '<ItemID>' . self::x($itemId) . '</ItemID><Variations>' . $vxml
            . '</Variations></Item></ReviseFixedPriceItemRequest>';
        $this->call('ReviseFixedPriceItem', $body);
    }

    /**
     * Restituisce l'Inventory Item gestito dalla REST Inventory API.
     * Un 404 significa che lo SKU appartiene al vecchio modello Trading API.
     */
    public function getInventoryItem(string $sku): ?array
    {
        [$http, $body] = $this->restCall(
            'GET',
            '/sell/inventory/v1/inventory_item/' . rawurlencode($sku)
        );
        if ($http === 404) return null;
        if ($http !== 200 || !is_array($body)) {
            throw new RuntimeException($this->restError($http, $body));
        }
        return $body;
    }

    /**
     * createOrReplaceInventoryItem sostituisce l'intero record. Per sicurezza
     * rimandiamo tutti e soli i campi modificabili letti immediatamente prima,
     * cambiando esclusivamente product.ean.
     */
    public function updateInventoryEan(string $sku, string $ean, array $current): array
    {
        $existing = $this->inventoryEan($current);
        if ($existing === $ean) return $current;
        if (!self::eanMissing($existing) && $existing !== $ean) {
            throw new RuntimeException('Inventory API contiene già un EAN diverso: aggiornamento bloccato.');
        }
        if (!isset($current['product']) || !is_array($current['product'])) {
            throw new RuntimeException('Dati prodotto Inventory API incompleti: aggiornamento bloccato per sicurezza.');
        }

        $payload = [];
        foreach (['availability', 'condition', 'conditionDescription', 'conditionDescriptors', 'packageWeightAndSize', 'product'] as $key) {
            if (array_key_exists($key, $current)) $payload[$key] = $current[$key];
        }
        $payload['product']['ean'] = [$ean];

        [$http, $body] = $this->restCall(
            'PUT',
            '/sell/inventory/v1/inventory_item/' . rawurlencode($sku),
            $payload
        );
        if ($http !== 204) {
            throw new RuntimeException($this->restError($http, $body));
        }

        // Conferma sul record sorgente dell'Inventory API. Piccoli ritardi di
        // propagazione sono possibili, quindi effettuiamo tre riletture brevi.
        $verified = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) usleep(500000);
            $verified = $this->getInventoryItem($sku);
            if (is_array($verified) && $this->inventoryEan($verified) === $ean) return $verified;
        }
        throw new RuntimeException('Inventory API ha accettato la richiesta ma non ha restituito l\'EAN aggiornato.');
    }

    public function inventoryEan(array $inventoryItem): string
    {
        $values = $inventoryItem['product']['ean'] ?? [];
        if (is_string($values)) return trim($values);
        if (!is_array($values)) return '';
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') return $value;
        }
        return '';
    }

    /**
     * Conferma il dato rileggendo eBay, non il payload appena inviato.
     * L'indicizzazione della revisione non e' immediata: attendiamo con pause
     * crescenti prima di dichiarare fallito l'inserimento.
     *
     * La conferma piena richiede l'identificatore di prodotto, l'unico che eBay
     * espone nella colonna "P:EAN" dei report venditore. Se eBay ha salvato solo
     * la specifica oggetto, la rilettura viene restituita con ean_confirmed =
     * 'specific': l'esito e' parziale e va segnalato come tale, non come
     * inserimento riuscito.
     */
    public function confirmEan(string $itemId, string $sku, string $expected): array
    {
        $current = [];
        $actual = '';
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if ($attempt > 0) sleep($attempt);
            $current = $this->inspectItem($itemId, $sku, true);
            $actual = trim((string)($current['existing_ean'] ?? ''));
            if ($actual === $expected) {
                $current['ean_confirmed'] = 'product';
                return $current;
            }
        }
        if (trim((string)($current['specific_ean'] ?? '')) === $expected) {
            $current['ean_confirmed'] = 'specific';
            return $current;
        }
        throw new RuntimeException(
            'eBay ha accettato la richiesta ma non ha salvato l\'EAN. '
            . ($actual === '' ? 'L\'EAN risulta ancora assente.' : 'EAN attuale: ' . $actual . '.')
        );
    }

    public static function eanMissing(string $value): bool
    {
        $v = strtolower(trim($value));
        return $v === '' || in_array($v, ['does not apply', 'doesnotapply', 'non applicabile', 'n/a', 'na'], true);
    }

    private function call(string $name, string $xmlBody): SimpleXMLElement
    {
        $this->lastTradingWarnings = [];
        // Hostinger può negoziare HTTP/2 con il gateway legacy della Trading API.
        // In alcuni casi il body viene interpretato/troncato come SOAP e eBay
        // restituisce l'errore XML 5. Forziamo HTTP/1.1 e una connessione nuova.
        $xmlBody = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xmlBody;
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        if (!simplexml_load_string($xmlBody, 'SimpleXMLElement', LIBXML_NONET)) {
            $errors = libxml_get_errors();
            $detail = $errors ? trim((string)$errors[0]->message) : 'errore sconosciuto';
            libxml_clear_errors();
            throw new RuntimeException('XML eBay generato localmente non valido: ' . $detail);
        }

        $token = $this->accessToken();
        $ch = curl_init('https://api.ebay.com/ws/api.dll');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xmlBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=UTF-8',
                'Accept: text/xml',
                'Expect:',
                'Connection: close',
                'Content-Length: ' . strlen($xmlBody),
                'X-EBAY-API-CALL-NAME: ' . $name,
                'X-EBAY-API-SITEID: ' . ($this->config['site_id'] ?? '101'),
                'X-EBAY-API-COMPATIBILITY-LEVEL: ' . ($this->config['compatibility_level'] ?? '1423'),
                'X-EBAY-API-IAF-TOKEN: ' . $token,
            ],
        ]);
        $response = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($response === false || $http < 200 || $http >= 300) {
            throw new RuntimeException('Errore HTTP eBay ' . $http . ($err ? ': ' . $err : ''));
        }
        libxml_clear_errors();
        $xml = simplexml_load_string((string)$response, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        if (!$xml) throw new RuntimeException('Risposta XML eBay non valida.');
        $ack = $this->firstText($xml, '//*[local-name()="Ack"]');
        $messages = [];
        foreach ($xml->xpath('//*[local-name()="Errors"]') ?: [] as $e) {
            $code = $this->relativeText($e, './*[local-name()="ErrorCode"]');
            $severity = $this->relativeText($e, './*[local-name()="SeverityCode"]');
            $msg = $this->relativeText($e, './*[local-name()="LongMessage"]') ?: $this->relativeText($e, './*[local-name()="ShortMessage"]');
            $formatted = trim(($severity !== '' ? $severity . ' ' : '') . $code . ' ' . $msg);
            $messages[] = $formatted;
            if (strcasecmp($severity, 'Warning') === 0 && $formatted !== '') $this->lastTradingWarnings[] = $formatted;
        }
        // eBay puo' rispondere Ack=Warning (ad esempio 21919456 sulle
        // Business Policies) pur avendo applicato correttamente la revisione.
        // La conferma del GTIN viene fatta subito dopo con GetItem.
        if (!in_array($ack, ['Success', 'Warning'], true)) {
            throw new RuntimeException('eBay: ' . ($messages ? implode(' | ', $messages) : 'operazione rifiutata.'));
        }
        return $xml;
    }

    private function accessToken(): string
    {
        $dataDir = rtrim((string)$this->config['data_dir'], '/');
        if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) throw new RuntimeException('Cartella dati non disponibile.');
        $cachePath = $dataDir . '/token.json';
        $key = hash('sha256', ($this->settings['client_id'] ?? '') . '|' . ($this->settings['refresh_token'] ?? ''));
        if (is_file($cachePath)) {
            $cached = json_decode((string)file_get_contents($cachePath), true);
            if (is_array($cached) && ($cached['key'] ?? '') === $key && (int)($cached['expires_at'] ?? 0) > time() + 120 && !empty($cached['access_token'])) {
                return (string)$cached['access_token'];
            }
        }
        $clientId = (string)($this->settings['client_id'] ?? '');
        $secret = (string)($this->settings['client_secret'] ?? '');
        $refresh = (string)($this->settings['refresh_token'] ?? '');
        $scope = trim((string)($this->settings['scope'] ?? ''));
        if ($clientId === '' || $secret === '' || $refresh === '') throw new RuntimeException('Credenziali eBay non configurate.');
        $form = ['grant_type' => 'refresh_token', 'refresh_token' => $refresh];
        if ($scope !== '') $form['scope'] = $scope;
        $ch = curl_init('https://api.ebay.com/identity/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($clientId . ':' . $secret),
            ],
        ]);
        $response = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string)$response, true);
        if ($http < 200 || $http >= 300 || !is_array($json) || empty($json['access_token'])) {
            $desc = is_array($json) ? (string)($json['error_description'] ?? $json['error'] ?? '') : '';
            throw new RuntimeException('OAuth eBay non riuscito' . ($desc ? ': ' . $desc : '.'));
        }
        $cached = ['key' => $key, 'access_token' => $json['access_token'], 'expires_at' => time() + (int)($json['expires_in'] ?? 7200)];
        file_put_contents($cachePath, json_encode($cached), LOCK_EX);
        return (string)$json['access_token'];
    }

    /** @return array{0:int,1:array|null} */
    private function restCall(string $method, string $path, ?array $payload = null): array
    {
        $ch = curl_init('https://api.ebay.com' . $path);
        $headers = [
            'Authorization: Bearer ' . $this->accessToken(),
            'Accept: application/json',
            'Accept-Language: it-IT',
            'Content-Language: it-IT',
            'Expect:',
        ];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
        ];
        if ($payload !== null) {
            try {
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $e) {
                throw new RuntimeException('Impossibile preparare i dati Inventory API.');
            }
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
            $options[CURLOPT_POSTFIELDS] = $json;
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new RuntimeException('Collegamento Inventory API non riuscito' . ($error ? ': ' . $error : '.'));
        }
        $body = trim((string)$response) === '' ? null : json_decode((string)$response, true);
        return [$http, is_array($body) ? $body : null];
    }

    private function restError(int $http, ?array $body): string
    {
        $messages = [];
        foreach (($body['errors'] ?? []) as $error) {
            if (!is_array($error)) continue;
            $id = trim((string)($error['errorId'] ?? ''));
            $message = trim((string)($error['longMessage'] ?? $error['message'] ?? ''));
            if ($message !== '') $messages[] = trim(($id !== '' ? $id . ' ' : '') . $message);
        }
        if ($http === 403 && !$messages) {
            $messages[] = 'il refresh token non dispone dello scope sell.inventory.';
        }
        return 'Inventory API eBay HTTP ' . $http . ': ' . ($messages ? implode(' | ', $messages) : 'operazione rifiutata.');
    }

    private function firstText(SimpleXMLElement $xml, string $path): string
    {
        $n = $xml->xpath($path);
        return $n ? trim((string)$n[0]) : '';
    }

    private function relativeText(SimpleXMLElement $xml, string $path): string
    {
        $n = $xml->xpath($path);
        return $n ? trim((string)$n[0]) : '';
    }

    private function nameValueList(SimpleXMLElement $xml, string $path): array
    {
        $result = [];
        foreach ($xml->xpath($path) ?: [] as $nvl) {
            $name = $this->relativeText($nvl, './*[local-name()="Name"]');
            $values = [];
            foreach ($nvl->xpath('./*[local-name()="Value"]') ?: [] as $value) {
                $values[] = (string)$value;
            }
            if ($name !== '' && $values) $result[] = ['name' => $name, 'values' => $values];
        }
        return $result;
    }

    private static function x(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
}
