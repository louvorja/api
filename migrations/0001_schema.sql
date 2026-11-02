-- Esquema traduzido das migrations de louvorja/api (MySQL -> D1/SQLite).
--
-- increments -> INTEGER PRIMARY KEY AUTOINCREMENT; time/datetime -> TEXT;
-- boolean -> INTEGER; json -> TEXT; enum -> TEXT com CHECK. Índices únicos e
-- chaves estrangeiras são os da origem. Datas ficam em UTC, 'YYYY-MM-DD HH:MM:SS'.
--

CREATE TABLE languages (
  id_language TEXT PRIMARY KEY,
  language    TEXT,
  created_at  TEXT,
  updated_at  TEXT
);

-- `file_name` é o nome do objeto que existe no acervo (.opus/.jpg), não o do
-- arquivo original da origem; `name` guarda o nome original.
CREATE TABLE files (
  id_file        INTEGER PRIMARY KEY AUTOINCREMENT,
  name           TEXT NOT NULL,
  type           TEXT NOT NULL,
  size           INTEGER NOT NULL,
  dir            TEXT NOT NULL,
  file_name      TEXT NOT NULL,
  image_position INTEGER,
  duration       TEXT,
  version        INTEGER NOT NULL,
  created_at     TEXT,
  updated_at     TEXT,
  UNIQUE (dir, file_name)
);
CREATE INDEX files_type ON files (type);

CREATE TABLE albums (
  id_album      INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT,
  id_file_image INTEGER REFERENCES files (id_file),
  color         TEXT NOT NULL DEFAULT '',
  id_language   TEXT NOT NULL REFERENCES languages (id_language),
  created_at    TEXT,
  updated_at    TEXT
);

CREATE TABLE categories (
  id_category INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT,
  slug        TEXT,
  "order"     INTEGER NOT NULL,
  type        TEXT,
  id_language TEXT NOT NULL REFERENCES languages (id_language),
  created_at  TEXT,
  updated_at  TEXT,
  UNIQUE (slug, id_language)
);

CREATE TABLE musics (
  id_music                   INTEGER PRIMARY KEY AUTOINCREMENT,
  name                       TEXT,
  id_file_image              INTEGER REFERENCES files (id_file),
  id_file_music              INTEGER REFERENCES files (id_file),
  id_file_instrumental_music INTEGER REFERENCES files (id_file),
  id_language                TEXT NOT NULL REFERENCES languages (id_language),
  created_at                 TEXT,
  updated_at                 TEXT
);

CREATE TABLE lyrics (
  id_lyric          INTEGER PRIMARY KEY AUTOINCREMENT,
  id_music          INTEGER NOT NULL REFERENCES musics (id_music),
  lyric             TEXT NOT NULL,
  aux_lyric         TEXT,
  id_file_image     INTEGER REFERENCES files (id_file),
  time              TEXT NOT NULL,
  instrumental_time TEXT NOT NULL,
  show_slide        INTEGER NOT NULL,
  "order"           INTEGER NOT NULL,
  id_language       TEXT NOT NULL REFERENCES languages (id_language),
  created_at        TEXT,
  updated_at        TEXT,
  UNIQUE (id_music, "order")
);

CREATE TABLE albums_musics (
  id_album_music INTEGER PRIMARY KEY AUTOINCREMENT,
  id_album       INTEGER NOT NULL REFERENCES albums (id_album),
  id_music       INTEGER NOT NULL REFERENCES musics (id_music),
  track          INTEGER NOT NULL,
  id_language    TEXT NOT NULL REFERENCES languages (id_language),
  created_at     TEXT,
  updated_at     TEXT,
  UNIQUE (id_album, id_music)
);
CREATE INDEX albums_musics_id_music ON albums_musics (id_music);

CREATE TABLE categories_albums (
  id_category_album INTEGER PRIMARY KEY AUTOINCREMENT,
  id_category       INTEGER NOT NULL REFERENCES categories (id_category),
  id_album          INTEGER NOT NULL REFERENCES albums (id_album),
  name              TEXT NOT NULL,
  "order"           INTEGER NOT NULL,
  id_language       TEXT NOT NULL REFERENCES languages (id_language),
  created_at        TEXT,
  updated_at        TEXT,
  UNIQUE (id_category, id_album)
);
CREATE INDEX categories_albums_id_album ON categories_albums (id_album);

CREATE TABLE configs (
  key        TEXT PRIMARY KEY,
  type       TEXT NOT NULL CHECK (type IN ('string', 'json', 'number', 'date', 'time', 'datetime')),
  value      TEXT,
  details    TEXT,
  created_at TEXT,
  updated_at TEXT
);

CREATE TABLE bible_book (
  id_bible_book INTEGER PRIMARY KEY AUTOINCREMENT,
  book_number   INTEGER NOT NULL,
  name          TEXT NOT NULL,
  chapters      INTEGER NOT NULL,
  testament     INTEGER NOT NULL,
  keywords      TEXT,
  abbreviation  TEXT NOT NULL,
  color         TEXT NOT NULL,
  id_language   TEXT NOT NULL REFERENCES languages (id_language),
  created_at    TEXT,
  updated_at    TEXT,
  UNIQUE (book_number, id_language)
);

