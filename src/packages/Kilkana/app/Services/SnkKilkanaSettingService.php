<?php

namespace Packages\Kilkana\Services;

use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaThemeModel;

/**
 * Los ajustes del periódico: una sola fila, con columnas de verdad.
 *
 * Fue una bolsa JSON, y la bolsa cobraba el precio de la flexibilidad sin darla: el
 * controlador ya enumera cada clave que acepta, así que nunca hubo claves arbitrarias. A
 * cambio se perdían los tipos —`onboarded` guardado como lo que fuera— y había que fusionar a
 * mano, con un caso especial para `tenant` que alguien tenía que acordarse de replicar en el
 * siguiente ajuste anidado. El día que se olvidara, una actualización parcial habría borrado
 * las claves hermanas en silencio.
 *
 * El JSON que ve la app conserva `tenant` anidado. Lo que cambia es que debajo hay columnas, y
 * el mapeo plano ↔ anidado está escrito aquí en vez de deducirse.
 *
 * Regla de crecimiento, para lo que venga: COLUMNA si el ajuste tiene tipo o clave foránea
 * (`logo_media_id`); COLUMNA JSON DE GRUPO si es un grupo abierto (`comments`, `paywall`,
 * `integrations`), normalizada al leer. Lo que no vuelve es la bolsa única con todo dentro.
 */
class SnkKilkanaSettingService
{
    public function __construct(private readonly SnkKilkanaThemeWearService $wear) {}

    private const SINGLETON_ID = 1;

    /**
     * Los campos de la identidad, planos en la base y anidados en la API.
     *
     * `url` es la dirección pública del periódico, y no está por completar la ficha: todo
     * correo que sale necesita enlazar a algún sitio, y el enlace de un boletín no puede ser
     * relativo. Sin ella, confirmar y darse de baja no tienen a dónde volver.
     */
    private const TENANT = ['name', 'tagline', 'city', 'url'];

    public function get(): SnkKilkanaSettingModel
    {
        return SnkKilkanaSettingModel::firstOrCreate(['id' => self::SINGLETON_ID]);
    }

    /**
     * Guarda lo que llegue y deja el resto donde estaba.
     *
     * Aquí hubo control de concurrencia optimista y se retiró: `version` era opcional en el
     * controlador y ningún cliente la mandaba, así que la comprobación no llegaba a ejecutarse
     * nunca y la respuesta devolvía un número que no garantizaba nada. Peor que no tenerlo,
     * porque parecía una garantía. Estos ajustes los toca poca gente y rara vez, así que la
     * política es last-write-wins y está dicha en voz alta.
     *
     * `kilkana_layouts` SÍ conserva la suya, y allí es obligatoria: dos personas componiendo la
     * portada a la vez es el caso normal, no la excepción.
     */
    public function update(array $data): SnkKilkanaSettingModel
    {
        $fila = $this->get();

        $fila->fill($this->columnsFrom($data));
        $fila->save();

        return $fila;
    }

    public function payload(SnkKilkanaSettingModel $fila): array
    {
        $fila->loadMissing('theme');

        return [
            'theme_id' => $fila->theme_id,
            // El tema resuelto viaja entero —spec incluido— para evitar una segunda llamada. Es
            // siempre la copia congelada del periódico con su retoque encima, no la fila del
            // catálogo: vestir un tema lo copia primero.
            'theme' => $fila->themePayload(),
            'theme_update_available' => $this->wear->updateAvailable($fila),
            'tenant' => [
                'name' => $fila->tenant_name,
                'tagline' => $fila->tenant_tagline,
                'city' => $fila->tenant_city,
                'url' => $fila->tenant_url,
            ],
            'onboarded' => (bool) $fila->onboarded,
        ];
    }

    /**
     * Del JSON anidado de la API a las columnas planas.
     *
     * Solo llega lo que venía: un guardado parcial toca sus campos y deja los demás donde
     * estaban. Eso antes exigía fusionar a mano; ahora es lo que hace `fill` por su cuenta.
     */
    private function columnsFrom(array $data): array
    {
        $columnas = collect($data)->only(['theme_overrides', 'onboarded'])->all();

        // Vestir es SIEMPRE copiar. Si llega un tema del catálogo se copia su spec aquí, porque
        // apuntar a la fila del catálogo dejaría al periódico a merced de la siguiente
        // sincronización — y cambiaría de aspecto solo. Va antes que el retoque: cambiar de tema
        // vacía lo movido, y mandar las dos cosas en la misma llamada tiene que dejar el retoque
        // nuevo puesto, no borrado.
        if (isset($data['theme_id'])) {
            $tema = SnkKilkanaThemeModel::query()->find($data['theme_id']);
            if ($tema) {
                $this->wear->wear($tema);
            }
        }

        $tenant = is_array($data['tenant'] ?? null) ? $data['tenant'] : [];
        foreach (self::TENANT as $campo) {
            if (array_key_exists($campo, $tenant)) {
                $columnas["tenant_{$campo}"] = $tenant[$campo];
            }
        }

        return $columnas;
    }
}
