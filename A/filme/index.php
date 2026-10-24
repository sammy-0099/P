<?php
require_once dirname(__DIR__) . '/config.php';
pm_require_authorized_embed();

// t.php - Sistema de Player Completo com JWPlayer, HLS, Qualidade e Iframe Fallback
// Uso: t.php?id=ID_TMDB&play=1

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


// ============================================================
// FUNÇÕES
// ============================================================

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

function get_movie($id) {
    return fetch_tmdb("/movie/{$id}");
}

function scrape_mgeb($id) {
    $cacheKey = 'source:' . $id;
    $cached = cache_read($cacheKey);
    if ($cached !== null) return $cached;


    $url = "https://mgeb.top/embed/{$id}";

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
            'Cache-Control: no-cache'
        ]
    ]);

    $html = curl_exec($ch);

    $httpCode = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($httpCode != 200 || empty($html)) {
        return null;
    }

    $sources = [];
    $first_url = null;
    $first_type = 'mp4';

    // ========================================================
    // SOURCES JAVASCRIPT
    // ========================================================

    if (preg_match('/sources\s*=\s*(\[.*?\]);/s', $html, $matches)) {

        $sourceData = $matches[1];

        preg_match_all(
            '/"file"\s*:\s*"([^"]+)"/',
            $sourceData,
            $fileMatches
        );

        preg_match_all(
            '/"type"\s*:\s*"([^"]+)"/',
            $sourceData,
            $typeMatches
        );

        preg_match_all(
            '/"label"\s*:\s*"([^"]+)"/',
            $sourceData,
            $labelMatches
        );

        for (
            $i = 0;
            $i < count($fileMatches[1]);
            $i++
        ) {

            $file = $fileMatches[1][$i];

            $type = isset($typeMatches[1][$i])
                ? $typeMatches[1][$i]
                : 'mp4';

            $label = isset($labelMatches[1][$i])
                ? $labelMatches[1][$i]
                : 'HD';

            if (filter_var($file, FILTER_VALIDATE_URL)) {

                if (
                    empty($type) ||
                    $type === 'mp4'
                ) {

                    if (
                        strpos($file, '.m3u8') !== false ||
                        strpos($file, 'hls') !== false
                    ) {
                        $type = 'hls';
                    } else {
                        $type = 'mp4';
                    }
                }

                $sources[] = [
                    'file' => $file,
                    'type' => $type,
                    'label' => $label
                ];

                if ($first_url === null) {
                    $first_url = $file;
                    $first_type = $type;
                }
            }
        }
    }

    // ========================================================
    // FALLBACK POR REGEX
    // ========================================================

    if (empty($sources)) {

        $patterns = [
            '/https?:\/\/[^\s"\']+\.m3u8[^\s"\']*/i',
            '/https?:\/\/[^\s"\']+\/hls\/[^\s"\']+\.m3u8[^\s"\']*/i',
            '/https?:\/\/[^\s"\']+\.mp4[^\s"\']*/i'
        ];

        foreach ($patterns as $pattern) {

            if (preg_match_all(
                $pattern,
                $html,
                $matches
            )) {

                foreach ($matches[0] as $videoUrl) {

                    $videoUrl = trim($videoUrl);

                    $videoUrl = rtrim(
                        $videoUrl,
                        '\'"<>),;'
                    );

                    $type =
                        (
                            strpos($videoUrl, '.m3u8') !== false ||
                            strpos($videoUrl, 'hls') !== false
                        )
                        ? 'hls'
                        : 'mp4';

                    $exists = false;

                    foreach ($sources as $src) {

                        if ($src['file'] === $videoUrl) {
                            $exists = true;
                            break;
                        }
                    }

                    if (
                        !$exists &&
                        filter_var(
                            $videoUrl,
                            FILTER_VALIDATE_URL
                        )
                    ) {

                        $sources[] = [
                            'file' => $videoUrl,
                            'type' => $type,
                            'label' => 'HD'
                        ];

                        if ($first_url === null) {
                            $first_url = $videoUrl;
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

// ============================================================
// PARÂMETROS
// ============================================================

$id = isset($_GET['id'])
    ? intval($_GET['id'])
    : 0;

$play = isset($_GET['play'])
    ? intval($_GET['play'])
    : 0;

$returnKey = isset($_GET['return'])
    ? trim((string)$_GET['return'])
    : '';

$incomingTitle = isset($_GET['pm_title'])
    ? trim((string)$_GET['pm_title'])
    : '';

$incomingPoster = isset($_GET['pm_poster'])
    ? trim((string)$_GET['pm_poster'])
    : '';

if (!$id) {
    die('ID inválido');
}
if (isset($_GET['action']) && $_GET['action'] === 'sources') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=900, stale-while-revalidate=60');
    $result = scrape_mgeb($id);
    $sources = $result['sources'] ?? [];
    $sources = array_values(array_filter($sources, static function ($source) {
        $host = strtolower((string)parse_url((string)($source['file'] ?? ''), PHP_URL_HOST));
        return $host !== 'www-fontedecanais-sh.77zzhf54vdll71.com';
    }));
    if (!$sources) {
        $sources = [['file'=>"https://embedplayapi.top/embed/{$id}",'type'=>'iframe','label'=>'AUTO']];
    }
    echo json_encode(['ok'=>true,'sources'=>$sources], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}


// ============================================================
// BUSCA DADOS DO FILME
// ============================================================

$movie = get_movie($id);

if (
    empty($movie) ||
    isset($movie['status_code'])
) {
    die('Filme não encontrado');
}

$movie_name = htmlspecialchars(
    isset($movie['title'])
        ? $movie['title']
        : 'Filme',
    ENT_QUOTES,
    'UTF-8'
);

$poster_url = !empty($movie['poster_path'])
    ? TMDB_IMG . $movie['poster_path']
    : 'https://i.imgur.com/XB5B8Md.jpeg';

// ============================================================
// SCRAPE MGEB
// ============================================================

// No player, as fontes são resolvidas por AJAX depois do HTML.
$video_sources = [];

// ============================================================
// JSON PARA JAVASCRIPT
// ============================================================

$sources_json = json_encode(
    $video_sources,
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
);

if ($sources_json === false) {
    $sources_json = '[]';
}

// ============================================================
// PLAYER
// ============================================================

if ($play == 1) {
?>
<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <base href="<?= htmlspecialchars(pm_base().'/', ENT_QUOTES, 'UTF-8') ?>" target="_top">

    <meta
        name="referrer"
        content="no-referrer"
    >

    <meta
        name="robots"
        content="noindex, nofollow"
    >

    <meta charset="UTF-8">

    <title>
        <?= $movie_name ?> - PlayMoz Player
    </title>

    <meta
        content="width=device-width, initial-scale=1.0"
        name="viewport"
    >

    <script
        type="text/javascript"
        src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js">
    </script>

    <script
        type="text/javascript"
        src="https://ssl.p.jwpcdn.com/player/v/8.6.2/jwplayer.js">
    </script>

    <script type="text/javascript">

        jwplayer.key =
            "64HPbvSQorQcd52B8XFuhMtEoitbvY/EXJmMBfKcXZQU2Rnn";

    </script>

    <script
        src="https://cdn.jsdelivr.net/npm/sweetalert2@11">
    </script>

    <script
        src="https://cdn.jsdelivr.net/npm/js-cookie@3.0.1/dist/js.cookie.min.js">
    </script>

    <style>

        html,
        body {

            padding: 0;
            margin: 0;

            width: 100%;
            height: 100%;

            background: #000;

            overflow: hidden;
        }

        #ani-player {

            width: 100% !important;
            height: 100% !important;

            overflow: hidden;

            background: #000;
        }

        #iframe-player {

            width: 100%;
            height: 100%;

            border: none;

            display: none;

            background: #000;
        }

        .download {

            background: #ff0000;

            padding: 10px;

            letter-spacing: 1px;

            box-shadow:
                0 1px 15px #ff0000;

            color: #fff;

            font-family:
                "Open-Sans",
                sans-serif;

            margin: 8px;

            border-radius: 19px;

            font-weight: bold;

            font-size: 11px;

            cursor: pointer;
        }

        #down,
        .down-list {

            position: absolute;
        }

        #down {

            z-index: 10;

            top: 0;

            right: 16px;
        }

        #down:hover > .down-list {

            display: block;
        }

        .down-list {

            display: none;

            list-style: none;

            left: 3px;

            z-index: 999999999;

            box-shadow:
                0 1px 15px #ff0000;

            background: #ff0000;

            margin: 0;

            border-radius: 40px;

            width: 144px;

            padding: 5px 0 0;
        }

        .down-list li {

            float: left;

            width: 134px;

            padding: 5px;

            text-align: center;

            margin-bottom: 5px;
        }

        .down-list li:hover {

            background: #0003;
        }

        .down-list li a {

            color: #fff;

            text-decoration: none;

            font-family:
                "Open-Sans",
                sans-serif;

            font-size: 18px;

            width: 100%;
        }

        .jw-icon.jw-icon-inline.jw-button-color.jw-reset.jw-icon-rewind {

            display: none;
        }

        .quality-float-container {

            position: fixed;

            bottom: 30px;
            left: 30px;

            z-index: 999999;

            display: flex;

            flex-direction: column;

            align-items: flex-start;

            gap: 8px;

            font-family:
                -apple-system,
                BlinkMacSystemFont,
                'Segoe UI',
                Roboto,
                sans-serif;
        }

        .quality-float-toggle {

            background:
                rgba(0, 0, 0, 0.85);

            backdrop-filter:
                blur(10px);

            -webkit-backdrop-filter:
                blur(10px);

            border:
                1px solid
                rgba(255,255,255,0.15);

            border-radius: 12px;

            padding: 10px 16px;

            color: white;

            cursor: pointer;

            display: flex;

            align-items: center;

            gap: 10px;

            font-size: 13px;

            font-weight: 500;

            transition: all .3s ease;

            box-shadow:
                0 8px 32px
                rgba(0,0,0,.4);

            user-select: none;
        }

        .quality-float-toggle:hover {

            background:
                rgba(30,30,30,.95);

            transform: scale(1.05);

            border-color:
                rgba(255,255,255,.3);
        }

        .quality-float-toggle .quality-badge {

            background:
                rgba(99,102,241,.3);

            padding: 2px 10px;

            border-radius: 20px;

            font-size: 11px;

            font-weight: 700;

            color: #a5b4fc;

            border:
                1px solid
                rgba(99,102,241,.2);
        }

        .quality-float-dropdown {

            background:
                rgba(0,0,0,.92);

            backdrop-filter:
                blur(10px);

            -webkit-backdrop-filter:
                blur(10px);

            border:
                1px solid
                rgba(255,255,255,.1);

            border-radius: 12px;

            padding: 12px;

            min-width: 200px;

            box-shadow:
                0 12px 40px
                rgba(0,0,0,.6);

            display: none;

            animation:
                slideUp .3s ease;
        }

        .quality-float-dropdown.show {

            display: block;
        }

        @keyframes slideUp {

            from {

                opacity: 0;

                transform:
                    translateY(10px);
            }

            to {

                opacity: 1;

                transform:
                    translateY(0);
            }
        }

        .quality-float-dropdown .quality-label {

            color:
                rgba(255,255,255,.6);

            font-size: 11px;

            text-transform: uppercase;

            letter-spacing: .5px;

            margin-bottom: 8px;

            display: block;

            font-weight: 600;
        }

        .quality-float-dropdown select {

            width: 100%;

            padding: 8px 12px;

            background:
                rgba(255,255,255,.08);

            border:
                1px solid
                rgba(255,255,255,.15);

            border-radius: 8px;

            color: white;

            font-size: 14px;

            font-weight: 500;

            cursor: pointer;

            outline: none;

            margin-bottom: 8px;
        }

        .quality-float-dropdown select option {

            background: #1a1a1a;

            color: white;
        }

        .quality-float-dropdown .btn-apply {

            width: 100%;

            padding: 8px;

            background: #6366f1;

            color: white;

            border: none;

            border-radius: 8px;

            font-weight: 600;

            font-size: 13px;

            cursor: pointer;
        }

        @media (max-width: 640px) {

            .quality-float-container {

                bottom: 20px;

                left: 20px;
            }

            .quality-float-toggle {

                padding: 8px 14px;

                font-size: 12px;
            }

            .quality-float-dropdown {

                min-width: 180px;

                padding: 10px;
            }
        }


        #playmoz-loader{position:fixed;inset:0;z-index:999999999;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:14px;background:#050505;transition:opacity .25s ease,visibility .25s ease}
        #playmoz-loader.hide{opacity:0;visibility:hidden;pointer-events:none}
        .pm-loader-ring{width:46px;height:46px;border:4px solid rgba(255,255,255,.12);border-top-color:#e50914;border-radius:50%;animation:pmspin .8s linear infinite}
        .pm-loader-text{color:#fff;font:600 13px Arial,sans-serif}
        .pm-loader-sub{color:#777;font:11px Arial,sans-serif}
        @keyframes pmspin{to{transform:rotate(360deg)}}

    </style>

</head>

<body>
<div id="playmoz-loader"><div class="pm-loader-ring"></div><div class="pm-loader-text">Carregando filme...</div><div class="pm-loader-sub">A preparar o player</div></div>





<div id="down">

    <div class="download">
        Espelhar/Baixar
    </div>

    <ul
        id="down-list"
        class="down-list"
    >

        <li>

            <a
                id="download-link"
                href="#"
                download
                target="_blank"
            >
                Clique aqui
            </a>

        </li>

    </ul>

</div>

<div id="ani-player"></div>

<iframe
    id="iframe-player"
    allowfullscreen
    allow="autoplay; encrypted-media">
</iframe>

<script src="../server-selector.js"></script>
<script>

var sourcesData = [];
var sourceEndpoint = <?= json_encode('/filme/?action=sources&id='.$id) ?>;

var currentPageUrl =
    window.location.href;

var player = null;

var returnKey =
    <?= json_encode(
        $returnKey,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) ?>;

var mediaId =
    <?= (int)$id ?>;

var mediaType = 'movie';
function hidePlayMozLoader(){
    var l=document.getElementById('playmoz-loader');
    if(l) l.classList.add('hide');
}


// ============================================================
// VOLTAR
// ============================================================

function goBackWithKey() {

    if (window.opener) {

        window.close();

        return;
    }

    if (window.PlayMozNav) {

        try {

            var returnUrl =
                window.PlayMozNav.getReturnUrl();

            if (
                returnUrl &&
                returnUrl !== window.location.href
            ) {

                window.location.href =
                    returnUrl;

                return;
            }

        } catch(e) {}
    }

    if (returnKey) {

        try {

            var historyData =
                JSON.parse(
                    sessionStorage.getItem(
                        'playmoz_nav_history'
                    ) || '{}'
                );

            if (
                historyData.returnKey ===
                returnKey
            ) {

                var idx =
                    historyData.history
                        ? historyData.history.indexOf(
                            window.location.href
                        )
                        : -1;

                if (idx > 0) {

                    window.location.href =
                        historyData.history[idx - 1];

                    return;
                }
            }

        } catch(e) {}
    }

    if (
        document.referrer &&
        document.referrer !==
        window.location.href
    ) {

        window.location.href =
            document.referrer;

        return;
    }

    window.location.href =
        '?page=home';
}

// ============================================================
// RETURN KEY
// ============================================================

if (
    !returnKey &&
    window.PlayMozNav
) {

    try {

        returnKey =
            window.PlayMozNav.generateReturnKey();

    } catch(e) {}
}

// ============================================================
// SALVAR PROGRESSO
// ============================================================

function saveVideoProgress(p) {

    try {

        var currentTime =
            p.getPosition();

        if (currentTime < 5) {
            return;
        }

        var videoKey =
            "videoProgress_" +
            btoa(
                mediaId +
                '_' +
                mediaType
            );

        Cookies.set(
            videoKey,
            currentTime,
            {
                expires: 7,
                path: '/'
            }
        );

        saveProgressToServer(
            mediaId,
            mediaType,
            currentTime
        );

    } catch(e) {}
}

// ============================================================
// SALVAR NO SERVIDOR
// ============================================================

function saveProgressToServer(
    id,
    type,
    progress
) {

    try {

        var xhr =
            new XMLHttpRequest();

        xhr.open(
            'POST',
            '?action=save_watch_progress',
            true
        );

        xhr.setRequestHeader(
            'Content-Type',
            'application/x-www-form-urlencoded'
        );

        xhr.send(
            'id=' +
            encodeURIComponent(id) +
            '&type=' +
            encodeURIComponent(type) +
            '&progress=' +
            encodeURIComponent(
                Math.round(progress)
            )
        );

    } catch(e) {}
}

// ============================================================
// CARREGAR PROGRESSO
// ============================================================

function loadVideoProgress(p) {

    try {

        var videoKey =
            "videoProgress_" +
            btoa(
                mediaId +
                '_' +
                mediaType
            );

        var savedTime =
            Cookies.get(videoKey);

        if (
            savedTime &&
            parseFloat(savedTime) > 10
        ) {

            Swal.fire({

                title:
                    "Continuar de onde parou?",

                text:
                    "Você parou em " +
                    formatTime(savedTime) +
                    ".",

                icon: "question",

                showCancelButton: true,

                confirmButtonText:
                    "Continuar",

                cancelButtonText:
                    "Recomeçar"

            }).then(function(result) {

                if (result.isConfirmed) {

                    p.seek(
                        parseFloat(savedTime)
                    );

                } else {

                    Cookies.remove(
                        videoKey
                    );
                }

            });
        }

    } catch(e) {}
}

// ============================================================
// FORMATAR TEMPO
// ============================================================

function formatTime(seconds) {

    seconds =
        parseFloat(seconds) || 0;

    var minutes =
        Math.floor(
            seconds / 60
        );

    var secs =
        Math.floor(
            seconds % 60
        );

    return (
        minutes +
        " min e " +
        secs +
        " seg"
    );
}

// ============================================================
// INICIAR PLAYER
// ============================================================


function setLoader(text, sub){
    var a=document.querySelector('.pm-loader-text'), b=document.querySelector('.pm-loader-sub');
    if(a && text) a.textContent=text;
    if(b && sub) b.textContent=sub;
}
function resolveSources(){
    setLoader('Carregando filme...','A preparar o player');
    return fetch(sourceEndpoint,{cache:'default',credentials:'same-origin'})
        .then(function(r){if(!r.ok) throw new Error('source');return r.json();})
        .then(function(data){
            sourcesData=(data&&Array.isArray(data.sources))?data.sources:[];
            PlayMozServers.start(sourcesData);
        })
        .catch(function(){
            setLoader('Preparando player...','Fonte alternativa');
            PlayMozServers.start([]);
        });
}

function initPlayer() {

    if (
        !sourcesData ||
        !Array.isArray(sourcesData) ||
        sourcesData.length === 0
    ) {

        loadIframeFallback();

        return;
    }

    var firstSource =
        sourcesData[0];

    if (
        !firstSource ||
        firstSource.type === 'iframe' ||
        !firstSource.file ||
        firstSource.file.indexOf('http') !== 0
    ) {

        loadIframeFallback(
            firstSource &&
            firstSource.file
                ? firstSource.file
                : null
        );

        return;
    }

    document.getElementById(
        'download-link'
    ).href =
        firstSource.file;

    var jwSources =
        sourcesData
            .filter(function(item) {

                return (
                    item &&
                    item.file &&
                    item.file.indexOf('http') === 0 &&
                    item.type !== 'iframe'
                );

            })
            .map(function(item) {

                return {

                    file: item.file,

                    label:
                        item.label ||
                        'AUTO',

                    type:
                        (
                            item.type === 'hls' ||
                            item.file.indexOf(
                                '.m3u8'
                            ) !== -1
                        )
                        ? 'hls'
                        : 'mp4'
                };

            });

    if (jwSources.length === 0) {

        loadIframeFallback(
            firstSource.file
        );

        return;
    }

    player =
        jwplayer(
            "ani-player"
        ).setup({

            sources:
                jwSources,

            tracks: [{

                file:
                    "https://vibra.ammsolucoes.online/legenda.srt",

                label:
                    "Português",

                kind:
                    "captions",

                default:
                    true
            }],

            captions: {

                color:
                    'yellow',

                fontSize:
                    '20px',

                fontFamily:
                    'Arial, sans-serif',

                backgroundOpacity:
                    0
            },

            aspectratio:
                "16:9",

            width:
                "100%",

            height:
                "100%",

            primary:
                "html5",

            autostart:
                false,

            image:
                <?= json_encode(
                    $poster_url,
                    JSON_UNESCAPED_SLASHES |
                    JSON_UNESCAPED_UNICODE
                ) ?>,

            playbackRateControls: [
                0.5,
                0.75,
                1,
                1.25,
                1.5,
                2
            ]
        });

    // ========================================================
    // PLAYER READY
    // ========================================================

    player.on(
        'ready',
        function() {

            hidePlayMozLoader();

            loadVideoProgress(
                player
            );

            // ------------------------------------------------
            // AVANÇAR 10S
            // ------------------------------------------------

            player.addButton(

                '<svg xmlns="http://www.w3.org/2000/svg" class="jw-svg-icon" viewBox="0 0 240 240"><path d="m25.99 57.778v125.3c.036 2.636 2.164 4.764 4.8 4.8h62.7v-19.3h-48.2v-96.4h115.7v19.3c0 5.3 3.6 7.2 8 4.3l41.8-27.9c2.936-1.48 4.138-5.044 2.7-8-.575-1.175-1.525-2.125-2.7-2.7l-41.8-27.9c-4.4-2.9-8-1-8 4.3v19.3H30.894c-2.69.04-4.86 2.21-4.9 4.9z"/></svg>',

                "Avançar 10s",

                function() {

                    if (player) {

                        player.seek(
                            player.getPosition() +
                            10
                        );
                    }

                },

                "Avançar 10s"
            );

            // ------------------------------------------------
            // VOLTAR 10S
            // ------------------------------------------------

            player.addButton(

                '<svg xmlns="http://www.w3.org/2000/svg" class="jw-svg-icon" viewBox="0 0 240 240"><path d="M113.2 131.078a21.589 21.589 0 0 0-17.7-10.6 21.589 21.589 0 0 0-17.7 10.6 44.769 44.769 0 0 0 0 46.3 21.589 21.589 0 0 0 17.7 10.6 21.589 21.589 0 0 0 17.7-10.6 44.769 44.769 0 0 0 0-46.3z"/></svg>',

                "Voltar 10s",

                function() {

                    if (player) {

                        player.seek(
                            Math.max(
                                0,
                                player.getPosition() -
                                10
                            )
                        );
                    }

                },

                "Voltar 10s"
            );

        }
    );

    // ========================================================
    // TEMPO
    // ========================================================

    player.on(
        'time',
        function() {

            saveVideoProgress(
                player
            );

        }
    );

    // ========================================================
    // ERRO
    // ========================================================

    player.on(
        'error',
        function() {

            var tryButton =
                document.getElementById(
                    'btn_try'
                );



            PlayMozServers.error();
        }
    );
}

// ============================================================
// IFRAME FALLBACK
// ============================================================

function loadIframeFallback(url) {

    var fallbackUrl =
        url ||
        'https://playerflixapi.com/filme/<?= (int)$id ?>';

    document.getElementById(
        'ani-player'
    ).style.display =
        'none';

    document.getElementById(
        'down'
    ).style.display =
        'none';

    var iframe =
        document.getElementById(
            'iframe-player'
        );

    iframe.src =
        fallbackUrl;

    iframe.style.display =
        'block';
    hidePlayMozLoader();
}

// ============================================================
// QUALIDADE
// ============================================================

function toggleQualityDropdown() {

    var dropdown =
        document.getElementById(
            'quality-dropdown'
        );

    if (dropdown) {

        dropdown.classList.toggle(
            'show'
        );
    }
}

function applyQualityChange() {

    var select =
        document.getElementById(
            'quality-select'
        );

    var badge =
        document.getElementById(
            'current-quality-badge'
        );

    if (
        !select ||
        !badge
    ) {
        return;
    }

    var selected =
        select.value;

    badge.textContent =
        selected;

    toggleQualityDropdown();

    if (player) {

        try {

            player.play();

        } catch(e) {}
    }
}

// ============================================================
// TECLADO
// ============================================================

document.addEventListener(
    'keydown',
    function(e) {

        if (
            e.key === 'Backspace' ||
            e.key === 'Escape'
        ) {

            e.preventDefault();

            goBackWithKey();
        }

    }
);

// ============================================================
// DOM READY
// ============================================================

document.addEventListener(
    'DOMContentLoaded',
    function() {

        resolveSources();

        if (
            !returnKey &&
            window.PlayMozNav
        ) {

            try {

                returnKey =
                    window.PlayMozNav
                        .generateReturnKey();

            } catch(e) {}
        }

    }
);

// ============================================================
// POPSTATE
// ============================================================

window.addEventListener(
    'popstate',
    function(e) {

        e.preventDefault();

        goBackWithKey();

    }
);

</script>


</body>

</html>
<?php
    exit;
}

// ============================================================
// REDIRECT PARA PLAYER
// ============================================================

$redirectUrl =
    '?id=' .
    $id .
    '&play=1';

// RETURN KEY

if ($returnKey !== '') {

    $redirectUrl .=
        '&return=' .
        rawurlencode(
            $returnKey
        );
}

// TÍTULO

if ($incomingTitle !== '') {

    $redirectUrl .=
        '&pm_title=' .
        rawurlencode(
            $incomingTitle
        );
}

// POSTER

if ($incomingPoster !== '') {

    $redirectUrl .=
        '&pm_poster=' .
        rawurlencode(
            $incomingPoster
        );
}

// ============================================================
// REDIRECT
// ============================================================

header(
    'Location: ' .
    $redirectUrl,
    true,
    302
);

exit;
?>