<?php

namespace Packages\Snk\Kilkana\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaCollectionModel;
use Packages\Snk\Kilkana\Models\SnkKilkanaMediaTagModel;

class SnkKilkanaMediaTaxonomiesController
{
    public function index(Request $request): JsonResponse
    {
        if ($response = $this->permit('index media')) return $response;
        return response()->json($this->model($request)::query()->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->permit('update media')) return $response;
        $model = $this->model($request);
        $data = $request->validate(['name' => 'required|string|max:120|unique:'.(new $model)->getTable().',name']);
        return response()->json($model::create($data), 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        if ($response = $this->permit('update media')) return $response;
        $model = $this->model($request);
        $row = $model::find($id);
        if (!$row) return response()->json(['error' => 'Record not found'], 404);
        $data = $request->validate(['name' => 'required|string|max:120|unique:'.$row->getTable().',name,'.$row->id]);
        $row->update($data);
        return response()->json($row);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        if ($response = $this->permit('update media')) return $response;
        $model = $this->model($request);
        $row = $model::find($id);
        if (!$row) return response()->json(['error' => 'Record not found'], 404);
        $row->delete();
        return response()->json(['id' => (string) $id]);
    }

    private function model(Request $request): string
    {
        return $request->route('taxonomy') === 'tags' ? SnkKilkanaMediaTagModel::class : SnkKilkanaMediaCollectionModel::class;
    }

    private function permit(string $permission): ?JsonResponse
    {
        $user = auth()->user();
        if (!$user) return response()->json(['error' => 'Unauthorized'], 401);
        return $user->can($permission) ? null : response()->json(['error' => 'Forbidden'], 403);
    }
}
