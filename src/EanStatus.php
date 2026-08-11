<?php
declare(strict_types=1);

/**
 * Classificazione di una riga del file dopo la lettura da eBay.
 *
 * eBay conserva l'EAN in due campi distinti, che l'app deve tenere separati:
 *
 * - l'identificatore di prodotto (Trading API: ProductListingDetails.EAN), che
 *   e' il valore pubblicato nella colonna "P:EAN" dei report venditore
 *   scaricabili da eBay;
 * - la specifica oggetto "EAN", che e' una semplice caratteristica
 *   dell'inserzione e non compare in quella colonna.
 *
 * Fino alla v1.1.0 i due valori venivano fusi in uno solo: un'inserzione con la
 * sola specifica oggetto risultava "EAN gia' presente" e veniva saltata, pur
 * avendo su eBay l'identificatore di prodotto vuoto.
 */
final class EanStatus
{
    /** @return array{0:string,1:string} stato e messaggio */
    public static function classify(string $fileEan, string $productEan, string $specificEan): array
    {
        if (!EbayClient::eanMissing($productEan)) {
            return $productEan === $fileEan
                ? ['present', 'Nessuna modifica necessaria.']
                : ['conflict', 'eBay ha già un EAN diverso: non verrà sovrascritto.'];
        }
        if (EbayClient::eanMissing($specificEan)) {
            return ['ready', 'Pronto per inserimento.'];
        }
        if ($specificEan === $fileEan) {
            return ['ready', 'EAN presente solo nelle specifiche oggetto: verrà allineato anche l’identificatore di prodotto.'];
        }
        return ['conflict', 'Le specifiche oggetto eBay contengono già un EAN diverso (' . $specificEan . '): non verrà sovrascritto.'];
    }
}
