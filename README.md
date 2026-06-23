# LouvorJA API

API RESTful para gerenciamento de liturgias, cânticos, oradores, videos online e notificações do LouvorJA.

## Documentação Interativa

A documentação interativa está disponível em: `/api/documentation` (quando o PR #11 for mergeado)

## Base URL

```
https://api.louvorja.com.br/api
```

## Autenticação

Atualmente a API não requer autenticação para endpoints públicos.

## Endpoints Disponíveis

### 📖 Liturgia

#### Listar Liturgias
```
GET /liturgia/{data}
```

**Parâmetros:**
- `data` (path): Data no formato YYYY-MM-DD (opcional, usa data atual se não informado)

**Response:**
```json
{
  "id": 123,
  "data": "2024-01-01",
  "versiculo": "Versículo do dia",
  "reflexao": "Reflexão do dia",
  "oracao": "Oração do dia",
  "hino": 456,
  "cantico_manha": "Cântico da manhã",
  "cantico_tarde": "Cântico da tarde",
  "created_at": "2024-01-01T00:00:00Z",
  "updated_at": "2024-01-01T00:00:00Z"
}
```

#### Copiar Liturgia do Dia
```
GET /liturgia/copia/{data}
```

**Parâmetros:**
- `data` (path): Data no formato YYYY-MM-DD (opcional, usa data atual se não informado)

**Response:** Objeto JSON da liturgia copiada

#### Atualizar Liturgia
```
PUT /liturgia
Content-Type: application/json
```

**Body:**
```json
{
  "data": "2024-01-01",
  "versiculo": "Texto do versículo",
  "reflexao": "Texto da reflexão",
  "oracao": "Texto da oração",
  "hino": 456
}
```

---

### 🎵 Cânticos

#### Listar Cânticos
```
GET /cantico?busca={termo}&codigo={codigo}
```

**Parâmetros Query:**
- `busca` (opcional): Termo para busca em título, conteúdo ou número
- `codigo` (opcional): Número específico do cântico

**Response:**
```json
[
  {
    "id": 456,
    "titulo": "Título do Cântico",
    "conteudo": "Conteúdo completo do cântico",
    "numero": 456,
    "created_at": "2024-01-01T00:00:00Z",
    "updated_at": "2024-01-01T00:00:00Z"
  }
]
```

---

### 🎤 Oradores

#### Listar Oradores
```
GET /orador
```

**Response:**
```json
[
  {
    "id": 1,
    "nome": "Nome do Orador",
    "bio": "Biografia do orador",
    "foto": "URL da foto",
    "created_at": "2024-01-01T00:00:00Z",
    "updated_at": "2024-01-01T00:00:00Z"
  }
]
```

#### Buscar Orador por Nome
```
GET /orador/nome/{nome}
```

**Parâmetros:**
- `nome` (path): Nome ou parte do nome do orador

#### Atualizar Orador
```
PUT /orador
Content-Type: application/json
```

**Body:**
```json
{
  "id": 1,
  "nome": "Nome do Orador",
  "bio": "Biografia do orador",
  "foto": "URL da foto"
}
```

#### Criar Orador
```
POST /orador
Content-Type: application/json
```

**Body:**
```json
{
  "nome": "Nome do Orador",
  "bio": "Biografia do orador",
  "foto": "URL da foto"
}
```

---

### 📺 Vídeos Online

#### Listar Vídeos Online (Formato SQL - Padrão)
```
GET /onlinevideos?lang={id_language}&tipo={tipo}&id={id}
```

**Parâmetros Query:**
- `lang` (opcional): Idioma (`pt`, `en`, `es`). Padrão: `pt`
- `tipo` (opcional): Tipo de retorno (`canais`, `playlists`, `videos`, `tudo`). Padrão: `tudo`
- `id` (opcional): ID do canal ou playlist para filtro

**Response (SQL):** String com comandos SQL separados por `|` para compatibilidade com desktop

#### Listar Vídeos Online (Formato JSON - Moderno)
```
GET /onlinevideos?format=json&lang={id_language}&tipo={tipo}&id={id}
```

**Parâmetros Query:**
- `format` (opcional): `sql` (padrão) ou `json`
- `lang` (opcional): Idioma (`pt`, `en`, `es`). Padrão: `pt`
- `tipo` (opcional): Tipo de retorno (`canais`, `playlists`, `videos`, `tudo`). Padrão: `tudo`
- `id` (opcional): ID do canal ou playlist para filtro

**Response (JSON):**
```json
{
  "channels": [
    {
      "channel_id": "UC...",
      "title": "Nome do Canal",
      "custom_url": "custom/url",
      "default_image": "https://...",
      "default_image_base64": "data:image/png;base64,..."
    }
  ],
  "playlists": [
    {
      "playlist_id": "PL...",
      "channel_id": "UC...",
      "title": "Nome da Playlist",
      "default_image": "https://...",
      "default_image_base64": "data:image/png;base64,..."
    }
  ],
  "videos": [
    {
      "video_id": "...",
      "playlist_id": "PL...",
      "title": "Título do Vídeo",
      "sequence": 1,
      "default_image": "https://...",
      "default_image_base64": "data:image/png;base64,..."
    }
  ]
}
```

---

### 🔧 Sistema

#### Interface de Rede
```
POST /network-interface
Content-Type: application/json
```

**Body:**
```json
{
  "interface": "eth0"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Interface de rede atualizada"
}
```

## Formato de Resposta

Sucesso:
```json
{
  "data": { ... }
}
```

Erro:
```json
{
  "error": "Mensagem de erro",
  "status": 400
}
```

## Código de Status HTTP

- `200`: Sucesso
- `400`: Bad Request
- `404`: Não encontrado
- `500`: Erro interno do servidor

## Repositório

- GitHub: https://github.com/louvorja/api
- Issues: https://github.com/louvorja/api/issues

## Licença

Este projeto está sob licença proprietária.