<?php
declare(strict_types=1);

session_start();
$config = require __DIR__ . '/config.php';
require __DIR__ . '/src/XlsReader.php';
require __DIR__ . '/src/EbayClient.php';
require __DIR__ . '/src/EanStatus.php';
require __DIR__ . '/src/JobStore.php';

if (!is_dir($config['data_dir'])) @mkdir($config['data_dir'], 0700, true);
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function settingsPath(array $config): string { return rtrim((string)$config['data_dir'], '/') . '/settings.json'; }
function loadSettings(array $config): array {
    $p = settingsPath($config);
    if (!is_file($p)) return [];
    $v = json_decode((string)file_get_contents($p), true);
    return is_array($v) ? $v : [];
}
function csrf(): void {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Sessione scaduta. Ricarica la pagina.');
}
function validEan13(string $ean): bool {
    if (!preg_match('/^\d{13}$/', $ean)) return false;
    $sum = 0;
    for ($i=0;$i<12;$i++) $sum += ((int)$ean[$i]) * (($i % 2) ? 3 : 1);
    return ((10 - ($sum % 10)) % 10) === (int)$ean[12];
}
function labelFor(string $status): string {
    return [
        'pending'=>'Da controllare','ready'=>'EAN mancante','present'=>'EAN già presente','conflict'=>'EAN diverso già presente',
        'invalid'=>'Riga non valida','error'=>'Errore','updated'=>'EAN inserito','partial'=>'Solo specifiche oggetto'
    ][$status] ?? $status;
}
/** EAN letto su eBay: l'identificatore di prodotto, o in mancanza la specifica oggetto. */
function shownEan(array $row): string {
    $ean = trim((string)($row['remote']['existing_ean'] ?? ''));
    return $ean !== '' ? $ean : trim((string)($row['remote']['specific_ean'] ?? ''));
}
/**
 * Dove eBay conserva l'EAN letto. "Identificatore prodotto" è il solo campo che
 * compare nella colonna P:EAN dei report venditore scaricabili da eBay.
 */
function sourceLabel(string $source): string {
    return [
        'product'=>'Identificatore prodotto (P:EAN)','specific'=>'Solo specifiche oggetto',
        'variation'=>'Identificatore variante','inventory'=>'Inventory API'
    ][$source] ?? '';
}
function jobPayload(array $job): array {
    $counts=[];
    foreach($job['rows'] as &$r){
        $r['status_label']=labelFor((string)$r['status']);
        $r['source_label']=sourceLabel((string)($r['remote']['ean_source']??''));
        $r['shown_ean']=shownEan($r);
        $counts[$r['status']]=($counts[$r['status']]??0)+1;
    }
    unset($r);
    return ['ok'=>true,'total'=>count($job['rows']),'counts'=>$counts,'rows'=>$job['rows']];
}
function jsonOut(array $data, int $code=200): never {
    http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf();
    if (hash_equals((string)$config['app_password'], (string)($_POST['password'] ?? ''))) { $_SESSION['auth']=true; header('Location: index.php'); exit; }
    $loginError='Password errata.';
}
if ($action === 'logout') { session_destroy(); header('Location: index.php'); exit; }

