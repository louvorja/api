<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Helpers\Validations;
use App\Models\Lyric;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

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

    #[OA\Get(
        path: '/{lang}/lyrics',
        summary: 'Listar letras (público)',
        description: 'Retorna lista paginada de letras para o idioma informado',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Código do idioma', in: 'path', required: true, schema: new OA\Schema(type: 'string', default: 'pt')),
            new OA\Parameter(name: 'q', description: 'Busca textual', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', description: 'Página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', description: 'Itens por página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de letras', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object')))
        ]
    )]
    #[OA\Get(
        path: '/admin/lyrics',
        summary: 'Listar letras',
        description: 'Retorna lista paginada de letras, com suporte a filtros por idioma e busca textual',
        tags: ['Admin - Letras'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Idioma', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pt')),
            new OA\Parameter(name: 'q', description: 'Busca textual', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', description: 'Página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', description: 'Itens por página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de letras', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]
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

    #[OA\Get(
        path: '/admin/lyrics/{id}',
        summary: 'Buscar letra por ID',
        description: 'Retorna os dados detalhados de um(a) letra específico(a)',
        tags: ['Admin - Letras'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) letra', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dados do(a) letra', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Letra não encontrado(a)')
        ]
    )]
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

    #[OA\Post(
        path: '/admin/lyrics',
        summary: 'Criar letra',
        description: 'Cria um novo(a) letra. Requer autenticação admin.',
        tags: ['Admin - Letras'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 201, description: 'Letra criado(a) com sucesso', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 422, description: 'Dados de validação inválidos')
        ]
    )]
    public function store(Request $request)
    {
        $this->validate($request, $this->validationRules($request), $this->validationMessages());

        $lyric = Lyric::create($request->all());

        $data = (object) [];
        $data->data = $lyric;
        $data->message = 'Registro cadastrado com sucesso!';
        return response()->json($data, 201);
    }

    #[OA\Put(
        path: '/admin/lyrics/{id}',
        summary: 'Atualizar letra',
        description: 'Atualiza os dados de um(a) letra existente',
        tags: ['Admin - Letras'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) letra', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Letra atualizado(a) com sucesso', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Letra não encontrado(a)'),
            new OA\Response(response: 422, description: 'Dados de validação inválidos')
        ]
    )]
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

    #[OA\Delete(
        path: '/admin/lyrics/{id}',
        summary: 'Excluir letra',
        description: 'Remove um(a) letra pelo ID',
        tags: ['Admin - Letras'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) letra', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Letra excluído(a) com sucesso', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')]
            )),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Letra não encontrado(a)')
        ]
    )]
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