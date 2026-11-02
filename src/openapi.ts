import { jsonDbPaths, jsonDbTag } from './openapi-json-db.ts'

const LANG = {
  name: 'lang',
  in: 'path',
  required: true,
  schema: { type: 'string', enum: ['pt', 'es'] },
  description: 'Idioma do conteúdo.',
} as const

const PAGE = {
  name: 'page',
  in: 'query',
  required: false,
  schema: { type: 'integer', minimum: 1, default: 1 },
  description: 'Página, no formato de paginação do Laravel (15 por página).',
} as const

const ID = {
  name: 'id',
  in: 'path',
  required: true,
  schema: { type: 'integer' },
} as const

const NOT_FOUND = {
  description: 'Recurso inexistente.',
  content: {
    'application/json': {
      schema: {
        type: 'object',
        properties: { error: { type: 'string', example: 'Arquivo não encontrado!' } },
      },
    },
  },
} as const

const BEARER = [{ bearerAuth: [] }]
const ADMIN_RESOURCE = {
  name: 'resource',
  in: 'path',
  required: true,
  schema: {
    type: 'string',
    enum: [
      'users',
      'categories',
      'categories_albums',
      'albums',
      'musics',
      'albums_musics',
      'lyrics',
    ],
  },
} as const

const adminWriteResponses = {
  200: { description: 'Operação concluída.' },
  401: { description: 'Sessão ausente ou expirada.' },
  403: { description: 'Usuário sem a permissão necessária.' },
  404: NOT_FOUND,
  422: { description: 'Falha de validação.' },
}

