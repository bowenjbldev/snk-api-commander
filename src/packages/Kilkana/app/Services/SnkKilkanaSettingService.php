<?php

namespace Packages\Kilkana\Services;

use Illuminate\Support\Facades\DB;
use Packages\Kilkana\Models\SnkKilkanaCategoryModel;
use Packages\Kilkana\Models\SnkKilkanaPostModel;
use Packages\Kilkana\Models\SnkKilkanaPostSlugModel;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;
use Packages\Kilkana\Models\SnkKilkanaTagModel;
use Packages\Kilkana\Models\SnkKilkanaThemeModel;
use RuntimeException;

/**
 * Los ajustes del periódico: una sola fila, con columnas de verdad.
 *
 * Columnas y no una bolsa JSON: el controlador enumera cada clave que acepta, así que no hay
 * claves arbitrarias que justifiquen la bolsa, y la bolsa cobra el precio de la flexibilidad
 * sin darla. Con columnas se conservan los tipos —`search_visible` es un booleano— y un guardado
 * parcial no fusiona a mano: no hay un caso especial para `tenant` que replicar en el siguiente
 * ajuste anidado, ni una actualización parcial que borre las claves hermanas en silencio.
 *
 * El JSON que ve la app conserva `tenant` anidado. Debajo hay columnas, y el mapeo
 * plano ↔ anidado está escrito aquí en vez de deducirse.
 *
 * Regla de crecimiento, para lo que venga: COLUMNA si el ajuste tiene tipo o clave foránea
 * (`logo_media_id`); COLUMNA JSON DE GRUPO si es un grupo abierto (`comments`, `paywall`,
 * `integrations`), normalizada al leer. Nunca una bolsa única con todo dentro.
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
     * Sin control de concurrencia optimista, a propósito: una `version` opcional que ningún
     * cliente manda no llega a comprobarse nunca, y devolver ese número parece una garantía que
     * no existe, que es peor que no tenerla. Estos ajustes los toca poca gente y rara vez, así
     * que la política es last-write-wins y está dicha en voz alta.
     *
     * `kilkana_layouts` SÍ conserva la suya, y allí es obligatoria: dos personas componiendo la
     * portada a la vez es el caso normal, no la excepción.
     */
    public function update(array $data): SnkKilkanaSettingModel
    {
        $row = $this->get();

        $row->fill($this->columnsFrom($data));
        // Lanzar es un hecho, no un ajuste: se sella una vez y no se deshace desde aquí. Un
        // periódico que quiera desaparecer de la calle tiene `search_visible`, que es otra cosa.
        if (($data['launched'] ?? false) === true) {
            $row->launched_at ??= now();
        }
        $row->save();

        return $row;
    }

    /**
     * La puesta en marcha, a cero: el archivo, las secciones y las etiquetas se van, y la fecha
     * de lanzar se borra. Es lo que hace falta para recorrer el onboarding otra vez.
     *
     * Es la única puerta que deshace `launched_at`, y por eso no vive en `update()`: allí lanzar
     * es un hecho. Las firmas y las cuentas se quedan —son gente, no contenido—; el tema se
     * quita, porque elegirlo es el primer paso del onboarding.
     *
     * ponytail: es un atajo de desarrollo y en producción no existe (403 en el controlador);
     * el día que un periódico real quiera borrarse entero, eso es un flujo con confirmación.
     *
     * @throws RuntimeException en producción
     */
    public function resetSetup(): SnkKilkanaSettingModel
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Setup cannot be reset in production.');
        }

        return DB::transaction(function () {
            // Lo que cuelga de una pieza —revisiones, notas, comentarios, lecturas, pivotes—
            // cae en cascada por la clave foránea. Sin papelera: se va del todo.
            SnkKilkanaPostModel::withTrashed()->forceDelete();
            // Hijas antes que madres: `parent_id` es RESTRICT.
            SnkKilkanaCategoryModel::query()->whereNotNull('parent_id')->delete();
            SnkKilkanaCategoryModel::query()->delete();
            SnkKilkanaTagModel::query()->delete();

            // Desvestido también: elegir tema es el primer paso, y reiniciar es volver a él.
            $row = $this->get();
            $row->forceFill([
                'launched_at' => null,
                'theme_id' => null,
                'theme_spec' => null,
                'theme_version' => null,
                'theme_overrides' => null,
            ])->save();

            return $row;
        });
    }

    public function payload(SnkKilkanaSettingModel $row): array
    {
        $row->loadMissing('theme');

        return [
            'theme_id' => $row->theme_id,
            // El tema resuelto viaja entero —spec incluido— para evitar una segunda llamada. Es
            // siempre la copia congelada del periódico con su retoque encima, no la fila del
            // catálogo: vestir un tema lo copia primero.
            'theme' => $row->themePayload(),
            'theme_update_available' => $this->wear->updateAvailable($row),
            'tenant' => [
                'name' => $row->tenant_name,
                'tagline' => $row->tenant_tagline,
                'city' => $row->tenant_city,
                'url' => $row->tenant_url,
            ],
            'launched_at' => $row->launched_at?->toIso8601String(),
            'lang' => $row->lang,
            'tz' => $row->tz,
            'search_visible' => (bool) $row->search_visible,
            'contextual_spellcheck' => (bool) $row->contextual_spellcheck,
            'newsletter_reply_to' => $row->newsletter_reply_to,
            'legal' => self::group($row->legal, SnkKilkanaSettingModel::LEGAL),
            'social' => self::group($row->social, SnkKilkanaSettingModel::SOCIAL),
        ];
    }

    /**
     * Lo que la instalación decide y el periódico solo lee: desde qué dirección sale el
     * boletín, y cuántas direcciones viejas sigue contestando la calle con un 301. Aparte del
     * payload porque no sale de la fila, sale de la instalación y del archivo.
     */
    public function facts(): array
    {
        return [
            'sender_address' => (string) config('mail.from.address'),
            'redirects' => SnkKilkanaPostSlugModel::query()->count(),
        ];
    }

    /** Un grupo abierto, siempre con todas sus claves: nula la que no se ha escrito. */
    public static function group(?array $value, array $keys): array
    {
        $group = [];
        foreach ($keys as $key) {
            $v = $value[$key] ?? null;
            $group[$key] = is_string($v) && trim($v) !== '' ? trim($v) : null;
        }

        return $group;
    }

    /**
     * Del JSON anidado de la API a las columnas planas.
     *
     * Solo llega lo que venía: un guardado parcial toca sus campos y deja los demás donde
     * estaban, que es lo que hace `fill` por su cuenta.
     */
    private function columnsFrom(array $data): array
    {
        $columns = collect($data)->only(['theme_overrides', 'lang', 'tz', 'search_visible', 'contextual_spellcheck', 'newsletter_reply_to'])->all();
        foreach (['legal' => SnkKilkanaSettingModel::LEGAL, 'social' => SnkKilkanaSettingModel::SOCIAL] as $group => $keys) {
            if (is_array($data[$group] ?? null)) {
                $columns[$group] = self::group($data[$group], $keys);
            }
        }

        // Vestir es SIEMPRE copiar. Si llega un tema del catálogo se copia su spec aquí, porque
        // apuntar a la fila del catálogo dejaría al periódico a merced de la siguiente
        // sincronización — y cambiaría de aspecto solo. Va antes que el retoque: cambiar de tema
        // vacía lo movido, y mandar las dos cosas en la misma llamada tiene que dejar el retoque
        // nuevo puesto, no borrado.
        if (isset($data['theme_id'])) {
            $theme = SnkKilkanaThemeModel::query()->find($data['theme_id']);
            if ($theme) {
                $this->wear->wear($theme);
            }
        }

        $tenant = is_array($data['tenant'] ?? null) ? $data['tenant'] : [];
        foreach (self::TENANT as $field) {
            if (array_key_exists($field, $tenant)) {
                $columns["tenant_{$field}"] = $tenant[$field];
            }
        }

        return $columns;
    }
}
