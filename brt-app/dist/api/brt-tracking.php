<?php
/**
 * BRT Tracking API Proxy - FTP/PHP Version
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Metodo non consentito']); exit(); }

$body = json_decode(file_get_contents('php://input'), true);
$parcelID   = isset($body['parcelID'])   ? trim($body['parcelID'])   : '';
$userID     = isset($body['userID'])     ? trim($body['userID'])     : '';
$password   = isset($body['password'])   ? trim($body['password'])   : '';
$useSandbox = isset($body['useSandbox']) ? (bool)$body['useSandbox'] : true;

if ($parcelID === '') { http_response_code(403); echo json_encode(['success'=>false,'message'=>"Il codice segnacollo è obbligatorio."]); exit(); }

$clean = $parcelID;
$isNumeric = (bool)preg_match('/^\d{7,15}$/', $clean);
$looksReal = $isNumeric && strpos($clean, '12345') !== 0;

// Include CSV lookup (silenzioso: non blocca in caso di errore)
require_once __DIR__ . '/csv-lookup.php';

// 1. Sandbox/demo → mock diretto (SOAP non restituisce referente_consegna né note_consegna)
if ($useSandbox || !$userID || !$password) {
    $mockResult = mockData($clean);
    $mockResult['fonte_dati'] = 'mock';
    echo json_encode($mockResult);
    exit();
}

// 2. BRT REST API (solo con credenziali reali)
$ch = curl_init('https://api.brt.it/rest/v1/tracking/parcelID/'.urlencode($clean));
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>["userID: $userID","password: $password","Accept: application/json"],CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true]);
$resp = curl_exec($ch); $httpCode = curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
$json = $resp ? json_decode($resp,true) : null;

$hasError = true;
if ($json) { $b=$json['parcelIDResult']??$json['ttParcelIdResponse']??$json; $c=isset($b['executionMessage']['code'])?(int)$b['executionMessage']['code']:(isset($b['code'])?(int)$b['code']:0); $hasError=$c<0; }

// 3. SOAP fallback solo se REST fallisce (con credenziali reali)
if (($httpCode!==200||$hasError||!$json)&&$looksReal) {
    $soap=fetchSoap($clean);
    if ($soap&&$soap['success']&&count($soap['lista_eventi'])>0){
        $soap = enrichWithCsv($soap, $clean);
        echo json_encode($soap);
        exit();
    }
}

if (!$json) { echo json_encode(['success'=>false,'code'=>-1,'severity'=>'ERROR','codeDesc'=>'Connection Failure','message'=>"Impossibile connettersi a BRT (".($err?:"HTTP $httpCode").").",'parcelID'=>$clean,'ragione_sociale'=>'','indirizzo'=>'','cap'=>'','localita'=>'','sigla_provincia'=>'','sigla_nazione'=>'','referente_consegna'=>'','telefono_referente'=>'','note_consegna'=>[],'colli'=>0,'peso_kg'=>0,'riferimento_mittente_numerico'=>0,'riferimento_mittente_alfabetico'=>'','data_cons_richiesta'=>'','ora_cons_richiesta'=>'','tipo_cons_richiesta'=>'','descrizione_cons_richiesta'=>'','data_teorica_consegna'=>'','ora_teorica_consegna_da'=>'','ora_teorica_consegna_a'=>'','data_consegna_merce'=>'','ora_consegna_merce'=>'','firmatario_consegna'=>'','lista_eventi'=>[]]); exit(); }

$restResult = parseRest($json, $clean);
$restResult = enrichWithCsv($restResult, $clean);
echo json_encode($restResult);

// ── FUNZIONI ──────────────────────────────────────────────────────────────────

/**
 * Arricchisce il risultato con i dati CSV SFTP se disponibili.
 * Se trova il collo nel CSV, sovrascrive i campi destinatario (non mascherati GDPR)
 * e aggiunge numero_ordine, note_consegna da CSV, fonte_dati.
 */
