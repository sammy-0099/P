# PlayMoz — correções de carregamento e autorização

- O HTML do player de filmes é entregue sem esperar pela consulta TMDB. O carregamento e as fontes continuam por AJAX.
- Os pedidos Xtream feitos pelo adaptador PHP enviam Origin: https://playmoz.xyz, X-Domain: playmoz.xyz e Referer: https://playmoz.xyz/.
- Na versão A/, a consulta AJAX das fontes usa token assinado emitido após o iframe passar pela validação já existente. Não remove a verificação de domínios autorizados.
- Timeout da consulta Xtream reduzido para 18s e cache de catálogo mantido em 2 horas.

## Importante
A chamada AJAX do player pode funcionar com origem autorizada, mas o pedido de vídeo feito diretamente pelo navegador segue a origem real do site onde está incorporado. Cabeçalhos definidos no PHP para consultar a API não mudam os cabeçalhos que o navegador envia ao servidor do vídeo. Cadastre também o domínio real do frontend no painel MegaEmbed. Restrições de plano Premium, 403 e fontes inexistentes não podem ser corrigidas por cabeçalhos.

Em produção, use variável de ambiente XTREAM_API para armazenar credenciais. Renove a senha anteriormente exposta. Substitua PM_EMBED_GATE_SECRET em A/config.php por segredo aleatório forte.

Verificações realizadas: php -l nos ficheiros alterados. Não foi possível comprovar reprodução ponta a ponta nesta execução.
