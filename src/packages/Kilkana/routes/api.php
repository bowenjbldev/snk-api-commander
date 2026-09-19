<?php

use Illuminate\Support\Facades\Route;
use Packages\Kilkana\Enums\SnkKilkanaLayoutEnum;
use Packages\Kilkana\Http\Controllers\SnkKilkanaActivityController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaAuthController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaAuthorsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCategoriesController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCommentsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaDictionaryWordsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaGateController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaLayoutsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaMediaController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaMediaTaxonomiesController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaNewsletterController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPanelController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPlanController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostNotesController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPublicController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaReadersController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaRolesController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaSettingsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaSubscribersController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaTagsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaThemesController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaTrashController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [SnkKilkanaAuthController::class, 'login'])->middleware('throttle:login');
    // Canjea el enlace del correo —invitación o recuperación— por una contraseña. Público, y
    // con freno propio: el de `login` solo cuenta los 401, y aquí un canje fallido es un 422.
    Route::post('auth/password', [SnkKilkanaAuthController::class, 'resetPassword'])->middleware('throttle:6,1');
    // Público y con freno: manda el enlace de recuperación a quien lo pide, y contesta igual
    // exista la cuenta o no.
    Route::post('auth/password-link', [SnkKilkanaAuthController::class, 'sendPasswordLink'])->middleware('throttle:6,1');
    // El alta de un lector desde la calle: público y con freno, nace con el papel `reader`.
    Route::post('auth/register', [SnkKilkanaAuthController::class, 'register'])->middleware('throttle:6,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [SnkKilkanaAuthController::class, 'me']);
        Route::post('auth/logout', [SnkKilkanaAuthController::class, 'logout']);
        // Las cuentas del panel. `invite` va antes del recurso y los ids se acotan a número:
        // sin lo segundo MySQL convierte `1abc` en `1` y un gesto con id malformado cae
        // sobre la cuenta 1.
        Route::post('users/invite', [SnkKilkanaUsersController::class, 'invite']);
        Route::post('users/{id}/invitation', [SnkKilkanaUsersController::class, 'resendInvitation'])->whereNumber('id');
        Route::post('users/{id}/password-link', [SnkKilkanaUsersController::class, 'sendPasswordLink'])->whereNumber('id');
        Route::post('users/{id}/suspension', [SnkKilkanaUsersController::class, 'suspend'])->whereNumber('id');
        Route::delete('users/{id}/suspension', [SnkKilkanaUsersController::class, 'restore'])->whereNumber('id');
        Route::apiResource('users', SnkKilkanaUsersController::class)->whereNumber('user');
        Route::get('roles', [SnkKilkanaRolesController::class, 'index']);
    });
});

