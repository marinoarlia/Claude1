<?php
/**
 * BRT CSV SFTP Lookup - legge i CSV giornalieri da SFTP BRT e cerca un collo per ID
 *
 * Utilizzo: include questo file in brt-tracking.php e chiama lookupParcelInCsv($parcelId)
 * Ritorna un array con i dati del destinatario oppure null se non trovato o in caso di errore.
 */

define('CSV_SFTP_HOST',   'sftp.brt.it');
define('CSV_SFTP_USER',   '0141416');
define('CSV_SFTP_PASS',   '7h[cmC4R=4');
define('CSV_SFTP_DIR',    '/OUT/');
define('CSV_CACHE_DIR',   __DIR__ . '/../cache/');
define('CSV_CACHE_TTL',   1800); // 30 minuti

/**
 * Costruisce l'ID a 15 cifre da una riga CSV parsata (array colonna => valore).
 * 014 (da VABCCM) + 3 cifre VABLNA (padStart 3, '0') + '01' fisso + 7 cifre VABNCD
 */
function buildParcelIdFromRow(array $row): string {
    $vabccm = trim($row['VABCCM'] ?? '');
    $vablna = trim($row['VABLNA'] ?? '');
    $vabncd = trim($row['VABNCD'] ?? '');

    // Primi due caratteri di VABCCM, anteponi "0" → sempre "014"
    $part1 = '0' . substr($vabccm, 0, 2);

    // VABLNA: filiale arrivo, padStart 3 con '0'
    $part2 = str_pad($vablna, 3, '0', STR_PAD_LEFT);

    // Fisso
    $part3 = '01';

    // VABNCD: sempre 7 cifre
    $part4 = str_pad($vabncd, 7, '0', STR_PAD_LEFT);

    return $part1 . $part2 . $part3 . $part4;
}

/**
 * Lista i file .csv presenti nella cartella SFTP (esclude .CHK e altre estensioni).
 * Ritorna array di nomi file o [] in caso di errore.
 */
function listSftpCsvFiles(): array {
    // Usa curl per listare la directory SFTP
    $url = 'sftp://' . CSV_SFTP_HOST . CSV_SFTP_DIR;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_USERPWD          => CSV_SFTP_USER . ':' . CSV_SFTP_PASS,
        CURLOPT_PROTOCOLS        => CURLPROTO_SFTP,
        CURLOPT_TIMEOUT          => 20,
        CURLOPT_FTP_USE_EPSV     => false,
        CURLOPT_SSL_VERIFYPEER   => false,
        CURLOPT_SSL_VERIFYHOST   => false,
    ]);

    $listing = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($listing === false || $listing === '') {
        error_log("BRT CSV SFTP list error: $err");
        return [];
    }

    // Il listing SFTP via curl restituisce una riga per file (solo nome)
    $lines = array_filter(array_map('trim', explode("\n", $listing)));
    $csvFiles = [];
    foreach ($lines as $line) {
        // Prendi solo l'ultimo token (nome file)
        $parts = preg_split('/\s+/', $line);
        $filename = end($parts);
        if (preg_match('/\.csv$/i', $filename)) {
            $csvFiles[] = $filename;
        }
    }

    return $csvFiles;
}

/**
 * Scarica il contenuto di un file CSV via SFTP.
 * Ritorna la stringa del file o null in caso di errore.
 */
function downloadSftpFile(string $filename): ?string {
    $url = 'sftp://' . CSV_SFTP_HOST . CSV_SFTP_DIR . $filename;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_USERPWD          => CSV_SFTP_USER . ':' . CSV_SFTP_PASS,
        CURLOPT_PROTOCOLS        => CURLPROTO_SFTP,
        CURLOPT_TIMEOUT          => 30,
        CURLOPT_FTP_USE_EPSV     => false,
        CURLOPT_SSL_VERIFYPEER   => false,
        CURLOPT_SSL_VERIFYHOST   => false,
    ]);

    $content = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($content === false || $content === '') {
        error_log("BRT CSV SFTP download error ($filename): $err");
        return null;
    }

    return $content;
}

