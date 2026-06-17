<?php
// ============================================================
// Configurazione credenziali BRT
// ============================================================
@include __DIR__ . '/.env.php';

if (!defined('BRT_USER_ID') || BRT_USER_ID === 'YOUR_USER_ID') {
    $credentialsWarning = 'Le credenziali BRT non sono configurate. Modifica il file <code>.env.php</code> con le tue credenziali.';
}

define('BRT_API_BASE', 'https://api.brt.it/rest/v1/tracking/parcelID/');

// ============================================================
// Funzione tracking via REST API BRT (parcelID)
// ============================================================
function brt_tracking_rest(string $parcelId): array {
    if (empty($parcelId)) {
        return ['error' => 'ID collo non fornito.'];
    }

    if (BRT_USER_ID === 'YOUR_USER_ID' || BRT_PASSWORD === 'YOUR_PASSWORD') {
        return ['error' => 'Credenziali BRT non configurate. Modifica .env.php con le tue credenziali.'];
    }

    $url = BRT_API_BASE . urlencode($parcelId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'userID: '   . BRT_USER_ID,
            'password: ' . BRT_PASSWORD,
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['error' => 'Errore di connessione: ' . $curlError];
    }
    if ($httpCode !== 200) {
        $msg = 'Risposta HTTP ' . $httpCode . ' dal server BRT.';
        if ($httpCode === 401) $msg .= ' Credenziali non valide.';
        if ($httpCode === 404) $msg .= ' ID collo non trovato.';
        return ['error' => $msg];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Risposta non valida dal server BRT: ' . json_last_error_msg()];
    }

    // Controllo esito
    $execMsg = $data['executionMessage'] ?? null;
    if ($execMsg && isset($execMsg['code']) && (int)$execMsg['code'] < 0) {
        $desc = $execMsg['codeDesc'] ?? 'Errore sconosciuto';
        $detail = $execMsg['message'] ?? '';
        return ['error' => 'BRT: ' . $desc . ($detail ? ' (' . $detail . ')' : '')];
    }

    return $data;
}

// ============================================================
// Funzione tracking via SOAP (BRT shipment ID)
// ============================================================
function brt_tracking_soap(string $shipmentId, string $lang = 'it'): array {
    if (empty($shipmentId)) {
        return ['error' => 'ID spedizione non fornito.'];
    }

    $wsdlUrl     = 'https://wsr.brt.it:10052/web/BRT_TrackingByBRTshipmentIDService/BRT_TrackingByBRTshipmentID?wdsl';
    $soapRequest = '<?xml version="1.0" encoding="utf-8"?>'
        . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" '
        . 'xmlns:brt="http://brt_trackingbybrtshipmentid.wsbeans.iseries/">'
        . '<soap:Header/><soap:Body>'
        . '<brt:brt_trackingbybrtshipmentid>'
        . '<arg0>'
        . '<SPEDIZIONE_ANNO>0</SPEDIZIONE_ANNO>'
        . '<SPEDIZIONE_BRT_ID>' . htmlspecialchars($shipmentId) . '</SPEDIZIONE_BRT_ID>'
        . '<LINGUA_ISO639_ALPHA2>' . htmlspecialchars($lang) . '</LINGUA_ISO639_ALPHA2>'
        . '</arg0>'
        . '</brt:brt_trackingbybrtshipmentid>'
        . '</soap:Body></soap:Envelope>';

    $ch = curl_init($wsdlUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => $soapRequest,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8'],
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['error' => 'Errore di connessione SOAP: ' . $curlError];
    }
    if (!$response) {
        return ['error' => 'Nessuna risposta dal server SOAP BRT.'];
    }

    $xml = @simplexml_load_string($response);
    if (!$xml) {
        return ['error' => 'Risposta SOAP non valida.'];
    }

    $statoSped   = '';
    $descriptions = $dates = $ore = $filiali = [];

    foreach ($xml->xpath('//STATO_SPED_PARTE1') as $v) {
        if (!empty($v)) $statoSped = (string)$v;
    }
    foreach ($xml->xpath('//DESCRIZIONE_STATO_SPED_PARTE1') as $v) {
        if (!empty($v)) $statoSped .= ' ' . (string)$v;
    }
    foreach ($xml->xpath('//DESCRIZIONE') as $v)  { if (!empty($v)) $descriptions[] = (string)$v; }
    foreach ($xml->xpath('//DATA')        as $v)  { if (!empty($v)) $dates[]        = str_replace('.', '-', (string)$v); }
    foreach ($xml->xpath('//ORA')         as $v)  { if (!empty($v)) $ore[]          = (string)$v; }
    foreach ($xml->xpath('//FILIALE')     as $v)  { if (!empty($v)) $filiali[]      = (string)$v; }

    $eventi = [];
    $count  = count($descriptions);
    for ($i = 0; $i < $count; $i++) {
        $eventi[] = [
            'descrizione' => $descriptions[$i],
            'data'        => $dates[$i]  ?? '',
            'ora'         => $ore[$i]    ?? '',
            'filiale'     => $filiali[$i] ?? '',
        ];
    }

    return [
        'stato_spedizione' => trim($statoSped),
        'last_state'       => $descriptions[0] ?? '',
        'eventi'           => $eventi,
    ];
}

