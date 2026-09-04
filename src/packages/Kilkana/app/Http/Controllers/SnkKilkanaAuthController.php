<?php

namespace Packages\Kilkana\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Packages\Core\Http\Controllers\CoreAuthController;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Services\SnkKilkanaAccountLinkService;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;

#[Group('Auth')]
class SnkKilkanaAuthController extends CoreAuthController
{
    /**
     * Una cuenta suspendida no entra, y lo sabe.
     *
     * Se decide DESPUÉS de comprobar la contraseña, no antes: decir «suspendida» a quien no
     * sabe la contraseña sería confirmarle que la cuenta existe. Con la contraseña buena, el
     * token que acaba de nacer se revoca y la respuesta dice el motivo, que no es el mismo que
     * una contraseña mal escrita.
     */
    public function login(Request $request): JsonResponse
    {
        $response = parent::login($request);
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        // El padre acaba de encontrarla por este mismo correo, así que aquí siempre hay cuenta;
        // se escribe explícito para que «no la hay» se lea como lo que es y no como un `?.`.
        $user = CoreUserModel::query()->where('email', $request->input('email'))->first();
        if ($user === null || $user->suspended_at === null) {
            return $response;
        }

        $user->tokens()->whereKey($response->getData(true)['token_id'] ?? 0)->delete();

        return response()->json(['error' => 'Account suspended'], 403);
    }

    /**
     * Canjea el enlace de correo por una contraseña: la primera de una cuenta invitada o una
     * nueva para quien la perdió.
     *
     * Contra el modelo del paquete y no con `Password::reset()`: el broker canjearía sobre el
     * modelo de la aplicación y el hash lo pone el mutador de `CoreUserModel`. Poner contraseña
     * cierra la invitación y tira las sesiones que hubiera: es el gesto de recuperar la cuenta.
     */
    /**
     * «He olvidado mi contraseña», iniciado por quien la olvidó.
     *
     * Contesta 202 exista la cuenta o no —un formulario público no puede ser un oráculo de
     * quién trabaja en la redacción—, y solo manda el enlace a una cuenta que ya entró y ocupa
     * asiento: a una invitada le sirve su invitación, y a una suspendida no le sirve nada.
     * Si el correo falla se anota y se contesta igual: decirlo aquí sería decir que existe. Y va
     * a la cola, no al SMTP: el tiempo de respuesta tampoco puede decirlo.
     */
    public function sendPasswordLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);

        $user = CoreUserModel::query()
            ->where('email', $data['email'])
            ->whereNull('invited_at')
            ->whereNull('suspended_at')
            ->whereHas('roles', fn ($query) => $query->whereNotIn('name', SnkKilkanaRoleCatalogService::WITHOUT_SEAT))
            ->first();

        if ($user !== null) {
            try {
                app(SnkKilkanaAccountLinkService::class)->send($user, SnkKilkanaAccountLinkService::RESET, $request, queued: true);
            } catch (\Throwable $fallo) {
                report($fallo);
            }
        }

        return response()->json(['ok' => true], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'token' => 'required|string|max:255',
            'password' => 'required|string|min:10|max:255|confirmed',
        ]);

        $user = CoreUserModel::query()->where('email', $data['email'])->first();
        if ($user === null || ! Password::broker()->tokenExists($user, $data['token'])) {
            return response()->json(['error' => 'Invalid or expired link'], 422);
        }

        $user->forceFill(['password' => $data['password'], 'invited_at' => null])->save();
        $user->tokens()->delete();
        Password::broker()->deleteToken($user);

        return response()->json(['email' => $user->email]);
    }
}
