<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Helpers\Validations;
use App\Models\AlbumMusic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

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

    #[OA\Get(
        path: '/albums/{album_id}/musics',
        summary: 'Listar associações álbum-música',
        description: 'Retorna lista paginada',
        tags: ['Admin - Álbuns-Músicas'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Idioma', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pt')),
            new OA\Parameter(name: 'page', description: 'Página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]
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

    #[OA\Get(
        path: '/albums/{album_id}/musics/{id}',
        summary: 'Buscar associação álbum-música por ID',
        tags: ['Admin - Álbuns-Músicas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Dados', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Não encontrado')
        ]
    )]
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

    #[OA\Post(
        path: '/albums/{album_id}/musics',
        summary: 'Criar associação álbum-música',
        tags: ['Admin - Álbuns-Músicas'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 201, description: 'Criado com sucesso', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 422, description: 'Validação falhou')
        ]
    )]
    public function store(Request $request)
    {
        $this->validate($request, $this->validationRules($request), $this->validationMessages());

        $album_music = AlbumMusic::create($request->all());

        $data = (object) [];
        $data->data = $album_music;
        $data->message = 'Registro cadastrado com sucesso!';
        return response()->json($data, 201);
    }

    #[OA\Put(
        path: '/albums/{album_id}/musics/{id}',
        summary: 'Atualizar associação álbum-música',
        tags: ['Admin - Álbuns-Músicas'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')),
        responses: [
            new OA\Response(response: 200, description: 'Atualizado', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 422, description: 'Validação falhou')
        ]
    )]
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

    #[OA\Delete(
        path: '/albums/{album_id}/musics/{id}',
        summary: 'Excluir associação álbum-música',
        tags: ['Admin - Álbuns-Músicas'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Excluído'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Não encontrado')
        ]
    )]
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