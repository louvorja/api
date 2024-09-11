<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Helpers\Validations;
use App\Models\AlbumMusic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AlbumMusicController extends Controller
{
    public function validationRules(Request $request, $id = null)
    {
        return [
            'id_music' => 'required',
            'id_album' => 'required|unique:albums_musics,id_album,' . ($id ? $id : 'NULL') . ',id_album_music,id_music,' . $request->input('id_music'),
            'id_language' => 'required|string|exists:languages,id_language',
        ];
    }

    private function validationMessages()
    {
        return Validations::validationMessages();
    }

    public function index(Request $request)
    {
        $model = new AlbumMusic;
        $fields = [
            'albums_musics.id_album_music',
            'albums_musics.id_music',
            DB::raw('musics.name as music_name'),
            'albums_musics.id_album',
            DB::raw('albums.name as album_name'),
            'albums_musics.track',
            'albums_musics.id_language',
        ];
        $data = $model->select($fields)
            ->leftJoin('musics', 'albums_musics.id_music', 'musics.id_music')
            ->leftJoin('albums', 'albums_musics.id_album', 'albums.id_album');
        if ($request->id_language) {
            $data->where('albums_musics.id_language', $request->id_language);
        }
        return response()->json(Data::data($data, $request, $fields));
    }

    public function store(Request $request)
    {
        $this->validate($request, $this->validationRules($request), $this->validationMessages());

        $album_music = AlbumMusic::create($request->all());

        $data = (object) [];
        $data->data = $album_music;
        $data->message = 'Registro cadastrado com sucesso!';
        return response()->json($data, 201);
    }

    public function show($id, Request $request)
    {
        $album_music = AlbumMusic::with(['album', 'music'])->find($id);

        $data = (object) [];
        $data->data = $album_music;

        if (!$album_music) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        return response()->json($data);
    }

    public function update(Request $request, $id)
    {
        $this->validate($request, $this->validationRules($request, $id), $this->validationMessages());

        $album_music = AlbumMusic::find($id);

        $data = (object) [];
        $data->data = $album_music;

        if (!$album_music) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        $album_music->update($request->all());

        $data->message = 'Registro alterado com sucesso!';
        return response()->json($data);
    }

    public function destroy($id)
    {
        $album_music = AlbumMusic::find($id);

        $data = (object) [];
        $data->data = $album_music;

        if (!$album_music) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        $album_music->delete();
        return response()->json(['message' => 'Registro excluído com sucesso!']);
    }
}
