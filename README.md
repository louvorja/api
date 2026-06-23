# LouvorJA API

API RESTful para gerenciar o banco de dados do LouvorJA (músicas, álbuns, categorias, arquivos).

## Endpoints Disponíveis

### Públicos (sem autenticação)

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/` | Health check |
| GET | `/file/{path}` | Arquivos estáticos |
| GET | `/metadata` | Metadados da API |
| GET | `/player` | Player |
| GET | `/json_db` | Lista todos os JSONs disponíveis (manifest) |
| GET | `/json_db/{file}` | Baixa JSON específico do banco de dados |
| GET | `/download` | Download de arquivos |
| GET | `/version_log` | Log de versões |
| GET | `/{lang}/download` | Download com idioma |

### Autenticados (requer token JWT)

#### Autenticação

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| POST | `/auth/login` | Login (retorna token) |
| POST | `/auth/refresh-token` | Renova token |
| GET | `/auth/me` | Dados do usuário atual |
| POST | `/auth/logout` | Logout |
| POST | `/auth/change-password` | Alterar senha |

#### Parâmetros e Configurações

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/params` | Parâmetros do sistema |
| GET | `/ftp` | Configurações FTP |
| GET | `/onlinevideos` | Vídeos online |

#### Tasks

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/tasks/` | Lista todas as tasks disponíveis |
| GET | `/tasks/refresh_configs` | Atualiza configurações |
| GET | `/tasks/export_database` | Exporta banco de dados |
| GET | `/tasks/refresh_files_size` | Atualiza tamanho dos arquivos |
| GET | `/tasks/refresh_files_duration` | Atualiza duração dos arquivos |
| GET | `/tasks/refresh_online_videos` | Atualiza vídeos online |
| GET | `/tasks/import_slides` | Importa slides |
| GET | `/tasks/export_database_json` | Exporta banco como JSON |

#### Endpoints com Idioma

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| GET | `/{lang}/languages` | Lista idiomas |
| GET | `/{lang}/config` | Configurações |
| GET | `/{lang}/musics` | Lista músicas |
| GET | `/{lang}/musics/{id}` | Detalhes da música |
| GET | `/{lang}/music/{id}` | Detalhes da música (alias) |
| GET | `/{lang}/categories` | Lista categorias |
| GET | `/{lang}/categories_albums` | Lista categorias de álbuns |
| GET | `/{lang}/albums` | Lista álbuns |
| GET | `/{lang}/albums/{id}` | Detalhes do álbum |
| GET | `/{lang}/albums_musics` | Lista músicas dos álbuns |
| GET | `/{lang}/lyrics` | Lista letras |
| GET | `/{lang}/hymnal` | Hinário |
| GET | `/{lang}/files` | Lista arquivos |
| GET | `/{lang}/ftp` | Configurações FTP |

### Admin (autenticado + senha confirmada)

#### Usuários

| Método | Endpoint | Descrição | Permissão |
|--------|----------|-----------|-----------|
| GET | `/admin/users` | Lista usuários | `users` |
| POST | `/admin/users` | Cria usuário | `users` |
| GET | `/admin/users/{id}` | Detalhes do usuário | `users` |
| PUT | `/admin/users/{id}` | Atualiza usuário | `users` |
| DELETE | `/admin/users/{id}` | Remove usuário | `users` |

#### Categorias

| Método | Endpoint | Descrição | Permissão |
|--------|----------|-----------|-----------|
| GET | `/admin/categories` | Lista categorias (público) | - |
| GET | `/admin/categories/{id}` | Detalhes da categoria (público) | - |
| POST | `/admin/categories` | Cria categoria | `categories` |
| PUT | `/admin/categories/{id}` | Atualiza categoria | `categories` |
| DELETE | `/admin/categories/{id}` | Remove categoria | `categories` |

#### Álbuns

| Método | Endpoint | Descrição | Permissão |
|--------|----------|-----------|-----------|
| GET | `/admin/albums` | Lista álbuns (público) | - |
| GET | `/admin/albums/{id}` | Detalhes do álbum (público) | - |
| POST | `/admin/albums` | Cria álbum | `albums` |
| PUT | `/admin/albums/{id}` | Atualiza álbum | `albums` |
| DELETE | `/admin/albums/{id}` | Remove álbum | `albums` |

#### Músicas

| Método | Endpoint | Descrição | Permissão |
|--------|----------|-----------|-----------|
| GET | `/admin/musics` | Lista músicas (público) | - |
| GET | `/admin/musics/{id}` | Detalhes da música (público) | - |
| POST | `/admin/musics` | Cria música | `musics` |
| PUT | `/admin/musics/{id}` | Atualiza música | `musics` |
| DELETE | `/admin/musics/{id}` | Remove música | `musics` |

#### Letras

| Método | Endpoint | Descrição | Permissão |
|--------|----------|-----------|-----------|
| GET | `/admin/lyrics` | Lista letras (público) | - |
| GET | `/admin/lyrics/{id}` | Detalhes da letra (público) | - |
| POST | `/admin/lyrics` | Cria letra | `lyrics` |
| PUT | `/admin/lyrics/{id}` | Atualiza letra | `lyrics` |
| DELETE | `/admin/lyrics/{id}` | Remove letra | `lyrics` |

## Autenticação

1. Faça login com `POST /auth/login` enviando `username` e `password`
2. Receba um token JWT na resposta
3. Inclua o token no header `Authorization: Bearer <token>` nas requisições autenticadas

## Swagger/OpenAPI

Documentação interativa disponível em `/api/documentation` (após merge do PR #11).

## Desenvolvimento

```bash
# Instalar dependências
composer install

# Configurar ambiente
cp .env.example .env

# Rodar servidor
php -S localhost:8000 -t public
```

## Licença

MIT