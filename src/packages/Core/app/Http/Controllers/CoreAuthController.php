<?php

namespace Packages\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Packages\Core\Models\CoreUserModel;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
        $data = $request->all();

        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;

        if ($email === null || $password === null) {
            return response()->json(['error' => 'Email and password are required'], 422);
        }

        // Aislamiento por BD: el login corre contra la BD del subdominio (conexion ya conmutada por
        // CoreResolvePackageFromHostMiddleware) o la central (operador). Solo busca por email.
        /** @var CoreUserModel|null $user */
        $user = CoreUserModel::query()->where('email', $email)->first();

        $hash = $user?->password;

        if ($user === null || $hash === null || $hash === '' || !Hash::check($password, (string) $hash)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $name = $data['name'] ?? 'login';
        $abilities = Arr::wrap($data['abilities'] ?? '*');
        $token = $user->createToken($name, $abilities === ['*'] ? ['*'] : $abilities);

        if (!empty($data['expires_at'])) {
            try {
                $token->accessToken->expires_at = Carbon::parse($data['expires_at']);
                $token->accessToken->save();
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Invalid expires_at'], 422);
            }
        }

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
