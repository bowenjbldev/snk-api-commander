<?php

namespace Packages\Kilkana\Services;

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
