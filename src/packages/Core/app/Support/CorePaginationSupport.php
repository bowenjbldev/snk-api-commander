<?php

namespace Packages\Core\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * El sobre de una respuesta paginada, escrito una sola vez.
 *
 * Estas cuatro claves son contrato: el panel lee `last_page` para saber si pintar el
 * paginador y `total` para la cifra de la cabecera. Estaban copiadas literalmente en seis
 * sitios —cinco controladores de Kilkana y el tablero—, así que añadir una quinta clave
 * significaba tocar seis ficheros o dejar dos listados contestando distinto.
 *
 * Vive aquí y no en `CoreBaseApiController` porque **dos de las copias no están en un
 * controlador**: `SnkKilkanaPanelService` compone las cuatro colas del tablero y
 * `SnkKilkanaMediaService` su mediateca. Un servicio no hereda del base ni debe.
 */
final class CorePaginationSupport
{
    /** @return array{current_page: int, per_page: int, total: int, last_page: int} */
    public static function meta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ];
    }
}
