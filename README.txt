EBAY EAN13 IMPORTER v1.1.0
===========================

CORREZIONE v1.1.0 - "eBay ha accettato la richiesta ma non ha salvato l'EAN"
Causa: l'EAN veniva scritto solo in ProductListingDetails.EAN. Quel campo non
memorizza il GTIN: serve unicamente a cercare un prodotto nel catalogo eBay.
Con IncludeeBayProductDetails=false l'abbinamento non veniva neppure tentato,
quindi eBay accettava la revisione (Ack=Warning, avviso 21919456 sulle Business
Policies, non correlato) e scartava il valore. GetItem restituisce
ProductListingDetails solo per le inserzioni abbinate al catalogo, per cui
l'EAN risultava ancora assente e il controllo finale falliva.

Soluzione conforme alla documentazione eBay: i product identifier (GTIN, Brand,
MPN) vanno inviati come specifiche oggetto, perche' i valori passati solo
tramite ProductListingDetails vengono ignorati senza abbinamento al catalogo.
- L'EAN viene scritto come Item Specific "EAN" in ItemSpecifics.NameValueList.
- Tutte le specifiche esistenti vengono rilette e rimandate insieme alla nuova
  coppia EAN, perche' la revisione sostituisce l'intero blocco ItemSpecifics.
- L'eventuale vecchia specifica "EAN: Non applicabile" viene sostituita.
- L'EAN resta ripetuto, identico, anche in ProductListingDetails.EAN con
  IncludeeBayProductDetails=false, per le categorie che lo usano ancora.
- Titolo, descrizione, immagini e categoria non vengono piu' rimandati a eBay:
  non servono per la revisione e ogni reinvio poteva alterare l'inserzione.
- Se eBay restituisce il blocco ItemSpecifics ma la rilettura non produce
  alcuna coppia, l'aggiornamento viene bloccato invece di svuotare le
  specifiche dell'inserzione.
- Conferma finale su quattro riletture con attese crescenti, per assorbire il
  ritardo di indicizzazione della revisione.

CORREZIONE v1.0.9
- Per le inserzioni Trading API senza varianti, disattiva esplicitamente
  l'abbinamento automatico al catalogo eBay durante l'inserimento dell'EAN.
- Rilegge immediatamente prima della modifica titolo, descrizione, categoria,
  specifiche e immagini e li rimanda identici, come richiesto da eBay quando
  IncludeeBayProductDetails e' false.
- Rimosso GetCategoryFeatures dal flusso: il vecchio controllo poteva rispondere HTTP 410.
- L'eventuale incompatibilita' della categoria viene ora restituita direttamente da ReviseFixedPriceItem.
- Mantiene gli eventuali avvisi eBay nel report se il controllo finale fallisce.
- Riconosce l'EAN anche quando eBay lo restituisce tra le specifiche oggetto.

CORREZIONE v1.0.7
- Rilevamento automatico del modello con cui eBay gestisce ogni SKU.
- Inserzioni Inventory API: aggiornamento tramite createOrReplaceInventoryItem,
  dopo avere riletto e preservato integralmente tutti gli altri dati.
- Inserzioni Trading API: aggiornamento tramite ReviseFixedPriceItem come prima.
- Conferma EAN tramite la stessa API proprietaria dell'inserzione.
- Tre riletture brevi per evitare falsi errori dovuti alla propagazione eBay.

REQUISITI
- Hosting PHP 8.1 o superiore
- Estensioni PHP: cURL, SimpleXML
- HTTPS attivo
- Accesso in uscita verso api.ebay.com

CORREZIONE v1.0.3
- ZIP senza cartella interna: i file sono direttamente alla radice.
- Estraendo lo ZIP dentro /ebay vengono finalmente sovrascritti i file attivi.
- Versione v1.0.3 ben visibile sotto il titolo per verificare l'aggiornamento.

CORREZIONE TRASPORTO XML
- Ogni richiesta inizia con la dichiarazione XML UTF-8 richiesta dal gateway.
- Forzato HTTP/1.1 verso la Trading API eBay per evitare l'errore XML 5
  "soapenv:Body" riscontrato su alcuni server Hostinger.
- Connessione cURL nuova per ogni chiamata e Content-Length esplicito.

AGGIORNAMENTO HOSTINGER
1. Apri la cartella /ebay che contiene l'index.php attualmente in uso.
2. Carica qui lo ZIP v1.1.0 ed estrailo direttamente dentro /ebay.
3. Conferma la sovrascrittura di tutti i file.
4. Non cancellare la cartella data: contiene le credenziali gia salvate.
5. Ricarica la pagina con Ctrl+F5 e verifica che sotto il titolo compaia v1.1.0.
6. Premi "Verifica collegamento".

INSTALLAZIONE NUOVA
1. Estrai tutto il contenuto dello ZIP nella cartella del sottodominio scelto.
2. Apri il sito dal browser.
3. Password iniziale: MB-7f2ed4eedb6ea15933d3
4. Inserisci Client ID, Client Secret, Refresh Token, RuName e Scope eBay.
5. Premi "Salva credenziali" e poi "Verifica collegamento".

FORMATO XLS
Il file deve essere un vero Excel 97-2003 .xls (NON .xlsx).
Viene letto esclusivamente il primo foglio.

Riga 1:
SKU | ITEM ID | EAN13

Dalla riga 2:
PPA0006 | 123456789012 | 8033745210511

Per inserzioni con varianti, ogni variante va su una riga con il proprio SKU.
Più varianti possono condividere lo stesso ITEM ID.

SICUREZZA OPERATIVA
- L'app fa prima un controllo eBay in sola lettura.
- Inserisce l'EAN solo se il campo eBay è vuoto/non applicabile.
- Se eBay contiene già lo stesso EAN: nessuna modifica.
- Se eBay contiene un EAN diverso: NON lo sovrascrive.
- Per le varianti mantiene invariati prezzo e quantità disponibili durante la revisione EAN.
- Non modifica titolo, prezzo o quantità.
- Per le varianti rimanda a eBay prezzo e VariationSpecifics già letti, invariati,
  perché richiesti dalla Trading API per la revisione della variante.
- EAN13 validato anche tramite checksum.

IMPORTANTE
Dopo il primo test, cambia la password in config.php alla voce app_password.
Le credenziali e i token sono salvati nella cartella data, bloccata via .htaccess.
Non condividere lo ZIP dopo averlo configurato con le credenziali.
