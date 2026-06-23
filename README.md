# LouvorJA API

API RESTful (Lumen 10) para gerenciamento de hinários, músicas, letras, álbuns, categorias, vídeos online e exportação de banco para o app desktop LouvorJA.

## Documentação Interativa

- **Swagger UI:** `/documentation`
- **OpenAPI JSON:** `/openapi.json`

## Base URL

```
https://api.louvorja.com.br
```

## Autenticação

Endpoints admin e auth exigem token JWT Bearer:

```
Authorization: Bearer <token>
```

Obtenha o token via `POST /auth/login`.

---

## Endpoints

### Documentação

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/openapi.json` | Spec OpenAPI 3.0 (JSON) |
| GET | `/documentation` | Swagger UI (HTML) |

### Público (sem autenticação)

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/metadata` | Metadados do sistema |
| GET | `/player` | Dados do player |
| GET | `/download` | Downloads disponíveis |
| GET | `/version` | Versão da API |
| GET | `/version_log` | Log de versões |
| GET | `/file/{path}` | Abrir arquivo estático |
| GET | `/json_db` | Manifest dos bancos JSON exportados |
| GET | `/json_db/{file}` | Download de arquivo JSON exportado |
| GET | `/params` | Parâmetros do sistema |
| GET | `/onlinevideos` | Vídeos online (YouTube) |
| GET | `/ftp` | Status FTP |

### Público por idioma (`/{lang}/...`)