// ============================================================
// Helpers
// ============================================================
function e(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function format_date(string $d): string {
    if (empty($d)) return '-';
    $ts = strtotime($d);
    if (!$ts) return e($d);
    return date('d/m/Y', $ts);
}

function stato_badge(string $stato): string {
    $stato = strtoupper($stato);
    if (str_contains($stato, 'CONSEGN')) return 'bg-success';
    if (str_contains($stato, 'TRANSIT') || str_contains($stato, 'VIAGGIO')) return 'bg-primary';
    if (str_contains($stato, 'FILIALE')) return 'bg-info text-dark';
    if (str_contains($stato, 'CONSEGNA') || str_contains($stato, 'ATTEMP')) return 'bg-warning text-dark';
    if (str_contains($stato, 'ANNULL') || str_contains($stato, 'ERROR')) return 'bg-danger';
    return 'bg-secondary';
}

function progress_step(string $stato, bool $consegnata = false): int {
    if ($consegnata) return 5;
    $stato = strtoupper(trim($stato));
    // Codici brevi BRT (stato_sped_parte1)
    if (in_array($stato, ['CONS', '11', 'CONSEGNATA', 'DELIVERED'])) return 5;
    if (in_array($stato, ['INC', 'IN CONS', 'IN CONSEGNA', 'OUT FOR DELIVERY'])) return 4;
    if (in_array($stato, ['ARR', 'FILIALE', 'IN FILIALE', 'AT HUB'])) return 3;
    if (in_array($stato, ['TRAN', 'TRANSIT', 'IN TRANSITO', 'IN VIAGGIO', 'IN TRANSIT'])) return 2;
    // Controllo su testo esteso
    if (str_contains($stato, 'CONSEGNAT') && !str_contains($stato, 'IN CONSEGNA')) return 5;
    if (str_contains($stato, 'IN CONSEGNA')) return 4;
    if (str_contains($stato, 'FILIALE') || str_contains($stato, 'HUB')) return 3;
    if (str_contains($stato, 'TRANSIT') || str_contains($stato, 'VIAGGIO') || str_contains($stato, 'TRAN')) return 2;
    return 1;
}

// ============================================================
// Elaborazione richiesta
// ============================================================
$risultato   = null;
$errore      = null;
$parcelInput = '';
$searchMode  = 'rest'; // 'rest' = parcelID, 'soap' = shipment BRT ID

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $parcelInput = trim($_POST['parcel_id'] ?? '');
    $searchMode  = $_POST['search_mode'] ?? 'rest';

    if ($searchMode === 'soap') {
        $raw = brt_tracking_soap($parcelInput);
    } else {
        $raw = brt_tracking_rest($parcelInput);
    }

    if (isset($raw['error'])) {
        $errore = $raw['error'];
    } else {
        $risultato = $raw;
    }
}

