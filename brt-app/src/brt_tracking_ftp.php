<?php
/**
 * Client di Tracking BRT REST API altamente rifinito
 * Tecnologie: PHP + Bootstrap 5 + Font Awesome 6 + DataTables + Chart.js
 * 
 * Istruzioni:
 * 1. Carica questo file sul tuo spazio FTP (es. rinominandolo in index.php)
 * 2. Compila le credenziali BRT_USERID e BRT_PASSWORD qui sotto o usa l'interfaccia web per testarli.
 * 3. Se lasciati vuoti o impostando "DEMO", lo script entrerà in modalità simulatore per dimostrazione completa.
 */

// --- CONFIGURAZIONE CREDENZIALI API BRT (In alternativa inseribili da pannello temporaneo) ---
define('BRT_USERID', '');    // <--- Inserisci qui il tuo UserID BRT
define('BRT_PASSWORD', '');  // <--- Inserisci qui la tua Password BRT

session_start();

// Gestione temporanea credenziali in Sessione per facilitarne il test immediato senza editare il file
if (isset($_POST['set_credentials'])) {
    $_SESSION['temp_userid'] = $_POST['temp_userid'] ?? '';
    $_SESSION['temp_password'] = $_POST['temp_password'] ?? '';
}

$userID = !empty(BRT_USERID) ? BRT_USERID : ($_SESSION['temp_userid'] ?? '');
$password = !empty(BRT_PASSWORD) ? BRT_PASSWORD : ($_SESSION['temp_password'] ?? '');

$parcelID = isset($_GET['parcelID']) ? trim($_GET['parcelID']) : '';
$error = null;
$result = null;
$executionMode = "Real API via cURL";

if ($parcelID !== '') {
    // Se non sono presenti credenziali reali, attiviamo il simulatore interattivo locale per dimostrazione
    if (empty($userID) || empty($password) || strtolower($userID) === 'demo' || strtolower($password) === 'demo') {
        $executionMode = "Simulatore Demonstrativo Offline (Credenziali BRT non inserite)";
        $result = generateDemoData($parcelID);
    } else {
        // --- CHIAMATA REALE API BRT REST ---
        $url = 'https://api.brt.it/rest/v1/tracking/parcelID/' . urlencode($parcelID);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'userID: ' . $userID,
            'password: ' . $password,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Abilita la verifica SSL per sicurezza
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = 'Errore di connessione cURL: ' . curl_error($ch);
        } elseif ($http_code !== 200) {
            $error = "L'API BRT ha restituito una risposta non valida (HTTP Code: $http_code).";
        } else {
            $data = json_decode($response, true);
            if ($data === null) {
                $error = "Impossibile decodificare la risposta JSON dell'API BRT.";
            } else {
                $result = parseBrtResponse($data, $parcelID);
            }
        }
        curl_close($ch);
    }
}

/**
 * Funzione per estrarre e mappare in modo sicuro i dati dello schema JSON BRT
 */
