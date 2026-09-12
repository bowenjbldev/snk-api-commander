<?php

namespace Packages\Core\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

class CoreUserModel extends Authenticatable
{
    protected $table = 'users';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * `lastname`, `company` y `phone` NO son columnas de Core: las añade cada package con su
     * propia migración (ver `create_core_base_schema`). Están aquí porque son el perfil que
     * aceptan las reglas de `CoreUsersController`, y quien use ese controlador tiene que
     * haberlas creado.
     *
     * Sin `avatar`: ningún contrato de Core lo valida ni lo sirve, y la foto que se publica
     * es cosa del package — en Kilkana, la biblioteca de medios.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'lastname',
        'email',
        'company',
        'phone',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verified_at',
        'created_at',
        'updated_at',
        'roles',
        'permissions',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * `name` es el nombre de pila y `lastname` el apellido; el nombre completo no se guarda,
     * se compone. Va en `$appends` para que viaje en toda serialización como si fuera columna.
     */
    protected $appends = ['full_name'];

    protected function fullName(): Attribute
    {
        // De los atributos crudos: una consulta recortada que no trajo `lastname` no debe
        // lanzar al serializar, y `$appends` lo pide en toda serialización.
        return Attribute::get(fn () => trim(($this->attributes['name'] ?? '').' '.($this->attributes['lastname'] ?? '')));
    }

    public function setPasswordAttribute($value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $hash = Hash::make($value);

        $this->attributes['password'] = $hash;
    }

    /**
     * Lo mismo que Spatie, preguntando primero al rol.
     *
     * Spatie mira primero los permisos directos del usuario y después los de sus roles: dos
     * consultas para decir que sí. Aquí el poder se reparte por rol —`model_has_permissions`
     * está vacía en todas las bases— y la primera consulta nunca acierta. Medido: `can()` pasa
     * de 1,7 a 0,9 ms por petición al invertir el orden. El resultado es el mismo: un permiso
     * directo sigue valiendo, solo que se consulta cuando el rol dice que no.
     *
     * @param  string|int|Permission|\BackedEnum  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        if ($this->getWildcardClass()) {
            return $this->hasWildcardPermission($permission, $guardName);
        }

        $permission = $this->filterPermission($permission, $guardName);

        return $this->hasPermissionViaRole($permission) || $this->hasDirectPermission($permission);
    }
}
