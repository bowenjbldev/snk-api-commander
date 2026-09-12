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

    /**
     * Manda el enlace. Un fallo del correo sube: decir «enviado» sin haberlo hecho es peor.
     *
     * `$queued` lo pide solo el camino público de «he olvidado mi contraseña»: ahí la respuesta
     * es la misma exista la cuenta o no, y un envío síncrono tarda lo que tarda el SMTP solo
     * cuando existe, que es un reloj que dice quién trabaja aquí. Los otros emisores siguen
     * síncronos porque dependen de que esto lance —invitar borra la cuenta si el correo no sale—.
     */
    public function send(CoreUserModel $user, string $kind, Request $request, bool $queued = false): void
    {
        $token = Password::broker()->createToken($user);
        $url = $this->panelUrl($request).'/admin/password?'.http_build_query([
            'email' => $user->email,
            'token' => $token,
        ]);
        $newspaper = SnkKilkanaSettingModel::query()->find(1)?->tenant_name ?: config('app.name');

        $email = new SnkKilkanaAccountLinkMail(
            $kind,
            $newspaper,
            $url,
            (int) config('auth.passwords.users.expire', 60),
        );
        $queued ? Mail::to($user->email)->queue($email) : Mail::to($user->email)->send($email);
    }

    /**
     * La URL del panel sale de datos, nunca de la cabecera `Host` de la petición: la del
     * periódico en sus ajustes, o `https://{cliente}.{dominio base}`, la misma receta que el
     * seeder. La petición solo aporta el subdominio del cliente. Sin ninguna de las dos no hay
     * enlace que mandar: con un «he olvidado mi contraseña» público, caer al host de la
     * petición sería una inyección de cabecera de manual, el enlace del correo apuntando a
     * donde diga quien llama.
     */
    private function panelUrl(Request $request): string
    {
        $settings = (string) (SnkKilkanaSettingModel::query()->find(1)?->tenant_url ?? '');
        if ($settings !== '') {
            return rtrim($settings, '/');
        }

        $base = (string) config('core.tenant_base_domain', '');
        $client = CorePackageSelectorSupport::subdomainFromHost($request->getHost());
        if ($base !== '' && $client !== null) {
            return "https://{$client}.{$base}";
        }

        throw new \RuntimeException('No panel URL: set tenant_url or core.tenant_base_domain.');
    }
}
