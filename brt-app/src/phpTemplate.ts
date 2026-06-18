/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

export const phpTemplateString = `<?php
/**
 * Client di Tracking BRT REST API altamente rifinito
 * Tecnologie: PHP + Bootstrap 5 + Font Awesome 6 + DataTables + Chart.js
 * 
 * Istruzioni:
 * 1. Carica questo file sul tuo spazio FTP (es. rinominandolo in index.php)
 * 2. Compila le credenziali BRT_USERID e BRT_PASSWORD qui sotto o usa l'interfaccia web per testarli.
 * 3. Se lasciati vuoti o impostando "DEMO", lo script entrerà in modalità simulatore per dimostrazione completa.
 */

// --- CONFIGURAZIONE CREDENZIALI API BRT ---
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
    $isRealNumeric = preg_match('/^\d{7,15}$/', $parcelID);
    
    if (empty($userID) || empty($password) || strtolower($userID) === 'demo' || strtolower($password) === 'demo') {
        if ($isRealNumeric) {
            $executionMode = "Tracciatore SOAP Pubblico Reale (Nessuna credenziale REST)";
            $soapRes = get_tracking_by_shipment_id($parcelID);
            if ($soapRes && $soapRes['success']) {
                $result = $soapRes;
            } else {
                $executionMode = "Simulatore (Credenziali REST vuote e ricerca SOAP fallita)";
                $result = generateDemoData($parcelID);
            }
        } else {
            $executionMode = "Simulatore Demonstrativo Offline (Credenziali BRT non inserite)";
            $result = generateDemoData($parcelID);
        }
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        $restParsed = false;
        if (!curl_errno($ch) && $http_code === 200) {
            $data = json_decode($response, true);
            if ($data !== null) {
                $result = parseBrtResponse($data, $parcelID);
                $restParsed = true;
            }
        }
        curl_close($ch);
        
        // Se la chiamata REST ha fallito (o le credenziali sono errate) e il codice è reale, proviamo il SOAP!
        if (!$restParsed && $isRealNumeric) {
            $executionMode = "Real SOAP API via cURL (Fallback dopo KO REST)";
            $soapRes = get_tracking_by_shipment_id($parcelID);
            if ($soapRes && $soapRes['success']) {
                $result = $soapRes;
                $error = null;
            } else {
                $error = "Sia le API REST che il tracciatore SOAP di BRT non sono riusciti a trovare questa spedizione. Verifica il segnacollo inserito.";
            }
        } else if (!$restParsed) {
            $error = "L'API REST BRT ha restituito errore (HTTP Code: $http_code).";
        }
    }
}

/**
 * Funzione di tracciamento SOAP reale suggerita dall'utente (arricchita con tag aggiuntivi per mittente/destinatario/merce)
 */
function get_tracking_by_shipment_id($shipment_id, $lingua_iso639="it") {
    $tracking = array();
    if(empty($shipment_id)) return false;
    $wsdlUrl = 'https://wsr.brt.it:10052/web/BRT_TrackingByBRTshipmentIDService/BRT_TrackingByBRTshipmentID?wdsl';
    $soapRequest = '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:brt="http://brt_trackingbybrtshipmentid.wsbeans.iseries/"><soap:Header/><soap:Body><brt:brt_trackingbybrtshipmentid><arg0><SPEDIZIONE_ANNO>0</SPEDIZIONE_ANNO><SPEDIZIONE_BRT_ID>'.$shipment_id.'</SPEDIZIONE_BRT_ID><LINGUA_ISO639_ALPHA2>'.$lingua_iso639.'</LINGUA_ISO639_ALPHA2></arg0></brt:brt_trackingbybrtshipmentid></soap:Body></soap:Envelope>';

    $ch = curl_init($wsdlUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $soapRequest);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: text/xml; charset=utf-8"));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
   
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    if($response){
        $xml = @simplexml_load_string($response);
        if (!$xml) return false;
        
        $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        
        $stato_spedizione = "";
        $CodeDPD = "";
        $descriptions = [];
        $dates = [];
        $ore = [];
        $filiale_a = [];
        $stati = [];
      
        if($xml->xpath('//STATO_SPED_PARTE1')){
            foreach ($xml->xpath('//STATO_SPED_PARTE1') as $stato_part1){
                if(!empty($stato_part1)) $stato_spedizione = (string)$stato_part1;
            }
        }
        if($xml->xpath('//DESCRIZIONE_STATO_SPED_PARTE1')){
            foreach ($xml->xpath('//DESCRIZIONE_STATO_SPED_PARTE1') as $stato_part2){
                if(!empty($stato_part2)) $stato_spedizione .= " ".(string)$stato_part2;
            }
        }
        if($xml->xpath('//RIFERIMENTO_PARTNER_ESTERO')){
            foreach ($xml->xpath('//RIFERIMENTO_PARTNER_ESTERO') as $rif_esterno){
                if(!empty($rif_esterno)) $CodeDPD = (string)$rif_esterno;
            }
        }
        if($xml->xpath('//DESCRIZIONE')){
            foreach ($xml->xpath('//DESCRIZIONE') as $desc){
                if(!empty($desc)) $descriptions [] = (string)$desc;
            }
        }
        if($xml->xpath('//DATA')){
            foreach ($xml->xpath('//DATA') as $data){
                if(!empty($data)) $dates [] = (string)$data;
            }
        }
        if($xml->xpath('//ORA')){
            foreach ($xml->xpath('//ORA') as $ora){
                if(!empty($ora)) $ore [] = (string)$ora;
            }
        }
        if($xml->xpath('//FILIALE')){
            foreach ($xml->xpath('//FILIALE') as $filiale){
                if(!empty($filiale)) $filiale_a [] = (string)$filiale;
            }
        }
      
        for($i=0;$i<sizeof($descriptions);$i++){
            $stati [] = array(
                "descrizione" => (string)$descriptions[$i],
                "data" => (string)str_replace('.','-', $dates[$i] ?? ''),
                "ora" => (string)($ore[$i] ?? ''),
                "filiale" => (string)($filiale_a[$i] ?? ''),
            );
        }
        
        // Estrai tag destinatario se presenti
        $ragione_sociale = "";
        $indirizzo = "";
        $cap = "";
        $localita = "";
        $sigla_provincia = "";
        $sigla_nazione = "IT";
        $telefono_referente = "";
        
        $destNode = $xml->xpath('//RAGIONE_SOC_DEST');
        if (!empty($destNode)) { $ragione_sociale = (string)$destNode[0]; }
        else {
            $destNodeAlt = $xml->xpath('//RAGIONE_SOCIALE_DEST');
            if (!empty($destNodeAlt)) $ragione_sociale = (string)$destNodeAlt[0];
        }
        
        $vNode = $xml->xpath('//VIA_DEST_RICEV'); if (!empty($vNode)) $indirizzo = (string)$vNode[0];
        $cNode = $xml->xpath('//CAP_DEST_RICEV'); if (!empty($cNode)) $cap = (string)$cNode[0];
        $lNode = $xml->xpath('//LOCALITA_DEST_RICEV'); if (!empty($lNode)) $localita = (string)$lNode[0];
        $pNode = $xml->xpath('//PROVINCIA_DEST_RICEV'); if (!empty($pNode)) $sigla_provincia = (string)$pNode[0];
        $tNode = $xml->xpath('//TELEFONO_DEST'); if (!empty($tNode)) $telefono_referente = (string)$tNode[0];

        $colli = 1;
        $peso_kg = 0.0;
        $colliNode = $xml->xpath('//NUMERO_COLLI'); if (!empty($colliNode)) $colli = (int)$colliNode[0];
        $pesoNode = $xml->xpath('//PESO_SPEDIZIONE'); if (!empty($pesoNode)) $peso_kg = (float)str_replace(',', '.', (string)$pesoNode[0]);

        $data_consegna = "";
        $ora_consegna = "";
        $dcNode = $xml->xpath('//DATA_CONSEGNA_EFFETTIVA'); if (!empty($dcNode)) $data_consegna = (string)$dcNode[0];
        $ocNode = $xml->xpath('//ORA_CONSEGNA_EFFETTIVA'); if (!empty($ocNode)) $ora_consegna = (string)$ocNode[0];
        $fNode = $xml->xpath('//FIRMATARIO'); if (!empty($fNode)) $firmatario = (string)$fNode[0];

        $tracking = array(
            'parcelID' => $shipment_id,
            'success' => count($stati) > 0 || !empty($stato_spedizione),
            'code' => 0,
            'severity' => 'INFO',
            'codeDesc' => 'SOAP OK',
            'message' => 'Tracciato in tempo reale tramite API SOAP di BRT pubblica.',
            'ragione_sociale' => $ragione_sociale,
            'indirizzo' => $indirizzo,
            'cap' => $cap,
            'localita' => $localita,
            'sigla_provincia' => $sigla_provincia,
            'sigla_nazione' => $sigla_nazione,
            'referente_consegna' => '',
            'telefono_referente' => $telefono_referente,
            'colli' => $colli,
            'peso_kg' => $peso_kg,
            'riferimento_mittente_numerico' => 0,
            'riferimento_mittente_alfabetico' => $CodeDPD,
            'data_cons_richiesta' => '',
            'ora_cons_richiesta' => '',
            'tipo_cons_richiesta' => 'Standard',
            'descrizione_cons_richiesta' => '',
            'data_teorica_consegna' => '',
            'ora_teorica_consegna_da' => '',
            'ora_teorica_consegna_a' => '',
            'data_consegna_merce' => $data_consegna,
            'ora_consegna_merce' => $ora_consegna,
            'firmatario_consegna' => $firmatario,
            "stato_sped_parte1" => $stato_spedizione,
            "stato_sped_parte2" => $CodeDPD,
            "descrizione_stato_sped_parte1" => $stato_spedizione,
            "descrizione_stato_sped_parte2" => $CodeDPD,
            "lista_eventi" => $stati
        );
        return $tracking;
    }
    return false;
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
                ['data' => '2026-06-16', 'ora' => '18:12', 'id' => 'GIA', 'descrizione' => 'APERTURA PRATICA DI GIACENZA', 'filiale' => 'FIRENZE CAMPI'],
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
            'peso_kg' => 8.5,
            'riferimento_mittente_numerico' => 193022,
            'riferimento_mittente_alfabetico' => 'ENG-992-A',
            'data_cons_richiesta' => '',
            'ora_cons_richiesta' => '',
            'tipo_cons_richiesta' => '',
            'descrizione_cons_richiesta' => '',
            'data_teorica_consegna' => '2026-06-18',
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
        return [
            'parcelID' => $parcelID,
            'success' => true,
            'code' => 0,
            'severity' => 'INFO',
            'codeDesc' => 'Consegna Avvenuta',
            'message' => 'Spedizione regolarmente recapitata.',
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f6fa; color: #2c3e50; }
        .card-brt { border: none; border-radius: 12px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05); margin-bottom: 24px; background: #ffffff; }
        .header-brt { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: #fff; border-radius: 12px 12px 0 0; padding: 24px; }
        .btn-brt { background-color: #e74c3c; color: white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 500; transition: all 0.2s ease-in-out; }
        .btn-brt:hover { background-color: #c0392b; color: white; transform: translateY(-1px); }
        .badge-status { font-size: 0.95rem; padding: 8px 16px; border-radius: 30px; font-weight: 600; }
        .text-label { font-size: 0.8rem; text-transform: uppercase; font-weight: 600; color: #95a5a6; margin-bottom: 2px; }
        .text-value { font-size: 1rem; font-weight: 500; color: #2c3e50; }
        .timeline-item { padding-left: 20px; border-left: 3px solid #e74c3c; position: relative; padding-bottom: 20px; }
        .timeline-item::after { content: ''; width: 12px; height: 12px; background: #e74c3c; border: 2px solid #fff; border-radius: 50%; position: absolute; left: -8px; top: 4px; }
        .value-highlight { font-size: 1.4rem; font-weight: 700; color: #e74c3c; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row mb-4">
        <div class="col-12 text-center">
            <h1 class="fw-bold text-dark mb-2"><i class="fa-solid fa-truck text-danger"></i> Client BRT API Tracking</h1>
            <p class="text-secondary">Pulsantiera e Dashboard integrata con PHP, Bootstrap, DataTables e Chart.js</p>
        </div>
    </div>

    <?php if (empty($userID) || empty($password)): ?>
        <div class="alert alert-warning card-brt p-4" role="alert">
            <div class="d-flex align-items-center mb-3">
                <i class="fa-solid fa-gears fa-2x text-warning me-3"></i>
                <h5 class="alert-heading m-0 fw-bold">Configurazione Rapida Credenziali</h5>
            </div>
            <p class="mb-3 small">Le credenziali non sono definite nel file. Inseriscile provvisoriamente per testare reale connessione, o lascia vuoto per modalità test offline.</p>
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
                <button class="btn btn-sm btn-outline-danger mt-2 mt-md-0" onclick="window.location.href = window.location.pathname;">Scarta Credenziali</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="card card-brt">
        <div class="card-body p-4">
            <form method="GET" action="">
                <div class="row align-items-end g-3">
                    <div class="col-md-8">
                        <label for="parcelID" class="form-label fw-bold"><i class="fa-solid fa-barcode text-secondary me-2"></i>Inserisci ID Collo / Segnacollo BRT</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                            <input type="text" class="form-control form-control-lg" id="parcelID" name="parcelID" required placeholder="Inserisci il codice segnacollo" value="<?php echo htmlspecialchars($parcelID); ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-brt btn-lg w-100 py-3"><i class="fa-solid fa-magnifying-glass-chart me-2"></i>Trova Spedizione</button>
                    </div>
                </div>
            </form>
            <div class="mt-3">
                <span class="small text-muted font-monospace">Esempi rapidi simulatore:</span>
                <a href="?parcelID=BRT-OK-CONSEGNATO" class="badge bg-light text-dark text-decoration-none border me-1">BRT-OK-CONSEGNATO</a>
                <a href="?parcelID=BRT-IN-TRANSIT" class="badge bg-light text-dark text-decoration-none border me-1">BRT-IN-TRANSIT</a>
                <a href="?parcelID=BRT-GIACENZA" class="badge bg-light text-dark text-decoration-none border me-1">BRT-GIACENZA</a>
            </div>
        </div>
    </div>

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

    <?php if ($result): ?>
        <div class="row mb-3">
            <div class="col-12">
                <div class="alert alert-info py-2 px-3 small border d-flex justify-content-between">
                    <span><strong>Modalità Esecuzione:</strong> <?php echo $executionMode; ?></span>
                    <span><strong>Risposta Server:</strong> Code <?php echo $result['code']; ?></span>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="card card-brt">
                    <div class="header-brt">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h5 class="text-uppercase m-0 opacity-75 small">ID COLLO: <?php echo htmlspecialchars($result['parcelID']); ?></h5>
                                <h2 class="fw-bold m-0 mt-1"><?php echo htmlspecialchars($result['ragione_sociale'] ?: 'Fornitore Non Indicato'); ?></h2>
                            </div>
                            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                                <?php
                                $badgeClass = "bg-success"; $statusIcon = "fa-check-double";
                                if (strpos(strtoupper($result['stato_sped_parte1']), 'GIACENZA') !== false) {
                                    $badgeClass = "bg-warning text-dark"; $statusIcon = "fa-triangle-exclamation";
                                } elseif (strpos(strtoupper($result['stato_sped_parte1']), 'VIAGGIO') !== false || strpos(strtoupper($result['stato_sped_parte1']), 'TRANSITO') !== false) {
                                    $badgeClass = "bg-primary"; $statusIcon = "fa-truck-fast";
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
                                        <div class="text-label">Referente consegna</div>
                                        <div class="text-value"><?php echo htmlspecialchars($result['referente_consegna'] ?: '-'); ?></div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12">
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
                                    <div class="text-value"><?php echo htmlspecialchars($result['riferimento_mittente_numerico'] ?: '-'); ?></div>
                                </div>
                                <div class="mb-3">
                                    <div class="text-label">Riferimento Mittente Alfabetico</div>
                                    <div class="text-value"><?php echo htmlspecialchars($result['riferimento_mittente_alfabetico'] ?: '-'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card card-brt">
                    <div class="card-body p-4">
                        <h5 class="fw-bold text-danger border-bottom pb-3 mb-3"><i class="fa-solid fa-calendar-check me-2"></i>Pianificazione & Orari Consegna</h5>
                        <div class="row g-3">
                            <div class="col-md-6 border-end">
                                <h6 class="fw-bold mb-3 text-secondary">Dettagli Richiesta</h6>
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
                                    <div class="text-label">Tipo Consegna</div>
                                    <div class="text-value"><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($result['tipo_cons_richiesta'] ?: 'Standard'); ?></span></div>
                                </div>
                                <div class="mb-3">
                                    <div class="text-label">Note Richiesta</div>
                                    <div class="text-value text-muted small"><?php echo htmlspecialchars($result['descrizione_cons_richiesta'] ?: '-'); ?></div>
                                </div>
                            </div>
                            
                            <div class="col-md-6 ps-md-4">
                                <h6 class="fw-bold mb-3 text-secondary">Stime e Consegna Effettiva</h6>
                                <div class="mb-3">
                                    <div class="text-label">Data Teorica Consegna</div>
                                    <div class="text-value fw-semibold text-success"><i class="fa-regular fa-calendar-days me-2"></i><?php echo htmlspecialchars($result['data_teorica_consegna'] ?: 'Stima non disponibile'); ?></div>
                                </div>
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <div class="text-label">Ora Da</div>
                                        <div class="text-value"><?php echo htmlspecialchars($result['ora_teorica_consegna_da'] ?: '-'); ?></div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-label">Ora A</div>
                                        <div class="text-value"><?php echo htmlspecialchars($result['ora_teorica_consegna_a'] ?: '-'); ?></div>
                                    </div>
                                </div>
                                <div class="p-2 bg-light rounded border mt-2">
                                    <div class="text-label">Consegna Avvenuta Il</div>
                                    <div class="text-value fw-bold text-danger">
                                        <i class="fa-solid fa-circle-check me-1"></i><?php echo htmlspecialchars($result['data_consegna_merce'] ?: 'In transito...'); ?>
                                        <?php if ($result['ora_consegna_merce']): ?> alle <?php echo htmlspecialchars($result['ora_consegna_merce']); ?><?php endif; ?>
                                    </div>
                                    <div class="text-label mt-1">Firmatario</div>
                                    <div class="text-value small"><?php echo htmlspecialchars($result['firmatario_consegna'] ?: 'In attesa di firma...'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card card-brt">
                    <div class="card-body p-4">
                        <h5 class="fw-bold text-danger border-bottom pb-3 mb-3"><i class="fa-solid fa-list-check me-2"></i>Log Eventi Spedizione (DataTables)</h5>
                        <div class="table-responsive">
                            <table id="eventiBrtTable" class="table table-striped table-hover border align-middle w-100">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Data</th>
                                        <th>Ora</th>
                                        <th>Cod.</th>
                                        <th>Descrizione Evento</th>
                                        <th>Filiale</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($result['lista_eventi'])): ?>
                                        <tr><td colspan="5" class="text-center text-muted">Nessun evento registrato</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($result['lista_eventi'] as $item): ?>
                                            <tr>
                                                <td class="fw-medium"><?php echo htmlspecialchars($item['data']); ?></td>
                                                <td><?php echo htmlspecialchars($item['ora']); ?></td>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($item['id']); ?></span></td>
                                                <td class="fw-semibold text-dark"><?php echo htmlspecialchars($item['descrizione']); ?></td>
                                                <td><?php echo htmlspecialchars($item['filiale']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card card-brt">
                    <div class="card-header bg-danger text-white fw-bold py-3"><i class="fa-solid fa-chart-simple me-2"></i>Ripartizione Colli / Peso</div>
                    <div class="card-body p-4 text-center">
                        <canvas id="brtChart" style="max-height: 240px;"></canvas>
                        <div class="row pt-3 mt-3 border-top">
                            <div class="col-6">
                                <span class="d-block text-muted small font-monospace">Colli</span>
                                <h4 class="fw-bold"><?php echo $result['colli']; ?></h4>
                            </div>
                            <div class="col-6">
                                <span class="d-block text-muted small font-monospace">Peso</span>
                                <h4 class="fw-bold text-danger"><?php echo $result['peso_kg']; ?> kg</h4>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card card-brt">
                    <div class="card-header bg-dark text-white fw-bold py-3"><i class="fa-solid fa-route me-2"></i>Avanzamento</div>
                    <div class="card-body p-4 text-center">
                        <?php
                        $prog = 20; $progText = "Merce Ritirata"; $progColor = "bg-secondary";
                        if (strpos(strtoupper($result['stato_sped_parte1'] ?? ''), 'VIAGGIO') !== false || strpos(strtoupper($result['stato_sped_parte1'] ?? ''), 'TRANSITO') !== false) {
                            $prog = 60; $progColor = "bg-primary"; $progText = "In viaggio";
                        } elseif (strpos(strtoupper($result['stato_sped_parte1'] ?? ''), 'GIACENZA') !== false) {
                            $prog = 50; $progColor = "bg-warning"; $progText = "In Giacenza / Sospesa";
                        } elseif ($result['data_consegna_merce'] !== '') {
                            $prog = 100; $progColor = "bg-success"; $progText = "Consegna Avvenuta";
                        }
                        ?>
                        <h6 class="fw-bold mb-3 text-secondary"><?php echo $progText; ?></h6>
                        <div class="progress mb-4" style="height: 23px; border-radius: 20px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated <?php echo $progColor; ?>" role="progressbar" style="width: <?php echo $prog; ?>%" aria-valuenow="<?php echo $prog; ?>" aria-valuemin="0" aria-valuemax="100"><strong><?php echo $prog; ?>%</strong></div>
                        </div>
                        <div class="text-start ms-2">
                            <div class="timeline-item <?php echo $prog >= 20 ? 'text-dark fw-medium' : 'text-muted'; ?>">Ritiro Merce Regolare</div>
                            <div class="timeline-item <?php echo $prog >= 60 ? 'text-dark fw-medium' : 'text-muted'; ?>">Smistato e in viaggio tra Hub</div>
                            <div class="timeline-item <?php echo $prog >= 100 ? 'text-success fw-bold' : 'text-muted'; ?>">Recapitata e firmata</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function() {
    if ($('#eventiBrtTable').length) {
        $('#eventiBrtTable').DataTable({
            "language": {
                "lengthMenu": "Mostra _MENU_ eventi",
                "zeroRecords": "Nessun evento compatibile",
                "info": "Pagina _PAGE_ di _PAGES_ (Totale _TOTAL_ record)",
                "infoEmpty": "Nessun dato",
                "search": "Cerca:",
                "paginate": { "next": "Succ", "previous": "Prec" }
            },
            "order": [[ 0, "desc" ], [ 1, "desc" ]],
            "pageLength": 5,
            "lengthChange": false
        });
    }

    <?php if ($result): ?>
    const ctx = document.getElementById('brtChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Colli Spediti', 'Peso Totale (kg)'],
                datasets: [{
                    data: [<?php echo $result['colli']; ?>, <?php echo $result['peso_kg']; ?>],
                    backgroundColor: ['rgba(52, 152, 219, 0.85)', 'rgba(231, 76, 60, 0.85)'],
                    borderWidth: 2
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
    }
    <?php endif; ?>
});
</script>
</body>
</html>`;