function enrichWithCsv(array $result, string $parcelId): array {
    try {
        $csvData = lookupParcelInCsv($parcelId);
    } catch (Throwable $e) {
        error_log("BRT CSV enrichWithCsv exception: " . $e->getMessage());
        $csvData = null;
    }

    if ($csvData === null) {
        // Nessun dato CSV: fonte solo API
        $result['fonte_dati'] = 'api';
        return $result;
    }

    // Sovrascrivi i campi destinatario con dati completi dal CSV
    if ($csvData['ragione_sociale'] !== '') $result['ragione_sociale'] = $csvData['ragione_sociale'];
    if ($csvData['indirizzo']       !== '') $result['indirizzo']       = $csvData['indirizzo'];
    if ($csvData['cap']             !== '') $result['cap']             = $csvData['cap'];
    if ($csvData['localita']        !== '') $result['localita']        = $csvData['localita'];
    if ($csvData['sigla_provincia'] !== '') $result['sigla_provincia'] = $csvData['sigla_provincia'];

    // Aggiungi numero ordine
    if (!empty($csvData['numero_ordine'])) {
        $result['numero_ordine'] = $csvData['numero_ordine'];
    }

    // Aggiungi/integra note consegna CSV
    $notaCSV = trim($csvData['note_consegna_csv'] ?? '');
    if ($notaCSV !== '') {
        $existingNotes = $result['note_consegna'] ?? [];
        if (!in_array($notaCSV, $existingNotes)) {
            $existingNotes[] = $notaCSV;
        }
        $result['note_consegna'] = $existingNotes;
    }

    $result['fonte_dati'] = 'csv+api';
    return $result;
}

function fetchSoap(string $id): ?array {
    $xml='<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:brt="http://brt_trackingbybrtshipmentid.wsbeans.iseries/"><soapenv:Header/><soapenv:Body><brt:brt_trackingbybrtshipmentid><arg0><SPEDIZIONE_ANNO>0</SPEDIZIONE_ANNO><SPEDIZIONE_BRT_ID>'.htmlspecialchars($id,ENT_XML1).'</SPEDIZIONE_BRT_ID><LINGUA_ISO639_ALPHA2>it</LINGUA_ISO639_ALPHA2></arg0></brt:brt_trackingbybrtshipmentid></soapenv:Body></soapenv:Envelope>';
    $ch=curl_init('https://wsr.brt.it:10052/web/BRT_TrackingByBRTshipmentIDService/BRT_TrackingByBRTshipmentID?wdsl');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$xml,CURLOPT_HTTPHEADER=>['Content-Type: text/xml; charset=utf-8'],CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true]);
    $r=curl_exec($ch); curl_close($ch);
    if(!$r)return null;
    return parseSoap($r,$id);
}

function pt(string $t,string $x):string{preg_match("/<{$t}[^>]*>([^<]*)<\/{$t}>/i",$x,$m);return isset($m[1])?trim($m[1]):'';}
function pts(string $t,string $x):array{preg_match_all("/<{$t}[^>]*>([^<]*)<\/{$t}>/i",$x,$m);return array_map('trim',$m[1]??[]);}

