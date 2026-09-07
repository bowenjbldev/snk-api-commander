<?php

namespace Packages\Kilkana\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;
use Packages\Kilkana\Models\SnkKilkanaReaderReadModel;
use Packages\Kilkana\Models\SnkKilkanaSubscriberModel;

/**
 * Lo que se le hace a una cuenta lectora, y desde dónde se le hace da igual.
 *
 * Bloquear llega por dos puertas —la moderación, con un comentario delante, y la pantalla de
 * lectores, con la cuenta delante— y las dos tienen que hacer exactamente lo mismo: escrito
 * dos veces, un día una tira las sesiones y la otra no.
 */
class SnkKilkanaReaderService
{
    public function __construct(private readonly SnkKilkanaActivityService $registro) {}

    /**
     * El conjunto de las cuentas lectoras, como consulta.
     *
     * Es la misma pregunta que `isReader()` hace sobre una cuenta ya cargada, escrita en SQL
     * para poder listarlas y contarlas. Vive aquí porque la hacen dos puertas —la pantalla de
     * lectores y el raíl del panel— y quien la escriba de nuevo acabará contando también a la
     * redacción.
     */
    public function accounts(): Builder
    {
        return CoreUserModel::query()
            ->whereHas('roles', fn (Builder $r) => $r->where('name', 'reader'))
            ->whereDoesntHave('roles', fn (Builder $r) => $r->whereNotIn('name', SnkKilkanaRoleCatalogService::WITHOUT_SEAT));
    }

    /**
     * Si la cuenta es SOLO lectora. Spatie admite varios roles, y quien además tiene un asiento
     * es un compañero aunque también comente: a ese no se le bloquea desde aquí. Falla cerrado.
     */
    /**
     * Cuelga de cada cuenta lo que la pantalla de lectores enseña de ella: comentarios,
     * lecturas del mes y su estado en el boletín.
     *
     * **Tres consultas para toda la página, no tres por fila.** Es la mitad de lo que hacía
     * `asRows()` en el controlador; la otra mitad —la forma de la fila— la declara
     * `SnkKilkanaReaderResource`. Estaban pegadas en un método de 63 líneas, y por eso ni una
     * de las dos se podía probar sin la otra.
     *
     * Va como atributos y no como un array aparte para que el Resource las lea con `whenHas()`,
     * igual que lee un `withCount`: para él no hay diferencia entre un agregado de la consulta
     * y uno calculado en dos pasos.
     *
     * @param  Collection<int, CoreUserModel>  $users
     * @return Collection<int, CoreUserModel>
     */
    public function withActivity(Collection $users): Collection
    {
        $ids = $users->modelKeys();
        if ($ids === []) {
            return $users;
        }

        $comentarios = [];
        $ultimo = [];
        SnkKilkanaCommentModel::query()
            ->whereIn('user_id', $ids)
            ->selectRaw('user_id, status, count(*) as total, max(created_at) as last_at')
            ->groupBy('user_id', 'status')
            ->get()
            ->each(function ($fila) use (&$comentarios, &$ultimo) {
                $comentarios[$fila->user_id] ??= ['total' => 0, 'rejected' => 0];
                $comentarios[$fila->user_id]['total'] += (int) $fila->total;
                if ($fila->status === SnkKilkanaCommentStatusEnum::Rejected) {
                    $comentarios[$fila->user_id]['rejected'] += (int) $fila->total;
                }
                $ultimo[$fila->user_id] = max($ultimo[$fila->user_id] ?? '', (string) $fila->last_at);
            });

        $lecturas = SnkKilkanaReaderReadModel::query()
            ->whereIn('user_id', $ids)
            ->inMonth()
            ->selectRaw('user_id, count(*) as total, max(read_at) as last_at')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $boletin = SnkKilkanaSubscriberModel::query()
            ->whereIn('email', $users->pluck('email'))
            ->get(['email', 'status', 'consent_source', 'consent_at'])
            ->keyBy('email');

        return $users->each(function (CoreUserModel $user) use ($comentarios, $ultimo, $lecturas, $boletin) {
            $id = $user->getKey();
            $lectura = $lecturas->get($id);

            $user->setAttribute('comments_total', $comentarios[$id]['total'] ?? 0);
            $user->setAttribute('comments_rejected', $comentarios[$id]['rejected'] ?? 0);
            $user->setAttribute('reads_total', (int) ($lectura?->total ?? 0));
            $user->setAttribute('newsletter', $boletin->get($user->email));

            // Como fechas y no como cadenas: hoy las tres llegan de un agregado en
            // `Y-m-d H:i:s`, pero el día que una venga en ISO la `T` ordena por delante del
            // espacio y ganaría la más vieja.
            $user->setAttribute('last_seen_at', collect([
                $user->getAttribute('last_token_at'),
                $ultimo[$id] ?? null,
                $lectura?->last_at,
            ])->filter()->map(fn ($v) => Carbon::parse($v))->max());
        });
    }

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
        $this->registro->log('comment', 'blocked the reader', $user->full_name ?: $user->name, '/readers');

        return SnkKilkanaCommentModel::query()
            ->where('user_id', $user->getKey())
            ->where('status', SnkKilkanaCommentStatusEnum::Pending)
            ->update(['status' => SnkKilkanaCommentStatusEnum::Rejected]);
    }

    /**
     * Retira a la persona y deja el rastro sin nombre: lo que el RGPD pide cuando alguien se va.
     *
     * Bloquea primero —sesiones fuera, pendientes rechazados— y después borra lo que identifica:
     * nombre, correo y contraseña. Los comentarios se quedan, que son parte de la conversación
     * de cada pieza, firmados por «Reader» y sin nadie detrás; y si ese correo estaba en el
     * boletín, se va de la lista. Cuenta como bloqueada para siempre, y no vuelve.
     */
    public function anonymize(CoreUserModel $user): void
    {
        $email = (string) $user->email;
        $this->block($user);
        $user->forceFill([
            'name' => 'Reader',
            'full_name' => null,
            'email' => 'retired-'.$user->getKey().'@anonymised.invalid',
            'password' => Str::random(40),
        ])->save();
        SnkKilkanaSubscriberModel::query()->where('email', $email)->delete();
        $this->registro->log('comment', 'removed the reader account', 'a reader', '/readers');
    }

    /** Vuelve a abrirla. Lo rechazado se queda rechazado: eso ya fue una decisión. */
    public function unblock(CoreUserModel $user): void
    {
        $user->forceFill(['suspended_at' => null])->save();
        $this->registro->log('comment', 'unblocked the reader', $user->full_name ?: $user->name, '/readers');
    }
}