Todos os endpoints abaixo suportam prefixo de idioma (ex: `/pt/musics`, `/en/albums`).

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/{lang}/` | Health check |
| GET | `/{lang}/languages` | Idiomas disponíveis |
| GET | `/{lang}/config` | Configurações (alias) |
| GET | `/{lang}/configs` | Configurações |
| GET | `/{lang}/musics` | Listar músicas |
| GET | `/{lang}/musics/{id}` | Buscar música por ID |
| GET | `/{lang}/music/{id}` | Buscar música por ID (alias) |
| GET | `/{lang}/categories` | Listar categorias |
| GET | `/{lang}/categories_albums` | Listar categorias-álbuns |
| GET | `/{lang}/albums` | Listar álbuns |
| GET | `/{lang}/albums/{id}` | Buscar álbum por ID |
| GET | `/{lang}/album/{id}` | Buscar álbum por ID (alias) |
| GET | `/{lang}/albums_musics` | Listar associações álbum-música |
| GET | `/{lang}/lyrics` | Listar letras |
| GET | `/{lang}/hymnal` | Listar hinários |
| GET | `/{lang}/files` | Listar arquivos |
| GET | `/{lang}/download` | Downloads por idioma |
| GET | `/{lang}/ftp` | Status FTP por idioma |

### Autenticação

| Método | Rota | Descrição | Auth |
|--------|------|-----------|------|
| POST | `/auth/login` | Login (retorna JWT) | Não |
| GET | `/auth/me` | Usuário atual | Sim |
| POST | `/auth/logout` | Logout | Sim |
| POST | `/auth/refresh-token` | Renovar token | Sim |
| POST | `/auth/refresh_token` | Renovar token (alias) | Sim |
| POST | `/auth/change-password` | Alterar senha | Sim |

**POST /auth/login — Body:**
```json
{
  "email": "user@example.com",
  "password": "senha"
}
```

**POST /auth/change-password — Body:**
```json
{
  "current_password": "senha_atual",
  "password": "nova_senha",
  "password_confirmation": "nova_senha"
}
```

### Admin — CRUD

Todos os endpoints admin exigem **Bearer JWT** + **senha confirmada** (`confirmed_pwd` middleware).

| Recurso | GET (listar) | GET (buscar) | POST (criar) | PUT (atualizar) | DELETE (excluir) |
|---------|-------------|-------------|-------------|-----------------|------------------|
| Usuários | `/admin/users` | `/admin/users/{id}` | `/admin/users` | `/admin/users/{id}` | `/admin/users/{id}` |
| Categorias | `/admin/categories` | `/admin/categories/{id}` | `/admin/categories` | `/admin/categories/{id}` | `/admin/categories/{id}` |
| Categorias-Álbuns | `/admin/categories_albums` | `/admin/categories_albums/{id}` | `/admin/categories_albums` | `/admin/categories_albums/{id}` | `/admin/categories_albums/{id}` |
| Álbuns | `/admin/albums` | `/admin/albums/{id}` | `/admin/albums` | `/admin/albums/{id}` | `/admin/albums/{id}` |
| Músicas | `/admin/musics` | `/admin/musics/{id}` | `/admin/musics` | `/admin/musics/{id}` | `/admin/musics/{id}` |
| Álbuns-Músicas | `/admin/albums_musics` | `/admin/albums_musics/{id}` | `/admin/albums_musics` | `/admin/albums_musics/{id}` | `/admin/albums_musics/{id}` |
| Letras | `/admin/lyrics` | `/admin/lyrics/{id}` | `/admin/lyrics` | `/admin/lyrics/{id}` | `/admin/lyrics/{id}` |
| Arquivos (leitura) | `/admin/files` | `/admin/files/{id}` | — | — | — |

> **Nota:** Endpoints de escrita em arquivos (`POST/PUT/DELETE /admin/files`) estão comentados nas rotas.

### Admin — Tarefas

| Método | Rota | Descrição |
|--------|------|-----------|
| GET | `/tasks` | Listar tarefas disponíveis |
| GET | `/tasks/refresh_configs` | Recarregar cache de configurações |
| GET | `/tasks/refresh_files_size` | Recalcular tamanho dos arquivos |
| GET | `/tasks/refresh_files_duration` | Recalcular duração dos áudios |
| GET | `/tasks/refresh_online_videos` | Atualizar dados de vídeos online |
| GET | `/tasks/import_slides` | Importar slides de apresentações |
| GET | `/tasks/export_database` | Exportar banco (SQL) |
| GET | `/tasks/export_database_json` | Exportar banco (JSON) |

### Vídeos Online

```
GET /onlinevideos?lang={lang}&tipo={tipo}&id={id}
```

**Parâmetros Query:**
| Parâmetro | Tipo | Default | Descrição |
|-----------|------|---------|------------|
| `lang` | string | `pt` | Idioma (`pt`, `en`, `es`) |
| `tipo` | string | `tudo` | Tipo: `canais`, `playlists`, `videos`, `tudo` |
| `id` | string | — | ID do canal ou playlist para filtro |

---

## Parâmetros Comuns de Query

| Parâmetro | Descrição |
|-----------|-----------|
| `lang` | Idioma (`pt`, `en`, `es`). Padrão: `pt` |
| `page` | Paginação. Padrão: `1` |
| `q` | Busca textual |

---

## Formato de Resposta

**Sucesso:**
```json
{
  "data": { ... }
}
```

**Erro:**
```json
{
  "error": "Mensagem de erro",
  "status": 400
}
```

## Códigos de Status HTTP

| Código | Descrição |
|--------|-----------|
| 200 | Sucesso |
| 201 | Criado com sucesso |
| 400 | Bad Request |
| 401 | Não autenticado |
| 403 | Sem permissão |
| 404 | Não encontrado |
| 422 | Validação falhou |
| 500 | Erro interno do servidor |

## Stack

- **Framework:** Laravel Lumen 10
- **PHP:** 8.3+
- **Auth:** JWT (php-open-source-saver/jwt-auth)
- **Docs:** Swagger PHP (zircote/swagger-php) — OpenAPI 3.0
- **Database:** MySQL + SQLite (desktop export)

## Repositório

- **GitHub:** https://github.com/louvorja/api
- **Issues:** https://github.com/louvorja/api/issues

## Licença

Este projeto está sob licença proprietária.
