<?php
require_once dirname(__DIR__) . '/config.php';

// s.php - Sistema Completo de Série (Lista + JWPlayer com Qualidade Flutuante e Fallback)
// Uso:
// - Lista: s.php?id=ID_TMDB
// - Player: s.php?id=ID_TMDB&t=SEASON&e=EPISODE&play=1

define('TMDB_API_KEY', 'b73f5479e8443355e40462afe494fc52');
define('TMDB_BASE', 'https://api.themoviedb.org/3');
define('TMDB_IMG', 'https://image.tmdb.org/t/p/w500');
// Cache somente em memória: APCu quando disponível.
// Não cria arquivos nem consome armazenamento da hospedagem.
define('CACHE_TTL', 900);
function cache_key($key) { return 'playmoz_' . hash('sha256', $key); }
function cache_read($key) {
    if (function_exists('apcu_fetch')) { $ok=false; $v=apcu_fetch(cache_key($key), $ok); return $ok && is_array($v) ? $v : null; }
    return null;
}
function cache_write($key, $value) {
    if (function_exists('apcu_store')) @apcu_store(cache_key($key), $value, CACHE_TTL);
}


function fetch_tmdb($endpoint) {
    $cacheKey = 'tmdb:' . $endpoint;
    $cached = cache_read($cacheKey);
    if ($cached !== null) return $cached;

    $url = TMDB_BASE . $endpoint . '?api_key=' . rawurlencode(TMDB_API_KEY) . '&language=pt-BR';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'PlayMoz/2.0'
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || !$resp) return null;
    $json = json_decode($resp, true);
    if (is_array($json)) cache_write($cacheKey, $json);
    return $json;
}

function get_serie($id) {
    return fetch_tmdb("/tv/{$id}");
}

function get_episodes($id, $season) {
    return fetch_tmdb("/tv/{$id}/season/{$season}");
}

function get_episode($id, $season, $episode) {
    return fetch_tmdb("/tv/{$id}/season/{$season}/episode/{$episode}");
}

function scrape_mgeb($id, $season, $episode) {
    $cacheKey = 'source:' . $id . ':' . $season . ':' . $episode;
    $cached = cache_read($cacheKey);
    if ($cached !== null) return $cached;

    $url = "https://mgeb.top/embed/{$id}/{$season}/{$episode}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_REFERER => 'https://mgeb.top/',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
            'Cache-Control: no-cache',
        ]
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200 || empty($html)) {
        return null;
    }

    $sources = [];
    $first_url = null;
    $first_type = 'mp4';

    if (preg_match('/sources\s*=\s*(\[.*?\]);/s', $html, $matches)) {
        $sourceData = $matches[1];

        preg_match_all('/"file"\s*:\s*"([^"]+)"/', $sourceData, $fileMatches);
        preg_match_all('/"type"\s*:\s*"([^"]+)"/', $sourceData, $typeMatches);
        preg_match_all('/"label"\s*:\s*"([^"]+)"/', $sourceData, $labelMatches);

        for ($i = 0; $i < count($fileMatches[1]); $i++) {
            $file = $fileMatches[1][$i];
            $type = isset($typeMatches[1][$i]) ? $typeMatches[1][$i] : 'mp4';
            $label = isset($labelMatches[1][$i]) ? $labelMatches[1][$i] : 'HD';

            if (filter_var($file, FILTER_VALIDATE_URL)) {
                if (empty($type) || $type == 'mp4') {
                    if (strpos($file, '.m3u8') !== false || strpos($file, 'hls') !== false) {
                        $type = 'hls';
                    } else {
                        $type = 'mp4';
                    }
                }

                $sources[] = ['file' => $file, 'type' => $type, 'label' => $label];

                if ($first_url === null) {
                    $first_url = $file;
                    $first_type = $type;
                }
            }
        }
    }

    if (empty($sources)) {
        $patterns = [
            '/https?:\/\/[^\s"\']+\.m3u8[^\s"\']*/i',
            '/https?:\/\/[^\s"\']+\/hls\/[^\s"\']+\.m3u8[^\s"\']*/i',
            '/https?:\/\/[^\s"\']+\.mp4[^\s"\']*/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[0] as $url) {
                    $url = trim($url);
                    $type = (strpos($url, '.m3u8') !== false || strpos($url, 'hls') !== false) ? 'hls' : 'mp4';

                    $exists = false;
                    foreach ($sources as $src) {
                        if ($src['file'] === $url) {
                            $exists = true;
                            break;
                        }
                    }

                    if (!$exists) {
                        $sources[] = ['file' => $url, 'type' => $type, 'label' => 'HD'];

                        if ($first_url === null) {
                            $first_url = $url;
                            $first_type = $type;
                        }
                    }
                }
            }
        }
    }

    $result = [
        'sources' => $sources,
        'first' => $first_url,
        'first_type' => $first_type
    ];
    cache_write($cacheKey, $result);
    return $result;
}

