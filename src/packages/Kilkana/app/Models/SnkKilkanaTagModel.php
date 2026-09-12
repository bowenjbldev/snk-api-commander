<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SnkKilkanaTagModel extends Model
{
    /**
     * La lista del vocabulario: con lo que el panel pinta de cada etiqueta, por nombre.
     *
     * Es un scope y no el estado normal del modelo porque solo lo quiere quien LISTA: pedir una
     * etiqueta para renombrarla no necesita recorrer su pivote. Y vive aquí y no en el
     * controlador porque lo preguntan dos puertas —el recurso y el armazón del panel—.
     *
     * `last_used_at` mira solo lo publicado, igual que en firmas: un borrador de hoy no es uso
     * del vocabulario, es una intención. `posts_count` cuenta todo lo que la lleva, incluidos
     * borradores, porque para fundir importa cuántas piezas hay que mover, no cuántas se ven.
     */
    public function scopeAsCatalogue(Builder $query): Builder
    {
        return $query
            ->withCount('posts')
            ->withMax(['posts as last_used_at' => fn ($q) => $q->published()], 'publish_at')
            ->orderBy('name');
    }

    protected $table = 'kilkana_tags';

    protected $guarded = [];

    // El pivot no aporta nada al JSON del post.
    protected $hidden = ['pivot'];

    /**
     * De aquí salen los anchos de las dos columnas y los topes de la validación, para que la
     * columna no prometa más de lo que la API deja escribir. 64 sobra para un tema —«cambio
     * climático» son 16— y es lo que hace que el slug quepa siempre en su columna.
     */
    public const NAME_MAX = 64;

    public const SLUG_MAX = 64;

    /**
     * El slug de una etiqueta: lo que la identifica y la única forma que tendría en una URL.
     *
     * Un nombre que no deja ni una letra latina —«###», solo emoji— daría cadena vacía, y
     * `slug` es UNIQUE: el segundo caso así reventaría el alta. El respaldo no es una URL
     * bonita, pero es estable, no colisiona y no tumba nada.
     */
    public static function slugFor(string $name): string
    {
        $slug = rtrim(mb_substr(Str::slug($name), 0, self::SLUG_MAX), '-');

        return $slug ?: 'tag-'.substr(sha1($name), 0, 8);
    }

    /**
     * Busca o crea por SLUG, no por cómo se escribió el nombre.
     *
     * «Covid-19» y «Covid 19» no son dos etiquetas: son una escrita de dos maneras, y para un
     * lector significan lo mismo. Buscar por nombre las trataría como distintas y luego chocaría
     * contra el UNIQUE del slug con un 500 —y por esta puerta entra un redactor tecleando la
     * etiqueta de su artículo, así que el 500 le llegaría a él—.
     *
     * ponytail: dos nombres GENUINAMENTE distintos que comparten slug («C++» y «C#», que
     * `Str::slug` deja los dos en «c») se colapsan en el primero en vez de dar error. Es la
     * consecuencia aceptada de que el slug mande; quien necesite separarlos los crea a mano
     * por `POST tags` con un slug propio, que sí avisa cuando está cogido.
     */
    public static function firstOrCreateByName(string $name): self
    {
        return self::firstOrCreate(['slug' => self::slugFor($name)], ['name' => $name]);
    }

    /**
     * Las piezas que llevan la etiqueta: lo que hace que su cifra signifique algo.
     *
     * Es la inversa de `SnkKilkanaPostModel::tags()`, y de aquí salen las dos cuentas del panel
     * —cuánto se usa y cuándo se usó por última vez— y lo que una fusión tiene que mover.
     *
     * @return BelongsToMany<SnkKilkanaPostModel, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(SnkKilkanaPostModel::class, 'kilkana_post_tag', 'tag_id', 'post_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $tag) {
            if ($tag->slug) {
                return;
            }

            $slug = self::slugFor((string) $tag->name);
            // El alta manual SÍ se para: nadie ha dicho que las dos sean la misma etiqueta.
            if (self::query()->where('slug', $slug)->exists()) {
                throw ValidationException::withMessages(['slug' => 'That tag URL is already in use; choose one.']);
            }

            $tag->slug = $slug;
        });
    }
}
