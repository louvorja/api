<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Helpers\Validations;
use App\Models\Lyric;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LyricController extends Controller
{
    public function validationRules(Request $request, $id = null)
    {
        return [
            'id_music' => 'required|exists:musics,id_music',
            'id_language' => 'required|string|exists:languages,id_language',
        ];
    }

    private function validationMessages()
    {
        return Validations::validationMessages();
    }

    public function index(Request $request)
    {
        $model = new Lyric;
        $fields = [
            'lyrics.id_lyric',
            'lyrics.id_music',
            DB::raw('musics.name as music'),
            'lyrics.lyric',
            'lyrics.aux_lyric',
            'lyrics.id_file_image',
            'lyrics.time',
            'lyrics.instrumental_time',
            'lyrics.show_slide',
            'lyrics.order',
            'lyrics.id_language',
        ];
        $data = $model->select($fields)
            ->leftJoin('musics', 'musics.id_music', 'lyrics.id_music');

        if ($request->id_language) {
            $data->where('lyrics.id_language', $request->id_language);
        }

        if (isset($request["id_album"])) {
            $data = $data
                ->join('albums_musics', 'albums_musics.id_music', 'lyrics.id_music')
                ->where('albums_musics.id_album', $request["id_album"]);
        }

        return response()->json(Data::data($data, $request, $fields));
    }

    public function show($id, Request $request)
    {
        $lyric = Lyric::select(
            'lyrics.id_lyric',
            'lyrics.id_music',
            'lyrics.lyric',
            'lyrics.aux_lyric',
            'lyrics.id_file_image',
            DB::raw('concat("' . config("files.url") . '",files.dir,"/",files.file_name) as url_image'),
            DB::raw('files.version as image_version'),
            'lyrics.time',
            'lyrics.instrumental_time',
            'lyrics.show_slide',
            'lyrics.order',
            'lyrics.id_language',
            'lyrics.created_at',
            'lyrics.updated_at',
        )
            ->leftJoin('files', 'lyrics.id_file_image', 'files.id_file')
            ->find($id);

        $data = (object) [];
        $data->data = $lyric;

        if (!$lyric) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        return response()->json($data);
    }

    public function store(Request $request)
    {
        $this->validate($request, $this->validationRules($request), $this->validationMessages());

        $lyric = Lyric::create($request->all());

        $data = (object) [];
        $data->data = $lyric;
        $data->message = 'Registro cadastrado com sucesso!';
        return response()->json($data, 201);
    }

    public function update(Request $request, $id)
    {
        $this->validate($request, $this->validationRules($request, $id), $this->validationMessages());

        $lyric = Lyric::find($id);

        $data = (object) [];
        $data->data = $lyric;

        if (!$lyric) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        $lyric->update($request->all());

        $data->message = 'Registro alterado com sucesso!';
        return response()->json($data);
    }

    public function destroy($id)
    {
        $lyric = Lyric::find($id);

        $data = (object) [];
        $data->data = $lyric;

        if (!$lyric) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        $lyric->delete();
        return response()->json(['message' => 'Registro excluído com sucesso!']);
    }
}
