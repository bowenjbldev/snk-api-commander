<?php

namespace Packages\Kilkana\Services;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Models\SnkKilkanaAuthorModel;

class SnkKilkanaBylineService
{
    /** true cuando la cuenta necesita firma pero no se pudo crear automáticamente. */
    public function ensureForUser(int $userId): bool
    {
        $user = CoreUserModel::query()->find($userId);
        if ($user === null || ! $user->getAllPermissions()->pluck('name')->contains('create posts')) {
            return false;
        }

        try {
            SnkKilkanaAuthorModel::firstOrCreate(['user_id' => $user->id]);

            return false;
        } catch (ValidationException|QueryException) {
            return true;
        }
    }
}
