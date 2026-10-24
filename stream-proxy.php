<?php
// PlayMoz same-origin authenticated stream relay; requires active permission upstream.
require_once __DIR__ . '/xtream-adapter.php';
function pm_proxy_error(int $code, string $message): void {
    http_response_code($code); header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store'); echo $message; exit;
}
$token=(string)($_GET['t'] ?? '');
$url=pm_proxy_decode($token);
if (!$url) pm_proxy_error(403,'Link do proxy inválido ou expirado');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','HEAD'],true)) pm_proxy_error(405,'Método não permitido');
function pm_proxy_fetch(string $url, int $redirects=0): void {
    if ($redirects>3 || !pm_proxy_allowed($url)) pm_proxy_error(403,'Origem do stream não autorizada');
    // Relay endpoints with PHP paths may also return HLS: detect from content-type.
    $chunks=''; $sent=false; $status=0; $ct=''; $location=''; $contentRange=''; $contentLength='';
    $ch=curl_init($url);
    $range=$_SERVER['HTTP_RANGE'] ?? '';
    $reqHeaders=['Origin: https://playmoz.xyz','X-Domain: playmoz.xyz','Referer: https://playmoz.xyz/','Accept: */*'];
    if (preg_match('/^bytes=\d*-\d*$/',$range)) $reqHeaders[]='Range: '.$range;
    curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>90,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>$reqHeaders,CURLOPT_ENCODING=>'identity',CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$status,&$ct,&$location,&$contentRange,&$contentLength){
        if(preg_match('#^HTTP/\S+\s+(\d+)#',$line,$m)){$status=(int)$m[1];$ct='';$location='';$contentRange='';$contentLength='';}
        if(stripos($line,'Content-Type:')===0)$ct=trim(substr($line,13));
        if(stripos($line,'Location:')===0)$location=trim(substr($line,9));
        if(stripos($line,'Content-Range:')===0)$contentRange=trim(substr($line,14));
        if(stripos($line,'Content-Length:')===0)$contentLength=trim(substr($line,15));
        return strlen($line);
    },CURLOPT_WRITEFUNCTION=>function($ch,$data)use(&$chunks,&$sent,&$status,&$ct,&$contentRange,&$contentLength,$url){
        $hls=(bool)preg_match('/mpegurl|application\/x-mpegurl/i',$ct);
        if(in_array($status,[301,302,303,307,308],true)) return strlen($data);
        if($hls){$chunks.=$data; return strlen($data)<=0?0:(strlen($chunks)>2097152?0:strlen($data));}
        if(!$sent){
            http_response_code($status?:502);
            header('Content-Type: '.($ct?:'application/octet-stream'));
            header('Cache-Control: private, no-store');
            header('Accept-Ranges: bytes');
            if($contentRange)header('Content-Range: '.$contentRange);
            if($contentLength)header('Content-Length: '.$contentLength);
            $sent=true;
        }
        echo $data;
        if(function_exists('flush')) flush();
        return strlen($data);
    }]);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD') curl_setopt($ch,CURLOPT_NOBODY,true);
    $ok=curl_exec($ch); $error=curl_error($ch); $status=curl_getinfo($ch,CURLINFO_HTTP_CODE) ?: $status;
    curl_close($ch);
    if($location && in_array($status,[301,302,303,307,308],true) && !$sent){
        $next=pm_proxy_absolute($url,$location); pm_proxy_fetch($next,$redirects+1); return;
    }
    if($chunks!=='' && preg_match('/mpegurl|application\/x-mpegurl/i',$ct)){
        if(strpos(ltrim($chunks),'#EXTM3U')!==0)pm_proxy_error(502,'Playlist HLS inválida');
        http_response_code($status?:200);header('Content-Type: application/vnd.apple.mpegurl');header('Cache-Control: private, no-store');
        echo pm_proxy_rewrite($chunks,$url);return;
    }
    if(!$sent){http_response_code($status?:502);header('Content-Type: '.($ct?:'text/plain')); if($error) echo 'Falha na origem: '.$error;}
}
function pm_proxy_rewrite(string $text,string $base): string {
    // Rewrite plain URI lines and URI="..." attributes for HLS keys, maps, variants, segments.
    $text=preg_replace_callback('/URI="([^"]+)"/',static function($m)use($base){$target=pm_proxy_absolute($base,$m[1]);return pm_proxy_allowed($target)?'URI="'.pm_proxy_link($target).'"':$m[0];},$text);
    return preg_replace_callback('/^(?!\s*#)([^\r\n]+)$/m',static function($m)use($base){$value=trim($m[1]);if($value==='')return $m[0];$target=pm_proxy_absolute($base,$value);return pm_proxy_allowed($target)?pm_proxy_link($target):$m[0];},$text);
}
pm_proxy_fetch($url);
