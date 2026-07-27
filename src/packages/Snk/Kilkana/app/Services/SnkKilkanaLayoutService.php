<?php

namespace Packages\Snk\Kilkana\Services;

use Illuminate\Support\Facades\DB;
use Packages\Snk\Kilkana\Models\SnkKilkanaLayoutModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaLayoutRevisionModel;

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

    /** @return array{row: SnkKilkanaLayoutModel, conflict: bool} */
    public function update(string $layout, array $schema, int $version, ?int $userId): array
    {
        $row = $this->get($layout);
        if ($version !== (int) $row->version) {
            return ['row' => $row, 'conflict' => true];
        }

        // La instantanea y el guardado son lo mismo: una portada guardada sin su copia de
        // seguridad deja el historial con un salto, y es justo el historial lo que permite
        // deshacer una composicion que rompio la portada.
        DB::transaction(function () use ($row, $schema, $userId) {
            $this->snapshot($row, $userId);
            $row->schema = $schema;
            $row->version = $row->version + 1;
            $row->save();
        });

        return ['row' => $row, 'conflict' => false];
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

    public function restore(string $layout, $revisionId, ?int $userId): ?SnkKilkanaLayoutModel
    {
        $row = SnkKilkanaLayoutModel::query()->where('layout', $layout)->first();
        $revision = $row
            ? SnkKilkanaLayoutRevisionModel::query()
                ->whereKey($revisionId)
                ->where('layout_id', $row->id)
                ->first()
            : null;
        if (! $row || ! $revision) {
            return null;
        }

        DB::transaction(function () use ($row, $revision, $userId) {
            $this->snapshot($row, $userId);
            $row->schema = $revision->schema;
            $row->version = $row->version + 1;
            $row->save();
        });

        return $row;
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
