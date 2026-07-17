<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.0.15: solo miglioramenti applicativi.
 * - Il guardiano dei fatal e il limite di memoria vengono attivati all'inizio
 *   della pagina di configurazione, non solo al click di "Scarica ordini".
 * - Le tabelle "Ultimi ordini" e "Log recenti" non caricano più i campi
 *   LONGTEXT raw_order/payload: causa probabile del fatal in memoria al caricamento.
 * - Ogni pannello della configurazione è isolato: un errore non blocca l'intera pagina.
 * Nessuna modifica ai dati o alla configurazione.
 */
function upgrade_module_1_0_15($module)
{
    return true;
}
