# PlayMoz Embed — MegaEmbed Xtream

Esta versão mantém as URLs públicas por ID TMDB (`/movie/550`, `/tv/ID/T/E`), a interface, o JW Player e a seleção de servidores. As fontes são obtidas da API Xtream, não do scraping HTML de mgeb.top.

## Como funciona

- Filmes: `get_vod_streams` -> comparação com `tmdb_id` -> `sources` ou `direct_source`.
- Séries: `get_series` -> comparação com `tmdb_id` -> `get_series_info` -> temporada e número de episódio -> `sources` ou `direct_source`.
- Cache JSON das respostas da API por duas horas em `.xtream-cache`, protegido por `.htaccess`.
- Tanto as páginas principais como as da pasta `/A` utilizam o adaptador único `xtream-adapter.php`.
- Os players continuam a utilizar URLs de reprodução internamente. Não são adicionadas listagens de links de Play na interface.

## Instalação

Publique os ficheiros no mesmo diretório do projeto PHP original. PHP com cURL é necessário. Configure o ambiente `XTREAM_API` com a URL Xtream completa para alterar as credenciais sem editar ficheiros. A URL atual está como valor padrão em `xtream-adapter.php` para o teste inicial e DEVE ser substituída ou removida antes de publicar o código em repositórios.

A plataforma MegaEmbed deve autorizar a origem `https://playmoz.xyz`.

## Limitações

O endpoint Xtream pode retornar filmes/séries sem `tmdb_id` correspondente, `get_series_info` como `{}`, fontes HTTP 404 ou outros erros. Nesse caso, não haverá fonte reproduzível; a interface original continua disponível. O cache precisa de permissão de escrita para a pasta `.xtream-cache`. HLS exige suporte do player e CORS apropriado nos recursos de mídia.

Este pacote foi validado com `php -l`. A reprodução real no domínio e a correspondência exata de todos os IDs TMDB não foram verificadas remotamente.
