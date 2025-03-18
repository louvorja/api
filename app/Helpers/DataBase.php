<?php

namespace App\Helpers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use App\Helpers\Tables;
use App\Helpers\Configs;
use App\Helpers\Files;
use App\Models\File as FileModel;
use App\Models\Music;
use App\Models\Category;
use App\Models\Album;
use App\Models\Lyric;
use App\Models\Language;
use App\Models\BibleVersion;
use App\Models\BibleBook;
use App\Models\BibleVerse;


class DataBase
{

    public static function fn_sqlite_no_accents($field)
    {
        return "
        LOWER(
        REPLACE(
        REPLACE(
        REPLACE(
        REPLACE(
        REPLACE(
        REPLACE(
        REPLACE(
        REPLACE(
        REPLACE(
        REPLACE(
        REPLACE(
        REPLACE(
        REPLACE(
        REPLACE(
        REPLACE(
        LOWER($field),
        'á', 'a'),
        'ã', 'a'),
        'â', 'a'),
        'à', 'a'),
        'é', 'e'),
        'ê', 'e'),
        'í', 'i'),
        'ó', 'o'),
        'õ', 'o'),
        'ô', 'o'),
        'ú', 'u'),
        'ç', 'c'),
        'É', 'e'),
        'Ê', 'e'),
        'Ó', 'o')
        )";
    }

    public static function save_file($dir, $name, $data)
    {
        try {
            file_put_contents($dir . $name, $data);
            return [
                'status' => 'success',
                'file' => $dir . $name,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'file' => $dir . $name,
            ];
        }
    }

    public static function export_json()
    {
        $logs = [];

        $path = app()->basePath('public/db/json/');
        if (!File::exists($path)) {
            File::makeDirectory($path, 0755, true);
        }

        /*$files = File::files($path);
        foreach ($files as $file) {
            File::delete($file);
        }*/

        $config = Configs::get(["version_number", "version", "datetime", "latest_updated"]);
        $ret = self::save_file($path, "config.json", json_encode($config));
        $logs[] = $ret;

        $langs = Language::orderBy("id_language", "desc")->get();
        foreach ($langs as $lang) {
            $l = $lang->id_language;

            $data = Music::select([
                'musics.id_music',
                'musics.name',
                DB::raw("if(ifnull(id_file_instrumental_music,0) > 0,1,0) as has_instrumental_music"),
                DB::raw("files_music.duration as duration"),
                DB::raw("(select group_concat(lyric separator ' ') from lyrics where lyrics.id_music=musics.id_music) as lyric"),
                DB::raw("(select group_concat(distinct albums.name separator '|')
                    from albums
                    inner join albums_musics on (albums_musics.id_album=albums.id_album)
                    inner join categories_albums on (categories_albums.id_album=albums.id_album)
                    inner join categories on (categories.id_category=categories_albums.id_category)
                    where 1=1
                    and categories.type in ('hymnal','collection')
                    and albums_musics.id_music=musics.id_music) as albums_names"),
            ])
                ->leftJoin('files as files_music', 'musics.id_file_music', 'files_music.id_file')
                ->where("id_language", $l)
                ->with(['albums' => function ($query) {
                    $query->select(['albums.id_album', 'albums.name',    DB::raw('min(categories.order) as `order`')])
                        ->leftJoin('categories_albums', 'categories_albums.id_album', 'albums.id_album')
                        ->leftJoin('categories', 'categories.id_category', 'categories_albums.id_category')
                        ->whereIn('categories.type', ['hymnal', 'collection'])
                        ->groupBy(['albums.id_album', 'albums.name', 'albums_musics.id_music', 'albums_musics.id_album', 'albums_musics.track'])
                        ->orderBy('order');
                }])
                ->get();
            //dd($data->toArray());
            $ret = self::save_file($path, $l . "_musics.json", $data->toJson());
            $logs[] = $ret;

            $categories = Category::select()->where("type", "hymnal")->where("id_language", $l)->get();
            foreach ($categories as $category) {
                $data = Music::select([
                    'musics.id_music',
                    'musics.name',
                    'albums_musics.track',
                    DB::raw("if(ifnull(id_file_instrumental_music,0) > 0,1,0) as has_instrumental_music"),
                    DB::raw("files_music.duration as duration"),
                    DB::raw("(select group_concat(lyric separator ' ') from lyrics where lyrics.id_music=musics.id_music) as lyric"),
                ])
                    ->leftJoin('files as files_music', 'musics.id_file_music', 'files_music.id_file')
                    ->join('albums_musics', 'albums_musics.id_music', 'musics.id_music')
                    ->join('categories_albums', 'categories_albums.id_album', 'albums_musics.id_album')
                    ->join('categories', 'categories.id_category', 'categories_albums.id_category')
                    ->where("categories.id_category", $category->id_category)
                    ->where("musics.id_language", $l)
                    ->orderBy('albums_musics.track')
                    ->get();
                //dd($data->toArray());
                $ret = self::save_file($path, $l . "_" . $category->slug . ".json", $data->toJson());
                $logs[] = $ret;
            }

            $data = Category::select([
                "id_category",
                "name",
                "slug",
                "order"
            ])
                ->where("type", "collection")->where("id_language", $l)
                ->orderBy("order")
                ->with(['albums' => function ($query) {
                    $query->select([
                        'albums.id_album',
                        'albums.name',
                        'albums.color',
                        DB::raw("concat(files_image.dir,'/',files_image.file_name) as url_image"),
                        DB::raw("categories_albums.name as subtitle"),
                        'categories_albums.order'
                    ])
                        ->leftJoin('files as files_image', 'albums.id_file_image', 'files_image.id_file')
                        ->orderBy('categories_albums.order');
                }])
                ->get();
            $data->each(function ($item) {
                $item->albums->makeHidden('pivot');
            });
            //dd($data->toArray());
            $ret = self::save_file($path, $l . "_categories.json", $data->toJson());
            $logs[] = $ret;

            $data = BibleVersion::select([
                "id_bible_version",
                "name",
                "abbreviation"
            ])
                ->where("id_language", $l)
                ->orderBy("name")
                ->get();
            //dd($data->toArray());
            $ret = self::save_file($path, $l . "_bible_version.json", $data->toJson());
            $logs[] = $ret;

            $data = BibleBook::select([
                "id_bible_book",
                "book_number",
                "name",
                "chapters",
                "testament",
                "keywords",
                "abbreviation",
                "color"
            ])
                ->where("id_language", $l)
                ->orderBy("book_number")
                ->get();
            //dd($data->toArray());
            $ret = self::save_file($path, $l . "_bible_book.json", $data->toJson());
            $logs[] = $ret;
        }


        $musics = Music::select([
            'musics.id_music',
            'musics.name',
            DB::raw("files_music.duration as duration"),
            DB::raw("files_instrumental_music.duration as instrumental_duration"),
            DB::raw("concat(files_image.dir,'/',files_image.file_name) as url_image"),
            DB::raw("files_image.image_position"),
            DB::raw("concat(files_music.dir,'/',files_music.file_name) as url_music"),
            DB::raw("concat(files_instrumental_music.dir,'/',files_instrumental_music.file_name) as url_instrumental_music"),
        ])
            ->leftJoin('files as files_image', 'musics.id_file_image', 'files_image.id_file')
            ->leftJoin('files as files_music', 'musics.id_file_music', 'files_music.id_file')
            ->leftJoin('files as files_instrumental_music', 'musics.id_file_instrumental_music', 'files_instrumental_music.id_file')
            ->with(['lyric' => function ($query) {
                $query->select([
                    'lyrics.id_lyric',
                    'lyrics.id_music',
                    'lyrics.lyric',
                    'lyrics.aux_lyric',
                    DB::raw("concat(files_image.dir,'/',files_image.file_name) as url_image"),
                    DB::raw("files_image.image_position"),
                    'lyrics.time',
                    DB::raw('if(lyrics.instrumental_time = 0,lyrics.time,lyrics.instrumental_time) as instrumental_time'),
                    'lyrics.show_slide',
                    'lyrics.order',
                ])
                    ->leftJoin('files as files_image', 'lyrics.id_file_image', 'files_image.id_file')
                    ->orderBy('lyrics.order', 'asc');
            }])
            ->with(['albums' => function ($query) {
                $query->select([
                    'albums.id_album',
                    'albums.name',
                    'albums_musics.track',
                    DB::raw("concat(files_image.dir,'/',files_image.file_name) as url_image"),
                    DB::raw('min(categories.order) as `order`'),
                ])
                    ->leftJoin('files as files_image', 'albums.id_file_image', 'files_image.id_file')
                    ->leftJoin('categories_albums', 'categories_albums.id_album', 'albums.id_album')
                    ->leftJoin('categories', 'categories.id_category', 'categories_albums.id_category')
                    ->whereIn('categories.type', ['hymnal', 'collection'])
                    ->groupBy(['albums.id_album', 'albums.name', 'albums_musics.id_music', 'albums_musics.id_album', 'albums_musics.track'])
                    ->orderBy('order')
                ;
            }])
            ->get();
        $musics->each(function ($item) {
            $item->albums->makeHidden('pivot');
        });
        foreach ($musics as $music) {
            $ret = self::save_file($path, "music_" . $music->id_music . ".json", $music->toJson());
            $logs[] = $ret;
        }
        //dd($musics->toJson());


        $albums = Album::select([
            'albums.id_album',
            'albums.name',
            'albums.color',
            DB::raw("concat(files_image.dir,'/',files_image.file_name) as url_image"),
            DB::raw("(
                select group_concat(concat(type,'.',slug) separator '|') from categories
                    inner join categories_albums on (categories_albums.id_category=categories.id_category)
                    where categories_albums.id_album=albums.id_album
            ) as categories"),
        ])
            ->leftJoin('files as files_image', 'albums.id_file_image', 'files_image.id_file')
            ->with(['musics' => function ($query) {
                $query->select([
                    'musics.id_music',
                    'musics.name',
                    DB::raw("if(ifnull(id_file_instrumental_music,0) > 0,1,0) as has_instrumental_music"),
                    DB::raw("files_music.duration as duration"),
                    'albums_musics.track',
                ])
                    ->leftJoin('files as files_music', 'musics.id_file_music', 'files_music.id_file')
                    ->orderBy('albums_musics.track', 'asc');
            }])
            ->get();
        $albums->each(function ($item) {
            $item->musics->makeHidden('pivot');
            $item->categories = explode("|", $item->categories);
        });
        //dd($albums->toJson());
        foreach ($albums as $album) {
            $ret = self::save_file($path, "album_" . $album->id_album . ".json", $album->toJson());
            $logs[] = $ret;
        }

        $verses = BibleVerse::select()
            ->orderBy('id_bible_version')
            ->orderBy('id_bible_book')
            ->orderBy('chapter')
            ->orderBy('verse')
            ->get();

        //dd($verses->toArray());
        $key = "";
        $data = [];
        foreach ($verses as $verse) {
            $last_key = $verse->id_bible_version . "_" . $verse->id_bible_book . "_" . $verse->chapter;
            if ($key <> $last_key) {
                if ($key <> "") {
                    $ret = self::save_file($path, "bible_" . $key . ".json", json_encode($data));
                    $logs[] = $ret;
                }
                $data = [];
                $key = $last_key;
            }
            $data[$verse->verse] =  $verse->text;
        }
        $ret = self::save_file($path, "bible_" . $key . ".json", json_encode($data));
        $logs[] = $ret;

        return $logs;
    }

