<?php

namespace Packages\Core\Support;

use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * El registrar de Spatie con memoria por worker de la colección ya hidratada.
 *
 * Cada petición tenant llega con el registrar vacío —el resolutor lo vacía al conmutar, y así
 * debe seguir— y Spatie vuelve a levantar los 63 permisos y 7 roles de un periódico como modelos
 * Eloquent a partir de la caché. Medido: 0,6 ms por petición autenticada, frente a 0,08 de leer
 * la caché. Aquí se hidrata una vez por worker y por base, y en cada petición solo se lee la
 * caché y se compara con lo que hay hidratado: si es lo mismo, se sirve una copia; si no, se
 * vuelve a hidratar.
 *
 * **La clave de la memoria es la de la caché, y esa lleva el nombre de la base** —lo fija
 * `CorePackageSelectorSupport::permissionCacheKey()`—, así que dos inquilinos no pueden
 * compartir entrada. Y se compara el contenido, no solo la clave, para que
 * `forgetCachedPermissions()` siga mandando: editar un rol vacía la caché, la siguiente
 * lectura la reconstruye distinta y la memoria se descarta sola. Lo que sale es siempre una
 * copia de los modelos: nada que una petición haga con ellos vive más que la petición.
 *
 * Va en `octane.warm`: resuelto dentro de una petición nacería en su sandbox y la memoria
 * moriría con ella.
 */
class CorePermissionRegistrarSupport extends PermissionRegistrar
{
    // ponytail: un tope y fuera la más antigua; un worker sirve a pocos periódicos seguidos.
    private const MAX_DATABASES = 32;

    /** @var array<string, array{raw: array<string, mixed>, hydrated: Collection}> */
    private array $hydratedByKey = [];

    public function getPermissions(array $params = [], bool $onlyOne = false): Collection
    {
        if ($this->permissions === null) {
            $this->permissions = $this->hydrated();
        }

        return parent::getPermissions($params, $onlyOne);
    }

    private function hydrated(): Collection
    {
        $raw = $this->cache->get($this->cacheKey);
        $cachedEntry = $this->hydratedByKey[$this->cacheKey] ?? null;

        if ($raw === null || $cachedEntry === null || $cachedEntry['raw'] !== $raw) {
            // La carga de Spatie: lee la caché —o la reconstruye desde la base— e hidrata.
            parent::getPermissions();
            $cachedEntry = ['raw' => $raw ?? $this->cache->get($this->cacheKey), 'hydrated' => $this->permissions];

            unset($this->hydratedByKey[$this->cacheKey]);
            $this->hydratedByKey[$this->cacheKey] = $cachedEntry;
            if (count($this->hydratedByKey) > self::MAX_DATABASES) {
                array_shift($this->hydratedByKey);
            }
        }

        // El clone de Eloquent es superficial: los roles guardados y su colección no pueden salir de aquí.
        return $cachedEntry['hydrated']->map(fn ($permission) => (clone $permission)->setRelation(
            'roles', $permission->roles->map(fn ($role) => clone $role),
        ));
    }
}