export const openapi = {
  openapi: '3.0.3',
  info: {
    title: 'LouvorJA API',
    version: '1.0.0',
    description: [
      'Substituição da API PHP de `api.louvorja.com.br`, executada em Cloudflare',
      'Workers, com o catálogo no D1 e os artefatos no R2.',
      '',
      'As rotas públicas preservam o contrato dos apps. `/auth/*` e `/admin/*`',
      'implementam o painel com JWT, validação, permissões e auditoria. `/tasks/*`',
      'despacha jobs longos para GitHub Actions; a publicação gera os JSON e a',
      'camada REST a partir do D1.',
      '',
      '**Compatibilidade e correções deliberadas:**',
      '- Ambos os domínios da API retornam os mesmos arquivos e URLs de',
      '  `files.louvorja.com.br`, preservando as extensões Opus e MP3 do catálogo.',
      '- URLs `.mp3` entregam somente MP3; capas `.bmp` podem resolver para JPEG.',
      '- Requisições `Range` devolvem `206` de verdade. A origem ignora o header',
      '  e manda o arquivo inteiro.',
      '- O `404` não expõe o caminho do arquivo no servidor.',
      '- `Access-Control-Allow-Credentials` foi removido: junto de',
      '  `Allow-Origin: *` ele é rejeitado por qualquer navegador.',
    ].join('\n'),
  },
  servers: [{ url: 'https://api.louvorja.com.br', description: 'Produção' }],
  components: {
    securitySchemes: {
      bearerAuth: { type: 'http', scheme: 'bearer', bearerFormat: 'JWT' },
    },
  },
  tags: [
    jsonDbTag,
    { name: 'Banco estático', description: 'Pacotes ZIP e tabelas em envelope.' },
    { name: 'Arquivos', description: 'Áudio, imagens e capas.' },
    {
      name: 'REST',
      description: 'Rotas herdadas da API PHP. As listas foram removidas; use /json_db.',
    },
    { name: 'Sistema', description: 'Metadados, versão e diagnóstico.' },
    { name: 'Autenticação', description: 'Sessão JWT do painel.' },
    { name: 'Admin', description: 'Catálogo e usuários no D1.' },
    { name: 'Tarefas', description: 'Despacho de jobs operacionais.' },
  ],
  paths: {
    ...jsonDbPaths,
    '/db/manifest': {
      get: {
        tags: ['Banco estático'],
        summary: 'Índice das tabelas, com hash MD5',
        description:
          'MD5 dos bytes JSON finais de /json_db/{key}, conforme o domínio da API. O ZIP também inclui um manifesto interno SHA-256.',
        responses: { 200: { description: '18.060 entradas.' } },
      },
    },
    '/db/bundle': {
      get: {
        tags: ['Banco estático'],
        summary: 'ZIP com todas as tabelas',
        description:
          'Uma requisição em vez de 18.060. Os campos `url_*` já vêm com o endereço ' +
          'completo de cada arquivo de mídia, direto no armazenamento.',
        responses: { 200: { description: 'application/zip.' }, 404: NOT_FOUND },
      },
    },
    '/db/catalog-bundle': {
      get: {
        tags: ['Banco estático'],
        summary: 'ZIP do catálogo sem capítulos bíblicos',
        description:
          'Pacote menor para instalação inicial. Inclui _bundle_manifest.json com tipo, tamanho e SHA-256 de cada JSON.',
        responses: { 200: { description: 'application/zip.' }, 404: NOT_FOUND },
      },
    },
    '/db/bible-bundle': {
      get: {
        tags: ['Banco estático'],
        summary: 'ZIP com a Bíblia inteira',
        description:
          'Todos os capítulos de todas as versões em uma requisição (cerca de 26 MB). ' +
          'Além de _bundle_manifest.json, cada entrada do ZIP é um `bible_{versão}_{livro}_{capítulo}.json` com o mesmo ' +
          'conteúdo de `/json_db/bible_{versão}_{livro}_{capítulo}`. As versões e os livros ' +
          'estão em `/json_db/{pt,es}_bible_version` e `/json_db/{pt,es}_bible_book`. ' +
          'É o caminho para quem quer a Bíblia completa: rotina agendada, cópia offline ou busca.',
        responses: { 200: { description: 'application/zip.' }, 404: NOT_FOUND },
      },
    },
    '/db/{table}': {
      get: {
        tags: ['Banco estático'],
        summary: 'Tabela JSON em envelope, paginada',
        description:
          'Mesmo conteúdo de `/json_db/{key}`, embrulhado em `{ data, meta }` e paginado. ' +
          'Os apps usam `/json_db`.',
        parameters: [
          { name: 'table', in: 'path', required: true, schema: { type: 'string' } },
          PAGE,
          {
            name: 'per_page',
            in: 'query',
            required: false,
            schema: { type: 'integer', default: 50 },
          },
        ],
        responses: { 200: { description: 'Registros da tabela.' }, 404: NOT_FOUND },
      },
    },
    '/file/{path}': {
      get: {
        tags: ['Arquivos'],
        deprecated: true,
        summary: 'Servir mídia e arquivos adicionais (será descontinuado)',
        description:
          '**Será descontinuado.** Use o endereço completo que o catálogo já entrega ' +
          '(`url_music`, `url_image`), que aponta direto para o armazenamento ' +
          '(`https://files.louvorja.com.br`). Cada arquivo servido aqui conta na cota ' +
          'diária de requisições do Worker. As respostas trazem o cabeçalho ' +
          '`Deprecation: true`. ' +
          'Prefixos válidos: `covers/`, `images/`, `musics/` e `system/`. ' +
          'Arquivos `system/` e músicas `.mp3` existentes redirecionam para ' +
          '`https://files.louvorja.com.br/{path}` com cache de uma hora. ' +
          'MP3 ausente retorna 404 mesmo quando existe Opus. As demais mídias ' +
          'aceitam `Range` e devolvem `206`; capas `.bmp` resolvem para `.jpg`.',
        parameters: [
          {
            name: 'path',
            in: 'path',
            required: true,
            schema: { type: 'string' },
            example: 'musics/pt/Adoradores 5/Digno.opus',
          },
        ],
        responses: {
          200: { description: 'Arquivo completo.' },
          206: { description: 'Trecho, em resposta a `Range`.' },
          302: { description: 'Arquivo existente em files.louvorja.com.br.' },
          404: NOT_FOUND,
        },
      },
    },
    '/params': {
      get: {
        tags: ['Sistema'],
        summary: 'Parâmetros da aplicação',
        description:
          'JSON por padrão; `?type=env` devolve INI com CRLF.',
        parameters: [
          {
            name: 'type',
            in: 'query',
            required: false,
            schema: { type: 'string', enum: ['json', 'env'] },
          },
        ],
        responses: { 200: { description: 'Parâmetros.' } },
      },
    },
    '/version_log': {
      get: {
        tags: ['Sistema'],
        summary: 'Changelog do app desktop',
        responses: { 200: { description: 'HTML.' } },
      },
    },
    '/onlinevideos': {
      get: {
        tags: ['Sistema'],
        summary: 'Catálogo de vídeos online',
        responses: { 200: { description: 'Texto.' } },
      },
    },
    '/health': {
      get: {
        tags: ['Sistema'],
        summary: 'Diagnóstico',
        responses: { 200: { description: 'Status dos serviços.' } },
      },
    },
    '/version': {
      get: {
        tags: ['Sistema'],
        summary: 'Versão da API',
        responses: { 200: { description: 'Versões.' } },
      },
    },
    '/metadata': {
      get: {
        tags: ['Sistema'],
        summary: 'Metadados da Bíblia',
        responses: { 200: { description: 'Versões e contagem de versículos.' } },
      },
    },
    '/auth/login': {
      post: {
        tags: ['Autenticação'],
        summary: 'Entrar no painel',
        requestBody: {
          required: true,
          content: {
            'application/json': {
              schema: {
                type: 'object',
                required: ['username', 'password'],
                properties: { username: { type: 'string' }, password: { type: 'string' } },
              },
            },
          },
        },
        responses: {
          200: { description: 'JWT e usuário.' },
          401: { description: 'Credenciais inválidas.' },
          422: { description: 'Falha de validação.' },
        },
      },
    },
    '/auth/me': {
      get: {
        tags: ['Autenticação'],
        summary: 'Usuário autenticado',
        security: BEARER,
        responses: { 200: { description: 'Usuário.' }, 401: { description: 'Sessão inválida.' } },
      },
    },
    '/auth/refresh-token': {
      post: {
        tags: ['Autenticação'],
        summary: 'Renovar JWT e bloquear o anterior',
        security: BEARER,
        responses: { 200: { description: 'Novo JWT.' }, 401: { description: 'Sessão inválida.' } },
      },
    },
    '/auth/logout': {
      post: {
        tags: ['Autenticação'],
        summary: 'Encerrar sessão',
        security: BEARER,
        responses: {
          200: { description: 'Sessão bloqueada.' },
          401: { description: 'Sessão inválida.' },
        },
      },
    },
    '/auth/change-password': {
      post: {
        tags: ['Autenticação'],
        summary: 'Trocar senha',
        security: BEARER,
        responses: {
          200: { description: 'Senha alterada.' },
          400: { description: 'Senha atual incorreta.' },
          422: { description: 'Falha de validação.' },
        },
      },
    },
    '/admin/{resource}': {
      get: {
        tags: ['Admin'],
        summary: 'Listar recurso administrativo',
        security: BEARER,
        parameters: [ADMIN_RESOURCE, PAGE],
        responses: adminWriteResponses,
      },
      post: {
        tags: ['Admin'],
        summary: 'Criar recurso administrativo',
        security: BEARER,
        parameters: [ADMIN_RESOURCE],
        responses: adminWriteResponses,
      },
    },
    '/admin/{resource}/{id}': {
      get: {
        tags: ['Admin'],
        summary: 'Consultar recurso administrativo',
        security: BEARER,
        parameters: [ADMIN_RESOURCE, ID],
        responses: adminWriteResponses,
      },
      put: {
        tags: ['Admin'],
        summary: 'Alterar recurso administrativo',
        security: BEARER,
        parameters: [ADMIN_RESOURCE, ID],
        responses: adminWriteResponses,
      },
      delete: {
        tags: ['Admin'],
        summary: 'Excluir recurso administrativo',
        security: BEARER,
        parameters: [ADMIN_RESOURCE, ID],
        responses: adminWriteResponses,
      },
    },
    '/admin/files': {
      get: {
        tags: ['Admin'],
        summary: 'Listar arquivos e vínculos',
        security: BEARER,
        parameters: [
          PAGE,
          { name: 'id_album', in: 'query', required: false, schema: { type: 'integer' } },
        ],
        responses: adminWriteResponses,
      },
    },
    '/tasks': {
      get: {
        tags: ['Tarefas'],
        summary: 'Listar tarefas disponíveis',
        security: BEARER,
        responses: {
          200: { description: 'Nomes das tarefas.' },
          401: { description: 'Sessão inválida.' },
          403: { description: 'Somente administradores.' },
        },
      },
    },
    '/tasks/{task}': {
      get: {
        tags: ['Tarefas'],
        summary: 'Agendar tarefa no GitHub Actions',
        security: BEARER,
        parameters: [{ name: 'task', in: 'path', required: true, schema: { type: 'string' } }],
        responses: {
          202: { description: 'Workflow aceito.' },
          401: { description: 'Sessão inválida.' },
          403: { description: 'Somente administradores.' },
          404: NOT_FOUND,
          502: { description: 'GitHub recusou a solicitação.' },
          503: { description: 'Dispatcher não configurado.' },
          504: { description: 'Timeout no GitHub.' },
        },
      },
    },
    '/{lang}/albums/category/{slug}': {
      get: {
        tags: ['REST'],
        deprecated: true,
        summary: 'Álbuns de uma categoria, por slug (será descontinuado)',
        description:
          '**Será descontinuado.** Para `doxology` e `children` use ' +
          '`/json_db/{lang}_doxology_albums` e `/json_db/{lang}_children_albums`, ' +
          'que devolvem a lista inteira, sem paginação.',
        parameters: [
          LANG,
          { name: 'slug', in: 'path', required: true, schema: { type: 'string' }, example: 'aym' },
          PAGE,
        ],
        responses: { 200: { description: 'Página de álbuns.' }, 404: NOT_FOUND },
      },
    },
    '/{lang}/collections/online': {
      get: {
        tags: ['REST'],
        deprecated: true,
        summary: 'Catálogo de conteúdo online (será descontinuado)',
        description: '**Será descontinuado.** Use `/json_db/{lang}_collections_online`.',
        parameters: [LANG],
        responses: { 200: { description: 'Catálogo.' } },
      },
    },
    '/{lang}/download': {
      get: {
        tags: ['Sistema'],
        summary: 'Redireciona para o instalador do desktop',
        parameters: [LANG],
        responses: {
          302: { description: 'Redirecionamento para o GitHub Releases.' },
          404: NOT_FOUND,
        },
      },
    },
    '/player': {
      get: {
        tags: ['Sistema'],
        summary: 'Página de player do YouTube',
        description:
          'O id é validado contra o alfabeto do YouTube antes de entrar no HTML. ' +
          'A origem interpola o parâmetro cru, o que permite injeção.',
        parameters: [
          {
            name: 'v',
            in: 'query',
            required: false,
            schema: { type: 'string', pattern: '^[A-Za-z0-9_-]{1,32}$' },
          },
        ],
        responses: { 200: { description: 'HTML com o iframe.' } },
      },
    },
  },
}
