<?php

namespace Packages\Core\Models;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * El token de Sanctum, con `last_used_at` escrito al minuto y no en cada petición.
 *
 * Sanctum marca `last_used_at` en CADA petición autenticada: un UPDATE en la base del inquilino
 * por cada GET del panel, que para InnoDB convierte la lectura en escritura con su fsync.
 * Medido: 0,3 ms por petición sin carga, y una escritura más por cada lectura bajo carga. Ese
 * sello solo se lee como «última actividad» de una cuenta —usuarios y lectores de Kilkana—, y
 * a esa pregunta le sobra el segundo: si el token ya quedó marcado hace menos de un minuto, la
 * marca de ahora no se escribe.
 */
class CorePersonalAccessTokenModel extends PersonalAccessToken
{
    /** Cada cuánto vale la pena volver a escribir `last_used_at`. */
    public const LAST_USED_RESOLUTION_SECONDS = 60;

    // La tabla de Sanctum: sin esto Eloquent la deduce del nombre de la clase.
    protected $table = 'personal_access_tokens';

    public function save(array $options = []): bool
    {
        $lastUsed = $this->getOriginal('last_used_at');

        if ($this->exists
            && $lastUsed !== null
            && array_keys($this->getDirty()) === ['last_used_at']
            && $lastUsed->gt(now()->subSeconds(self::LAST_USED_RESOLUTION_SECONDS))) {
            $this->discardChanges();

            return true;
        }

        return parent::save($options);
    }
}
