<?php

namespace Packages\Snk\Kilkana\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;

// Firma editorial. La IDENTIDAD es la cuenta (`user_id` → `users.email`); aquí solo vive
// lo editorial: alias, slug público, bio, web, redes y foto.
class SnkKilkanaAuthorModel extends Model
{
    protected $table = 'kilkana_authors';

    protected $guarded = [];

    protected $casts = ['socials' => 'array', 'noindex' => 'boolean'];

    /**
     * La cuenta viaja SIEMPRE, porque el nombre que se publica sale de ella.
     *
     * Con columnas: `users` guarda el correo del periodista y esto se sirve anidado en cada
     * post de la salida pública. `$hidden` la esconde del JSON —quien lee la firma quiere
     * `name`, no un objeto `user`— pero cargada, que es lo que necesita el accesor.
     */
    protected $with = ['user:id,name'];

    protected $hidden = ['user'];

    protected $appends = ['name'];

    /**
     * El nombre que se publica: su alias si firma con seudónimo, y si no, el de su cuenta.
     *
     * Derivado, no guardado. Renombrar a la persona renombra su firma en el periódico
     * entero —tarjetas, ficha de autor, JSON-LD, feed— sin tocar nada más.
     */
    public function getNameAttribute(): string
    {
        $alias = trim((string) ($this->attributes['alias'] ?? ''));

        return $alias !== '' ? $alias : (string) ($this->user?->name ?? '');
    }

    public function avatarMedia(): BelongsTo
    {
        return $this->belongsTo(SnkKilkanaMediaModel::class, 'avatar_media_id');
    }

    /** La cuenta que identifica la firma. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(CoreUserModel::class, 'user_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $author) {
            if ($author->slug) {
                return;
            }
            $author->slug = self::deriveSlug($author);
        });
    }

    /**
     * URL pública de una firma nueva, cuando nadie la ha escrito.
     *
     * Dos periodistas pueden llamarse igual, y `slug` es UNIQUE: derivar a secas reventaba
     * el alta de la segunda cuenta con un 1062. La cascada usa lo que una redacción usa de
     * verdad —el segundo apellido, que ya vive en `users.full_name`— y **se para ahí**:
     *
     *     jorge-bowen  →  jorge-bowen-lopez  →  la elige una persona
     *
     * Sin `-2`. Un sufijo automático fija para siempre una URL que nadie eligió y que nadie
     * vuelve a mirar; Discord lo tuvo ocho años (`jorge#4821`) y lo retiró por eso mismo.
     * Cuando las dos candidatas están ocupadas —o el nombre no deja slug latino— esto lanza
     * un 422 sobre `slug`: el alta manual lo pinta como «esa URL ya está en uso» y la
     * automática (`SnkKilkanaUsersController::ensureByline`) lo convierte en un aviso.
     *
     * `getNameAttribute` no vale aquí: la relación no está cargada en una fila que aún no
     * existe, así que se pide la cuenta.
     */
    private static function deriveSlug(self $author): string
    {
        $alias = trim((string) ($author->attributes['alias'] ?? ''));
        $user = CoreUserModel::query()->whereKey($author->user_id)->first(['name', 'full_name']);

        // Con seudónimo no hay segunda vuelta: el nombre de su cuenta no es el que firma.
        $candidatas = $alias !== ''
            ? [Str::slug($alias)]
            : [Str::slug((string) ($user?->name ?? '')), Str::slug((string) ($user?->full_name ?? ''))];

        foreach (array_unique(array_filter($candidatas)) as $slug) {
            if (! self::query()->where('slug', $slug)->exists()) {
                return $slug;
            }
        }

        throw ValidationException::withMessages(['slug' => 'Byline URL already taken; choose one.']);
    }
}
