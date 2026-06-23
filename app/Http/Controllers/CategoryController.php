<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Helpers\Validations;
use App\Models\Category;
use Illuminate\Http\Request;
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

    #[OA\Get(
        path: '/categories',
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
        path: '/categories/{id}',
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
        path: '/categories',
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
        path: '/categories/{id}',
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
        path: '/categories/{id}',
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