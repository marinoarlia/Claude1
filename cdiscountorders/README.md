# Ordini Cdiscount / Octopia per PrestaShop 8.1.2

Modulo separato dal catalogo `cdiscountsync`.

Versione 1.0.13: il modulo è ora **autonomo**. Le credenziali Octopia (Client ID, Client Secret, Seller ID, Sales Channel ID) si inseriscono e si salvano direttamente nel pannello **"Credenziali Octopia"** della configurazione del modulo, senza dipendere dal modulo `cdiscountsync`. Aggiunto il pulsante **"Verifica connessione"** che richiede un token a Octopia per validare subito le credenziali. Chi aggiorna da una versione precedente si vede riprese automaticamente le credenziali già presenti. Aggiunto inoltre un fallback sull'indirizzo di consegna letto a livello di ordine (oltre che per riga), per evitare il blocco "Indirizzo di consegna non ancora disponibile".

Versione 1.0.12: l'importazione di un ordine problematico non blocca più l'intera sincronizzazione. Aggiunti isolamento per singolo ordine, timeout compatibili con il back office, checkpoint dell'importazione, cattura degli errori PHP fatali, log non bloccanti e gestione compatibile PrestaShop 8.1 delle righe generiche per SKU mancanti. `Ultimo controllo` viene aggiornato a ogni esecuzione conclusa e viene mostrato separatamente l'ultimo controllo senza errori.

Versione 1.0.11: corretto il falso errore `Modulo Cdiscount Orders non disponibile` nell'endpoint diretto. L'esecuzione diretta non dipende più dal flag `active` legato al contesto shop, ma richiede comunque modulo installato e token cron valido.

Versione 1.0.10: aggiunto un endpoint cron diretto e protetto in `/modules/cdiscountorders/cron.php`, indipendente dal Dispatcher front-office di PrestaShop che sul sito restituiva 404 anche usando `index.php?fc=module`.

Versione 1.0.9: l'URL cron usa la forma universale `index.php?fc=module&module=cdiscountorders&controller=cron`, evitando l'errore 404 riscontrato con la rotta amichevole `/it/module/cdiscountorders/cron`.

Versione 1.0.8: l'invio riconosce anche il passaggio allo stato `Spedito` registrato nella cronologia PrestaShop, utile quando un altro modulo cambia nuovamente lo stato tecnico corrente subito dopo l'aggiornamento. Il diagnostico mostra ID e nome dello stato corrente se l'ordine resta in attesa.

Versione 1.0.7: corretto il riconoscimento degli ordini PrestaShop visualizzati come `Spedito` anche quando lo stato non espone il flag tecnico `shipped`. La riga corriere con tracking viene letta con priorità e il riepilogo indica il motivo preciso di un'eventuale attesa.

Versione 1.0.6: il tracking inviato a Octopia viene sempre normalizzato con il prefisso `CS` (esempio: `661673174` diventa `CS661673174`), senza duplicarlo se è già presente. Lo stesso valore viene usato come numero spedizione Octopia.

Versione 1.0.5: corretto il caricamento da versioni precedenti. Le migrazioni 1.0.2-1.0.4 non possono più annullare l'aggiornamento del modulo; la correzione dati viene ritentata in modo protetto dal pannello di configurazione.

Versione 1.0.4: la correzione dei vecchi totali è protetta e non può più rendere inaccessibile la configurazione con errore 500. Vengono riallineati ordine, corriere, fattura e pagamento senza sommare la spedizione PrestaShop.

Versione 1.0.3: il totale importato corrisponde alla somma dei prodotti Octopia; qualsiasi costo di spedizione calcolato da PrestaShop viene azzerato. Gli ordini già importati vengono corretti automaticamente.

Versione 1.0.2: gli ordini importati vengono assegnati allo stato standard PrestaShop `Pagamento accettato`; gli ordini già importati con il vecchio stato vengono aggiornati automaticamente.

Versione 1.0.1: corretta la ricerca SKU/EAN su MariaDB eliminando il doppio `LIMIT 1` aggiunto automaticamente da PrestaShop.

## Funzioni

- credenziali Octopia autonome: `CDO_CLIENT_ID`, `CDO_CLIENT_SECRET`, `CDO_SELLER_ID`, `CDO_SALES_CHANNEL_ID` gestite dal pannello del modulo;
- accetta gli ordini Octopia in `WaitingAcceptance`;
- importa in PrestaShop gli ordini `InPreparation` con modalità logistica `Seller`;
- impedisce importazioni duplicate;
- cerca i prodotti per riferimento SKU e poi per EAN;
- usa una riga generica quando il prodotto non esiste;
- invia a Octopia corriere e tracking quando l'ordine PrestaShop è nello stato Spedito;
- usa il tracking PrestaShop anche come `parcelNumber` Octopia;
- include esecuzione manuale, URL cron protetto e log.

## Installazione

1. Installa questo ZIP da Gestione moduli.
2. Apri la configurazione del modulo, inserisci nel pannello "Credenziali Octopia" Client ID, Client Secret, Seller ID e Sales Channel ID (Cdiscount Francia = `CDISFR`), poi premi "Verifica connessione".
3. Seleziona il corriere usato inizialmente negli ordini importati.
4. Configura sul server l'URL cron mostrato dal modulo ogni 10 minuti.

## Invio spedizione

Nell'ordine PrestaShop inserisci il numero di tracking, seleziona il corriere e cambia lo stato in uno stato con proprietà `Spedito`. Il modulo invia automaticamente una sola spedizione Octopia contenente tutte le righe ancora spedibili.

Octopia accetta la dichiarazione soltanto quando le righe sono `InPreparation` e `supplyMode` è `Seller`.
