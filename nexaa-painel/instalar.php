<?php
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$file=__DIR__.'/data/admin.php';
$config=require __DIR__.'/config.php';
$ready=is_file($file) || (!empty($config['password_hash']) && $config['password_hash']!=='SUBSTITUA_PELO_HASH_GERADO');
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST' && !$ready) {
    $pass=(string)($_POST['password']??'');
    $confirm=(string)($_POST['confirm']??'');
    if (strlen($pass)<12) $error='A palavra-passe deve ter pelo menos 12 caracteres.';
    elseif ($pass!==$confirm) $error='As palavras-passe não coincidem.';
    else {
        $hash=password_hash($pass,PASSWORD_DEFAULT);
        $fh=@fopen($file,'x'); // Criação exclusiva: só uma instalação possível.
        if (!$fh) $error='Não foi possível guardar. Verifique as permissões da pasta data.';
        else {
            fwrite($fh,"<?php\n// Apenas para leitura interna, não partilhar.\nreturn ".var_export($hash,true).";\n");
            fclose($fh);
            @chmod($file,0600);
            header('Location: index.php');
            exit;
        }
    }
}
function h($value){return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="pt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PLAYMOZ • Configurar acesso</title><style>body{background:#08121e;color:#fff;font:16px system-ui;margin:0;padding:20px}.panel{max-width:470px;margin:9vh auto;padding:28px;background:#142638;border:1px solid #385064;border-radius:16px}input,button{box-sizing:border-box;width:100%;padding:12px;margin:8px 0 18px;border-radius:8px;font:inherit}input{background:#091624;border:1px solid #4e6578;color:#fff}button{background:#17b2cf;color:#04111a;border:0;font-weight:bold;cursor:pointer}a{color:#7bdcec}.error{color:#ff9ba5}</style></head><body><main class="panel"><h1>PLAYMOZ</h1><h2>Configurar acesso</h2><?php if($ready):?><p>O painel já tem uma palavra-passe configurada.</p><a href="index.php">Entrar no painel</a><?php else:?><?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?><p>Cria a tua palavra-passe de administrador (mínimo 12 caracteres). Faz esta configuração logo após publicar os ficheiros.</p><form method="post"><label>Nova palavra-passe</label><input type="password" name="password" minlength="12" autocomplete="new-password" required><label>Confirmar palavra-passe</label><input type="password" name="confirm" minlength="12" autocomplete="new-password" required><button type="submit">Criar acesso</button></form><?php endif;?></main></body></html>
