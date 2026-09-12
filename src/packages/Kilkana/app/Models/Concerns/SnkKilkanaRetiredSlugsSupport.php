<?php

namespace Packages\Kilkana\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Renombrar una entidad pública sin romper lo que ya está enlazado.
 *
 * Lo comparten las entidades cuya ruta pública ES su slug —firmas y secciones—. Los
 * artículos NO lo usan: su ruta son cuatro segmentos con la fecha sellada delante y sus
 * reglas son otras (desempate por día, tercer salto de reparación). Forzarlos aquí sería
 * abstraer dos cosas que solo se parecen por fuera.
 *
 * Lo que aporta son las dos mitades del mismo hecho:
 *
 *   guardar   la ruta que se deja entra en el historial; la que se toma sale, si era suya
 *   proteger  no se puede tomar una ruta que otro tiene viva NI retirada
 *
 * La segunda es la que no es evidente: una URL retirada sigue redirigiendo, así que no está
 * libre. Sin ella, renombrar encima de la redirección de otro la borraría y sus lectores
 * acabarían en la entidad equivocada, con un 200 y sin ninguna señal.
 */
trait SnkKilkanaRetiredSlugsSupport
{
    /** Las rutas que esta entidad ha dejado atrás. */
    abstract public function retiredSlugs(): HasMany;

    /** El modelo de esa tabla, para poder preguntar por rutas de OTROS. */
    abstract public static function retiredSlugModel(): string;

    /** La columna que apunta al dueño dentro de esa tabla. */
    abstract public static function retiredSlugOwnerKey(): string;

    /**
     * Ocupada si la tiene otra entidad viva o si la conserva el historial de otra.
     *
     * `$ignoreId` deja fuera a la propia: su URL vieja no puede bloquearle, o revertir un
     * renombrado sería imposible.
     */
    public static function slugTaken(string $slug, ?int $ignoreId = null): bool
    {
        $takenByLive = static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($takenByLive) {
            return true;
        }

        $retiredSlugModel = static::retiredSlugModel();

        return $retiredSlugModel::query()
            ->where('path', $slug)
            ->when($ignoreId, fn ($query) => $query->where(static::retiredSlugOwnerKey(), '!=', $ignoreId))
            ->exists();
    }

    protected static function bootSnkKilkanaRetiredSlugsSupport(): void
    {
        static::updating(function (Model $model) {
            if ($model->isDirty('slug') && $model::slugTaken((string) $model->slug, $model->getKey())) {
                throw ValidationException::withMessages([
                    'slug' => 'That public URL is already in use, now or by a redirect; choose another.',
                ]);
            }
        });

        static::updated(function (Model $model) {
            if (! $model->wasChanged('slug')) {
                return;
            }

            // Dentro de `updated` el original todavía no se ha sincronizado: es el previo al cambio.
            $previousSlug = $model->getOriginal('slug');
            if ($previousSlug) {
                $model->retiredSlugs()->updateOrCreate(['path' => $previousSlug]);
            }

            // Y la que acaba de adoptar deja de ser redirección, o apuntaría a sí misma.
            // Acotado a las suyas: la de otro no se toca aunque exista la fila.
            $model->retiredSlugs()->where('path', $model->slug)->delete();
        });
    }
}
