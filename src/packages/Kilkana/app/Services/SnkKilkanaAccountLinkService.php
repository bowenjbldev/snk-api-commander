<?php

namespace Packages\Kilkana\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Packages\Core\Models\CoreUserModel;
use Packages\Core\Support\CorePackageSelectorSupport;
use Packages\Kilkana\Mail\SnkKilkanaAccountLinkMail;
use Packages\Kilkana\Models\SnkKilkanaSettingModel;

/**
 * El correo que lleva a poner una contraseña: la invitación y el enlace de recuperación.
 *
 * Son el mismo mecanismo con dos textos. Los dos acuñan un token del broker de contraseñas
 * —caduca solo, y al usarlo se borra— y apuntan a la pantalla del panel que lo canjea. El
 * enlace lleva el host del PERIÓDICO y no el de la API: quien lo pulsa tiene que aterrizar en
 * el panel de su cabecera, y `APP_URL` apunta a otro sitio.
 */
class SnkKilkanaAccountLinkService
{
    public const INVITE = 'invite';

    public const RESET = 'reset';

    /** Manda el enlace. Un fallo del correo sube: decir «enviado» sin haberlo hecho es peor. */
    public function send(CoreUserModel $user, string $kind, Request $request): void
    {
        $token = Password::broker()->createToken($user);
        $url = $this->panelUrl($request).'/admin/password?'.http_build_query([
            'email' => $user->email,
            'token' => $token,
        ]);
        $periodico = SnkKilkanaSettingModel::query()->find(1)?->tenant_name ?: config('app.name');

        Mail::to($user->email)->send(new SnkKilkanaAccountLinkMail(
            $kind,
            $periodico,
            $url,
            (int) config('auth.passwords.users.expire', 60),
        ));
    }

    /**
     * `https://{cliente}.{dominio base}`: la misma receta que el seeder para la URL del periódico.
     *
     * La caída al host de la petición solo salta sin dominio base configurado —los tests—, y hoy
     * no es un agujero porque los tres emisores exigen administrar cuentas. El día que exista un
     * «he olvidado mi contraseña» público, esa caída es una inyección de cabecera Host de manual
     * y hay que quitarla antes de abrir la ruta.
     */
    private function panelUrl(Request $request): string
    {
        $base = (string) config('core.tenant_base_domain', '');
        $cliente = CorePackageSelectorSupport::subdomainFromHost($request->getHost());

        return $base !== '' && $cliente !== null
            ? "https://{$cliente}.{$base}"
            : $request->getSchemeAndHttpHost();
    }
}
