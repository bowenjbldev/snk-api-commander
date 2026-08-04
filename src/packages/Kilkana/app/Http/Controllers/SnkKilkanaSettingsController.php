<?php

namespace Packages\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Kilkana\Services\SnkKilkanaSettingService;

/**
 * Ajustes del workspace (singleton): variante de tema, identidad del periódico (tenant) y flag
 * de onboarding. Se acepta y se expone con `tenant` anidado, y el guardado es parcial: lo que
 * no viene se queda donde estaba.
 *
 * El tema ya no es una cadena con retoques en un JSON: es una clave foránea a
 * `kilkana_theme_variants`, así que la base rechaza vestir una variante que no existe. Los
 * colores y la densidad se editan por su propio CRUD (`theme-variants`), que es donde vive la
 * validación de rangos.
 */
#[Group('Settings')]
class SnkKilkanaSettingsController extends Controller
{
    public function __construct(private readonly SnkKilkanaSettingService $settings) {}

    public function show(): JsonResponse
    {
        return response()->json($this->settings->payload($this->settings->get()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            // `exists` y no solo un entero: la clave foránea ya lo impediría, pero devolvería
            // un error de motor donde toca un 422 sobre el campo.
            'theme_variant_id' => 'nullable|integer|exists:kilkana_theme_variants,id',
            'tenant' => 'nullable|array',
            'tenant.name' => 'nullable|string|max:255',
            'tenant.tagline' => 'nullable|string|max:255',
            'tenant.city' => 'nullable|string|max:255',
            // `url:http,https` y no `url` a secas: la dirección del periódico se pinta como
            // enlace y sale en cada correo, así que `javascript:` aquí es un agujero, igual
            // que lo era en la web de una firma.
            'tenant.url' => 'nullable|url:http,https|max:255',
            'onboarded' => 'nullable|boolean',
        ]);

        return response()->json($this->settings->payload($this->settings->update($data)));
    }
}
