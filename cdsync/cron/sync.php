<?php
/**
 * Cron – CdiscountSync v2 (cdsync)
 * URL: https://tuosito.com/modules/cdsync/cron/sync.php?token=XXXXX
 * Cron ogni 30 min: *\/30 * * * * curl -s "URL" > /dev/null
 */

define('_PS_ROOT_DIR_', realpath(dirname(__FILE__) . '/../../../..'));

if (!file_exists(_PS_ROOT_DIR_ . '/config/config.inc.php')) {
    die('PrestaShop non trovato.');
}

require_once _PS_ROOT_DIR_ . '/config/config.inc.php';
require_once _PS_ROOT_DIR_ . '/init.php';

$expectedToken = Configuration::get('CDS2_CRON_TOKEN');
$providedToken = isset($_GET['token']) ? (string) $_GET['token'] : '';

if (!$expectedToken || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    die('Token non valido.');
}

$module = Module::getInstanceByName('cdsync');
if (!$module || !$module->active) {
    die('Modulo non attivo.');
}

$start      = microtime(true);
$maxSeconds = 110;
$results    = ['synced' => 0, 'errors' => 0, 'orders' => 0];

// ---- 1. Sync offerte (prezzo + stock) ----
$p       = _DB_PREFIX_;
$pending = Db::getInstance()->executeS(
    "SELECT id_product, id_product_attribute
     FROM `{$p}cds2_product`
     WHERE enabled=1 AND cds_status NOT IN ('synced','error')
     ORDER BY id_product ASC LIMIT 200"
);

if (is_array($pending)) {
    foreach ($pending as $row) {
        if ((microtime(true) - $start) > ($maxSeconds - 15)) {
            break;
        }
        $idP    = (int) $row['id_product'];
        $idA    = (int) $row['id_product_attribute'];
        $result = callPrivate($module, 'syncOffer', [$idP, $idA]);
        $status = $result['success'] ? 'synced' : 'error';
        $err    = $result['success'] ? '' : $result['message'];

        Db::getInstance()->execute(
            "UPDATE `{$p}cds2_product` SET
             cds_status='" . pSQL($status) . "',
             last_error='" . pSQL($err) . "',
             last_sync=NOW()"
            . ($result['price'] !== null ? ',last_price=' . (float)$result['price'] : '')
            . ($result['stock'] !== null ? ',last_stock=' . (int)$result['stock'] : '')
            . " WHERE id_product={$idP} AND id_product_attribute={$idA}"
        );

        $result['success'] ? $results['synced']++ : $results['errors']++;
    }
}

// ---- 2. Import ordini ----
$orderResult     = callPrivate($module, 'importCdiscountOrders', []);
$results['orders'] = $orderResult['imported'] ?? 0;

$elapsed = round(microtime(true) - $start, 2);
echo json_encode(array_merge($results, ['elapsed_sec' => $elapsed]));

function callPrivate($module, $methodName, array $args = [])
{
    try {
        $ref    = new ReflectionClass($module);
        $method = $ref->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($module, $args);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage(), 'price' => null, 'stock' => null, 'imported' => 0];
    }
}
