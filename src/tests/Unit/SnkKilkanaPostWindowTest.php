<?php

namespace Tests\Unit;

use Packages\Kilkana\Services\SnkKilkanaPostService;
use PHPUnit\Framework\TestCase;

/**
 * La ventana de «lo más leído» y el vocabulario de `sort`: dos listas cerradas que deciden
 * cuánto cuesta una consulta, y que **hasta ahora no fijaba nada**.
 *
 * La ventana la preguntan dos puertas —el listado editorial y el tablero—, y lo que la protege
 * es que solo admita siete y treinta: una ventana libre invita a pedir mil días y a pagar la
 * subconsulta entera de audiencia sin que nadie mire el resultado. Lo que no sea uno de los dos
 * es «desde siempre», no un 422, que es la misma regla que el resto de la query.
 *
 * El vocabulario estaba escrito dos veces con distinta forma —las claves en el controlador para
 * validar, el mapa en el servicio para ordenar—, así que un orden nuevo había que darlo de alta
 * en los dos sitios o quedaba aceptado y sin efecto. Aquí se fija que son la misma lista.
 */
class SnkKilkanaPostWindowTest extends TestCase
{
    public function test_the_reads_window_only_allows_seven_and_thirty(): void
    {
        $this->assertSame(7, SnkKilkanaPostService::readsWindow('7'));
        $this->assertSame(30, SnkKilkanaPostService::readsWindow(30));

        foreach (['1000', 'banana', '0', '', null, '-7'] as $requested) {
            $this->assertNull(
                SnkKilkanaPostService::readsWindow($requested),
                'Lo que no es una de las dos ventanas es «desde siempre»: '.var_export($requested, true),
            );
        }
    }

    public function test_the_sort_map_keys_are_the_sort_vocabulary(): void
    {
        $this->assertSame(['date', 'title', 'reads'], array_keys(SnkKilkanaPostService::SORTS));
        $this->assertSame('views_total', SnkKilkanaPostService::SORTS['reads'], 'El alias del `withSum`.');
    }
}
