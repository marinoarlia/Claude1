<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class Cdsync extends Module
{
    const VERSION = '1.0.0';

    /** @var string */
    protected $currentTab = 'config';

    public function __construct()
    {
        $this->name      = 'cdsync';
        $this->tab       = 'market_place';
        $this->version   = self::VERSION;
        $this->author    = 'Masterbrico';
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('Cdiscount Sync');
        $this->description = $this->l('Sincronizza prodotti, prezzi e ordini con Cdiscount via Octopia API.');
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    // =========================================================================
    // INSTALL / UNINSTALL
    // =========================================================================

    public function install()
    {
        return parent::install() && $this->createTables() && $this->setDefaults();
    }

    public function uninstall()
    {
        foreach ([
            'CDS2_CLIENT_ID','CDS2_CLIENT_SECRET','CDS2_SELLER_ID',
            'CDS2_COMMISSION_RATE','CDS2_SHIPPING_TIERS',
            'CDS2_AI_ENDPOINT','CDS2_AI_MODEL',
            'CDS2_CRON_TOKEN','CDS2_LAST_ORDER_SYNC',
            'CDS2_CATEGORY_LAST_SYNC',
        ] as $k) {
            Configuration::deleteByName($k);
        }
        return parent::uninstall();
    }

    private function createTables()
    {
        $db = Db::getInstance();
        $p  = _DB_PREFIX_;
        $e  = _MYSQL_ENGINE_;

        $ok = true;
        $ok = $ok && $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cds2_product` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_product` INT UNSIGNED NOT NULL,
            `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
            `enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `catalog_status` VARCHAR(32) NOT NULL DEFAULT 'none',
            `cds_status` VARCHAR(32) NOT NULL DEFAULT 'none',
            `last_price` DECIMAL(20,6) DEFAULT NULL,
            `last_stock` INT DEFAULT NULL,
            `last_sync` DATETIME DEFAULT NULL,
            `last_error` TEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_pa` (`id_product`,`id_product_attribute`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;");

        $ok = $ok && $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cds2_translation` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_product` INT UNSIGNED NOT NULL,
            `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
            `src_hash` VARCHAR(64) DEFAULT NULL,
            `title_fr` VARCHAR(510) DEFAULT NULL,
            `desc_short_fr` TEXT DEFAULT NULL,
            `desc_fr` MEDIUMTEXT DEFAULT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'none',
            `updated_at` DATETIME DEFAULT NULL,
            `last_error` TEXT DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_trans` (`id_product`,`id_product_attribute`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;");

        $ok = $ok && $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cds2_category_map` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_category` INT UNSIGNED NOT NULL,
            `cds_reference` VARCHAR(16) DEFAULT NULL,
            `cds_label` VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_cat` (`id_category`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;");

        $ok = $ok && $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cds2_category` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reference` VARCHAR(16) NOT NULL,
            `label` VARCHAR(255) NOT NULL DEFAULT '',
            `level` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `parent_reference` VARCHAR(16) DEFAULT NULL,
            `parent_references` VARCHAR(255) DEFAULT NULL,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ref` (`reference`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;");

        $ok = $ok && $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cds2_order` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `cds_order_id` VARCHAR(64) NOT NULL,
            `id_order` INT UNSIGNED DEFAULT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'imported',
            `raw` MEDIUMTEXT DEFAULT NULL,
            `imported_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_cds_order` (`cds_order_id`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;");

        return $ok;
    }

    private function setDefaults()
    {
        $tiers = json_encode([
            ['max_weight' => 0.5,  'price' => 4.99],
            ['max_weight' => 1.0,  'price' => 5.99],
            ['max_weight' => 2.0,  'price' => 6.99],
            ['max_weight' => 5.0,  'price' => 8.99],
            ['max_weight' => 30.0, 'price' => 12.99],
        ]);
        $defaults = [
            'CDS2_COMMISSION_RATE' => '15',
            'CDS2_AI_ENDPOINT'     => 'https://ollama.masterbrico.com',
            'CDS2_AI_MODEL'        => 'qwen2.5:7b',
            'CDS2_CRON_TOKEN'      => substr(md5(uniqid('cds2', true)), 0, 24),
            'CDS2_SHIPPING_TIERS'  => $tiers,
        ];
        foreach ($defaults as $k => $v) {
            if (!Configuration::get($k)) {
                Configuration::updateValue($k, $v);
            }
        }
        return true;
    }

    // =========================================================================
    // ADMIN URL HELPER  (evita errori token CSRF)
    // =========================================================================

    private function adminUrl(array $extra = [])
    {
        return 'index.php?' . http_build_query(array_merge([
            'controller' => 'AdminModules',
            'configure'  => $this->name,
            'token'      => Tools::getAdminTokenLite('AdminModules'),
        ], $extra));
    }

    // =========================================================================
    // ENTRY POINT
    // =========================================================================

    public function getContent()
    {
        $ajax = (string) Tools::getValue('cds_ajax');
        if ($ajax !== '') {
            $this->handleAjax($ajax);
            exit;
        }

        $this->currentTab = (string) Tools::getValue('cds_tab', 'config');
        $output = $this->handlePost();

        return $output
            . $this->renderTabs()
            . $this->renderCurrentTab();
    }

    // =========================================================================
    // POST HANDLERS
    // =========================================================================

    private function handlePost()
    {
        if (Tools::isSubmit('submitCds2Config')) {
            return $this->postConfig();
        }
        if (Tools::isSubmit('submitCds2CategoryImport')) {
            return $this->postCategoryImport();
        }
        if (Tools::isSubmit('submitCds2CategoryMap')) {
            return $this->postCategoryMap();
        }
        if (Tools::isSubmit('submitCds2Products')) {
            return $this->postProducts();
        }
        return '';
    }

    private function postConfig()
    {
        $fields = [
            'CDS2_CLIENT_ID','CDS2_CLIENT_SECRET','CDS2_SELLER_ID',
            'CDS2_AI_ENDPOINT','CDS2_AI_MODEL',
        ];
        foreach ($fields as $f) {
            Configuration::updateValue($f, pSQL(trim((string) Tools::getValue($f))));
        }
        $rate = (float) str_replace(',', '.', Tools::getValue('CDS2_COMMISSION_RATE'));
        Configuration::updateValue('CDS2_COMMISSION_RATE', $rate);

        // shipping tiers
        $tiers = [];
        $weights = Tools::getValue('tier_weight', []);
        $prices  = Tools::getValue('tier_price',  []);
        foreach ($weights as $i => $w) {
            $w = (float) str_replace(',', '.', $w);
            $price = (float) str_replace(',', '.', $prices[$i] ?? 0);
            if ($w > 0 && $price > 0) {
                $tiers[] = ['max_weight' => $w, 'price' => $price];
            }
        }
        if (!empty($tiers)) {
            usort($tiers, fn($a, $b) => $a['max_weight'] <=> $b['max_weight']);
            Configuration::updateValue('CDS2_SHIPPING_TIERS', json_encode($tiers));
        }

        return $this->displayConfirmation($this->l('Configurazione salvata.'));
    }

    private function postCategoryImport()
    {
        try {
            $result = $this->downloadCategories();
            if ($result['success']) {
                Configuration::updateValue('CDS2_CATEGORY_LAST_SYNC', date('Y-m-d H:i:s'));
                return $this->displayConfirmation(
                    sprintf($this->l('Categorie importate: %d'), $result['count'])
                );
            }
            return $this->displayError($result['message']);
        } catch (Throwable $e) {
            return $this->displayError($e->getMessage());
        }
    }

    private function postCategoryMap()
    {
        $ids  = Tools::getValue('map_id',  []);
        $cdss = Tools::getValue('map_cds', []);
        if (!is_array($ids)) {
            return $this->displayError($this->l('Nessun dato.'));
        }
        $p = _DB_PREFIX_;
        foreach ($ids as $i => $rawId) {
            $idCategory = (int) $rawId;
            if ($idCategory <= 0) { continue; }
            $input     = trim((string)($cdss[$i] ?? ''));
            $reference = $this->extractReference($input);
            $label     = '';
            if ($reference) {
                $row = Db::getInstance()->getRow(
                    "SELECT label FROM `{$p}cds2_category` WHERE reference='" . pSQL($reference) . "'"
                );
                $label = $row ? (string) $row['label'] : '';
            }
            Db::getInstance()->execute(
                "INSERT INTO `{$p}cds2_category_map` (id_category,cds_reference,cds_label)
                 VALUES ({$idCategory},'" . pSQL($reference) . "','" . pSQL($label) . "')
                 ON DUPLICATE KEY UPDATE cds_reference='" . pSQL($reference) . "', cds_label='" . pSQL($label) . "'"
            );
        }
        return $this->displayConfirmation($this->l('Mapping salvato.'));
    }

    private function postProducts()
    {
        $p       = _DB_PREFIX_;
        $enabled = Tools::getValue('enabled_products', []);
        if (!is_array($enabled)) { $enabled = []; }

        // disable all, then re-enable selected
        Db::getInstance()->execute("UPDATE `{$p}cds2_product` SET enabled=0");

        foreach ($enabled as $key) {
            [$idP, $idA] = array_map('intval', explode('_', $key . '_0'));
            Db::getInstance()->execute(
                "INSERT INTO `{$p}cds2_product` (id_product,id_product_attribute,enabled)
                 VALUES ({$idP},{$idA},1)
                 ON DUPLICATE KEY UPDATE enabled=1"
            );
        }
        return $this->displayConfirmation(
            sprintf($this->l('%d prodotti selezionati per la sincronizzazione.'), count($enabled))
        );
    }

    // =========================================================================
    // AJAX
    // =========================================================================

    private function handleAjax($action)
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            switch ($action) {
                case 'translate_one':
                    echo json_encode($this->ajaxTranslateOne());
                    break;
                case 'sync_one':
                    echo json_encode($this->ajaxSyncOne());
                    break;
                case 'import_orders':
                    echo json_encode($this->ajaxImportOrders());
                    break;
                default:
                    echo json_encode(['success' => false, 'message' => 'Azione sconosciuta']);
            }
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function ajaxTranslateOne()
    {
        $idProduct = (int) Tools::getValue('id_product');
        $idAttr    = (int) Tools::getValue('id_product_attribute');
        if ($idProduct <= 0) {
            return ['success' => false, 'message' => 'ID prodotto mancante'];
        }

        $idLang = $this->getItalianLangId();
        $product = new Product($idProduct, false, $idLang);
        $title   = trim((string) $product->name);
        $short   = trim(strip_tags((string) $product->description_short));
        $desc    = trim(strip_tags((string) $product->description));

        if (!$title) {
            return ['success' => false, 'message' => 'Prodotto non trovato o nome vuoto'];
        }

        $hash = md5($title . '|' . $short . '|' . $desc);
        $p    = _DB_PREFIX_;

        // check if already translated and not changed
        $existing = Db::getInstance()->getRow(
            "SELECT src_hash, status FROM `{$p}cds2_translation`
             WHERE id_product={$idProduct} AND id_product_attribute={$idAttr}"
        );
        if ($existing && $existing['src_hash'] === $hash && $existing['status'] === 'done') {
            return ['success' => true, 'message' => 'Già tradotto (invariato)', 'skipped' => true];
        }

        // translate each field
        $titleFr = $this->ollamaTranslate($title);
        if ($titleFr === null) {
            return ['success' => false, 'message' => 'Ollama non raggiungibile o risposta vuota per il titolo'];
        }

        $shortFr = $short ? $this->ollamaTranslate($short) : '';
        if ($short && $shortFr === null) {
            return ['success' => false, 'message' => 'Ollama: errore sulla descrizione breve'];
        }

        $descFr = $desc ? $this->ollamaTranslate($desc) : '';
        if ($desc && $descFr === null) {
            return ['success' => false, 'message' => 'Ollama: errore sulla descrizione lunga'];
        }

        Db::getInstance()->execute(
            "INSERT INTO `{$p}cds2_translation`
             (id_product,id_product_attribute,src_hash,title_fr,desc_short_fr,desc_fr,status,updated_at,last_error)
             VALUES ({$idProduct},{$idAttr},
                 '" . pSQL($hash) . "','" . pSQL((string)$titleFr) . "',
                 '" . pSQL((string)$shortFr) . "','" . pSQL((string)$descFr) . "',
                 'done',NOW(),'')
             ON DUPLICATE KEY UPDATE
                 src_hash='" . pSQL($hash) . "',title_fr='" . pSQL((string)$titleFr) . "',
                 desc_short_fr='" . pSQL((string)$shortFr) . "',desc_fr='" . pSQL((string)$descFr) . "',
                 status='done',updated_at=NOW(),last_error=''"
        );

        return ['success' => true, 'message' => 'Tradotto: ' . $titleFr];
    }

    private function ajaxSyncOne()
    {
        $idProduct = (int) Tools::getValue('id_product');
        $idAttr    = (int) Tools::getValue('id_product_attribute');
        if ($idProduct <= 0) {
            return ['success' => false, 'message' => 'ID prodotto mancante'];
        }
        $result = $this->syncOffer($idProduct, $idAttr);
        $p      = _DB_PREFIX_;
        $status = $result['success'] ? 'synced' : 'error';
        $err    = $result['success'] ? '' : $result['message'];
        Db::getInstance()->execute(
            "UPDATE `{$p}cds2_product` SET
             cds_status='" . pSQL($status) . "',
             last_error='" . pSQL($err) . "',
             last_sync=NOW()"
            . ($result['price'] !== null ? ',last_price=' . (float)$result['price'] : '')
            . ($result['stock'] !== null ? ',last_stock=' . (int)$result['stock'] : '')
            . " WHERE id_product={$idProduct} AND id_product_attribute={$idAttr}"
        );
        return $result;
    }

    private function ajaxImportOrders()
    {
        return $this->importCdiscountOrders();
    }

    // =========================================================================
    // TABS RENDERING
    // =========================================================================

    private function renderTabs()
    {
        $tabs = [
            'config'      => ['icon' => '⚙️', 'label' => '1. Configurazione'],
            'categories'  => ['icon' => '🗂️', 'label' => '2. Categorie'],
            'products'    => ['icon' => '📦', 'label' => '3. Prodotti'],
            'translations'=> ['icon' => '🌐', 'label' => '4. Traduzioni'],
            'sync'        => ['icon' => '🔄', 'label' => '5. Sincronizza'],
            'orders'      => ['icon' => '📋', 'label' => '6. Ordini'],
        ];

        $html = '<ul class="nav nav-tabs" style="margin-bottom:20px;margin-top:10px;">';
        foreach ($tabs as $key => $tab) {
            $active = ($key === $this->currentTab) ? 'active' : '';
            $url    = htmlspecialchars($this->adminUrl(['cds_tab' => $key]), ENT_QUOTES, 'UTF-8');
            $html  .= "<li class='{$active}'><a href='{$url}'>{$tab['icon']} {$tab['label']}</a></li>";
        }
        $html .= '</ul>';
        return $html;
    }

    private function renderCurrentTab()
    {
        try {
            switch ($this->currentTab) {
                case 'config':       return $this->renderTabConfig();
                case 'categories':   return $this->renderTabCategories();
                case 'products':     return $this->renderTabProducts();
                case 'translations': return $this->renderTabTranslations();
                case 'sync':         return $this->renderTabSync();
                case 'orders':       return $this->renderTabOrders();
                default:             return $this->renderTabConfig();
            }
        } catch (Throwable $e) {
            return $this->displayError('Errore nel rendering del tab: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // TAB 1 – CONFIGURAZIONE
    // =========================================================================

    private function renderTabConfig()
    {
        $action = htmlspecialchars($this->adminUrl(['cds_tab' => 'config']), ENT_QUOTES, 'UTF-8');

        $f = fn($k) => htmlspecialchars((string) Configuration::get($k), ENT_QUOTES, 'UTF-8');
        $clientId     = $f('CDS2_CLIENT_ID');
        $clientSecret = $f('CDS2_CLIENT_SECRET');
        $sellerId     = $f('CDS2_SELLER_ID');
        $aiEndpoint   = $f('CDS2_AI_ENDPOINT');
        $aiModel      = $f('CDS2_AI_MODEL');
        $commission   = $f('CDS2_COMMISSION_RATE');
        $cronToken    = $f('CDS2_CRON_TOKEN');
        $cronUrl      = htmlspecialchars(
            _PS_BASE_URL_ . __PS_BASE_URI__ . 'modules/cdsync/cron/sync.php?token=' . Configuration::get('CDS2_CRON_TOKEN'),
            ENT_QUOTES, 'UTF-8'
        );

        $tiers     = json_decode(Configuration::get('CDS2_SHIPPING_TIERS') ?: '[]', true) ?: [];
        $tiersRows = '';
        foreach ($tiers as $i => $t) {
            $w = htmlspecialchars((string)$t['max_weight'], ENT_QUOTES, 'UTF-8');
            $p = htmlspecialchars((string)$t['price'], ENT_QUOTES, 'UTF-8');
            $tiersRows .= "
            <tr>
                <td><input type='number' step='0.1' name='tier_weight[]' value='{$w}' class='form-control' style='width:100px;'> kg</td>
                <td><input type='number' step='0.01' name='tier_price[]' value='{$p}' class='form-control' style='width:100px;'> €</td>
                <td><button type='button' class='btn btn-xs btn-danger' onclick='this.closest(\"tr\").remove()'>✕</button></td>
            </tr>";
        }

        return <<<HTML
<div class="panel">
    <h3>⚙️ Configurazione API Octopia (Cdiscount)</h3>
    <form method="post" action="{$action}">
        <div class="form-group">
            <label>Client ID</label>
            <input type="text" name="CDS2_CLIENT_ID" value="{$clientId}" class="form-control">
        </div>
        <div class="form-group">
            <label>Client Secret</label>
            <input type="password" name="CDS2_CLIENT_SECRET" value="{$clientSecret}" class="form-control">
        </div>
        <div class="form-group">
            <label>Seller ID</label>
            <input type="text" name="CDS2_SELLER_ID" value="{$sellerId}" class="form-control">
        </div>
        <hr>
        <h4>Commissione Cdiscount</h4>
        <div class="form-group">
            <label>Percentuale commissione (%)</label>
            <input type="number" step="0.1" name="CDS2_COMMISSION_RATE" value="{$commission}" class="form-control" style="width:120px;">
            <p class="help-block">Prezzo CDS = (Prezzo PS + Spedizione) / (1 - commissione%)</p>
        </div>
        <h4>Fasce spedizione</h4>
        <table class="table table-bordered" id="tiers-table" style="max-width:400px;">
            <thead><tr><th>Peso max</th><th>Costo spedizione</th><th></th></tr></thead>
            <tbody>{$tiersRows}</tbody>
        </table>
        <button type="button" class="btn btn-default btn-sm" onclick="addTierRow()">+ Aggiungi fascia</button>
        <hr>
        <h4>Traduzione AI (Ollama)</h4>
        <div class="form-group">
            <label>Endpoint Ollama</label>
            <input type="text" name="CDS2_AI_ENDPOINT" value="{$aiEndpoint}" class="form-control">
        </div>
        <div class="form-group">
            <label>Modello</label>
            <input type="text" name="CDS2_AI_MODEL" value="{$aiModel}" class="form-control" style="width:220px;">
        </div>
        <hr>
        <h4>Cron automatico (ogni 30 min)</h4>
        <div class="form-group">
            <label>Token cron</label>
            <input type="text" value="{$cronToken}" class="form-control" style="max-width:340px;" readonly>
        </div>
        <div class="form-group">
            <label>URL da aggiungere al cron</label>
            <input type="text" value="{$cronUrl}" class="form-control" readonly onclick="this.select()">
            <p class="help-block">Esempio: <code>*/30 * * * * curl -s "{$cronUrl}" &gt; /dev/null</code></p>
        </div>
        <button type="submit" name="submitCds2Config" value="1" class="btn btn-primary">💾 Salva configurazione</button>
    </form>
</div>
<script>
function addTierRow(){
    var tbody=document.querySelector('#tiers-table tbody');
    var tr=document.createElement('tr');
    tr.innerHTML="<td><input type='number' step='0.1' name='tier_weight[]' value='' class='form-control' style='width:100px;'> kg</td><td><input type='number' step='0.01' name='tier_price[]' value='' class='form-control' style='width:100px;'> €</td><td><button type='button' class='btn btn-xs btn-danger' onclick='this.closest(\"tr\").remove()'>✕</button></td>";
    tbody.appendChild(tr);
}
</script>
HTML;
    }

    // =========================================================================
    // TAB 2 – CATEGORIE
    // =========================================================================

    private function renderTabCategories()
    {
        $action   = htmlspecialchars($this->adminUrl(['cds_tab' => 'categories']), ENT_QUOTES, 'UTF-8');
        $p        = _DB_PREFIX_;
        $count    = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds2_category`");
        $lastSync = Configuration::get('CDS2_CATEGORY_LAST_SYNC') ?: 'mai';

        $catOptions = $this->buildCategoryDatalist();
        $mapRows    = $this->getCategoryMapRows();

        $mapHtml = '';
        foreach ($mapRows as $r) {
            $idCat    = (int) $r['id_category'];
            $psName   = htmlspecialchars((string) $r['ps_name'], ENT_QUOTES, 'UTF-8');
            $cdsRef   = (string) $r['cds_reference'];
            $cdsLabel = (string) $r['cds_label'];
            $display  = $cdsRef ? htmlspecialchars($cdsRef . ' – ' . $cdsLabel, ENT_QUOTES, 'UTF-8') : '';
            $mapHtml .= "<tr>
                <td>{$idCat}<input type='hidden' name='map_id[]' value='{$idCat}'></td>
                <td>{$psName}</td>
                <td><input list='cds2-cat-list' type='text' name='map_cds[]' value='{$display}'
                    class='form-control' placeholder='Cerca categoria CDS...' style='min-width:320px;'>
                </td>
            </tr>";
        }

        $noMap = empty($mapRows)
            ? '<p class="alert alert-info">Prima seleziona almeno un prodotto nel tab <strong>3. Prodotti</strong>.</p>'
            : '';

        return <<<HTML
<div class="panel">
    <h3>🗂️ Categorie Cdiscount</h3>
    <p>Categorie salvate: <strong>{$count}</strong> &nbsp;|&nbsp; Ultimo aggiornamento: <strong>{$lastSync}</strong></p>
    <form method="post" action="{$action}">
        <button type="submit" name="submitCds2CategoryImport" value="1" class="btn btn-primary">
            ⬇️ Scarica / aggiorna categorie da Cdiscount
        </button>
    </form>
</div>
<div class="panel">
    <h3>Mapping Prestashop → Cdiscount</h3>
    {$noMap}
    <form method="post" action="{$action}">
        <datalist id="cds2-cat-list">{$catOptions}</datalist>
        <div class="table-responsive">
        <table class="table table-bordered">
            <thead><tr><th>ID Cat PS</th><th>Categoria Prestashop</th><th>Categoria Cdiscount (livello 3)</th></tr></thead>
            <tbody>{$mapHtml}</tbody>
        </table>
        </div>
        <button type="submit" name="submitCds2CategoryMap" value="1" class="btn btn-primary">💾 Salva mapping</button>
    </form>
    <p class="help-block">Digita il nome o il codice della categoria per filtrare. Usa solo categorie di livello 3.</p>
</div>
HTML;
    }

    // =========================================================================
    // TAB 3 – PRODOTTI
    // =========================================================================

    private function renderTabProducts()
    {
        $action = htmlspecialchars($this->adminUrl(['cds_tab' => 'products']), ENT_QUOTES, 'UTF-8');
        $page   = max(1, (int) Tools::getValue('p', 1));
        $limit  = 100;
        $offset = ($page - 1) * $limit;
        $rows   = $this->getProductRows($limit, $offset);
        $total  = $this->countAllProducts();
        $pages  = max(1, (int) ceil($total / $limit));

        $tableRows = '';
        foreach ($rows as $r) {
            $idP     = (int) $r['id_product'];
            $idA     = (int) $r['id_product_attribute'];
            $key     = $idP . '_' . $idA;
            $sku     = htmlspecialchars(trim((string)($r['sku_attr'] ?: $r['sku_product'])), ENT_QUOTES, 'UTF-8');
            $ean     = htmlspecialchars(trim((string)($r['ean_attr'] ?: $r['ean_product'])), ENT_QUOTES, 'UTF-8');
            $name    = htmlspecialchars((string) $r['name'] . ($r['combo'] ? ' – ' . $r['combo'] : ''), ENT_QUOTES, 'UTF-8');
            $price   = number_format((float) $r['price'], 2, ',', '.');
            $stock   = (int) $r['quantity'];
            $enabled = $r['enabled'] ? 'checked' : '';

            $statusLabel = '';
            if ($r['cds_status'] === 'synced') {
                $statusLabel = '<span class="label label-success">synced</span>';
            } elseif ($r['cds_status'] === 'error') {
                $errTip = htmlspecialchars((string) $r['last_error'], ENT_QUOTES, 'UTF-8');
                $statusLabel = "<span class='label label-danger' title='{$errTip}'>errore</span>";
            } elseif ($r['enabled']) {
                $statusLabel = '<span class="label label-warning">in attesa</span>';
            }

            $tableRows .= "<tr>
                <td><input type='checkbox' name='enabled_products[]' value='{$key}' {$enabled}></td>
                <td>{$name}</td>
                <td>{$sku}</td>
                <td>{$ean}</td>
                <td>€{$price}</td>
                <td>{$stock}</td>
                <td>{$statusLabel}</td>
            </tr>";
        }

        // pagination
        $paginationHtml = '';
        if ($pages > 1) {
            $paginationHtml = '<nav><ul class="pagination">';
            for ($i = 1; $i <= $pages; $i++) {
                $pgUrl    = htmlspecialchars($this->adminUrl(['cds_tab' => 'products', 'p' => $i]), ENT_QUOTES, 'UTF-8');
                $active   = ($i === $page) ? 'class="active"' : '';
                $paginationHtml .= "<li {$active}><a href='{$pgUrl}'>{$i}</a></li>";
            }
            $paginationHtml .= '</ul></nav>';
        }

        $enabledCount = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cds2_product` WHERE enabled=1'
        );

        return <<<HTML
<div class="panel">
    <h3>📦 Prodotti ({$total} totali, {$enabledCount} selezionati per sync)</h3>
    <p>Seleziona i prodotti da sincronizzare con Cdiscount. Mostra {$limit} prodotti per pagina.</p>
    <form method="post" action="{$action}">
        <div style="margin-bottom:10px;">
            <button type="button" class="btn btn-default btn-sm" onclick="toggleAll(true)">✅ Seleziona tutti</button>
            <button type="button" class="btn btn-default btn-sm" onclick="toggleAll(false)">⬜ Deseleziona tutti</button>
        </div>
        <div class="table-responsive">
        <table class="table table-bordered table-striped table-hover" style="font-size:13px;">
            <thead>
                <tr>
                    <th style="width:30px;"><input type="checkbox" id="check-all" onchange="toggleAll(this.checked)"></th>
                    <th>Prodotto</th><th>SKU</th><th>EAN</th><th>Prezzo PS</th><th>Stock</th><th>Stato CDS</th>
                </tr>
            </thead>
            <tbody>{$tableRows}</tbody>
        </table>
        </div>
        {$paginationHtml}
        <button type="submit" name="submitCds2Products" value="1" class="btn btn-primary btn-lg">
            💾 Salva selezione
        </button>
    </form>
</div>
<script>
function toggleAll(v){
    document.querySelectorAll('input[name="enabled_products[]"]').forEach(function(c){c.checked=v;});
    var ca=document.getElementById('check-all');
    if(ca) ca.checked=v;
}
</script>
HTML;
    }

    // =========================================================================
    // TAB 4 – TRADUZIONI
    // =========================================================================

    private function renderTabTranslations()
    {
        $p     = _DB_PREFIX_;
        $total = (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `{$p}cds2_product` WHERE enabled=1"
        );
        $done = (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `{$p}cds2_product` cp
             INNER JOIN `{$p}cds2_translation` t ON t.id_product=cp.id_product AND t.id_product_attribute=cp.id_product_attribute
             WHERE cp.enabled=1 AND t.status='done'"
        );

        $rows = Db::getInstance()->executeS(
            "SELECT cp.id_product, cp.id_product_attribute,
                    COALESCE(pl.name, CONCAT('Prodotto #', cp.id_product)) AS name,
                    COALESCE(t.status,'none') AS tr_status,
                    COALESCE(t.title_fr,'') AS title_fr,
                    COALESCE(t.last_error,'') AS tr_error
             FROM `{$p}cds2_product` cp
             LEFT JOIN `{$p}product_lang` pl ON pl.id_product=cp.id_product AND pl.id_lang=" . (int)$this->getItalianLangId() . "
             LEFT JOIN `{$p}cds2_translation` t ON t.id_product=cp.id_product AND t.id_product_attribute=cp.id_product_attribute
             WHERE cp.enabled=1
             ORDER BY name ASC"
        );
        if (!is_array($rows)) { $rows = []; }

        $tableRows = '';
        foreach ($rows as $r) {
            $idP   = (int) $r['id_product'];
            $idA   = (int) $r['id_product_attribute'];
            $key   = $idP . '_' . $idA;
            $name  = htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8');
            $trFr  = htmlspecialchars((string) $r['title_fr'], ENT_QUOTES, 'UTF-8');
            $trErr = htmlspecialchars((string) $r['tr_error'], ENT_QUOTES, 'UTF-8');
            $status = (string) $r['tr_status'];

            if ($status === 'done') {
                $badge = '<span class="label label-success">✓ tradotto</span>';
            } elseif ($status === 'error') {
                $badge = '<span class="label label-danger" title="' . $trErr . '">✗ errore</span>';
            } else {
                $badge = '<span class="label label-default">in attesa</span>';
            }

            $errRow = $trErr ? "<br><small class='text-danger'>{$trErr}</small>" : '';

            $tableRows .= "<tr id='tr-row-{$key}'>
                <td><input type='checkbox' class='tr-check' value='{$key}' data-idp='{$idP}' data-ida='{$idA}' checked></td>
                <td>{$name}</td>
                <td id='tr-fr-{$key}'>{$trFr}</td>
                <td id='tr-status-{$key}'>{$badge}{$errRow}</td>
            </tr>";
        }

        $ajaxUrl = json_encode($this->adminUrl(['cds_ajax' => 'translate_one']));

        return <<<HTML
<div class="panel">
    <h3>🌐 Traduzioni IT → FR via Ollama</h3>
    <p>Tradotti: <strong>{$done} / {$total}</strong> prodotti selezionati.</p>
    <div style="margin-bottom:12px;">
        <button type="button" class="btn btn-default btn-sm" onclick="toggleAllTr(true)">✅ Seleziona tutti</button>
        <button type="button" class="btn btn-default btn-sm" onclick="toggleAllTr(false)">⬜ Deseleziona tutti</button>
        <button type="button" id="btn-tr-start" class="btn btn-primary" onclick="startTranslation()">
            ▶ Traduci selezionati
        </button>
        <button type="button" id="btn-tr-stop" class="btn btn-danger" onclick="stopTranslation()" style="display:none;">
            ⏹ Ferma
        </button>
    </div>
    <div id="tr-progress" style="display:none;margin-bottom:10px;">
        <div class="progress">
            <div id="tr-bar" class="progress-bar" role="progressbar" style="width:0%">0%</div>
        </div>
        <small id="tr-info"></small>
    </div>
    <div class="table-responsive">
    <table class="table table-bordered table-striped table-hover" style="font-size:13px;">
        <thead>
            <tr>
                <th style="width:30px;"><input type="checkbox" onchange="toggleAllTr(this.checked)"></th>
                <th>Prodotto</th><th>Titolo FR</th><th>Stato</th>
            </tr>
        </thead>
        <tbody>{$tableRows}</tbody>
    </table>
    </div>
</div>
<script>
var _trStop = false;
var _ajaxUrl = {$ajaxUrl};

function toggleAllTr(v){
    document.querySelectorAll('.tr-check').forEach(function(c){c.checked=v;});
}

function startTranslation(){
    var checks = Array.from(document.querySelectorAll('.tr-check:checked'));
    if(!checks.length){ alert('Nessun prodotto selezionato.'); return; }
    _trStop = false;
    document.getElementById('btn-tr-start').disabled=true;
    document.getElementById('btn-tr-stop').style.display='';
    document.getElementById('tr-progress').style.display='';
    translateNext(checks, 0, checks.length);
}

function stopTranslation(){ _trStop=true; }

function translateNext(checks, idx, total){
    if(_trStop || idx >= checks.length){
        document.getElementById('btn-tr-start').disabled=false;
        document.getElementById('btn-tr-stop').style.display='none';
        document.getElementById('tr-info').textContent = _trStop ? 'Fermato.' : 'Completato!';
        return;
    }
    var c   = checks[idx];
    var idP = c.dataset.idp;
    var idA = c.dataset.ida;
    var key = c.value;
    var pct = Math.round((idx/total)*100);
    document.getElementById('tr-bar').style.width=pct+'%';
    document.getElementById('tr-bar').textContent=pct+'%';
    document.getElementById('tr-info').textContent='Traduzione '+(idx+1)+'/'+total+': prodotto #'+idP;
    document.getElementById('tr-status-'+key).innerHTML='<span class="label label-info">in corso...</span>';

    var url = _ajaxUrl + '&id_product='+idP+'&id_product_attribute='+idA;
    fetch(url)
        .then(function(r){ return r.json(); })
        .then(function(data){
            if(data.success){
                document.getElementById('tr-status-'+key).innerHTML='<span class="label label-success">✓ tradotto</span>';
                if(data.message){ document.getElementById('tr-fr-'+key).textContent=data.message.replace(/^Tradotto: /,''); }
            } else {
                var err = data.message || 'Errore sconosciuto';
                document.getElementById('tr-status-'+key).innerHTML='<span class="label label-danger" title="'+err+'">✗ errore</span><br><small class="text-danger">'+err+'</small>';
            }
            translateNext(checks, idx+1, total);
        })
        .catch(function(e){
            document.getElementById('tr-status-'+key).innerHTML='<span class="label label-danger">✗ fetch error</span><br><small class="text-danger">'+e.message+'</small>';
            translateNext(checks, idx+1, total);
        });
}
</script>
HTML;
    }

    // =========================================================================
    // TAB 5 – SINCRONIZZA
    // =========================================================================

    private function renderTabSync()
    {
        $p        = _DB_PREFIX_;
        $total    = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds2_product` WHERE enabled=1");
        $synced   = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds2_product` WHERE enabled=1 AND cds_status='synced'");
        $errors   = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds2_product` WHERE enabled=1 AND cds_status='error'");
        $pending  = $total - $synced - $errors;

        $rows = Db::getInstance()->executeS(
            "SELECT cp.id_product, cp.id_product_attribute,
                    COALESCE(pl.name, CONCAT('Prodotto #', cp.id_product)) AS name,
                    cp.cds_status, cp.last_sync, cp.last_price, cp.last_stock, cp.last_error
             FROM `{$p}cds2_product` cp
             LEFT JOIN `{$p}product_lang` pl ON pl.id_product=cp.id_product AND pl.id_lang=" . (int)$this->getItalianLangId() . "
             WHERE cp.enabled=1
             ORDER BY cp.cds_status ASC, pl.name ASC"
        );
        if (!is_array($rows)) { $rows = []; }

        $tableRows = '';
        foreach ($rows as $r) {
            $idP   = (int) $r['id_product'];
            $idA   = (int) $r['id_product_attribute'];
            $key   = $idP . '_' . $idA;
            $name  = htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8');
            $err   = htmlspecialchars((string) $r['last_error'], ENT_QUOTES, 'UTF-8');
            $sync  = $r['last_sync'] ? htmlspecialchars((string) $r['last_sync'], ENT_QUOTES, 'UTF-8') : '—';
            $price = $r['last_price'] ? '€' . number_format((float)$r['last_price'], 2, ',', '.') : '—';
            $stock = $r['last_stock'] !== null ? (int)$r['last_stock'] : '—';

            switch ($r['cds_status']) {
                case 'synced': $badge = '<span class="label label-success">✓ synced</span>'; break;
                case 'error':  $badge = '<span class="label label-danger" title="'.$err.'">✗ errore</span>'; break;
                default:       $badge = '<span class="label label-warning">in attesa</span>';
            }
            $errRow = $err ? "<br><small class='text-danger' style='max-width:300px;display:block;'>{$err}</small>" : '';

            $tableRows .= "<tr id='sy-row-{$key}'>
                <td><input type='checkbox' class='sy-check' value='{$key}' data-idp='{$idP}' data-ida='{$idA}' checked></td>
                <td>{$name}</td>
                <td id='sy-price-{$key}'>{$price}</td>
                <td id='sy-stock-{$key}'>{$stock}</td>
                <td>{$sync}</td>
                <td id='sy-status-{$key}'>{$badge}{$errRow}</td>
            </tr>";
        }

        $ajaxUrl = json_encode($this->adminUrl(['cds_ajax' => 'sync_one']));
        $cronUrl = htmlspecialchars(
            _PS_BASE_URL_ . __PS_BASE_URI__ . 'modules/cdsync/cron/sync.php?token=' . Configuration::get('CDS2_CRON_TOKEN'),
            ENT_QUOTES, 'UTF-8'
        );

        return <<<HTML
<div class="panel">
    <h3>🔄 Sincronizzazione Cdiscount</h3>
    <p>
        <strong>Totale:</strong> {$total} &nbsp;|&nbsp;
        <span class="text-success"><strong>Synced:</strong> {$synced}</span> &nbsp;|&nbsp;
        <span class="text-danger"><strong>Errori:</strong> {$errors}</span> &nbsp;|&nbsp;
        <strong>In attesa:</strong> {$pending}
    </p>
    <div style="margin-bottom:12px;">
        <button type="button" class="btn btn-default btn-sm" onclick="toggleAllSy(true)">✅ Seleziona tutti</button>
        <button type="button" class="btn btn-default btn-sm" onclick="toggleAllSy(false)">⬜ Deseleziona tutti</button>
        <button type="button" id="btn-sy-start" class="btn btn-primary" onclick="startSync()">
            ▶ Sincronizza selezionati
        </button>
        <button type="button" id="btn-sy-stop" class="btn btn-danger" onclick="stopSync()" style="display:none;">
            ⏹ Ferma
        </button>
    </div>
    <div id="sy-progress" style="display:none;margin-bottom:10px;">
        <div class="progress">
            <div id="sy-bar" class="progress-bar progress-bar-striped active" role="progressbar" style="width:0%">0%</div>
        </div>
        <small id="sy-info"></small>
    </div>
    <div class="table-responsive">
    <table class="table table-bordered table-striped table-hover" style="font-size:13px;">
        <thead>
            <tr>
                <th style="width:30px;"><input type="checkbox" onchange="toggleAllSy(this.checked)"></th>
                <th>Prodotto</th><th>Prezzo CDS</th><th>Stock</th><th>Ultima sync</th><th>Stato</th>
            </tr>
        </thead>
        <tbody>{$tableRows}</tbody>
    </table>
    </div>
    <hr>
    <h4>Cron automatico</h4>
    <p>Aggiungi questo URL al cron di sistema per la sincronizzazione automatica ogni 30 minuti:</p>
    <input type="text" class="form-control" value="{$cronUrl}" readonly onclick="this.select()" style="max-width:700px;">
    <code style="display:block;margin-top:5px;">*/30 * * * * curl -s "{$cronUrl}" &gt; /dev/null</code>
</div>
<script>
var _syStop = false;
var _syAjaxUrl = {$ajaxUrl};

function toggleAllSy(v){
    document.querySelectorAll('.sy-check').forEach(function(c){c.checked=v;});
}

function startSync(){
    var checks = Array.from(document.querySelectorAll('.sy-check:checked'));
    if(!checks.length){ alert('Nessun prodotto selezionato.'); return; }
    _syStop = false;
    document.getElementById('btn-sy-start').disabled=true;
    document.getElementById('btn-sy-stop').style.display='';
    document.getElementById('sy-progress').style.display='';
    syncNext(checks, 0, checks.length);
}

function stopSync(){ _syStop=true; }

function syncNext(checks, idx, total){
    if(_syStop || idx >= checks.length){
        document.getElementById('btn-sy-start').disabled=false;
        document.getElementById('btn-sy-stop').style.display='none';
        document.getElementById('sy-info').textContent = _syStop ? 'Fermato.' : 'Completato!';
        document.getElementById('sy-bar').classList.remove('active');
        return;
    }
    var c   = checks[idx];
    var idP = c.dataset.idp;
    var idA = c.dataset.ida;
    var key = c.value;
    var pct = Math.round((idx/total)*100);
    document.getElementById('sy-bar').style.width=pct+'%';
    document.getElementById('sy-bar').textContent=pct+'%';
    document.getElementById('sy-info').textContent='Sync '+(idx+1)+'/'+total+': prodotto #'+idP;
    document.getElementById('sy-status-'+key).innerHTML='<span class="label label-info">in corso...</span>';

    var url = _syAjaxUrl + '&id_product='+idP+'&id_product_attribute='+idA;
    fetch(url)
        .then(function(r){ return r.json(); })
        .then(function(data){
            if(data.success){
                document.getElementById('sy-status-'+key).innerHTML='<span class="label label-success">✓ synced</span>';
                if(data.price) document.getElementById('sy-price-'+key).textContent='€'+parseFloat(data.price).toFixed(2).replace('.',',');
                if(data.stock !== undefined) document.getElementById('sy-stock-'+key).textContent=data.stock;
            } else {
                var err = data.message || 'Errore sconosciuto';
                document.getElementById('sy-status-'+key).innerHTML='<span class="label label-danger">✗ errore</span><br><small class="text-danger">'+err+'</small>';
            }
            syncNext(checks, idx+1, total);
        })
        .catch(function(e){
            document.getElementById('sy-status-'+key).innerHTML='<span class="label label-danger">✗ fetch error</span><br><small class="text-danger">'+e.message+'</small>';
            syncNext(checks, idx+1, total);
        });
}
</script>
HTML;
    }

    // =========================================================================
    // TAB 6 – ORDINI
    // =========================================================================

    private function renderTabOrders()
    {
        $p        = _DB_PREFIX_;
        $imported = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds2_order`");
        $lastSync = Configuration::get('CDS2_LAST_ORDER_SYNC') ?: 'mai';

        $recentOrders = Db::getInstance()->executeS(
            "SELECT cds_order_id, id_order, status, imported_at FROM `{$p}cds2_order`
             ORDER BY imported_at DESC LIMIT 50"
        );
        if (!is_array($recentOrders)) { $recentOrders = []; }

        $orderRows = '';
        foreach ($recentOrders as $r) {
            $cdsId   = htmlspecialchars((string) $r['cds_order_id'], ENT_QUOTES, 'UTF-8');
            $psId    = $r['id_order'] ? '<a href="' . $this->context->link->getAdminLink('AdminOrders') . '&id_order=' . (int)$r['id_order'] . '">#' . (int)$r['id_order'] . '</a>' : '—';
            $status  = htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8');
            $date    = htmlspecialchars((string) $r['imported_at'], ENT_QUOTES, 'UTF-8');
            $orderRows .= "<tr><td>{$cdsId}</td><td>{$psId}</td><td>{$status}</td><td>{$date}</td></tr>";
        }

        $ajaxUrl = json_encode($this->adminUrl(['cds_ajax' => 'import_orders']));

        return <<<HTML
<div class="panel">
    <h3>📋 Ordini Cdiscount</h3>
    <p>Ordini importati: <strong>{$imported}</strong> &nbsp;|&nbsp; Ultima importazione: <strong>{$lastSync}</strong></p>
    <button type="button" id="btn-import-orders" class="btn btn-primary" onclick="importOrders()">
        ⬇️ Importa nuovi ordini ora
    </button>
    <div id="order-result" style="margin-top:12px;"></div>
</div>
<div class="panel">
    <h3>Ultimi 50 ordini importati</h3>
    <div class="table-responsive">
    <table class="table table-bordered table-striped table-hover" style="font-size:13px;">
        <thead><tr><th>ID Cdiscount</th><th>Ordine PS</th><th>Stato</th><th>Importato il</th></tr></thead>
        <tbody>{$orderRows}</tbody>
    </table>
    </div>
</div>
<script>
var _ordAjaxUrl = {$ajaxUrl};
function importOrders(){
    var btn = document.getElementById('btn-import-orders');
    btn.disabled=true;
    btn.textContent='⏳ Importazione in corso...';
    document.getElementById('order-result').innerHTML='';
    fetch(_ordAjaxUrl)
        .then(function(r){ return r.json(); })
        .then(function(data){
            btn.disabled=false;
            btn.textContent='⬇️ Importa nuovi ordini ora';
            if(data.success || data.imported !== undefined){
                document.getElementById('order-result').innerHTML='<div class="alert alert-success">Importati: <strong>'+(data.imported||0)+'</strong> nuovi ordini.</div>';
            } else {
                document.getElementById('order-result').innerHTML='<div class="alert alert-danger">Errore: '+(data.message||'sconosciuto')+'</div>';
            }
        })
        .catch(function(e){
            btn.disabled=false;
            btn.textContent='⬇️ Importa nuovi ordini ora';
            document.getElementById('order-result').innerHTML='<div class="alert alert-danger">Errore di rete: '+e.message+'</div>';
        });
}
</script>
HTML;
    }

    // =========================================================================
    // API – TOKEN
    // =========================================================================

    private function getToken()
    {
        $clientId     = Configuration::get('CDS2_CLIENT_ID');
        $clientSecret = Configuration::get('CDS2_CLIENT_SECRET');
        if (!$clientId || !$clientSecret) {
            throw new RuntimeException('Client ID o Client Secret non configurati.');
        }

        $ch = curl_init('https://api.octopia.com/api/security/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) { throw new RuntimeException('cURL token: ' . $err); }
        $data = json_decode($body, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException('Token non ottenuto: ' . $body);
        }
        return $data['access_token'];
    }

    private function apiCall($method, $path, $payload = null, $token = null)
    {
        if (!$token) { $token = $this->getToken(); }
        $url = 'https://api.octopia.com' . $path;

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        curl_setopt_array($ch, $opts);
        $body    = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err     = curl_error($ch);
        curl_close($ch);

        if ($err) { throw new RuntimeException('cURL: ' . $err); }
        return ['code' => $httpCode, 'body' => $body, 'data' => json_decode($body, true)];
    }

    // =========================================================================
    // SYNC OFFER
    // =========================================================================

    private function syncOffer($idProduct, $idAttr)
    {
        try {
            $token    = $this->getToken();
            $sellerId = Configuration::get('CDS2_SELLER_ID');
            $idLang   = $this->getItalianLangId();

            $product = new Product($idProduct, false, $idLang);
            if (!Validate::isLoadedObject($product)) {
                return ['success' => false, 'message' => 'Prodotto non trovato', 'price' => null, 'stock' => null];
            }

            $price  = $idAttr
                ? (float) Product::getPriceStatic($idProduct, true, $idAttr)
                : (float) Product::getPriceStatic($idProduct, true);
            $stock  = (int) StockAvailable::getQuantityAvailableByProduct($idProduct, $idAttr ?: 0);
            $sku    = $idAttr ? $this->getAttributeSku($idProduct, $idAttr, $product->reference) : (string) $product->reference;
            $ean    = $idAttr ? $this->getAttributeEan($idProduct, $idAttr, $product->ean13) : (string) $product->ean13;

            if (!$sku) {
                return ['success' => false, 'message' => 'SKU mancante', 'price' => null, 'stock' => null];
            }

            $shipping  = $this->calcShipping((float) $product->weight);
            $cdsPrice  = $this->calcCdsPrice($price, $shipping);

            // Step 1: create product catalog if not exists
            $catResult = $this->createCatalogProduct($product, $idProduct, $idAttr, $sku, $ean, $cdsPrice, $token);
            if (!$catResult['success'] && !$catResult['already_exists']) {
                return ['success' => false, 'message' => 'Catalog: ' . $catResult['message'], 'price' => null, 'stock' => null];
            }

            // Step 2: update offer (price + stock)
            $offerPayload = [
                'offers' => [[
                    'sellerProductId' => $sku,
                    'price'           => $cdsPrice,
                    'stockQuantity'   => $stock,
                    'isActive'        => true,
                    'shippingPrice'   => 0,
                ]],
            ];
            $res = $this->apiCall('PUT', '/v1/sellers/' . urlencode($sellerId) . '/offers', $offerPayload, $token);
            if ($res['code'] >= 400) {
                return ['success' => false, 'message' => 'Offer PUT ' . $res['code'] . ': ' . $res['body'], 'price' => null, 'stock' => null];
            }

            return ['success' => true, 'message' => 'OK', 'price' => $cdsPrice, 'stock' => $stock];

        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'price' => null, 'stock' => null];
        }
    }

    private function createCatalogProduct($product, $idProduct, $idAttr, $sku, $ean, $cdsPrice, $token)
    {
        $p        = _DB_PREFIX_;
        $sellerId = Configuration::get('CDS2_SELLER_ID');
        $idLang   = $this->getItalianLangId();

        // get FR translation
        $trans = Db::getInstance()->getRow(
            "SELECT title_fr, desc_short_fr, desc_fr FROM `{$p}cds2_translation`
             WHERE id_product={$idProduct} AND id_product_attribute={$idAttr} AND status='done'"
        );

        $titleFr = $trans ? (string)$trans['title_fr'] : (string)$product->name;
        $shortFr = $trans ? (string)$trans['desc_short_fr'] : '';
        $descFr  = $trans ? (string)$trans['desc_fr'] : '';

        // category mapping
        $catRef = '';
        $catRow = Db::getInstance()->getRow(
            "SELECT cds_reference FROM `{$p}cds2_category_map` WHERE id_category=" . (int)$product->id_category_default
        );
        if ($catRow) { $catRef = (string) $catRow['cds_reference']; }

        $images = $this->getProductImageUrls($idProduct);

        $payload = [
            'products' => [[
                'sellerProductId' => $sku,
                'ean'             => $ean ?: null,
                'title'           => $titleFr,
                'description'     => $descFr ?: $shortFr ?: $titleFr,
                'shortDescription'=> $shortFr ?: null,
                'brand'           => $product->manufacturer_name ?: 'Generique',
                'categoryCode'    => $catRef ?: null,
                'price'           => $cdsPrice,
                'images'          => array_map(fn($u) => ['uri' => $u], $images),
            ]],
        ];

        $res = $this->apiCall('POST', '/v1/sellers/' . urlencode($sellerId) . '/products', $payload, $token);

        if ($res['code'] === 409) {
            return ['success' => true, 'already_exists' => true, 'message' => 'Già esistente'];
        }
        if ($res['code'] >= 400) {
            return ['success' => false, 'already_exists' => false, 'message' => 'POST ' . $res['code'] . ': ' . $res['body']];
        }
        return ['success' => true, 'already_exists' => false, 'message' => 'Creato'];
    }

    // =========================================================================
    // ORDERS IMPORT
    // =========================================================================

    private function importCdiscountOrders()
    {
        try {
            $token    = $this->getToken();
            $sellerId = Configuration::get('CDS2_SELLER_ID');
            $p        = _DB_PREFIX_;
            $imported = 0;

            $res = $this->apiCall('GET', '/v1/sellers/' . urlencode($sellerId) . '/orders?status=new&pageSize=50', null, $token);
            if ($res['code'] >= 400) {
                return ['success' => false, 'message' => 'GET orders ' . $res['code'] . ': ' . $res['body'], 'imported' => 0];
            }

            $orders = $res['data']['items'] ?? $res['data']['orders'] ?? [];
            if (!is_array($orders)) {
                return ['success' => true, 'message' => 'Nessun ordine', 'imported' => 0];
            }

            foreach ($orders as $cdsOrder) {
                $cdsId = (string)($cdsOrder['id'] ?? $cdsOrder['orderId'] ?? '');
                if (!$cdsId) { continue; }

                $exists = Db::getInstance()->getValue(
                    "SELECT id FROM `{$p}cds2_order` WHERE cds_order_id='" . pSQL($cdsId) . "'"
                );
                if ($exists) { continue; }

                $psOrderId = $this->createPsOrder($cdsOrder);
                Db::getInstance()->execute(
                    "INSERT INTO `{$p}cds2_order` (cds_order_id,id_order,status,raw,imported_at)
                     VALUES ('" . pSQL($cdsId) . "'," . ($psOrderId ? (int)$psOrderId : 'NULL') . ",'imported',
                     '" . pSQL(json_encode($cdsOrder)) . "',NOW())"
                );
                $imported++;
            }

            Configuration::updateValue('CDS2_LAST_ORDER_SYNC', date('Y-m-d H:i:s'));
            return ['success' => true, 'imported' => $imported];

        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'imported' => 0];
        }
    }

    private function createPsOrder(array $cdsOrder)
    {
        try {
            $email     = (string)($cdsOrder['customer']['email'] ?? 'cdiscount_' . uniqid() . '@noreply.com');
            $firstName = (string)($cdsOrder['shippingAddress']['firstName'] ?? $cdsOrder['customer']['firstName'] ?? 'CDS');
            $lastName  = (string)($cdsOrder['shippingAddress']['lastName']  ?? $cdsOrder['customer']['lastName']  ?? 'Customer');

            // find or create customer
            $customer = new Customer();
            $customer->getByEmail($email);
            if (!$customer->id) {
                $customer->email      = $email;
                $customer->firstname  = $firstName;
                $customer->lastname   = $lastName;
                $customer->passwd     = Tools::encrypt(Tools::passwdGen());
                $customer->is_guest   = 1;
                $customer->id_default_group = (int) Configuration::get('PS_GUEST_GROUP');
                $customer->add();
            }
            if (!$customer->id) { return null; }

            // address
            $addr              = new Address();
            $addr->id_customer = (int) $customer->id;
            $addr->alias       = 'cdiscount';
            $addr->firstname   = $firstName;
            $addr->lastname    = $lastName;
            $addr->address1    = (string)($cdsOrder['shippingAddress']['street'] ?? 'N/A');
            $addr->postcode    = (string)($cdsOrder['shippingAddress']['zipCode'] ?? '00000');
            $addr->city        = (string)($cdsOrder['shippingAddress']['city'] ?? 'N/A');
            $addr->id_country  = Country::getByIso(strtoupper((string)($cdsOrder['shippingAddress']['country'] ?? 'FR')));
            if (!$addr->id_country) { $addr->id_country = (int) Configuration::get('PS_COUNTRY_DEFAULT'); }
            $addr->add();
            if (!$addr->id) { return null; }

            // cart
            $cart              = new Cart();
            $cart->id_customer = (int) $customer->id;
            $cart->id_address_delivery = (int) $addr->id;
            $cart->id_address_invoice  = (int) $addr->id;
            $cart->id_currency = (int) Currency::getIdByIsoCode('EUR') ?: (int) Configuration::get('PS_CURRENCY_DEFAULT');
            $cart->id_lang     = (int) Configuration::get('PS_LANG_DEFAULT');
            $cart->add();
            if (!$cart->id) { return null; }

            $items = $cdsOrder['orderLines'] ?? $cdsOrder['items'] ?? [];
            foreach ($items as $item) {
                $sku = (string)($item['sellerProductId'] ?? $item['sku'] ?? '');
                if (!$sku) { continue; }
                $idProduct = (int) Db::getInstance()->getValue(
                    "SELECT id_product FROM `" . _DB_PREFIX_ . "product` WHERE reference='" . pSQL($sku) . "'"
                );
                if ($idProduct) {
                    $cart->updateQty((int)($item['quantity'] ?? 1), $idProduct);
                }
            }

            $idCarrier = (int) Configuration::get('PS_CARRIER_DEFAULT');
            $order     = new Order();
            $order->id_customer    = (int) $customer->id;
            $order->id_cart        = (int) $cart->id;
            $order->id_address_delivery = (int) $addr->id;
            $order->id_address_invoice  = (int) $addr->id;
            $order->id_currency    = $cart->id_currency;
            $order->id_carrier     = $idCarrier;
            $order->id_lang        = $cart->id_lang;
            $order->id_shop        = (int) $this->context->shop->id;
            $order->id_shop_group  = (int) $this->context->shop->id_shop_group;
            $order->payment        = 'Cdiscount';
            $order->module         = 'cdsync';
            $order->total_paid     = (float)($cdsOrder['orderPrice'] ?? $cart->getOrderTotal());
            $order->total_paid_real= $order->total_paid;
            $order->total_products = (float) $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS);
            $order->total_products_wt = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
            $order->total_shipping = (float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
            $order->conversion_rate = 1.0;
            $order->current_state  = (int) Configuration::get('PS_OS_PAYMENT');
            $order->secure_key     = md5(uniqid());
            $order->add();

            return $order->id ?: null;

        } catch (Throwable $e) {
            return null;
        }
    }

    // =========================================================================
    // CATEGORIES – DOWNLOAD
    // =========================================================================

    private function downloadCategories()
    {
        $token = $this->getToken();
        $res   = $this->apiCall('GET', '/v1/referential/categories', null, $token);
        if ($res['code'] >= 400) {
            return ['success' => false, 'message' => 'GET categories ' . $res['code'] . ': ' . $res['body']];
        }

        $categories = $res['data']['categories'] ?? $res['data'] ?? [];
        if (!is_array($categories)) {
            return ['success' => false, 'message' => 'Risposta categorie non valida'];
        }

        $p   = _DB_PREFIX_;
        $cnt = 0;
        foreach ($categories as $cat) {
            $ref   = pSQL((string)($cat['code'] ?? $cat['reference'] ?? ''));
            $label = pSQL((string)($cat['label'] ?? $cat['name'] ?? ''));
            $level = (int)($cat['level'] ?? 0);
            $active= isset($cat['isActive']) ? (int)(bool)$cat['isActive'] : 1;
            $parentRef = pSQL((string)($cat['parentCode'] ?? $cat['parentReference'] ?? ''));
            if (!$ref) { continue; }
            Db::getInstance()->execute(
                "INSERT INTO `{$p}cds2_category` (reference,label,level,is_active,parent_reference,updated_at)
                 VALUES ('{$ref}','{$label}',{$level},{$active}," . ($parentRef ? "'{$parentRef}'" : 'NULL') . ",NOW())
                 ON DUPLICATE KEY UPDATE label='{$label}',level={$level},is_active={$active},parent_reference=" . ($parentRef ? "'{$parentRef}'" : 'NULL') . ",updated_at=NOW()"
            );
            $cnt++;
        }
        return ['success' => true, 'count' => $cnt];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function ollamaTranslate($text)
    {
        $endpoint = rtrim((string) Configuration::get('CDS2_AI_ENDPOINT'), '/');
        $model    = (string) Configuration::get('CDS2_AI_MODEL');
        if (!$endpoint || !$model) {
            return null;
        }

        $prompt = "Traduci in francese il seguente testo di prodotto. Rispondi SOLO con la traduzione, senza spiegazioni:\n\n" . $text;

        $ch = curl_init($endpoint . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'  => $model,
                'prompt' => $prompt,
                'stream' => false,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) { return null; }
        if ($code < 200 || $code >= 300) { return null; }

        $data = json_decode($body, true);
        $result = trim((string)($data['response'] ?? ''));
        return $result !== '' ? $result : null;
    }

    private function calcCdsPrice($price, $shipping)
    {
        $commission = (float) Configuration::get('CDS2_COMMISSION_RATE');
        if ($commission >= 100 || $commission < 0) { $commission = 15; }
        return round(($price + $shipping) / (1 - $commission / 100), 2);
    }

    private function calcShipping($weightKg)
    {
        $tiers = json_decode(Configuration::get('CDS2_SHIPPING_TIERS') ?: '[]', true) ?: [];
        usort($tiers, fn($a, $b) => $a['max_weight'] <=> $b['max_weight']);
        foreach ($tiers as $tier) {
            if ($weightKg <= (float)$tier['max_weight']) {
                return (float)$tier['price'];
            }
        }
        return isset($tiers[count($tiers) - 1]) ? (float)$tiers[count($tiers) - 1]['price'] : 9.99;
    }

    private function getItalianLangId()
    {
        $id = Language::getIdByIso('it');
        return $id ?: (int) Configuration::get('PS_LANG_DEFAULT');
    }

    private function getAttributeSku($idProduct, $idAttr, $default = '')
    {
        $row = Db::getInstance()->getRow(
            'SELECT reference FROM `' . _DB_PREFIX_ . 'product_attribute`
             WHERE id_product=' . (int)$idProduct . ' AND id_product_attribute=' . (int)$idAttr
        );
        $ref = $row ? trim((string)$row['reference']) : '';
        return $ref ?: (string)$default;
    }

    private function getAttributeEan($idProduct, $idAttr, $default = '')
    {
        $row = Db::getInstance()->getRow(
            'SELECT ean13 FROM `' . _DB_PREFIX_ . 'product_attribute`
             WHERE id_product=' . (int)$idProduct . ' AND id_product_attribute=' . (int)$idAttr
        );
        $ean = $row ? trim((string)$row['ean13']) : '';
        return $ean ?: (string)$default;
    }

    private function getProductImageUrls($idProduct)
    {
        $images = Image::getImages((int) $this->context->language->id, $idProduct);
        $urls   = [];
        foreach ($images as $img) {
            $urls[] = $this->context->link->getImageLink('', $img['id_image'], ImageType::getFormattedName('large'));
        }
        return array_slice($urls, 0, 5);
    }

    private function extractReference($input)
    {
        // input can be "REF001 – Nom catégorie" or just "REF001"
        $parts = explode('–', $input);
        return trim($parts[0]);
    }

    // =========================================================================
    // DB HELPERS
    // =========================================================================

    private function getProductRows($limit, $offset)
    {
        $p      = _DB_PREFIX_;
        $idLang = (int) $this->getItalianLangId();
        $idShop = (int) $this->context->shop->id;

        $rows = Db::getInstance()->executeS(
            "SELECT p.id_product, 0 AS id_product_attribute,
                    COALESCE(pl.name, CONCAT('Prodotto #', p.id_product)) AS name,
                    p.reference AS sku_product, '' AS sku_attr,
                    p.ean13 AS ean_product, '' AS ean_attr,
                    '' AS combo,
                    COALESCE(ps.price, p.price) AS price,
                    sa.quantity,
                    COALESCE(cp.enabled, 0) AS enabled,
                    COALESCE(cp.cds_status, 'none') AS cds_status,
                    COALESCE(cp.last_error, '') AS last_error
             FROM `{$p}product` p
             LEFT JOIN `{$p}product_lang` pl ON pl.id_product=p.id_product AND pl.id_lang={$idLang} AND pl.id_shop={$idShop}
             LEFT JOIN `{$p}product_shop` ps ON ps.id_product=p.id_product AND ps.id_shop={$idShop}
             LEFT JOIN `{$p}stock_available` sa ON sa.id_product=p.id_product AND sa.id_product_attribute=0 AND sa.id_shop={$idShop}
             LEFT JOIN `{$p}cds2_product` cp ON cp.id_product=p.id_product AND cp.id_product_attribute=0
             WHERE p.active=1 AND NOT EXISTS (
                 SELECT 1 FROM `{$p}product_attribute` pa WHERE pa.id_product=p.id_product LIMIT 1
             )
             UNION ALL
             SELECT p.id_product, pa.id_product_attribute,
                    COALESCE(pl.name, CONCAT('Prodotto #', p.id_product)) AS name,
                    p.reference AS sku_product, pa.reference AS sku_attr,
                    p.ean13 AS ean_product, pa.ean13 AS ean_attr,
                    GROUP_CONCAT(DISTINCT agl.name ORDER BY agl.name SEPARATOR ' / ') AS combo,
                    (COALESCE(ps.price, p.price) + pa.price) AS price,
                    sa.quantity,
                    COALESCE(cp.enabled, 0) AS enabled,
                    COALESCE(cp.cds_status, 'none') AS cds_status,
                    COALESCE(cp.last_error, '') AS last_error
             FROM `{$p}product` p
             INNER JOIN `{$p}product_attribute` pa ON pa.id_product=p.id_product
             LEFT JOIN `{$p}product_lang` pl ON pl.id_product=p.id_product AND pl.id_lang={$idLang} AND pl.id_shop={$idShop}
             LEFT JOIN `{$p}product_shop` ps ON ps.id_product=p.id_product AND ps.id_shop={$idShop}
             LEFT JOIN `{$p}stock_available` sa ON sa.id_product=p.id_product AND sa.id_product_attribute=pa.id_product_attribute AND sa.id_shop={$idShop}
             LEFT JOIN `{$p}product_attribute_combination` pac ON pac.id_product_attribute=pa.id_product_attribute
             LEFT JOIN `{$p}attribute` a ON a.id_attribute=pac.id_attribute
             LEFT JOIN `{$p}attribute_group_lang` agl ON agl.id_attribute_group=a.id_attribute_group AND agl.id_lang={$idLang}
             LEFT JOIN `{$p}cds2_product` cp ON cp.id_product=p.id_product AND cp.id_product_attribute=pa.id_product_attribute
             WHERE p.active=1
             GROUP BY p.id_product, pa.id_product_attribute
             ORDER BY name ASC
             LIMIT {$limit} OFFSET {$offset}"
        );
        return is_array($rows) ? $rows : [];
    }

    private function countAllProducts()
    {
        $p      = _DB_PREFIX_;
        $idShop = (int) $this->context->shop->id;
        $simple = (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `{$p}product` p
             WHERE p.active=1 AND NOT EXISTS (
                 SELECT 1 FROM `{$p}product_attribute` pa WHERE pa.id_product=p.id_product LIMIT 1
             )"
        );
        $variants = (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM `{$p}product` p
             INNER JOIN `{$p}product_attribute` pa ON pa.id_product=p.id_product
             WHERE p.active=1"
        );
        return $simple + $variants;
    }

    private function getCategoryMapRows()
    {
        $p      = _DB_PREFIX_;
        $idLang = (int) $this->getItalianLangId();
        $idShop = (int) $this->context->shop->id;

        $rows = Db::getInstance()->executeS(
            "SELECT DISTINCT p.id_category_default AS id_category,
                    COALESCE(cl.name, CONCAT('Categoria #', p.id_category_default)) AS ps_name,
                    COALESCE(cm.cds_reference,'') AS cds_reference,
                    COALESCE(cm.cds_label,'') AS cds_label
             FROM `{$p}cds2_product` cp
             INNER JOIN `{$p}product` p ON p.id_product=cp.id_product
             LEFT JOIN `{$p}category_lang` cl ON cl.id_category=p.id_category_default AND cl.id_lang={$idLang} AND cl.id_shop={$idShop}
             LEFT JOIN `{$p}cds2_category_map` cm ON cm.id_category=p.id_category_default
             WHERE cp.enabled=1
             ORDER BY ps_name ASC"
        );
        return is_array($rows) ? $rows : [];
    }

    private function buildCategoryDatalist()
    {
        $p    = _DB_PREFIX_;
        $rows = Db::getInstance()->executeS(
            "SELECT reference, label FROM `{$p}cds2_category` WHERE is_active=1 AND level=3 ORDER BY label ASC"
        );
        if (!is_array($rows)) { return ''; }
        $html = '';
        foreach ($rows as $r) {
            $val = htmlspecialchars($r['reference'] . ' – ' . $r['label'], ENT_QUOTES, 'UTF-8');
            $html .= "<option value='{$val}'></option>";
        }
        return $html;
    }
}
