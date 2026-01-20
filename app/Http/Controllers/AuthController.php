<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $this->validate($request, [
            'username' => 'required|string',
            'password' => 'required|string',
        ], [
            'username.required' => 'O campo nome de usuário é obrigatório.',
            'username.string' => 'O campo nome de usuário deve ser uma string.',
            'password.required' => 'O campo senha é obrigatório.',
            'password.string' => 'O campo senha deve ser uma string.',
        ]);

        $credentials = $request->only('username', 'password');

        try {
            if (!$token = JWTAuth::attempt($credentials)) {
                return response()->json(['error' => 'Login ou senha incorretos.'], 401);
            }
            $user = auth()->user();
        } catch (JWTException $e) {
            return response()->json(['error' => 'Ocorreu um erro ao tentar fazer o login.'], 500);
        }

        return response()->json(['token' => $token, 'user' => $user]);
    }

    public function me()
    {
        return response()->json(JWTAuth::parseToken()->authenticate());
    }

    public function refreshToken(Request $request)
    {
        try {
            $currentToken = JWTAuth::getToken();

            if (!$newToken = JWTAuth::refresh($currentToken)) {
                return response()->json([
                    'error' => 'Não foi possível atualizar o token.',
                ], 500);
            }

            $user = auth()->user();

            return response()->json([
                'token' => $newToken,
                'user' => $user
            ]);
        } catch (JWTException $e) {
            return response()->json([
                'error' => 'Token inválido ou expirado.',
            ], 401);
        }
    }

    public function logout(Request $request)
    {
        try {
            // Obtém o token do cabeçalho Authorization
            $token = JWTAuth::getToken();

            // Invalida o token
            JWTAuth::invalidate($token);

            return response()->json([]);
        } catch (JWTException $e) {
            return response()->json([
                'error' => 'Não foi possível realizar o logout.',
            ], 500);
        }
    }

    public function changePassword(Request $request)
    {
        $this->validate($request, [
            'current_password' => 'required|string',
            'new_password' =>  [
                'required',
                'string',
                'min:8',
                'confirmed',
                'regex:/^(?=.*[A-Za-z])(?=.*\d)(?=.*[@$!%*#?&])[A-Za-z\d@$!%*#?&]{8,}$/'
            ],
        ], [
            'current_password.required' => 'O campo senha atual é obrigatório.',
            'new_password.required' => 'O campo nova senha é obrigatório.',
            'new_password.min' => 'A nova senha deve ter no mínimo 8 caracteres.',
            'new_password.confirmed' => 'A confirmação da nova senha não coincide.',
            'new_password.regex' => 'A nova senha deve conter pelo menos uma letra, um número e um caractere especial.',
        ]);

        $user = $request->user();

        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json(['error' => 'Senha atual incorreta.'], 400);
        }

        if (Hash::check($request->input('new_password'), $user->password)) {
            return response()->json(['error' => 'A nova senha não pode ser igual à senha atual.'], 400);
        }

        $user->password = Hash::make($request->input('new_password'));
        $user->is_temporary_password = false;
        $user->save();

        return response()->json(['message' => 'Senha alterada com sucesso.']);
    }
}
