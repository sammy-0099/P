PLAYMOZ - INSTALAÇÃO DO PAINEL PHP

1. Aloje a pasta nexa-painel no site nexaacapital.xo.je com nome painel-jogos.
2. Gere uma hash num PHP local/servidor com: php -r "echo password_hash('MINHA_SENHA_FORTE', PASSWORD_DEFAULT), PHP_EOL;"
3. Substitua SUBSTITUA_PELO_HASH_GERADO no config.php pela hash gerada.
4. Certifique-se de que a pasta data/ tem permissão de escrita pelo PHP e de que o .htaccess bloqueia acesso direto.
5. Aceda a https://nexaacapital.xo.je/painel-jogos/ e faça login.
6. JSON público: https://nexaacapital.xo.je/painel-jogos/data-public.php
7. O Node PlayMoz já vem configurado para ler esse endereço. Se o alojamento utilizar outro caminho, configure PLAYMOZ_GAMES_JSON_URL.
8. Horários: no painel indique a hora local de Moçambique. Internamente é guardado UNIX timestamp; no PlayMoz adapta-se ao fuso horário do visitante.
9. Os eventos da EmbedTV só serão importáveis caso o URL disponibilize JSON válido para o servidor PHP.
10. Use apenas transmissões que está autorizado a distribuir/incorporar.
NOTA: A instalação não foi feita no domínio remoto; faça upload da pasta via gestor de ficheiros/FTP.
