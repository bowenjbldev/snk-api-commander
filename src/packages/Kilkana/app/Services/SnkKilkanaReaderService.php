<?php

namespace Packages\Kilkana\Services;

use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;

/**
 * Lo que se le hace a una cuenta lectora, y desde dónde se le hace da igual.
 *
 * Bloquear llega por dos puertas —la moderación, con un comentario delante, y la pantalla de
 * lectores, con la cuenta delante— y las dos tienen que hacer exactamente lo mismo: escrito
 * dos veces, un día una tira las sesiones y la otra no.
 */
class SnkKilkanaReaderService
{
    /**
     * Si la cuenta es SOLO lectora. Spatie admite varios roles, y quien además tiene un asiento
     * es un compañero aunque también comente: a ese no se le bloquea desde aquí. Falla cerrado.
     */
    public function isReader(?CoreUserModel $user): bool
    {
        $roles = $user?->roles->pluck('name') ?? collect();

        return $roles->contains('reader')
            && $roles->every(fn (string $rol) => in_array($rol, SnkKilkanaRoleCatalogService::WITHOUT_SEAT, true));
    }

    /**
     * Cierra la puerta: suspende la cuenta, tira sus sesiones y rechaza lo que tenía en la
     * cola. Devuelve cuántos comentarios rechazó. La cuenta y sus comentarios se quedan: es
     * reversible, y borrar se llevaría los hilos por delante.
     */
    public function block(CoreUserModel $user): int
    {
        $user->forceFill(['suspended_at' => $user->suspended_at ?? now()])->save();
        $user->tokens()->delete();

        return SnkKilkanaCommentModel::query()
            ->where('user_id', $user->getKey())
            ->where('status', SnkKilkanaCommentStatusEnum::Pending)
            ->update(['status' => SnkKilkanaCommentStatusEnum::Rejected]);
    }

    /** Vuelve a abrirla. Lo rechazado se queda rechazado: eso ya fue una decisión. */
    public function unblock(CoreUserModel $user): void
    {
        $user->forceFill(['suspended_at' => null])->save();
    }
}
