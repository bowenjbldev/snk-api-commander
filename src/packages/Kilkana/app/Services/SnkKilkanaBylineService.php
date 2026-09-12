<?php

namespace Packages\Kilkana\Services;

use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;

class SnkKilkanaBylineService
{
    /** Toda cuenta que puede crear piezas tiene firma; si no la tiene, se le crea. */
    public function ensureForUser(int $userId): void
    {
        // Lo barato primero: el armazón pregunta en cada navegación, y la respuesta casi siempre
        // es «ya tiene». Un SELECT por índice antes de cargar la cuenta y sus permisos.
        if (SnkKilkanaAuthorModel::query()->where('user_id', $userId)->exists()) {
            return;
        }

        $user = CoreUserModel::query()->find($userId);
        if ($user === null || ! $user->getAllPermissions()->pluck('name')->contains('create posts')) {
            return;
        }

        SnkKilkanaAuthorModel::firstOrCreate(['user_id' => $user->id]);
    }
}