function parseBrtResponse($data, $parcelID) {
    $resBody = $data['parcelIDResult'] ?? $data['ttParcelIdResponse'] ?? $data;
    $bolla = $resBody['bolla'] ?? $resBody;
    
    $code = isset($resBody['code']) ? (int)$resBody['code'] : 0;
    
    $dest = $bolla['destinatario'] ?? $resBody['destinatario'] ?? [];
    $merce = $bolla['merce'] ?? $resBody['merce'] ?? [];
    $references = $bolla['riferimenti'] ?? $resBody['riferimenti'] ?? [];
    $deliv = $bolla['dati_consegna'] ?? $resBody['dati_consegna'] ?? [];
    
    // Flatten eventi
    $rawEvents = $bolla['lista_eventi'] ?? $resBody['lista_eventi'] ?? [];
    if (!is_array($rawEvents)) {
        $rawEvents = $rawEvents ? [$rawEvents] : [];
    }
    
    $events = [];
    foreach ($rawEvents as $item) {
        $ev = $item['evento'] ?? $item;
        $events[] = [
            'data' => $ev['data'] ?? '',
            'ora' => $ev['ora'] ?? '',
            'id' => $ev['id'] ?? '',
            'descrizione' => $ev['descrizione'] ?? '',
            'filiale' => $ev['filiale'] ?? ''
        ];
    }
    
    return [
        'parcelID' => $parcelID,
        'success' => $code >= 0,
        'code' => $code,
        'severity' => $resBody['severity'] ?? 'INFO',
        'codeDesc' => $resBody['codeDesc'] ?? '',
        'message' => $resBody['message'] ?? '',
        
        // Destinatario
        'ragione_sociale' => $dest['ragione_sociale'] ?? '',
        'indirizzo' => $dest['indirizzo'] ?? '',
        'cap' => $dest['cap'] ?? '',
        'localita' => $dest['localita'] ?? '',
        'sigla_provincia' => $dest['sigla_provincia'] ?? '',
        'sigla_nazione' => $dest['sigla_nazione'] ?? '',
        'referente_consegna' => $dest['referente_consegna'] ?? '',
        'telefono_referente' => $dest['telefono_referente'] ?? '',
        
        // Merce
        'colli' => isset($merce['colli']) ? (int)$merce['colli'] : 0,
        'peso_kg' => isset($merce['peso_kg']) ? (float)$merce['peso_kg'] : 0.0,
        
        // Riferimenti
        'riferimento_mittente_numerico' => isset($references['riferimento_mittente_numerico']) ? (int)$references['riferimento_mittente_numerico'] : 0,
        'riferimento_mittente_alfabetico' => $references['riferimento_mittente_alfabetico'] ?? '',
        
        // Consegna
        'data_cons_richiesta' => $deliv['data_cons_richiesta'] ?? '',
        'ora_cons_richiesta' => $deliv['ora_cons_richiesta'] ?? '',
        'tipo_cons_richiesta' => $deliv['tipo_cons_richiesta'] ?? '',
        'descrizione_cons_richiesta' => $deliv['descrizione_cons_richiesta'] ?? '',
        'data_teorica_consegna' => $deliv['data_teorica_consegna'] ?? '',
        'ora_teorica_consegna_da' => $deliv['ora_teorica_consegna_da'] ?? '',
        'ora_teorica_consegna_a' => $deliv['ora_teorica_consegna_a'] ?? '',
        'data_consegna_merce' => $deliv['data_consegna_merce'] ?? '',
        'ora_consegna_merce' => $deliv['ora_consegna_merce'] ?? '',
        'firmatario_consegna' => $deliv['firmatario_consegna'] ?? '',
        
        'lista_eventi' => $events,
        'stato_sped_parte1' => $resBody['stato_sped_parte1'] ?? $bolla['stato_sped_parte1'] ?? '',
        'stato_sped_parte2' => $resBody['stato_sped_parte2'] ?? $bolla['stato_sped_parte2'] ?? '',
        'descrizione_stato_sped_parte1' => $resBody['descrizione_stato_sped_parte1'] ?? $bolla['descrizione_stato_sped_parte1'] ?? '',
        'descrizione_stato_sped_parte2' => $resBody['descrizione_stato_sped_parte2'] ?? $bolla['descrizione_stato_sped_parte2'] ?? '',
    ];
}

/**
 * Generatore Dati Demo quando attivato in locale/senza credenziali
 */
