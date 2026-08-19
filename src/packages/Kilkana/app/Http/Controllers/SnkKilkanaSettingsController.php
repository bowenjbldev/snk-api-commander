<?php

namespace Packages\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Services\SnkKilkanaSettingService;

/**
 * Ajustes del workspace (singleton): el tema que viste el periódico y lo que ha movido encima,
 * su identidad (tenant) y el flag de onboarding. Se acepta y se expone con `tenant` anidado, y
 * el guardado es parcial: lo que no viene se queda donde estaba.
 *
 * **Aquí vive el vestido entero.** `theme_id` es una clave foránea, así que la base rechaza
 * vestir un tema que no existe; `theme_spec` es la copia congelada que se dibuja, y
 * `theme_overrides` los colores y la densidad que la redacción ajustó encima —con sus rangos
 * validados contra el mismo contrato que el catálogo—.
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
            // un error de motor donde toca un 422 sobre el campo. Mandarlo viste el tema, que
            // copia su spec — y por eso el retoque que venga en la misma llamada se aplica
            // después, encima del recién copiado.
            'theme_id' => 'nullable|integer|exists:kilkana_themes,id',
            // El retoque que la redacción pone encima del tema: los rangos salen del validador
            // de spec, que es quien define el contrato del catálogo.
            ...SnkKilkanaSettingModel::shapeRules(),
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
