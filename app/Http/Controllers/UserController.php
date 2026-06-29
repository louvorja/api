<?php

namespace App\Http\Controllers;

use App\Helpers\Data;
use App\Helpers\Validations;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

class UserController extends Controller
{
    public function validationRules(Request $request, $id = null)
    {
        return [
            'name' => 'required|string',
            'username' => 'required|string|unique:users,username' . ($id ? ",$id" : ''),
            'email' => 'required|string|email|unique:users,email' . ($id ? ",$id" : ''),
        ];
    }

    private function validationMessages()
    {
        return Validations::validationMessages();
    }


    #[OA\Get(
        path: '/admin/users',
        summary: 'Listar usuários',
        description: 'Retorna lista paginada de usuários, com suporte a filtros por idioma e busca textual',
        tags: ['Admin - Usuários'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'lang', description: 'Idioma', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'pt')),
            new OA\Parameter(name: 'q', description: 'Busca textual', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', description: 'Página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', description: 'Itens por página', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de usuários', content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))),
            new OA\Response(response: 401, description: 'Não autenticado')
        ]
    )]
    public function index(Request $request)
    {
        $model = new User;
        $data = $model->select();

        return response()->json(Data::data($data, $request, [$model->getKeyName(), ...$model->getFillable()]));
    }

    #[OA\Get(
        path: '/admin/users/{id}',
        summary: 'Buscar usuário por ID',
        description: 'Retorna os dados detalhados de um(a) usuário específico(a)',
        tags: ['Admin - Usuários'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) usuário', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dados do(a) usuário', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Usuário não encontrado(a)')
        ]
    )]
    public function show($id, Request $request)
    {
        $user = User::find($id);

        $data = (object) [];
        $data->data = $user;

        if (!$user) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        return response()->json($data);
    }

    #[OA\Post(
        path: '/admin/users',
        summary: 'Criar usuário',
        description: 'Cria um novo(a) usuário. Requer autenticação admin.',
        tags: ['Admin - Usuários'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 201, description: 'Usuário criado(a) com sucesso', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 422, description: 'Dados de validação inválidos')
        ]
    )]
    public function store(Request $request)
    {
        $rules = $this->validationRules($request);
        $rules['password'] = 'required|string|min:6';

        $this->validate($request, $rules, $this->validationMessages());

        $inputs = $request->except('is_admin');
        if ($request->filled('password')) {
            $inputs['password'] = Hash::make($request->input('password'));
            $inputs['is_temporary_password'] = true;
        }
        $user = User::create($inputs);

        $data = (object) [];
        $data->data = $user;
        $data->message = 'Registro cadastrado com sucesso!';
        return response()->json($data, 201);
    }

    #[OA\Put(
        path: '/admin/users/{id}',
        summary: 'Atualizar usuário',
        description: 'Atualiza os dados de um(a) usuário existente',
        tags: ['Admin - Usuários'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) usuário', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(type: 'object')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Usuário atualizado(a) com sucesso', content: new OA\JsonContent(type: 'object')),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Usuário não encontrado(a)'),
            new OA\Response(response: 422, description: 'Dados de validação inválidos')
        ]
    )]
    public function update(Request $request, $id)
    {
        $rules = $this->validationRules($request, $id);
        if ($request->filled('password')) {
            $rules['password'] = 'required|string|min:6';
        }

        $this->validate($request, $rules, $this->validationMessages());

        $user = User::find($id);

        $data = (object) [];
        $data->data = $user;

        if (!$user) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        $inputs = $request->except('is_admin');
        if ($request->filled('password')) {
            if ($user->is_admin) {
                return response()->json(['error' => 'A senha do administrador não pode ser alterada por esta rota!'], 400);
            }
            $inputs['password'] = Hash::make($request->input('password'));
            $inputs['is_temporary_password'] = true;
        }
        if ($user->is_admin) {
            unset($inputs['permissions']);
        }
        $user->update($inputs);

        $data->message = 'Registro alterado com sucesso!';
        return response()->json($data);
    }

    #[OA\Delete(
        path: '/admin/users/{id}',
        summary: 'Excluir usuário',
        description: 'Remove um(a) usuário pelo ID',
        tags: ['Admin - Usuários'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', description: 'ID do(a) usuário', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Usuário excluído(a) com sucesso', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')]
            )),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 404, description: 'Usuário não encontrado(a)')
        ]
    )]
    public function destroy($id)
    {
        $user = User::find($id);

        $data = (object) [];
        $data->data = $user;

        if (!$user) {
            return response()->json(['error' => 'Registro não encontrado!'], 404);
        }

        if ($user->is_admin) {
            return response()->json(['error' => 'Este usuário não pode ser excluído!'], 400);
        }

        $user->delete();
        return response()->json(['message' => 'Registro excluído com sucesso!']);
    }
}