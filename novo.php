<?php
/**
 * net-inspect-mp4.php — DevTools Network em tempo real
 * Para a transferência assim que encontra um link .mp4
 *
 * Uso: php net-inspect-mp4.php "https://hyper.hyperapps.site/series/visionquest/temporada/1/episodio/2"
 */

$url = $argv[1] ?? 'https://hyper.hyperapps.site/series/visionquest/temporada/1/episodio/2';

$C = [
    'reset' => "\033[0m", 'dim' => "\033[2m", 'bold' => "\033[1m",
    'info'  => "\033[36m", 'ok'  => "\033[32m", 'warn' => "\033[33m",
    'erro'  => "\033[31m", 'mag' => "\033[35m",
];

function line($label, $value, $cor = 'info') {
    global $C;
    printf("%s%-22s%s %s\n", $C[$cor], $label, $C['reset'], $value);
}
function titulo($txt) {
    global $C;
    echo "\n{$C['bold']}{$C['mag']}▶ $txt{$C['reset']}\n";
    echo str_repeat('─', 70) . "\n";
}

// ---------- variáveis compartilhadas ----------
$blocos = [];
$blocoAtual = [];
$body = '';
$mp4Achado = null;
$bytesRecebidos = 0;
$abortado = false;

// Buffer de busca: mantemos os últimos N bytes entre chunks
// pra não perder um link que ficou "cortado" entre dois chunks
$bufferBusca = '';
const TAM_BUFFER = 4096;

// Regex pra achar mp4 / m3u8 / webm (com ou sem query string)
// Aceita: "file.mp4", "file.mp4?token=x", URLs escapadas em JSON (\u002F etc)
$regexVideo = '#https?:(?:\\\\?/){2}[^"\'\s<>\\\\]+?\.(?:mp4|m3u8|webm|mpd)(?:\?[^"\'\s<>\\\\]*)?#i';
$regexVideoRelativo = '#["\']([^"\']+?\.(?:mp4|m3u8|webm|mpd)(?:\?[^"\']*)?)["\']#i';

// ---------- cURL ----------
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER   => false,   // usamos WRITEFUNCTION
    CURLOPT_FOLLOWLOCATION   => true,
    CURLOPT_MAXREDIRS        => 10,
    CURLOPT_TIMEOUT          => 60,
    CURLOPT_CONNECTTIMEOUT   => 10,
    CURLOPT_ENCODING         => '',
    CURLOPT_USERAGENT        => 'Mozilla/5.0 (PHP net-inspect)',
    CURLOPT_HEADERFUNCTION   => function ($ch, $linha) use (&$blocos, &$blocoAtual) {
        $len  = strlen($linha);
        $trim = rtrim($linha, "\r\n");
        if (preg_match('#^HTTP/#', $trim)) {
            if (!empty($blocoAtual)) $blocos[] = $blocoAtual;
            $blocoAtual = ['status' => $trim, 'headers' => []];
            echo "\n{$GLOBALS['C']['ok']}← {$trim}{$GLOBALS['C']['reset']}\n";
        } elseif (strpos($trim, ':') !== false) {
            [$k, $v] = explode(':', $trim, 2);
            $blocoAtual['headers'][] = [trim($k), trim($v)];
            if (strcasecmp(trim($k), 'Content-Length') === 0) {
                line('Content-Length', trim($v), 'dim');
            } elseif (strcasecmp(trim($k), 'Content-Type') === 0) {
                line('Content-Type', trim($v), 'dim');
            }
        }
        return $len;
    },
    CURLOPT_WRITEFUNCTION    => function ($ch, $dados) use (&$body, &$mp4Achado, &$bytesRecebidos, &$bufferBusca, &$abortado, $regexVideo, $regexVideoRelativo) {
        $bytesRecebidos += strlen($dados);
        $body .= $dados;

        // progresso em tempo real (a cada ~20KB)
        static $ultimoPrint = 0;
        if ($bytesRecebidos - $ultimoPrint >= 20480) {
            $ultimoPrint = $bytesRecebidos;
            echo "\r  {$GLOBALS['C']['dim']}recebidos: " . round($bytesRecebidos/1024, 1) . " KB{$GLOBALS['C']['reset']}   ";
        }

        // Junta buffer + chunk atual pra busca
        $texto = $bufferBusca . $dados;

        // Busca URL absoluta
        if (preg_match($regexVideo, $texto, $m)) {
            $mp4Achado = $m[0];
            $abortado = true;
            return defined('CURL_WRITEFUNC_ERROR') ? CURL_WRITEFUNC_ERROR : 0;
        }

        // Busca URL relativa
        if (preg_match($regexVideoRelativo, $texto, $m)) {
            $rel = $m[1];
            // Se já for URL absoluta, usa direto; senão resolve contra a URL base
            if (!preg_match('#^https?://#i', $rel)) {
                $base = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $p = parse_url($base);
                if (strpos($rel, '//') === 0) {
                    $rel = $p['scheme'] . ':' . $rel;
                } elseif (strpos($rel, '/') === 0) {
                    $rel = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $rel;
                } else {
                    $dir = rtrim(dirname($p['path'] ?? '/'), '/');
                    $rel = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $dir . '/' . ltrim($rel, '/');
                }
            }
            $mp4Achado = $rel;
            $abortado = true;
            return defined('CURL_WRITEFUNC_ERROR') ? CURL_WRITEFUNC_ERROR : 0;
        }

        // Mantém final do texto no buffer pro próximo chunk
        if (strlen($texto) > TAM_BUFFER) {
            $bufferBusca = substr($texto, -TAM_BUFFER);
        } else {
            $bufferBusca = $texto;
        }
        return strlen($dados);
    },
]);