if (empty($_SESSION['auth'])) {
?><!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>eBay EAN13 Importer</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="wrap"><div class="card" style="max-width:480px;margin:90px auto"><h1>eBay EAN13 Importer</h1><p class="muted">Accesso amministratore</p><?php if(!empty($loginError)):?><div class="alert err"><?=h($loginError)?></div><?php endif?><form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><label>Password</label><input type="password" name="password" required autofocus><p><button class="btn">Accedi</button></p></form></div></main></body></html><?php exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_settings') {
        csrf();
        $s = [
            'client_id'=>trim((string)$_POST['client_id']), 'client_secret'=>trim((string)$_POST['client_secret']),
            'refresh_token'=>trim((string)$_POST['refresh_token']), 'ru_name'=>trim((string)$_POST['ru_name']), 'scope'=>trim((string)$_POST['scope'])
        ];
        foreach(['client_id','client_secret','refresh_token'] as $k) if($s[$k]==='') throw new RuntimeException('Compila tutte le credenziali obbligatorie.');
        if(file_put_contents(settingsPath($config), json_encode($s,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)===false) throw new RuntimeException('Impossibile salvare le impostazioni.');
        @chmod(settingsPath($config),0600);
        @unlink(rtrim((string)$config['data_dir'],'/').'/token.json');
        $notice='Credenziali salvate.';
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test_connection') {
        csrf(); $client=new EbayClient(loadSettings($config),$config); $who=$client->testConnection(); $notice='Collegamento riuscito: '.$who['user'];
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
        csrf();
        if(!isset($_FILES['xls'])||$_FILES['xls']['error']!==UPLOAD_ERR_OK) throw new RuntimeException('Caricamento XLS non riuscito.');
        $f=$_FILES['xls'];
        if((int)$f['size']>(int)$config['max_upload_bytes']) throw new RuntimeException('File troppo grande. Massimo 20 MB.');
        if(strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION))!== 'xls') throw new RuntimeException('È accettato solo il formato .xls, non .xlsx.');
        $sheet=(new XlsReader())->readFirstSheet((string)$f['tmp_name']);
        if(!$sheet) throw new RuntimeException('Il primo foglio è vuoto.');
        $header=array_map(fn($v)=>strtoupper(trim((string)$v)),$sheet[min(array_keys($sheet))]);
        if(($header[0]??'')!=='SKU'||!in_array(($header[1]??''),['ITEM ID','ITEMID','ITEM_ID'],true)||($header[2]??'')!=='EAN13') throw new RuntimeException('Intestazioni richieste: SKU | ITEM ID | EAN13.');
        $rows=[];$first=min(array_keys($sheet));$seen=[];
        foreach($sheet as $excelRow=>$cols){
            if($excelRow===$first) continue;
            $sku=trim((string)($cols[0]??''));$item=preg_replace('/\D+/','',(string)($cols[1]??''));$ean=preg_replace('/\s+/','',(string)($cols[2]??''));
            if($sku===''&&$item===''&&$ean==='') continue;
            $status='pending';$message='';
            if($sku===''||$item===''||$ean===''){$status='invalid';$message='SKU, ITEM ID ed EAN13 sono obbligatori.';}
            elseif(!preg_match('/^\d{9,19}$/',$item)){$status='invalid';$message='ITEM ID non valido.';}
            elseif(!validEan13($ean)){$status='invalid';$message='EAN13 non valido o checksum errato.';}
            $key=$item.'|'.$sku;
            if(isset($seen[$key])){$status='invalid';$message=$seen[$key]!==$ean?'Duplicato con EAN differente nel file.':'Riga duplicata nel file.';} else {$seen[$key]=$ean;}
            $rows[]=['excel_row'=>$excelRow,'sku'=>$sku,'item_id'=>$item,'ean'=>$ean,'status'=>$status,'message'=>$message,'remote'=>null];
        }
        if(!$rows) throw new RuntimeException('Nessuna riga prodotto trovata.');
        $jobId=(new JobStore((string)$config['data_dir']))->create($rows); header('Location: index.php?job='.$jobId); exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action,['check_batch','update_batch'],true)) {
        csrf();
        $store=new JobStore((string)$config['data_dir']);$job=$store->load((string)($_POST['job']??''));$client=new EbayClient(loadSettings($config),$config);
        if($action==='check_batch'){
            $itemIds=[];
            foreach($job['rows'] as $r) if($r['status']==='pending'&&!in_array($r['item_id'],$itemIds,true)){$itemIds[]=$r['item_id'];if(count($itemIds)>=3)break;}
            foreach($itemIds as $itemId){
                foreach($job['rows'] as &$r){
                    if($r['status']!=='pending'||$r['item_id']!==$itemId)continue;
                    try{$remote=$client->inspectItem($r['item_id'],$r['sku']);$r['remote']=$remote;
                        [$r['status'],$r['message']]=EanStatus::classify($r['ean'],(string)$remote['existing_ean'],(string)($remote['specific_ean']??''));
                    }catch(Throwable $e){$r['status']='error';$r['message']=$e->getMessage();}
                }unset($r);
            }
            $store->write($job['id'],$job);$out=jobPayload($job);$out['done']=!array_filter($job['rows'],fn($r)=>$r['status']==='pending');jsonOut($out);
        }
        if(array_filter($job['rows'],fn($r)=>$r['status']==='pending')) throw new RuntimeException('Completa prima il controllo eBay.');
        $target=null;
        foreach($job['rows'] as $r) if($r['status']==='ready'){$target=$r['item_id'];break;}
        if($target!==null){
            $idx=[];$ready=[];foreach($job['rows'] as $i=>$r) if($r['status']==='ready'&&$r['item_id']===$target){$idx[]=$i;$ready[]=$r;}
            try{
                $types=array_unique(array_map(fn($r)=>$r['remote']['type']??'', $ready));
                if(count($types)!==1) throw new RuntimeException('Tipologia inserzione incoerente.');
                // Le inserzioni create o migrate con Inventory API non possono
                // essere revisionate con la Trading API. Rileviamo il modello
                // tramite SKU e usiamo esclusivamente l'API proprietaria.
                $inventory=[];$inventoryCount=0;
                foreach($ready as $k=>$rr){
                    $remoteSku=trim((string)($rr['remote']['remote_sku']??''));
                    $inventory[$k]=$remoteSku===$rr['sku']?$client->getInventoryItem($rr['sku']):null;
                    if(is_array($inventory[$k]))$inventoryCount++;
                }
                if($inventoryCount>0 && $inventoryCount!==count($ready)) throw new RuntimeException('Inserzione con gestione API incoerente: aggiornamento bloccato.');

                if($inventoryCount===count($ready)){
                    foreach($ready as $k=>$rr){
                        $verified=$client->updateInventoryEan($rr['sku'],$rr['ean'],$inventory[$k]);
                        $i=$idx[$k];
                        $job['rows'][$i]['status']='updated';
                        $job['rows'][$i]['remote']['existing_ean']=$client->inventoryEan($verified);
                        $job['rows'][$i]['remote']['ean_source']='inventory';
                        $job['rows'][$i]['message']='EAN13 inserito e verificato tramite Inventory API eBay.';
                    }
                }else{
                    $revisionWarnings=[];
                    if($types[0]==='single'){
                        if(count($ready)!==1)throw new RuntimeException('Più righe per un’inserzione senza varianti.');
                        // Rilegge le specifiche oggetto subito prima della revisione:
                        // la revisione sostituisce l'intero blocco ItemSpecifics, quindi
                        // vanno rimandate tutte insieme alla nuova coppia EAN.
                        $fresh=$client->inspectItem($target,$ready[0]['sku'],true,true);
                        if(($fresh['type']??'')!=='single')throw new RuntimeException('La struttura dell’inserzione eBay è cambiata.');
                        $freshEan=(string)($fresh['existing_ean']??'');$freshSpec=(string)($fresh['specific_ean']??'');
                        if(!EbayClient::eanMissing($freshEan)&&$freshEan!==$ready[0]['ean'])throw new RuntimeException('EAN eBay modificato dopo il controllo: aggiornamento bloccato.');
                        if(!EbayClient::eanMissing($freshSpec)&&$freshSpec!==$ready[0]['ean'])throw new RuntimeException('Specifica oggetto EAN modificata dopo il controllo: aggiornamento bloccato.');
                        $revisionWarnings=$client->reviseSingle($target,$ready[0]['ean'],$fresh['revision_data']??[]);
                    }
                    else{
                        // Rilegge le varianti subito prima della scrittura: prezzo e quantità
                        // inviati a eBay devono essere quelli correnti, non quelli dell'anteprima.
                        foreach($ready as &$rr){
                            $fresh=$client->inspectItem($target,$rr['sku']);
                            if(($fresh['type']??'')!=='variation') throw new RuntimeException('La struttura dell’inserzione eBay è cambiata.');
                            $freshEan=(string)($fresh['existing_ean']??'');
                            if(!EbayClient::eanMissing($freshEan) && $freshEan!==$rr['ean']) throw new RuntimeException('EAN eBay modificato dopo il controllo: aggiornamento bloccato.');
                            $rr['remote']=$fresh;
                        }unset($rr);
                        $client->reviseVariations($target,$ready);
                    }
                    foreach($idx as $i){
                        try{$confirmed=$client->confirmEan($job['rows'][$i]['item_id'],$job['rows'][$i]['sku'],$job['rows'][$i]['ean']);}
                        catch(Throwable $confirmError){
                            $extra=$revisionWarnings?' Avvisi eBay: '.implode(' | ',$revisionWarnings):'';
                            throw new RuntimeException($confirmError->getMessage().$extra);
                        }
                        $warn=$revisionWarnings?' Avvisi eBay: '.implode(' | ',$revisionWarnings):'';
                        $job['rows'][$i]['remote']=$confirmed;
                        if(($confirmed['ean_confirmed']??'')==='specific'){
                            // eBay ha accettato la specifica oggetto ma non l'identificatore
                            // di prodotto: nei report venditore la colonna P:EAN resta vuota.
                            $job['rows'][$i]['status']='partial';
                            $job['rows'][$i]['message']='EAN13 salvato solo nelle specifiche oggetto: eBay non ha registrato l’identificatore di prodotto, nel report eBay la colonna P:EAN resterà vuota. Verifica che la categoria accetti il codice a barre.'.$warn;
                        }else{
                            $job['rows'][$i]['status']='updated';
                            $job['rows'][$i]['message']='EAN13 inserito e verificato tramite Trading API eBay.';
                        }
                    }
                }
            }catch(Throwable $e){foreach($idx as $i){$job['rows'][$i]['status']='error';$job['rows'][$i]['message']=$e->getMessage();}}
            $store->write($job['id'],$job);
        }
        $out=jobPayload($job);$out['done']=!array_filter($job['rows'],fn($r)=>$r['status']==='ready');jsonOut($out);
    }
    if ($action==='report' && isset($_GET['job'])) {
        $job=(new JobStore((string)$config['data_dir']))->load((string)$_GET['job']);
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="report_ebay_ean13.csv"');
        $o=fopen('php://output','w');fwrite($o,"\xEF\xBB\xBF");fputcsv($o,['RIGA','SKU','ITEM ID','EAN13 FILE','EAN EBAY','FONTE EAN EBAY','EAN SPECIFICHE OGGETTO','STATO','MESSAGGIO'],';');
        foreach($job['rows'] as $r)fputcsv($o,[$r['excel_row'],$r['sku'],$r['item_id'],$r['ean'],shownEan($r),sourceLabel((string)($r['remote']['ean_source']??'')),$r['remote']['specific_ean']??'',labelFor($r['status']),$r['message']],';');fclose($o);exit;
    }
} catch (Throwable $e) {
    if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['check_batch','update_batch'],true)) jsonOut(['ok'=>false,'error'=>$e->getMessage()],400);
    $error=$e->getMessage();
}