// Lectura pública (sin auth): solo lo publicado, resuelto por slug (URLs SEO).
Route::middleware(['api'])->group(function () {
    Route::get('public/posts', [SnkKilkanaPublicController::class, 'posts']);
    Route::get('public/sitemap', [SnkKilkanaPublicController::class, 'sitemap']);
    Route::get('public/tags/{slug}', [SnkKilkanaPublicController::class, 'tag']);
    Route::get('public/categories', [SnkKilkanaPublicController::class, 'categories']);
    // Una sección por slug: lo necesita quien resuelve `/{section}`, y es por donde entra la
    // redirección cuando esa URL es una que la sección dejó atrás.
    Route::get('public/categories/{slug}', [SnkKilkanaPublicController::class, 'category']);
    // Perfil de una firma: lo necesita /author/{slug}, que existe aunque no tenga publicados.
    Route::get('public/authors/{slug}', [SnkKilkanaPublicController::class, 'author']);
    // Un artículo se direcciona por FECHA + slug: el slug solo es único dentro de su día,
    // así que los cuatro segmentos son la clave, no un adorno. Las tres rutas comparten la
    // misma forma para que no convivan dos maneras de nombrar la misma pieza.
    Route::get('public/posts/{year}/{month}/{day}/{slug}', [SnkKilkanaPublicController::class, 'post'])
        ->whereNumber(['year', 'month', 'day']);
    // Audiencia: la manda el navegador, no el render. Throttle porque es un POST anónimo que
    // incrementa un contador, o sea aporreable por definición.
    Route::post('public/posts/{year}/{month}/{day}/{slug}/view', [SnkKilkanaPublicController::class, 'view'])
        ->whereNumber(['year', 'month', 'day'])
        ->middleware('throttle:kilkana-view');
    // La previa por enlace: firmada y con caducidad, para quien no tiene sesión.
    Route::get('public/preview/{post}', [SnkKilkanaPublicController::class, 'preview'])
        ->whereNumber('post')
        ->middleware('throttle:kilkana-signed');
    Route::get('public/posts/{year}/{month}/{day}/{slug}/comments', [SnkKilkanaPublicController::class, 'comments'])
        ->whereNumber(['year', 'month', 'day']);
    Route::get('public/layouts/{layout}', [SnkKilkanaPublicController::class, 'layout'])->whereIn('layout', SnkKilkanaLayoutEnum::values());
    Route::get('public/site', [SnkKilkanaPublicController::class, 'site']);
    Route::post('public/subscribe', [SnkKilkanaPublicController::class, 'subscribe'])->middleware('throttle:kilkana-subscribe');
    // Los dos enlaces del correo. `signed` es toda la autorización que hay y toda la que puede
    // haber: quien pulsa viene de su buzón, no de una sesión. Sin firma, recorrer ids daría de
    // baja a media lista. Van nombradas porque `URL::signedRoute` las construye por nombre.
    Route::get('public/subscribe/confirm/{subscriber}', [SnkKilkanaPublicController::class, 'confirmSubscription'])
        ->name('subscribe.confirm')
        ->whereNumber('subscriber')
        ->middleware(['signed', 'throttle:kilkana-signed']);
    Route::get('public/subscribe/unsubscribe/{subscriber}', [SnkKilkanaPublicController::class, 'unsubscribe'])
        ->name('subscribe.unsubscribe')
        ->whereNumber('subscriber')
        ->middleware(['signed', 'throttle:kilkana-signed']);
    // Lo que un suscriptor hace con un número: abrir —el píxel— y pulsar una pieza. Firmadas
    // por número y suscriptor: sin firma, recorrer ids inventaría aperturas.
    Route::get('public/newsletter/{issue}/open/{subscriber}', [SnkKilkanaPublicController::class, 'newsletterOpen'])
        ->name('newsletter.open')
        ->whereNumber(['issue', 'subscriber'])
        ->middleware(['signed', 'throttle:kilkana-signed']);
    Route::get('public/newsletter/{issue}/click/{subscriber}/{post}', [SnkKilkanaPublicController::class, 'newsletterClick'])
        ->name('newsletter.click')
        ->whereNumber(['issue', 'subscriber', 'post'])
        ->middleware(['signed', 'throttle:kilkana-signed']);
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    // Antes del apiResource: si no, `show` se traga «bulk» como id de post.
    Route::post('posts/bulk', [SnkKilkanaPostsController::class, 'bulk']);
    Route::apiResource('posts', SnkKilkanaPostsController::class);
    Route::get('posts/{post}/revisions', [SnkKilkanaPostsController::class, 'revisions']);
    Route::post('posts/{post}/preview-link', [SnkKilkanaPostsController::class, 'previewLink'])->whereNumber('post');
    Route::post('posts/{post}/revisions/{revision}/restore', [SnkKilkanaPostsController::class, 'restore']);
    // Las notas de mesa de una pieza: se leen con la pieza y se escriben con editarla.
    Route::get('posts/{post}/notes', [SnkKilkanaPostNotesController::class, 'list'])->whereNumber('post');
    Route::post('posts/{post}/notes', [SnkKilkanaPostNotesController::class, 'add'])->whereNumber('post');
    Route::patch('posts/{post}/notes/{note}', [SnkKilkanaPostNotesController::class, 'resolve'])->whereNumber(['post', 'note']);
    Route::apiResource('categories', SnkKilkanaCategoriesController::class);
    Route::apiResource('authors', SnkKilkanaAuthorsController::class);
    Route::post('media/upload', [SnkKilkanaMediaController::class, 'upload']);
    Route::post('media/bulk', [SnkKilkanaMediaController::class, 'bulk']);
    Route::post('media/{id}/restore', [SnkKilkanaMediaController::class, 'restore']);
    Route::delete('media/{id}/force', [SnkKilkanaMediaController::class, 'forceDestroy']);
    Route::post('media/{id}/replace', [SnkKilkanaMediaController::class, 'replace']);
    Route::get('media/{id}/frame/{at}', [SnkKilkanaMediaController::class, 'frame'])->whereNumber(['id', 'at']);
    Route::post('media/{id}/poster', [SnkKilkanaMediaController::class, 'poster'])->whereNumber('id');
    // Sin `store`: un medio ENTRA por `upload` o por `replace`, que calculan hash, ruta y
    // dimensiones. El alta genérica no valida nada —el controlador no declara reglas, así
    // que el base mete `$request->all()` en el modelo— y dejaría escribir `disk` y `path`,
    // que son justo lo que `Storage::delete` consume al vaciar la papelera.
    Route::apiResource('media', SnkKilkanaMediaController::class)->except('store');
    // Taxonomías de medios: tags y colecciones comparten controlador; el segmento {taxonomy} decide el modelo.
    Route::get('media-{taxonomy}', [SnkKilkanaMediaTaxonomiesController::class, 'index'])->whereIn('taxonomy', ['tags', 'collections'])->middleware('can:index media');
    Route::post('media-{taxonomy}', [SnkKilkanaMediaTaxonomiesController::class, 'store'])->whereIn('taxonomy', ['tags', 'collections'])->middleware('can:update media');
    Route::put('media-{taxonomy}/{id}', [SnkKilkanaMediaTaxonomiesController::class, 'update'])->whereIn('taxonomy', ['tags', 'collections'])->whereNumber('id')->middleware('can:update media');
    Route::post('media-{taxonomy}/{id}/merge', [SnkKilkanaMediaTaxonomiesController::class, 'merge'])->whereIn('taxonomy', ['tags', 'collections'])->whereNumber('id')->middleware('can:update media');
    Route::delete('media-{taxonomy}/{id}', [SnkKilkanaMediaTaxonomiesController::class, 'destroy'])->whereIn('taxonomy', ['tags', 'collections'])->whereNumber('id')->middleware('can:update media');
    // Antes del apiResource: `tags/{id}/merge` no choca con `show`, pero declararla después
    // deja la fusión escondida detrás del recurso al leer las rutas de una taxonomía.
    //
    // Y las dos con `whereNumber`: sin él, `tags/1abc` llega al controlador y MySQL castea el
    // id al comparar, así que `DELETE tags/1abc` borra la etiqueta 1 —con sus piezas
    // desetiquetadas— y `POST tags/1abc/merge` funde la 1. Es el mismo `where` que acota las
    // taxonomías de medios y las portadas, aplicado a lo que se puede destruir.
    Route::post('tags/{id}/merge', [SnkKilkanaTagsController::class, 'merge'])->whereNumber('id');
    Route::apiResource('tags', SnkKilkanaTagsController::class)->whereNumber('tag');
    // El diccionario de la redacción: se lista, se añade y se quita. Una palabra no se edita.
    Route::apiResource('dictionary-words', SnkKilkanaDictionaryWordsController::class)->only(['index', 'store', 'destroy'])->whereNumber('dictionary_word');
    // Antes del apiResource, como en posts: si no, `show` se traga «bulk» como id.
    Route::post('comments/bulk', [SnkKilkanaCommentsController::class, 'bulk']);
    Route::post('comments/{id}/block-author', [SnkKilkanaCommentsController::class, 'blockAuthor'])->whereNumber('id');
    Route::apiResource('comments', SnkKilkanaCommentsController::class)->whereNumber('comment');

    // Antes del apiResource: sin `whereNumber`, `show` se tragaría «export» como id.
    Route::post('subscribers/import', [SnkKilkanaSubscribersController::class, 'import']);
    Route::get('subscribers/export', [SnkKilkanaSubscribersController::class, 'export']);
    Route::apiResource('subscribers', SnkKilkanaSubscribersController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    // Los números del boletín: se listan, se crean en borrador, se corrigen mientras lo son, se
    // prueban y se mandan. No se borran: un número enviado es lo que hay en las bandejas.
    Route::get('newsletter', [SnkKilkanaNewsletterController::class, 'index']);
    Route::post('newsletter', [SnkKilkanaNewsletterController::class, 'store']);
    Route::put('newsletter/{id}', [SnkKilkanaNewsletterController::class, 'update'])->whereNumber('id');
    Route::post('newsletter/{id}/send', [SnkKilkanaNewsletterController::class, 'send'])->whereNumber('id');
    Route::post('newsletter/{id}/test', [SnkKilkanaNewsletterController::class, 'test'])->whereNumber('id');
    // Las cuentas lectoras: se listan y se bloquean, y nada más. No hay `show` ni `destroy`
    // a propósito: borrar una cuenta se lleva sus comentarios, y para eso está bloquear.
    Route::get('readers', [SnkKilkanaReadersController::class, 'index']);
    Route::post('readers/{id}/block', [SnkKilkanaReadersController::class, 'block'])->whereNumber('id');
    Route::delete('readers/{id}/block', [SnkKilkanaReadersController::class, 'unblock'])->whereNumber('id');
    Route::delete('readers/{id}', [SnkKilkanaReadersController::class, 'anonymize'])->whereNumber('id');

    Route::get('layouts/{layout}/revisions', [SnkKilkanaLayoutsController::class, 'revisions'])->whereIn('layout', SnkKilkanaLayoutEnum::values())->middleware('can:show layouts');
    Route::post('layouts/{layout}/revisions/{revision}/restore', [SnkKilkanaLayoutsController::class, 'restore'])->whereIn('layout', SnkKilkanaLayoutEnum::values())->middleware('can:update layouts');
    Route::get('layouts/{layout}', [SnkKilkanaLayoutsController::class, 'show'])->whereIn('layout', SnkKilkanaLayoutEnum::values())->middleware('can:show layouts');
    Route::put('layouts/{layout}', [SnkKilkanaLayoutsController::class, 'update'])->whereIn('layout', SnkKilkanaLayoutEnum::values())->middleware('can:update layouts');

    // El catálogo solo se lee: un tema es material del producto y lo escribe
    // `kilkana:sync-themes`. Lo único que un periódico hace con uno es vestírselo, y eso copia
    // su spec a los ajustes — el retoque de encima se guarda con el resto de ajustes.
    Route::get('themes', [SnkKilkanaThemesController::class, 'catalog']);
    Route::get('theme-categories', [SnkKilkanaThemesController::class, 'categories']);
    Route::post('themes/{id}/wear', [SnkKilkanaThemesController::class, 'wear']);

    // El consumo del propio periódico contra su plan. Solo lectura, y lo mide Commander.
    //
    // `can:index posts` y no `auth:sanctum` a secas: la decisión de producto es que la tarjeta de
    // plan la ve TODA LA REDACCIÓN, y esa lista son exactamente los seis roles que abren el panel.
    // Un lector también recibe token —`login` no mira el rol— y esta sería la única ruta
    // autenticada que podría leer, con el precio, los asientos y la banda del periódico dentro.
    // Tampoco `can:show settings`, que dejaría sin plan a cuatro de los seis que sí deben verlo.
    Route::get('plan', [SnkKilkanaPlanController::class, 'show'])->middleware('can:index posts');

    // Las dos pantallas que el panel pediría a trozos. No sustituyen al CRUD por recurso —que
    // es el contrato general y lo usa el resto del panel—: son un atajo para las dos pantallas
    // donde lo que cuesta, medido, son los viajes y no las consultas.
    //
    // `bootstrap` no lleva permiso propio a propósito: cada sección conserva el suyo y la que
    // quien mira no puede ver llega nula. Un `can:` aquí dejaría al moderador —que no tiene
    // ninguno de los tres catálogos— sin armazón desde el que irse a su pantalla.
    Route::get('panel/bootstrap', [SnkKilkanaPanelController::class, 'bootstrap']);
    Route::get('panel/dashboard', [SnkKilkanaPanelController::class, 'dashboard'])->middleware('can:index posts');

    // El registro de la redacción: lo lee quien ve el sumario de la redacción —los cinco de
    // dentro; un colaborador externo ve lo suyo y ya—, y los movimientos de cuentas solo quien
    // las administra. Solo lectura: lo escribe el propio panel al escribir.
    Route::get('activity', [SnkKilkanaActivityController::class, 'index'])->middleware('can:view others posts');
    Route::post('activity/seen', [SnkKilkanaActivityController::class, 'seen'])->middleware('can:view others posts');

    // La puerta de salida: el veredicto de la última pasada, y la pasada nueva que lo sustituye.
    // Quien audita es la salida pública; aquí se guarda y se lee.
    Route::get('gate', [SnkKilkanaGateController::class, 'index']);
    Route::put('gate', [SnkKilkanaGateController::class, 'store']);

    // La papelera: cada clase con el permiso de borrarla, y vaciar purga lo que quien pulsa
    // puede purgar. `kind` se acota a las dos clases en el controlador.
    Route::get('trash', [SnkKilkanaTrashController::class, 'index']);
    Route::delete('trash', [SnkKilkanaTrashController::class, 'empty']);
    Route::post('trash/{kind}/{id}/restore', [SnkKilkanaTrashController::class, 'restore'])->whereNumber('id');
    Route::delete('trash/{kind}/{id}', [SnkKilkanaTrashController::class, 'purge'])->whereNumber('id');

    Route::get('settings', [SnkKilkanaSettingsController::class, 'show'])->middleware('can:show settings');
    Route::put('settings', [SnkKilkanaSettingsController::class, 'update'])->middleware('can:update settings');
    // La puesta en marcha a cero: archivo, secciones y etiquetas fuera. Atajo de desarrollo; en producción, 403.
    Route::delete('setup', [SnkKilkanaSettingsController::class, 'reset'])->middleware('can:update settings');
});
