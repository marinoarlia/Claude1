<?php
/**
 * Cron script – CdiscountSync
 * Esempio URL: https://tuosito.com/modules/cdiscountsync/cron/sync.php?token=XXXXX
 * Aggiungi al cron ogni 30 minuti:
 * * /30 * * * * curl -s "https://tuosito.com/modules/cdiscountsync/cron/sync.php?token=XXXXX" > /dev/null
 */

define('_PS_ROOT_DIR_', realpath(dirname(__FILE__) . '/../../../..'));

if (!file_exists(_PS_ROOT_DIR_ . '/config/config.inc.php')) {
    die('Prestashop not found');
}

require_once _PS_ROOT_DIR_ . '/config/config.inc.php';
require_once _PS_ROOT_DIR_ . '/init.php';

// Token check
$expectedToken = Configuration::get('CDS_CRON_TOKEN');
$providedToken = isset($_GET['token']) ? (string) $_GET['token'] : '';

if (!$expectedToken || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    die('Token non valido.');
}

$module = Module::getInstanceByName('cdiscountsync');
if (!$module || !$module->active) {
    die('Modulo non attivo.');
}

$startTime = microtime(true);
$maxSeconds = 110; // stay safely under 2 min cron
$results = ['synced' => 0, 'errors' => 0, 'orders' => 0];

// ---- 1. Sync offers (price + stock) ----
$p = _DB_PREFIX_;
$pending = Db::getInstance()->executeS(
    "SELECT id_product, id_product_attribute
     FROM `{$p}cds_product`
     WHERE enabled=1 AND cds_status NOT IN ('synced','error')
     ORDER BY id_product ASC LIMIT 200"
);

if (is_array($pending)) {
    foreach ($pending as $row) {
        if ((microtime(true) - $startTime) > ($maxSeconds - 15)) {
            break; // leave time for order import
        }

        $idProduct = (int) $row['id_product'];
        $idAttr    = (int) $row['id_product_attribute'];

        // Use reflection to call private method via a public wrapper
        $result = cronSyncOffer($module, $idProduct, $idAttr);
        $status = $result['success'] ? 'synced' : 'error';
        $errMsg = $result['success'] ? '' : $result['message'];
        $price  = $result['price'] ?? null;
        $stock  = $result['stock'] ?? null;

        Db::getInstance()->execute(
            "UPDATE `{$p}cds_product` SET
             cds_status='" . pSQL($status) . "',
             last_error='" . pSQL($errMsg) . "',
             last_sync=NOW()" .
            ($price !== null ? ', last_price=' . (float)$price : '') .
            ($stock !== null ? ', last_stock=' . (int)$stock : '') .
            " WHERE id_product={$idProduct} AND id_product_attribute={$idAttr}"
        );

        if ($result['success']) { $results['synced']++; } else { $results['errors']++; }
    }
}

// ---- 2. Import orders ----
$orderResult = cronImportOrders($module);
$results['orders'] = $orderResult['imported'] ?? 0;

$elapsed = round(microtime(true) - $startTime, 2);
echo json_encode(array_merge($results, ['elapsed_sec' => $elapsed]));

// ---- Helper functions (call module methods via subclass trick) ----

function cronSyncOffer($module, $idProduct, $idAttr)
{
    // We call the public-facing sync logic by instantiating with a helper
    // Since syncOffer is private, we replicate the call using ReflectionClass
    try {
        $ref    = new ReflectionClass($module);
        $method = $ref->getMethod('syncOffer');
        $method->setAccessible(true);
        return $method->invoke($module, $idProduct, $idAttr);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function cronImportOrders($module)
{
    try {
        $ref    = new ReflectionClass($module);
        $method = $ref->getMethod('importCdiscountOrders');
        $method->setAccessible(true);
        return $method->invoke($module);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage(), 'imported' => 0];
    }
}
