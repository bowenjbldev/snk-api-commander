<?php

namespace Packages\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Packages\Core\Models\CoreUserModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

#[Group('Auth')]
class CoreAuthController extends Controller
{
    #[Endpoint(
        title: '1. Login',
        description: 'Authenticates a user.',
    )]
    #[BodyParameter('email', description: 'User email.', type: 'string', required: true, example: 'admin@snake.com')]
    #[BodyParameter('password', description: 'User password.', type: 'string', required: true, example: 'secret1234')]
    #[Response(200, 'Success')]
    #[Response(401, 'Unauthorized')]
    #[Response(422, 'Validation')]
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:255',
        ]);

        // Aislamiento por BD: el login corre contra la BD del subdominio (conexion ya conmutada por
        // CoreResolvePackageFromHostMiddleware) o la commander (operador). Solo busca por email.
        /** @var CoreUserModel|null $user */
        $user = CoreUserModel::query()->where('email', $data['email'])->first();

        $hash = $user?->password;

        if ($user === null || $hash === null || $hash === '' || !Hash::check($data['password'], (string) $hash)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        // Nombre, capacidades y caducidad son politica del servidor, nunca entrada del cliente.
        $token = $user->createToken(
            'login',
            ['api'],
            now()->addMinutes((int) config('core.auth.token_expiration_minutes', 1440)),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $user,
            'token_id' => $token->accessToken->id,
        ]);
    }

    #[Endpoint(
        title: '2. Logout',
        description: 'Logs out the current authenticated user.',
    )]
    #[Response(200, 'Success')]
    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out']);
    }

    #[Endpoint(
        title: '3. Current User',
        description: 'Returns the currently authenticated user including their assigned roles and all inherited permissions.',
    )]
    #[Response(200, 'Success')]
    #[Response(401, 'Unauthorized')]
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if ($user) {
            $user->setAttribute('all_permissions', $user->getAllPermissions()->pluck('name'));
            $user->setAttribute('role_names', $user->roles()->pluck('name'));
        }

        return response()->json([
            'user' => $user,
        ]);
    }
}
