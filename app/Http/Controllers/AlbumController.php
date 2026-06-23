<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Helpers\Validations;
use App\Models\Album;
use App\Models\Music;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class AlbumController extends Controller
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
        path: '/albums',
        summary: 'Listar álbuns',
        description: 'Retorna lista paginada de álbuns, com suporte a filtros por idioma e busca textual',
        tags: ['Admin - Álbuns'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Idioma', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pt')),
            new OA\Parameter(name: 'q', description: 'Busca textual', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', description: 'Página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', description: 'Itens por página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de álbuns', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]
    public function index(Request $request)
    {
        $model = new Album;
        $fields = [
            'albums.id_album',
            'albums.name',
            'albums.id_file_image',
            DB::raw('concat("' . config("files.url") . '",files.dir,"/",files.file_name) as url_image'),
            DB::raw('files.version as image_version'),
            'albums.id_language',
            'albums.color',
            DB::raw((isset($request["categories_slug"]) ? 'categories_albums.name' : '""') . ' as subtitle'),
            DB::raw((isset($request["categories_slug"]) ? 'categories_albums.order' : '""') . ' as `order`'),
            'albums.created_at',
            'albums.updated_at',
        ];
        $data = $model->select($fields)
            ->leftJoin('files', 'albums.id_file_image', 'files.id_file');

        if ($request->id_language) {
            $data->where('albums.id_language', $request->id_language);
        }

        if (isset($request["categories_slug"])) {
            $categories = explode(",", $request["categories_slug"]);
            $data = $data
                ->join('categories_albums', 'categories_albums.id_album', 'albums.id_album')
                ->join('categories', 'categories.id_category', 'categories_albums.id_category')
                ->whereIn('categories.slug', $categories);
        }

        if (isset($request["with_categories"]) && $request["with_categories"] == 1) {
            $data = $data->with('categories');
        }
        $data = $data->distinct();

        return response()->json(Data::data($data, $request, $fields));
    }

    #[OA\Get(
        path: '/albums/{id}',
        summary: 'Buscar álbum por ID',
        description: 'Retorna os dados detalhados de um(a) álbum específico(a)',
        tags: ['Admin - Álbuns'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) álbum', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dados do(a) álbum', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Álbum não encontrado(a)')
        ]
    )]
    public function show($id, Request $request)
    {
        $album = Album::select(
            'albums.id_album',
            'albums.name',
            'albums.id_file_image',
            DB::raw('concat("' . config("files.url") . '",files.dir,"/",files.file_name) as url_image'),
            'files.version as image_version',
            'albums.id_language',
            'albums.color',
            'albums.created_at',
            'albums.updated_at',
        )
            ->leftJoin('files', 'albums.id_file_image', 'files.id_file')
            ->find($id);
        if ($album) {
            $album->musics = Music::where('albums_musics.id_album', $album->id_album)
                ->leftJoin('albums_musics', 'albums_musics.id_music', 'musics.id_music')
                ->leftJoin('files as files_image', 'musics.id_file_image', 'files_image.id_file')
                ->leftJoin('files as files_music', 'musics.id_file_music', 'files_music.id_file')
                ->leftJoin('files as files_instrumental_music', 'musics.id_file_instrumental_music', 'files_instrumental_music.id_file')
                ->select(
                    'musics.id_music',
                    'albums_musics.track',
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
                )
                ->orderBy('albums_musics.track')
                ->get();
        }

        $data = (object) [];
        $data->data = $album;

        if (!$album) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        return response()->json($data);
    }

    #[OA\Post(
        path: '/albums',
        summary: 'Criar álbum',
        description: 'Cria um novo(a) álbum. Requer autenticação admin.',
        tags: ['Admin - Álbuns'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 201, description: 'Álbum criado(a) com sucesso', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 422, description: 'Dados de validação inválidos')
        ]
    )]
    public function store(Request $request)
    {
        $this->validate($request, $this->validationRules($request), $this->validationMessages());

        $album = Album::create($request->all());

        $data = (object) [];
        $data->data = $album;
        $data->message = 'Registro cadastrado com sucesso!';
        return response()->json($data, 201);
    }

    #[OA\Put(
        path: '/albums/{id}',
        summary: 'Atualizar álbum',
        description: 'Atualiza os dados de um(a) álbum existente',
        tags: ['Admin - Álbuns'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) álbum', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Álbum atualizado(a) com sucesso', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Álbum não encontrado(a)'),
            new OA\Response(response: 422, description: 'Dados de validação inválidos')
        ]
    )]
    public function update(Request $request, $id)
    {
        $this->validate($request, $this->validationRules($request, $id), $this->validationMessages());

        $album = Album::find($id);

        $data = (object) [];
        $data->data = $album;

        if (!$album) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        $album->update($request->all());

        $data->message = 'Registro alterado com sucesso!';
        return response()->json($data);
    }

    #[OA\Delete(
        path: '/albums/{id}',
        summary: 'Excluir álbum',
        description: 'Remove um(a) álbum pelo ID',
        tags: ['Admin - Álbuns'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) álbum', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Álbum excluído(a) com sucesso', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')]
            )),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Álbum não encontrado(a)')
        ]
    )]
    public function destroy($id)
    {
        $album = Album::find($id);

        $data = (object) [];
        $data->data = $album;

        if (!$album) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        $album->delete();
        return response()->json(['message' => 'Registro excluído com sucesso!']);
    }
}