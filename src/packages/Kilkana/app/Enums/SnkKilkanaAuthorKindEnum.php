<?php

namespace Packages\Kilkana\Enums;

use Packages\Kilkana\Enums\Concerns\SnkKilkanaEnumValuesSupport;

/**
 * Que clase de firma es, que no es lo mismo que quien la escribe.
 *
 * Una firma es una PAGINA PUBLICA y un asiento es acceso al panel, y el periodico tiene las
 * dos cosas por separado: agencias y colectivos publican sin entrar nunca, y hay cuentas que
 * no firman. Sin esta columna el panel no podia contestar la pregunta que trae a alguien a la
 * pantalla de firmas —«que se publica aqui sin firma de persona»— y las cuatro clases se
 * distinguian por convencion en el nombre.
 */
enum SnkKilkanaAuthorKindEnum: string
{
    use SnkKilkanaEnumValuesSupport;

    /** Plantilla: firma y entra al panel. */
    case Staff = 'staff';

    /** La casa firmando en grupo: «Redaccion». Una cuenta de servicio, o ninguna. */
    case Collective = 'collective';

    /** Firma de fuera con nombre propio. Puede tener asiento o no tenerlo. */
    case Contributor = 'contributor';

    /** Agencia. Publica y no entra: es el caso que obliga a que la cuenta sea opcional. */
    case Agency = 'agency';

    /**
     * Si la firma es una persona de la casa.
     *
     * Lo usa quien tiene que decidir si esperar una cuenta detras: una agencia sin asiento es
     * lo normal, y un `staff` sin asiento es una ficha a medio hacer.
     */
    public function isStaff(): bool
    {
        return $this === self::Staff;
    }
}
