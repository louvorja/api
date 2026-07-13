<?php

namespace App\Helpers;

use Illuminate\Support\Facades\File;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Language;
use App\Models\Category;
use App\Models\Album;
use App\Models\Music;
use App\Models\OnlineVideo;
use App\Models\OnlineVideoPlaylist;
use App\Models\OnlineVideoChannel;

/**
 * Gera JSONs estáticos a partir do banco de dados para uso offline pelo app desktop/web.
 * Os arquivos são salvos em public/db/json/ seguindo o padrão do DatabaseJsonController.
 *
 * Cada JSON gerado inclui um campo _meta com hash MD5 para versionamento/ETag.
 */
class GenerateStaticJsons
{
    /**
     * Diretório base para os JSONs estáticos.
     */
    private static function jsonDir(): string
    {
        $path = app()->basePath('public/db/json') . '/';
        if (!File::exists(rtrim($path, '/'))) {
            File::makeDirectory(rtrim($path, '/'), 0755, true);
        }
        return $path;
    }

    /**
     * Gera todos os JSONs estáticos.
     * Retorna array de logs com status de cada arquivo gerado.
     */
    public static function generate(): array
    {
        $logs = [];
        $path = self::jsonDir();
        $filesUrl = config('files.url');

        // 1. Categorias com álbuns (por idioma) - para navegacao principal do app
        $langs = Language::orderBy('id_language', 'desc')->get();
        foreach ($langs as $lang) {
            $l = $lang->id_language;

            // 1a. Categorias (collection) com albums
            $categories = Category::select([
                'id_category',
                'name',
                'slug',
                'order',
            ])
                ->where('type', 'collection')
                ->where('id_language', $l)
                ->orderBy('order')
                ->with(['albums' => function ($query) use ($filesUrl) {
                    $query->select([
                        'albums.id_album',
                        'albums.name',
                        'albums.color',
                        DB::raw("concat('{$filesUrl}',files_image.dir,'/',files_image.file_name) as url_image"),
                        DB::raw("categories_albums.name as subtitle"),
                        'categories_albums.order',
                    ])
                        ->leftJoin('files as files_image', 'albums.id_file_image', 'files_image.id_file')
                        ->orderBy('categories_albums.order');
                }])
                ->get();
            $categories->each(function ($item) {
                $item->albums->makeHidden('pivot');
            });
            $logs[] = self::saveStaticJson($path, "{$l}_categories.json", $categories->toArray());

            // 1b. Albums de cada categoria (individual)
            $categoryList = Category::where('id_language', $l)->where('type', 'collection')->orderBy('order')->get();
            foreach ($categoryList as $category) {
                $albums = (new Album)->select([
                    'albums.id_album',
                    'albums.name',
                    'albums.id_file_image',
                    DB::raw("concat('{$filesUrl}',files.dir,'/',files.file_name) as url_image"),
                    DB::raw('files.version as image_version'),
                    'albums.id_language',
                    'albums.color',
                    'categories_albums.order',
                    'albums.created_at',
                    'albums.updated_at',
                ])
                    ->join('categories_albums', 'categories_albums.id_album', 'albums.id_album')
                    ->join('categories', 'categories.id_category', 'categories_albums.id_category')
                    ->leftJoin('files', 'albums.id_file_image', 'files.id_file')
                    ->where('categories.id_category', $category->id_category)
                    ->where('albums.id_language', $l)
                    ->orderBy('categories_albums.order')
                    ->get()
                    ->toArray();
                $logs[] = self::saveStaticJson($path, "{$l}_category_{$category->id_category}_albums.json", $albums);

                // 1c. Albums com musicas aninhadas (para detalhe offline)
                $albumsWithMusics = self::getCategoryAlbumsWithMusics($category->id_category, $l, $filesUrl);
                $logs[] = self::saveStaticJson($path, "{$l}_category_{$category->id_category}_albums_with_musics.json", $albumsWithMusics);
            }

            // 2. Hinario (musicas da categoria "hymnal")
            $hymnal = self::getHymnalMusics($l, $filesUrl);
            $logs[] = self::saveStaticJson($path, "{$l}_hymnal.json", $hymnal);

            // 3. Collections online (canais, playlists, videos)
            $collections = self::getOnlineCollections($l);
            $logs[] = self::saveStaticJson($path, "{$l}_collections_online.json", $collections);

            // 4. Albums listagem geral
            $allAlbums = (new Album)->select([
                'albums.id_album',
                'albums.name',
                'albums.id_file_image',
                DB::raw("concat('{$filesUrl}',files.dir,'/',files.file_name) as url_image"),
                DB::raw('files.version as image_version'),
                'albums.id_language',
                'albums.color',
                'albums.created_at',
                'albums.updated_at',
            ])
                ->leftJoin('files', 'albums.id_file_image', 'files.id_file')
                ->where('albums.id_language', $l)
                ->orderBy('albums.name')
                ->get()
                ->toArray();
            $logs[] = self::saveStaticJson($path, "{$l}_albums.json", $allAlbums);

            // 5. Musicas listagem geral
            $allMusics = (new Music)->select([
                'musics.id_music',
                'musics.name',
                'musics.id_file_image',
                DB::raw("concat('{$filesUrl}',files_image.dir,'/',files_image.file_name) as url_image"),
                'files_image.version as image_version',
                'musics.id_file_music',
                DB::raw("concat('{$filesUrl}',files_music.dir,'/',files_music.file_name) as url_music"),
                'files_music.version as music_version',
                'musics.id_file_instrumental_music',
                DB::raw("concat('{$filesUrl}',files_instrumental_music.dir,'/',files_instrumental_music.file_name) as url_instrumental_music"),
                'files_instrumental_music.version as instrumental_music_version',
                'musics.id_language',
                'musics.created_at',
                'musics.updated_at',
            ])
                ->leftJoin('files as files_image', 'musics.id_file_image', 'files_image.id_file')
                ->leftJoin('files as files_music', 'musics.id_file_music', 'files_music.id_file')
                ->leftJoin('files as files_instrumental_music', 'musics.id_file_instrumental_music', 'files_instrumental_music.id_file')
                ->where('musics.id_language', $l)
                ->orderBy('musics.name')
                ->get()
                ->toArray();
            $logs[] = self::saveStaticJson($path, "{$l}_musics.json", $allMusics);
        }

        // Limpa cache do manifest apos gerar novos JSONs
        Cache::forget('db.manifest');
        Cache::forget('db.manifest.hashes');

        return $logs;
    }

