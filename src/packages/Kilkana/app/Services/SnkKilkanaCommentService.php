<?php

namespace Packages\Kilkana\Services;

use Illuminate\Database\Eloquent\Collection;
use Packages\Kilkana\Enums\SnkKilkanaCommentStatusEnum;
use Packages\Kilkana\Models\SnkKilkanaCommentModel;

class SnkKilkanaCommentService
{
    /** Las acciones masivas que cambian de estado, por su verbo. Borrar va aparte. */
    public const STATUS_OF = [
        'approve' => SnkKilkanaCommentStatusEnum::Approved,
        'reject' => SnkKilkanaCommentStatusEnum::Rejected,
        'pending' => SnkKilkanaCommentStatusEnum::Pending,
        'spam' => SnkKilkanaCommentStatusEnum::Spam,
    ];

    /**
     * Cuántos hay de cada estado, y `all`. Vive aquí y no en el controlador porque lo
     * preguntan dos puertas —la pantalla de moderación y el raíl del panel— y una cifra
     * escrita dos veces acaba contando cosas distintas.
     *
     * @return array<string, int>
     */
    /**
     * Cuelga de la cuenta de cada comentario cuántos ha escrito y cuántos se le han rechazado.
     *
     * **Una consulta para la página entera, no una por fila.** Estaba en el controlador, junto
     * al mapeo de la fila; aquí es lo que es —una consulta— y la forma la declara
     * `SnkKilkanaCommentResource`.
     *
     * @param  Collection<int, SnkKilkanaCommentModel>  $comentarios
     * @return Collection<int, SnkKilkanaCommentModel>
     */
    public function withAuthorHistory(Collection $comentarios): Collection
    {
        $cuentas = $comentarios->pluck('user')->filter()->unique(fn ($u) => $u->getKey());
        if ($cuentas->isEmpty()) {
            return $comentarios;
        }

        $historial = [];
        SnkKilkanaCommentModel::query()
            ->whereIn('user_id', $cuentas->map->getKey()->all())
            ->selectRaw('user_id, status, count(*) as total')
            ->groupBy('user_id', 'status')
            ->get()
            ->each(function ($fila) use (&$historial) {
                $historial[$fila->user_id] ??= ['total' => 0, 'rejected' => 0];
                $historial[$fila->user_id]['total'] += (int) $fila->total;
                if ($fila->status === SnkKilkanaCommentStatusEnum::Rejected) {
                    $historial[$fila->user_id]['rejected'] += (int) $fila->total;
                }
            });

        $cuentas->each(function ($cuenta) use ($historial) {
            $cuenta->setAttribute('comments_total', $historial[$cuenta->getKey()]['total'] ?? 0);
            $cuenta->setAttribute('comments_rejected', $historial[$cuenta->getKey()]['rejected'] ?? 0);
        });

        return $comentarios;
    }

    public function countsByStatus(): array
    {
        $porEstado = SnkKilkanaCommentModel::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $counts = [];
        foreach (SnkKilkanaCommentStatusEnum::cases() as $caso) {
            $counts[$caso->value] = (int) $porEstado->get($caso->value, 0);
        }
        $counts['all'] = array_sum($counts);

        return $counts;
    }

    /** Desde cuándo espera el más viejo de la cola: es lo que mide si la moderación va tarde. */
    public function oldestPendingAt(): ?string
    {
        return SnkKilkanaCommentModel::query()
            ->where('status', SnkKilkanaCommentStatusEnum::Pending)
            ->min('created_at');
    }

    public function create(array $data, int $userId): ?SnkKilkanaCommentModel
    {
        if (! empty($data['parent_id'])) {
            $parentPost = SnkKilkanaCommentModel::query()->whereKey($data['parent_id'])->value('post_id');
            if ((int) $parentPost !== (int) $data['post_id']) {
                return null;
            }
        }

        return SnkKilkanaCommentModel::create([
            ...$data,
            'user_id' => $userId,
            'status' => SnkKilkanaCommentStatusEnum::Pending,
        ]);
    }

    public function bulk(array $ids, string $action): int
    {
        $query = SnkKilkanaCommentModel::query()->whereIn('id', $ids);

        if ($action === 'delete') {
            $count = $query->count();
            $query->delete();

            return $count;
        }

        $estado = self::STATUS_OF[$action] ?? SnkKilkanaCommentStatusEnum::from($action);

        return $query->update(['status' => $estado]);
    }
}