// Suporta:
// s.php?id=9787
// s.php?id=9787&t=1&e=12
// s.php?id=9787/1/12  <- URL curta
$rawId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$parts = array_values(array_filter(explode('/', trim($rawId, '/')), 'strlen'));

$id = isset($parts[0]) ? (int)$parts[0] : 0;
$season = isset($parts[1]) ? (int)$parts[1] : (isset($_GET['t']) ? (int)$_GET['t'] : 1);
$episode = isset($parts[2]) ? (int)$parts[2] : (isset($_GET['e']) ? (int)$_GET['e'] : 0);
$play = isset($_GET['play']) ? (int)$_GET['play'] : (($season > 0 && $episode > 0 && count($parts) >= 3) ? 1 : 0);
$returnKey = isset($_GET['return']) ? trim((string)$_GET['return']) : null;

if (!$id) {
    http_response_code(400);
    die('ID inválido');
}

if ($season < 1) $season = 1;
if ($episode < 1) $episode = 1;

// Endpoint leve: resolve apenas a fonte do episódio.
// O HTML do player já é enviado antes desta operação.
if (isset($_GET['action']) && $_GET['action'] === 'sources') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=900, stale-while-revalidate=60');
    $result = scrape_mgeb($id, $season, $episode);
    $sources = $result['sources'] ?? [];
    $sources = array_values(array_filter($sources, static function ($source) {
        $host = strtolower((string)parse_url((string)($source['file'] ?? ''), PHP_URL_HOST));
        return $host !== 'www-fontedecanais-sh.77zzhf54vdll71.com';
    }));
    if (!$sources) {
        $sources = [[
            'file' => "https://mgeb.top/embed/{$id}/{$season}/{$episode}",
            'type' => 'iframe',
            'label' => 'AUTO'
        ]];
    }
    echo json_encode(['ok'=>true,'sources'=>$sources], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

// No modo player não bloqueamos o primeiro byte com TMDB/scraping.
// O player aparece imediatamente e resolve a fonte em segundo plano.
if ($play == 1) {
    $incomingTitle = isset($_GET['pm_title']) ? trim((string)$_GET['pm_title']) : '';
    $incomingPoster = isset($_GET['pm_poster']) ? trim((string)$_GET['pm_poster']) : '';
    $serie = [
        'name' => $incomingTitle !== '' ? $incomingTitle : 'Série',
        'poster_path' => ''
    ];
    $episode_data = [];
    $seasons = [];
    $episodes_list = [];
    $video_sources = [];
} else {
    $serie = get_serie($id);
    if (empty($serie) || isset($serie['status_code'])) die('Série não encontrada');

    $seasons = $serie['seasons'] ?? [];
    $seasons = array_values(array_filter($seasons, fn($x) => ($x['season_number'] ?? 0) > 0));
    if (empty($seasons)) die('Nenhuma temporada encontrada');

    $all_episodes = get_episodes($id, $season);
    $episodes_list = $all_episodes['episodes'] ?? [];
    $episode_data = get_episode($id, $season, $episode);
    $video_sources = [];
}

$serie_name = htmlspecialchars($serie['name'] ?? 'Série', ENT_QUOTES, 'UTF-8');
$poster = !empty($serie['poster_path']) ? TMDB_IMG . $serie['poster_path'] : '';
$backdrop = !empty($serie['backdrop_path']) ? TMDB_IMG . $serie['backdrop_path'] : '';
$sources_json = '[]';

if ($play == 1) {
    $poster_url = ($incomingPoster ?? '') ?: ($episode_data['still_path'] ?? $serie['poster_path'] ?? '');
    $poster_url = $poster_url ? (strpos($poster_url, 'http') === 0 ? $poster_url : TMDB_IMG . $poster_url) : 'https://i.imgur.com/XB5B8Md.jpeg';
?>
<!DOCTYPE html>
<html>
<head>
    <base href="<?= htmlspecialchars(pm_base().'/', ENT_QUOTES, 'UTF-8') ?>" target="_top">
    <meta charset="UTF-8">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $serie_name ?> - T<?= $season ?>E<?= $episode ?></title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <script src="https://ssl.p.jwpcdn.com/player/v/8.6.2/jwplayer.js"></script>
    <script>jwplayer.key = "64HPbvSQorQcd52B8XFuhMtEoitbvY/EXJmMBfKcXZQU2Rnn";</script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/js-cookie@3.0.1/dist/js.cookie.min.js"></script>

    <style>
        html,body{padding:0;margin:0;height:100%;background:#000;overflow:hidden}
        #ani-player{width:100%!important;height:100%!important;overflow:hidden;background:#000}
        #iframe-player{width:100%;height:100%;border:none;display:none;background:#000}

        /* SISTEMA DE CARREGAMENTO - ADICIONADO */
        #playmoz-loader{
            position:fixed;inset:0;z-index:999999999;
            display:flex;align-items:center;justify-content:center;
            flex-direction:column;gap:14px;background:#050505;
            transition:opacity .35s ease,visibility .35s ease;
        }
        #playmoz-loader.hide{opacity:0;visibility:hidden;pointer-events:none}
        .pm-loader-ring{
            width:46px;height:46px;border:4px solid rgba(255,255,255,.12);
            border-top-color:#e50914;border-radius:50%;
            animation:pmspin .8s linear infinite;
        }
        .pm-loader-text{color:#fff;font:600 13px Arial,sans-serif;letter-spacing:.3px}
        .pm-loader-sub{color:#777;font:11px Arial,sans-serif}
        @keyframes pmspin{to{transform:rotate(360deg)}}

        .download{background:#ff0000;padding:10px;letter-spacing:1px;box-shadow:0 1px 15px #ff0000;color:#fff;font-family:"Open-Sans",sans-serif;margin:8px;border-radius:19px;font-weight:bold;font-size:11px;cursor:pointer}
        #down,.down-list{position:absolute}
        #down{z-index:10;top:0;right:16px}
        #down:hover>.down-list{display:block}
        .down-list{display:none;list-style:none;left:3px;z-index:999999999;box-shadow:0 1px 15px #ff0000;background:#ff0000;margin:0;border-radius:40px;width:144px;padding:5px 0 0}
        .down-list li{float:left;width:134px;padding:5px;text-align:center;margin-bottom:5px}
        .down-list li:hover{background:#0003}
        .down-list li a{color:#fff;text-decoration:none;font-family:"Open-Sans",sans-serif;font-size:18px;width:100%}
        .jw-icon.jw-icon-inline.jw-button-color.jw-reset.jw-icon-rewind{display:none}

        .quality-float-container{position:fixed;bottom:30px;left:30px;z-index:999999;display:flex;flex-direction:column;align-items:flex-start;gap:8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
        .quality-float-toggle{background:rgba(0,0,0,.85);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.15);border-radius:12px;padding:10px 16px;color:white;cursor:pointer;display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;transition:all .3s ease;box-shadow:0 8px 32px rgba(0,0,0,.4);user-select:none}
        .quality-float-toggle:hover{background:rgba(30,30,30,.95);transform:scale(1.05);border-color:rgba(255,255,255,.3)}
        .quality-float-toggle .quality-badge{background:rgba(99,102,241,.3);padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;color:#a5b4fc;border:1px solid rgba(99,102,241,.2)}
        .quality-float-dropdown{background:rgba(0,0,0,.92);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:12px;min-width:200px;box-shadow:0 12px 40px rgba(0,0,0,.6);display:none;animation:slideUp .3s ease}
        .quality-float-dropdown.show{display:block}
        @keyframes slideUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
        .quality-float-dropdown .quality-label{color:rgba(255,255,255,.6);font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:block;font-weight:600}
        .quality-float-dropdown select{width:100%;padding:8px 12px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:8px;color:white;font-size:14px;font-weight:500;cursor:pointer;outline:none;margin-bottom:8px}
        .quality-float-dropdown select option{background:#1a1a1a;color:white}
        .quality-float-dropdown .btn-apply{width:100%;padding:8px;background:#6366f1;color:white;border:none;border-radius:8px;font-weight:600;font-size:13px;cursor:pointer}
        @media(max-width:640px){.quality-float-container{bottom:20px;left:20px}.quality-float-toggle{padding:8px 14px;font-size:12px}.quality-float-dropdown{min-width:180px;padding:10px}}
    </style>
</head>
<body>

<div id="playmoz-loader">
    <div class="pm-loader-ring"></div>
    <div class="pm-loader-text">Carregando episódio...</div>
    <div class="pm-loader-sub">A preparar o player</div>
</div>



<div id="btn_try" style="background:#fff;padding:10px 20px;letter-spacing:1px;box-shadow:0 1px 15px #fff;color:#000;font-family:'Open-Sans',sans-serif;margin:8px;border-radius:19px;font-weight:bold;font-size:11px;position:absolute;left:100px;top:0;z-index:10;display:none;cursor:pointer" onclick="window.location.reload()">Tentar novamente</div>

<div id="down">
    <div class="download">Espelhar/Baixar</div>
    <ul id="down-list" class="down-list">
        <li><a id="download-link" href="#" download target="_blank">Clique aqui</a></li>
    </ul>
</div>

<div id="ani-player"></div>
<iframe id="iframe-player" allowfullscreen allow="autoplay; encrypted-media"></iframe>

<script src="../server-selector.js"></script>
<script>
var sourcesData=[];
var sourceEndpoint=<?= json_encode('/serie/?action=sources&id='.$id.'&t='.$season.'&e='.$episode) ?>;
var player=null;
var returnKey=<?= json_encode($returnKey) ?>;
var mediaId=<?= $id ?>;
var mediaType='tv';
var season=<?= $season ?>;
var episode=<?= $episode ?>;

function hidePlayMozLoader(){
    var l=document.getElementById('playmoz-loader');
    if(l) l.classList.add('hide');
}

function goBackWithKey(){
    if(window.opener){window.close();return}
    if(window.PlayMozNav){
        var returnUrl=window.PlayMozNav.getReturnUrl();
        if(returnUrl&&returnUrl!==window.location.href){window.location.href=returnUrl;return}
    }
    if(returnKey){
        try{
            var history=JSON.parse(sessionStorage.getItem('playmoz_nav_history')||'{}');
            if(history.returnKey===returnKey){
                var idx=history.history?history.history.indexOf(window.location.href):-1;
                if(idx>0){window.location.href=history.history[idx-1];return}
            }
        }catch(e){}
    }
    if(document.referrer&&document.referrer!==window.location.href){window.location.href=document.referrer;return}
    window.location.href='?page=home';
}

if(!returnKey&&window.PlayMozNav)returnKey=window.PlayMozNav.generateReturnKey();

function saveVideoProgress(p){
    var currentTime=p.getPosition();
    if(currentTime<5)return;
    var videoKey="videoProgress_"+btoa(mediaId+'_'+mediaType+'_'+season+'_'+episode);
    Cookies.set(videoKey,currentTime,{expires:7,path:'/'});
    saveProgressToServer(mediaId,mediaType,currentTime);
}

function saveProgressToServer(id,type,progress){
    try{
        var xhr=new XMLHttpRequest();
        xhr.open('POST','?action=save_watch_progress',true);
        xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
        xhr.send('id='+id+'&type='+type+'&progress='+Math.round(progress));
    }catch(e){}
}

function loadVideoProgress(p){
    var videoKey="videoProgress_"+btoa(mediaId+'_'+mediaType+'_'+season+'_'+episode);
    var savedTime=Cookies.get(videoKey);
    if(savedTime&&parseFloat(savedTime)>10){
        Swal.fire({
            title:"Continuar de onde parou?",
            text:"Você parou em "+formatTime(savedTime)+".",
            icon:"question",
            showCancelButton:true,
            confirmButtonText:"Continuar",
            cancelButtonText:"Recomeçar"
        }).then(function(result){
            if(result.isConfirmed)p.seek(savedTime);else Cookies.remove(videoKey);
        });
    }
}

function formatTime(seconds){
    var minutes=Math.floor(seconds/60);
    var secs=Math.floor(seconds%60);
    return minutes+" min e "+secs+" seg";
}


function setLoader(text, sub){
    var a=document.querySelector('.pm-loader-text'), b=document.querySelector('.pm-loader-sub');
    if(a && text) a.textContent=text;
    if(b && sub) b.textContent=sub;
}
function resolveSources(){
    setLoader('Carregando episódio...','A preparar o player');
    return fetch(sourceEndpoint,{cache:'default',credentials:'same-origin'})
        .then(function(r){ if(!r.ok) throw new Error('source'); return r.json(); })
        .then(function(data){
            sourcesData=(data&&Array.isArray(data.sources))?data.sources:[];
            PlayMozServers.start(sourcesData);
        })
        .catch(function(){
            setLoader('Preparando player...','Fonte alternativa');
            PlayMozServers.start([]);
        });
}

function initPlayer(){
    if(!sourcesData||sourcesData.length===0){loadIframeFallback();return}
    var firstSource=sourcesData[0];

    if(firstSource.type==='iframe'||firstSource.file.indexOf('http')!==0){
        loadIframeFallback(firstSource.file);return
    }

    document.getElementById('download-link').href=firstSource.file;

    var jwSources=sourcesData.map(function(item){
        return {
            file:item.file,
            label:item.label||'AUTO',
            type:(item.type==='hls'||item.file.includes('.m3u8'))?'hls':'mp4'
        };
    });

    player=jwplayer("ani-player").setup({
        sources:jwSources,
        tracks:[{
            file:"https://vibra.ammsolucoes.online/legenda.srt",
            label:"Português",
            kind:"captions",
            default:true
        }],
        captions:{color:'yellow',fontSize:'20px',fontFamily:'Arial, sans-serif',backgroundOpacity:0},
        aspectratio:"16:9",
        width:"100%",
        height:"100%",
        primary:"html5",
        autostart:false,
        image:"<?= htmlspecialchars($poster_url, ENT_QUOTES, 'UTF-8') ?>",
        playbackRateControls:[0.5,0.75,1,1.25,1.5,2]
    });

    player.on('ready',function(){
        hidePlayMozLoader();
        loadVideoProgress(player);

        player.addButton('<svg xmlns="http://www.w3.org/2000/svg" class="jw-svg-icon" viewBox="0 0 240 240"><path d="m25.993957,57.778v125.3c.03604,2.63589 2.164107,4.76396 4.8,4.8h62.7v-19.3h-48.2v-96.4h115.7v19.3c0,5.3 3.6,7.2 8,4.3l41.8-27.9c2.93574-1.480087 4.13843-5.04363 2.7-8-.57502-1.174985-1.52502-2.124979-2.7-2.7l-41.8-27.9c-4.4-2.9-8-1-8,4.3v19.3H30.893957c-2.689569.03972-4.860275 2.210431-4.9,4.9z"/></svg>',"Avançar 10s",function(){player.seek(player.getPosition()+10)},"Avançar 10s");

        player.addButton('<svg xmlns="http://www.w3.org/2000/svg" class="jw-svg-icon" viewBox="0 0 240 240"><path d="M113.2,131.078a21.589,21.589,0,0,0-17.7-10.6,21.589,21.589,0,0,0-17.7,10.6,44.769,44.769,0,0,0,0,46.3,21.589,21.589,0,0,0,17.7,10.6,21.589,21.589,0,0,0,17.7-10.6,44.769,44.769,0,0,0,0-46.3Zm-17.7,47.2c-7.8,0-14.4-11-14.4-24.1s6.6-24.1,14.4-24.1,14.4,11,14.4,24.1S103.4,178.278,95.5,178.278Z"/></svg>',"Voltar 10s",function(){player.seek(player.getPosition()-10)},"Voltar 10s");
    });

    player.on('time',function(){saveVideoProgress(player)});

    player.on('error',function(){
        
        PlayMozServers.error();
    });
}

function loadIframeFallback(url){
    var fallbackUrl=url||'https://mgeb.top/embed/<?= $id ?>/<?= $season ?>/<?= $episode ?>';
    document.getElementById('ani-player').style.display='none';
    document.getElementById('down').style.display='none';
    var iframe=document.getElementById('iframe-player');
    iframe.src=fallbackUrl;
    iframe.style.display='block';
    hidePlayMozLoader();
}

document.addEventListener('keydown',function(e){
    if(e.key==='Backspace'||e.key==='Escape'){
        e.preventDefault();goBackWithKey();
    }
});

document.addEventListener('DOMContentLoaded',function(){
    resolveSources();
    if(!returnKey&&window.PlayMozNav)returnKey=window.PlayMozNav.generateReturnKey();
});


window.addEventListener('popstate',function(e){
    e.preventDefault();goBackWithKey();
});
</script>
<script>
var link = "https://omg10.com/4/10811407";
var tempo = 120000; // 2 minutos
var ultimo = 0;

document.addEventListener('click', function() {
    var agora = Date.now();
    if (agora - ultimo >= tempo) {
        window.open(link, '_blank');
        ultimo = agora;
    }
});
</script>
</body>
</html>
<?php
exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $serie_name ?> - Assistir Online</title>
<meta name="robots" content="noindex, nofollow">
<script src="https://cdn.tailwindcss.com"></script>

<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#0a0a0a;font-family:'Segoe UI',system-ui,sans-serif;color:#fff;min-height:100vh}

/* SISTEMA DE CARREGAMENTO - ADICIONADO */
#playmoz-loader{
    position:fixed;inset:0;z-index:999999999;
    display:flex;align-items:center;justify-content:center;
    flex-direction:column;gap:14px;background:#0a0a0a;
    transition:opacity .35s ease,visibility .35s ease;
}
#playmoz-loader.hide{opacity:0;visibility:hidden;pointer-events:none}
.pm-loader-ring{
    width:46px;height:46px;border:4px solid rgba(255,255,255,.12);
    border-top-color:#e50914;border-radius:50%;
    animation:pmspin .8s linear infinite;
}
.pm-loader-text{color:#fff;font:600 13px Arial,sans-serif;letter-spacing:.3px}
.pm-loader-sub{color:#777;font:11px Arial,sans-serif}
@keyframes pmspin{to{transform:rotate(360deg)}}

.banner{position:relative;width:100%;height:220px;background:#0a0a0a;overflow:hidden}
.banner img{width:100%;height:100%;object-fit:cover;opacity:.6}
.banner .gradient{position:absolute;inset:0;background:linear-gradient(180deg,rgba(10,10,10,.2) 0%,rgba(10,10,10,.95) 85%,#0a0a0a 100%)}
.banner .title-overlay{position:absolute;bottom:20px;left:24px;z-index:2}
.banner .title-overlay h1{font-size:1.6rem;font-weight:700;text-shadow:0 2px 20px rgba(0,0,0,.8)}
.banner .title-overlay .meta{display:flex;gap:12px;flex-wrap:wrap;font-size:.8rem;color:#ccc;margin-top:4px}
.banner .title-overlay .meta span{background:rgba(0,0,0,.5);padding:2px 12px;border-radius:12px;backdrop-filter:blur(4px)}

.container{max-width:1100px;margin:0 auto;padding:12px 16px 40px}
.season-bar{display:flex;flex-wrap:wrap;gap:6px;padding:10px 0 14px;border-bottom:1px solid rgba(255,255,255,.06);margin-bottom:16px}
.season-bar .label{color:#777;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-right:6px;display:flex;align-items:center}
.season-bar a{padding:4px 14px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;color:#999;background:rgba(255,255,255,.05);transition:all .25s;border:1px solid transparent}
.season-bar a:hover{background:rgba(255,255,255,.1);color:#fff}
.season-bar a.active{background:#e50914;color:#fff;border-color:#e50914;box-shadow:0 2px 12px rgba(229,9,20,.25)}

.episodes-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}
.episode-card{background:rgba(255,255,255,.04);border-radius:10px;overflow:hidden;transition:all .3s;border:1px solid rgba(255,255,255,.04);text-decoration:none;color:#fff;cursor:pointer}
.episode-card:hover{transform:translateY(-4px);border-color:rgba(229,9,20,.3);background:rgba(255,255,255,.07);box-shadow:0 8px 25px rgba(0,0,0,.5)}
.episode-card .thumb{position:relative;height:90px;background:#141414;overflow:hidden}
.episode-card .thumb img{width:100%;height:100%;object-fit:cover}
.episode-card .thumb .ep-num{position:absolute;bottom:4px;left:6px;background:rgba(0,0,0,.8);padding:1px 10px;border-radius:12px;font-size:10px;font-weight:700;border:1px solid rgba(255,255,255,.08)}
.episode-card .thumb .play-overlay{position:absolute;inset:0;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .3s}
.episode-card:hover .thumb .play-overlay{opacity:1}
.episode-card .thumb .play-overlay .play-btn{width:36px;height:36px;background:rgba(229,9,20,.9);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;color:#fff;box-shadow:0 4px 15px rgba(229,9,20,.3)}
.episode-card .info{padding:8px 10px}
.episode-card .info .name{font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.episode-card .info .desc{font-size:10px;color:#888;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;height:26px;line-height:1.3;margin-top:2px}
.episode-card .info .date{font-size:9px;color:#555;margin-top:4px;padding-top:4px;border-top:1px solid rgba(255,255,255,.04)}
.no-episodes{text-align:center;padding:40px 0;color:#555}
.no-episodes i{font-size:2rem;display:block;margin-bottom:12px;opacity:.3}

@media(max-width:600px){
.banner{height:150px}.banner .title-overlay h1{font-size:1.1rem}.banner .title-overlay .meta{font-size:.65rem;gap:6px}.banner .title-overlay .meta span{padding:1px 8px}
.container{padding:8px 10px 30px}.episodes-grid{grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px}
.episode-card .thumb{height:72px}.episode-card .info .name{font-size:11px}.episode-card .info .desc{font-size:9px;height:22px}
.season-bar{gap:4px;padding:6px 0 10px}.season-bar a{font-size:10px;padding:3px 10px}.season-bar .label{font-size:10px}
}
@media(max-width:400px){
.episodes-grid{grid-template-columns:repeat(auto-fill,minmax(100px,1fr))}
.episode-card .thumb{height:60px}.episode-card .info{padding:5px 6px}.episode-card .info .name{font-size:10px}.episode-card .info .desc{display:none}
.banner{height:120px}.banner .title-overlay{bottom:10px;left:12px}.banner .title-overlay h1{font-size:.9rem}
}
</style>
</head>
<body>

<div id="playmoz-loader">
    <div class="pm-loader-ring"></div>
    <div class="pm-loader-text">A procurar episódios...</div>
    <div class="pm-loader-sub">A carregar informações da série</div>
</div>

<div class="banner">
<?php if($backdrop): ?>
<img src="<?= htmlspecialchars($backdrop,ENT_QUOTES,'UTF-8') ?>" alt="<?= $serie_name ?>">
<?php else: ?>
<div style="width:100%;height:100%;background:linear-gradient(135deg,#1a0a0a,#0a0a0a)"></div>
<?php endif; ?>
<div class="gradient"></div>
<div class="title-overlay">
<h1><?= $serie_name ?></h1>
<div class="meta">
<span><i class="fas fa-calendar"></i> <?= substr($serie['first_air_date']??'N/A',0,4) ?></span>
<span><i class="fas fa-star" style="color:#f5c518"></i> <?= number_format($serie['vote_average']??0,1) ?></span>
<span><i class="fas fa-list"></i> <?= count($seasons) ?> T</span>
<span><i class="fas fa-video"></i> <?= $serie['number_of_episodes']??0 ?></span>
</div>
</div>
</div>

<div class="container">
<div class="season-bar">
<span class="label"><i class="fas fa-layer-group"></i> Temporadas:</span>
<?php foreach($seasons as $s): ?>
<a href="?id=<?= $id ?>&t=<?= $s['season_number'] ?>" class="<?= $s['season_number']==$season?'active':'' ?>">T<?= $s['season_number'] ?></a>
<?php endforeach; ?>
</div>

<?php if($episodes_list): ?>
<div class="episodes-grid">
<?php foreach($episodes_list as $ep):
$ep_num=$ep['episode_number'];
$thumb=!empty($ep['still_path'])?TMDB_IMG.$ep['still_path']:'';
$playerUrl = '/tv/' . $id . '/' . $season . '/' . $ep_num;
if($returnKey)$playerUrl.='&return='.urlencode($returnKey);
?>
<a href="<?= htmlspecialchars($playerUrl,ENT_QUOTES,'UTF-8') ?>" target="_blank" class="episode-card">
<div class="thumb">
<?php if($thumb): ?>
<img src="<?= htmlspecialchars($thumb,ENT_QUOTES,'UTF-8') ?>" alt="Episódio <?= $ep_num ?>" loading="lazy">
<?php else: ?>
<div style="width:100%;height:100%;background:linear-gradient(135deg,#1a1a2e,#16213e);display:flex;align-items:center;justify-content:center;color:#444;font-size:20px"><i class="fas fa-film"></i></div>
<?php endif; ?>
<span class="ep-num">E<?= str_pad($ep_num,2,'0',STR_PAD_LEFT) ?></span>
<div class="play-overlay"><div class="play-btn"><i class="fas fa-play"></i></div></div>
</div>
<div class="info">
<div class="name"><?= htmlspecialchars($ep['name']??"Episódio {$ep_num}",ENT_QUOTES,'UTF-8') ?></div>
<div class="desc"><?= htmlspecialchars($ep['overview']??'',ENT_QUOTES,'UTF-8') ?></div>
<div class="date"><i class="far fa-calendar-alt"></i> <?= !empty($ep['air_date'])?date('d/m/Y',strtotime($ep['air_date'])):'--/--' ?></div>
</div>
</a>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="no-episodes"><i class="fas fa-tv"></i><p>Nenhum episódio encontrado.</p></div>
<?php endif; ?>
</div>



<script>
(function(){
    var loader=document.getElementById('playmoz-loader');
    function hideLoader(){
        if(loader) loader.classList.add('hide');
    }
    window.addEventListener('load',function(){
        setTimeout(hideLoader,250);
    });
    setTimeout(hideLoader,3500);
})();
</script>
</body>
</html>
<?php
?>
