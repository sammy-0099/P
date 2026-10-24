# PlayMoz Stream Proxy

Instale stream-proxy.php e xtream-adapter.php na raiz do mesmo domínio playmoz.xyz, com os diretórios filme/, serie/ e A/.
As fontes retornadas pelos endpoints AJAX agora usam URLs locais assinadas por 2 horas. O proxy usa Origin e X-Domain playmoz.xyz na consulta upstream, reescreve playlists HLS e encaminha segmentos.

ATENÇÃO: Proxy não concede permissões e não contorna bloqueios de subscrição, origem ou restrições do provedor. Somente links HTTPS de mgeb.lat são aceites. Se o HLS remeter a CDN externa, é necessário autorizar explicitamente os hosts legítimos no código e junto do provedor.

IMPORTANTE: em outros domínios altere URL de retorno em pm_proxy_link e autorize esse domínio no painel MegaEmbed. Os tokens expiram em duas horas; após expiração atualize a página.

Mantenha a senha Xtream em XTREAM_API na configuração do servidor e renove a credencial previamente exposta. Certifique-se de dispor dos direitos para redistribuir streams.
