<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_5($module)
{
    // Versione di consolidamento: le migrazioni dati vengono eseguite dal
    // pannello protetto senza poter bloccare il caricamento del modulo.
    return true;
}
