<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_12($module)
{
    // Aggiornamento solo applicativo: configurazione e associazioni ordini restano intatte.
    return true;
}
