<?php

namespace Packages\Kilkana\Services;

use Illuminate\Support\Facades\DB;
use Packages\Kilkana\Models\SnkKilkanaLayoutModel;
use Packages\Kilkana\Models\SnkKilkanaLayoutRevisionModel;

class SnkKilkanaLayoutService
{
    private const DEFAULT_SCHEMA = ['version' => 1, 'sections' => []];

    public function get(string $layout): SnkKilkanaLayoutModel
    {
        return SnkKilkanaLayoutModel::firstOrCreate(
            ['layout' => $layout],
            ['schema' => self::DEFAULT_SCHEMA, 'version' => 1],
        );
    }

    /**
     * Guarda si nadie se ha adelantado, y si se adelantó devuelve conflicto.
     *
     * La comprobación de version y la escritura van bajo el MISMO bloqueo de fila. Comprobar
     * fuera de la transacción no sirve de nada: dos personas guardando la portada a la vez
     * leían la version 3, las dos pasaban la comprobación y las dos escribían la 4 — la
     * segunda pisaba a la primera, las dos recibían 200, y quien perdió su trabajo no se
     * enteraba. Medido: de seis guardados simultáneos declarando la misma version, DOS
     * respondían 200. Con el bloqueo, el segundo espera, relee la version ya incrementada y
     * se lleva su 409.
     *
     * `get()` va antes y fuera a propósito: crea la fila si es la primera vez, y no se puede
     * bloquear una fila que todavía no existe.
     *
     * @return array{row: SnkKilkanaLayoutModel, conflict: bool}
     */
    public function update(string $layout, array $schema, int $version, ?int $userId): array
    {
        $this->get($layout);

        return DB::transaction(function () use ($layout, $schema, $version, $userId) {
            $row = $this->locked($layout);

            if ($version !== (int) $row->version) {
                return ['row' => $row, 'conflict' => true];
            }

            // La instantanea y el guardado son lo mismo: una portada guardada sin su copia de
            // seguridad deja el historial con un salto, y es justo el historial lo que permite
            // deshacer una composicion que rompio la portada.
            $this->snapshot($row, $userId);
            $row->schema = $schema;
            $row->version = $row->version + 1;
            $row->save();

            return ['row' => $row, 'conflict' => false];
        });
    }

    public function revisions(string $layout)
    {
        $layoutId = SnkKilkanaLayoutModel::query()->where('layout', $layout)->value('id');

        return SnkKilkanaLayoutRevisionModel::query()
            ->where('layout_id', $layoutId ?? 0)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get();
    }

    /** Restaurar también bloquea: sube la version, así que compite con un guardado normal. */
    public function restore(string $layout, $revisionId, ?int $userId): ?SnkKilkanaLayoutModel
    {
        return DB::transaction(function () use ($layout, $revisionId, $userId) {
            $row = $this->locked($layout);
            $revision = $row
                ? SnkKilkanaLayoutRevisionModel::query()
                    ->whereKey($revisionId)
                    ->where('layout_id', $row->id)
                    ->first()
                : null;
            if (! $row || ! $revision) {
                return null;
            }

            $this->snapshot($row, $userId);
            $row->schema = $revision->schema;
            $row->version = $row->version + 1;
            $row->save();

            return $row;
        });
    }

    /** La fila con el bloqueo puesto hasta que la transacción confirme. */
    private function locked(string $layout): ?SnkKilkanaLayoutModel
    {
        return SnkKilkanaLayoutModel::query()
            ->where('layout', $layout)
            ->lockForUpdate()
            ->first();
    }

    public function payload(SnkKilkanaLayoutModel $row): array
    {
        return [
            'layout' => $row->layout,
            'schema' => $row->schema,
            'version' => $row->version,
            'updated_at' => $row->updated_at,
        ];
    }

    private function snapshot(SnkKilkanaLayoutModel $row, ?int $userId): void
    {
        SnkKilkanaLayoutRevisionModel::create([
            'layout_id' => $row->id,
            'user_id' => $userId,
            'schema' => $row->schema,
        ]);
    }
}
