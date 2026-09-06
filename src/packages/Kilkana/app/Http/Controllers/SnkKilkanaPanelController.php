<?php

namespace Packages\Kilkana\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Packages\Kilkana\Services\SnkKilkanaPanelService;

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

    /** El armazón: los tres catálogos y las seis cifras del raíl, donde antes iban nueve llamadas. */
    public function bootstrap(): JsonResponse
    {
        return response()->json($this->panel->bootstrap());
    }

    /**
     * El tablero, donde antes iban cinco.
     *
     * `reads` es la ventana de «lo más leído», y se lee como en el listado editorial: solo
     * siete o treinta, y cualquier otra cosa cae a «desde siempre». Una ventana libre invita a
     * pedir mil días y a pagar la subconsulta entera sin que nadie mire el resultado.
     */
    public function dashboard(): JsonResponse
    {
        $dias = (int) request()->query('reads', '0');

        return response()->json($this->panel->dashboard(in_array($dias, [7, 30], true) ? $dias : null));
    }
}
