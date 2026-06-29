<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Helpers\Validations;
use App\Models\CategoryAlbum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class CategoryAlbumController extends Controller
{
    public function validationRules(Request $request, $id = null)
    {
        return [
            'id_category' => 'required',
            'id_album' => 'required|unique:categories_albums,id_album,' . ($id ? $id : 'NULL') . ',id_category_album,id_category,' . $request->input('id_category'),
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
        path: '/{lang}/categories_albums',
        summary: 'Listagem de associações categoria-álbum (público)',
        description: 'Retorna a listagem de associações categoria-álbum para o idioma informado',
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
        path: '/admin/categories_albums',
        summary: 'Listar associações categoria-álbum',
        description: 'Retorna lista paginada',
        tags: ['Admin - Categorias-Álbuns'],
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
        $model = new CategoryAlbum;
        $fields = [
            'categories_albums.id_category_album',
            'categories_albums.id_category',
            DB::raw('categories.name as category_name'),
            'categories_albums.id_album',
            DB::raw('albums.name as album_name'),
            'categories_albums.name',
            'categories_albums.order',
            'categories_albums.id_language',
        ];
        $data = $model->select($fields)
            ->leftJoin('categories', 'categories_albums.id_category', 'categories.id_category')
            ->leftJoin('albums', 'categories_albums.id_album', 'albums.id_album');
        if ($request->id_language) {
            $data->where('categories_albums.id_language', $request->id_language);
        }
        return response()->json(Data::data($data, $request, $fields));
    }

    #[OA\Get(
        path: '/admin/categories_albums/{id}',
        summary: 'Buscar associação categoria-álbum por ID',
        tags: ['Admin - Categorias-Álbuns'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Dados', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Não encontrado')
        ]
    )]
    public function show($id, Request $request)
    {
        $category_album = CategoryAlbum::with(['category', 'album'])->find($id);

        $data = (object) [];
        $data->data = $category_album;

        if (!$category_album) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        return response()->json($data);
    }
  
    #[OA\Post(
        path: '/admin/categories_albums',
        summary: 'Criar associação categoria-álbum',
        tags: ['Admin - Categorias-Álbuns'],
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

        $inputs = $request->all();
        if (!$request->filled('order')) {
            $inputs['order'] = 0;
        }
        if (!$request->filled('name')) {
            $inputs['name'] = '';
        }
        $category_album = CategoryAlbum::create($inputs);

        $data = (object) [];
        $data->data = $category_album;
        $data->message = 'Registro cadastrado com sucesso!';
        return response()->json($data, 201);
    }

    #[OA\Put(
        path: '/admin/categories_albums/{id}',
        summary: 'Atualizar associação categoria-álbum',
        tags: ['Admin - Categorias-Álbuns'],
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

        $category_album = CategoryAlbum::find($id);

        $data = (object) [];
        $data->data = $category_album;

        if (!$category_album) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        $category_album->update($request->all());

        $data->message = 'Registro alterado com sucesso!';
        return response()->json($data);
    }

    #[OA\Delete(
        path: '/admin/categories_albums/{id}',
        summary: 'Excluir associação categoria-álbum',
        tags: ['Admin - Categorias-Álbuns'],
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Excluído'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Não encontrado')
        ]
    )]
    public function destroy($id)
    {
        $category_album = CategoryAlbum::find($id);

        $data = (object) [];
        $data->data = $category_album;

        if (!$category_album) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        $category_album->delete();
        return response()->json(['message' => 'Registro excluído com sucesso!']);
    }
}