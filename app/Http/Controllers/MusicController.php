<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Helpers\Validations;
use App\Models\Lyric;
use App\Models\AlbumMusic;
use App\Models\Music;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class MusicController extends Controller
{
    public function validationRules(Request $request, $id = null)
    {
        return [
            'name' => 'required|string',
            'id_language' => 'required|string|exists:languages,id_language',
        ];
    }

    private function validationMessages()
    {
        return Validations::validationMessages();
    }

    #[OA\Get(
        path: '/musics',
        summary: 'Listar músicas',
        description: 'Retorna lista paginada de músicas, com suporte a filtros por idioma e busca textual',
        tags: ['Admin - Músicas'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Idioma', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pt')),
            new OA\Parameter(name: 'q', description: 'Busca textual', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', description: 'Página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', description: 'Itens por página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de músicas', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]
    public function index(Request $request)
    {
        $model = new Music;
        $fields = [
            'musics.id_music',
            'musics.name',
            'musics.id_file_image',
            DB::raw('concat("' . config("files.url") . '",files_image.dir,"/",files_image.file_name) as url_image'),
            'files_image.version as image_version',
            'musics.id_file_music',
            DB::raw('concat("' . config("files.url") . '",files_music.dir,"/",files_music.file_name) as url_music'),
            'files_music.version as music_version',
            'musics.id_file_instrumental_music',
            DB::raw('concat("' . config("files.url") . '",files_instrumental_music.dir,"/",files_instrumental_music.file_name) as url_instrumental_music'),
            'files_instrumental_music.version as instrumental_music_version',
            'musics.id_language',
            'musics.created_at',
            'musics.updated_at',
        ];
        $data = $model->select($fields)
            ->leftJoin('files as files_image', 'musics.id_file_image', 'files_image.id_file')
            ->leftJoin('files as files_music', 'musics.id_file_music', 'files_music.id_file')
            ->leftJoin('files as files_instrumental_music', 'musics.id_file_instrumental_music', 'files_instrumental_music.id_file');
        if ($request->id_language) {
            $data->where('musics.id_language', $request->id_language);
        }

        if (isset($request["with_albums"]) && $request["with_albums"] == 1) {
            $data = $data->with('albums');
        }

        if (isset($request["id_album"])) {
            $data = $data
                ->join('albums_musics', 'albums_musics.id_music', 'musics.id_music')
                ->where('albums_musics.id_album', $request["id_album"]);
        }

        return response()->json(Data::data($data, $request, $fields));
    }

    #[OA\Get(
        path: '/musics/{id}',
        summary: 'Buscar música por ID',
        description: 'Retorna os dados detalhados de um(a) música específico(a)',
        tags: ['Admin - Músicas'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) música', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dados do(a) música', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Música não encontrado(a)')
        ]
    )]
    public function show($id, Request $request)
    {
        $music = Music::select(
            'musics.id_music',
            'musics.name',
            'musics.id_file_image',
            DB::raw('concat("' . config("files.url") . '",files_image.dir,"/",files_image.file_name) as url_image'),
            DB::raw('files_image.version as image_version'),
            DB::raw('files_image.image_position as image_position'),
            'musics.id_file_music',
            DB::raw('concat("' . config("files.url") . '",files_music.dir,"/",files_music.file_name) as url_music'),
            DB::raw('files_music.version as music_version'),
            'musics.id_file_instrumental_music',
            DB::raw('concat("' . config("files.url") . '",files_instrumental_music.dir,"/",files_instrumental_music.file_name) as url_instrumental_music'),
            DB::raw('files_instrumental_music.version as instrumental_music_version'),
            'musics.id_language',
            'musics.created_at',
            'musics.updated_at',
        )
            ->leftJoin('files as files_image', 'musics.id_file_image', 'files_image.id_file')
            ->leftJoin('files as files_music', 'musics.id_file_music', 'files_music.id_file')
            ->leftJoin('files as files_instrumental_music', 'musics.id_file_instrumental_music', 'files_instrumental_music.id_file')
            ->find($id);
        if ($music) {
            $music->lyric = Lyric::where('id_music', $music->id_music)
                ->leftJoin('files as files_image', 'lyrics.id_file_image', 'files_image.id_file')
                ->select(
                    'lyrics.id_lyric',
                    'lyrics.id_music',
                    'lyrics.lyric',
                    DB::raw('ifnull(lyrics.id_file_image,0' . $music->id_file_image . ') id_file_image'),
                    DB::raw('ifnull(concat("' . config("files.url") . '",files_image.dir,"/",files_image.file_name),"' . $music->url_image . '") as url_image'),
                    DB::raw('ifnull(files_image.version,0' . $music->image_version . ') as image_version'),
                    DB::raw('ifnull(files_image.image_position,0' . $music->image_position . ') as image_position'),
                    'lyrics.time',
                    'lyrics.instrumental_time',
                    'lyrics.show_slide',
                    'lyrics.order',
                    'lyrics.id_language',
                    'lyrics.created_at',
                    'lyrics.updated_at',
                )
                ->orderBy('order')->get();
        }

        $data = (object) [];
        $data->data = $music;

        return response()->json($data);
    }

    #[OA\Post(
        path: '/musics',
        summary: 'Criar música',
        description: 'Cria um novo(a) música. Requer autenticação admin.',
        tags: ['Admin - Músicas'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 201, description: 'Música criado(a) com sucesso', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 422, description: 'Dados de validação inválidos')
        ]
    )]
    public function store(Request $request)
    {
        $this->validate($request, $this->validationRules($request), $this->validationMessages());

        $music = Music::create($request->all());

        $data = (object) [];
        $data->data = $music;
        $data->message = 'Registro cadastrado com sucesso!';
        return response()->json($data, 201);
    }

    #[OA\Put(
        path: '/musics/{id}',
        summary: 'Atualizar música',
        description: 'Atualiza os dados de um(a) música existente',
        tags: ['Admin - Músicas'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) música', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Música atualizado(a) com sucesso', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Música não encontrado(a)'),
            new OA\Response(response: 422, description: 'Dados de validação inválidos')
        ]
    )]
    public function update(Request $request, $id)
    {
        $this->validate($request, $this->validationRules($request, $id), $this->validationMessages());

        $music = Music::find($id);

        $data = (object) [];
        $data->data = $music;

        if (!$music) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        $music->update($request->all());

        $data->message = 'Registro alterado com sucesso!';
        return response()->json($data);
    }

    #[OA\Delete(
        path: '/musics/{id}',
        summary: 'Excluir música',
        description: 'Remove um(a) música pelo ID',
        tags: ['Admin - Músicas'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) música', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Música excluído(a) com sucesso', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')]
            )),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Música não encontrado(a)')
        ]
    )]
    public function destroy($id)
    {
        $music = Music::find($id);

        $data = (object) [];
        $data->data = $music;

        if (!$music) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        Lyric::where('id_music', $id)->delete();
        AlbumMusic::where('id_music', $id)->delete();
        $music->delete();
        return response()->json(['message' => 'Registro excluído com sucesso!']);
    }
}