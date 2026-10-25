<?php
declare(strict_types=1);
session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Lax']);
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
$config=require __DIR__.'/config.php';
date_default_timezone_set($config['timezone']);
$file=__DIR__.'/data/jogos.json';
function esc($s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function readGames($file):array{$x=json_decode((string)@file_get_contents($file),true);return is_array($x)?array_values(array_filter($x,'is_array')):[];}
function writeGames($file,array $games):void{
 if(!is_dir(dirname($file)) && !mkdir(dirname($file),0755,true) && !is_dir(dirname($file)))throw new RuntimeException('Sem permissões para criar data');
 if(!is_file(dirname($file).'/.htaccess'))@file_put_contents(dirname($file).'/.htaccess',"Require all denied\nDeny from all\n");
 $lock=fopen($file.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Falha de bloqueio');
 try{$tmp=tempnam(dirname($file),'jogos_');if(!$tmp)throw new RuntimeException('Falha ao gravar');
  $json=json_encode(array_values($games),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  if(file_put_contents($tmp,$json."\n")===false||!rename($tmp,$file))throw new RuntimeException('Falha ao gravar JSON');
 }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function post($k):string{return trim((string)($_POST[$k]??''));}
function utcTs($date):int {if(!$date)return 0;try{$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$date,new DateTimeZone('Africa/Maputo'));if(!$d)return 0;return $d->getTimestamp();}catch(Throwable $e){return 0;}}
function fetchEvents($url):array{
 if(strpos($url,'https://embedtv.cc/')!==0)throw new RuntimeException('Origem inválida');
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'PlayMozAdmin/1.0']);
 $raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
 if($raw===false||$status!==200)throw new RuntimeException('API não disponível (HTTP '.$status.')');
 if(strlen($raw)>2000000)throw new RuntimeException('Resposta demasiado grande');
 $data=json_decode($raw,true);if(!is_array($data))throw new RuntimeException('A API não devolveu JSON válido. Respondeu: '.substr($type,0,70));
 foreach(['events','data','results','matches','sports'] as $key){if(isset($data[$key])&&is_array($data[$key])){$data=$data[$key];break;}}
 if(isset($data['events'])&&is_array($data['events']))$data=$data['events'];
 if(array_keys($data)!==range(0,count($data)-1) && $data!==[])throw new RuntimeException('Formato da lista de eventos não reconhecido');
 return array_slice(array_values(array_filter($data,'is_array')),0,200);
}
function eventToGame(array $x):array{
 $teams=$x['teams']??[];
 $home=$teams['home']['name']??$x['home_team']??'';
 $away=$teams['away']['name']??$x['away_team']??'';
 $title=(string)($x['title']??$x['name']??($home.' x '.$away));
 $league=$x['league']??[];
 $competition=is_array($league)?(string)($league['name']??'Futebol'):(string)($league?:($x['competition']??'Futebol'));
 $startRaw=$x['time_start']??$x['start_timestamp']??$x['timestamp']??$x['start']??$x['date']??0;
 $endRaw=$x['time_end']??$x['end_timestamp']??0;
 $start=is_numeric($startRaw)?(int)$startRaw:(strtotime((string)$startRaw)?:0);
 $end=is_numeric($endRaw)?(int)$endRaw:(strtotime((string)$endRaw)?:0);
 if($start>100000000000)$start=intdiv($start,1000);
 if($end>100000000000)$end=intdiv($end,1000);
 if($end<=$start)$end=$start+7200;
 $players=$x['players']??$x['embeds']??[];
 $embeds=[];
 if(is_array($players))foreach($players as $item){
   $url=is_array($item)?(string)($item['embed_url']??$item['url']??''):(string)$item;
   if(filter_var($url,FILTER_VALIDATE_URL)&&in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true))$embeds[]=['embed_url'=>$url,'provider'=>'Servidor '.(count($embeds)+1),'quality'=>'HD'];
 }
 $id='evt-'.substr(hash('sha256',(string)($x['id']??($title.'|'.$start))),0,16);
 $teamsOut=['home'=>['name'=>(string)$home,'image'=>(string)($teams['home']['image']??'')],'away'=>['name'=>(string)$away,'image'=>(string)($teams['away']['image']??'')]];
 return ['id'=>$id,'slug'=>(string)($x['slug']??$id),'title'=>mb_substr($title,0,180),'teams'=>$teamsOut,
  'league'=>['name'=>$competition,'image'=>(string)(is_array($league)?($league['image']??''):'')],
  'competition'=>$competition,'poster'=>(string)($x['poster']??$x['image']??''),
  'start_timestamp'=>$start,'end_timestamp'=>$end,'time_start'=>$start?gmdate('Y-m-d\TH:i:s\Z',$start):'',
  'time_end'=>$end?gmdate('Y-m-d\TH:i:s\Z',$end):'','embeds'=>$embeds,'players'=>array_column($embeds,'embed_url')];
}

