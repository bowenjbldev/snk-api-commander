<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Packages\Core\Http\Controllers\CoreBaseApiController;
use ReflectionClass;
use Tests\TestCase;

/**
 * Ningún `PUT` de la API puede escribir columnas que nadie ha declarado.
 *
 * `CoreBaseApiController::update()` hace `$data = $request->all()` y solo lo reduce a
 * `$validator->validated()` **si hay reglas**. Los modelos de Kilkana llevan `$guarded = []`, así
 * que un controlador montado con `apiResource` y sin reglas de actualización deja reescribir
 * cualquier columna de la fila: la clave foránea de un comentario, su autoría, su fecha.
 *
 * Por eso esto es una puerta y no un arreglo: lo que caza es el **siguiente** controlador que se
 * monte así, que sin ella no lo canta nada. En el alta de medios esa misma trampa deja inventar
 * `disk` y `path`, los dos campos con los que se borra un fichero del disco.
 *
 * Se arregla de una de estas dos formas, y la prueba acepta las dos porque las dos cierran el
 * agujero: declarar `$validationRulesUpdate` o escribir el `update()` del controlador. Quitar la
 * ruta también vale, y entonces no aparece aquí.
 */
class CoreMassAssignmentGuardTest extends TestCase
{
    public function test_every_update_route_declares_what_it_accepts(): void
    {
        $undeclared = [];

        foreach (Route::getRoutes() as $route) {
            if (! array_intersect(['PUT', 'PATCH'], $route->methods())) {
                continue;
            }

            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action);
            if (! class_exists($class) || ! is_subclass_of($class, CoreBaseApiController::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            // Un `update()` propio decide él solo lo que acepta: no pasa por la base.
            if ($reflection->getMethod($method)->getDeclaringClass()->getName() !== CoreBaseApiController::class) {
                continue;
            }

            // Las reglas pueden ponerse en el constructor —`rule()` es una llamada y una
            // propiedad no se inicializa con eso—, así que se mira la INSTANCIA, no la clase.
            $controller = app($class);
            $rules = $this->property($reflection, $controller, 'validationRulesUpdate');

            if ($rules === []) {
                $undeclared[] = $route->uri().' → '.class_basename($class);
            }
        }

        $this->assertSame([], $undeclared, implode("\n", [
            'Estas rutas escriben lo que llegue en la petición, columna a columna:',
            ...$undeclared,
            'Declara `$validationRulesUpdate` o escribe su `update()`.',
        ]));
    }

    private function property(ReflectionClass $reflection, object $instance, string $name): mixed
    {
        while (! $reflection->hasProperty($name)) {
            $reflection = $reflection->getParentClass();
            if ($reflection === false) {
                return null;
            }
        }

        $property = $reflection->getProperty($name);
        $property->setAccessible(true);

        return $property->isInitialized($instance) ? $property->getValue($instance) : null;
    }
}
