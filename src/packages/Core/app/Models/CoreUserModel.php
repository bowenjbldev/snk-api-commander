<?php

namespace Packages\Core\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class CoreUserModel extends Authenticatable
{
    protected $table = 'users';

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    /**
     * `full_name`, `company` y `phone` NO son columnas de Core: las anade cada package con su
     * propia migracion (ver `create_core_base_schema`). Siguen aqui porque son el perfil que
     * aceptan las reglas de `CoreUsersController`, y quien use ese controlador tiene que
     * haberlas creado.
     *
     * Sin `avatar`: ningun contrato de Core lo valida ni lo sirve, y la foto que se publica
     * es cosa del package — en Kilkana, la biblioteca de medios.
     */
    protected $fillable = [
        'name',
        'email',
        'full_name',
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

    protected static function booted()
    {
        static::saving(function (CoreUserModel $model) {
            if ((string) $model->name === '' && !empty($model->full_name)) {
                $model->name = $model->full_name;
            }
        });
    }

    public function setPasswordAttribute($value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $hash = Hash::make($value);

        $this->attributes['password'] = $hash;
    }

}