function generateDemoData($parcelID) {
    $cleanId = strtoupper(trim($parcelID));
    
    // Definiamo scenari diversi in base a cosa si scrive nel campo di ricerca
    $isGiacenza = (strpos($cleanId, 'GIA') !== false || strpos($cleanId, '3') !== false);
    $isTransito = (strpos($cleanId, 'TRA') !== false || strpos($cleanId, '2') !== false);
    
    if ($isGiacenza) {
        return [
            'parcelID' => $parcelID,
            'success' => true,
            'code' => 0,
            'severity' => 'WARNING',
            'codeDesc' => 'In Giacenza',
            'message' => 'Spedizione attualmente ferma in giacenza per destinatario assente.',
            'ragione_sociale' => 'Pasticceria Delizie d\'Italia S.a.s.',
            'indirizzo' => 'Piazza Duomo, 14',
            'cap' => '50122',
            'localita' => 'Firenze',
            'sigla_provincia' => 'FI',
            'sigla_nazione' => 'IT',
            'referente_consegna' => 'Leonardo da Vinci',
            'telefono_referente' => '+39 055 1234567',
            'colli' => 4,
            'peso_kg' => 42.8,
            'riferimento_mittente_numerico' => 482094,
            'riferimento_mittente_alfabetico' => 'PAST-D-2026',
            'data_cons_richiesta' => '2026-06-16',
            'ora_cons_richiesta' => '14:30',
            'tipo_cons_richiesta' => 'Programmata',
            'descrizione_cons_richiesta' => 'Consegnare sul retro nel laboratorio',
            'data_teorica_consegna' => '2026-06-16',
            'ora_teorica_consegna_da' => '14:00',
            'ora_teorica_consegna_a' => '17:00',
            'data_consegna_merce' => '',
            'ora_consegna_merce' => '',
            'firmatario_consegna' => '',
            'stato_sped_parte1' => 'IN GIACENZA',
            'stato_sped_parte2' => 'DET-ASSENTE',
            'descrizione_stato_sped_parte1' => 'Merce ferma in deposito filiale',
            'descrizione_stato_sped_parte2' => 'Destinatario non presente al primo tentativo',
            'lista_eventi' => [
                ['data' => '2026-06-16', 'ora' => '18:12', 'id' => 'GIA', 'descrizione' => 'APERTURA PRATICA DI GIACENZA', 'filiale' => 'FIRENE CAMPI'],
                ['data' => '2026-06-16', 'ora' => '15:45', 'id' => 'ABS', 'descrizione' => 'DESTINATARIO ASSENTE - LASCIATO AVVISO', 'filiale' => 'FIRENZE CAMPI'],
                ['data' => '2026-06-16', 'ora' => '08:30', 'id' => 'O4D', 'descrizione' => 'IN CONSEGNA CON AUTISTA', 'filiale' => 'FIRENZE CAMPI'],
                ['data' => '2026-06-16', 'ora' => '04:15', 'id' => 'ARR', 'descrizione' => 'ARRIVATA PRESSO FILIALE DI DESTINAZIONE', 'filiale' => 'FIRENZE CAMPI'],
                ['data' => '2026-06-15', 'ora' => '23:00', 'id' => 'HUB', 'descrizione' => 'TRANSITO PRESSO HUB LOGISTICO', 'filiale' => 'BOLOGNA HUB'],
                ['data' => '2026-06-15', 'ora' => '16:00', 'id' => 'PUG', 'descrizione' => 'SPEDIZIONE PRELEVATA PRESSO MITTENTE', 'filiale' => 'TORINO SUD']
            ]
        ];
    } elseif ($isTransito) {
        return [
            'parcelID' => $parcelID,
            'success' => true,
            'code' => 0,
            'severity' => 'INFO',
            'codeDesc' => 'In Transito',
            'message' => 'Spedizione in orario regolare, verso la filiale di destinazione.',
            'ragione_sociale' => 'Ingegneria & Sistemi Marino',
            'indirizzo' => 'Via Giuseppe Garibaldi, 4',
            'cap' => '80142',
            'localita' => 'Napoli',
            'sigla_provincia' => 'NA',
            'sigla_nazione' => 'IT',
            'referente_consegna' => 'Ing. Marino Arlia',
            'telefono_referente' => '+39 081 998877',
            'colli' => 1,
            'peso_kg: ' => 8.5,
            'colli' => 1,
            'peso_kg' => 8.5,
            'riferimento_mittente_numerico' => 193022,
            'riferimento_mittente_alfabetico' => 'ENG-992-A',
            'data_cons_richiesta' => '',
            'ora_cons_richiesta' => '',
            'tipo_cons_richiesta' => '',
            'descrizione_cons_richiesta' => '',
            'data_teorica_consegna' => '2026-06-18',
            'ora_teorica_consegna_da' => '09:00',
            'ora_teorica_consegna_a: ' => '18:00',
            'ora_teorica_consegna_da' => '09:00',
            'ora_teorica_consegna_a' => '18:00',
            'data_consegna_merce' => '',
            'ora_consegna_merce' => '',
            'firmatario_consegna' => '',
            'stato_sped_parte1' => 'IN VIAGGIO',
            'stato_sped_parte2' => 'REGOLARE',
            'descrizione_stato_sped_parte1' => 'In viaggio verso filiale arrivo',
            'descrizione_stato_sped_parte2' => 'Tabella di marcia regolare',
            'lista_eventi' => [
                ['data' => '2026-06-17', 'ora' => '03:10', 'id' => 'HUB', 'descrizione' => 'ARRIVATA ALL\'HUB DI SMISTAMENTO CENTRALE', 'filiale' => 'BOLOGNA HUB'],
                ['data' => '2026-06-16', 'ora' => '20:15', 'id' => 'DEP', 'descrizione' => 'PARTITA DALLA FILIALE COMPOSIZIONE', 'filiale' => 'MILANO ROVERETO'],
                ['data' => '2026-06-16', 'ora' => '15:20', 'id' => 'PUG', 'descrizione' => 'RITIRO IN CORSO PRESSO SEDE CLIENTE', 'filiale' => 'MILANO ROVERETO']
            ]
        ];
    } else {
        // Consegnato Standard
        return [
            'parcelID' => $parcelID,
            'success' => true,
            'code' => 0,
            'severity' => 'INFO',
            'codeDesc' => 'Consegna Avvenuta',
            'message' => 'Spedizione regolarmente recapitata.',
            'ragione_sociale' => 'Boutique della Moda di Anna Bianchi',
            'indirizzo: ' => 'Corso Italia, 122',
            'ragione_sociale' => 'Moda & Stile di Anna Bianchi',
            'indirizzo' => 'Corso Italia, 122',
            'cap' => '70121',
            'localita' => 'Bari',
            'sigla_provincia' => 'BA',
            'sigla_nazione' => 'IT',
            'referente_consegna' => 'Anna Bianchi',
            'telefono_referente' => '+39 333 4455667',
            'colli' => 2,
            'peso_kg' => 12.4,
            'riferimento_mittente_numerico' => 842109,
            'riferimento_mittente_alfabetico' => 'MODA-BARI-03',
            'data_cons_richiesta' => '2026-06-15',
            'ora_cons_richiesta' => '10:00',
            'tipo_cons_richiesta' => 'Standard',
            'descrizione_cons_richiesta' => 'Prego consegnare nel negozio al piano terra',
            'data_teorica_consegna' => '2026-06-16',
            'ora_teorica_consegna_da' => '10:00',
            'ora_teorica_consegna_a' => '14:00',
            'data_consegna_merce' => '2026-06-16',
            'ora_consegna_merce' => '11:45',
            'firmatario_consegna' => 'Anna Bianchi (Proprietaria)',
            'stato_sped_parte1' => 'CONSEGNATA',
            'stato_sped_parte2' => 'OK',
            'descrizione_stato_sped_parte1' => 'Regolarmente consegnato',
            'descrizione_stato_sped_parte2' => 'Ricevuta firmata digitalmente',
            'lista_eventi' => [
                ['data' => '2026-06-16', 'ora' => '11:45', 'id' => 'DEL', 'descrizione' => 'CONSEGNA EFFETTUATA CON SUCCESSO', 'filiale' => 'BARI Z.I.'],
                ['data' => '2026-06-16', 'ora' => '07:30', 'id' => 'O4D', 'descrizione' => 'IN CONSEGNA CON AUTISTA BRT', 'filiale' => 'BARI Z.I.'],
                ['data' => '2026-06-16', 'ora' => '05:00', 'id' => 'ARR', 'descrizione' => 'ARRIVATA IN FILIALE DI DESTINAZIONE', 'filiale' => 'BARI Z.I.'],
                ['data' => '2026-06-15', 'ora' => '20:45', 'id' => 'HUB', 'descrizione' => 'SMISTATA PRESSO CENTRO COMMUTAZIONE', 'filiale' => 'BOLOGNA HUB'],
                ['data' => '2026-06-15', 'ora' => '14:15', 'id' => 'PUG', 'descrizione' => 'ACQUISITA IN FILIALE MITTENTE', 'filiale' => 'MILANO CENTRALE']
            ]
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracking BRT - Client REST API</title>
    <!-- CDN Bootstrap 5 (Styling richiesto) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- CDN Font Awesome 6 (Icone richieste) -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <!-- CDN DataTables CSS (Tabella richiesta) -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Google Fonts per tipografia curata -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f6fa;
            color: #2c3e50;
        }
        .card-brt {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
            background: #ffffff;
        }
        .header-brt {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: #fff;
            border-radius: 12px 12px 0 0;
            padding: 24px;
        }
        .btn-brt {
            background-color: #e74c3c;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease-in-out;
        }
        .btn-brt:hover {
            background-color: #c0392b;
            color: white;
            transform: translateY(-1px);
        }
        .badge-status {
            font-size: 0.95rem;
            padding: 8px 16px;
            border-radius: 30px;
            font-weight: 600;
        }
        .text-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            font-weight: 600;
            color: #95a5a6;
            margin-bottom: 2px;
        }
        .text-value {
            font-size: 1rem;
            font-weight: 500;
            color: #2c3e50;
        }
        .timeline-item {
            padding-left: 20px;
            border-left: 3px solid #e74c3c;
            position: relative;
            padding-bottom: 20px;
        }
        .timeline-item::after {
            content: '';
            width: 12px;
            height: 12px;
            background: #e74c3c;
            border: 2px solid #fff;
            border-radius: 50%;
            position: absolute;
            left: -8px;
            top: 4px;
        }
        .value-highlight {
            font-size: 1.4rem;
            font-weight: 700;
            color: #e74c3c;
        }
    </style>
</head>
<body>

<div class="container py-5">
    
    <!-- HEADER -->
    <div class="row mb-4">
        <div class="col-12 text-center">
            <h1 class="fw-bold text-dark mb-2"><i class="fa-solid fa-truck text-danger"></i> Client BRT API Tracking</h1>
            <p class="text-secondary">Pulsantiera e Dashboard integrata con PHP, Bootstrap, DataTables e Chart.js</p>
        </div>
    </div>

    <!-- CONFIG / CREDENZIALI ALERT -->
    <?php if (empty($userID) || empty($password)): ?>
        <div class="alert alert-warning card-brt p-4" role="alert">
            <div class="d-flex align-items-center mb-3">
                <i class="fa-solid fa-gears fa-2x text-warning me-3"></i>
                <h5 class="alert-heading m-0 fw-bold">Configurazione Rapida Credenziali</h5>
            </div>
            <p class="mb-3 Small">Le credenziali <code>BRT_USERID</code> e <code>BRT_PASSWORD</code> costanti non sono definite nel codice del file. Puoi inserirle provvisoriamente qui sotto per testare la connessione con le tue reali API BRT, altrimenti il sistema eseguirà una <strong>simulazione offline completa</strong> per mostrarti tutti i grafici e le tabelle.</p>
            <form method="POST" class="row row-cols-lg-auto g-3 align-items-center">
                <input type="hidden" name="set_credentials" value="1">
                <div class="col-12">
                    <input type="text" name="temp_userid" class="form-control form-control-sm" placeholder="UserID API BRT" value="<?php echo htmlspecialchars($userID); ?>" required>
                </div>
                <div class="col-12">
                    <input type="password" name="temp_password" class="form-control form-control-sm" placeholder="Password API BRT" value="<?php echo htmlspecialchars($password); ?>" required>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-warning btn-sm fw-medium">Registra Credenziali Sessione</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div class="alert alert-success card-brt p-3" role="alert">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <i class="fa-solid fa-shield-halved text-success me-2"></i>
                    <strong>Credenziali BRT caricate correttamente:</strong> 
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($userID); ?></span>
                </div>
                <button class="btn btn-sm btn-outline-danger mt-2 mt-md-0" onclick="window.location.href = window.location.pathname;">Scarta Credenziali Temp</button>
            </div>
        </div>
    <?php endif; ?>

    <!-- FORM DI RICERCA -->
    <div class="card card-brt">
        <div class="card-body p-4">
            <form method="GET" action="">
                <div class="row align-items-end g-3">
                    <div class="col-md-8">
                        <label for="parcelID" class="form-label fw-bold"><i class="fa-solid fa-barcode text-secondary me-2"></i>Inserisci ID Collo / Segnacollo BRT</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                            <input type="text" class="form-control form-control-lg" id="parcelID" name="parcelID" required 
                                   placeholder="Es: BRT-OK-CONSEGNATO, BRT-IN-TRANSIT, BRT-GIACENZA o un ID reale" 
                                   value="<?php echo htmlspecialchars($parcelID); ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-brt btn-lg w-full w-100 py-3"><i class="fa-solid fa-magnifying-glass-chart me-2"></i>Trova Spedizione</button>
                    </div>
                </div>
            </form>
            <div class="mt-3">
                <span class="small text-muted">Esempi di test rapidi per simulatore:</span>
                <a href="?parcelID=BRT-OK-CONSEGNATO" class="badge bg-light text-dark text-decoration-none border me-1">BRT-OK-CONSEGNATO</a>
                <a href="?parcelID=BRT-IN-TRANSIT" class="badge bg-light text-dark text-decoration-none border me-1">BRT-IN-TRANSIT</a>
                <a href="?parcelID=BRT-GIACENZA" class="badge bg-light text-dark text-decoration-none border Me-1">BRT-GIACENZA</a>
            </div>
        </div>
    </div>

    <!-- GESTIONE ERRORI -->
    <?php if ($error): ?>
        <div class="alert alert-danger card-brt p-4" role="alert">
            <div class="d-flex align-items-center">
                <i class="fa-solid fa-circle-exclamation fa-2x text-danger me-3"></i>
                <div>
                    <h5 class="alert-heading fw-bold mb-1">Si è verificato un errore</h5>
                    <p class="m-0"><?php echo htmlspecialchars($error); ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- RISULTATO -->
    <?php if ($result): ?>

        <div class="row mb-3">
            <div class="col-12">
                <div class="alert alert-info py-2 px-3 small border d-flex justify-content-between">
                    <span><strong>Modalità Esecuzione:</strong> <?php echo $executionMode; ?></span>
                    <span><strong>Esito del Server:</strong> Code <?php echo $result['code']; ?> (<?php echo htmlspecialchars($result['severity']); ?>)</span>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- COLONNA DETTAGLI -->
            <div class="col-lg-8">
                
                <!-- STATO PRINCIPALE -->
                <div class="card card-brt">
                    <div class="header-brt">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h5 class="text-uppercase m-0 opacity-75 small">ID COLLO BRT: <?php echo htmlspecialchars($result['parcelID']); ?></h5>
                                <h2 class="fw-bold m-0 mt-1"><?php echo htmlspecialchars($result['ragione_sociale'] ?: 'Fornitore Non Indicato'); ?></h2>
                            </div>
                            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                <?php
                                $badgeClass = "bg-success";
                                $statusIcon = "fa-check-double";
                                if (strpos(strtoupper($result['stato_sped_parte1']), 'GIACENZA') !== false) {
                                    $badgeClass = "bg-warning text-dark";
                                    $statusIcon = "fa-triangle-exclamation";
                                } elseif (strpos(strtoupper($result['stato_sped_parte1']), 'VIAGGIO') !== false || strpos(strtoupper($result['stato_sped_parte1']), 'TRANSITO') !== false) {
                                    $badgeClass = "bg-primary";
                                    $statusIcon = "fa-truck-fast";
                                }
                                ?>
                                <span class="badge <?php echo $badgeClass; ?> badge-status shadow-sm">
                                    <i class="fa-solid <?php echo $statusIcon; ?> me-2"></i><?php echo htmlspecialchars($result['stato_sped_parte1'] ?: 'REGISTRATA'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4 bg-light-subtle">
                        <div class="row g-4">
                            <div class="col-md-6 border-end">
                                <h5 class="fw-bold text-danger border-bottom pb-2 mb-3"><i class="fa-solid fa-map-location-dot me-2"></i>Destinatario (Spedizione a)</h5>
                                <div class="mb-3">
                                    <div class="text-label">Ragione Sociale</div>
                                    <div class="text-value text-dark fw-semibold"><?php echo htmlspecialchars($result['ragione_sociale']); ?></div>
                                </div>
                                <div class="mb-3">
                                    <div class="text-label">Indirizzo</div>
                                    <div class="text-value"><?php echo htmlspecialchars($result['indirizzo']); ?></div>
                                </div>
                                <div class="row">
                                    <div class="col-4 mb-3">
                                        <div class="text-label">C.A.P.</div>
                                        <div class="text-value"><?php echo htmlspecialchars($result['cap']); ?></div>
                                    </div>
                                    <div class="col-8 mb-3">
                                        <div class="text-label">Località</div>
                                        <div class="text-value"><?php echo htmlspecialchars($result['localita']); ?> (<?php echo htmlspecialchars($result['sigla_provincia']); ?>)</div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <div class="text-label">Nazione</div>
                                        <div class="text-value"><?php echo htmlspecialchars($result['sigla_nazione']); ?></div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-label">Provincia</div>
                                        <div class="text-value"><?php echo htmlspecialchars($result['sigla_provincia']); ?></div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <div class="text-label">Referente Consegna</div>
                                        <div class="text-value"><?php echo htmlspecialchars($result['referente_consegna'] ?: '-'); ?></div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-label">Telefono Referente</div>
                                        <div class="text-value"><?php echo htmlspecialchars($result['telefono_referente'] ?: '-'); ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5 class="fw-bold text-danger border-bottom pb-2 mb-3"><i class="fa-solid fa-box-open me-2"></i>Dati Merce & Riferimenti</h5>
                                <div class="row">
                                    <div class="col-6 mb-3 text-center border-end">
                                        <div class="text-label">Colli Totali</div>
                                        <div class="value-highlight"><?php echo htmlspecialchars($result['colli']); ?></div>
                                    </div>
                                    <div class="col-6 mb-3 text-center">
                                        <div class="text-label">Peso Spedizione</div>
                                        <div class="value-highlight"><?php echo htmlspecialchars($result['peso_kg']); ?> <span class="fs-5">kg</span></div>
                                    </div>
                                </div>
                                <hr>
                                <div class="mb-3">
                                    <div class="text-label">Riferimento Mittente Numerico</div>
                                    <div class="text-value"><i class="fa-solid fa-hashtag text-secondary me-2"></i><?php echo htmlspecialchars($result['riferimento_mittente_numerico'] ?: '-'); ?></div>
                                </div>
                                <div class="mb-3">
                                    <div class="text-label">Riferimento Mittente Alfabetico</div>
                                    <div class="text-value"><i class="fa-solid fa-font text-secondary me-2"></i><?php echo htmlspecialchars($result['riferimento_mittente_alfabetico'] ?: '-'); ?></div>
                                </div>
                                <div class="mb-3">
                                    <div class="text-label">Nota o Messaggio Risoluzione</div>
                                    <div class="text-value text-secondary small italic"><?php echo htmlspecialchars($result['message'] ?: 'Nessun messaggio di errore o nota associata.'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CONSEGNA RICHIESTA & TEORICA -->
                <div class="card card-brt">
                    <div class="card-body p-4">
                        <h5 class="fw-bold text-danger border-bottom pb-3 mb-3"><i class="fa-solid fa-calendar-check me-2"></i>Fascicolo Tempistiche & Pianificazione Consegne</h5>
                        <div class="row g-3">
                            <div class="col-md-6 border-end">
                                <h6 class="fw-bold mb-3 text-secondary"><i class="fa-solid fa-user-clock me-2"></i>Dettagli Consegna Richiesta</h6>
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <div class="text-label">Data Richiesta</div>
                                        <div class="text-value"><?php echo htmlspecialchars($result['data_cons_richiesta'] ?: '-'); ?></div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-label">Ora Richiesta</div>
                                        <div class="text-value"><?php echo htmlspecialchars($result['ora_cons_richiesta'] ?: '-'); ?></div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="text-label">Tipo Consegna Richiesta</div>
                                    <div class="text-value"><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($result['tipo_cons_richiesta'] ?: 'Standard / Libera'); ?></span></div>
                                </div>
                                <div class="mb-3">
                                    <div class="text-label">Descrizione Dettaglio Richiesta</div>
                                    <div class="text-value text-muted small"><?php echo htmlspecialchars($result['descrizione_cons_richiesta'] ?: 'Nessuna specifica o nota aggiunta.'); ?></div>
                                </div>
                            </div>
                            
                            <div class="col-md-6 ps-md-4">
                                <h6 class="fw-bold mb-3 text-secondary"><i class="fa-solid fa-clock-rotate-left me-2"></i>Stime Teoriche & Consegna Effettiva</h6>
                                <div class="row">
                                    <div class="col-12 mb-3">
                                        <div class="text-label">Data Teorica Consegna (Fascia Stimata)</div>
                                        <div class="text-value fw-semibold text-success">
                                            <i class="fa-regular fa-calendar-days me-2"></i><?php echo htmlspecialchars($result['data_teorica_consegna'] ?: 'Stima non disponibile'); ?>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-label">Ora Consegna Da</div>
                                        <div class="text-value"><i class="fa-regular fa-clock me-1"></i> <?php echo htmlspecialchars($result['ora_teorica_consegna_da'] ?: '-'); ?></div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-label">Ora Consegna A</div>
                                        <div class="text-value"><i class="fa-regular fa-clock me-1"></i> <?php echo htmlspecialchars($result['ora_teorica_consegna_a'] ?: '-'); ?></div>
                                    </div>
                                </div>
                                <hr class="my-2">
                                <div class="row pt-2 bg-light rounded p-2 border">
                                    <div class="col-12 mb-2">
                                        <div class="text-label">Consegna Merce Avvenuta Il</div>
                                        <div class="text-value fw-bold text-danger">
                                            <i class="fa-solid fa-circle-check me-2"></i><?php echo htmlspecialchars($result['data_consegna_merce'] ?: 'In transito...'); ?>
                                            <?php if ($result['ora_consegna_merce']): ?>
                                                alle ore <?php echo htmlspecialchars($result['ora_consegna_merce']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="text-label">Ricevuto Da / Firmatario Consegna</div>
                                        <div class="text-value italic fw-medium"><i class="fa-solid fa-signature text-secondary me-2"></i><?php echo htmlspecialchars($result['firmatario_consegna'] ?: 'In attesa di firma...'); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DATATABLES TIMELINE EVENTI BRT -->
                <div class="card card-brt">
                    <div class="card-body p-4">
                        <h5 class="fw-bold text-danger border-bottom pb-3 mb-3"><i class="fa-solid fa-list-check me-2"></i>Log Storico Eventi Spedizione (DataTables)</h5>
                        <p class="text-muted small">Tutti i passaggi registrati per questo segnacollo BRT. Puoi usare la casella di ricerca nativa o impaginare i risultati.</p>
                        
                        <div class="table-responsive">
                            <table id="eventiBrtTable" class="table table-striped table-hover border align-middle w-100">
                                <thead class="table-dark">
                                    <tr>
                                        <th><i class="fa-solid fa-calendar me-1"></i> Data</th>
                                        <th><i class="fa-solid fa-clock me-1"></i> Ora</th>
                                        <th>Cod.</th>
                                        <th><i class="fa-solid fa-truck-ramp-box me-1"></i> Descrizione Evento Interno</th>
                                        <th><i class="fa-solid fa-building-flag me-1"></i> Filiale</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($result['lista_eventi'])): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">Nessun evento registrato per questo codice</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($result['lista_eventi'] as $item): ?>
                                            <tr>
                                                <td class="fw-medium font-monospace"><?php echo htmlspecialchars($item['data']); ?></td>
                                                <td class="font-monospace"><?php echo htmlspecialchars($item['ora']); ?></td>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($item['id']); ?></span></td>
                                                <td class="fw-semibold text-dark"><?php echo htmlspecialchars($item['descrizione']); ?></td>
                                                <td><i class="fa-solid fa-warehouse text-secondary me-1"></i> <?php echo htmlspecialchars($item['filiale']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>

            <!-- COLONNA DIAGRAMMI & TIMELINE GRAFICA -->
            <div class="col-lg-4">
                
                <!-- CARD CHART.JS -->
                <div class="card card-brt">
                    <div class="card-header bg-danger text-white fw-bold py-3">
                        <i class="fa-solid fa-chart-simple me-2"></i>Statistica Colli & Peso Proporzionale
                    </div>
                    <div class="card-body p-4 text-center">
                        <canvas id="brtChart" style="max-height: 240px;"></canvas>
                        <div class="row pt-3 mt-3 border-top text-center">
                            <div class="col-6">
                                <span class="d-block text-muted small">Colli Spediti</span>
                                <h4 class="fw-bold text-dark"><?php echo $result['colli']; ?></h4>
                            </div>
                            <div class="col-6">
                                <span class="d-block text-muted small">Peso Totale kg</span>
                                <h4 class="fw-bold text-danger"><?php echo $result['peso_kg']; ?> kg</h4>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MILESTONE PROGRESS BAR -->
                <div class="card card-brt">
                    <div class="card-header bg-dark text-white fw-bold py-3">
                        <i class="fa-solid fa-route me-2"></i>Stato Avanzamento
                    </div>
                    <div class="card-body p-4 text-center">
                        <?php
                        $prog = 20; 
                        $progColor = "bg-secondary";
                        $progText = "Merce Ritirata";
                        
                        if (strpos(strtoupper($result['stat_sped_parte1'] ?? ''), 'VIAGGIO') !== false || strpos(strtoupper($result['stato_sped_parte1'] ?? ''), 'VIAGGIO') !== false) {
                            $prog = 60;
                            $progColor = "bg-primary shadow-sm";
                            $progText = "Spedizione in viaggio";
                        } elseif (strpos(strtoupper($result['stato_sped_parte1'] ?? ''), 'GIACENZA') !== false) {
                            $prog = 50;
                            $progColor = "bg-warning";
                            $progText = "Fermo per Anomalie";
                        } elseif ($result['data_consegna_merce'] !== '') {
                            $prog = 100;
                            $progColor = "bg-success";
                            $progText = "Consegna Avvenuta correttamente";
                        }
                        ?>
                        <h6 class="fw-bold mb-3 text-secondary"><?php echo $progText; ?></h6>
                        <div class="progress mb-4" style="height: 25px; border-radius: 20px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated <?php echo $progColor; ?>" 
                                 role="progressbar" style="width: <?php echo $prog; ?>%" 
                                 aria-valuenow="<?php echo $prog; ?>" aria-valuemin="0" aria-valuemax="100">
                                 <strong><?php echo $prog; ?>%</strong>
                            </div>
                        </div>
                        
                        <!-- Mini Timeline -->
                        <div class="text-start ms-2">
                            <div class="timeline-item <?php echo $prog >= 20 ? 'text-dark fw-medium' : 'text-muted'; ?>">
                                Prelevato ed Elaborato
                            </div>
                            <div class="timeline-item <?php echo $prog >= 60 ? 'text-dark fw-medium' : 'text-muted'; ?>">
                                In Transito (Hub)
                            </div>
                            <div class="timeline-item <?php echo $prog >= 100? 'text-success fw-bold' : 'text-muted'; ?>">
                                Consegnato & Firmato
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    <?php endif; ?>

</div>

<!-- SCRIPT CDN RICHIESTI -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
$(document).ready(function() {
    // Inizializza DataTables per storico eventi spedizione
    if ($('#eventiBrtTable').length) {
        $('#eventiBrtTable').DataTable({
            "language": {
                "lengthMenu": "Mostra _MENU_ eventi per pagina",
                "zeroRecords": "Nessun evento compatibile trovato",
                "info": "Pagina _PAGE_ di _PAGES_ (Totale _TOTAL_ record)",
                "infoEmpty": "Nessun dato disponibile",
                "infoFiltered": "(filtrati da _MAX_ record totali)",
                "search": "Cerca evento:",
                "paginate": {
                    "first": "Primo",
                    "last": "Ultimo",
                    "next": "Succ",
                    "previous": "Prec"
                }
            },
            "order": [[ 0, "desc" ], [ 1, "desc" ]], // Ordina prima per data, poi per ora (decrescente)
            "pageLength": 5,
            "lengthChange": false,
            "searching": true
        });
    }

    // Inizializza Chart.js se i dati sono disponibili
    <?php if ($result): ?>
    const ctx = document.getElementById('brtChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Colli (Unità)', 'Peso Spedito (kg)'],
                datasets: [{
                    data: [<?php echo $result['colli']; ?>, <?php echo $result['peso_kg']; ?>],
                    backgroundColor: [
                        'rgba(52, 152, 219, 0.85)',
                        'rgba(231, 76, 60, 0.85)'
                    ],
                    borderColor: [
                        '#ffffff',
                        '#ffffff'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                family: 'Inter',
                                size: 12
                            }
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>
});
</script>
</body>
</html>
