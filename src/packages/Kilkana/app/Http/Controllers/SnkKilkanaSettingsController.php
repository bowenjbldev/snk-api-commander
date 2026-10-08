<?php

namespace Packages\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Services\SnkKilkanaSettingService;
use RuntimeException;

/**
 * Ajustes del workspace (singleton): el tema que viste el periódico y lo que ha movido encima,
 * su identidad (tenant) y la fecha en que abrió al público. Se acepta y se expone con `tenant` anidado, y
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
        return response()->json($this->settings->payload($this->settings->get()) + $this->settings->facts());
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
            // que en la web de una firma.
            'tenant.url' => 'nullable|url:http,https|max:255',
            // Solo hacia delante: `true` sella `launched_at` si no lo estaba, y `false` no hace nada.
            'launched' => 'sometimes|boolean',
            // Por dónde va el asistente. Solo estas tres claves: lo demás se cuenta de lo que hay.
            'onboarding' => 'sometimes|array',
            'onboarding.step' => ['sometimes', 'string', Rule::in(SnkKilkanaSettingModel::ONBOARDING_STEPS)],
            // La clase de diario: el slug de una categoría de temas, que es catálogo y cambia.
            'onboarding.kind' => 'sometimes|nullable|string|max:40',
            'onboarding.declined' => 'sometimes|boolean',
            'lang' => ['sometimes', 'string', Rule::in(SnkKilkanaSettingModel::LANGS)],
            'tz' => 'sometimes|string|timezone:all',
            'search_visible' => 'sometimes|boolean',
            'contextual_spellcheck' => 'sometimes|boolean',
            'newsletter_reply_to' => 'nullable|email|max:255',
            'legal' => 'nullable|array',
            'legal.name' => 'nullable|string|max:255',
            'legal.email' => 'nullable|email|max:255',
            'social' => 'nullable|array',
            'social.x' => 'nullable|url:http,https|max:255',
            'social.facebook' => 'nullable|url:http,https|max:255',
            'social.instagram' => 'nullable|url:http,https|max:255',
        ]);

        return response()->json($this->settings->payload($this->settings->update($data)) + $this->settings->facts());
    }

    /** Volver a empezar la puesta en marcha: archivo, secciones y etiquetas fuera. No en producción. */
    public function reset(): JsonResponse
    {
        try {
            $row = $this->settings->resetSetup();
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return response()->json($this->settings->payload($row) + $this->settings->facts());
    }
}