$settings=loadSettings($config);$job=null;$payload=null;
if(!empty($_GET['job'])){try{$job=(new JobStore((string)$config['data_dir']))->load((string)$_GET['job']);$payload=jobPayload($job);}catch(Throwable $e){$error=$e->getMessage();}}
?><!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>eBay EAN13 Importer</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="wrap">
<div class="top"><div><h1>eBay EAN13 Importer</h1><div class="muted">Inserisce EAN13 solo dove manca · <strong>v1.1.1</strong></div></div><a class="btn secondary" href="?action=logout">Esci</a></div>
<?php if(!empty($error)):?><div class="alert err"><?=h($error)?></div><?php endif?><?php if(!empty($notice)):?><div class="alert ok"><?=h($notice)?></div><?php endif?>
<section class="card"><h2>1. Collegamento eBay</h2><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><div class="grid"><div><label>Client ID</label><input type="text" name="client_id" value="<?=h((string)($settings['client_id']??''))?>" required></div><div><label>Client Secret</label><input type="password" name="client_secret" value="<?=h((string)($settings['client_secret']??''))?>" required></div><div><label>Refresh Token</label><input type="password" name="refresh_token" value="<?=h((string)($settings['refresh_token']??''))?>" required></div><div><label>RuName</label><input type="text" name="ru_name" value="<?=h((string)($settings['ru_name']??''))?>"></div></div><label>Scope</label><textarea name="scope" rows="3"><?=h((string)($settings['scope']??''))?></textarea><p class="row"><button class="btn" name="action" value="save_settings">Salva credenziali</button><button class="btn secondary" name="action" value="test_connection">Verifica collegamento</button></p></form></section>
<section class="card"><h2>2. Carica file .xls</h2><p class="muted">Primo foglio, riga 1: <b>SKU | ITEM ID | EAN13</b>. Dati dalla riga 2.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="upload"><input type="file" name="xls" accept=".xls,application/vnd.ms-excel" required><p><button class="btn">Carica XLS</button></p></form></section>
<?php if($job&&$payload):?><section class="card"><h2>3. Controllo e aggiornamento</h2><div class="progress"><i id="bar" style="width:<?=round((($payload['total']-($payload['counts']['pending']??0))/max(1,$payload['total']))*100)?>%"></i></div><div id="counts" class="counts"><?php foreach($payload['counts'] as $k=>$v):?><span><b><?=h($k)?></b>: <?=$v?></span><?php endforeach?></div><p class="row"><button id="checkBtn" class="btn" <?=empty($payload['counts']['pending'])?'disabled':''?>>Controlla su eBay</button><button id="updateBtn" class="btn danger" <?=empty($payload['counts']['ready'])?'disabled':''?>>Inserisci EAN mancanti</button><a class="btn secondary" href="?action=report&amp;job=<?=h($job['id'])?>">Scarica report CSV</a></p><div class="tablebox"><table><thead><tr><th>Riga</th><th>SKU</th><th>Item ID</th><th>EAN file</th><th>EAN eBay</th><th>Fonte EAN eBay</th><th>Titolo</th><th>Stato</th><th>Messaggio</th></tr></thead><tbody id="tbody"><?php foreach($payload['rows'] as $r):?><tr><td><?=$r['excel_row']?></td><td><?=h($r['sku'])?></td><td><?=h($r['item_id'])?></td><td><?=h($r['ean'])?></td><td><?=h((string)($r['shown_ean']??''))?></td><td><?=h((string)($r['source_label']??''))?></td><td><?=h((string)($r['remote']['title']??''))?></td><td class="st-<?=h($r['status'])?>"><?=h($r['status_label'])?></td><td><?=h($r['message'])?></td></tr><?php endforeach?></tbody></table></div></section><script>window.EAN_APP=<?=json_encode(['csrf'=>$_SESSION['csrf'],'job'=>$job['id']],JSON_UNESCAPED_SLASHES)?>;</script><script src="assets/app.js"></script><?php endif?>
</main></body></html>
