<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
$file=__DIR__.'/data/jogos.json';
$json=@file_get_contents($file);
$data=json_decode((string)$json,true);
echo json_encode(is_array($data)?array_values($data):[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
