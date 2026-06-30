<?php
/**
 * BRT CSV SFTP Lookup
 */

define('CSV_SFTP_HOST',   'sftp.brt.it');
define('CSV_SFTP_USER',   '0141416');
define('CSV_SFTP_PASS',   '7h[cmC4R=4');
define('CSV_SFTP_DIR',    '/OUT/');
define('CSV_CACHE_DIR',   __DIR__ . '/../cache/');
define('CSV_CACHE_TTL',   1800);

function listSftpCsvFiles(): array {
    $ch = curl_init('sftp://' . CSV_SFTP_HOST . CSV_SFTP_DIR);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERPWD=>CSV_SFTP_USER.':'.CSV_SFTP_PASS,CURLOPT_PROTOCOLS=>CURLPROTO_SFTP,CURLOPT_TIMEOUT=>20,CURLOPT_FTP_USE_EPSV=>false,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false]);
    $listing = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($listing === false || $listing === '') { error_log("BRT CSV SFTP list error: $err"); return []; }
    $lines = array_filter(array_map('trim', explode("\n", $listing)));
    $csvFiles = [];
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', $line);
        $filename = end($parts);
        if (preg_match('/\.csv$/i', $filename)) $csvFiles[] = $filename;
    }
    return $csvFiles;
}

function downloadSftpFile(string $filename): ?string {
    $ch = curl_init('sftp://' . CSV_SFTP_HOST . CSV_SFTP_DIR . $filename);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERPWD=>CSV_SFTP_USER.':'.CSV_SFTP_PASS,CURLOPT_PROTOCOLS=>CURLPROTO_SFTP,CURLOPT_TIMEOUT=>30,CURLOPT_FTP_USE_EPSV=>false,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false]);
    $content = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($content === false || $content === '') { error_log("BRT CSV SFTP download error ($filename): $err"); return null; }
    return $content;
}

function parseBrtCsv(string $csvContent): array {
    $rows = [];
    $lines = explode("\n", str_replace("\r", '', $csvContent));
    if (count($lines) < 2) return [];
    $headers = array_map('trim', str_getcsv(trim($lines[0]), ';', '"'));
    for ($i = 1; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if ($line === '') continue;
        $values = str_getcsv($line, ';', '"');
        if (count($values) !== count($headers)) continue;
        $row = [];
        foreach ($headers as $idx => $col) $row[$col] = trim($values[$idx] ?? '');
        $rows[] = $row;
    }
    return $rows;
}

function ensureCacheDir(): bool {
    $dir = CSV_CACHE_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) { error_log("BRT CSV: impossibile creare cache dir: $dir"); return false; }
    $ht = $dir . '.htaccess';
    if (!file_exists($ht)) file_put_contents($ht, "Deny from all\n");
    return true;
}

function loadFromCache(string $filename): ?array {
    if (!ensureCacheDir()) return null;
    $cachePath = CSV_CACHE_DIR . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filename) . '.json';
    if (!file_exists($cachePath)) return null;
    if ((time() - filemtime($cachePath)) > CSV_CACHE_TTL) { @unlink($cachePath); return null; }
    $json = file_get_contents($cachePath);
    if ($json === false) return null;
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function saveToCache(string $filename, array $rows): void {
    if (!ensureCacheDir()) return;
    $cachePath = CSV_CACHE_DIR . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filename) . '.json';
    file_put_contents($cachePath, json_encode($rows, JSON_UNESCAPED_UNICODE));
}

/**
 * Cerca un collo nei CSV SFTP BRT per colonna PARCEL (BRT code).
 * Funziona sia con l'id collo sia con il BRT code (entrambi corrispondono al valore PARCEL).
 */
function lookupParcelInCsv(string $parcelId): ?array {
    $parcelId = trim($parcelId);
    if ($parcelId === '') return null;

    try { $files = listSftpCsvFiles(); } catch (Throwable $e) { error_log("BRT CSV exception: " . $e->getMessage()); return null; }
    if (empty($files)) { error_log("BRT CSV: nessun file CSV trovato su SFTP"); return null; }

    foreach ($files as $filename) {
        try {
            $rows = loadFromCache($filename);
            if ($rows === null) {
                $content = downloadSftpFile($filename);
                if ($content === null) continue;
                $rows = parseBrtCsv($content);
                if (!empty($rows)) saveToCache($filename, $rows);
            }
            foreach ($rows as $row) {
                // Confronto diretto con la colonna PARCEL (BRT code)
                if (trim($row['PARCEL'] ?? '') === $parcelId) {
                    return [
                        'ragione_sociale'   => $row['VABRSD'] ?? '',
                        'indirizzo'         => $row['VABIND'] ?? '',
                        'cap'               => $row['VABCAD'] ?? '',
                        'localita'          => $row['VABLOD'] ?? '',
                        'sigla_provincia'   => $row['VABPRD'] ?? '',
                        'numero_ordine'     => $row['VABNOT'] ?? '',
                        'note_consegna_csv' => $row['VABNT2'] ?? '',
                        'csv_file'          => $filename,
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log("BRT CSV errore su file $filename: " . $e->getMessage());
            continue;
        }
    }
    return null;
}