$inicio = microtime(true);
echo "\n{$C['bold']}→ GET {$url}{$C['reset']}\n";
$ok = curl_exec($ch);
$tempoTotal = round((microtime(true) - $inicio) * 1000, 2);
$info = curl_getinfo($ch);
$erro = curl_error($ch);
$errno = curl_errno($ch);
curl_close($ch);
if (!empty($blocoAtual)) $blocos[] = $blocoAtual;

echo "\r" . str_repeat(' ', 60) . "\r"; // limpa linha de progresso

// ---------- resultado ----------
if ($mp4Achado) {
    echo "\n{$C['bold']}{$C['ok']}🎬 VÍDEO ENCONTRADO — TRANSFERÊNCIA INTERROMPIDA{$C['reset']}\n";
    echo str_repeat('═', 70) . "\n";
    line('Status',        $abortado ? 'ABORTADO no download' : 'completo', 'warn');
    line('Bytes recebidos', round($bytesRecebidos/1024, 2) . ' KB');
    line('Tempo até achar', $tempoTotal . ' ms');
    line('Status HTTP',   $info['http_code'], $info['http_code'] < 400 ? 'ok' : 'erro');
    line('URL da página',  $info['url']);
    echo "\n{$C['bold']}URL do vídeo:{$C['reset']}\n";
    echo "  {$C['ok']}{$mp4Achado}{$C['reset']}\n\n";

    // salva resultado
    file_put_contents('video_url.txt', $mp4Achado . "\n");
    line('Salvo em',     realpath('video_url.txt'));
    exit(0);
}

if ($erro) {
    line('ERRO cURL', "($errno) $erro", 'erro');
    exit(1);
}

// ---------- nenhum mp4 achado ----------
echo "\n{$C['warn']}⚠ Nenhum link .mp4 / .m3u8 / .webm encontrado na página.{$C['reset']}\n\n";

titulo('RESUMO');
line('Status HTTP',   $info['http_code']);
line('Tamanho baixado', round($bytesRecebidos/1024, 2) . ' KB');
line('Tempo total',   $tempoTotal . ' ms');
line('Content-Type',  $info['content_type']);

if (!empty($blocos)) {
    titulo('HEADERS DA RESPOSTA FINAL');
    foreach (end($blocos)['headers'] as [$k, $v]) {
        printf("  %s%-28s%s %s\n", $C['dim'], $k . ':', $C['reset'], $v);
    }
}

// Salva body pra inspeção (útil se o vídeo for carregado via JS/API)
file_put_contents('body_debug.html', $body);
echo "\n{$C['dim']}Body salvo em: " . realpath('body_debug.html') . "{$C['reset']}\n";
echo "{$C['dim']}Dica: se a página carrega vídeo via JS/XHR, rode em outra aba e depois tente a URL da API.{$C['reset']}\n";