if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
$error='';$message='';$events=[];
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'],post('csrf')))throw new RuntimeException('Pedido inválido. Recarregue a página.');
  $action=post('action');
  if($action==='search'){$events=fetchEvents($config['events_api']);$message=count($events).' eventos encontrados';}
  elseif($action==='save'||$action==='import'){
   if($action==='import'){$item=json_decode(post('event_json'),true);if(!is_array($item))throw new RuntimeException('Evento inválido');$g=eventToGame($item);}
   else{
    $start=utcTs(post('start'));$end=utcTs(post('end'));if(!$start)throw new RuntimeException('Defina uma data e hora válidas');
    $links=preg_split('/\R/u',post('embeds'));$embeds=[];foreach($links as $link){$link=trim($link);if(!$link)continue;if(!filter_var($link,FILTER_VALIDATE_URL)||!in_array(parse_url($link,PHP_URL_SCHEME),['https','http'],true))throw new RuntimeException('URL de servidor inválida');$embeds[]=['embed_url'=>$link,'provider'=>'Servidor '.(count($embeds)+1),'quality'=>'HD'];}
    $g=['id'=>post('id')?:'manual-'.bin2hex(random_bytes(7)),'title'=>mb_substr(post('title'),0,180),'competition'=>mb_substr(post('competition')?:'Futebol',0,100),'poster'=>post('poster'),'start_timestamp'=>$start,'end_timestamp'=>$end>$start?$end:$start+7200,'embeds'=>$embeds,'players'=>array_column($embeds,'embed_url'),'time_start'=>gmdate('Y-m-d\TH:i:s\Z',$start),'time_end'=>gmdate('Y-m-d\TH:i:s\Z',$end>$start?$end:$start+7200)];
   }
   if(!$g['title']||!$g['start_timestamp'])throw new RuntimeException('Evento sem título ou horário válido');
   $list=readGames($file);$found=false;foreach($list as $i=>$old){if(($old['id']??null)===$g['id']){$list[$i]=array_merge($old,$g);$found=true;break;}}if(!$found)$list[]=$g;writeGames($file,$list);$message='Jogo guardado com sucesso';
  }elseif($action==='delete'){$list=array_values(array_filter(readGames($file),fn($g)=>(string)($g['id']??'')!==post('id')));writeGames($file,$list);$message='Jogo apagado';}
 }
}catch(Throwable $e){$error=$e->getMessage();}
$games=readGames($file);
$editing=null;foreach($games as $g)if(($g['id']??'')===($_GET['edit']??null))$editing=$g;
function localDate($ts):string{return $ts?date('Y-m-d\TH:i',(int)$ts):'';}
?><!doctype html><html lang="pt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>PLAYMOZ | Painel de jogos</title><style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#08121e;color:#e8f1fa;font:15px system-ui,-apple-system,Arial}.wrap{max-width:1180px;margin:auto;padding:24px}header{display:flex;justify-content:space-between;align-items:center;gap:16px;border-bottom:1px solid #263748;margin-bottom:24px}h1{font-size:26px}h2{font-size:19px;margin-top:0}.muted{color:#99b0c3}.panel{background:#101f2f;border:1px solid #284055;border-radius:17px;padding:20px;margin:20px 0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:15px}.full{grid-column:1/-1}label{display:block;margin:0 0 7px;color:#bbccda}input,textarea{width:100%;padding:12px;border:1px solid #395269;background:#091624;color:#fff;border-radius:9px;font:inherit}textarea{min-height:90px}button,.btn{cursor:pointer;background:#17b2cf;border:0;padding:11px 17px;border-radius:9px;color:#04111a;font-weight:750;text-decoration:none;display:inline-block}button:hover,.btn:hover{filter:brightness(1.13)}.danger{background:#e6677c;color:#fff}.ghost{background:#263a4d;color:#fff}.actions{display:flex;gap:9px;flex-wrap:wrap;align-items:center}.notice{padding:14px;border-radius:9px;background:#164d49}.error{background:#68303b}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.game{padding:16px;border:1px solid #2e4559;border-radius:12px;background:#0b1928}.game strong{display:block;margin-bottom:8px}.game small{display:block;color:#a8bdce;margin-bottom:13px}form.inline{display:inline}code{word-break:break-all;color:#8bdeec}footer{margin:30px 0;color:#91aabc}@media(max-width:600px){.wrap{padding:14px}header{align-items:flex-start;flex-direction:column}}
</style></head><body><div class="wrap"><header><div><h1>PLAYMOZ <span style="color:#17b2cf">/ JOGOS</span></h1><p class="muted">Painel de administração • Horário de Moçambique (UTC+2)</p></div></header>
<?php if($error):?><p class="notice error"><?=esc($error)?></p><?php endif;?><?php if($message):?><p class="notice"><?=esc($message)?></p><?php endif;?>
<section class="panel"><h2><?= $editing?'Editar jogo':'Adicionar jogo manualmente'?></h2><form method="post" class="grid"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=esc($editing['id']??'')?>"><div><label>Nome do jogo</label><input required name="title" placeholder="Equipa A x Equipa B" value="<?=esc($editing['title']??'')?>"></div><div><label>Competição</label><input name="competition" value="<?=esc($editing['competition']??'Futebol')?>"></div><div><label>Início (Moçambique)</label><input required type="datetime-local" name="start" value="<?=esc(localDate($editing['start_timestamp']??0))?>"></div><div><label>Fim (opcional; padrão 2h)</label><input type="datetime-local" name="end" value="<?=esc(localDate($editing['end_timestamp']??0))?>"></div><div class="full"><label>URL do cartaz/imagem (opcional)</label><input type="url" name="poster" value="<?=esc($editing['poster']??'')?>"></div><div class="full"><label>Links dos servidores (um por linha)</label><textarea name="embeds" placeholder="https://servidor.exemplo/player"><?=esc(implode("\n",array_map(fn($e)=>(string)($e['embed_url']??''),$editing['embeds']??[])))?></textarea></div><div class="full actions"><button name="action" value="save">Guardar jogo</button><?php if($editing):?><a class="btn ghost" href="./">Cancelar edição</a><?php endif;?></div></form></section>
<section class="panel"><h2>Importar eventos da EmbedTV</h2><p class="muted">Fonte: <code><?=esc($config['events_api'])?></code>. A importação só funciona quando a origem devolve JSON válido.</p><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><button name="action" value="search">Procurar jogos na API</button></form><?php if($events):?><div class="cards" style="margin-top:18px"><?php foreach($events as $raw):$g=eventToGame($raw);?><div class="game"><strong><?=esc($g['title']?:'Evento sem título')?></strong><small><?=esc($g['competition'])?> · <?=esc($g['start_timestamp']?date('d/m/Y H:i',$g['start_timestamp']):'Sem data')?></small><form method="post"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="event_json" value="<?=esc(json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>"><button name="action" value="import">Importar jogo</button></form></div><?php endforeach;?></div><?php endif;?></section>
<section class="panel"><h2>Jogos guardados (<?=count($games)?>)</h2><div class="cards"><?php foreach($games as $g):?><div class="game"><strong><?=esc($g['title']??'Jogo')?></strong><small><?=esc($g['competition']??'')?> · <?=esc(date('d/m/Y H:i',(int)($g['start_timestamp']??0)))?></small><div class="actions"><a class="btn ghost" href="?edit=<?=urlencode((string)$g['id'])?>">Editar</a><form method="post" class="inline" onsubmit="return confirm('Apagar este jogo?')"><input type="hidden" name="csrf" value="<?=esc($_SESSION['csrf'])?>"><input type="hidden" name="id" value="<?=esc($g['id'])?>"><button class="danger" name="action" value="delete">Apagar</button></form></div></div><?php endforeach;?></div><?php if(!$games):?><p class="muted">Ainda não há jogos guardados.</p><?php endif;?></section>
<footer>PLAYMOZ · O JSON público encontra-se em <code>data-public.php</code></footer></div></body></html>