    /**
     * Retorna dados de albums com musicas aninhadas para uma categoria.
     */
    private static function getCategoryAlbumsWithMusics(int $categoryId, string $lang, string $filesUrl): array
    {
        $category = Category::where('id_category', $categoryId)
            ->where('id_language', $lang)
            ->first();

        if (!$category) {
            return ['category' => null, 'albums' => []];
        }

        $albums = (new Album)->select([
            'albums.id_album',
            'albums.name',
            'albums.id_file_image',
            DB::raw("concat('{$filesUrl}',files.dir,'/',files.file_name) as url_image"),
            DB::raw('files.version as image_version'),
            'albums.id_language',
            'albums.color',
            'categories_albums.order',
        ])
            ->join('categories_albums', 'categories_albums.id_album', 'albums.id_album')
            ->join('categories', 'categories.id_category', 'categories_albums.id_category')
            ->leftJoin('files', 'albums.id_file_image', 'files.id_file')
            ->where('categories.id_category', $categoryId)
            ->where('albums.id_language', $lang)
            ->orderBy('categories_albums.order')
            ->get();

        $albumIds = $albums->pluck('id_album')->toArray();
        $musicsByAlbum = collect();
        if (!empty($albumIds)) {
            $musicsByAlbum = Music::select([
                'albums_musics.id_album',
                'musics.id_music',
                'albums_musics.track',
                'musics.name',
                'musics.id_file_image',
                DB::raw("concat('{$filesUrl}',files_image.dir,'/',files_image.file_name) as url_image"),
                'files_image.version as image_version',
                'musics.id_file_music',
                DB::raw("concat('{$filesUrl}',files_music.dir,'/',files_music.file_name) as url_music"),
                'files_music.version as music_version',
                'musics.id_file_instrumental_music',
                DB::raw("concat('{$filesUrl}',files_instrumental_music.dir,'/',files_instrumental_music.file_name) as url_instrumental_music"),
                'files_instrumental_music.version as instrumental_music_version',
            ])
                ->leftJoin('albums_musics', 'albums_musics.id_music', 'musics.id_music')
                ->leftJoin('files as files_image', 'musics.id_file_image', 'files_image.id_file')
                ->leftJoin('files as files_music', 'musics.id_file_music', 'files_music.id_file')
                ->leftJoin('files as files_instrumental_music', 'musics.id_file_instrumental_music', 'files_instrumental_music.id_file')
                ->whereIn('albums_musics.id_album', $albumIds)
                ->orderBy('albums_musics.track')
                ->get()
                ->groupBy('id_album');
        }

        $albumsWithMusics = [];
        foreach ($albums as $album) {
            $albumArray = $album->toArray();
            $albumArray['musics'] = $musicsByAlbum->get($album->id_album, collect())->values()->toArray();
            $albumsWithMusics[] = $albumArray;
        }

        return [
            'category' => $category->toArray(),
            'albums' => $albumsWithMusics,
        ];
    }