    public static function export()
    {

        $database = env('DB_SQLITE_DATABASE');
        $url_database = app()->basePath('public/db/' . basename($database));

        if (File::exists($database)) {
            unlink($database);
        }

        $dir_database = dirname($database);
        if ($dir_database <> "") {
            if (!file_exists($dir_database)) {
                mkdir($dir_database, 0755, true);
            }
        }

        $tables = Tables::public();
        $system_tables = Tables::system();
        touch($database);

        Artisan::call('migrate', [
            '--database' => 'sqlite',
            '--path' => 'database/migrations',
        ]);

        DB::connection('sqlite')->statement("ATTACH DATABASE '{$database}' AS sqlite_db");
        foreach ($system_tables as $table) {
            DB::connection('sqlite')->statement("DROP TABLE IF EXISTS {$table}");
        }

        DB::connection('sqlite')->statement('PRAGMA foreign_keys = OFF;');

        $log = [];
        $chunkSize = 500;
        foreach ($tables as $table) {
            try {
                $log[$table]["table_name"] = $table;

                if ($table == "languages") {
                    DB::connection('sqlite')->table($table)->truncate();
                }

                if ($table == "files") {
                    $data = DB::connection('mysql')->table($table)->get();
                } else {
                    $data = DB::connection('mysql')->table($table)->where('id_language', 'pt')->get();
                }
                $log[$table]["count"] = $data->count();
                $chunks = array_chunk($data->toArray(), $chunkSize);
                DB::connection('sqlite')->beginTransaction();
                try {
                    foreach ($chunks as $chunk) {
                        $chunk = json_decode(json_encode($chunk), true);
                        DB::connection('sqlite')->table($table)->insert($chunk);
                    }
                    DB::connection('sqlite')->commit();
                } catch (\Exception $e) {
                    DB::connection('sqlite')->rollBack();
                    $log[$table]["error"] = $e->getMessage();
                    $log[$table]["status"] = "error1";
                }
            } catch (\Exception $e) {
                $log[$table]["error"] = $e->getMessage();
                $log[$table]["status"] = "error2";
            }
        }
        dd($log);
        /* CRIAÇÃO DE VIEWS E TABELAS PARA RETROCOMPATIBILIDADE (COM A VERSÂO DELPHI) */

        DB::connection('sqlite')->statement("CREATE VIEW ALBUM AS
            SELECT
                albums.id_album ID,
                albums.name NOME,
                files.file_name IMAGEM,
                CASE WHEN categories.slug = 'hymnal'
                    THEN 'N'
                    ELSE 'S'
                END AS PERMITE_DESATIVAR
            FROM albums
            LEFT JOIN files ON files.id_file = albums.id_file_image
            LEFT JOIN categories_albums ON categories_albums.id_album = albums.id_album
            LEFT JOIN categories ON categories.id_category = categories_albums.id_category
            WHERE albums.id_language = 'pt'");

        DB::connection('sqlite')->statement("CREATE TABLE ALBUM_MUSICAS AS
            SELECT
                albums_musics.id_album ID_ALBUM,
                albums_musics.id_music ID_MUSICA,
                albums_musics.track FAIXA
            FROM albums_musics
            WHERE albums_musics.id_language = 'pt'");

        DB::connection('sqlite')->statement("CREATE VIEW ALBUM_TIPO AS
            SELECT
                categories_albums.id_album ID_ALBUM,
                CASE
                    WHEN categories.slug = 'misc' THEN 'DIV'
                    WHEN categories.slug = 'doxology' THEN 'DOX'
                    WHEN categories.slug = 'hymnal' THEN 'HASD'
                    WHEN categories.slug = 'hymnal_1996' THEN 'HASD_1996'
                    WHEN categories.slug = 'children' THEN 'INF'
                    WHEN categories.slug = 'aym' THEN 'JA_ANO'
                    ELSE 'DIV'
                END AS TIPO,
                categories_albums.name SUBTITULO,
                categories_albums.`order` ORDEM
            FROM categories_albums
            LEFT JOIN categories ON categories.id_category = categories_albums.id_category
            WHERE categories_albums.id_language = 'pt'");

        DB::connection('sqlite')->statement("CREATE TABLE TIPOS_ALBUM AS
            SELECT 'DIV' ID, 'Diversas' TIPO
            UNION
            SELECT 'DOX' ID, 'Doxologia' TIPO
            UNION
            SELECT 'HASD' ID, 'Hinário Adventista' TIPO
            UNION
            SELECT 'HASD_1996' ID, 'Hinário Adventista 1996' TIPO
            UNION
            SELECT 'INF' ID, 'Infantis' TIPO
            UNION
            SELECT 'JA_ANO' ID, 'CDs Oficiais/Ano' TIPO");

        DB::connection('sqlite')->statement("CREATE TABLE ARQUIVOS_ADICIONAIS AS
            SELECT '1' AS ID, '1minuto_escsb.mp3' AS ARQUIVO, 'config\\1minuto_escsb.mp3' AS URL
            UNION ALL
            SELECT '2' AS ID, '5minutos_escsb.mp3' AS ARQUIVO, 'config\\5minutos_escsb.mp3' AS URL
            UNION ALL
            SELECT '3' AS ID, 'abertura_escsb.mp3' AS ARQUIVO, 'config\\abertura_escsb.mp3' AS URL
            UNION ALL
            SELECT '4' AS ID, 'din-condensed-bold.ttf' AS ARQUIVO, 'config\\fontes\\din-condensed-bold.ttf' AS URL
            UNION ALL
            SELECT '5' AS ID, 'database.db' AS ARQUIVO, 'config\\database.db' AS URL
            UNION ALL
            SELECT '6' AS ID, 'bass.dll' AS ARQUIVO, 'bass.dll' AS URL
            UNION ALL
            SELECT '7' AS ID, 'borlndmm.dll' AS ARQUIVO, 'borlndmm.dll' AS URL
            UNION ALL
            SELECT '8' AS ID, 'midas.dll' AS ARQUIVO, 'midas.dll' AS URL
            UNION ALL
            SELECT '10' AS ID, 'LouvorJA.exe' AS ARQUIVO, 'LouvorJA.exe' AS URL
            UNION ALL
            SELECT '11' AS ID, 'ssleay32.dll' AS ARQUIVO, 'ssleay32.dll' AS URL
            UNION ALL
            SELECT '12' AS ID, 'libeay32.dll' AS ARQUIVO, 'libeay32.dll' AS URL
            UNION ALL
            SELECT '13' AS ID, 'louvorja_slja.ico' AS ARQUIVO, 'config\\ico\\louvorja_slja.ico' AS URL
            UNION ALL
            SELECT '14' AS ID, 'pagina_nao_encontrada.htm' AS ARQUIVO, 'config\\server\\pagina_nao_encontrada.htm' AS URL
            UNION ALL
            SELECT '15' AS ID, 'page.htm' AS ARQUIVO, 'config\\server\\page.htm' AS URL
            UNION ALL
            SELECT '16' AS ID, 'index.htm' AS ARQUIVO, 'config\\server\\index.htm' AS URL
            UNION ALL
            SELECT '17' AS ID, 'file.ja' AS ARQUIVO, 'config\\server\\file\\file.ja' AS URL
            UNION ALL
            SELECT '18' AS ID, 'estilo.css' AS ARQUIVO, 'config\\server\\lib\\estilo.css' AS URL
            UNION ALL
            SELECT '19' AS ID, 'scripts.js' AS ARQUIVO, 'config\\server\\lib\\scripts.js' AS URL");

        DB::connection('sqlite')->statement("CREATE TABLE IMAGEM_POSICAO AS
            SELECT `name` IMAGEM,image_position POSICAO FROM files WHERE image_position IS NOT NULL");

        DB::connection('sqlite')->statement("CREATE VIEW MUSICAS AS
            SELECT
                musics.id_music ID,
                substr(files_url.dir, 12, 100) ALBUM,
                musics.name NOME,
                files_image.name IMAGEM,
                files_url.name URL,
                files_url_instrumental.name URL_INSTRUMENTAL,
                upper(musics.id_language) IDIOMA,
                0 TAMANHO_LETRA,
                '' COR_LETRA,
                1 FUNDO_LETRA,
                files_image.size TAMANHO_IMAGEM,
                files_url.size TAMANHO_ARQUIVO,
                files_url_instrumental.size TAMANHO_ARQUIVO_PB,
                (SELECT GROUP_CONCAT(lyric) FROM lyrics WHERE lyrics.id_music = musics.id_music) LETRA
            FROM musics
            INNER JOIN albums_musics ON albums_musics.id_music = musics.id_music
            LEFT JOIN files files_image ON files_image.id_file = musics.id_file_image
            LEFT JOIN files files_url ON files_url.id_file = musics.id_file_music
            LEFT JOIN files files_url_instrumental ON files_url_instrumental.id_file = musics.id_file_instrumental_music
            WHERE musics.id_language = 'pt'");

        DB::connection('sqlite')->statement("CREATE VIEW MUSICAS_LETRA AS
            SELECT
                lyrics.id_lyric ID,
                lyrics.show_slide EXIBE_SLIDE,
                lyrics.`order` ORDEM,
                files.name IMAGEM,
                lyrics.lyric LETRA,
                '' COR_LETRA,
                lyrics.id_music MUSICA,
                lyrics.time TEMPO,
                IIF(lyrics.instrumental_time = '00:00:00',lyrics.time,lyrics.instrumental_time) TEMPO_PB,
                1 FUNDO_LETRA,
                0 TAMANHO_LETRA,
                lyrics.aux_lyric LETRA_AUX,
                0 TAMANHO_LETRA_AUX,
                '' COR_LETRA_AUX,
                files.size TAMANHO_IMAGEM
            FROM lyrics
            LEFT JOIN files ON files.id_file = lyrics.id_file_image
            WHERE lyrics.id_language = 'pt'");


        $version = Configs::get("version");
        DB::connection('sqlite')->statement("CREATE TABLE VERSAO AS
            SELECT
                1 ID,
                '" . substr($version, 0, 5) . "." .  substr($version, 5, 5) . "' VERSAO_BD
        ");

        DB::connection('sqlite')->statement("CREATE VIEW HINARIO_ADVENTISTA AS
            SELECT
                musics.id_music ID,
                albums_musics.track FAIXA,
                musics.name NOME,
                " . self::fn_sqlite_no_accents("musics.name") . " AS NOME_SEMAC,
                printf('%03d', albums_musics.track) || ' - ' || musics.name NOME_COM,
                substr(files_url.dir, 12, 100) ALBUM,
                files_url.name URL,
                files_url_instrumental.name URL_INSTRUMENTAL
            FROM musics
            INNER JOIN albums_musics ON albums_musics.id_music = musics.id_music
            INNER JOIN categories_albums ON categories_albums.id_album = albums_musics.id_album
            INNER JOIN categories ON categories.id_category = categories_albums.id_category
            LEFT JOIN files files_url ON files_url.id_file = musics.id_file_music
            LEFT JOIN files files_url_instrumental ON files_url_instrumental.id_file = musics.id_file_instrumental_music
            WHERE musics.id_language = 'pt'
                AND categories.slug = 'hymnal'
            ORDER BY albums_musics.track");

        DB::connection('sqlite')->statement("CREATE VIEW HINARIO_ADVENTISTA_1996 AS
            SELECT
                musics.id_music ID,
                albums_musics.track FAIXA,
                musics.name NOME,
                " . self::fn_sqlite_no_accents("musics.name") . " AS NOME_SEMAC,
                printf('%03d', albums_musics.track) || ' - ' || musics.name NOME_COM,
                substr(files_url.dir, 12, 100) ALBUM,
                files_url.name URL,
                files_url_instrumental.name URL_INSTRUMENTAL
            FROM musics
            INNER JOIN albums_musics ON albums_musics.id_music = musics.id_music
            INNER JOIN categories_albums ON categories_albums.id_album = albums_musics.id_album
            INNER JOIN categories ON categories.id_category = categories_albums.id_category
            LEFT JOIN files files_url ON files_url.id_file = musics.id_file_music
            LEFT JOIN files files_url_instrumental ON files_url_instrumental.id_file = musics.id_file_instrumental_music
            WHERE musics.id_language = 'pt'
                AND categories.slug = 'hymnal_1996'
            ORDER BY albums_musics.track");

        DB::connection('sqlite')->statement("CREATE TABLE _ALBUM_IGNORAR (ID INT)");
        DB::connection('sqlite')->statement("CREATE TABLE _COLETANEAS_PERSONALIZADAS (ID STRING, NOME STRING, URL STRING)");

        DB::connection('sqlite')->statement("CREATE TABLE ONL_CANAIS (CANAL_ID STRING, NOME STRING, CUSTOM_URL STRING, IMAGEM STRING)");
        DB::connection('sqlite')->statement("CREATE TABLE ONL_PLAYLISTS (PLAYLIST_ID STRING, CANAL_ID STRING, NOME STRING, IMAGEM STRING)");
        DB::connection('sqlite')->statement("CREATE TABLE ONL_VIDEOS (VIDEO_ID STRING, PLAYLIST_ID STRING, NOME STRING, POSICAO INT, IMAGEM STRING)");

        DB::connection('sqlite')->statement("CREATE TABLE ARQUIVOS_SISTEMA AS
            SELECT 'ARQUIVOS_ADICIONAIS' AS TIPO,
                ARQUIVO,
                URL,
                0 AS TAMANHO,
                '' AS TABELA,
                '' AS CAMPO_ARQ,
                '' AS CAMPO_ARQ_TAM,
                '' AS CHAVE
            FROM ARQUIVOS_ADICIONAIS
            WHERE TRIM(URL) <> ''
            
            UNION
            
            SELECT 'MUSICA' AS TIPO,
                MUSICAS.URL AS ARQUIVO,
                'config\musicas\' || MUSICAS.ALBUM || '\' || MUSICAS.URL AS URL,
                TAMANHO_ARQUIVO AS TAMANHO,
                'MUSICAS' AS TABELA,
                'ALBUM' || '\' || URL AS CAMPO_ARQ,
                'TAMANHO_ARQUIVO' AS CAMPO_ARQ_TAM,
                MUSICAS.ALBUM || '\' || MUSICAS.URL AS CHAVE
            FROM ALBUM
                INNER JOIN (MUSICAS INNER JOIN ALBUM_MUSICAS ON MUSICAS.ID = ALBUM_MUSICAS.ID_MUSICA) ON ALBUM.ID = ALBUM_MUSICAS.ID_ALBUM
            WHERE 1=1
                AND ((ALBUM.PERMITE_DESATIVAR = 'N') OR (ALBUM.PERMITE_DESATIVAR = 'S' AND ALBUM.ID NOT IN (SELECT ID FROM _ALBUM_IGNORAR)))
                AND (TRIM(MUSICAS.URL) <> '')
                AND (TRIM(MUSICAS.ALBUM) <> '')
            
            UNION
            
            SELECT 'MUSICA_PB' AS TIPO,
                MUSICAS.URL_INSTRUMENTAL AS ARQUIVO,
                'config\musicas\' || MUSICAS.ALBUM || '\' || MUSICAS.URL_INSTRUMENTAL AS URL,
                TAMANHO_ARQUIVO_PB AS TAMANHO,
                'MUSICAS' AS TABELA,
                'ALBUM' || '\' || URL_INSTRUMENTAL AS CAMPO_ARQ,
                'TAMANHO_ARQUIVO_PB' AS CAMPO_ARQ_TAM,
                MUSICAS.ALBUM || '\' || MUSICAS.URL_INSTRUMENTAL AS CHAVE
            FROM ALBUM
                INNER JOIN (MUSICAS INNER JOIN ALBUM_MUSICAS ON MUSICAS.ID = ALBUM_MUSICAS.ID_MUSICA) ON ALBUM.ID = ALBUM_MUSICAS.ID_ALBUM
            WHERE 1=1
                AND ((ALBUM.PERMITE_DESATIVAR = 'N') OR (ALBUM.PERMITE_DESATIVAR = 'S' AND ALBUM.ID NOT IN (SELECT ID FROM _ALBUM_IGNORAR)))
                AND (TRIM(MUSICAS.URL_INSTRUMENTAL) <> '')
                AND (TRIM(MUSICAS.ALBUM) <> '')
            
            UNION
            
            SELECT 'IMAGEM_FUNDO' AS TIPO,
                IMAGEM AS ARQUIVO,
                'config\imagens\' || IMAGEM AS URL,
                TAMANHO_IMAGEM AS TAMANHO,
                'MUSICAS_LETRA' AS TABELA,
                'IMAGEM' AS CAMPO_ARQ,
                'TAMANHO_IMAGEM' AS CAMPO_ARQ_TAM,
                IMAGEM AS CHAVE
            FROM MUSICAS_LETRA
            WHERE TRIM(IMAGEM) <> ''
            
            UNION
            
            SELECT 'IMAGEM_FUNDO_CAPA' AS TIPO,
                IMAGEM AS ARQUIVO,
                'config\imagens\' || IMAGEM AS URL,
                TAMANHO_IMAGEM AS TAMANHO,
                'MUSICAS' AS TABELA,
                'IMAGEM' AS CAMPO_ARQ,
                'TAMANHO_IMAGEM' AS CAMPO_ARQ_TAM,
                IMAGEM AS CHAVE
            FROM MUSICAS
            WHERE TRIM(IMAGEM) <> ''
            
            UNION
            
            SELECT 'IMAGEM_ALBUM' AS TIPO,
                IMAGEM AS ARQUIVO,
                'config\capas\' || IMAGEM AS URL,
                0 AS TAMANHO,
                '' AS TABELA,
                '' AS CAMPO_ARQ,
                '' AS CAMPO_ARQ_TAM,
                '' AS CHAVE
            FROM ALBUM
            WHERE 1=1
                AND ((PERMITE_DESATIVAR = 'N') OR (PERMITE_DESATIVAR = 'S' AND ID NOT IN (SELECT ID FROM _ALBUM_IGNORAR)))
                AND TRIM(IMAGEM) <> ''
            
            UNION
            
            SELECT 'IMAGEM_ONL_CANAL' AS TIPO,
                CANAL_ID || '.jpg' AS ARQUIVO,
                'config\imagens_onl\canais\' || CANAL_ID || '.jpg' AS URL,
                0 AS TAMANHO,
                '' AS TABELA,
                '' AS CAMPO_ARQ,
                '' AS CAMPO_ARQ_TAM,
                '' AS CHAVE
            FROM ONL_CANAIS
            WHERE TRIM(CANAL_ID) <> ''
            
            UNION
            
            SELECT 'IMAGEM_ONL_PLAYLIST' AS TIPO,
                PLAYLIST_ID || '.jpg' AS ARQUIVO,
                'config\imagens_onl\playlists\' || PLAYLIST_ID || '.jpg' AS URL,
                0 AS TAMANHO,
                '' AS TABELA,
                '' AS CAMPO_ARQ,
                '' AS CAMPO_ARQ_TAM,
                '' AS CHAVE
            FROM ONL_PLAYLISTS
            WHERE TRIM(PLAYLIST_ID) <> ''
            
            UNION
            
            SELECT 'IMAGEM_ONL_VIDEOS' AS TIPO,
                VIDEO_ID || '.jpg' AS ARQUIVO,
                'config\imagens_onl\videos\' || VIDEO_ID || '.jpg' AS URL,
                0 AS TAMANHO,
                '' AS TABELA,
                '' AS CAMPO_ARQ,
                '' AS CAMPO_ARQ_TAM,
                '' AS CHAVE
            FROM ONL_VIDEOS
            WHERE TRIM(VIDEO_ID) <> ''
            
            ORDER BY ARQUIVO");

        DB::connection('sqlite')->statement("CREATE VIEW LISTA_MUSICAS AS
            SELECT M.ID,
                A.ID AS ID_ALBUM,
                A.NOME AS NOME_ALBUM,
                A.NOME ||
                COALESCE(
                    (SELECT ' (' || ALBUM_TIPO.SUBTITULO || ')'
                        FROM ALBUM_TIPO
                        WHERE ALBUM_TIPO.ID_ALBUM = A.ID
                        AND ALBUM_TIPO.TIPO = 'JA_ANO'
                    ),
                '') AS NOME_ALBUM_COM,
                
                AM.FAIXA,
                M.NOME ||
                (CASE WHEN EXISTS (SELECT 1 FROM ALBUM_TIPO WHERE ALBUM_TIPO.ID_ALBUM = A.ID AND ALBUM_TIPO.TIPO = 'HASD') THEN ' (Hino nº ' || printf('%03d', AM.FAIXA) || ') ' ELSE '' END) AS NOME,
                
                (CASE WHEN EXISTS (SELECT 1 FROM ALBUM_TIPO WHERE ALBUM_TIPO.ID_ALBUM = A.ID AND ALBUM_TIPO.TIPO = 'HASD')
                    THEN printf('%03d', AM.FAIXA) || ' - '
                    ELSE ''
                END) || M.NOME || ' (' ||
                COALESCE(
                    (SELECT ALBUM_TIPO.SUBTITULO || ' - '
                        FROM ALBUM_TIPO
                        WHERE ALBUM_TIPO.ID_ALBUM = A.ID
                        AND ALBUM_TIPO.TIPO = 'JA_ANO'
                    )
                ,'') || A.NOME || ')' AS NOME_COM,
                
                (CASE WHEN EXISTS (SELECT 1 FROM ALBUM_TIPO WHERE ALBUM_TIPO.ID_ALBUM = A.ID AND ALBUM_TIPO.TIPO = 'HASD') THEN 'S' ELSE 'N' END) AS TIPO_HASD,
                (CASE WHEN EXISTS (SELECT 1 FROM ALBUM_TIPO WHERE ALBUM_TIPO.ID_ALBUM = A.ID AND ALBUM_TIPO.TIPO = 'JA_ANO') THEN 'S' ELSE 'N' END) AS TIPO_JA,
                'S' AS TIPO_BAIXADA,
                'N' AS TIPO_WEB,
                'N' AS TIPO_PERSO,
                'B' AS TIPO,
                '' AS URL_ALBUM,
                M.ALBUM,
                M.URL,
                M.URL_INSTRUMENTAL,
                M.IDIOMA,
                M.LETRA,

                " . self::fn_sqlite_no_accents("M.NOME ||(CASE WHEN EXISTS (SELECT 1 FROM ALBUM_TIPO WHERE ALBUM_TIPO.ID_ALBUM = A.ID AND ALBUM_TIPO.TIPO = 'HASD') THEN ' (Hino nº ' || printf('%03d', AM.FAIXA) || ') ' ELSE '' END)") . " AS NOME_SEMAC,
                " . self::fn_sqlite_no_accents("M.LETRA") . " AS LETRA_SEMAC,
                " . self::fn_sqlite_no_accents("
                A.NOME ||
                COALESCE(
                    (SELECT ' (' || ALBUM_TIPO.SUBTITULO || ')'
                        FROM ALBUM_TIPO
                        WHERE ALBUM_TIPO.ID_ALBUM = A.ID
                        AND ALBUM_TIPO.TIPO = 'JA_ANO'
                    ),
                '')") . " AS NOME_ALBUM_COM_SEMAC
            FROM MUSICAS AS M
            LEFT JOIN ALBUM_MUSICAS AS AM ON M.ID = AM.ID_MUSICA
            LEFT JOIN ALBUM AS A ON AM.ID_ALBUM = A.ID
            WHERE 1=1
            AND (
                    (A.PERMITE_DESATIVAR = 'N')
                    OR
                    (A.PERMITE_DESATIVAR = 'S' AND A.ID NOT IN (SELECT ID FROM _ALBUM_IGNORAR))
                )");

        DB::connection('sqlite')->statement("CREATE VIEW LISTA_MUSICAS_ONL AS
            SELECT ONL_VIDEOS.VIDEO_ID AS ID,
                0 AS ID_ALBUM,
                ONL_PLAYLISTS.NOME AS NOME_ALBUM,
                ONL_PLAYLISTS.NOME || ' (Canal ' || ONL_CANAIS.NOME || ')' AS NOME_ALBUM_COM,
                ONL_VIDEOS.POSICAO AS FAIXA,
                ONL_VIDEOS.NOME AS NOME,
                ONL_VIDEOS.NOME || ' (Canal ' || ONL_CANAIS.NOME || ')' AS NOME_COM,
                'N' AS TIPO_HASD,
                'N' AS TIPO_JA,
                'N' AS TIPO_BAIXADA,
                'S' AS TIPO_WEB,
                'N' AS TIPO_PERSO,
                'W' AS TIPO,
                ONL_VIDEOS.VIDEO_ID AS URL_ALBUM,
                '' AS ALBUM,
                '' AS URL,
                '' AS URL_INSTRUMENTAL,
                '' AS IDIOMA,
                '' AS LETRA,

                " . self::fn_sqlite_no_accents("ONL_VIDEOS.NOME") . " AS NOME_SEMAC,
                '' AS LETRA_SEMAC,
                " . self::fn_sqlite_no_accents("ONL_PLAYLISTS.NOME || ' (Canal ' || ONL_CANAIS.NOME || ')'") . " AS NOME_ALBUM_COM_SEMAC
            FROM ONL_VIDEOS
            INNER JOIN ONL_PLAYLISTS ON ONL_VIDEOS.PLAYLIST_ID = ONL_PLAYLISTS.PLAYLIST_ID
            INNER JOIN ONL_CANAIS ON ONL_PLAYLISTS.CANAL_ID = ONL_CANAIS.CANAL_ID
            ORDER BY ONL_VIDEOS.NOME");

        DB::connection('sqlite')->statement("CREATE VIEW LISTA_MUSICAS_PERSO AS
            SELECT ID,
                0 AS ID_ALBUM,
                'Coletâneas Personalizadas' AS NOME_ALBUM,
                'Coletâneas Personalizadas' AS NOME_ALBUM_COM,
                0 AS FAIXA,
                NOME,
                NOME AS NOME_COM,
                'N' AS TIPO_HASD,
                'N' AS TIPO_JA,
                'N' AS TIPO_BAIXADA,
                'N' AS TIPO_WEB,
                'S' AS TIPO_PERSO,
                'P' AS TIPO,
                '' AS URL_ALBUM,
                '' AS ALBUM,
                URL,
                '' AS URL_INSTRUMENTAL,
                '' AS IDIOMA,
                '' AS LETRA,

                " . self::fn_sqlite_no_accents("NOME") . " AS NOME_SEMAC,
                '' AS LETRA_SEMAC,
                'coletaneas personalizadas' AS NOME_ALBUM_COM_SEMAC
            FROM _COLETANEAS_PERSONALIZADAS
            ORDER BY NOME");


        DB::connection('sqlite')->statement("CREATE VIEW LISTA_MUSICAS_TODAS AS
            SELECT * FROM LISTA_MUSICAS
            UNION
            SELECT * FROM LISTA_MUSICAS_ONL
            UNION
            SELECT * FROM LISTA_MUSICAS_PERSO
            ORDER BY NOME");

        DB::connection('sqlite')->statement("CREATE TABLE MUSICAS_SLIDE AS
            SELECT
                'CAPA' AS TIPO,
                ID AS MUSICA_ID,
                -1 AS LETRA_ID,
                ALBUM || '/' || URL AS URL_MUSICA,
                CASE WHEN URL_INSTRUMENTAL <> '' THEN ALBUM || '/' || URL_INSTRUMENTAL ELSE '' END AS URL_MUSICA_PB,
                NOME AS LETRA,
                UPPER(NOME) AS LETRA_UCASE,
                -1 AS ORDEM,
                MUSICAS.IMAGEM,
                IFNULL(IMAGEM_POSICAO.POSICAO, 0) AS IMAGEM_POSICAO,
                '00:00:00' AS TEMPO,
                '00:00:00' AS TEMPO_PB,
                FUNDO_LETRA,
                TAMANHO_LETRA,
                COR_LETRA,
                '' AS LETRA_AUX,
                0 AS TAMANHO_LETRA_AUX,
                '' AS COR_LETRA_AUX
            FROM
                MUSICAS
            LEFT JOIN
                IMAGEM_POSICAO ON IMAGEM_POSICAO.IMAGEM = MUSICAS.IMAGEM
            
            UNION
            
            SELECT
                'LETRA' AS TIPO,
                MUSICA AS MUSICA_ID,
                ID AS LETRA_ID,
                '' AS URL_MUSICA,
                '' AS URL_MUSICA_PB,
                LETRA,
                UPPER(LETRA) AS LETRA_UCASE,
                ORDEM,
                MUSICAS_LETRA.IMAGEM,
                IFNULL(IMAGEM_POSICAO.POSICAO, 0) AS IMAGEM_POSICAO,
                TEMPO,
                CASE WHEN IFNULL(MUSICAS_LETRA.TEMPO_PB, 0) > 0 THEN IFNULL(MUSICAS_LETRA.TEMPO_PB, 0) ELSE TEMPO END AS TEMPO_PB,
                FUNDO_LETRA,
                TAMANHO_LETRA,
                COR_LETRA,
                LETRA_AUX,
                TAMANHO_LETRA_AUX,
                COR_LETRA_AUX
            FROM
                MUSICAS_LETRA
            LEFT JOIN
                IMAGEM_POSICAO ON IMAGEM_POSICAO.IMAGEM = MUSICAS_LETRA.IMAGEM
            WHERE
                EXIBE_SLIDE = 1
            ORDER BY MUSICA_ID, ORDEM");

        DB::connection('sqlite')->statement("CREATE VIEW LISTA_COLETANEAS AS
            SELECT DISTINCT A.ID AS ID,
                T.ID_ALBUM AS ID_ALBUM,
                T.TIPO,
                T.SUBTITULO,
                A.NOME,
                A.NOME || (CASE WHEN T.SUBTITULO <> '' THEN ' (' || T.SUBTITULO || ')' ELSE '' END) AS NOME_ALBUM,
                A.IMAGEM
            FROM ALBUM AS A
            LEFT JOIN ALBUM_TIPO AS T ON T.ID_ALBUM = A.ID
            WHERE (A.PERMITE_DESATIVAR = 'N' OR (A.PERMITE_DESATIVAR = 'S' AND A.ID NOT IN (SELECT ID FROM _ALBUM_IGNORAR)))
            ORDER BY T.ORDEM, A.NOME");

        DB::connection('sqlite')->statement("CREATE VIEW DOXOLOGIA_ALBUNS AS
            SELECT A.ID, A.NOME, A.IMAGEM
            FROM ALBUM AS A
            LEFT JOIN ALBUM_TIPO AS AT ON A.ID = AT.ID_ALBUM
            WHERE AT.TIPO = 'DOX'
            ORDER BY AT.ORDEM, A.NOME");

        DB::connection('sqlite')->statement("CREATE VIEW MUSICAS_INFANTIS AS
            SELECT MUSICA.ID, MUSICA.NOME, MUSICA.ALBUM, MUSICA.URL, MUSICA.URL_INSTRUMENTAL
            FROM MUSICAS AS MUSICA
            JOIN ALBUM_MUSICAS AS ALBUM_MUSICAS ON MUSICA.ID = ALBUM_MUSICAS.ID_MUSICA
            JOIN ALBUM AS ALBUM ON ALBUM_MUSICAS.ID_ALBUM = ALBUM.ID
            JOIN ALBUM_TIPO AS ALBUM_TIPO ON ALBUM.ID = ALBUM_TIPO.ID_ALBUM
            WHERE ALBUM_TIPO.TIPO = 'INF'
            ORDER BY MUSICA.NOME");

        DB::connection('sqlite')->statement("CREATE VIEW BIBLIA AS
            SELECT
                bible_verse.id_bible_verse ID,
                (SELECT abbreviation FROM bible_version WHERE id_bible_version=bible_verse.id_bible_version) VERSAO,
                bible_verse.id_bible_book LIVRO,
                bible_verse.chapter CAPITULO,
                bible_verse.verse VERSICULO,
                bible_verse.text PASSAGEM
            FROM bible_verse
            WHERE bible_verse.id_language = 'pt'");

        DB::connection('sqlite')->statement("CREATE VIEW LIVRO AS
            SELECT 1 ID, 'Gn' SIGLA, 1 ID_SECAO, 'AT' TESTAMENTO, 'Gênesis' LIVRO,  'genesis' PALAVRACHAVE, 'Gn' SIGLA_L, '' SIGLA_N, 50 CAPITULOS, '$0d9a201' COR
            UNION SELECT 2 ID, 'Ex' SIGLA, 2 ID_SECAO, 'AT' TESTAMENTO, 'Êxodo' LIVRO,  'exodo' PALAVRACHAVE, 'Ex' SIGLA_L, '' SIGLA_N, 40 CAPITULOS, '$0d9a201' COR
            UNION SELECT 3 ID, 'Lv' SIGLA, 3 ID_SECAO, 'AT' TESTAMENTO, 'Levítico' LIVRO,  'levitico' PALAVRACHAVE, 'Lv' SIGLA_L, '' SIGLA_N, 27 CAPITULOS, '$0d9a201' COR
            UNION SELECT 4 ID, 'Nm' SIGLA, 4 ID_SECAO, 'AT' TESTAMENTO, 'Números' LIVRO,  'numeros' PALAVRACHAVE, 'Nm' SIGLA_L, '' SIGLA_N, 36 CAPITULOS, '$0d9a201' COR
            UNION SELECT 5 ID, 'Dt' SIGLA, 5 ID_SECAO, 'AT' TESTAMENTO, 'Deuteronômio' LIVRO,  'deuteronomio' PALAVRACHAVE, 'Dt' SIGLA_L, '' SIGLA_N, 34 CAPITULOS, '$0d9a201' COR
            UNION SELECT 6 ID, 'Js' SIGLA, 6 ID_SECAO, 'AT' TESTAMENTO, 'Josué' LIVRO,  'josue' PALAVRACHAVE, 'Js' SIGLA_L, '' SIGLA_N, 24 CAPITULOS, '$000b085' COR
            UNION SELECT 7 ID, 'Jz' SIGLA, 7 ID_SECAO, 'AT' TESTAMENTO, 'Juízes' LIVRO,  'juizes' PALAVRACHAVE, 'Jz' SIGLA_L, '' SIGLA_N, 21 CAPITULOS, '$000b085' COR
            UNION SELECT 8 ID, 'Rt' SIGLA, 8 ID_SECAO, 'AT' TESTAMENTO, 'Rute' LIVRO,  'rute' PALAVRACHAVE, 'Rt' SIGLA_L, '' SIGLA_N, 4 CAPITULOS, '$000b085' COR
            UNION SELECT 9 ID, '1Sm' SIGLA, 9 ID_SECAO, 'AT' TESTAMENTO, 'I Samuel' LIVRO,  'i samuel' PALAVRACHAVE, 'Sm' SIGLA_L, '1' SIGLA_N, 31 CAPITULOS, '$000b085' COR
            UNION SELECT 10 ID, '2Sm' SIGLA, 10 ID_SECAO, 'AT' TESTAMENTO, 'II Samuel' LIVRO,  'ii samuel' PALAVRACHAVE, 'Sm' SIGLA_L, '2' SIGLA_N, 24 CAPITULOS, '$000b085' COR
            UNION SELECT 11 ID, '1Rs' SIGLA, 11 ID_SECAO, 'AT' TESTAMENTO, 'I Reis' LIVRO,  'i reis' PALAVRACHAVE, 'Rs' SIGLA_L, '1' SIGLA_N, 22 CAPITULOS, '$000b085' COR
            UNION SELECT 12 ID, '2Rs' SIGLA, 12 ID_SECAO, 'AT' TESTAMENTO, 'II Reis' LIVRO,  'ii reis' PALAVRACHAVE, 'Rs' SIGLA_L, '2' SIGLA_N, 25 CAPITULOS, '$000b085' COR
            UNION SELECT 13 ID, '1Cr' SIGLA, 13 ID_SECAO, 'AT' TESTAMENTO, 'I Crônicas' LIVRO,  'i cronicas' PALAVRACHAVE, 'Cr' SIGLA_L, '1' SIGLA_N, 29 CAPITULOS, '$000b085' COR
            UNION SELECT 14 ID, '2Cr' SIGLA, 14 ID_SECAO, 'AT' TESTAMENTO, 'II Crônicas' LIVRO,  'ii cronicas' PALAVRACHAVE, 'Cr' SIGLA_L, '2' SIGLA_N, 36 CAPITULOS, '$000b085' COR
            UNION SELECT 15 ID, 'Ed' SIGLA, 15 ID_SECAO, 'AT' TESTAMENTO, 'Esdras' LIVRO,  'esdras' PALAVRACHAVE, 'Ed' SIGLA_L, '' SIGLA_N, 10 CAPITULOS, '$000b085' COR
            UNION SELECT 16 ID, 'Ne' SIGLA, 16 ID_SECAO, 'AT' TESTAMENTO, 'Neemias' LIVRO,  'neemias' PALAVRACHAVE, 'Ne' SIGLA_L, '' SIGLA_N, 13 CAPITULOS, '$000b085' COR
            UNION SELECT 17 ID, 'Es' SIGLA, 17 ID_SECAO, 'AT' TESTAMENTO, 'Ester' LIVRO,  'ester' PALAVRACHAVE, 'Es' SIGLA_L, '' SIGLA_N, 10 CAPITULOS, '$000b085' COR
            UNION SELECT 18 ID, 'Jó' SIGLA, 18 ID_SECAO, 'AT' TESTAMENTO, 'Jó' LIVRO,  'jo' PALAVRACHAVE, 'Jó' SIGLA_L, '' SIGLA_N, 42 CAPITULOS, '$0ff8de1' COR
            UNION SELECT 19 ID, 'Sl' SIGLA, 19 ID_SECAO, 'AT' TESTAMENTO, 'Salmos' LIVRO,  'salmos' PALAVRACHAVE, 'Sl' SIGLA_L, '' SIGLA_N, 150 CAPITULOS, '$0ff8de1' COR
            UNION SELECT 20 ID, 'Pv' SIGLA, 20 ID_SECAO, 'AT' TESTAMENTO, 'Provérbios' LIVRO,  'proverbios' PALAVRACHAVE, 'Pv' SIGLA_L, '' SIGLA_N, 31 CAPITULOS, '$0ff8de1' COR
            UNION SELECT 21 ID, 'Ec' SIGLA, 21 ID_SECAO, 'AT' TESTAMENTO, 'Eclesiastes' LIVRO,  'eclesiastes' PALAVRACHAVE, 'Ec' SIGLA_L, '' SIGLA_N, 12 CAPITULOS, '$0ff8de1' COR
            UNION SELECT 22 ID, 'Cn' SIGLA, 22 ID_SECAO, 'AT' TESTAMENTO, 'Cantares de Salomão' LIVRO,  'cantares salomao canticos' PALAVRACHAVE, 'Cn' SIGLA_L, '' SIGLA_N, 8 CAPITULOS, '$0ff8de1' COR
            UNION SELECT 23 ID, 'Is' SIGLA, 23 ID_SECAO, 'AT' TESTAMENTO, 'Isaías' LIVRO,  'isaias' PALAVRACHAVE, 'Is' SIGLA_L, '' SIGLA_N, 66 CAPITULOS, '$03f40ff' COR
            UNION SELECT 24 ID, 'Jr' SIGLA, 24 ID_SECAO, 'AT' TESTAMENTO, 'Jeremias' LIVRO,  'jeremias' PALAVRACHAVE, 'Jr' SIGLA_L, '' SIGLA_N, 52 CAPITULOS, '$03f40ff' COR
            UNION SELECT 25 ID, 'Lm' SIGLA, 25 ID_SECAO, 'AT' TESTAMENTO, 'Lamentações' LIVRO,  'lamentacoes' PALAVRACHAVE, 'Lm' SIGLA_L, '' SIGLA_N, 5 CAPITULOS, '$03f40ff' COR
            UNION SELECT 26 ID, 'Ez' SIGLA, 26 ID_SECAO, 'AT' TESTAMENTO, 'Ezequiel' LIVRO,  'ezequiel' PALAVRACHAVE, 'Ez' SIGLA_L, '' SIGLA_N, 48 CAPITULOS, '$03f40ff' COR
            UNION SELECT 27 ID, 'Dn' SIGLA, 27 ID_SECAO, 'AT' TESTAMENTO, 'Daniel' LIVRO,  'daniel' PALAVRACHAVE, 'Dn' SIGLA_L, '' SIGLA_N, 12 CAPITULOS, '$03f40ff' COR
            UNION SELECT 28 ID, 'Os' SIGLA, 28 ID_SECAO, 'AT' TESTAMENTO, 'Oséias' LIVRO,  'oseias' PALAVRACHAVE, 'Os' SIGLA_L, '' SIGLA_N, 14 CAPITULOS, '$00085b1' COR
            UNION SELECT 29 ID, 'Jl' SIGLA, 29 ID_SECAO, 'AT' TESTAMENTO, 'Joel' LIVRO,  'joel' PALAVRACHAVE, 'Jl' SIGLA_L, '' SIGLA_N, 3 CAPITULOS, '$00085b1' COR
            UNION SELECT 30 ID, 'Am' SIGLA, 30 ID_SECAO, 'AT' TESTAMENTO, 'Amós' LIVRO,  'amos' PALAVRACHAVE, 'Am' SIGLA_L, '' SIGLA_N, 9 CAPITULOS, '$00085b1' COR
            UNION SELECT 31 ID, 'Ob' SIGLA, 31 ID_SECAO, 'AT' TESTAMENTO, 'Obadias' LIVRO,  'obadias' PALAVRACHAVE, 'Ob' SIGLA_L, '' SIGLA_N, 1 CAPITULOS, '$00085b1' COR
            UNION SELECT 32 ID, 'Jn' SIGLA, 32 ID_SECAO, 'AT' TESTAMENTO, 'Jonas' LIVRO,  'jonas' PALAVRACHAVE, 'Jn' SIGLA_L, '' SIGLA_N, 4 CAPITULOS, '$00085b1' COR
            UNION SELECT 33 ID, 'Mq' SIGLA, 33 ID_SECAO, 'AT' TESTAMENTO, 'Miquéias' LIVRO,  'miqueias' PALAVRACHAVE, 'Mq' SIGLA_L, '' SIGLA_N, 7 CAPITULOS, '$00085b1' COR
            UNION SELECT 34 ID, 'Na' SIGLA, 34 ID_SECAO, 'AT' TESTAMENTO, 'Naum' LIVRO,  'naum' PALAVRACHAVE, 'Na' SIGLA_L, '' SIGLA_N, 3 CAPITULOS, '$00085b1' COR
            UNION SELECT 35 ID, 'Hc' SIGLA, 35 ID_SECAO, 'AT' TESTAMENTO, 'Habacuque' LIVRO,  'habacuque' PALAVRACHAVE, 'Hc' SIGLA_L, '' SIGLA_N, 3 CAPITULOS, '$00085b1' COR
            UNION SELECT 36 ID, 'Sf' SIGLA, 36 ID_SECAO, 'AT' TESTAMENTO, 'Sofonias' LIVRO,  'sofonias' PALAVRACHAVE, 'Sf' SIGLA_L, '' SIGLA_N, 3 CAPITULOS, '$00085b1' COR
            UNION SELECT 37 ID, 'Ag' SIGLA, 37 ID_SECAO, 'AT' TESTAMENTO, 'Ageu' LIVRO,  'ageu' PALAVRACHAVE, 'Ag' SIGLA_L, '' SIGLA_N, 2 CAPITULOS, '$00085b1' COR
            UNION SELECT 38 ID, 'Zc' SIGLA, 38 ID_SECAO, 'AT' TESTAMENTO, 'Zacarias' LIVRO,  'zacarias' PALAVRACHAVE, 'Zc' SIGLA_L, '' SIGLA_N, 14 CAPITULOS, '$00085b1' COR
            UNION SELECT 39 ID, 'Ml' SIGLA, 39 ID_SECAO, 'AT' TESTAMENTO, 'Malaquias' LIVRO,  'malaquias' PALAVRACHAVE, 'Ml' SIGLA_L, '' SIGLA_N, 4 CAPITULOS, '$00085b1' COR
            UNION SELECT 40 ID, 'Mt' SIGLA, 1 ID_SECAO, 'NT' TESTAMENTO, 'Mateus' LIVRO,  'mateus' PALAVRACHAVE, 'Mt' SIGLA_L, '' SIGLA_N, 28 CAPITULOS, '$08d8c00' COR
            UNION SELECT 41 ID, 'Mc' SIGLA, 2 ID_SECAO, 'NT' TESTAMENTO, 'Marcos' LIVRO,  'marcos' PALAVRACHAVE, 'Mc' SIGLA_L, '' SIGLA_N, 16 CAPITULOS, '$08d8c00' COR
            UNION SELECT 42 ID, 'Lc' SIGLA, 3 ID_SECAO, 'NT' TESTAMENTO, 'Lucas' LIVRO,  'lucas' PALAVRACHAVE, 'Lc' SIGLA_L, '' SIGLA_N, 24 CAPITULOS, '$08d8c00' COR
            UNION SELECT 43 ID, 'Jo' SIGLA, 4 ID_SECAO, 'NT' TESTAMENTO, 'João' LIVRO,  'joao' PALAVRACHAVE, 'Jo' SIGLA_L, '' SIGLA_N, 21 CAPITULOS, '$08d8c00' COR
            UNION SELECT 44 ID, 'At' SIGLA, 5 ID_SECAO, 'NT' TESTAMENTO, 'Atos' LIVRO,  'atos' PALAVRACHAVE, 'At' SIGLA_L, '' SIGLA_N, 28 CAPITULOS, '$0ff65b2' COR
            UNION SELECT 45 ID, 'Rm' SIGLA, 6 ID_SECAO, 'NT' TESTAMENTO, 'Romanos' LIVRO,  'romanos' PALAVRACHAVE, 'Rm' SIGLA_L, '' SIGLA_N, 16 CAPITULOS, '$06667ff' COR
            UNION SELECT 46 ID, '1Co' SIGLA, 7 ID_SECAO, 'NT' TESTAMENTO, 'I Coríntios' LIVRO,  'i corintios' PALAVRACHAVE, 'Co' SIGLA_L, '1' SIGLA_N, 16 CAPITULOS, '$06667ff' COR
            UNION SELECT 47 ID, '2Co' SIGLA, 8 ID_SECAO, 'NT' TESTAMENTO, 'II Coríntios' LIVRO,  'ii corintios' PALAVRACHAVE, 'Co' SIGLA_L, '2' SIGLA_N, 13 CAPITULOS, '$06667ff' COR
            UNION SELECT 48 ID, 'Gl' SIGLA, 9 ID_SECAO, 'NT' TESTAMENTO, 'Gálatas' LIVRO,  'galatas' PALAVRACHAVE, 'Gl' SIGLA_L, '' SIGLA_N, 6 CAPITULOS, '$06667ff' COR
            UNION SELECT 49 ID, 'Ef' SIGLA, 10 ID_SECAO, 'NT' TESTAMENTO, 'Efésios' LIVRO,  'efesios' PALAVRACHAVE, 'Ef' SIGLA_L, '' SIGLA_N, 6 CAPITULOS, '$06667ff' COR
            UNION SELECT 50 ID, 'Fp' SIGLA, 11 ID_SECAO, 'NT' TESTAMENTO, 'Filipenses' LIVRO,  'filipenses' PALAVRACHAVE, 'Fp' SIGLA_L, '' SIGLA_N, 4 CAPITULOS, '$06667ff' COR
            UNION SELECT 51 ID, 'Cl' SIGLA, 12 ID_SECAO, 'NT' TESTAMENTO, 'Colossenses' LIVRO,  'colossenses' PALAVRACHAVE, 'Cl' SIGLA_L, '' SIGLA_N, 4 CAPITULOS, '$06667ff' COR
            UNION SELECT 52 ID, '1Ts' SIGLA, 13 ID_SECAO, 'NT' TESTAMENTO, 'I Tessalonicenses' LIVRO,  'i tessalonicensses' PALAVRACHAVE, 'Ts' SIGLA_L, '1' SIGLA_N, 5 CAPITULOS, '$06667ff' COR
            UNION SELECT 53 ID, '2Ts' SIGLA, 14 ID_SECAO, 'NT' TESTAMENTO, 'II Tessalonicenses' LIVRO,  'ii tessalonicenses' PALAVRACHAVE, 'Ts' SIGLA_L, '2' SIGLA_N, 3 CAPITULOS, '$06667ff' COR
            UNION SELECT 54 ID, '1Tm' SIGLA, 15 ID_SECAO, 'NT' TESTAMENTO, 'I Timóteo' LIVRO,  'i timoteo' PALAVRACHAVE, 'Tm' SIGLA_L, '1' SIGLA_N, 6 CAPITULOS, '$06667ff' COR
            UNION SELECT 55 ID, '2Tm' SIGLA, 16 ID_SECAO, 'NT' TESTAMENTO, 'II Timóteo' LIVRO,  'ii timoteo' PALAVRACHAVE, 'Tm' SIGLA_L, '2' SIGLA_N, 4 CAPITULOS, '$06667ff' COR
            UNION SELECT 56 ID, 'Tt' SIGLA, 17 ID_SECAO, 'NT' TESTAMENTO, 'Tito' LIVRO,  'tito' PALAVRACHAVE, 'Tt' SIGLA_L, '' SIGLA_N, 3 CAPITULOS, '$06667ff' COR
            UNION SELECT 57 ID, 'Fm' SIGLA, 18 ID_SECAO, 'NT' TESTAMENTO, 'Filemom' LIVRO,  'filemom' PALAVRACHAVE, 'Fm' SIGLA_L, '' SIGLA_N, 1 CAPITULOS, '$06667ff' COR
            UNION SELECT 58 ID, 'Hb' SIGLA, 19 ID_SECAO, 'NT' TESTAMENTO, 'Hebreus' LIVRO,  'hebreus' PALAVRACHAVE, 'Hb' SIGLA_L, '' SIGLA_N, 13 CAPITULOS, '$0ff9774' COR
            UNION SELECT 59 ID, 'Tg' SIGLA, 20 ID_SECAO, 'NT' TESTAMENTO, 'Tiago' LIVRO,  'tiago' PALAVRACHAVE, 'Tg' SIGLA_L, '' SIGLA_N, 5 CAPITULOS, '$0ff9774' COR
            UNION SELECT 60 ID, '1Pe' SIGLA, 21 ID_SECAO, 'NT' TESTAMENTO, 'I Pedro' LIVRO,  'i pedro' PALAVRACHAVE, 'Pe' SIGLA_L, '1' SIGLA_N, 5 CAPITULOS, '$0ff9774' COR
            UNION SELECT 61 ID, '2Pe' SIGLA, 22 ID_SECAO, 'NT' TESTAMENTO, 'II Pedro' LIVRO,  'ii pedro' PALAVRACHAVE, 'Pe' SIGLA_L, '2' SIGLA_N, 3 CAPITULOS, '$0ff9774' COR
            UNION SELECT 62 ID, '1Jo' SIGLA, 23 ID_SECAO, 'NT' TESTAMENTO, 'I João' LIVRO,  'i joao' PALAVRACHAVE, 'Jo' SIGLA_L, '1' SIGLA_N, 5 CAPITULOS, '$0ff9774' COR
            UNION SELECT 63 ID, '2Jo' SIGLA, 24 ID_SECAO, 'NT' TESTAMENTO, 'II João' LIVRO,  'ii joao' PALAVRACHAVE, 'Jo' SIGLA_L, '2' SIGLA_N, 1 CAPITULOS, '$0ff9774' COR
            UNION SELECT 64 ID, '3Jo' SIGLA, 25 ID_SECAO, 'NT' TESTAMENTO, 'III João' LIVRO,  'iii joao' PALAVRACHAVE, 'Jo' SIGLA_L, '3' SIGLA_N, 1 CAPITULOS, '$0ff9774' COR
            UNION SELECT 65 ID, 'Jd' SIGLA, 26 ID_SECAO, 'NT' TESTAMENTO, 'Judas' LIVRO,  'judas' PALAVRACHAVE, 'Jd' SIGLA_L, '' SIGLA_N, 1 CAPITULOS, '$0ff9774' COR
            UNION SELECT 66 ID, 'Ap' SIGLA, 27 ID_SECAO, 'NT' TESTAMENTO, 'Apocalipse' LIVRO,  'apocalipse' PALAVRACHAVE, 'Ap' SIGLA_L, '' SIGLA_N, 22 CAPITULOS, '$040d1ff' COR");

        DB::connection('sqlite')->statement("CREATE VIEW VERSAO_BIBLICA AS
            SELECT
                    abbreviation SIGLA,
                    name VERSAO,
                    IIF(abbreviation = 'NTLH', 0, 1) AS QUEBRA,
                    IIF(abbreviation = 'NTLH', '<pb/>', '') AS SIMBOLO_QUEBRA,
                    '' EXPLICACAO_VERSOS
            FROM bible_version WHERE id_language='pt'");

        /* Renomeia para identificar a versão */
        $version = Configs::get("version");
        $path_parts = pathinfo($database);
        $newname = $path_parts['dirname'] . '/' . $version . '.' . $path_parts['extension'];
        if (copy($database, $newname)) {
            $url_database = app()->basePath('public/db/' . $newname);
        }

        return ["url" => $url_database, "logs" => $log];
    }

    public static function import_file($file_path)
    {
        if (!File::exists($file_path)) {
            return ['error' => 'Arquivo não encontrado.'];
        }

        $info = pathinfo($file_path);
        $mime = mime_content_type($file_path);

        if ($mime == "application/zip") {

            $output = dirname($file_path);
            $output = rtrim($output, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $output = $output . '~' . $info['filename'];

            Files::unzip($file_path, $output);

            $text_file = $output . DIRECTORY_SEPARATOR . 'slides.lja';
            $content = File::get($text_file);

            $sections = preg_split('/\[(.*?)\]/', $content, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);

            $slides = [];
            for ($i = 0; $i < count($sections); $i += 2) {
                $sectionName = trim($sections[$i]);

                $lines = explode("\n", trim($sections[$i + 1]));
                $data = [];
                foreach ($lines as $line) {
                    list($key, $value) = explode('=', $line, 2);
                    $data[trim($key)] = trim($value);
                }

                $slides[$sectionName] = $data;
            }

            $music = [];
            $lyrics = [];

            $id_music = null;
            $id_image = null;
            $order = 0;
            foreach ($slides as $key => $slide) {
                $text = trim($slide["letra"] ?? "");
                $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
                $text = str_replace("|", PHP_EOL, $text);
                $text = ucfirst($text);

                $slide["url_musica"] = mb_convert_encoding($slide["url_musica"] ?? "", 'UTF-8', 'ISO-8859-1');
                $slide["imagem"] = mb_convert_encoding($slide["imagem"] ?? "", 'UTF-8', 'ISO-8859-1');

                if ($key == "Geral") {
                    if ($slide["url_musica"] <> "") {
                        $file = FileModel::where("name", basename($slide["url_musica"]))->first();
                        if (!$file) {
                            $file = FileModel::create([
                                "name" => basename($slide["url_musica"]),
                                "file_name" => basename($slide["url_musica"]),
                                "type" => "music",
                                "size" => 0,
                                "dir" => "/",
                                "version" => 1,
                            ]);
                        }
                        $id_music = $file["id_file"];
                    }
                } else {
                    if ($slide["imagem"] <> "") {
                        $file = FileModel::where("name", basename($slide["imagem"]))->first();
                        if (!$file) {
                            $file = FileModel::create([
                                "name" => basename($slide["imagem"]),
                                "file_name" => basename($slide["imagem"]),
                                "type" => "image_music",
                                "size" => 0,
                                "dir" => "/",
                                "version" => 1,
                            ]);
                        }
                        $id_image = $file["id_file"];
                    }

                    if ($slide["tipo"] == "CAPA") {
                        $text = str_replace(PHP_EOL, " ", $text);

                        $music["name"] = $text;
                        $music["id_file_music"] = $id_music;
                        $music["id_file_image"] = $id_image;
                        $music["id_language"] = "pt";
                    } else {
                        $order = $order + 10;
                        $lyrics[] = [
                            "lyric" => $text,
                            "id_file_image" => $id_image,
                            "time" => "00:" . $slide["tempo_hms"],
                            "instrumental_time" => '00:00:00',
                            "show_slide" => 1,
                            "order" => $order,
                            "id_language" => "pt",
                        ];
                    }
                }
            }

            File::deleteDirectory($output);
            $new_path = dirname($file_path);
            $new_path = rtrim($new_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $new_path = $new_path . 'imported' . DIRECTORY_SEPARATOR;

            if (!File::isDirectory($new_path)) {
                File::makeDirectory($new_path, 0755, true);
            }
            File::move($file_path, $new_path . basename($file_path));

            $music = Music::create($music);
            foreach ($lyrics as $lyric) {
                $lyric["id_music"] = $music->id_music;
                Lyric::create($lyric);
            }

            return ['music' => $music];
        } else {
            return ['error' => 'Formato não suportado.'];
        }
    }
}
