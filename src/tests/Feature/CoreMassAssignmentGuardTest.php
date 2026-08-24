<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use ReflectionClass;
use Tests\TestCase;

/**
 * Ningun `PUT` de la API puede escribir columnas que nadie ha declarado.
 *
 * `CoreBaseApiController::update()` hace `$data = $request->all()` y solo lo reduce a
 * `$validator->validated()` **si hay reglas**. Los modelos de Kilkana llevan `$guarded = []`, asi
 * que un controlador montado con `apiResource` y sin reglas de actualizacion deja reescribir
 * cualquier columna de la fila: la clave foranea de un comentario, su autoria, su fecha.
 *
 * Hoy no hay ninguno —los seis que no declaran reglas no tienen ruta de `update`—, y por eso esto
 * es una puerta y no un arreglo: lo que falla no es el codigo de ahora, es el **siguiente**
 * controlador que nazca asi, que hoy no lo cantaria nada. Es la misma trampa que ya mordio una vez
 * en el alta de medios, donde dejaba inventar `disk` y `path` —los dos campos con los que se borra
 * un fichero del disco—.
 *
 * Se arregla de una de estas tres formas, y la prueba acepta las tres porque las tres cierran el
 * agujero: declarar `$validationRulesUpdate`, apuntar un `$updateRequestClass`, o escribir el
 * `update()` del controlador. Quitar la ruta tambien vale, y entonces no aparece aqui.
 */
class CoreMassAssignmentGuardTest extends TestCase
{
    public function test_toda_ruta_de_actualizacion_declara_que_acepta(): void
    {
        $sinDeclarar = [];

        foreach (Route::getRoutes() as $ruta) {
            if (! array_intersect(['PUT', 'PATCH'], $ruta->methods())) {
                continue;
            }

            $accion = $ruta->getActionName();
            if (! str_contains($accion, '@')) {
                continue;
            }

            [$clase, $metodo] = explode('@', $accion);
            if (! class_exists($clase) || ! is_subclass_of($clase, CoreBaseApiController::class)) {
                continue;
            }

            $espejo = new ReflectionClass($clase);

            // Un `update()` propio decide el solo lo que acepta: no pasa por la base.
            if ($espejo->getMethod($metodo)->getDeclaringClass()->getName() !== CoreBaseApiController::class) {
                continue;
            }

            // Las reglas pueden ponerse en el constructor —`rule()` es una llamada y una
            // propiedad no se inicializa con eso—, asi que se mira la INSTANCIA, no la clase.
            $controlador = app($clase);
            $reglas = $this->propiedad($espejo, $controlador, 'validationRulesUpdate');
            $formRequest = $this->propiedad($espejo, $controlador, 'updateRequestClass');

            if ($reglas === [] && $formRequest === null) {
                $sinDeclarar[] = $ruta->uri().' → '.class_basename($clase);
            }
        }

        $this->assertSame([], $sinDeclarar, implode("\n", [
            'Estas rutas escriben lo que llegue en la peticion, columna a columna:',
            ...$sinDeclarar,
            'Declara `$validationRulesUpdate`, un `$updateRequestClass`, o escribe su `update()`.',
        ]));
    }

    private function propiedad(ReflectionClass $espejo, object $instancia, string $nombre): mixed
    {
        while (! $espejo->hasProperty($nombre)) {
            $espejo = $espejo->getParentClass();
            if ($espejo === false) {
                return null;
            }
        }

        $propiedad = $espejo->getProperty($nombre);
        $propiedad->setAccessible(true);

        return $propiedad->isInitialized($instancia) ? $propiedad->getValue($instancia) : null;
    }
}