    /**
     * Retorna todas as musicas do hinario (categoria slug=hymnal) para um idioma.
     */
    private static function getHymnalMusics(string $lang, string $filesUrl): array
    {
        return (new Music)->select([
            'musics.id_music',
            'musics.name',
            'albums_musics.track',
            'musics.id_file_image',
            DB::raw("concat('{$filesUrl}',files_image.dir,'/',files_image.file_name) as url_image"),
            'files_image.version as image_version',
            'musics.id_file_music',
            DB::raw("concat('{$filesUrl}',files_music.dir,'/',files_music.file_name) as url_music"),
            'files_music.version as music_version',
            'musics.id_file_instrumental_music',
            DB::raw("concat('{$filesUrl}',files_instrumental_music.dir,'/',files_instrumental_music.file_name) as url_instrumental_music"),
            'files_instrumental_music.version as instrumental_music_version',
            'musics.id_language',
            'musics.created_at',
            'musics.updated_at',
        ])
            ->join('albums_musics', 'albums_musics.id_music', 'musics.id_music')
            ->join('categories_albums', 'categories_albums.id_album', 'albums_musics.id_album')
            ->join('categories', 'categories.id_category', 'categories_albums.id_category')
            ->leftJoin('files as files_image', 'musics.id_file_image', 'files_image.id_file')
            ->leftJoin('files as files_music', 'musics.id_file_music', 'files_music.id_file')
            ->leftJoin('files as files_instrumental_music', 'musics.id_file_instrumental_music', 'files_instrumental_music.id_file')
            ->where('categories.slug', 'hymnal')
            ->where('categories.id_language', $lang)
            ->where('musics.id_language', $lang)
            ->orderBy('albums_musics.track')
            ->get()
            ->toArray();
    }

    /**
     * Retorna collections online (canais, playlists, videos) para um idioma.
     */
    private static function getOnlineCollections(string $lang): array
    {
        $channels = OnlineVideoChannel::where('id_language', $lang)
            ->where('status', 'validated')
            ->get()
            ->map(function ($channel) {
                return [
                    'channel_id' => $channel->channel_id,
                    'title' => $channel->title,
                    'custom_url' => $channel->custom_url,
                    'default_image' => $channel->default_image,
                    'default_image_base64' => $channel->default_image_base64,
                ];
            })->toArray();

        $playlists = OnlineVideoPlaylist::where('id_language', $lang)
            ->where('status', 'validated')
            ->with('channel')
            ->get()
            ->map(function ($playlist) {
                return [
                    'playlist_id' => $playlist->playlist_id,
                    'channel_id' => $playlist->channel ? $playlist->channel->channel_id : null,
                    'title' => $playlist->title,
                    'default_image' => $playlist->default_image,
                    'default_image_base64' => $playlist->default_image_base64,
                ];
            })->toArray();

        $videos = OnlineVideo::where('id_language', $lang)
            ->where('status', 'validated')
            ->with('playlist')
            ->get()
            ->map(function ($video) {
                return [
                    'video_id' => $video->video_id,
                    'playlist_id' => $video->playlist ? $video->playlist->playlist_id : null,
                    'title' => $video->title,
                    'sequence' => $video->sequence,
                    'default_image' => $video->default_image,
                    'default_image_base64' => $video->default_image_base64,
                ];
            })->toArray();

        return [
            'channels' => $channels,
            'playlists' => $playlists,
            'videos' => $videos,
        ];
    }

    /**
     * Salva JSON estatico no disco e retorna log.
     * Inclui _meta com hash MD5 para versionamento via ETag.
     */
    private static function saveStaticJson(string $dir, string $filename, array $data): array
    {
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = md5($content);

        $payload = [
            '_meta' => [
                'hash' => $hash,
                'generated_at' => Carbon::now()->toIso8601String(),
            ],
            'data' => $data,
        ];

        $jsonContent = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            file_put_contents($dir . $filename, $jsonContent);
            return [
                'status' => 'success',
                'file' => $filename,
                'hash' => $hash,
                'size' => strlen($jsonContent),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'file' => $filename,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Retorna o hash MD5 de um arquivo JSON estatico.
     */
    public static function getHash(string $filename): ?string
    {
        $filePath = self::jsonDir() . $filename;
        if (!File::exists($filePath)) {
            return null;
        }
        $content = file_get_contents($filePath);
        $decoded = json_decode($content, true);
        return $decoded['_meta']['hash'] ?? md5($content);
    }
}