CREATE TABLE bible_version (
  id_bible_version INTEGER PRIMARY KEY AUTOINCREMENT,
  name             TEXT NOT NULL,
  abbreviation     TEXT NOT NULL,
  id_language      TEXT NOT NULL REFERENCES languages (id_language),
  created_at       TEXT,
  updated_at       TEXT,
  UNIQUE (abbreviation, id_language)
);

CREATE TABLE bible_verse (
  id_bible_verse   INTEGER PRIMARY KEY AUTOINCREMENT,
  id_bible_version INTEGER NOT NULL REFERENCES bible_version (id_bible_version),
  id_bible_book    INTEGER NOT NULL REFERENCES bible_book (id_bible_book),
  chapter          INTEGER NOT NULL,
  verse            INTEGER NOT NULL,
  text             TEXT NOT NULL,
  id_language      TEXT NOT NULL REFERENCES languages (id_language),
  created_at       TEXT,
  updated_at       TEXT,
  UNIQUE (id_bible_version, id_bible_book, chapter, verse)
);

-- NOCASE: no MySQL de origem a comparação de usuário e e-mail ignora caixa.
CREATE TABLE users (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  name                  TEXT NOT NULL,
  username              TEXT NOT NULL COLLATE NOCASE UNIQUE,
  email                 TEXT NOT NULL COLLATE NOCASE UNIQUE,
  password              TEXT NOT NULL,
  is_temporary_password INTEGER NOT NULL DEFAULT 1,
  is_admin              INTEGER NOT NULL DEFAULT 0,
  phone                 TEXT,
  permissions           TEXT,
  created_at            TEXT,
  updated_at            TEXT
);

CREATE TABLE logs (
  id_log     INTEGER PRIMARY KEY AUTOINCREMENT,
  "table"    TEXT NOT NULL,
  action     TEXT NOT NULL,
  old_values TEXT,
  new_values TEXT,
  user_id    INTEGER,
  user       TEXT,
  created_at TEXT,
  updated_at TEXT
);

CREATE TABLE download_logs (
  id_download_log      INTEGER PRIMARY KEY AUTOINCREMENT,
  version              TEXT NOT NULL,
  ip                   TEXT,
  http_client_ip       TEXT,
  http_x_forwarded_for TEXT,
  remote_addr          TEXT,
  browser              TEXT,
  id_language          TEXT NOT NULL REFERENCES languages (id_language),
  created_at           TEXT,
  updated_at           TEXT
);

CREATE TABLE online_videos_channels (
  id_online_video_channel INTEGER PRIMARY KEY AUTOINCREMENT,
  channel_id              TEXT NOT NULL UNIQUE,
  title                   TEXT,
  description             TEXT,
  custom_url              TEXT,
  default_image           TEXT,
  medium_image            TEXT,
  high_image              TEXT,
  default_image_base64    TEXT,
  error                   TEXT,
  status                  TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'validated', 'error')),
  playlists               TEXT NOT NULL,
  id_language             TEXT NOT NULL REFERENCES languages (id_language),
  created_at              TEXT,
  updated_at              TEXT
);

CREATE TABLE online_videos_playlists (
  id_online_video_playlist INTEGER PRIMARY KEY AUTOINCREMENT,
  id_online_video_channel  INTEGER NOT NULL REFERENCES online_videos_channels (id_online_video_channel) ON UPDATE CASCADE ON DELETE CASCADE,
  playlist_id              TEXT NOT NULL UNIQUE,
  title                    TEXT,
  description              TEXT,
  default_image            TEXT,
  medium_image             TEXT,
  high_image               TEXT,
  standard_image           TEXT,
  maxres_image             TEXT,
  default_image_base64     TEXT,
  error                    TEXT,
  status                   TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'validated', 'error')),
  id_language              TEXT NOT NULL REFERENCES languages (id_language),
  created_at               TEXT,
  updated_at               TEXT
);

CREATE TABLE online_videos (
  id_online_video          INTEGER PRIMARY KEY AUTOINCREMENT,
  id_online_video_playlist INTEGER NOT NULL REFERENCES online_videos_playlists (id_online_video_playlist) ON UPDATE CASCADE ON DELETE CASCADE,
  video_id                 TEXT NOT NULL,
  title                    TEXT,
  description              TEXT,
  sequence                 INTEGER,
  default_image            TEXT,
  medium_image             TEXT,
  high_image               TEXT,
  standard_image           TEXT,
  maxres_image             TEXT,
  default_image_base64     TEXT,
  error                    TEXT,
  status                   TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'validated', 'error')),
  id_language              TEXT NOT NULL REFERENCES languages (id_language),
  created_at               TEXT,
  updated_at               TEXT,
  UNIQUE (id_online_video_playlist, video_id)
);

-- Tokens encerrados por logout ou renovação. A origem guardava isso no cache
-- em arquivo do jwt-auth; aqui o token deixa de valer a partir de `blocked_after`.
CREATE TABLE jwt_blacklist (
  jti           TEXT PRIMARY KEY,
  blocked_after INTEGER NOT NULL,
  expires_at    INTEGER NOT NULL
);
