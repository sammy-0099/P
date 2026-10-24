<?php
// MegaEmbed Xtream adapter - server side only. Only catalog/source resolution changes.
// Use XTREAM_API environment variable in production to override credentials.
const PM_XTREAM_ORIGIN = 'https://playmoz.xyz';
const PM_XTREAM_TTL = 7200;
function pm_xtream_url(): string {
    $env = getenv('XTREAM_API');
    return ($env && filter_var($env, FILTER_VALIDATE_URL)) ? $env : 'https://mgeb.lat/player_api.php?username=Feljo&password=22109f3c5de3aa6c';
}
function pm_xtream_cache(string $key, callable $load): array {
    $dir = __DIR__ . '/.xtream-cache';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $path = $dir . '/' . hash('sha256', $key) . '.json';
    if (is_file($path) && time() - filemtime($path) < PM_XTREAM_TTL) {
        $result = json_decode((string)@file_get_contents($path), true);
        if (is_array($result)) return $result;
    }
    $result = $load();
    if ($result && is_dir($dir)) @file_put_contents($path, json_encode($result, JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $result;
}
function pm_xtream_query(string $action, array $params = []): array {
    $key = $action . ':' . json_encode($params);
    return pm_xtream_cache($key, static function() use ($action, $params) {
        $url = pm_xtream_url();
        $sep = strpos($url, '?') === false ? '?' : '&';
        $url .= $sep . http_build_query(array_merge(['action'=>$action], $params));
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>18, CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_HTTPHEADER=>['Origin: '.PM_XTREAM_ORIGIN, 'X-Domain: playmoz.xyz', 'Referer: https://playmoz.xyz/', 'Accept: application/json']]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !is_string($body)) return [];
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    });
}
function pm_xtream_find(string $kind, int $tmdbId): ?array {
    static $found = [];
    $cacheKey = $kind . ":" . $tmdbId;
    if (array_key_exists($cacheKey, $found)) return $found[$cacheKey];
    $items = pm_xtream_query($kind === 'movie' ? 'get_vod_streams' : 'get_series');
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        if ((string)($item['tmdb_id'] ?? '') === (string)$tmdbId && $tmdbId > 0) return $found[$cacheKey] = $item;
    }
    // Existing routes use TMDB ids, but permit direct Xtream ids as a convenience.
    $idField = $kind === 'movie' ? 'stream_id' : 'series_id';
    foreach ($items as $item) {
        if (is_array($item) && (string)($item[$idField] ?? '') === (string)$tmdbId) return $found[$cacheKey] = $item;
    }
    return $found[$cacheKey] = null;
}
function pm_xtream_sources(array $record, string $kind, ?int $episodeId = null): array {
    $candidates = $record['sources'] ?? [];
    $result = [];
    if (is_array($candidates)) foreach ($candidates as $item) {
        if (!is_array($item)) continue;
        $u = (string)($item['url'] ?? '');
        if (!filter_var($u, FILTER_VALIDATE_URL) || !in_array(parse_url($u, PHP_URL_SCHEME), ['https','http'], true)) continue;
        $format = strtolower((string)($item['format'] ?? $record['container_extension'] ?? ''));
        $type = in_array($format, ['m3u8','txt','hls'], true) || preg_match('/\\.(m3u8|txt)(?:\\?|$)/i', $u) ? 'hls' : 'mp4';
        $result[] = ['file'=>$u,'type'=>$type,'label'=>(string)($item['name'] ?? 'Servidor '.(count($result)+1))];
    }
    if (!$result) {
        $u = (string)($record['direct_source'] ?? '');
        if (!filter_var($u, FILTER_VALIDATE_URL)) {
            $api = parse_url(pm_xtream_url());
            $query = []; parse_str($api['query'] ?? '', $query);
            $user = $query['username'] ?? ''; $pass = $query['password'] ?? '';
            $id = $episodeId ?? ($record['stream_id'] ?? null);
            $ext = $record['container_extension'] ?? '';
            if ($user !== '' && $pass !== '' && $id && $ext) {
                $u = 'https://' . ($api['host'] ?? 'mgeb.lat') . '/' . ($kind === 'movie' ? 'movie' : 'series') . '/' . rawurlencode($user) . '/' . rawurlencode($pass) . '/' . rawurlencode((string)$id) . '.' . rawurlencode((string)$ext);
            }
        }
        if (filter_var($u, FILTER_VALIDATE_URL)) {
            $type = preg_match('/\\.(m3u8|txt)(?:\\?|$)/i', $u) || in_array(($record['container_extension'] ?? ''), ['m3u8','txt'], true) ? 'hls' : 'mp4';
            $result[] = ['file'=>$u,'type'=>$type,'label'=>'Servidor 1'];
        }
    }
    return $result;
}
function pm_xtream_resolve_movie(int $tmdbId): array {
    $item = pm_xtream_find('movie', $tmdbId);
    if (!$item) return ['sources'=>[], 'first'=>null, 'first_type'=>'mp4'];
    $sources = pm_xtream_sources($item, 'movie');
    return ['sources'=>$sources, 'first'=>$sources[0]['file'] ?? null, 'first_type'=>$sources[0]['type'] ?? 'mp4'];
}
function pm_xtream_resolve_series(int $tmdbId, int $season, int $episode): array {
    $item = pm_xtream_find('series', $tmdbId);
    if (!$item) return ['sources'=>[], 'first'=>null, 'first_type'=>'mp4'];
    $details = pm_xtream_query('get_series_info', ['series_id'=>(string)$item['series_id']]);
    $episodes = $details['episodes'][(string)$season] ?? [];
    foreach ($episodes as $ep) {
        if ((int)($ep['episode_num'] ?? -1) !== $episode) continue;
        $sources = pm_xtream_sources($ep, 'series', (int)($ep['id'] ?? 0));
        return ['sources'=>$sources,'first'=>$sources[0]['file'] ?? null,'first_type'=>$sources[0]['type'] ?? 'mp4'];
    }
    return ['sources'=>[], 'first'=>null, 'first_type'=>'mp4'];
}
