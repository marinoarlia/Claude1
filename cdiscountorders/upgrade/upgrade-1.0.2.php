<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_2($module)
{
    try {
        $module->upgradeTo102();
    } catch (Throwable $ignored) {
        // L'aggiornamento dei file non deve essere annullato da una migrazione dati.
    }
    return true;
}
