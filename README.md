# eBay EAN13 Importer

App PHP per inserire in blocco gli EAN13 sulle inserzioni del proprio account
venditore eBay, partendo da un file Excel 97-2003 (`.xls`) con le colonne
`SKU | ITEM ID | EAN13`.

L'app lavora in due fasi: prima un controllo in sola lettura su eBay, poi
l'inserimento dell'EAN solo dove il campo risulta vuoto o "Non applicabile".
Un EAN diverso gia' presente non viene mai sovrascritto.

## Struttura

| File | Ruolo |
| --- | --- |
| `index.php` | Interfaccia, login, upload XLS, orchestrazione dei batch |
| `src/EbayClient.php` | Chiamate Trading API (XML) e Inventory API (REST) |
| `src/XlsReader.php` | Lettore BIFF8/OLE2 per i file `.xls` |
| `src/JobStore.php` | Persistenza delle lavorazioni su file JSON |
| `config.php` | Password app, site ID eBay, cartella dati |

## Come viene scritto l'EAN

- **Inserzioni Trading senza varianti**: l'EAN viene scritto come specifica
  oggetto (`ItemSpecifics.NameValueList` con `Name = EAN`) tramite
  `ReviseFixedPriceItem`, e ripetuto identico in `ProductListingDetails.EAN`
  con `IncludeeBayProductDetails = false`. `ProductListingDetails` da solo non
  memorizza il GTIN: serve alla ricerca nel catalogo eBay e, senza abbinamento,
  il valore viene scartato pur ricevendo una risposta accettata.
- **Inserzioni con varianti**: `Variations.Variation.VariationProductListingDetails.EAN`,
  che e' la posizione prevista da eBay per il GTIN a livello di variante.
- **Inserzioni Inventory API**: `createOrReplaceInventoryItem` con `product.ean`,
  rimandando invariati tutti gli altri campi riletti un istante prima.

Dopo ogni scrittura l'EAN viene riletto da eBay: lo stato "EAN inserito" viene
assegnato solo se eBay restituisce davvero il valore atteso.

Il dettaglio delle correzioni e le istruzioni di installazione sono in
`README.txt`.
