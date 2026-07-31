<?php

namespace Packages\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SnkKilkanaTagModel extends Model
{
    protected $table = 'kilkana_tags';

    protected $guarded = [];

    // El pivot no aporta nada al JSON del post.
    protected $hidden = ['pivot'];

    /**
     * De aqui salen los anchos de las dos columnas y los topes de la validacion.
     *
     * Eran `varchar(255)` mientras las cuatro reglas de la API decian 64: la columna prometia
     * cuatro veces lo que nadie podia escribir. 64 sobra para un tema —«cambio climatico» son
     * 16— y es lo que hace que el slug quepa siempre en su columna.
     */
    public const NAME_MAX = 64;

    public const SLUG_MAX = 64;

    /**
     * El slug de una etiqueta: lo que la identifica y la unica forma que tendria en una URL.
     *
     * Un nombre que no deja ni una letra latina —«###», solo emoji— daria cadena vacia, y
     * `slug` es UNIQUE: el segundo caso asi reventaba el alta. El respaldo no es una URL
     * bonita, pero es estable, no colisiona y no tumba nada.
     */
    public static function slugFor(string $name): string
    {
        $slug = rtrim(mb_substr(Str::slug($name), 0, self::SLUG_MAX), '-');

        return $slug ?: 'tag-'.substr(sha1($name), 0, 8);
    }

    /**
     * Busca o crea por SLUG, no por como se escribio el nombre.
     *
     * «Covid-19» y «Covid 19» no son dos etiquetas: son una escrita de dos maneras, y para un
     * lector significan lo mismo. Buscar por nombre las trataba como distintas y luego chocaba
     * contra el UNIQUE del slug con un 500 —y por esta puerta entra un redactor tecleando la
     * etiqueta de su articulo, asi que el 500 le llegaba a el—.
     *
     * ponytail: dos nombres GENUINAMENTE distintos que comparten slug («C++» y «C#», que
     * `Str::slug` deja los dos en «c») se colapsan en el primero en vez de dar error. Es la
     * consecuencia aceptada de que el slug mande; quien necesite separarlos los crea a mano
     * por `POST tags` con un slug propio, que si avisa cuando esta cogido.
     */
    public static function firstOrCreateByName(string $name): self
    {
        return self::firstOrCreate(['slug' => self::slugFor($name)], ['name' => $name]);
    }

    protected static function booted(): void
    {
        static::creating(function (self $tag) {
            if ($tag->slug) {
                return;
            }

            $slug = self::slugFor((string) $tag->name);
            // El alta manual SI se para: nadie ha dicho que las dos sean la misma etiqueta.
            if (self::query()->where('slug', $slug)->exists()) {
                throw ValidationException::withMessages(['slug' => 'That tag URL is already in use; choose one.']);
            }

            $tag->slug = $slug;
        });
    }
}