// Estrazione dati per la vista REST
$bolla         = $risultato['ttParcelIdResponse']['bolla']   ?? null;
$datiSped      = $bolla['dati_spedizione']   ?? [];
$datiCons      = $bolla['dati_consegna']     ?? [];
$riferimenti   = $bolla['riferimenti']       ?? [];
$mittente      = $bolla['mittente']          ?? [];
$destinatario  = $bolla['destinatario']      ?? [];
$merce         = $bolla['merce']             ?? [];
$eventi        = $bolla['eventi']['evento']  ?? [];
$execMsg       = $risultato['executionMessage'] ?? null;

// Normalizza eventi (array singolo vs multiplo)
if (isset($eventi['descrizione'])) {
    $eventi = [$eventi];
}

// Dati SOAP
$soapData   = null;
$soapEventi = [];
if ($searchMode === 'soap' && $risultato) {
    $soapData   = $risultato;
    $soapEventi = $risultato['eventi'] ?? [];
}

// Derivazione stato: priorità a data_consegna_merce, poi descrizione, poi codice corto
$isConsegnata = !empty($datiCons['data_consegna_merce']);
if ($isConsegnata) {
    $statoRaw = 'Consegnata';
} elseif (!empty($datiSped['descrizione_stato_sped_parte1'])) {
    $statoRaw = trim($datiSped['descrizione_stato_sped_parte1']);
    if (!empty($datiSped['descrizione_stato_sped_parte2'])) {
        $statoRaw .= ' ' . trim($datiSped['descrizione_stato_sped_parte2']);
    }
} elseif (!empty($datiSped['stato_sped_parte1'])) {
    $statoRaw = trim($datiSped['stato_sped_parte1']);
    if (!empty($datiSped['stato_sped_parte2'])) {
        $statoRaw .= ' ' . trim($datiSped['stato_sped_parte2']);
    }
} else {
    $statoRaw = $soapData['stato_spedizione'] ?? '';
}
$step  = progress_step($statoRaw, $isConsegnata);
$steps = ['Affidate a BRT', 'In viaggio', 'In filiale', 'In consegna', 'Consegnata'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BRT Tracking — Ricerca collo</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <style>
        :root {
            --brt-red:   #cc0000;
            --brt-dark:  #222;
            --brt-light: #f5f5f5;
        }
        body { background: var(--brt-light); font-family: 'Segoe UI', sans-serif; }

        /* ---- Navbar ---- */
        .navbar-brt {
            background: #fff;
            border-bottom: 3px solid var(--brt-red);
            padding: 0.6rem 1.5rem;
        }
        .navbar-brt .brand-text {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--brt-red);
            letter-spacing: 1px;
        }

        /* ---- Search card ---- */
        .search-card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,.08);
        }
        .search-card .card-header {
            background: var(--brt-red);
            color: #fff;
            border-radius: 8px 8px 0 0;
            font-weight: 600;
        }
        .btn-brt {
            background: var(--brt-red);
            color: #fff;
            border: none;
            font-weight: 600;
        }
        .btn-brt:hover { background: #a00000; color: #fff; }

        /* ---- Progress bar steps ---- */
        .tracking-steps { display: flex; align-items: center; margin: 1.5rem 0 0.5rem; }
        .step-wrap { flex: 1; display: flex; flex-direction: column; align-items: center; position: relative; }
        .step-wrap:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 18px; left: 50%; right: -50%;
            height: 3px;
            background: #ddd;
            z-index: 0;
        }
        .step-wrap.done:not(:last-child)::after { background: var(--brt-red); }
        .step-wrap.active:not(:last-child)::after { background: var(--brt-red); }
        .step-icon {
            width: 38px; height: 38px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: #ddd; color: #888;
            font-size: 1rem; z-index: 1;
            transition: background .3s;
        }
        .step-wrap.done .step-icon   { background: var(--brt-red); color: #fff; }
        .step-wrap.active .step-icon { background: var(--brt-red); color: #fff; box-shadow: 0 0 0 4px rgba(204,0,0,.25); }
        .step-label { font-size: .72rem; color: #555; margin-top: 6px; text-align: center; }
        .step-wrap.done   .step-label { color: var(--brt-red); font-weight: 600; }
        .step-wrap.active .step-label { color: var(--brt-red); font-weight: 700; }

        /* ---- Info cards ---- */
        .info-card { border: none; border-radius: 8px; box-shadow: 0 1px 8px rgba(0,0,0,.07); }
        .info-card .card-header {
            background: #fff;
            border-bottom: 2px solid var(--brt-red);
            font-weight: 700; color: var(--brt-dark);
        }
        .info-label { font-size: .8rem; color: #888; margin-bottom: 2px; }
        .info-value { font-size: .95rem; color: var(--brt-dark); font-weight: 500; }

        /* ---- Status badge ---- */
        .stato-badge { font-size: .9rem; padding: .45em .9em; border-radius: 20px; }

        /* ---- DataTable override ---- */
        .dataTables_wrapper .dataTables_filter input { border: 1px solid #ccc; border-radius: 4px; padding: 3px 8px; }
        table.dataTable thead th { border-bottom: 2px solid var(--brt-red) !important; }

        /* ---- Chart container ---- */
        #chartWrap { max-height: 200px; }

        /* ---- Skeleton loader ---- */
        .skeleton { background: linear-gradient(90deg,#e8e8e8 25%,#f5f5f5 50%,#e8e8e8 75%); background-size: 200% 100%; animation: shimmer 1.4s infinite; border-radius: 4px; height: 14px; margin-bottom: 8px; }
        @keyframes shimmer { 0%{background-position:200% 0} 100%{background-position:-200% 0} }
    </style>
</head>
<body>

<!-- ===================== NAVBAR ===================== -->
<nav class="navbar navbar-brt mb-4">
    <span class="brand-text"><i class="fa-solid fa-box me-2"></i>BRT Tracking</span>
    <span class="ms-auto text-muted" style="font-size:.85rem">
        <i class="fa-regular fa-calendar me-1"></i><?= date('d/m/Y') ?>
    </span>
</nav>

<div class="container pb-5">

    <!-- ===================== CREDENZIALI ALERT ===================== -->
    <?php if (!empty($credentialsWarning)): ?>
    <div class="alert alert-danger d-flex align-items-start mb-4" role="alert">
        <i class="fa-solid fa-exclamation-circle fa-lg me-3 mt-1 flex-shrink-0"></i>
        <div>
            <strong>⚠️ Credenziali non configurate</strong><br>
            <?= $credentialsWarning ?><br><br>
            <code>.env.php</code> deve contenere:
            <pre style="background:#f8f9fa;padding:8px;border-radius:4px;margin-top:8px"><small>define('BRT_USER_ID',  'la_tua_user_id');<br>define('BRT_PASSWORD', 'la_tua_password');</small></pre>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===================== FORM RICERCA ===================== -->
    <div class="row justify-content-center mb-4">
        <div class="col-lg-8">
            <div class="card search-card">
                <div class="card-header py-3">
                    <i class="fa-solid fa-magnifying-glass me-2"></i>Ricerca spedizione BRT
                </div>
                <div class="card-body py-4">
                    <form method="POST" id="searchForm" novalidate>
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label fw-semibold">ID collo / Segnacollo</label>
                                <input
                                    type="text"
                                    name="parcel_id"
                                    id="parcel_id"
                                    class="form-control form-control-lg"
                                    placeholder="es. 01412301102688..."
                                    value="<?= e($parcelInput) ?>"
                                    required
                                    autocomplete="off"
                                >
                                <div class="invalid-feedback">Inserire un ID collo.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Modalità ricerca</label>
                                <select name="search_mode" class="form-select form-select-lg">
                                    <option value="rest"  <?= $searchMode === 'rest'  ? 'selected' : '' ?>>ParcelID (REST)</option>
                                    <option value="soap"  <?= $searchMode === 'soap'  ? 'selected' : '' ?>>Spedizione BRT (SOAP)</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-grid">
                                <button type="submit" class="btn btn-brt btn-lg" id="searchBtn">
                                    <i class="fa-solid fa-search me-1"></i>Cerca
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== ERRORE ===================== -->
    <?php if ($errore): ?>
    <div class="alert alert-danger d-flex align-items-center" role="alert">
        <i class="fa-solid fa-triangle-exclamation fa-lg me-3"></i>
        <div><strong>Attenzione:</strong> <?= e($errore) ?></div>
    </div>
    <?php endif; ?>

    <!-- ===================== RISULTATI REST ===================== -->
    <?php if ($risultato && $searchMode === 'rest' && $bolla): ?>

    <!-- Stato spedizione -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="card info-card">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div>
                            <div class="info-label">Segnacollo</div>
                            <div class="fw-bold fs-5"><?= e($parcelInput) ?></div>
                        </div>
                        <div>
                            <div class="info-label">ID Spedizione BRT</div>
                            <div class="fw-bold"><?= e($datiSped['spedizione_id'] ?? '-') ?></div>
                        </div>
                        <div class="ms-auto">
                            <span class="badge stato-badge <?= stato_badge($statoRaw) ?>">
                                <i class="fa-solid fa-circle-check me-1"></i>
                                <?= e($statoRaw ?: 'Stato sconosciuto') ?>
                            </span>
                        </div>
                        <?php if (!empty($datiSped['stato_sped_parte2'])): ?>
                        <div class="text-muted" style="font-size:.85rem"><?= e($datiSped['stato_sped_parte2']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Step tracker -->
                    <div class="tracking-steps mt-3">
                        <?php
                        $icons = ['fa-truck-ramp-box','fa-truck','fa-warehouse','fa-person-biking','fa-circle-check'];
                        foreach ($steps as $i => $s):
                            $n = $i + 1;
                            if ($step === 5) {
                                // Tutti completati: 1-4 done, 5 active
                                $cls = $n < 5 ? 'done' : 'active';
                            } else {
                                $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
                            }
                        ?>
                        <div class="step-wrap <?= $cls ?>">
                            <div class="step-icon"><i class="fa-solid <?= $icons[$i] ?>"></i></div>
                            <div class="step-label"><?= $s ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($datiCons['data_consegna_merce'])): ?>
                    <div class="text-end mt-2" style="font-size:.88rem">
                        <span class="text-success fw-semibold">
                            <i class="fa-regular fa-calendar-check me-1"></i>
                            Consegnata il <?= e(format_date($datiCons['data_consegna_merce'])) ?>
                            <?= !empty($datiCons['ora_consegna_merce']) ? 'alle ' . e($datiCons['ora_consegna_merce']) : '' ?>
                        </span>
                        <?php if (!empty($datiCons['firmatario_consegna'])): ?>
                        — Firmato da: <strong><?= e($datiCons['firmatario_consegna']) ?></strong>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Mittente / Destinatario -->
    <?php
    // Helper: costruisce stringa indirizzo completa
    function build_address(array $rec, string $prov_key = 'sigla_area'): string {
        $parts = [];
        if (!empty($rec['indirizzo'])) $parts[] = $rec['indirizzo'];
        $city = trim(($rec['cap'] ?? '') . ' ' . ($rec['localita'] ?? ''));
        if ($city) {
            $prov = $rec[$prov_key] ?? $rec['sigla_area'] ?? $rec['sigla_provincia'] ?? '';
            if ($prov) $city .= ' (' . $prov . ')';
            $parts[] = $city;
        }
        if (!empty($rec['sigla_nazione']) && $rec['sigla_nazione'] !== 'IT') {
            $parts[] = $rec['sigla_nazione'];
        }
        return implode(', ', $parts);
    }
    $mittAddr = build_address($mittente, 'sigla_area');
    $destAddr = build_address($destinatario, 'sigla_provincia');
    ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card info-card h-100">
                <div class="card-header">
                    <i class="fa-solid fa-building me-2 text-danger"></i>Mittente
                </div>
                <div class="card-body">
                    <?php if (!empty($mittente['ragione_sociale'])): ?>
                    <div class="mb-2">
                        <div class="info-label">Ragione sociale</div>
                        <div class="info-value fw-bold"><?= e($mittente['ragione_sociale']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($mittente['codice'])): ?>
                    <div class="mb-2">
                        <div class="info-label">Codice cliente</div>
                        <div class="info-value"><?= e($mittente['codice']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($mittAddr): ?>
                    <div class="mb-2">
                        <div class="info-label">Indirizzo</div>
                        <div class="info-value">
                            <?php if (!empty($mittente['indirizzo'])): ?>
                            <?= e($mittente['indirizzo']) ?><br>
                            <?php endif; ?>
                            <?php
                            $cityLine = trim(($mittente['cap'] ?? '') . ' ' . ($mittente['localita'] ?? ''));
                            $prov = $mittente['sigla_area'] ?? '';
                            if ($cityLine): ?>
                            <strong><?= e($cityLine) ?><?= $prov ? ' (' . e($prov) . ')' : '' ?></strong>
                            <?php endif; ?>
                            <?php if (!empty($mittente['sigla_nazione']) && $mittente['sigla_nazione'] !== 'IT'): ?>
                            — <?= e($mittente['sigla_nazione']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-muted small"><i class="fa-solid fa-info-circle me-1"></i>Indirizzo non disponibile</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card info-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid fa-user me-2 text-danger"></i>Destinatario</span>
                    <?php if ($destAddr): ?>
                    <a href="https://www.google.com/maps/search/<?= urlencode($destAddr) ?>" target="_blank"
                       class="btn btn-sm btn-outline-secondary" title="Apri in Google Maps">
                        <i class="fa-solid fa-map-location-dot"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($destinatario['ragione_sociale'])): ?>
                    <div class="mb-2">
                        <div class="info-label">Ragione sociale / Nome</div>
                        <div class="info-value fw-bold"><?= e($destinatario['ragione_sociale']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($destAddr): ?>
                    <div class="mb-2">
                        <div class="info-label">Indirizzo</div>
                        <div class="info-value">
                            <?php if (!empty($destinatario['indirizzo'])): ?>
                            <?= e($destinatario['indirizzo']) ?><br>
                            <?php endif; ?>
                            <?php
                            $cityLine = trim(($destinatario['cap'] ?? '') . ' ' . ($destinatario['localita'] ?? ''));
                            $prov = $destinatario['sigla_provincia'] ?? '';
                            if ($cityLine): ?>
                            <strong><?= e($cityLine) ?><?= $prov ? ' (' . e($prov) . ')' : '' ?></strong>
                            <?php endif; ?>
                            <?php if (!empty($destinatario['sigla_nazione']) && $destinatario['sigla_nazione'] !== 'IT'): ?>
                            — <?= e($destinatario['sigla_nazione']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-muted small"><i class="fa-solid fa-info-circle me-1"></i>Indirizzo non disponibile</div>
                    <?php endif; ?>
                    <?php if (!empty($destinatario['referente_consegna'])): ?>
                    <div class="mb-2">
                        <div class="info-label">Referente</div>
                        <div class="info-value"><?= e($destinatario['referente_consegna']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($destinatario['telefono_referente'])): ?>
                    <div class="mb-2">
                        <div class="info-label">Telefono</div>
                        <div class="info-value">
                            <a href="tel:<?= e($destinatario['telefono_referente']) ?>" class="text-decoration-none">
                                <i class="fa-solid fa-phone fa-xs me-1 text-muted"></i>
                                <?= e($destinatario['telefono_referente']) ?>
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Dati spedizione / merce / riferimenti -->
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card info-card h-100">
                <div class="card-header">
                    <i class="fa-solid fa-boxes-stacked me-2 text-danger"></i>Merce
                </div>
                <div class="card-body">
                    <?php
                    $merceFields = [
                        'Colli'     => $merce['colli']        ?? '-',
                        'Peso (kg)' => $merce['peso_kg']      ?? '-',
                        'Volume'    => !empty($merce['volume_m3']) ? $merce['volume_m3'] . ' m³' : '-',
                        'Natura'    => $merce['natura_merce'] ?? '-',
                    ];
                    foreach ($merceFields as $lbl => $val): ?>
                    <div class="mb-2">
                        <div class="info-label"><?= e($lbl) ?></div>
                        <div class="info-value"><?= e($val) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card info-card h-100">
                <div class="card-header">
                    <i class="fa-solid fa-circle-info me-2 text-danger"></i>Dettagli spedizione
                </div>
                <div class="card-body">
                    <?php
                    $spedFields = [
                        'Data spedizione'    => format_date($datiSped['spedizione_data'] ?? ''),
                        'Servizio'           => $datiSped['servizio']      ?? '-',
                        'Porto'              => $datiSped['porto']         ?? '-',
                        'Filiale arrivo'     => $datiSped['filiale_arrivo'] ?? '-',
                        'Cons. teorica'      => format_date($datiCons['data_teorica_consegna'] ?? ''),
                        'Cons. richiesta'    => format_date($datiCons['data_cons_richiesta']   ?? ''),
                    ];
                    foreach ($spedFields as $lbl => $val): ?>
                    <div class="mb-2">
                        <div class="info-label"><?= e($lbl) ?></div>
                        <div class="info-value"><?= e($val) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card info-card h-100">
                <div class="card-header">
                    <i class="fa-solid fa-hashtag me-2 text-danger"></i>Riferimenti
                </div>
                <div class="card-body">
                    <?php
                    $rifFields = [
                        'Rif. numerico'      => $riferimenti['riferimento_mittente_numerico']   ?? '-',
                        'Rif. alfabetico'    => $riferimenti['riferimento_mittente_alfabetico'] ?? '-',
                        'Rif. partner estero'=> $riferimenti['riferimento_partner_estero']      ?? '-',
                        'BRT Code'           => $datiSped['spedizione_id'] ?? '-',
                    ];
                    foreach ($rifFields as $lbl => $val): ?>
                    <div class="mb-2">
                        <div class="info-label"><?= e($lbl) ?></div>
                        <div class="info-value"><?= e($val) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Storico eventi -->
    <?php if (!empty($eventi)): ?>
    <div class="card info-card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-timeline me-2 text-danger"></i>Storico spedizioni</span>
            <span class="badge bg-secondary"><?= count($eventi) ?> eventi</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="eventsTable" class="table table-hover table-striped mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Data</th>
                            <th>Ora</th>
                            <th>Luogo</th>
                            <th>Stato spedizione</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eventi as $ev): ?>
                        <tr>
                            <td><?= e(format_date($ev['data'] ?? '')) ?></td>
                            <td><?= e($ev['ora'] ?? '-') ?></td>
                            <td><?= e($ev['filiale'] ?? '-') ?></td>
                            <td>
                                <span class="badge <?= stato_badge($ev['descrizione'] ?? '') ?>">
                                    <?= e($ev['descrizione'] ?? '-') ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Chart stati -->
    <?php
    $statiCount = [];
    foreach ($eventi as $ev) {
        $k = $ev['descrizione'] ?? 'Sconosciuto';
        $statiCount[$k] = ($statiCount[$k] ?? 0) + 1;
    }
    $chartLabels = json_encode(array_keys($statiCount));
    $chartData   = json_encode(array_values($statiCount));
    ?>
    <div class="card info-card mb-4">
        <div class="card-header">
            <i class="fa-solid fa-chart-pie me-2 text-danger"></i>Distribuzione stati
        </div>
        <div class="card-body">
            <div id="chartWrap">
                <canvas id="statiChart"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Debug panel -->
    <?php if (!empty($_GET['debug']) && $risultato): ?>
    <div class="card info-card mb-4 border border-warning">
        <div class="card-header bg-warning text-dark">
            <i class="fa-solid fa-bug me-2"></i>Debug — Risposta grezza API BRT
        </div>
        <div class="card-body p-0">
            <pre style="max-height:400px;overflow:auto;font-size:.75rem;padding:1rem;margin:0"><?= e(json_encode($risultato, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; /* fine REST */ ?>

    <!-- ===================== RISULTATI SOAP ===================== -->
    <?php if ($risultato && $searchMode === 'soap' && $soapData): ?>

    <div class="row mb-3">
        <div class="col-12">
            <div class="card info-card">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <div>
                            <div class="info-label">ID Spedizione BRT</div>
                            <div class="fw-bold fs-5"><?= e($parcelInput) ?></div>
                        </div>
                        <div class="ms-auto">
                            <span class="badge stato-badge <?= stato_badge($soapData['stato_spedizione'] ?? '') ?>">
                                <i class="fa-solid fa-circle-check me-1"></i>
                                <?= e($soapData['stato_spedizione'] ?: 'Stato sconosciuto') ?>
                            </span>
                        </div>
                    </div>

                    <div class="tracking-steps mt-3">
                        <?php
                        $icons = ['fa-truck-ramp-box','fa-truck','fa-warehouse','fa-person-biking','fa-circle-check'];
                        $stepSoap = progress_step($soapData['last_state'] ?? '');
                        foreach ($steps as $i => $s):
                            $n = $i + 1;
                            $cls = $n < $stepSoap ? 'done' : ($n === $stepSoap ? 'active' : '');
                        ?>
                        <div class="step-wrap <?= $cls ?>">
                            <div class="step-icon"><i class="fa-solid <?= $icons[$i] ?>"></i></div>
                            <div class="step-label"><?= $s ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($soapEventi)): ?>
    <div class="card info-card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-timeline me-2 text-danger"></i>Storico spedizioni</span>
            <span class="badge bg-secondary"><?= count($soapEventi) ?> eventi</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="eventsTableSoap" class="table table-hover table-striped mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Data</th>
                            <th>Ora</th>
                            <th>Filiale</th>
                            <th>Stato spedizione</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($soapEventi as $ev): ?>
                        <tr>
                            <td><?= e(format_date($ev['data'] ?? '')) ?></td>
                            <td><?= e($ev['ora'] ?? '-') ?></td>
                            <td><?= e($ev['filiale'] ?? '-') ?></td>
                            <td>
                                <span class="badge <?= stato_badge($ev['descrizione'] ?? '') ?>">
                                    <?= e($ev['descrizione'] ?? '-') ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; /* fine SOAP */ ?>

    <!-- Placeholder se nessun risultato e nessun errore e c'è stato un POST -->
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errore && !$risultato): ?>
    <div class="alert alert-warning d-flex align-items-center">
        <i class="fa-solid fa-circle-question fa-lg me-3"></i>
        <div>Nessun dato restituito per il codice <strong><?= e($parcelInput) ?></strong>. Verificare l'ID inserito.</div>
    </div>
    <?php endif; ?>

</div><!-- /container -->

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<script>
$(function () {
    // Form validation
    $('#searchForm').on('submit', function (e) {
        var v = $.trim($('#parcel_id').val());
        if (!v) {
            e.preventDefault();
            $('#parcel_id').addClass('is-invalid').focus();
            return;
        }
        $('#searchBtn').html('<span class="spinner-border spinner-border-sm me-1"></span>Ricerca…').prop('disabled', true);
    });
    $('#parcel_id').on('input', function () { $(this).removeClass('is-invalid'); });

    // DataTable eventi REST
    if ($('#eventsTable').length) {
        $('#eventsTable').DataTable({
            order: [[0, 'desc']],
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/it-IT.json'
            },
            pageLength: 10,
            dom: '<"d-flex justify-content-between align-items-center mb-2"lf>rtip'
        });
    }

    // DataTable eventi SOAP
    if ($('#eventsTableSoap').length) {
        $('#eventsTableSoap').DataTable({
            order: [[0, 'desc']],
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/it-IT.json'
            },
            pageLength: 10,
            dom: '<"d-flex justify-content-between align-items-center mb-2"lf>rtip'
        });
    }

    // Chart.js — distribuzione stati
    <?php if (!empty($statiCount)): ?>
    var ctx = document.getElementById('statiChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?= $chartLabels ?>,
                datasets: [{
                    data: <?= $chartData ?>,
                    backgroundColor: [
                        '#cc0000','#e63946','#457b9d','#2a9d8f','#e9c46a','#264653','#f4a261'
                    ],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'right' }
                }
            }
        });
    }
    <?php endif; ?>
});
</script>
</body>
</html>
