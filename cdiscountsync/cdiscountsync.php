<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class CdiscountSync extends Module
{
    const VERSION = '3.1.0';

    public function __construct()
    {
        $this->name       = 'cdiscountsync';
        $this->tab        = 'market_place';
        $this->version    = self::VERSION;
        $this->author     = 'Masterbrico';
        $this->bootstrap  = true;
        parent::__construct();
        $this->displayName = $this->l('Cdiscount Sync');
        $this->description = $this->l('Sincronizza prezzi, stock e ordini con Cdiscount Octopia API.');
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    // =========================================================================
    // INSTALL / UNINSTALL
    // =========================================================================

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        $this->createTables();
        $this->setDefaults();
        return true;
    }

    public function uninstall()
    {
        $keys = [
            'CDS_CLIENT_ID','CDS_CLIENT_SECRET','CDS_SELLER_ID',
            'CDS_COMMISSION_RATE','CDS_SHIPPING_TIERS',
            'CDS_AI_ENDPOINT','CDS_AI_MODEL',
            'CDS_CRON_TOKEN','CDS_LAST_ORDER_SYNC',
            'CDS_LAST_SYNC_ERROR','CDS_CATEGORY_LAST_SYNC',
        ];
        foreach ($keys as $k) {
            Configuration::deleteByName($k);
        }
        return parent::uninstall();
    }

    private function createTables()
    {
        $db = Db::getInstance();
        $p  = _DB_PREFIX_;
        $e  = _MYSQL_ENGINE_;

        $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cds_product` (
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
            UNIQUE KEY `uq_product_attr` (`id_product`,`id_product_attribute`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;");

        $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cds_translation` (
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

        $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cds_category_map` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_category` INT UNSIGNED NOT NULL,
            `ps_name` VARCHAR(255) DEFAULT NULL,
            `cds_reference` VARCHAR(16) DEFAULT NULL,
            `cds_label` VARCHAR(255) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_cat` (`id_category`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;");

        $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cds_category` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reference` VARCHAR(16) NOT NULL,
            `label` VARCHAR(255) NOT NULL DEFAULT '',
            `level` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 0,
            `parent_reference` VARCHAR(16) DEFAULT NULL,
            `parent_references` VARCHAR(255) DEFAULT NULL,
            `updated_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ref` (`reference`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;");

        $db->execute("CREATE TABLE IF NOT EXISTS `{$p}cds_order` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `cds_order_id` VARCHAR(64) NOT NULL,
            `id_order` INT UNSIGNED DEFAULT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'imported',
            `raw` MEDIUMTEXT DEFAULT NULL,
            `imported_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_cds_order` (`cds_order_id`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;");
    }

    private function setDefaults()
    {
        $defaults = [
            'CDS_COMMISSION_RATE' => '15',
            'CDS_AI_ENDPOINT'     => 'https://ollama.masterbrico.com',
            'CDS_AI_MODEL'        => 'qwen2.5:7b',
            'CDS_CRON_TOKEN'      => substr(md5(uniqid('cds', true)), 0, 24),
            'CDS_SHIPPING_TIERS'  => json_encode($this->defaultShippingTiers()),
        ];
        foreach ($defaults as $k => $v) {
            if (!Configuration::get($k)) {
                Configuration::updateValue($k, $v);
            }
        }
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
        $output = '';

        // Handle POST actions per tab
        $output .= $this->handlePost($this->currentTab);

        return $output
            . $this->renderTabs($this->currentTab)
            . $this->renderTab($this->currentTab);
    }

    private function handlePost($tab)
    {
        $output = '';

        if (Tools::isSubmit('submitCdsConfig')) {
            Configuration::updateValue('CDS_CLIENT_ID',      pSQL(Tools::getValue('CDS_CLIENT_ID')));
            Configuration::updateValue('CDS_CLIENT_SECRET',  pSQL(Tools::getValue('CDS_CLIENT_SECRET')));
            Configuration::updateValue('CDS_SELLER_ID',      pSQL(Tools::getValue('CDS_SELLER_ID')));
            Configuration::updateValue('CDS_COMMISSION_RATE', (float) Tools::getValue('CDS_COMMISSION_RATE'));
            $output .= $this->displayConfirmation('Configurazione API salvata.');
        }

        if (Tools::isSubmit('submitCdsShipping')) {
            $tiers = $this->parseShippingPost();
            Configuration::updateValue('CDS_SHIPPING_TIERS', json_encode($tiers));
            $output .= $this->displayConfirmation('Fasce spedizione salvate.');
        }

        if (Tools::isSubmit('submitCdsAi')) {
            Configuration::updateValue('CDS_AI_ENDPOINT', trim(Tools::getValue('CDS_AI_ENDPOINT')));
            Configuration::updateValue('CDS_AI_MODEL',    trim(Tools::getValue('CDS_AI_MODEL')));
            $output .= $this->displayConfirmation('Configurazione AI salvata.');
        }

        if (Tools::isSubmit('submitCdsCategoryImport')) {
            $result = $this->importCdsCategories();
            if ($result['success']) {
                $output .= $this->displayConfirmation('Categorie scaricate: ' . $result['count'] . '.');
            } else {
                $output .= $this->displayError('Errore download categorie: ' . $result['message']);
            }
        }

        if (Tools::isSubmit('submitCdsCategoryMap')) {
            $this->saveCategoryMapping();
            $output .= $this->displayConfirmation('Mapping categorie salvato.');
        }

        if (Tools::isSubmit('submitCdsSelection')) {
            $this->saveProductSelection();
            $output .= $this->displayConfirmation('Selezione prodotti salvata.');
        }

        return $output;
    }

    // =========================================================================
    // TABS NAVIGATION
    // =========================================================================

    /**
     * Costruisce l'URL admin corretto con token CSRF di PrestaShop.
     * Usa sempre questo metodo al posto di $_SERVER['REQUEST_URI'].
     */
    private function adminUrl(array $extra = [])
    {
        $params = array_merge([
            'controller' => 'AdminModules',
            'configure'  => $this->name,
            'token'      => Tools::getAdminTokenLite('AdminModules'),
        ], $extra);
        return 'index.php?' . http_build_query($params);
    }

    private function renderTabs($active)
    {
        $tabs = [
            'config'      => '1. Configurazione',
            'categories'  => '2. Categorie',
            'products'    => '3. Prodotti',
            'translation' => '4. Traduzioni',
            'sync'        => '5. Sincronizza',
            'orders'      => '6. Ordini',
        ];
        $html = '<ul class="nav nav-tabs" style="margin-bottom:20px;">';
        foreach ($tabs as $key => $label) {
            $cls  = $active === $key ? 'active' : '';
            $href = htmlspecialchars($this->adminUrl(['cds_tab' => $key]), ENT_QUOTES, 'UTF-8');
            $html .= '<li class="' . $cls . '"><a href="' . $href . '">' . $label . '</a></li>';
        }
        $html .= '</ul>';
        return $html;
    }

    private function renderTab($tab)
    {
        switch ($tab) {
            case 'categories':  return $this->renderTabCategories();
            case 'products':    return $this->renderTabProducts();
            case 'translation': return $this->renderTabTranslation();
            case 'sync':        return $this->renderTabSync();
            case 'orders':      return $this->renderTabOrders();
            default:            return $this->renderTabConfig();
        }
    }

    // =========================================================================
    // TAB 1 – CONFIGURAZIONE
    // =========================================================================

    private function renderTabConfig()
    {
        $action = htmlspecialchars($this->adminUrl(['cds_tab' => $this->currentTab]), ENT_QUOTES, 'UTF-8');
        $clientId   = htmlspecialchars((string) Configuration::get('CDS_CLIENT_ID'),      ENT_QUOTES, 'UTF-8');
        $clientSec  = htmlspecialchars((string) Configuration::get('CDS_CLIENT_SECRET'),   ENT_QUOTES, 'UTF-8');
        $sellerId   = htmlspecialchars((string) Configuration::get('CDS_SELLER_ID'),       ENT_QUOTES, 'UTF-8');
        $commission = htmlspecialchars((string)(Configuration::get('CDS_COMMISSION_RATE') ?: '15'), ENT_QUOTES, 'UTF-8');
        $aiEndpoint = htmlspecialchars((string)(Configuration::get('CDS_AI_ENDPOINT') ?: 'https://ollama.masterbrico.com'), ENT_QUOTES, 'UTF-8');
        $aiModel    = htmlspecialchars((string)(Configuration::get('CDS_AI_MODEL') ?: 'qwen2.5:7b'), ENT_QUOTES, 'UTF-8');
        $cronToken  = htmlspecialchars((string) Configuration::get('CDS_CRON_TOKEN'), ENT_QUOTES, 'UTF-8');
        $cronUrl    = htmlspecialchars(Tools::getShopDomainSsl(true) . '/modules/cdiscountsync/cron/sync.php?token=' . $cronToken, ENT_QUOTES, 'UTF-8');

        $tiers    = $this->getShippingTiers();
        $shipRows = '';
        foreach ($tiers as $i => $t) {
            $from  = htmlspecialchars(number_format((float)$t['from'],  2, '.', ''), ENT_QUOTES, 'UTF-8');
            $to    = $t['to'] === null ? '' : htmlspecialchars(number_format((float)$t['to'], 2, '.', ''), ENT_QUOTES, 'UTF-8');
            $price = htmlspecialchars(number_format((float)$t['price'], 2, '.', ''), ENT_QUOTES, 'UTF-8');
            $shipRows .= "<tr>
                <td><input type='text' name='ship_from[]' value='{$from}' class='form-control input-sm' placeholder='Da kg'></td>
                <td><input type='text' name='ship_to[]'   value='{$to}'   class='form-control input-sm' placeholder='vuoto=oltre'></td>
                <td><div class='input-group input-group-sm'><span class='input-group-addon'>€</span>
                    <input type='text' name='ship_price[]' value='{$price}' class='form-control'></div></td>
            </tr>";
        }

        return <<<HTML
<div class="panel">
    <h3>API Cdiscount / Octopia</h3>
    <form method="post" action="{$action}" class="form-horizontal">
        <div class="form-group">
            <label class="control-label col-lg-3">Client ID</label>
            <div class="col-lg-5"><input type="text" name="CDS_CLIENT_ID" value="{$clientId}" class="form-control"></div>
        </div>
        <div class="form-group">
            <label class="control-label col-lg-3">Client Secret</label>
            <div class="col-lg-5"><input type="password" name="CDS_CLIENT_SECRET" value="{$clientSec}" class="form-control"></div>
        </div>
        <div class="form-group">
            <label class="control-label col-lg-3">Seller ID</label>
            <div class="col-lg-5"><input type="text" name="CDS_SELLER_ID" value="{$sellerId}" class="form-control"></div>
        </div>
        <div class="form-group">
            <label class="control-label col-lg-3">Commissione CDS %</label>
            <div class="col-lg-2"><input type="text" name="CDS_COMMISSION_RATE" value="{$commission}" class="form-control"></div>
            <div class="col-lg-4"><p class="help-block">Prezzo CDS = (Prezzo PS + Spedizione) / (1 − commissione%)</p></div>
        </div>
        <div class="panel-footer">
            <button type="submit" name="submitCdsConfig" value="1" class="btn btn-primary">Salva API</button>
        </div>
    </form>
</div>

<div class="panel">
    <h3>Fasce spedizione EUR2 (per kg)</h3>
    <form method="post" action="{$action}">
        <div class="table-responsive">
        <table class="table table-bordered" style="max-width:500px;">
            <thead><tr><th>Da kg</th><th>A kg</th><th>Prezzo €</th></tr></thead>
            <tbody>{$shipRows}</tbody>
        </table>
        </div>
        <p class="help-block">Lascia "A kg" vuoto per l'ultima fascia (oltre). Il modulo userà la fascia più alta come fallback.</p>
        <button type="submit" name="submitCdsShipping" value="1" class="btn btn-primary">Salva fasce</button>
    </form>
</div>

<div class="panel">
    <h3>Traduzione automatica (Ollama)</h3>
    <form method="post" action="{$action}" class="form-horizontal">
        <div class="form-group">
            <label class="control-label col-lg-3">Endpoint Ollama</label>
            <div class="col-lg-5"><input type="text" name="CDS_AI_ENDPOINT" value="{$aiEndpoint}" class="form-control"></div>
        </div>
        <div class="form-group">
            <label class="control-label col-lg-3">Modello</label>
            <div class="col-lg-5"><input type="text" name="CDS_AI_MODEL" value="{$aiModel}" class="form-control" placeholder="qwen2.5:7b"></div>
        </div>
        <div class="panel-footer">
            <button type="submit" name="submitCdsAi" value="1" class="btn btn-primary">Salva AI</button>
        </div>
    </form>
</div>

<div class="panel">
    <h3>Cron job (sincronizzazione automatica)</h3>
    <p>Aggiungi questo URL al tuo cron ogni 30 minuti:</p>
    <code style="display:block;padding:10px;background:#f5f5f5;word-break:break-all;">{$cronUrl}</code>
    <p class="help-block">Il cron aggiorna prezzi e stock su Cdiscount per tutti i prodotti abilitati e scarica i nuovi ordini.</p>
</div>
HTML;
    }

    // =========================================================================
    // TAB 2 – CATEGORIE
    // =========================================================================

    private function renderTabCategories()
    {
        $action = htmlspecialchars($this->adminUrl(['cds_tab' => $this->currentTab]), ENT_QUOTES, 'UTF-8');
        $count     = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cds_category`');
        $lastSync  = Configuration::get('CDS_CATEGORY_LAST_SYNC') ?: 'mai';

        // Build datalist options for CDS categories (level 3 only)
        $catOptions = $this->buildCategoryDatalist();

        // Build mapping rows
        $mapRows   = $this->getCategoryMapRows();
        $mapHtml   = '';
        foreach ($mapRows as $r) {
            $idCat    = (int) $r['id_category'];
            $psName   = htmlspecialchars((string) $r['ps_name'], ENT_QUOTES, 'UTF-8');
            $cdsRef   = htmlspecialchars((string) $r['cds_reference'], ENT_QUOTES, 'UTF-8');
            $cdsLabel = htmlspecialchars((string) $r['cds_label'], ENT_QUOTES, 'UTF-8');
            $display  = $cdsRef ? $this->formatCategoryDisplay($cdsRef) : '';
            $displayH = htmlspecialchars($display, ENT_QUOTES, 'UTF-8');
            $mapHtml .= "<tr>
                <td>{$idCat}<input type='hidden' name='map_id[]' value='{$idCat}'></td>
                <td>{$psName}</td>
                <td><input list='cds-cat-list' type='text' name='map_cds[]' value='{$displayH}'
                    class='form-control' placeholder='Cerca categoria CDS...' style='min-width:350px;'></td>
            </tr>";
        }

        $noMap = empty($mapRows) ? '<p class="alert alert-info">Prima seleziona almeno un prodotto nel tab Prodotti.</p>' : '';

        return <<<HTML
<div class="panel">
    <h3>Categorie Cdiscount</h3>
    <p>Categorie salvate: <strong>{$count}</strong> &nbsp;|&nbsp; Ultimo aggiornamento: <strong>{$lastSync}</strong></p>
    <form method="post" action="{$action}">
        <button type="submit" name="submitCdsCategoryImport" value="1" class="btn btn-primary">
            Scarica / aggiorna categorie da Cdiscount
        </button>
    </form>
</div>

<div class="panel">
    <h3>Mapping categorie Prestashop → Cdiscount</h3>
    {$noMap}
    <form method="post" action="{$action}">
        <datalist id="cds-cat-list">{$catOptions}</datalist>
        <div class="table-responsive">
        <table class="table table-bordered">
            <thead><tr><th>ID Cat. PS</th><th>Categoria Prestashop</th><th>Categoria Cdiscount (livello 3)</th></tr></thead>
            <tbody>{$mapHtml}</tbody>
        </table>
        </div>
        <button type="submit" name="submitCdsCategoryMap" value="1" class="btn btn-primary">Salva mapping</button>
    </form>
    <p class="help-block">Usa solo categorie di livello 3. Inizia a digitare per filtrare l'elenco.</p>
</div>
HTML;
    }

    // =========================================================================
    // TAB 3 – PRODOTTI
    // =========================================================================

    private function renderTabProducts()
    {
        $action = htmlspecialchars($this->adminUrl(['cds_tab' => $this->currentTab]), ENT_QUOTES, 'UTF-8');
        $page   = max(1, (int) Tools::getValue('p', 1));
        $limit  = 100;
        $offset = ($page - 1) * $limit;
        $rows   = $this->getProductRows($limit, $offset);
        $total  = $this->countAllProducts();
        $pages  = max(1, (int) ceil($total / $limit));

        $tableRows = '';
        foreach ($rows as $r) {
            $idP   = (int) $r['id_product'];
            $idA   = (int) $r['id_product_attribute'];
            $key   = $idP . '_' . $idA;
            $sku   = htmlspecialchars(trim((string)($r['sku_attr'] ?: $r['sku_product'])), ENT_QUOTES, 'UTF-8');
            $ean   = htmlspecialchars(trim((string)($r['ean_attr'] ?: $r['ean_product'])), ENT_QUOTES, 'UTF-8');
            $name  = htmlspecialchars((string) $r['name'] . ($r['combo'] ? ' – ' . $r['combo'] : ''), ENT_QUOTES, 'UTF-8');
            $stock = (int) $r['quantity'];
            $weight= max(0, (float)$r['product_weight'] + (float)$r['attribute_weight']);
            $price = (float) Product::getPriceStatic($idP, true, $idA ?: null);
            $ship  = $this->getShippingCost($weight);
            $cdsp  = $this->calcCdsPrice($price, $ship);
            $chk   = (int) $r['enabled'] ? 'checked' : '';
            $status= htmlspecialchars($this->productStatusBadge((string)$r['cds_status'], (int)$r['enabled']), ENT_QUOTES, 'UTF-8');
            $valid  = $sku !== '' && $ean !== '' && $stock > 0 && $price > 0;
            $dis    = $valid ? '' : 'disabled title="SKU, EAN, stock o prezzo mancante"';

            $tableRows .= "<tr>
                <td><input type='checkbox' name='sel[{$key}]' value='1' {$chk} {$dis}></td>
                <td>{$idP}</td>
                <td>" . ($idA ?: '') . "</td>
                <td>{$name}</td>
                <td>{$sku}</td>
                <td>{$ean}</td>
                <td>" . number_format($weight, 2) . " kg</td>
                <td>{$stock}</td>
                <td>" . number_format($price, 2) . " €</td>
                <td>" . number_format($ship, 2) . " €</td>
                <td><strong>" . number_format($cdsp, 2) . " €</strong></td>
                <td>{$status}</td>
            </tr>";
        }

        // Pagination
        $paginHtml = '';
        for ($i = 1; $i <= $pages; $i++) {
            $href   = htmlspecialchars($this->adminUrl(['cds_tab' => 'products', 'p' => $i]), ENT_QUOTES, 'UTF-8');
            $active = $i === $page ? 'class="active"' : '';
            $paginHtml .= "<li {$active}><a href='{$href}'>{$i}</a></li>";
        }

        return <<<HTML
<div class="panel">
    <h3>Prodotti da sincronizzare su Cdiscount</h3>
    <p>
        Prezzo CDS = <strong>(Prezzo Prestashop + Spedizione) / (1 − commissione%)</strong>.<br>
        I prodotti senza SKU, EAN o stock non sono selezionabili.
    </p>
    <form method="post" action="{$action}">
        <div class="table-responsive" style="max-height:70vh;overflow-y:auto;">
        <table class="table table-bordered table-condensed" style="font-size:12px;white-space:nowrap;">
            <thead style="position:sticky;top:0;background:#fff;z-index:2;">
                <tr>
                    <th><input type='checkbox' id='chk-all'></th>
                    <th>ID PS</th><th>Var.</th><th>Nome</th>
                    <th>SKU</th><th>EAN</th><th>Peso</th>
                    <th>Stock</th><th>Prezzo PS</th><th>Sped.</th>
                    <th>Prezzo CDS</th><th>Stato CDS</th>
                </tr>
            </thead>
            <tbody>{$tableRows}</tbody>
        </table>
        </div>
        <nav><ul class="pagination">{$paginHtml}</ul></nav>
        <p class="help-block">Totale prodotti/varianti: <strong>{$total}</strong> — Pagina {$page} di {$pages}</p>
        <button type="submit" name="submitCdsSelection" value="1" class="btn btn-primary btn-lg">
            Salva selezione
        </button>
    </form>
</div>
<script>
document.getElementById('chk-all').addEventListener('change', function(){
    document.querySelectorAll('input[name^="sel["]:not(:disabled)').forEach(function(c){ c.checked = this.checked; }, this);
});
</script>
HTML;
    }

    // =========================================================================
    // TAB 4 – TRADUZIONI
    // =========================================================================

    private function renderTabTranslation()
    {
        $endpoint = htmlspecialchars((string)(Configuration::get('CDS_AI_ENDPOINT') ?: 'https://ollama.masterbrico.com'), ENT_QUOTES, 'UTF-8');
        $model    = htmlspecialchars((string)(Configuration::get('CDS_AI_MODEL') ?: 'qwen2.5:7b'), ENT_QUOTES, 'UTF-8');
        $stats    = $this->translationStats();
        $ajaxUrl  = json_encode($this->adminUrl(['cds_tab' => 'translation']), JSON_UNESCAPED_SLASHES);
        $token    = json_encode(Tools::getAdminTokenLite('AdminModules'));

        return <<<HTML
<div class="panel">
    <h3>Traduzione automatica in francese — Ollama</h3>
    <p>Endpoint: <strong>{$endpoint}</strong> &nbsp;|&nbsp; Modello: <strong>{$model}</strong></p>
    <p>
        Tradotti: <strong id="t-done">{$stats['translated']}</strong> &nbsp;
        In attesa: <strong id="t-pending">{$stats['pending']}</strong> &nbsp;
        Errori: <strong id="t-errors">{$stats['errors']}</strong>
    </p>

    <div class="progress" style="margin:10px 0;">
        <div id="t-bar" class="progress-bar" role="progressbar" style="width:0%;min-width:2em;">0%</div>
    </div>
    <p id="t-msg" style="color:#555;font-size:13px;">Pronto.</p>

    <div style="margin-top:10px;">
        <button id="t-start" class="btn btn-success">Avvia traduzione</button>
        <button id="t-stop"  class="btn btn-default" disabled>Stop</button>
        <button id="t-test"  class="btn btn-default">Test Ollama</button>
    </div>
    <p class="help-block" style="margin-top:10px;">
        Traduce un prodotto alla volta via AJAX per evitare timeout. Puoi riprendere in qualsiasi momento.
        Solo i prodotti selezionati nel tab Prodotti verranno tradotti.
    </p>
</div>

<script>
(function(){
    var ajaxUrl = {$ajaxUrl};
    var token   = {$token};
    var running = false;
    var bar     = document.getElementById('t-bar');
    var msg     = document.getElementById('t-msg');
    var start   = document.getElementById('t-start');
    var stop    = document.getElementById('t-stop');
    var test    = document.getElementById('t-test');

    function setText(id, v){ var el=document.getElementById(id); if(el) el.textContent=v; }
    function setBar(done,total){
        var pct = total>0 ? Math.round(((total-done)/total)*100) : 0;
        bar.style.width=pct+'%'; bar.textContent=pct+'%';
    }

    function ajax(action, extra){
        var fd = new FormData();
        fd.append('cds_ajax', action);
        if(token) fd.append('token', token);
        if(extra) for(var k in extra) fd.append(k,extra[k]);
        return fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
            .then(function(r){ return r.text().then(function(t){ return {s:r.status,t:t}; }); })
            .then(function(res){
                try{ return JSON.parse(res.t); }
                catch(e){ throw new Error('HTTP '+res.s+': '+res.t.substring(0,300)); }
            });
    }

    function loop(){
        if(!running) return;
        ajax('translate_next').then(function(d){
            setText('t-done', d.translated||0);
            setText('t-pending', d.pending||0);
            setText('t-errors', d.errors||0);
            setBar(parseInt(d.pending||0), parseInt(d.total||0));
            msg.textContent = d.message || '...';
            if(d.done || parseInt(d.pending||0)<=0){
                running=false; start.disabled=false; stop.disabled=true;
                msg.textContent='Traduzione completata. Ricarica per vedere gli stati aggiornati.';
                return;
            }
            setTimeout(loop, 600);
        }).catch(function(e){
            running=false; start.disabled=false; stop.disabled=true;
            msg.textContent='Errore: '+e.message+' — premi Avvia per riprendere.';
        });
    }

    start.addEventListener('click', function(){
        running=true; start.disabled=true; stop.disabled=false;
        msg.textContent='Traduzione in corso...';
        loop();
    });
    stop.addEventListener('click', function(){
        running=false; start.disabled=false; stop.disabled=true;
        msg.textContent='Fermato manualmente.';
    });
    test.addEventListener('click', function(){
        msg.textContent='Test in corso...';
        ajax('test_ollama').then(function(d){
            msg.textContent = d.success ? 'Ollama OK: '+d.message : 'Errore: '+d.message;
        }).catch(function(e){ msg.textContent='Errore: '+e.message; });
    });

    // Init stats
    ajax('translation_stats').then(function(d){
        setText('t-done',d.translated||0);
        setText('t-pending',d.pending||0);
        setText('t-errors',d.errors||0);
        setBar(parseInt(d.pending||0), parseInt(d.total||0));
    }).catch(function(){});
})();
</script>
HTML;
    }

    // =========================================================================
    // TAB 5 – SINCRONIZZA
    // =========================================================================

    private function renderTabSync()
    {
        $ajaxUrl = json_encode($this->adminUrl(['cds_tab' => 'sync']), JSON_UNESCAPED_SLASHES);
        $token   = json_encode(Tools::getAdminTokenLite('AdminModules'));
        $p       = _DB_PREFIX_;
        $enabled = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds_product` WHERE enabled=1");
        $synced  = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds_product` WHERE enabled=1 AND cds_status='synced'");
        $errors  = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds_product` WHERE enabled=1 AND cds_status='error'");
        $created = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds_product` WHERE enabled=1 AND catalog_status IN ('created','exists')");

        return <<<HTML
<div class="panel">
    <h3>Sincronizzazione prodotti su Cdiscount</h3>
    <p>
        Prodotti abilitati: <strong>{$enabled}</strong> &nbsp;|&nbsp;
        Catalogo CDS creato: <strong id="s-created">{$created}</strong> &nbsp;|&nbsp;
        Offerte sincronizzate: <strong id="s-synced">{$synced}</strong> &nbsp;|&nbsp;
        Errori: <strong id="s-errors">{$errors}</strong>
    </p>
    <p class="alert alert-info">
        <strong>Come funziona — 2 passi per ogni prodotto:</strong><br>
        <strong>1.</strong> <code>POST /products</code> → crea il prodotto nel catalogo Cdiscount (titolo FR, descrizione FR, immagini, marca, categoria)<br>
        <strong>2.</strong> <code>PUT /offers</code> → imposta prezzo e stock<br>
        Se il prodotto esiste già nel catalogo CDS, il passo 1 viene saltato automaticamente e si aggiorna solo l'offerta.
        La traduzione FR deve essere completata prima di sincronizzare.
    </p>

    <div class="progress" style="margin:10px 0;">
        <div id="s-bar" class="progress-bar" role="progressbar" style="width:0%;min-width:2em;">0%</div>
    </div>
    <p id="s-msg" style="color:#555;font-size:13px;">Pronto.</p>

    <div>
        <button id="s-start" class="btn btn-success btn-lg">Avvia sincronizzazione</button>
        <button id="s-stop"  class="btn btn-default" disabled>Stop</button>
        <button id="s-reset" class="btn btn-warning">Reset errori (ri-sincronizza tutti)</button>
    </div>
    <p class="help-block" style="margin-top:10px;">
        Vengono sincronizzati solo i prodotti abilitati nel tab Prodotti che non sono stati ancora sincronizzati
        o che hanno errori (dopo Reset). Il cron job esegue la stessa operazione automaticamente.
    </p>

    <h4 style="margin-top:25px;">Ultimi errori</h4>
    <div id="s-err-list" style="max-height:200px;overflow-y:auto;font-size:12px;">
        {$this->renderSyncErrors()}
    </div>
</div>

<script>
(function(){
    var ajaxUrl = {$ajaxUrl};
    var token   = {$token};
    var running = false;
    var bar     = document.getElementById('s-bar');
    var msg     = document.getElementById('s-msg');
    var start   = document.getElementById('s-start');
    var stop    = document.getElementById('s-stop');
    var reset   = document.getElementById('s-reset');

    function setText(id,v){ var el=document.getElementById(id); if(el) el.textContent=v; }
    function setBar(done,total){
        var pct = total>0 ? Math.round((done/total)*100) : 0;
        bar.style.width=pct+'%'; bar.textContent=pct+'%';
    }
    function ajax(action){
        var fd=new FormData(); fd.append('cds_ajax',action);
        if(token) fd.append('token',token);
        return fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
            .then(function(r){ return r.text().then(function(t){return{s:r.status,t:t};}); })
            .then(function(res){
                try{ return JSON.parse(res.t); }
                catch(e){ throw new Error('HTTP '+res.s+': '+res.t.substring(0,300)); }
            });
    }

    function loop(){
        if(!running) return;
        ajax('sync_next').then(function(d){
            setText('s-created', d.created||0);
            setText('s-synced',  d.synced||0);
            setText('s-errors',  d.errors||0);
            setBar(parseInt(d.synced||0)+parseInt(d.errors||0), parseInt(d.total||0));
            msg.textContent = d.message || '...';
            if(d.done){
                running=false; start.disabled=false; stop.disabled=true;
                msg.textContent='Sincronizzazione completata.';
                return;
            }
            setTimeout(loop, 500);
        }).catch(function(e){
            running=false; start.disabled=false; stop.disabled=true;
            msg.textContent='Errore: '+e.message;
        });
    }

    start.addEventListener('click', function(){
        running=true; start.disabled=true; stop.disabled=false;
        msg.textContent='Sincronizzazione in corso...';
        loop();
    });
    stop.addEventListener('click', function(){
        running=false; start.disabled=false; stop.disabled=true;
        msg.textContent='Fermato.';
    });
    reset.addEventListener('click', function(){
        if(!confirm('Reimposta tutti i prodotti come "da sincronizzare"?')) return;
        ajax('sync_reset').then(function(d){
            msg.textContent = d.message;
            setText('s-synced',0); setBar(0,1);
        });
    });
})();
</script>
HTML;
    }

    private function renderSyncErrors()
    {
        $p    = _DB_PREFIX_;
        $rows = Db::getInstance()->executeS(
            "SELECT cp.id_product, cp.id_product_attribute, cp.catalog_status,
                    cp.cds_status, cp.last_error, cp.last_sync
             FROM `{$p}cds_product` cp
             WHERE cp.cds_status='error' AND cp.enabled=1
             ORDER BY cp.last_sync DESC LIMIT 30"
        );
        if (!$rows) {
            return '<p>Nessun errore.</p>';
        }
        $html = '<table class="table table-condensed table-bordered"><thead><tr>
            <th>ID PS</th><th>Variante</th><th>Catalogo</th><th>Offerta</th><th>Errore</th><th>Data</th>
        </tr></thead><tbody>';
        foreach ($rows as $r) {
            $err   = htmlspecialchars((string)$r['last_error'],    ENT_QUOTES, 'UTF-8');
            $date  = htmlspecialchars((string)$r['last_sync'],     ENT_QUOTES, 'UTF-8');
            $cat   = htmlspecialchars((string)$r['catalog_status'],ENT_QUOTES, 'UTF-8');
            $offer = htmlspecialchars((string)$r['cds_status'],    ENT_QUOTES, 'UTF-8');
            $html .= "<tr>
                <td>{$r['id_product']}</td>
                <td>{$r['id_product_attribute']}</td>
                <td>{$cat}</td><td>{$offer}</td>
                <td>{$err}</td><td>{$date}</td>
            </tr>";
        }
        return $html . '</tbody></table>';
    }

    // =========================================================================
    // TAB 6 – ORDINI
    // =========================================================================

    private function renderTabOrders()
    {
        $ajaxUrl = json_encode($this->adminUrl(['cds_tab' => 'orders']), JSON_UNESCAPED_SLASHES);
        $token    = json_encode(Tools::getAdminTokenLite('AdminModules'));
        $imported = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cds_order`');
        $lastSync = Configuration::get('CDS_LAST_ORDER_SYNC') ?: 'mai';

        $orderRows = $this->renderOrderList();

        return <<<HTML
<div class="panel">
    <h3>Importazione ordini da Cdiscount</h3>
    <p>
        Ordini importati: <strong>{$imported}</strong> &nbsp;|&nbsp;
        Ultimo import: <strong>{$lastSync}</strong>
    </p>
    <p id="o-msg" style="color:#555;">Pronto.</p>
    <button id="o-import" class="btn btn-success btn-lg">Importa nuovi ordini</button>
    <p class="help-block">Scarica gli ordini Cdiscount in stato "InProgress" e li crea in Prestashop.
        Ogni ordine crea un nuovo cliente PS con i dati di spedizione Cdiscount.
        Il cron esegue questa operazione automaticamente.</p>
</div>

<div class="panel">
    <h3>Ordini importati</h3>
    <div id="o-list">{$orderRows}</div>
</div>

<script>
(function(){
    var ajaxUrl={$ajaxUrl}; var token={$token};
    var msg=document.getElementById('o-msg');
    var btn=document.getElementById('o-import');
    var list=document.getElementById('o-list');

    btn.addEventListener('click', function(){
        btn.disabled=true; msg.textContent='Importazione in corso...';
        var fd=new FormData(); fd.append('cds_ajax','import_orders');
        if(token) fd.append('token',token);
        fetch(ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'})
            .then(function(r){return r.json();})
            .then(function(d){
                msg.textContent=d.message||'Fatto.';
                btn.disabled=false;
                if(d.html) list.innerHTML=d.html;
            })
            .catch(function(e){ msg.textContent='Errore: '+e.message; btn.disabled=false; });
    });
})();
</script>
HTML;
    }

    private function renderOrderList()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'cds_order` ORDER BY imported_at DESC LIMIT 100'
        );
        if (!$rows) {
            return '<p>Nessun ordine importato.</p>';
        }
        $html = '<table class="table table-bordered table-condensed"><thead><tr>
            <th>Ordine CDS</th><th>Ordine PS</th><th>Stato</th><th>Data import</th>
        </tr></thead><tbody>';
        foreach ($rows as $r) {
            $cdsId    = htmlspecialchars((string) $r['cds_order_id'], ENT_QUOTES, 'UTF-8');
            $psOrder  = $r['id_order']
                ? '<a href="' . $this->context->link->getAdminLink('AdminOrders') . '&id_order=' . (int) $r['id_order'] . '">#' . (int) $r['id_order'] . '</a>'
                : '–';
            $status   = htmlspecialchars((string) $r['status'],      ENT_QUOTES, 'UTF-8');
            $date     = htmlspecialchars((string) $r['imported_at'], ENT_QUOTES, 'UTF-8');
            $html    .= "<tr><td>{$cdsId}</td><td>{$psOrder}</td><td>{$status}</td><td>{$date}</td></tr>";
        }
        return $html . '</tbody></table>';
    }

    // =========================================================================
    // AJAX HANDLER
    // =========================================================================

    private function handleAjax($action)
    {
        @ini_set('display_errors', '0');
        @ini_set('memory_limit', '512M');
        @set_time_limit(90);

        register_shutdown_function(function () {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                while (ob_get_level()) { @ob_end_clean(); }
                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                }
                echo json_encode(['success' => false, 'message' => 'PHP fatal: ' . $e['message']]);
            }
        });

        try {
            switch ($action) {
                case 'translate_next':   $data = $this->ajaxTranslateNext();   break;
                case 'translation_stats':$data = $this->translationStats();     break;
                case 'test_ollama':      $data = $this->ajaxTestOllama();       break;
                case 'sync_next':        $data = $this->ajaxSyncNext();         break;
                case 'sync_reset':       $data = $this->ajaxSyncReset();        break;
                case 'import_orders':    $data = $this->ajaxImportOrders();     break;
                default:                 $data = ['success' => false, 'message' => 'Azione non valida.'];
            }
        } catch (Throwable $e) {
            $data = ['success' => false, 'message' => 'Errore: ' . $e->getMessage()];
        }

        while (ob_get_level()) { @ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // =========================================================================
    // AJAX – TRANSLATION
    // =========================================================================

    private function ajaxTestOllama()
    {
        $payload = [
            'model'   => (string)(Configuration::get('CDS_AI_MODEL') ?: 'qwen2.5:7b'),
            'prompt'  => 'Rispondi solo con "OK".',
            'stream'  => false,
            'options' => ['temperature' => 0.1, 'num_predict' => 5],
        ];
        $resp = $this->ollamaRequest($payload, 30);
        if (!$resp['success']) {
            return ['success' => false, 'message' => $resp['message']];
        }
        $data = json_decode($resp['body'], true);
        $text = isset($data['response']) ? trim((string) $data['response']) : 'risposta ricevuta';
        return ['success' => true, 'message' => $text];
    }

    private function translationStats()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT status, COUNT(*) AS c FROM `' . _DB_PREFIX_ . 'cds_translation`
             INNER JOIN `' . _DB_PREFIX_ . 'cds_product` cp
                ON cp.id_product = `' . _DB_PREFIX_ . 'cds_translation`.id_product
               AND cp.id_product_attribute = `' . _DB_PREFIX_ . 'cds_translation`.id_product_attribute
             WHERE cp.enabled=1
             GROUP BY status'
        );
        $stats = ['translated' => 0, 'pending' => 0, 'errors' => 0, 'total' => 0];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $c = (int) $r['c'];
                $stats['total'] += $c;
                if ($r['status'] === 'done')  { $stats['translated'] += $c; }
                elseif ($r['status'] === 'error') { $stats['errors'] += $c; }
                else { $stats['pending'] += $c; }
            }
        }
        // Also count enabled products with no translation record
        $enabled = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cds_product` WHERE enabled=1'
        );
        $hasTranslation = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'cds_translation` t
             INNER JOIN `' . _DB_PREFIX_ . 'cds_product` cp
                ON cp.id_product=t.id_product AND cp.id_product_attribute=t.id_product_attribute
             WHERE cp.enabled=1'
        );
        $stats['pending'] += ($enabled - $hasTranslation);
        $stats['total']    = $enabled;
        $stats['done']     = $stats['pending'] <= 0;
        return $stats;
    }

    private function ajaxTranslateNext()
    {
        // Find one enabled product that hasn't been translated yet
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $p      = _DB_PREFIX_;

        $row = Db::getInstance()->getRow(
            "SELECT cp.id_product, cp.id_product_attribute
             FROM `{$p}cds_product` cp
             LEFT JOIN `{$p}cds_translation` t
                ON t.id_product=cp.id_product AND t.id_product_attribute=cp.id_product_attribute
             WHERE cp.enabled=1
               AND (t.status IS NULL OR t.status NOT IN ('done'))
             ORDER BY cp.id_product ASC LIMIT 1"
        );

        if (!$row) {
            $stats = $this->translationStats();
            $stats['done'] = true;
            $stats['message'] = 'Nessun prodotto da tradurre.';
            return $stats;
        }

        $idProduct = (int) $row['id_product'];
        $idAttr    = (int) $row['id_product_attribute'];

        // Load full product data
        $pRow = $this->getOneProductRow($idProduct, $idAttr, $idLang, $idShop);
        if (!$pRow) {
            Db::getInstance()->execute(
                "INSERT INTO `{$p}cds_translation` (id_product,id_product_attribute,status,updated_at,last_error)
                 VALUES ({$idProduct},{$idAttr},'error',NOW(),'Prodotto non trovato')
                 ON DUPLICATE KEY UPDATE status='error',updated_at=NOW(),last_error='Prodotto non trovato'"
            );
            $stats = $this->translationStats();
            $stats['message'] = 'Prodotto ' . $idProduct . ' non trovato, saltato.';
            return $stats;
        }

        $result = $this->translateProduct($pRow);
        if ($result['success']) {
            Db::getInstance()->execute(
                "INSERT INTO `{$p}cds_translation`
                 (id_product,id_product_attribute,src_hash,title_fr,desc_short_fr,desc_fr,status,updated_at,last_error)
                 VALUES ({$idProduct},{$idAttr},
                 '" . pSQL($result['hash'])       . "',
                 '" . pSQL($result['title_fr'], true)     . "',
                 '" . pSQL($result['short_fr'], true)     . "',
                 '" . pSQL($result['desc_fr'],  true)     . "',
                 'done',NOW(),'')
                 ON DUPLICATE KEY UPDATE
                 src_hash='" . pSQL($result['hash'])       . "',
                 title_fr='" . pSQL($result['title_fr'], true)     . "',
                 desc_short_fr='" . pSQL($result['short_fr'], true) . "',
                 desc_fr='" . pSQL($result['desc_fr'],  true)       . "',
                 status='done',updated_at=NOW(),last_error=''"
            );
        } else {
            Db::getInstance()->execute(
                "INSERT INTO `{$p}cds_translation` (id_product,id_product_attribute,status,updated_at,last_error)
                 VALUES ({$idProduct},{$idAttr},'error',NOW(),'" . pSQL($result['message'], true) . "')
                 ON DUPLICATE KEY UPDATE
                 status='error',updated_at=NOW(),last_error='" . pSQL($result['message'], true) . "'"
            );
        }

        $stats = $this->translationStats();
        $name  = isset($pRow['name']) ? (string) $pRow['name'] : 'Prodotto ' . $idProduct;
        $stats['message'] = $result['success']
            ? 'Tradotto: ' . $name
            : 'Errore su ' . $name . ': ' . $result['message'];
        return $stats;
    }

    // =========================================================================
    // AJAX – SYNC
    // =========================================================================

    private function ajaxSyncNext()
    {
        $p = _DB_PREFIX_;

        $total   = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds_product` WHERE enabled=1");
        $synced  = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds_product` WHERE enabled=1 AND cds_status='synced'");
        $errors  = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds_product` WHERE enabled=1 AND cds_status='error'");
        $created = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds_product` WHERE enabled=1 AND catalog_status IN ('created','exists')");

        // Find next product to sync
        $row = Db::getInstance()->getRow(
            "SELECT id_product, id_product_attribute
             FROM `{$p}cds_product`
             WHERE enabled=1 AND cds_status NOT IN ('synced','error')
             ORDER BY id_product ASC LIMIT 1"
        );

        if (!$row) {
            return [
                'done'    => true,
                'total'   => $total,
                'synced'  => $synced,
                'errors'  => $errors,
                'created' => $created,
                'message' => 'Tutti i prodotti sincronizzati.',
            ];
        }

        $idProduct = (int) $row['id_product'];
        $idAttr    = (int) $row['id_product_attribute'];

        $result = $this->syncOffer($idProduct, $idAttr);
        $status = $result['success'] ? 'synced' : 'error';
        $errMsg = $result['success'] ? '' : $result['message'];
        $price  = isset($result['price']) ? (float) $result['price'] : null;
        $stock  = isset($result['stock']) ? (int) $result['stock'] : null;

        Db::getInstance()->execute(
            "UPDATE `{$p}cds_product` SET
             cds_status='" . pSQL($status) . "',
             last_error='" . pSQL($errMsg) . "',
             last_sync=NOW()" .
            ($price !== null ? ", last_price=" . (float)$price : '') .
            ($stock !== null ? ", last_stock=" . (int)$stock : '') .
            " WHERE id_product={$idProduct} AND id_product_attribute={$idAttr}"
        );

        if ($result['success']) { $synced++; } else { $errors++; }
        $created = (int) Db::getInstance()->getValue("SELECT COUNT(*) FROM `{$p}cds_product` WHERE enabled=1 AND catalog_status IN ('created','exists')");

        $label = $idAttr ? "ID {$idProduct}/var.{$idAttr}" : "ID {$idProduct}";
        return [
            'done'    => false,
            'total'   => $total,
            'synced'  => $synced,
            'errors'  => $errors,
            'created' => $created,
            'message' => $result['success']
                ? "Creato + sincronizzato: {$label}"
                : "Errore {$label}: " . $result['message'],
        ];
    }

    private function ajaxSyncReset()
    {
        Db::getInstance()->execute(
            "UPDATE `" . _DB_PREFIX_ . "cds_product`
             SET cds_status='none', catalog_status='none', last_error=NULL
             WHERE enabled=1"
        );
        return ['success' => true, 'message' => 'Reset completato. Tutti i prodotti verranno ricreati e ri-sincronizzati.'];
    }

    // =========================================================================
    // AJAX – ORDERS
    // =========================================================================

    private function ajaxImportOrders()
    {
        $result = $this->importCdiscountOrders();
        $result['html'] = $this->renderOrderList();
        return $result;
    }

    // =========================================================================
    // OCTOPIA – CATALOG PRODUCT CREATION + OFFER SYNC
    // =========================================================================

    /**
     * Full sync for one product/variant:
     * 1. POST /products  → crea o aggiorna il prodotto nel catalogo CDS
     * 2. PUT  /offers    → aggiorna prezzo + stock
     */
    private function syncOffer($idProduct, $idAttr)
    {
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $pRow   = $this->getOneProductRow($idProduct, $idAttr, $idLang, $idShop);

        if (!$pRow) {
            return ['success' => false, 'message' => 'Prodotto non trovato nel DB.'];
        }

        $sku    = trim((string)($pRow['sku_attr'] ?: $pRow['sku_product']));
        $ean    = trim((string)($pRow['ean_attr'] ?: $pRow['ean_product']));
        $stock  = (int) $pRow['quantity'];
        $weight = max(0, (float)$pRow['product_weight'] + (float)$pRow['attribute_weight']);
        $price  = (float) Product::getPriceStatic($idProduct, true, $idAttr ?: null);
        $ship   = $this->getShippingCost($weight);
        $cdsPrice = $this->calcCdsPrice($price, $ship);

        if (!$sku) {
            return ['success' => false, 'message' => 'SKU mancante.'];
        }
        if (!$ean) {
            return ['success' => false, 'message' => 'EAN13 mancante.'];
        }

        $token = $this->getOctopiaToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Token Octopia non ottenuto. Controlla credenziali API.'];
        }

        // ---- Step 1: crea prodotto nel catalogo ----
        $catalogResult = $this->createCatalogProduct($pRow, $idProduct, $idAttr, $sku, $ean, $token, $idLang, $idShop);
        if (!$catalogResult['success']) {
            // Salva catalog_status=error ma continua con l'offerta solo se il prodotto esiste già
            if ($catalogResult['already_exists'] ?? false) {
                $this->updateCatalogStatus($idProduct, $idAttr, 'exists');
            } else {
                $this->updateCatalogStatus($idProduct, $idAttr, 'error');
                return ['success' => false, 'message' => 'Errore catalogo: ' . $catalogResult['message'], 'price' => $cdsPrice, 'stock' => $stock];
            }
        } else {
            $this->updateCatalogStatus($idProduct, $idAttr, 'created');
        }

        // ---- Step 2: aggiorna offerta (prezzo + stock) ----
        $offerPayload = [[
            'sellerProductId'     => $sku,
            'productEan'          => $ean,
            'price'               => round($cdsPrice, 2),
            'shippingInformation' => $this->buildShippingPayload(),
            'stock'               => $stock,
            'state'               => 'NEW',
            'comment'             => '',
        ]];

        $offerResp = $this->octopiaRequest(
            'PUT',
            'https://api.octopia-io.net/seller/v2/offers',
            $token,
            json_encode($offerPayload, JSON_UNESCAPED_UNICODE)
        );

        if (!$offerResp['success']) {
            return ['success' => false, 'message' => 'Errore offerta: ' . $offerResp['message'], 'price' => $cdsPrice, 'stock' => $stock];
        }

        return ['success' => true, 'message' => '', 'price' => $cdsPrice, 'stock' => $stock];
    }

    /**
     * Crea o aggiorna il prodotto nel catalogo Cdiscount via POST /products.
     * Richiede traduzione FR già disponibile.
     */
    private function createCatalogProduct(array $pRow, $idProduct, $idAttr, $sku, $ean, $token, $idLang, $idShop)
    {
        $p = _DB_PREFIX_;

        // Recupera traduzione FR
        $trans = Db::getInstance()->getRow(
            "SELECT title_fr, desc_short_fr, desc_fr FROM `{$p}cds_translation`
             WHERE id_product=" . (int)$idProduct . " AND id_product_attribute=" . (int)$idAttr . "
             AND status='done'"
        );
        if (!$trans || empty($trans['title_fr'])) {
            return ['success' => false, 'message' => 'Traduzione FR mancante. Esegui prima la traduzione nel tab Traduzioni.'];
        }

        // Recupera categoria CDS mappata
        $catRow = Db::getInstance()->getRow(
            "SELECT cm.cds_reference FROM `{$p}cds_category_map` cm
             INNER JOIN `{$p}product` p ON p.id_category_default = cm.id_category
             WHERE p.id_product=" . (int)$idProduct
        );
        $cdsCategory = $catRow ? trim((string)$catRow['cds_reference']) : '';
        if (!$cdsCategory) {
            return ['success' => false, 'message' => 'Categoria Cdiscount non mappata. Vai nel tab Categorie.'];
        }

        $brand       = trim((string)$pRow['manufacturer_name']);
        $titleFr     = Tools::substr(trim((string)$trans['title_fr']), 0, 500);
        $descShortFr = trim((string)$trans['desc_short_fr']);
        $descFr      = trim((string)$trans['desc_fr']);
        $weight      = max(0, (float)$pRow['product_weight'] + (float)$pRow['attribute_weight']);

        // Immagini
        $images = $this->getProductImageUrls((int)$idProduct);

        $product = [
            'sellerProductId'     => $sku,
            'ean'                 => $ean,
            'title'               => $titleFr,
            'longLabel'           => $descFr ?: $titleFr,
            'description'         => $descShortFr ?: $titleFr,
            'brandReference'      => $brand ?: 'Sans marque',
            'categoryReference'   => $cdsCategory,
            'weight'              => round($weight, 3),
            'mainImageUrl'        => isset($images[0]) ? $images[0] : '',
            'additionalImageUrls' => array_slice($images, 1, 9),
            'navigation'          => [
                ['name' => 'MARQUE', 'value' => $brand ?: 'Sans marque'],
            ],
        ];

        // Aggiungi variante se presente (colore/taglia/ecc.)
        $combo = trim((string)$pRow['combo']);
        if ($combo) {
            $product['sellerProductFamily'] = trim((string)($pRow['sku_product'] ?: $sku));
            $product['modelReference']      = $sku;
            // Prova a estrarre attributi (es. "Colore: Rosso, Taglia: L")
            $attrs = [];
            foreach (explode(', ', $combo) as $pair) {
                $parts = explode(': ', $pair, 2);
                if (count($parts) === 2) {
                    $attrs[] = ['name' => trim($parts[0]), 'value' => trim($parts[1])];
                }
            }
            if ($attrs) {
                $product['navigation'] = array_merge($product['navigation'], $attrs);
            }
        }

        $payload  = [$product];
        $response = $this->octopiaRequest(
            'POST',
            'https://api.octopia-io.net/seller/v2/products',
            $token,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if (!$response['success']) {
            // HTTP 409 = prodotto già esiste → non è un errore bloccante
            if ($response['http_code'] === 409 || strpos($response['message'], '409') !== false) {
                return ['success' => false, 'message' => $response['message'], 'already_exists' => true];
            }
            return ['success' => false, 'message' => $response['message'], 'already_exists' => false];
        }

        return ['success' => true, 'message' => ''];
    }

    private function updateCatalogStatus($idProduct, $idAttr, $status)
    {
        Db::getInstance()->execute(
            "UPDATE `" . _DB_PREFIX_ . "cds_product` SET catalog_status='" . pSQL($status) . "'
             WHERE id_product=" . (int)$idProduct . " AND id_product_attribute=" . (int)$idAttr
        );
    }

    /**
     * Restituisce array di URL assoluti delle immagini del prodotto.
     */
    private function getProductImageUrls($idProduct)
    {
        $images = Image::getImages((int)$this->context->language->id, (int)$idProduct);
        if (!is_array($images)) { return []; }
        $urls = [];
        foreach ($images as $img) {
            $imageObj = new Image((int)$img['id_image']);
            $path     = _PS_IMG_DIR_ . 'p/' . $imageObj->getImgPath() . '.jpg';
            if (!file_exists($path)) { continue; }
            $url = Tools::getShopDomainSsl(true)
                . '/img/p/' . $imageObj->getImgPath() . '.jpg';
            $urls[] = $url;
        }
        return $urls;
    }

    private function buildShippingPayload()
    {
        $tiers = $this->getShippingTiers();
        $info  = [];
        foreach ($tiers as $t) {
            $info[] = [
                'shippingZone'  => 'EUR2',
                'minWeightKg'   => (float) $t['from'],
                'maxWeightKg'   => $t['to'] !== null ? (float) $t['to'] : 9999.0,
                'price'         => round((float) $t['price'], 2),
                'shippingTime'  => 3,
            ];
        }
        return $info;
    }

    // =========================================================================
    // OCTOPIA – CATEGORIES
    // =========================================================================

    private function importCdsCategories()
    {
        @set_time_limit(300);
        $token = $this->getOctopiaToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Token non ottenuto.'];
        }

        $page    = 1;
        $size    = 500;
        $count   = 0;
        $maxPage = 30;

        while ($page <= $maxPage) {
            $url  = "https://api.octopia-io.net/seller/v2/categories?pageIndex={$page}&pageSize={$size}&fields=label,level,isActive,parentReference,parentReferences&sort=label";
            $resp = $this->octopiaRequest('GET', $url, $token, null, ['Accept-Language: fr-FR']);

            if (!$resp['success']) {
                return ['success' => false, 'message' => $resp['message']];
            }

            $data  = json_decode($resp['body'], true);
            $items = isset($data['items']) ? (array) $data['items'] : [];
            if (empty($items)) { break; }

            foreach ($items as $item) {
                $ref    = pSQL((string)($item['categoryReference'] ?? ''));
                $label  = pSQL((string)($item['label'] ?? ''));
                $level  = (int)($item['level'] ?? 0);
                $active = !empty($item['isActive']) ? 1 : 0;
                $pRef   = pSQL((string)($item['parentReference'] ?? ''));
                $pRefs  = pSQL(is_array($item['parentReferences'] ?? null) ? implode(',', $item['parentReferences']) : '');

                if (!$ref) { continue; }

                Db::getInstance()->execute(
                    "INSERT INTO `{_DB_PREFIX_}cds_category`
                     (reference,label,level,is_active,parent_reference,parent_references,updated_at)
                     VALUES ('{$ref}','{$label}',{$level},{$active},'{$pRef}','{$pRefs}',NOW())
                     ON DUPLICATE KEY UPDATE
                     label='{$label}',level={$level},is_active={$active},
                     parent_reference='{$pRef}',parent_references='{$pRefs}',updated_at=NOW()"
                );
                $count++;
            }

            if (count($items) < $size) { break; }
            $page++;
        }

        Configuration::updateValue('CDS_CATEGORY_LAST_SYNC', date('Y-m-d H:i:s'));
        return ['success' => true, 'count' => $count];
    }

    // =========================================================================
    // OCTOPIA – ORDERS
    // =========================================================================

    private function importCdiscountOrders()
    {
        @set_time_limit(300);
        $token = $this->getOctopiaToken();
        if (!$token) {
            return ['success' => false, 'message' => 'Token non ottenuto.'];
        }

        // Fetch orders in InProgress state (not yet shipped)
        $url  = 'https://api.octopia-io.net/seller/v2/orders?states=InProgress&pageIndex=1&pageSize=100';
        $resp = $this->octopiaRequest('GET', $url, $token);
        if (!$resp['success']) {
            return ['success' => false, 'message' => $resp['message']];
        }

        $data   = json_decode($resp['body'], true);
        $orders = isset($data['items']) ? (array) $data['items'] : [];
        if (empty($orders)) {
            Configuration::updateValue('CDS_LAST_ORDER_SYNC', date('Y-m-d H:i:s'));
            return ['success' => true, 'message' => 'Nessun nuovo ordine da importare.', 'imported' => 0];
        }

        $imported = 0;
        foreach ($orders as $order) {
            $cdsOrderId = (string)($order['orderId'] ?? $order['id'] ?? '');
            if (!$cdsOrderId) { continue; }

            // Skip already imported
            $exists = Db::getInstance()->getValue(
                "SELECT id FROM `{_DB_PREFIX_}cds_order` WHERE cds_order_id='" . pSQL($cdsOrderId) . "'"
            );
            if ($exists) { continue; }

            $psOrderId = $this->createPrestashopOrder($order);

            Db::getInstance()->execute(
                "INSERT INTO `{_DB_PREFIX_}cds_order`
                 (cds_order_id,id_order,status,raw,imported_at)
                 VALUES (
                     '" . pSQL($cdsOrderId) . "',
                     " . ($psOrderId ? (int)$psOrderId : 'NULL') . ",
                     '" . ($psOrderId ? 'imported' : 'error') . "',
                     '" . pSQL(json_encode($order, JSON_UNESCAPED_UNICODE)) . "',
                     NOW()
                 )"
            );
            if ($psOrderId) { $imported++; }
        }

        Configuration::updateValue('CDS_LAST_ORDER_SYNC', date('Y-m-d H:i:s'));
        return [
            'success'  => true,
            'message'  => "Importati {$imported} ordini su " . count($orders) . " trovati.",
            'imported' => $imported,
        ];
    }

    private function createPrestashopOrder(array $cdsOrder)
    {
        try {
            $delivery  = $cdsOrder['shippingAddress'] ?? $cdsOrder['deliveryAddress'] ?? [];
            $billing   = $cdsOrder['billingAddress']  ?? $delivery;
            $items     = $cdsOrder['orderLineList']    ?? $cdsOrder['lines'] ?? [];

            $firstName = trim((string)($delivery['firstName'] ?? $delivery['firstname'] ?? 'Client'));
            $lastName  = trim((string)($delivery['lastName']  ?? $delivery['lastname']  ?? 'Cdiscount'));
            $email     = trim((string)($cdsOrder['customer']['email'] ?? $cdsOrder['customerEmail'] ?? 'noreply@cdiscount.com'));

            // Find or create customer
            $customer = Customer::getCustomersByEmail($email);
            if (!empty($customer)) {
                $customerId = (int) $customer[0]['id_customer'];
                $customerObj = new Customer($customerId);
            } else {
                $customerObj = new Customer();
                $customerObj->firstname   = $firstName;
                $customerObj->lastname    = $lastName;
                $customerObj->email       = $email;
                $customerObj->passwd      = Tools::passwdGen(12);
                $customerObj->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
                $customerObj->newsletter  = false;
                $customerObj->active      = true;
                $customerObj->add();
            }

            if (!$customerObj->id) { return null; }

            // Create address
            $country   = Country::getByIso((string)($delivery['countryIso'] ?? $delivery['country'] ?? 'FR'));
            $address   = new Address();
            $address->id_customer = $customerObj->id;
            $address->alias       = 'Cdiscount';
            $address->firstname   = $firstName;
            $address->lastname    = $lastName;
            $address->address1    = (string)($delivery['street']  ?? $delivery['address1'] ?? 'N/D');
            $address->address2    = (string)($delivery['street2'] ?? $delivery['address2'] ?? '');
            $address->postcode    = (string)($delivery['zipCode'] ?? $delivery['postcode'] ?? '00000');
            $address->city        = (string)($delivery['city']    ?? 'N/D');
            $address->id_country  = $country ?: (int) Configuration::get('PS_COUNTRY_DEFAULT');
            $address->phone       = (string)($delivery['phone'] ?? '');
            $address->add();

            if (!$address->id) { return null; }

            // Cart
            $cart = new Cart();
            $cart->id_customer   = $customerObj->id;
            $cart->id_address_delivery  = $address->id;
            $cart->id_address_invoice   = $address->id;
            $cart->id_lang       = (int) Configuration::get('PS_LANG_DEFAULT');
            $cart->id_currency   = (int) Configuration::get('PS_CURRENCY_DEFAULT');
            $cart->id_carrier    = (int) Configuration::get('PS_CARRIER_DEFAULT');
            $cart->recyclable    = false;
            $cart->gift          = false;
            $cart->add();

            if (!$cart->id) { return null; }

            // Add products to cart
            foreach ($items as $line) {
                $sku      = (string)($line['sellerProductId'] ?? $line['sku'] ?? '');
                $qty      = (int)($line['quantity'] ?? 1);
                $linePrice = (float)($line['unitSalePrice'] ?? $line['price'] ?? 0);

                if (!$sku || $qty <= 0) { continue; }

                // Find product by reference (SKU)
                $idProduct = (int) Db::getInstance()->getValue(
                    "SELECT id_product FROM `" . _DB_PREFIX_ . "product` WHERE reference='" . pSQL($sku) . "' LIMIT 1"
                );
                $idAttr = 0;
                if (!$idProduct) {
                    // Try attribute reference
                    $row = Db::getInstance()->getRow(
                        "SELECT id_product, id_product_attribute FROM `" . _DB_PREFIX_ . "product_attribute`
                         WHERE reference='" . pSQL($sku) . "' LIMIT 1"
                    );
                    if ($row) {
                        $idProduct = (int) $row['id_product'];
                        $idAttr    = (int) $row['id_product_attribute'];
                    }
                }

                if ($idProduct) {
                    $cart->updateQty($qty, $idProduct, $idAttr ?: null);
                }
            }

            // Create order from cart
            $totalAmount = (float)($cdsOrder['orderAmount'] ?? $cdsOrder['total'] ?? $cart->getOrderTotal());

            $orderStateId = (int) Configuration::get('PS_OS_PAYMENT');

            $validate = new PaymentModule();
            $validate->validateOrder(
                $cart->id,
                $orderStateId,
                $totalAmount,
                'Cdiscount Marketplace',
                'Ordine Cdiscount: ' . ($cdsOrder['orderId'] ?? ''),
                [],
                null,
                false,
                $cart->secure_key
            );

            $idOrder = (int) Order::getIdByCartId($cart->id);
            return $idOrder ?: null;
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('CdiscountSync order import error: ' . $e->getMessage(), 3);
            return null;
        }
    }

    // =========================================================================
    // OLLAMA – TRANSLATION
    // =========================================================================

    private function translateProduct(array $r)
    {
        $name    = trim((string) $r['name'] . ($r['combo'] ? ' – ' . $r['combo'] : ''));
        $short   = trim(strip_tags((string)($r['desc_short'] ?? '')));
        $desc    = trim(strip_tags((string)($r['description'] ?? '')));
        $desc    = Tools::substr($desc, 0, 1200);
        $brand   = trim((string)($r['manufacturer_name'] ?? ''));
        $cat     = trim((string)($r['category_name'] ?? ''));

        $hash = sha1($name . '|' . $short . '|' . $desc . '|' . $brand);

        $prompt = "Traduis en français pour Cdiscount marketplace. Réponds UNIQUEMENT en JSON valide.\n"
            . "JSON attendu: {\"title_fr\":\"...\",\"desc_short_fr\":\"...\",\"desc_fr\":\"...\"}\n"
            . "Titre IT: {$name}\n"
            . "Marque: {$brand}\n"
            . "Catégorie: {$cat}\n"
            . "Description IT: {$short} {$desc}\n";

        $payload = [
            'model'   => (string)(Configuration::get('CDS_AI_MODEL') ?: 'qwen2.5:7b'),
            'prompt'  => $prompt,
            'stream'  => false,
            'options' => ['temperature' => 0.2, 'num_predict' => 600],
        ];

        $resp = $this->ollamaRequest($payload, 55);
        if (!$resp['success']) {
            return ['success' => false, 'message' => $resp['message']];
        }

        $data = json_decode($resp['body'], true);
        $raw  = isset($data['response']) ? (string) $data['response'] : '';
        $json = $this->extractJson($raw);

        if (!is_array($json) || empty($json['title_fr'])) {
            return ['success' => false, 'message' => 'Risposta AI non JSON valida: ' . Tools::substr($raw, 0, 200)];
        }

        return [
            'success'  => true,
            'hash'     => $hash,
            'title_fr' => Tools::substr(trim((string)$json['title_fr']), 0, 500),
            'short_fr' => trim((string)($json['desc_short_fr'] ?? '')),
            'desc_fr'  => trim((string)($json['desc_fr'] ?? '')),
        ];
    }

    private function ollamaRequest(array $payload, int $timeout = 60)
    {
        $endpoint = rtrim(trim((string)(Configuration::get('CDS_AI_ENDPOINT') ?: 'https://ollama.masterbrico.com')), '/');
        $url      = $endpoint . '/api/generate';
        return $this->httpRequest('POST', $url, ['Content-Type: application/json'], json_encode($payload, JSON_UNESCAPED_UNICODE), $timeout);
    }

    // =========================================================================
    // OCTOPIA – AUTH + HTTP
    // =========================================================================

    private function getOctopiaToken()
    {
        $clientId  = trim((string) Configuration::get('CDS_CLIENT_ID'));
        $clientSec = trim((string) Configuration::get('CDS_CLIENT_SECRET'));
        if (!$clientId || !$clientSec) { return ''; }

        $body = http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $clientSec,
            'grant_type'    => 'client_credentials',
        ]);

        $resp = $this->httpRequest(
            'POST',
            'https://auth.octopia-io.net/auth/realms/maas/protocol/openid-connect/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            $body,
            30
        );

        if (!$resp['success']) { return ''; }
        $data = json_decode($resp['body'], true);
        return (string)($data['access_token'] ?? '');
    }

    private function octopiaRequest($method, $url, $token, $body = null, array $extra = [])
    {
        $sellerId = trim((string) Configuration::get('CDS_SELLER_ID'));
        $headers  = array_merge([
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ], $extra);
        if ($sellerId) {
            $headers[] = 'SellerId: ' . $sellerId;
        }
        return $this->httpRequest($method, $url, $headers, $body, 60);
    }

    private function httpRequest($method, $url, array $headers = [], $body = null, int $timeout = 45)
    {
        $method = strtoupper($method);
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $responseBody = curl_exec($ch);
            $curlErr      = curl_error($ch);
            $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($responseBody === false) {
                return ['success' => false, 'message' => 'cURL error: ' . $curlErr, 'body' => '', 'http_code' => 0];
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                return ['success' => false, 'message' => 'HTTP ' . $httpCode . ': ' . Tools::substr((string)$responseBody, 0, 400), 'body' => (string)$responseBody, 'http_code' => $httpCode];
            }
            return ['success' => true, 'message' => '', 'body' => (string)$responseBody, 'http_code' => $httpCode];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'body' => '', 'http_code' => 0];
        }
    }

    // =========================================================================
    // PRICE HELPERS
    // =========================================================================

    private function calcCdsPrice($price, $shipping)
    {
        $commission = (float)(Configuration::get('CDS_COMMISSION_RATE') ?: 15);
        $divider    = 1 - ($commission / 100);
        if ($divider <= 0) { $divider = 0.85; }
        return round(((float)$price + (float)$shipping) / $divider, 2);
    }

    private function getShippingCost($weight)
    {
        $weight = (float) $weight;
        if ($weight <= 0) { return 0.0; }
        $tiers = $this->getShippingTiers();
        foreach ($tiers as $t) {
            $from = (float) $t['from'];
            $to   = $t['to'] === null ? null : (float) $t['to'];
            if ($to === null && $weight > $from) { return (float) $t['price']; }
            if ($to !== null && $weight > $from && $weight <= $to) { return (float) $t['price']; }
            if ($from == 0.0 && $to !== null && $weight <= $to) { return (float) $t['price']; }
        }
        $last = end($tiers);
        return $last ? (float) $last['price'] : 0.0;
    }

    private function getShippingTiers()
    {
        $raw   = Configuration::get('CDS_SHIPPING_TIERS');
        $tiers = $raw ? json_decode((string)$raw, true) : null;
        if (!is_array($tiers) || empty($tiers)) {
            $tiers = $this->defaultShippingTiers();
            Configuration::updateValue('CDS_SHIPPING_TIERS', json_encode($tiers));
        }
        return $tiers;
    }

    private function defaultShippingTiers()
    {
        return [
            ['from' => 0,  'to' => 3,    'price' => 10.20],
            ['from' => 3,  'to' => 5,    'price' => 11.73],
            ['from' => 5,  'to' => 10,   'price' => 12.75],
            ['from' => 10, 'to' => 15,   'price' => 14.79],
            ['from' => 15, 'to' => 20,   'price' => 16.83],
            ['from' => 20, 'to' => 31,   'price' => 18.87],
            ['from' => 31, 'to' => null, 'price' => 21.42],
        ];
    }

    private function parseShippingPost()
    {
        $froms  = Tools::getValue('ship_from', []);
        $tos    = Tools::getValue('ship_to',   []);
        $prices = Tools::getValue('ship_price',[]);
        $tiers  = [];
        $count  = max(count($froms), count($tos), count($prices));
        for ($i = 0; $i < $count; $i++) {
            $from  = (float) str_replace(',', '.', $froms[$i]  ?? '0');
            $toRaw = trim($tos[$i] ?? '');
            $to    = $toRaw === '' ? null : (float) str_replace(',', '.', $toRaw);
            $price = (float) str_replace(',', '.', $prices[$i] ?? '0');
            if ($to !== null && $to <= $from) { continue; }
            $tiers[] = ['from' => $from, 'to' => $to, 'price' => $price];
        }
        if (empty($tiers)) { return $this->defaultShippingTiers(); }
        usort($tiers, function ($a, $b) {
            if ($a['to'] === null) { return 1; }
            if ($b['to'] === null) { return -1; }
            return $a['from'] <=> $b['from'];
        });
        return $tiers;
    }

    // =========================================================================
    // DB HELPERS – PRODUCTS
    // =========================================================================

    private function getProductRows(int $limit = 100, int $offset = 0)
    {
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $p      = _DB_PREFIX_;

        $comboSub = "SELECT pac.id_product_attribute,
            GROUP_CONCAT(CONCAT(agl.name,': ',al.name) ORDER BY agl.name SEPARATOR ', ') AS combo
            FROM `{$p}product_attribute_combination` pac
            INNER JOIN `{$p}attribute` a ON a.id_attribute=pac.id_attribute
            INNER JOIN `{$p}attribute_lang` al ON al.id_attribute=a.id_attribute AND al.id_lang={$idLang}
            INNER JOIN `{$p}attribute_group_lang` agl ON agl.id_attribute_group=a.id_attribute_group AND agl.id_lang={$idLang}
            GROUP BY pac.id_product_attribute";

        $sql = "SELECT
                p.id_product,
                COALESCE(pa.id_product_attribute, 0) AS id_product_attribute,
                COALESCE(pl.name,'Prodotto #'.p.id_product) AS name,
                COALESCE(comb.combo,'') AS combo,
                COALESCE(cl.name,'') AS category_name,
                p.reference AS sku_product, pa.reference AS sku_attr,
                p.ean13     AS ean_product, pa.ean13     AS ean_attr,
                p.weight    AS product_weight, COALESCE(pa.weight,0) AS attribute_weight,
                COALESCE(sa.quantity,0) AS quantity,
                p.active,
                COALESCE(m.name,'') AS manufacturer_name,
                COALESCE(cp.enabled,0) AS enabled,
                COALESCE(cp.cds_status,'none') AS cds_status,
                COALESCE(cp.last_sync,'') AS last_sync,
                COALESCE(cp.last_error,'') AS last_error
            FROM `{$p}product` p
            LEFT JOIN `{$p}product_lang` pl
                ON pl.id_product=p.id_product AND pl.id_lang={$idLang} AND pl.id_shop={$idShop}
            LEFT JOIN `{$p}product_attribute` pa ON pa.id_product=p.id_product
            LEFT JOIN ({$comboSub}) comb ON comb.id_product_attribute=pa.id_product_attribute
            LEFT JOIN `{$p}category_lang` cl
                ON cl.id_category=p.id_category_default AND cl.id_lang={$idLang} AND cl.id_shop={$idShop}
            LEFT JOIN `{$p}stock_available` sa
                ON sa.id_product=p.id_product
                AND sa.id_product_attribute=COALESCE(pa.id_product_attribute,0)
                AND sa.id_shop={$idShop}
            LEFT JOIN `{$p}cds_product` cp
                ON cp.id_product=p.id_product
                AND cp.id_product_attribute=COALESCE(pa.id_product_attribute,0)
            LEFT JOIN `{$p}manufacturer` m ON m.id_manufacturer=p.id_manufacturer
            WHERE p.active=1
            ORDER BY p.id_product DESC, pa.id_product_attribute ASC
            LIMIT {$limit} OFFSET {$offset}";

        $rows = Db::getInstance()->executeS($sql);
        return is_array($rows) ? $rows : [];
    }

    private function countAllProducts()
    {
        $p      = _DB_PREFIX_;
        $idShop = (int) $this->context->shop->id;
        return (int) Db::getInstance()->getValue(
            "SELECT COUNT(*)
             FROM `{$p}product` p
             LEFT JOIN `{$p}product_attribute` pa ON pa.id_product=p.id_product
             WHERE p.active=1"
        );
    }

    private function getOneProductRow($idProduct, $idAttr, $idLang, $idShop)
    {
        $p    = _DB_PREFIX_;
        $idP  = (int) $idProduct;
        $idA  = (int) $idAttr;

        $comboSub = "SELECT pac.id_product_attribute,
            GROUP_CONCAT(CONCAT(agl.name,': ',al.name) ORDER BY agl.name SEPARATOR ', ') AS combo
            FROM `{$p}product_attribute_combination` pac
            INNER JOIN `{$p}attribute` a ON a.id_attribute=pac.id_attribute
            INNER JOIN `{$p}attribute_lang` al ON al.id_attribute=a.id_attribute AND al.id_lang={$idLang}
            INNER JOIN `{$p}attribute_group_lang` agl ON agl.id_attribute_group=a.id_attribute_group AND agl.id_lang={$idLang}
            GROUP BY pac.id_product_attribute";

        $attrJoin  = $idA ? "INNER JOIN `{$p}product_attribute` pa ON pa.id_product=p.id_product AND pa.id_product_attribute={$idA}"
                           : "LEFT JOIN  `{$p}product_attribute` pa ON pa.id_product=p.id_product AND pa.id_product_attribute=0";

        $row = Db::getInstance()->getRow(
            "SELECT p.id_product, COALESCE(pa.id_product_attribute,0) AS id_product_attribute,
                COALESCE(pl.name,'') AS name,
                COALESCE(pl.description,'') AS description,
                COALESCE(pl.description_short,'') AS desc_short,
                COALESCE(comb.combo,'') AS combo,
                COALESCE(cl.name,'') AS category_name,
                p.reference AS sku_product, pa.reference AS sku_attr,
                p.ean13 AS ean_product, pa.ean13 AS ean_attr,
                p.weight AS product_weight, COALESCE(pa.weight,0) AS attribute_weight,
                COALESCE(sa.quantity,0) AS quantity,
                COALESCE(m.name,'') AS manufacturer_name
            FROM `{$p}product` p
            LEFT JOIN `{$p}product_lang` pl ON pl.id_product=p.id_product AND pl.id_lang={$idLang} AND pl.id_shop={$idShop}
            {$attrJoin}
            LEFT JOIN ({$comboSub}) comb ON comb.id_product_attribute=COALESCE(pa.id_product_attribute,0)
            LEFT JOIN `{$p}category_lang` cl ON cl.id_category=p.id_category_default AND cl.id_lang={$idLang} AND cl.id_shop={$idShop}
            LEFT JOIN `{$p}stock_available` sa ON sa.id_product=p.id_product AND sa.id_product_attribute=COALESCE(pa.id_product_attribute,0) AND sa.id_shop={$idShop}
            LEFT JOIN `{$p}manufacturer` m ON m.id_manufacturer=p.id_manufacturer
            WHERE p.id_product={$idP} LIMIT 1"
        );
        return is_array($row) ? $row : null;
    }

    private function saveProductSelection()
    {
        $p   = _DB_PREFIX_;
        $sel = Tools::getValue('sel', []);

        // Disable all currently enabled
        Db::getInstance()->execute("UPDATE `{$p}cds_product` SET enabled=0");

        if (!is_array($sel)) { return; }

        foreach ($sel as $key => $val) {
            $parts = explode('_', (string) $key);
            if (count($parts) !== 2) { continue; }
            $idP = (int) $parts[0];
            $idA = (int) $parts[1];
            if ($idP <= 0) { continue; }
            Db::getInstance()->execute(
                "INSERT INTO `{$p}cds_product` (id_product,id_product_attribute,enabled,cds_status)
                 VALUES ({$idP},{$idA},1,'none')
                 ON DUPLICATE KEY UPDATE enabled=1, cds_status=IF(cds_status='synced','synced','none')"
            );
        }
    }

    // =========================================================================
    // DB HELPERS – CATEGORIES
    // =========================================================================

    private function getCategoryMapRows()
    {
        $p      = _DB_PREFIX_;
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;

        $rows = Db::getInstance()->executeS(
            "SELECT DISTINCT p.id_category_default AS id_category,
                COALESCE(cl.name,'Categoria #'.p.id_category_default) AS ps_name,
                COALESCE(cm.cds_reference,'') AS cds_reference,
                COALESCE(cm.cds_label,'') AS cds_label
             FROM `{$p}cds_product` cp
             INNER JOIN `{$p}product` p ON p.id_product=cp.id_product
             LEFT JOIN `{$p}category_lang` cl ON cl.id_category=p.id_category_default AND cl.id_lang={$idLang} AND cl.id_shop={$idShop}
             LEFT JOIN `{$p}cds_category_map` cm ON cm.id_category=p.id_category_default
             WHERE cp.enabled=1
             ORDER BY ps_name ASC"
        );
        return is_array($rows) ? $rows : [];
    }

    private function saveCategoryMapping()
    {
        $p    = _DB_PREFIX_;
        $ids  = Tools::getValue('map_id',  []);
        $cdss = Tools::getValue('map_cds', []);

        if (!is_array($ids)) { return; }

        foreach ($ids as $i => $rawId) {
            $idCategory = (int) $rawId;
            if ($idCategory <= 0) { continue; }
            $input     = trim((string)($cdss[$i] ?? ''));
            $reference = $this->extractReference($input);
            $label     = '';
            if ($reference) {
                $row = Db::getInstance()->getRow(
                    "SELECT label FROM `{$p}cds_category` WHERE reference='" . pSQL($reference) . "'"
                );
                if ($row) { $label = (string) $row['label']; }
            }
            Db::getInstance()->execute(
                "INSERT INTO `{$p}cds_category_map` (id_category,cds_reference,cds_label)
                 VALUES ({$idCategory},'" . pSQL($reference) . "','" . pSQL($label) . "')
                 ON DUPLICATE KEY UPDATE cds_reference='" . pSQL($reference) . "', cds_label='" . pSQL($label) . "'"
            );
        }
    }

    private function buildCategoryDatalist()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT reference, label, level, parent_references FROM `' . _DB_PREFIX_ . 'cds_category`
             WHERE is_active=1 AND level=3 ORDER BY label ASC'
        );
        if (!is_array($rows)) { return ''; }
        $map = $this->buildCategoryMap();
        $html = '';
        foreach ($rows as $r) {
            $path  = $this->categoryPath((string)$r['reference'], $map);
            $value = htmlspecialchars($r['reference'] . ' – ' . $path, ENT_QUOTES, 'UTF-8');
            $html .= "<option value='{$value}'></option>";
        }
        return $html;
    }

    private function buildCategoryMap()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT reference, label, parent_reference, parent_references FROM `' . _DB_PREFIX_ . 'cds_category`'
        );
        $map = [];
        if (!is_array($rows)) { return $map; }
        foreach ($rows as $r) {
            $map[strtoupper((string)$r['reference'])] = $r;
        }
        return $map;
    }

    private function categoryPath($reference, array $map = [])
    {
        $reference = strtoupper(trim($reference));
        if (!$reference || !isset($map[$reference])) { return $reference; }

        $refs = [];
        $pRefs = trim((string)$map[$reference]['parent_references']);
        if ($pRefs) {
            foreach (preg_split('/[,\s]+/', $pRefs) as $ref) {
                $ref = strtoupper(trim($ref));
                if ($ref && isset($map[$ref])) { $refs[] = $ref; }
            }
        }
        $refs[] = $reference;
        $labels = [];
        foreach ($refs as $ref) {
            $labels[] = isset($map[$ref]) ? (string)$map[$ref]['label'] : $ref;
        }
        return implode(' > ', $labels);
    }

    private function formatCategoryDisplay($reference)
    {
        if (!$reference) { return ''; }
        $map  = $this->buildCategoryMap();
        $path = $this->categoryPath($reference, $map);
        return $reference . ' – ' . $path;
    }

    private function extractReference($input)
    {
        $input = strtoupper(trim((string)$input));
        if (preg_match('/\b([A-Z0-9]{6})\b/', $input, $m)) { return $m[1]; }
        if (preg_match('/\b([A-Z0-9]{4})\b/', $input, $m)) { return $m[1]; }
        return '';
    }

    // =========================================================================
    // MISC HELPERS
    // =========================================================================

    private function productStatusBadge($status, $enabled)
    {
        if (!$enabled) { return '<span class="badge badge-secondary">Non selezionato</span>'; }
        $map = [
            'synced' => ['success', 'Sincronizzato'],
            'error'  => ['danger',  'Errore'],
            'none'   => ['info',    'Da sincronizzare'],
        ];
        [$cls, $label] = $map[$status] ?? ['warning', ucfirst($status)];
        return '<span class="badge badge-' . $cls . '">' . $label . '</span>';
    }

    private function extractJson($raw)
    {
        $raw  = trim((string)$raw);
        $data = json_decode($raw, true);
        if (is_array($data)) { return $data; }
        $s = strpos($raw, '{');
        $e = strrpos($raw, '}');
        if ($s !== false && $e !== false && $e > $s) {
            $data = json_decode(substr($raw, $s, $e - $s + 1), true);
            if (is_array($data)) { return $data; }
        }
        return null;
    }
}
