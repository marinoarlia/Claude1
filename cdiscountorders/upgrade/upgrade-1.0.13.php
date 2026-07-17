<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.0.13: il modulo diventa autonomo per le credenziali Octopia.
 * Le credenziali si inseriscono e si salvano nel pannello di configurazione,
 * senza più dipendere dal modulo cdiscountsync. In fase di aggiornamento
 * riprendiamo i valori eventualmente già presenti (chiavi CDS_*), così chi
 * aggiorna non deve reinserirli.
 */
function upgrade_module_1_0_13($module)
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
    if (trim((string) Configuration::get('CDO_SALES_CHANNEL_ID')) === '') {
        Configuration::updateValue('CDO_SALES_CHANNEL_ID', 'CDISFR');
    }

    return true;
}
