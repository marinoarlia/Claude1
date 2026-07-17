<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.0.14: solo miglioramenti applicativi.
 * - La sincronizzazione alza il limite di memoria a 512M per reggere gli hook
 *   dei moduli terzi durante la creazione ordine.
 * - Nel back office l'errore fatale mostra il messaggio reale invece di una
 *   pagina "Errore fatale" vuota.
 * Nessuna modifica ai dati o alla configurazione.
 */
function upgrade_module_1_0_14($module)
{
    return true;
}
