<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Helpers\Validations;
use App\Models\Album;
use App\Models\Category;
use App\Models\Music;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class CategoryController extends Controller
{
    public function validationRules(Request $request, $id = null)
    {
        return [
            'name' => 'required|string',
            'slug' => 'required|string|unique:categories,slug,' . ($id ? $id : 'NULL') . ',id_category,id_language,' . $request->input('id_language'),
            'id_language' => 'required|string|exists:languages,id_language',
        ];
    }

    private function validationMessages()
    {
        return Validations::validationMessages();
    }

    /**
     * Display a listing of the resource.
     */
    #[OA\Get(
        path: '/{lang}/categories',
        summary: 'Listagem de categorias (público)',
        description: 'Retorna a listagem de categorias para o idioma informado',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Código do idioma', in: 'path', required: true, schema: new OA\Schema(type: 'string', default: 'pt'))
        ],
        responses: [
            new OA\Response(response: 200, description: '...', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object')))
        ]
    )]
    #[OA\Get(
        path: '/admin/categories',
        summary: 'Listar categorias',
        description: 'Retorna lista paginada de categorias, com suporte a filtros por idioma e busca textual',
        tags: ['Admin - Categorias'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Idioma', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pt')),
            new OA\Parameter(name: 'q', description: 'Busca textual', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', description: 'Página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', description: 'Itens por página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de categorias', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]
    public function index(Request $request)
    {
        $model = new Category;
        $data = $model->select();
        if ($request->id_language) {
            $data->where('id_language', $request->id_language);
        }
        return response()->json(Data::data($data, $request, [$model->getKeyName(), ...$model->getFillable()]));
    }

    #[OA\Get(
        path: '/{lang}/categories/{id}/albums',
        summary: 'Álbuns de uma categoria (público)',
        description: 'Retorna lista paginada de álbuns pertencentes a uma categoria específica',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Código do idioma', in: 'path', required: true, schema: new OA\Schema(type: 'string', default: 'pt')),
            new OA\Parameter(name: 'id', description: 'ID da categoria', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', description: 'Página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', description: 'Itens por página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de álbuns', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'Categoria não encontrada')
        ]
    )]
    public function albums(Request $request, $id)
    {
        // Validate category exists (404 if not)
        Category::where('id_category', $id)
            ->where('id_language', $request->id_language)
            ->firstOrFail();

        $cacheKey = "categories.{$request->lang}.{$id}.albums.{$request->get('page', 1)}";
        return Cache::remember($cacheKey, 300, function () use ($request, $id) {
            $fields = [
                'albums.id_album',
                'albums.name',
                'albums.id_file_image',
                DB::raw('concat("' . config("files.url") . '",files.dir,"/",files.file_name) as url_image'),
                'files.version as image_version',
                'albums.id_language',
                'albums.color',
                'categories_albums.order',
                'albums.created_at',
                'albums.updated_at',
            ];
            $data = (new Album)->select($fields)
                ->join('categories_albums', 'categories_albums.id_album', 'albums.id_album')
                ->join('categories', 'categories.id_category', 'categories_albums.id_category')
                ->leftJoin('files', 'albums.id_file_image', 'files.id_file')
                ->where('categories.id_category', $id)
                ->where('albums.id_language', $request->id_language)
                ->orderBy('categories_albums.order');

            return response()->json(Data::data($data, $request, $fields));
        });
    }

    #[OA\Get(
        path: '/{lang}/categories/{id}/albums-with-musics',
        summary: 'Álbuns com músicas de uma categoria (público)',
        description: 'Retorna uma categoria com seus álbuns e respectivas músicas em estrutura aninhada',
        tags: ['Public'],
        security: [],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Código do idioma', in: 'path', required: true, schema: new OA\Schema(type: 'string', default: 'pt')),
            new OA\Parameter(name: 'id', description: 'ID da categoria', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Estrutura aninhada', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 404, description: 'Categoria não encontrada')
        ]
    )]
    public function albumsWithMusics(Request $request, $id)
    {
        $category = Category::where('id_category', $id)
            ->where('id_language', $request->id_language)
            ->firstOrFail();

        $cacheKey = "categories.{$request->lang}.{$id}.awm";
        return Cache::remember($cacheKey, 300, function () use ($request, $id, $category) {
            // Get albums for this category
            $albums = (new Album)->select([
                'albums.id_album',
                'albums.name',
                'albums.id_file_image',
                DB::raw('concat("' . config("files.url") . '",files.dir,"/",files.file_name) as url_image'),
                'files.version as image_version',
                'albums.id_language',
                'albums.color',
                'categories_albums.order',
            ])
                ->join('categories_albums', 'categories_albums.id_album', 'albums.id_album')
                ->join('categories', 'categories.id_category', 'categories_albums.id_category')
                ->leftJoin('files', 'albums.id_file_image', 'files.id_file')
                ->where('categories.id_category', $id)
                ->where('albums.id_language', $request->id_language)
                ->orderBy('categories_albums.order')
                ->get();

            // Batch query for musics (avoids N+1)
            $albumIds = $albums->pluck('id_album')->toArray();
            $musicsByAlbum = collect();
            if (!empty($albumIds)) {
                $musicsByAlbum = Music::select([
                    'albums_musics.id_album',
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

            // Nest musics into albums
            foreach ($albums as $album) {
                $album->musics = $musicsByAlbum->get($album->id_album, collect())->values();
            }

            return response()->json([
                'category' => $category,
                'albums' => $albums,
            ]);
        });
    }

    #[OA\Get(
        path: '/admin/categories/{id}',
        summary: 'Buscar categoria por ID',
        description: 'Retorna os dados detalhados de um(a) categoria específico(a)',
        tags: ['Admin - Categorias'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) categoria', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dados do(a) categoria', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Categoria não encontrado(a)')
        ]
    )]
    public function show($id, Request $request)
    {
        $category = Category::find($id);

        $data = (object) [];
        $data->data = $category;

        if (!$category) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        return response()->json($data);
    }

    #[OA\Post(
        path: '/admin/categories',
        summary: 'Criar categoria',
        description: 'Cria um novo(a) categoria. Requer autenticação admin.',
        tags: ['Admin - Categorias'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 201, description: 'Categoria criado(a) com sucesso', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 422, description: 'Dados de validação inválidos')
        ]
    )]
    public function store(Request $request)
    {
        $this->validate($request, $this->validationRules($request), $this->validationMessages());

        $inputs = $request->all();
        if (!$request->filled('order')) {
            $inputs['order'] = 0;
        }
        $category = Category::create($inputs);

        $data = (object) [];
        $data->data = $category;
        $data->message = 'Registro cadastrado com sucesso!';
        return response()->json($data, 201);
    }

    #[OA\Put(
        path: '/admin/categories/{id}',
        summary: 'Atualizar categoria',
        description: 'Atualiza os dados de um(a) categoria existente',
        tags: ['Admin - Categorias'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) categoria', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Categoria atualizado(a) com sucesso', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Categoria não encontrado(a)'),
            new OA\Response(response: 422, description: 'Dados de validação inválidos')
        ]
    )]
    public function update(Request $request, $id)
    {
        $this->validate($request, $this->validationRules($request, $id), $this->validationMessages());

        $category = Category::find($id);

        $data = (object) [];
        $data->data = $category;

        if (!$category) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        $category->update($request->all());

        $data->message = 'Registro alterado com sucesso!';
        return response()->json($data);
    }

    #[OA\Delete(
        path: '/admin/categories/{id}',
        summary: 'Excluir categoria',
        description: 'Remove um(a) categoria pelo ID',
        tags: ['Admin - Categorias'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) categoria', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Categoria excluído(a) com sucesso', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')]
            )),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Categoria não encontrado(a)')
        ]
    )]
    public function destroy($id)
    {
        $category = Category::find($id);

        $data = (object) [];
        $data->data = $category;

        if (!$category) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        $category->delete();
        return response()->json(['message' => 'Registro excluído com sucesso!']);
    }
}
