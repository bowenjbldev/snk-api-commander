<?php

namespace Packages\Kilkana\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Renombrar una entidad publica sin romper lo que ya esta enlazado.
 *
 * Lo comparten las entidades cuya ruta publica ES su slug —firmas y secciones—. Los
 * articulos NO lo usan: su ruta son cuatro segmentos con la fecha sellada delante y sus
 * reglas son otras (desempate por dia, tercer salto de reparacion). Forzarlos aqui seria
 * abstraer dos cosas que solo se parecen por fuera.
 *
 * Lo que aporta son las dos mitades del mismo hecho:
 *
 *   guardar   la ruta que se deja entra en el historial; la que se toma sale, si era suya
 *   proteger  no se puede tomar una ruta que otro tiene viva NI retirada
 *
 * La segunda es la que no es evidente: una URL retirada sigue redirigiendo, asi que no esta
 * libre. Sin ella, renombrar encima de la redireccion de otro la borraria y sus lectores
 * acabarian en la entidad equivocada, con un 200 y sin ninguna señal.
 */
trait SnkKilkanaRetiredSlugsSupport
{
    /** Las rutas que esta entidad ha dejado atras. */
    abstract public function retiredSlugs(): HasMany;

    /** El modelo de esa tabla, para poder preguntar por rutas de OTROS. */
    abstract public static function retiredSlugModel(): string;

    /** La columna que apunta al dueño dentro de esa tabla. */
    abstract public static function retiredSlugOwnerKey(): string;

    /**
     * Ocupada si la tiene otra entidad viva o si la conserva el historial de otra.
     *
     * `$ignoreId` deja fuera a la propia: su URL vieja no puede bloquearle, o revertir un
     * renombrado seria imposible.
     */
    public static function slugTaken(string $slug, ?int $ignoreId = null): bool
    {
        $viva = static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($viva) {
            return true;
        }

        $historial = static::retiredSlugModel();

        return $historial::query()
            ->where('path', $slug)
            ->when($ignoreId, fn ($query) => $query->where(static::retiredSlugOwnerKey(), '!=', $ignoreId))
            ->exists();
    }

    protected static function bootSnkKilkanaRetiredSlugsSupport(): void
    {
        static::updating(function (Model $entidad) {
            if ($entidad->isDirty('slug') && $entidad::slugTaken((string) $entidad->slug, $entidad->getKey())) {
                throw ValidationException::withMessages([
                    'slug' => 'That public URL is already in use, now or by a redirect; choose another.',
                ]);
            }
        });

        static::updated(function (Model $entidad) {
            if (! $entidad->wasChanged('slug')) {
                return;
            }

            // Dentro de `updated` el original todavia no se ha sincronizado: es el de antes.
            $anterior = $entidad->getOriginal('slug');
            if ($anterior) {
                $entidad->retiredSlugs()->updateOrCreate(['path' => $anterior]);
            }

            // Y la que acaba de adoptar deja de ser redireccion, o apuntaria a si misma.
            // Acotado a las suyas: la de otro no se toca ni existiendo dato viejo.
            $entidad->retiredSlugs()->where('path', $entidad->slug)->delete();
        });
    }
}
