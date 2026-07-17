<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_3($module)
{
    try {
        $module->upgradeTo103();
    } catch (Throwable $ignored) {
        // La correzione viene ritentata in modo protetto nella configurazione.
    }
    return true;
}
