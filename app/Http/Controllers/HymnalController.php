<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Models\Music;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HymnalController extends Controller
{
    public function __construct()
    {

    }

    public function index(Request $request)
    {
        $model = new Music;
        $data = $model->select()->where('musics.id_language', $request->id_language)
            ->join('albums_musics', 'albums_musics.id_music', 'musics.id_music')
            ->join('categories_albums', 'categories_albums.id_album', 'albums_musics.id_album')
            ->join('categories', 'categories.id_category', 'categories_albums.id_category')
            ->leftJoin('files as files_image', 'musics.id_file_image', 'files_image.id_file')
            ->leftJoin('files as files_music', 'musics.id_file_music', 'files_music.id_file')
            ->leftJoin('files as files_instrumental_music', 'musics.id_file_instrumental_music', 'files_instrumental_music.id_file')
            ->where('categories.slug', 'hymnal')
            ->where('categories.id_language', $request->id_language)
            ->select(
                'musics.id_music',
                'musics.name',
                'albums_musics.track',
                'musics.id_file_image',
                DB::raw('concat(files_image.base_url,files_image.subdirectory,files_image.file_name) as url_image'),
                'files_image.version as image_version',
                'musics.id_file_music',
                DB::raw('concat(files_music.base_url,files_music.subdirectory,files_music.file_name) as url_music'),
                'files_music.version as music_version',
                'musics.id_file_instrumental_music',
                DB::raw('concat(files_instrumental_music.base_url,files_instrumental_music.subdirectory,files_instrumental_music.file_name) as url_instrumental_music'),
                'files_instrumental_music.version as instrumental_music_version',
                'musics.id_language',
                'musics.created_at',
                'musics.updated_at',
            );
        return response()->json(Data::data($data, $request, $model->getFillable()));
    }

    public function create(Request $request)
    {
        //
    }

    public function store(Request $request)
    {
        //
    }

    public function show(Music $music)
    {
        //
    }

    public function edit(Music $music)
    {
        //
    }

    public function update(Request $request, Music $music)
    {
        //
    }

    public function destroy(Music $music)
    {
        //
    }
}