function parseSoap(string $x,string $id):array{
    $s1=pt('STATO_SPED_PARTE1',$x)?:pt('STATO_SPED_PARTE_1',$x);
    $ds=pt('DESCRIZIONE_STATO_SPED_PARTE1',$x)?:pt('DESCRIZIONE_STATO_SPED_PARTE_1',$x);
    $descs=pts('DESCRIZIONE',$x);$dates=pts('DATA',$x);$times=pts('ORA',$x);$branches=pts('FILIALE',$x);$ids=pts('ID',$x)?:pts('CODICE',$x);
    $ev=[];$max=max(count($descs),count($dates),count($times),count($branches));
    for($i=0;$i<$max;$i++)$ev[]=['data'=>str_replace('.','-',$dates[$i]??''),'ora'=>$times[$i]??'','id'=>$ids[$i]??'EVT','descrizione'=>$descs[$i]??'','filiale'=>$branches[$i]??''];
    $ok=$max>0||$s1!=='';
    return ['parcelID'=>$id,'success'=>$ok,'code'=>$ok?0:-1,'severity'=>'INFO','codeDesc'=>$ok?'SOAP Success':'No tracking','message'=>$ds?:($ok?'Tracciato via SOAP BRT.':'Non trovato.'),'ragione_sociale'=>pt('RAGIONE_SOC_DEST',$x)?:pt('RAGIONE_SOCIALE',$x),'indirizzo'=>pt('VIA_DEST_RICEV',$x)?:pt('INDIRIZZO_DEST',$x),'cap'=>pt('CAP_DEST_RICEV',$x)?:pt('CAP_DEST',$x),'localita'=>pt('LOCALITA_DEST_RICEV',$x)?:pt('LOCALITA_DEST',$x),'sigla_provincia'=>pt('PROVINCIA_DEST_RICEV',$x)?:pt('PROVINCIA_DEST',$x),'sigla_nazione'=>pt('NAZIONE_DEST_RICEV',$x)?:'IT','referente_consegna'=>pt('REFERENTE_CONSEGNA',$x),'telefono_referente'=>pt('TELEFONO_DEST',$x)?:pt('TELEFONO_REFERENTE',$x),'note_consegna'=>array_values(array_filter(pts('NOTA',$x))),'ragione_sociale_mittente'=>pt('RAGIONE_SOC_MITT',$x),'indirizzo_mittente'=>pt('VIA_MITT',$x),'cap_mittente'=>pt('CAP_MITT',$x),'localita_mittente'=>pt('LOCALITA_MITT',$x),'sigla_provincia_mittente'=>pt('PROV_MITT',$x),'sigla_nazione_mittente'=>'IT','telefono_mittente'=>pt('TELEFONO_MITT',$x),'colli'=>(int)(pt('NUMERO_COLLI',$x)?:pt('COLLI',$x)?:1),'peso_kg'=>(float)str_replace(',','.',pt('PESO_SPEDIZIONE',$x)?:pt('PESO',$x)?:'0'),'riferimento_mittente_numerico'=>(int)(pt('RIF_MITTENTE_NUM',$x)?:0),'riferimento_mittente_alfabetico'=>pt('RIF_MITTENTE_ALF',$x)?:pt('RIFERIMENTO_PARTNER_ESTERO',$x),'data_cons_richiesta'=>'','ora_cons_richiesta'=>'','tipo_cons_richiesta'=>'','descrizione_cons_richiesta'=>'','data_teorica_consegna'=>pt('DATA_TEORICA_CONSEGNA',$x),'ora_teorica_consegna_da'=>pt('ORA_CONSEGNA_DA',$x),'ora_teorica_consegna_a'=>pt('ORA_CONSEGNA_A',$x),'data_consegna_merce'=>pt('DATA_CONSEGNA_EFFETTIVA',$x)?:($descs[0]==='CONSEGNATA'?($dates[0]??''):''),'ora_consegna_merce'=>pt('ORA_CONSEGNA_EFFETTIVA',$x)?:($descs[0]==='CONSEGNATA'?($times[0]??''):''),'firmatario_consegna'=>pt('FIRMATARIO',$x)?:pt('FIRMATARIO_CONSEGNA',$x),'lista_eventi'=>$ev,'stato_sped_parte1'=>$s1,'stato_sped_parte2'=>pt('STATO_SPED_PARTE2',$x),'descrizione_stato_sped_parte1'=>$ds,'descrizione_stato_sped_parte2'=>pt('DESCRIZIONE_STATO_SPED_PARTE2',$x)];
}