/**
 * Parse di un CSV BRT (separatore ';', valori tra '"').
 * Ritorna array di righe, ognuna come array [colonna => valore].
 */
function parseBrtCsv(string $csvContent): array {
    $rows = [];
    $lines = explode("\n", str_replace("\r", '', $csvContent));
    if (count($lines) < 2) return [];

    // Prima riga = header
    $headerLine = trim($lines[0]);
    $headers = str_getcsv($headerLine, ';', '"');
    $headers = array_map('trim', $headers);

    for ($i = 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if ($line === '') continue;

        $values = str_getcsv($line, ';', '"');
        if (count($values) !== count($headers)) continue;

        $row = [];
        foreach ($headers as $idx => $col) {
            $row[$col] = trim($values[$idx] ?? '');
        }
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Assicura che la cartella cache esista e abbia il .htaccess protettivo.
 */
function ensureCacheDir(): bool {
    $dir = CSV_CACHE_DIR;
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log("BRT CSV: impossibile creare cache dir: $dir");
            return false;
        }
    }
    $htaccess = $dir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
    }
    return true;
}

/**
 * Carica i dati parsati di un file CSV dalla cache (se valida).
 * Ritorna array di righe o null se cache mancante/scaduta.
 */
function loadFromCache(string $filename): ?array {
    if (!ensureCacheDir()) return null;
    $cacheKey = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filename);
    $cachePath = CSV_CACHE_DIR . $cacheKey . '.json';

    if (!file_exists($cachePath)) return null;

    $mtime = filemtime($cachePath);
    if ((time() - $mtime) > CSV_CACHE_TTL) {
        // Cache scaduta
        @unlink($cachePath);
        return null;
    }

    $json = file_get_contents($cachePath);
    if ($json === false) return null;

    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/**
 * Salva i dati parsati di un file CSV in cache.
 */
function saveToCache(string $filename, array $rows): void {
    if (!ensureCacheDir()) return;
    $cacheKey = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filename);
    $cachePath = CSV_CACHE_DIR . $cacheKey . '.json';
    file_put_contents($cachePath, json_encode($rows, JSON_UNESCAPED_UNICODE));
}

/**
 * Funzione principale: cerca un collo nei CSV SFTP BRT.
 *
 * @param string $parcelId  ID a 15 cifre del collo
 * @return array|null  Array con dati destinatario oppure null se non trovato
 */
function lookupParcelInCsv(string $parcelId): ?array {
    $parcelId = trim($parcelId);
    if ($parcelId === '') return null;

    // Lista file CSV su SFTP
    try {
        $files = listSftpCsvFiles();
    } catch (Throwable $e) {
        error_log("BRT CSV listSftpCsvFiles exception: " . $e->getMessage());
        return null;
    }

    if (empty($files)) {
        error_log("BRT CSV: nessun file CSV trovato su SFTP");
        return null;
    }

    foreach ($files as $filename) {
        try {
            // Prova a caricare dalla cache
            $rows = loadFromCache($filename);

            if ($rows === null) {
                // Scarica da SFTP
                $content = downloadSftpFile($filename);
                if ($content === null) continue;

                $rows = parseBrtCsv($content);
                if (!empty($rows)) {
                    saveToCache($filename, $rows);
                }
            }

            // Cerca la riga con ID matching
            foreach ($rows as $row) {
                $rowId = buildParcelIdFromRow($row);
                if ($rowId === $parcelId) {
                    // Trovato! Estrai i dati rilevanti
                    return [
                        'ragione_sociale'  => $row['VABRSD'] ?? '',
                        'indirizzo'        => $row['VABIND'] ?? '',
                        'cap'              => $row['VABCAD'] ?? '',
                        'localita'         => $row['VABLOD'] ?? '',
                        'sigla_provincia'  => $row['VABPRD'] ?? '',
                        'numero_ordine'    => $row['VABNOT'] ?? '',
                        'note_consegna_csv'=> $row['VABNT2'] ?? '',
                        'csv_file'         => $filename,
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log("BRT CSV errore su file $filename: " . $e->getMessage());
            // Continua con il prossimo file
            continue;
        }
    }

    return null;
}
