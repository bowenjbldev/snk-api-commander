<?php

namespace Packages\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Packages\Kilkana\Services\SnkKilkanaPanelService;
use Packages\Kilkana\Services\SnkKilkanaPostService;

/**
 * Las dos pantallas del panel que se piden enteras. Solo lectura, y el CRUD por recurso sigue
 * siendo el contrato general: esto es un atajo para dos pantallas medidas, no un sustituto.
 *
 * El controlador solo compone y valida la frontera; lo que hay dentro es del servicio.
 */
#[Group('Panel')]
class SnkKilkanaPanelController extends Controller
{
    public function __construct(private readonly SnkKilkanaPanelService $panel) {}

    /** El armazón: los tres catálogos y las seis cifras del raíl, en una llamada y no en nueve. */
    public function bootstrap(): JsonResponse
    {
        return response()->json($this->panel->bootstrap());
    }

    /**
     * El tablero, en una llamada y no en cinco.
     *
     * `reads` es la ventana de «lo más leído», y se lee como en el listado editorial: solo
     * siete o treinta, y cualquier otra cosa cae a «desde siempre». Una ventana libre invita a
     * pedir mil días y a pagar la subconsulta entera sin que nadie mire el resultado.
     */
    public function dashboard(): JsonResponse
    {
        return response()->json($this->panel->dashboard(SnkKilkanaPostService::readsWindow(request()->query('reads'))));
    }

    /**
     * En que idioma quiere el panel quien esta dentro.
     *
     * Sin permiso: cada cuenta cambia el suyo y solo el suyo, asi que la sesion ya es toda la
     * autorizacion que hace falta. `null` lo devuelve a «lo que diga el navegador», que no es lo
     * mismo que elegir el idioma por defecto.
     */
    public function locale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['present', 'nullable', Rule::in(SnkKilkanaPanelService::PANEL_LOCALES)],
        ]);

        $user = $request->user();
        $user->panel_locale = $data['locale'];
        $user->save();

        return response()->json(['locale' => $user->panel_locale]);
    }
}