function parseRest(array $data,string $id):array{
    $rb=$data['parcelIDResult']??$data['ttParcelIdResponse']??$data;
    $b=$rb['bolla']??$rb;
    $code=isset($rb['executionMessage']['code'])?(int)$rb['executionMessage']['code']:(isset($rb['code'])?(int)$rb['code']:0);
    $dest=$b['destinatario']??$rb['destinatario']??[];
    $mit=$b['mittente']??$rb['mittente']??[];
    $m=$b['merce']??$rb['merce']??[];
    $r=$b['riferimenti']??$rb['riferimenti']??[];
    $d=$b['dati_consegna']??$rb['dati_consegna']??[];
    $rawEv=$b['lista_eventi']??$rb['lista_eventi']??[];if(!is_array($rawEv))$rawEv=$rawEv?[$rawEv]:[];
    $ev=[];foreach($rawEv as $item){$e=$item['evento']??$item;$ev[]=['data'=>$e['data']??'','ora'=>$e['ora']??'','id'=>$e['id']??'','descrizione'=>$e['descrizione']??'','filiale'=>$e['filiale']??''];}
    $rawNotes=$b['lista_note']??$rb['lista_note']??[];if(!is_array($rawNotes))$rawNotes=$rawNotes?[$rawNotes]:[];
    $note_consegna=array_values(array_filter(array_map(fn($n)=>trim($n['nota']['descrizione']??$n['descrizione']??''),$rawNotes)));
    return ['parcelID'=>$id,'success'=>$code>=0,'code'=>$code,'severity'=>$rb['severity']??'INFO','codeDesc'=>$rb['codeDesc']??'','message'=>$rb['message']??'','ragione_sociale'=>$dest['ragione_sociale']??'','indirizzo'=>$dest['indirizzo']??'','cap'=>$dest['cap']??'','localita'=>$dest['localita']??'','sigla_provincia'=>$dest['sigla_provincia']??'','sigla_nazione'=>$dest['sigla_nazione']??'IT','referente_consegna'=>$dest['referente_consegna']??'','telefono_referente'=>$dest['telefono_referente']??'','note_consegna'=>$note_consegna,'ragione_sociale_mittente'=>$mit['ragione_sociale']??'','indirizzo_mittente'=>$mit['indirizzo']??'','cap_mittente'=>$mit['cap']??'','localita_mittente'=>$mit['localita']??'','sigla_provincia_mittente'=>$mit['sigla_provincia']??'','sigla_nazione_mittente'=>$mit['sigla_nazione']??'IT','telefono_mittente'=>$mit['telefono']??'','colli'=>isset($m['colli'])?(int)$m['colli']:0,'peso_kg'=>isset($m['peso_kg'])?(float)$m['peso_kg']:0.0,'riferimento_mittente_numerico'=>isset($r['riferimento_mittente_numerico'])?(int)$r['riferimento_mittente_numerico']:0,'riferimento_mittente_alfabetico'=>$r['riferimento_mittente_alfabetico']??'','data_cons_richiesta'=>$d['data_cons_richiesta']??'','ora_cons_richiesta'=>$d['ora_cons_richiesta']??'','tipo_cons_richiesta'=>$d['tipo_cons_richiesta']??'','descrizione_cons_richiesta'=>$d['descrizione_cons_richiesta']??'','data_teorica_consegna'=>$d['data_teorica_consegna']??'','ora_teorica_consegna_da'=>$d['ora_teorica_consegna_da']??'','ora_teorica_consegna_a'=>$d['ora_teorica_consegna_a']??'','data_consegna_merce'=>$d['data_consegna_merce']??'','ora_consegna_merce'=>$d['ora_consegna_merce']??'','firmatario_consegna'=>$d['firmatario_consegna']??'','lista_eventi'=>$ev,'stato_sped_parte1'=>$rb['stato_sped_parte1']??$b['stato_sped_parte1']??'','stato_sped_parte2'=>$rb['stato_sped_parte2']??$b['stato_sped_parte2']??'','descrizione_stato_sped_parte1'=>$rb['descrizione_stato_sped_parte1']??$b['descrizione_stato_sped_parte1']??'','descrizione_stato_sped_parte2'=>$rb['descrizione_stato_sped_parte2']??$b['descrizione_stato_sped_parte2']??'','originalPayload'=>$data];
}

