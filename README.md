# LouvorJA API — Cloudflare Workers

Substituição da API PHP do LouvorJA. O Worker mantém os contratos HTTP usados
pelos apps, o painel administra o catálogo no D1 e o R2 guarda a mídia e os
arquivos que os apps leem.

- API: `https://api.louvorja.com.br` e `https://api.louvorja.workers.dev`
  (mesmo catálogo e mesmos formatos de mídia)
- Swagger UI: `/documentation` (um caminho que nenhuma rota atende responde 404 com
  `"error": "Caminho não encontrado"` e o endereço dela)
- OpenAPI: `/openapi.json`
- Painel: `https://adm.louvorja.com.br`, no repositório
  [`louvorja/adm`](https://github.com/louvorja/adm) (GitHub Pages). Ele chama
  esta API por `https://api.louvorja.com.br`.

Tudo roda no plano gratuito da Cloudflare. As decisões abaixo existem para
caber nele; mude-as sabendo o que cada uma protege.

## Arquitetura

| Parte | Responsabilidade |
| --- | --- |
| Worker + Hono | Rotas públicas, autenticação, CRUD administrativo e despacho de tarefas |
| D1 | Fonte da verdade do catálogo, usuários e auditoria — usado só pelo painel |
| R2 | Mídia, JSONs públicos, pacotes ZIP e parâmetros — o que os apps leem |
| GitHub Actions | Jobs longos: publicação, reconciliação, YouTube e importação de slides |

O caminho de um dado: o painel grava no D1; a publicação exporta o D1, gera os
arquivos e os envia ao R2; os apps leem do R2 através do Worker.

### As rotas públicas não tocam o D1

No plano gratuito o D1 para de responder quando a cota diária estoura (5
milhões de linhas lidas, 100 mil escritas), e as rotas públicas não têm
autenticação. Por isso:

- leitura: toda rota pública responde a partir dos snapshots do R2, inclusive
  `/{lang}/albums/category/{slug}`, `/{lang}/collections/online` e `/onlinevideos`;
- escrita: a única exceção é o registro de `/download`, limitado a 500 por dia
  por um contador (`public_write_budget`). Passado o teto, nada mais é gravado.

`test/legacy-routes.test.ts` falha se uma rota pública voltar a consultar o
banco. `/health` faz um `SELECT 1`, que não lê linha nenhuma.

### Cache

Cada resposta sem cache é uma requisição a mais no teto diário de 100 mil do
plano gratuito (resposta `304` também conta). O catálogo fica em cache no
cliente; respostas iguais nos dois domínios também podem ficar na borda:

| Rotas | Cache |
| --- | --- |
| `/json_db/*`, `/db/bible-bundle` | um dia |
| `/db/*`, `/{lang}/*`, `/params`, `/onlinevideos` | uma hora |
| `/file/*` | um ano (nome estável); redirecionamentos: uma hora |
| `/auth/*`, `/admin/*`, `/tasks/*`, `/health`, erros | sem cache |

O app acrescenta a data à URL de `/json_db` e um carimbo único quando o
operador pede para atualizar, então o cache não segura conteúdo velho além do
dia.

### Limpeza automática de `/params`

Ao final de cada manutenção, `scripts/purge-params-cache.ts` limpa o prefixo
`api.louvorja.com.br/params` no cache da Cloudflare. Isso inclui as variantes
JSON e INI (`?type=env`) e demais query strings, depois das gravações no R2.
A limpeza é restrita a esse prefixo; não limpa o restante do site.

Em **Settings → Secrets and variables → Actions** deste repositório, configure:

- variável `CLOUDFLARE_ZONE_ID`: Zone ID de **louvorja.com.br**, disponível na
  página inicial da zona na Cloudflare (não é o Account ID);
- segredo `CLOUDFLARE_CACHE_PURGE_TOKEN`: token dedicado com permissão
  **Zone → Cache Purge**, restrito à zona **louvorja.com.br**.

Sem essa configuração ou se a Cloudflare recusar a limpeza, o passo falha com
uma mensagem explícita. Os arquivos já publicados permanecem no R2. O passo
roda também após falhas parciais e em manutenções sem alterações, para repetir
uma limpeza pendente; não roda após cancelamento. A limpeza não depende de
novo deploy do Worker e não remove cópias já guardadas no navegador dos apps.

Para executar manualmente com as variáveis exportadas no ambiente:

```bash
node --env-file-if-exists=.env --experimental-strip-types scripts/purge-params-cache.ts
node --experimental-strip-types --test test/params-cache.node.ts
```

### O programa Delphi

O Delphi lê tudo de `https://api.louvorja.com.br/params?type=env` e precisa de
endereços nesse domínio: no `workers.dev` a Cloudflare recusa a identificação
dele ("Indy Library", erro 1010), e isso não é configurável. Por isso `/params`
sempre entrega com o domínio antigo os endereços desta API (`conn_ftp`,
`coletaneas_online`, `version_log`).

**FTP desligado (versões anteriores à 27.0).** Essas versões baixam banco e
mídias só por FTP, a partir de um aperto de mão HTTPS: `/params` anuncia
`conn_ftp` e o programa pede a `/ftp` os dados do servidor. O FTP foi
desligado; `/ftp` responde com `ftp_msg`, que o programa mostra no lugar do
download, mandando instalar a versão nova pelo site. Sem `conn_ftp` o Delphi
recusaria o download com um erro genérico. A atualização do programa em si
(v25.0 em diante) não usa FTP: vem de `pt_download`/`es_download`, no GitHub.
`db_version` fica no número em que o PHP deixou o banco do FTP, para essas
instalações não tentarem atualizar a cada abertura.

**Atualização por HTTPS, com suporte a Opus.** Os arquivos públicos ficam em
`files.louvorja.com.br` (bucket `files`), e `/params` diz onde:

| Chave de `/params` | O que é |
| --- | --- |
| `https_db_version` | Versão do banco disponível por HTTPS. Compare com a do banco local |
| `https_db_pt`, `https_db_es` | O banco SQLite de cada idioma (`config/<idioma>_database.db`) |
| `https_files` | Base única de todos os arquivos: some `/` + `ARQUIVOS_SISTEMA.URL_DOWNLOAD` |

No SQLite, `ARQUIVOS_SISTEMA.URL_DOWNLOAD` é o caminho relativo ao bucket,
com barras `/`, por exemplo `system/bass.dll` ou
`system/config/server/index.html`. `ARQUIVOS_SISTEMA.URL` continua sendo o
caminho de instalação no Windows, como `bass.dll` ou `config\server\index.html`.
O atualizador HTTPS deve usar o primeiro para baixar e o segundo para gravar
localmente, codificando os segmentos do caminho remoto para formar a URL.
`TAMANHO` informa os bytes do arquivo publicado. `ARQUIVOS_ADICIONAIS` também
inclui `URL_DOWNLOAD` e `TAMANHO`. Esses campos são gerados no SQLite
distribuído, com os tamanhos conferidos no bucket; o esquema D1 permanece igual.

O banco preserva as tabelas e visões do PHP. O modo padrão `stored` usa o áudio
realmente publicado (Opus ou MP3), mantém BMP nas imagens do Delphi e confere
os tamanhos contra `files`. `npm run delphi:db` gera os bancos a partir do D1
quando o catálogo passa de `https_db_version`, e só anuncia a versão depois de
publicar ambos. O banco FTP e `db_version` permanecem independentes. As cópias
anteriores em `files-original` são preservadas; `--audio=legacy` existe apenas
para operações explícitas de compatibilidade e exige todos os originais no
destino anunciado.

Somente no banco HTTPS (`stored`), `LISTA_MUSICAS` e `LISTA_MUSICAS_ONL` são
tabelas com os campos de busca já calculados. Nomes, colunas e resultados são
preservados; `LISTA_MUSICAS_TODAS` e `LISTA_MUSICAS_PERSO` continuam como views.
Gatilhos atualizam as tabelas quando o Delphi altera os álbuns ignorados ou
atualiza canais, playlists e vídeos. O exportador mantém as views por padrão;
`delphi:db` habilita a materialização para o banco HTTPS. As tabelas são criadas
com tipos explícitos: nomes e outros campos de exibição usam `NVARCHAR(n)`,
que o FireDAC reconhece como string; letras usam `TEXT` (memo), e IDs de vídeos
permanecem textuais. O tamanho declarado acomoda os valores exportados.
O gerador também tipa `musics.name`, `albums.name` e `bible_book.name`,
`abbreviation` e `keywords` no SQLite exportado. As views repassam o tipo
declarado, então `MUSICAS.NOME`, `ALBUM.NOME` e `LIVRO.LIVRO` chegam como
string, e não como memo, que a tela mostraria como `(WIDEMEMO)`. O esquema do
D1 permanece igual.
Para regenerar na mesma versão, use `--force`; clientes que já baixaram essa
versão precisam baixar o banco novamente para receber a mudança.

Esta atualização mantém a versão 185. Clientes HTTPS que já guardaram um
banco 185 sem `ARQUIVOS_SISTEMA.URL_DOWNLOAD` devem detectar a coluna ausente
e baixar novamente o banco uma vez; comparar apenas a versão não detecta
a mudança. A regeneração usa `--force` sem incrementar a versão.

`ARQUIVOS_ADICIONAIS` contém 15 arquivos: seis DLLs (incluindo
`bassopus.dll`), fonte, ícone, três áudios da Escola Sabatina e quatro páginas
HTML de `system/config/server/`. O executável é atualizado pelo instalador e
fica fora da lista de downloads do banco. O cron acompanha o último instalador oficial estável e a `main` do Delphi:
o executável e as dependências vêm do instalador; `bassopus.dll` e as quatro
páginas HTML vêm de um mesmo commit da `main`. Mudanças nesses arquivos
regeneram os dois bancos SQLite sem incrementar a versão do catálogo.
Também é possível baixar um adicional por
`/file/system/<caminho>`, que confirma sua existência e redireciona para `files`.

**Versão nova do programa.** As chaves de versão e de instalador saem do
último lançamento final de `louvorja/desktop` no GitHub (`npm run
delphi:version`): publicar o lançamento lá é o aviso. Pré-lançamento não é
anunciado, e a versão nunca volta para trás.

### Mídia nova

O painel permite enviar áudio Opus ou MP3, capas BMP e imagens BMP/JPEG/PNG
pela biblioteca. O arquivo é gravado em `files` e `files-original` com os
mesmos bytes e a mesma extensão, sem conversão automática. Áudios usam
`musics/<idioma>/<pasta>/<arquivo>`; capas usam `covers/` e fundos usam
`images/`. Capas precisam ser BMP porque o Delphi exige esse formato.

`POST /admin/files` exige sessão válida e permissão `files.insert` (ou
administrador). Aceita até 50 MiB por áudio e 10 MiB por imagem, valida o
conteúdo e recusa sobrescrever um nome já existente. O envio seleciona o
arquivo no formulário; o cadastro da música ou álbum ainda precisa ser salvo.

`npm run media:normalize` sincroniza mídia que chegou por importação ou
diretamente ao armazenamento e confere os backups. As conversões históricas
continuam válidas: o catálogo aponta para o arquivo já publicado, e o original
fica preservado. BMPs ainda necessários ao Delphi são mantidos. O cron não
cria outra versão do áudio e interrompe a publicação se faltar algum arquivo
necessário ou se houver mídia pendente além do limite do lote.

### Bíblia fora do banco

- A Bíblia não é editada pelo painel: os 404 mil versículos ficam como estão no
  R2 e a publicação não toca neles. `seed-d1.ts --bible` existe, mas carregá-los
  ocuparia cerca de 11 dias da cota de escrita e não é parte do plano.

### Bíblia inteira em um arquivo

Quem precisa da Bíblia completa (cópia offline, busca, rotina agendada) baixa
`/db/bible-bundle`: um ZIP de cerca de 26 MB com os 15.457 capítulos de todas as
versões. Cada entrada se chama `bible_{versão}_{livro}_{capítulo}.json` e tem o
mesmo conteúdo de `/json_db/bible_{versão}_{livro}_{capítulo}`. As versões e os
livros estão em `/json_db/{pt,es}_bible_version` e `/json_db/{pt,es}_bible_book`;
os livros de 1 a 66 são os das versões em português e os de 67 a 132, os das
versões em espanhol.

O capítulo avulso em `/json_db` existe para a tela que mostra um capítulo por
vez. Varrer todos por ele são milhares de requisições na cota diária, contra
uma do pacote. Por isso o pacote aparece na página inicial, no `/openapi.json`
e na resposta de capítulo inexistente.

## O domínio da API antiga

`api.louvorja.com.br` é atendido inteiro por este Worker (rota única em
`routes`, no `wrangler.jsonc`). O registro de DNS do endereço ainda aponta
para o servidor PHP, porque as rotas precisam dele, mas nenhum pedido chega lá
por HTTP: `/auth`, `/admin`, `/tasks` e `/health` desse domínio também são
os daqui. O FTP daquela máquina, que o Delphi anterior à 27.0 usava direto no
IP, não é mais anunciado.

Quem pede `/file/musics/<faixa>.mp3` recebe um redirecionamento para `files`
somente quando esse MP3 existe lá. A API não entrega Opus com nome de MP3.

### Descontinuação do `/file`

> **O `/file/*` será descontinuado.** O catálogo (`/json_db`, `/db`) já entrega o
> endereço completo de cada arquivo (`url_music`, `url_image`), apontando direto
> para `https://files.louvorja.com.br`. Cliente novo deve usar esse endereço e não
> montar `/file/...` sozinho: cada arquivo servido pelo Worker conta na cota
> diária de 100 mil requisições.

Enquanto a rota existir, toda resposta de `/file` traz `Deprecation: true`, e o
`openapi.json` a marca como `deprecated`. Ainda dependem dela o Flute 1.9.x, o
Piano e instalações com catálogo antigo guardado (caminho relativo). Não há data
de remoção definida. O passo a passo para removê-la está no comentário de
`src/features/files.ts`.

### Rotas REST removidas

Das rotas REST herdadas da API PHP só ficaram as que algum cliente usa:
`/{lang}/albums/category/{slug}` e `/{lang}/collections/online` (ambas já
descontinuadas, veja abaixo) e `/{lang}/download`, além de `/player`, `/version`,
`/metadata` e `/health`.
`/{lang}/musics`, `/files`, `/hymnal`, `/categories`, `/albums`, `/lyrics`,
`/languages`, `/config` e os itens por id (`/musics/{id}`, `/albums/{id}`,
`/hymnal/{id}`) deixaram de existir: o catálogo completo está em `/json_db`.

### Rotas REST descontinuadas

`/{lang}/collections/online` e `/{lang}/albums/category/{slug}` seguem no ar
só para versões antigas do violin-app e respondem com `Deprecation: true`. Os
equivalentes no `json_db`, que devolvem a lista inteira, sem paginação:

| Descontinuada | Use |
| --- | --- |
| `/{lang}/collections/online` | `/json_db/{lang}_collections_online` |
| `/{lang}/albums/category/doxology` | `/json_db/{lang}_doxology_albums` |
| `/{lang}/albums/category/children` | `/json_db/{lang}_children_albums` |

### Endereços de mídia do catálogo

O catálogo é guardado com o caminho do arquivo dentro do armazenamento
(`/musics/...`, `/covers/...`, `/images/...`). Quem lê recebe o endereço
completo, e ele aponta direto para o armazenamento: arquivo entregue por
`/file` conta na cota diária de requisições do Worker. A troca é feita em
`src/lib/media-urls.ts` e vale para `/json_db`, `/db`, o pacote `/db/bundle`
e as rotas REST que restaram (`/{lang}/albums/category/{slug}`).
O domínio do pedido não muda o formato:

| Pedido por | Quem é | Áudio | Capas e imagens |
| --- | --- | --- | --- |
| ambos os domínios | todos os apps | `files.louvorja.com.br`, com o nome exato do arquivo (Opus ou MP3) | `files.louvorja.com.br` |

- `files.louvorja.com.br` é o bucket `files`: a melhor versão de cada arquivo.
  Metade do acervo está em Opus; a outra metade ficou em MP3 porque o original
  é de 128 kbps e converter só acrescentaria perda.
- `mp3.louvorja.com.br` é o bucket `files-original`: backup dos arquivos
  originais, com os mesmos nomes e pastas. É preservado, mas não é selecionado
  pelas respostas atuais das APIs. Não se copia o MP3 para `files` quando
  a versão desse áudio já é Opus.

`/{lang}/albums/category/{slug}` mantém `Cache-Control: private` porque os links de
paginação preservam o domínio solicitado. O catálogo e os pacotes são iguais nos dois
domínios; nesta migração, seus tempos e políticas de cache foram preservados.

As URLs dos retratos REST (`rest/{lang}_*.json`) são conferidas contra as chaves
reais do armazenamento durante a publicação. A publicação continua gerando todos
eles, mas nenhuma rota pública serve mais a maioria (`musics`, `files`,
`hymnal`…): ficam como insumo de `/json_db`, dos pacotes e dos scripts de
reconciliação. O retrato `rest/{lang}_files_legacy.json` guarda os tamanhos dos
MP3 originais; o atual tem `size` do arquivo indicado em `url`.
Para corrigir só a mídia dos retratos existentes, sem publicar alterações do
D1 nem mudar `db_version`: `npm run rest:media` simula;
`npm run rest:media -- --write` grava e relê o resultado, preservando uma cópia
local dos objetos anteriores. Prepare esses retratos antes de implantar o
Worker que os utiliza. A publicação normal também os mantém atualizados.

`/file` conserva a compatibilidade entre extensões para instalações com
catálogos antigos. Os endereços diretos entregues pelo catálogo
usam a extensão que existe no armazenamento e não dependem dessa troca.

O pacote completo mantém `/db/bundle` e as variantes `meta/bundle-files.zip` e
`meta/bundle-legacy.zip`; `meta/bundle.zip` é a base com caminhos relativos.
`/db/catalog-bundle` entrega o catálogo sem capítulos, em ambos os domínios,
a partir de `meta/catalog-bundle-files.zip`. Os artefatos `legacy` continuam
disponíveis no armazenamento, mas não são selecionados pelo domínio da API.
`/db/bible-bundle` entrega somente capítulos.

Todos os ZIPs incluem `_bundle_manifest.json` (`schema_version: 1`, `kind`:
`full`, `catalog` ou `bible`, e `files: [{ name, bytes, sha256 }]`). O publicador
confere os capítulos esperados pelos metadados e referências de músicas/álbuns
antes de construir os pacotes. O cliente valida CRC e o conjunto exato de JSONs,
mais tamanho/SHA-256 por arquivo. Os manifestos públicos mantêm o formato antigo
com MD5, calculado sobre os bytes finais de cada domínio; `meta/manifest.json`
representa a base interna, e `meta/manifest-files.json`/`meta/manifest-legacy.json`
as variantes atual e legada; ambas as APIs selecionam a atual.

`npm run bundle:variants -- --write` regenera todos esses artefatos a partir da
base publicada, sem incrementar a versão do catálogo nem alterar os bancos FTP.
O app web `louvorja/app` lê `/json_db/{key}` individualmente e não depende do nome
do objeto ZIP. A rota `/db/bundle` continua completa para clientes existentes.

O app novo aceita endereço completo desde a beta.12; versões anteriores
montavam o endereço por conta própria e ficam sem capa e sem áudio. A versão
web precisa ter `files.louvorja.com.br` na política de conteúdo dela.

Enquanto os dois sistemas existirem, `db_version` em `/params` precisa
acompanhar o banco que está no FTP: o Delphi compara esse número com o dele e
tenta atualizar quando o da API é maior.

## Contratos atendidos

As rotas públicas incluem `/params`, `/ftp`, `/onlinevideos`, `/file/*`, `/json_db/*`,
`/db/*`, `/metadata`, `/download`, `/version`, `/version_log`, `/player` e as
coleções sob `/{pt,es}/...`.

O painel usa:

- `/auth/login`, refresh, logout, usuário atual e troca de senha;
- CRUD de usuários, categorias, vínculos, álbuns, músicas e letras em `/admin`;
- consulta de arquivos em `/admin/files`.

`/tasks/*` agenda o workflow `maintenance.yml` para quem chama a API com sessão
de administrador; o painel não tem tela para isso.

A única listagem pública paginada que restou é `/{lang}/albums/category/{slug}`
(descontinuada, no formato do Laravel). Os filtros legados (`like:`, `orlike:`,
`or:`, `sort_by`, `limit`) valem só em `/admin`, que exige JWT.

## Publicação

O D1 é a fonte da verdade. Uma publicação:

1. exporta o D1 para uma réplica SQL;
2. gera os JSONs de `/json_db` e a camada REST e compara com o que está no R2;
3. envia só o que mudou, refaz o ZIP e o manifesto;
4. se o conteúdo mudou, sobe a versão em `meta/params.json` e, por último, no D1.

Sem `--write` o script só mostra o que faria.

```bash
npx wrangler d1 export louvorja --remote --output=replica.sql
npm run cutover:check -- --export=replica.sql
npm run publish -- --export=replica.sql
npm run publish -- --export=replica.sql --write
```

`publish` para antes de ler ou gravar o R2 quando a réplica não tem ao menos um
administrador com senha no formato aceito.

`npm run catalog:diff` compara o que o banco gera com o que está publicado, byte
a byte, e falha se sobrar diferença sem explicação.

## Administrador inicial e senhas do PHP

Usuários e logs são dados operacionais do D1; o `d1:seed` carrega só o catálogo.
A autenticação aceita `pbkdf2-sha256$100000`. Os hashes bcrypt do Laravel não são
compatíveis nem convertidos: contas antigas precisam de senha nova.

Antes da primeira publicação, crie um administrador. O SQL gerado deixa a senha
como temporária e não deve ser versionado:

```bash
umask 077
read -s LOUVORJA_USER_PASSWORD
export LOUVORJA_USER_PASSWORD
ADMIN_SQL="$(mktemp)"
npm run --silent d1:user -- \
  --name="Administrador" \
  --username=admin-worker \
  --email=admin-worker@example.com \
  --admin > "$ADMIN_SQL"
unset LOUVORJA_USER_PASSWORD
npx wrangler d1 execute louvorja --remote --file="$ADMIN_SQL"
rm -f "$ADMIN_SQL"
```

Depois, confirme o login e a troca obrigatória da senha temporária no painel.

## Migrations

As migrations de produção são aplicadas à mão, nunca pelo workflow:

```bash
npx wrangler d1 migrations apply louvorja --remote
```

`0002_row_counts.sql` cria gatilhos que somam uma escrita por linha inserida.
Aplique-a só depois de terminar a carga inicial das letras.

## Tarefas de manutenção

| Comando | Função |
| --- | --- |
| `npm run cutover:check` | Exige um administrador com senha válida na réplica |
| `npm run publish` | Gera e publica os snapshots |
| `npm run publish:gate` | Verifica alterações do painel, manutenção diária e tentativas pendentes |
| `npm run catalog:diff` | Audita o que o banco gera contra o que está publicado |
| `npm run media:normalize` | Sincroniza mídia e backup, mantendo formato e bytes |
| `npm run files:reconcile` | Confere tamanho no R2 e duração com `ffprobe` |
| `node --experimental-strip-types scripts/system-files.ts` | Sincroniza os 16 arquivos oficiais do programa |
| `npm run delphi:db` | Gera e publica os bancos SQLite do Delphi |
| `npm run delphi:version` | Anuncia a versão do Delphi lançada no GitHub |
| `npm run bundle:variants` | Regera os pacotes com endereço de mídia |
| `npm run online-videos:refresh` | Atualiza canais, playlists e vídeos do YouTube |
| `npm run slides:import` | Importa `.slja/.lja` com áudio e imagens |

O workflow `maintenance.yml` é o único cron: executa esses jobs fora do limite
curto do Worker, nesta ordem: arquivos do programa, mídia nova, reconciliação,
publicação, banco do Delphi e versão do Delphi. A manutenção completa ocorre
após 24 horas da última conclusão; uma falha continua pendente para a próxima
execução. Os arquivos do programa são conferidos mesmo sem alterações no catálogo. As rotas `/tasks/*` só fazem um `workflow_dispatch`
autenticado. A ingestão que copiava tudo da API PHP (`ingest.yml`) foi
desligada e não deve voltar: as duas gravariam os mesmos objetos. O agendamento
(de hora em hora) fica desligado até a variável `MAINTENANCE_SCHEDULE` do
repositório valer `on` — ligue depois de conferir a primeira publicação manual.

Exportar o banco não é de graça: cada export conta, na cota diária de leitura
do D1, uma linha por linha exportada (medido: 62.829 linhas lidas para 62.829
exportadas). Por isso a rotina de hora em hora começa por `publish:gate`, que
compara o maior id da auditoria com a marca deixada pela última publicação e
custa uma linha. Sem alteração do painel ela para ali; uma vez por dia roda
inteira. Carga feita direto no banco não passa pela auditoria: depois dela,
dispare a tarefa `publish` à mão.

## Desenvolvimento

Requisitos: Node 22, `zip`, `unzip` e `ffprobe` (pacote `ffmpeg`).

```bash
npm ci
cp .env.example .env
npm run dev          # API em http://localhost:8787
npm run type-check
npm test
npm run lint
```

O runtime local usa D1 e R2 do Miniflare. Os testes `test/*.node.ts` exercitam
os scripts de publicação e manutenção (`npm run test:<nome>`).

## Configuração

Bindings declarados em `wrangler.jsonc`:

- `DB`: banco D1 `louvorja`;
- `FILES`: bucket R2 `files`;
- `RATE_LIMIT_FILES`, `RATE_LIMIT_METADATA` e `RATE_LIMIT_GENERAL`: limites por
  minuto equivalentes aos três grupos da API PHP;
- `BIBLE_LIMIT_PER_MINUTE` (150) e `BIBLE_THROTTLE_MS` (8000): capítulos da Bíblia por
  minuto e por IP em `/json_db/bible_*`, contados por um Durable Object por IP
  (`src/lib/window-counter.ts`; o binding de rate limit, a Cache API e a memória do
  isolate não seguraram o teto em produção: 200 pedidos passaram por um limite de 60/min).
  Se o objeto não responder, a rota segue sem limite; Passado o limite, o 429 é segurado por
  8 s para frear robô sequencial e aponta para `/db/bible-bundle`. Recusar dentro do
  Worker não poupa a cota diária (o pedido já foi contado): a proteção da cota é a
  regra de WAF da zona;
- `JWT_SECRET` (obrigatório): assinatura das sessões do painel;
- `GITHUB_TASK_TOKEN` (opcional): token restrito a Actions para `/tasks`. Sem
  ele, `/tasks` responde `503` e o resto funciona.

Além do teto de 150/min, capítulos avulsos têm uma janela móvel de cinco minutos
(agregada por segundo): 50 respostas 404, quando representam pelo menos 90% dos
resultados 200/206/304/404, suspendem esse IP por 30 minutos. Uma reincidência em
24 horas aumenta a suspensão para duas horas. Apenas GET/HEAD de capítulos entram
nessa conta; preflight, bundles, demais rotas e erros de infraestrutura ficam fora.
O histórico e a suspensão ficam no armazenamento do Durable Object, sobrevivem a
reinícios e são apagados por alarme depois de vencerem. Durante a suspensão, a API
responde 429 antes de consultar R2, com `Retry-After` e
`X-RateLimit-Reason: invalid-bible-queries`, sem a espera de 8 s do teto por minuto.
Os critérios ficam em `src/lib/bible-abuse.ts`.

A regra WAF `API - bloquear caminhos de scanner` está registrada em
`config/waf-api-scanners.json` e é administrada separadamente no painel da
Cloudflare (o `wrangler deploy` não a cria). Ela bloqueia caminhos de arquivos
sensíveis e sondagens de WordPress exclusivamente em `api.louvorja.com.br`, antes
do Worker. Não cobre `api.louvorja.workers.dev`, que continua ativo para os apps;
a suspensão interna funciona nos dois endereços.

Os jobs usam `CLOUDFLARE_API_TOKEN`, credenciais S3 do R2 e, no job mensal,
`YOUTUBE_API_KEY`. Segredos não entram em `wrangler.jsonc`, no R2 ou no Git.

`PUBLIC_API_URL` é a origem gravada nas URLs dos snapshots quando o script
oferece essa configuração. O padrão é `https://api.louvorja.com.br`, atendido
por este mesmo Worker.

## Segurança e compatibilidade HTTP

- `/file/*` aceita somente `covers/`, `images/`, `musics/` e `system/`, bloqueia
  subida de diretório e responde `206` para `Range` nas mídias transmitidas.
- Em `/file`, o nome `.bmp` continua resolvendo para a capa JPEG e o `.opus`
  para o MP3 quando a faixa não foi convertida. MP3 e adicionais existentes
  redirecionam para `files`; MP3 ausente responde `404`. O `Content-Type`
  descreve os bytes reais.
- Respostas privadas nunca vão para o cache, e um `404` público não fica preso
  na borda.
- Entradas de rede e arquivos importados são validados antes do uso.
- Notas de versão vindas do GitHub são escapadas antes de entrar no HTML.

## Colocar em produção

1. termine a carga inicial e aplique as migrations;
2. grave o segredo: `npx wrangler secret put JWT_SECRET`;
3. crie o administrador e rode `cutover:check`;
4. publique o Worker (`npm run deploy`); o painel é publicado pelo repositório
   `louvorja/adm`, a cada commit na `main` de lá;
5. confirme o login e a troca de senha no painel;
6. rode a primeira publicação à mão (`workflow_dispatch`, tarefa `publish`) e
   confira os apps;
7. ligue o agendamento com a variável `MAINTENANCE_SCHEDULE=on`.

## Licença

Projeto proprietário.

## Versões dos aplicativos em /params

Configure repositórios e chaves em `config/app-releases.json`. Já inclui Classic,
Violin, Piano e Flute. Para adicionar outro, acrescente um objeto com `id`,
`repository` (owner/repo) e `versionKey` (terminado em `-version`). IDs e chaves
precisam ser únicos. Repositórios privados exigem acesso de leitura via GITHUB_TOKEN.

```bash
npm run apps:versions             # consulta e simula, sem gravar
npm run apps:versions -- --write  # atualiza o R2 configurado no .env
npm run test:apps-versions
```

Usa o último release estável, remove apenas o prefixo v e preserva as partes
numéricas da versão. Não anuncia versões anteriores. Tags inválidas, pré-lançamentos
e falhas preservam os valores anteriores; chaves novas sem release válido ficam
ausentes. Falhas retornam status 1 depois de processar os demais aplicativos.
Sem --write não grava. O destino é o R2 do .env, não o armazenamento local do Wrangler.

A manutenção executa o comando geral e preserva todos os parâmetros antigos.
Os aliases e instaladores do Delphi continuam usando as regras anteriores.
`npm run delphi:version` permanece disponível, restrito ao Classic;
`DELPHI_RELEASES_REPO` continua sobrescrevendo o repositório do Classic.
Retirar um aplicativo da configuração não apaga sua chave já publicada.
O documento é relido antes da gravação; publicadores concorrentes ainda precisam
ser serializados, como já ocorre pelo concurrency do workflow.
