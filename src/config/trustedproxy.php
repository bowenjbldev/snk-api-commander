<?php

/*
|--------------------------------------------------------------------------
| Proxies de confianza
|--------------------------------------------------------------------------
|
| Lo lee `Illuminate\Http\Middleware\TrustProxies` sin más cableado: si esto
| es nulo, Laravel descarta las cabeceras `X-Forwarded-*` y `$request->ip()`
| devuelve la IP de quien abre la conexión TCP.
|
| Por qué importa aquí, y no es teoría: el panel de Kilkana habla con esta API
| desde su propio servidor (BFF), así que **todas** las peticiones del panel
| llegan con la misma IP —la pasarela de Docker de ese despliegue—. El límite
| de fallos de login por IP (`CORE_LOGIN_IP_RATE_LIMIT`, 10/min) queda entonces
| compartido por toda la redacción, y a quien lo agote deja fuera a los demás.
| El límite por cuenta (5/min) sí discrimina y sigue protegiendo.
|
| El valor **no** tiene default a propósito. Las dos salidas fáciles son malas:
| dejarlo nulo conserva el cubo compartido, y poner `'*'` deja que cualquiera
| escriba su propia `X-Forwarded-For` y rote de IP a voluntad, que vacía el
| límite por IP en vez de arreglarlo. Lo correcto es enumerar la IP o el CIDR
| del proxy que de verdad tenemos delante, y eso solo lo sabe cada despliegue.
|
| Acepta una lista separada por comas o un array: `10.0.0.0/8,172.18.0.1`.
|
*/

return [
    'proxies' => env('TRUSTED_PROXIES'),
];