function mockData(string $id):array{
    $n=strtoupper(trim($id));
    $mock=['08459100301718'=>['success'=>true,'code'=>0,'severity'=>'INFO','codeDesc'=>'Spedizione consegnata con successo','message'=>'La spedizione è stata consegnata.','ragione_sociale'=>'ABATE GIORGIO','indirizzo'=>'VIA FRANCESCO REDI 58','cap'=>'93012','localita'=>'GELA','sigla_provincia'=>'CL','sigla_nazione'=>'IT','referente_consegna'=>'ABATE GIORGIO','telefono_referente'=>'+393495806585','note_consegna'=>['Consegnare al piano terra','Chiamare prima della consegna'],'ragione_sociale_mittente'=>'ARLIA SRL','indirizzo_mittente'=>'VIA FRAILLITI SNC','cap_mittente'=>'87030','localita_mittente'=>'LONGOBARDI','sigla_provincia_mittente'=>'CS','sigla_nazione_mittente'=>'IT','telefono_mittente'=>'+39098278131','colli'=>1,'peso_kg'=>0,'riferimento_mittente_numerico'=>88151,'riferimento_mittente_alfabetico'=>'brt-marco2','data_cons_richiesta'=>'16.06.2026','ora_cons_richiesta'=>'Qualsiasi ora','tipo_cons_richiesta'=>'Standard','descrizione_cons_richiesta'=>'Nessun vincolo inserito','data_teorica_consegna'=>'16.06.2026','ora_teorica_consegna_da'=>'08:30','ora_teorica_consegna_a'=>'18:30','data_consegna_merce'=>'16.06.2026','ora_consegna_merce'=>'15:40','firmatario_consegna'=>'ABATE GIORGIO','stato_sped_parte1'=>'CONSEGNATA','stato_sped_parte2'=>'OK','descrizione_stato_sped_parte1'=>'Spedizione consegnata con successo','descrizione_stato_sped_parte2'=>'Consegnata','lista_eventi'=>[['data'=>'16.06.2026','ora'=>'18:47:25','id'=>'POD','descrizione'=>'Abbiamo ricevuto la prova di consegna','filiale'=>''],['data'=>'16.06.2026','ora'=>'15:40:10','id'=>'DEL','descrizione'=>'La spedizione è stata consegnata','filiale'=>'Caltanissetta, IT'],['data'=>'16.06.2026','ora'=>'10:39:59','id'=>'O4D','descrizione'=>'La spedizione è in consegna','filiale'=>'Caltanissetta, IT'],['data'=>'16.06.2026','ora'=>'08:32:45','id'=>'ARR','descrizione'=>'La spedizione è presso la filiale di consegna','filiale'=>'Caltanissetta, IT']]]];
    if(isset($mock[$n]))return array_merge(['parcelID'=>$id],$mock[$n]);
    $del=str_starts_with($n,'BRT')||(strlen($n)%2===0);
    $tr=str_contains($n,'TR')||(strlen($n)%3===1);
    if($del)return['parcelID'=>$id,'success'=>true,'code'=>0,'severity'=>'INFO','codeDesc'=>'Esito Positivo','message'=>'Spedizione recapitata regolarmente.','ragione_sociale'=>"Ditta Automatica SpA ($id)",'indirizzo'=>'Viale dei Ciliegi, 204','cap'=>'00185','localita'=>'Roma','sigla_provincia'=>'RM','sigla_nazione'=>'IT','referente_consegna'=>'Dott. Giovanni Verga','telefono_referente'=>'+39 06 11223344','note_consegna'=>['Consegnare in portineria'],'colli'=>2,'peso_kg'=>14.8,'riferimento_mittente_numerico'=>554321,'riferimento_mittente_alfabetico'=>'DYN-'.substr($n,-4),'data_cons_richiesta'=>'2026-06-16','ora_cons_richiesta'=>'10:00','tipo_cons_richiesta'=>'standard','descrizione_cons_richiesta'=>'Suonare al civico 10 se cancello chiuso','data_teorica_consegna'=>'2026-06-17','ora_teorica_consegna_da'=>'10:00','ora_teorica_consegna_a'=>'12:00','data_consegna_merce'=>'2026-06-17','ora_consegna_merce'=>'11:22','firmatario_consegna'=>'G. Verga (Portineria)','stato_sped_parte1'=>'CONSEGNATA','stato_sped_parte2'=>'OK','descrizione_stato_sped_parte1'=>'Consegnata','descrizione_stato_sped_parte2'=>'Consegna completata','lista_eventi'=>[['data'=>'2026-06-17','ora'=>'11:22','id'=>'DEL','descrizione'=>'MERCE CONSEGNATA CON SUCCESSO','filiale'=>'ROMA LAURENTINA'],['data'=>'2026-06-17','ora'=>'07:55','id'=>'O4D','descrizione'=>'COLLI IN CONSEGNA CON AUTISTA','filiale'=>'ROMA LAURENTINA'],['data'=>'2026-06-17','ora'=>'02:14','id'=>'ARR','descrizione'=>'ARRIVATA PRESSO FILIALE DI ARRIVO','filiale'=>'ROMA LAURENTINA'],['data'=>'2026-06-16','ora'=>'21:30','id'=>'HUB','descrizione'=>'IN VIAGGIO TRA CENTRI OPERATIVI','filiale'=>'MILANO HUB'],['data'=>'2026-06-16','ora'=>'18:05','id'=>'PUG','descrizione'=>'ACCETTATA E DOCUMENTATA','filiale'=>'MILANO BOVISA']]];
    if($tr)return['parcelID'=>$id,'success'=>true,'code'=>0,'severity'=>'INFO','codeDesc'=>'In Transito','message'=>'In transito.','ragione_sociale'=>"Studio Paladini ($id)",'indirizzo'=>'Piazza Garibaldi, 12','cap'=>'70122','localita'=>'Bari','sigla_provincia'=>'BA','sigla_nazione'=>'IT','referente_consegna'=>'Ing. Paola Paladini','telefono_referente'=>'+39 080 334455','note_consegna'=>[],'colli'=>1,'peso_kg'=>3.1,'riferimento_mittente_numerico'=>981240,'riferimento_mittente_alfabetico'=>'BRT-SHIP-'.substr($n,-3),'data_cons_richiesta'=>'','ora_cons_richiesta'=>'','tipo_cons_richiesta'=>'standard','descrizione_cons_richiesta'=>'','data_teorica_consegna'=>'2026-06-18','ora_teorica_consegna_da'=>'09:00','ora_teorica_consegna_a'=>'18:00','data_consegna_merce'=>'','ora_consegna_merce'=>'','firmatario_consegna'=>'','stato_sped_parte1'=>'IN VIAGGIO','stato_sped_parte2'=>'IN ORARIO','descrizione_stato_sped_parte1'=>'Spedizione in viaggio','descrizione_stato_sped_parte2'=>'Regolare','lista_eventi'=>[['data'=>'2026-06-17','ora'=>'04:12','id'=>'HUB','descrizione'=>'IN COMMUTAZIONE HUB LOGISTICO','filiale'=>'ANCONA HUB'],['data'=>'2026-06-16','ora'=>'19:00','id'=>'DEP','descrizione'=>'PARTENZA DA FILIALE','filiale'=>'BOLOGNA ROVERI'],['data'=>'2026-06-16','ora'=>'14:22','id'=>'PUG','descrizione'=>'CARICATO ALLA PARTENZA','filiale'=>'BOLOGNA ROVERI']]];
    return['parcelID'=>$id,'success'=>false,'code'=>-10,'severity'=>'ERROR','codeDesc'=>'Non Trovato','message'=>"Il segnacollo $id non è stato trovato.",'ragione_sociale'=>'','indirizzo'=>'','cap'=>'','localita'=>'','sigla_provincia'=>'','sigla_nazione'=>'','referente_consegna'=>'','telefono_referente'=>'','note_consegna'=>[],'colli'=>0,'peso_kg'=>0,'riferimento_mittente_numerico'=>0,'riferimento_mittente_alfabetico'=>'','data_cons_richiesta'=>'','ora_cons_richiesta'=>'','tipo_cons_richiesta'=>'','descrizione_cons_richiesta'=>'','data_teorica_consegna'=>'','ora_teorica_consegna_da'=>'','ora_teorica_consegna_a'=>'','data_consegna_merce'=>'','ora_consegna_merce'=>'','firmatario_consegna'=>'','lista_eventi'=>[]];
}
