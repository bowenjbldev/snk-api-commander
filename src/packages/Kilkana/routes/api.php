<?php

use Illuminate\Support\Facades\Route;
use Packages\Kilkana\Enums\SnkKilkanaLayoutEnum;
use Packages\Kilkana\Http\Controllers\SnkKilkanaAuthController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaAuthorsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCategoriesController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaCommentsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaLayoutsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaMediaController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaMediaTaxonomiesController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPostsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaPublicController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaSettingsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaSubscribersController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaTagsController;
use Packages\Kilkana\Http\Controllers\SnkKilkanaUsersController;

Route::middleware(['api'])->group(function () {
    Route::post('auth/login', [SnkKilkanaAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [SnkKilkanaAuthController::class, 'me']);
        Route::post('auth/logout', [SnkKilkanaAuthController::class, 'logout']);
        Route::apiResource('users', SnkKilkanaUsersController::class);
    });
});

// Lectura pública (sin auth): solo published, resuelta por slug (URLs SEO).
Route::middleware(['api'])->group(function () {
    Route::get('public/posts', [SnkKilkanaPublicController::class, 'posts']);
    Route::get('public/categories', [SnkKilkanaPublicController::class, 'categories']);
    // Una sección por slug: lo necesita quien resuelve `/{seccion}`, y es por donde entra la
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
});

Route::middleware(['api', 'auth:sanctum'])->group(function () {
    // Antes del apiResource: si no, `show` se traga «bulk» como id de post.
    Route::post('posts/bulk', [SnkKilkanaPostsController::class, 'bulk']);
    Route::apiResource('posts', SnkKilkanaPostsController::class);
    Route::get('posts/{post}/revisions', [SnkKilkanaPostsController::class, 'revisions']);
    Route::post('posts/{post}/revisions/{revision}/restore', [SnkKilkanaPostsController::class, 'restore']);
    Route::apiResource('categories', SnkKilkanaCategoriesController::class);
    Route::apiResource('authors', SnkKilkanaAuthorsController::class);
    Route::post('media/upload', [SnkKilkanaMediaController::class, 'upload']);
    Route::post('media/bulk', [SnkKilkanaMediaController::class, 'bulk']);
    Route::post('media/{id}/restore', [SnkKilkanaMediaController::class, 'restore']);
    Route::delete('media/{id}/force', [SnkKilkanaMediaController::class, 'forceDestroy']);
    Route::post('media/{id}/replace', [SnkKilkanaMediaController::class, 'replace']);
    // Sin `store`: un medio ENTRA por `upload` o por `replace`, que calculan hash, ruta y
    // dimensiones. El alta genérica no validaba nada —el controlador no declara reglas, así
    // que el base metía `$request->all()` en el modelo— y dejaba escribir `disk` y `path`,
    // que son justo lo que `Storage::delete` consume al vaciar la papelera.
    Route::apiResource('media', SnkKilkanaMediaController::class)->except('store');
    // Taxonomías de medios: tags y colecciones comparten controlador; el segmento {taxonomy} decide el modelo.
    Route::get('media-{taxonomy}', [SnkKilkanaMediaTaxonomiesController::class, 'index'])->whereIn('taxonomy', ['tags', 'collections'])->middleware('can:index media');
    Route::post('media-{taxonomy}', [SnkKilkanaMediaTaxonomiesController::class, 'store'])->whereIn('taxonomy', ['tags', 'collections'])->middleware('can:update media');
    Route::put('media-{taxonomy}/{id}', [SnkKilkanaMediaTaxonomiesController::class, 'update'])->whereIn('taxonomy', ['tags', 'collections'])->middleware('can:update media');
    Route::delete('media-{taxonomy}/{id}', [SnkKilkanaMediaTaxonomiesController::class, 'destroy'])->whereIn('taxonomy', ['tags', 'collections'])->middleware('can:update media');
    Route::apiResource('tags', SnkKilkanaTagsController::class);
    // Antes del apiResource, como en posts: si no, `show` se traga «bulk» como id.
    Route::post('comments/bulk', [SnkKilkanaCommentsController::class, 'bulk']);
    Route::apiResource('comments', SnkKilkanaCommentsController::class);

    Route::apiResource('subscribers', SnkKilkanaSubscribersController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    Route::get('layouts/{layout}/revisions', [SnkKilkanaLayoutsController::class, 'revisions'])->whereIn('layout', SnkKilkanaLayoutEnum::values())->middleware('can:show layouts');
    Route::post('layouts/{layout}/revisions/{revision}/restore', [SnkKilkanaLayoutsController::class, 'restore'])->whereIn('layout', SnkKilkanaLayoutEnum::values())->middleware('can:update layouts');
    Route::get('layouts/{layout}', [SnkKilkanaLayoutsController::class, 'show'])->whereIn('layout', SnkKilkanaLayoutEnum::values())->middleware('can:show layouts');
    Route::put('layouts/{layout}', [SnkKilkanaLayoutsController::class, 'update'])->whereIn('layout', SnkKilkanaLayoutEnum::values())->middleware('can:update layouts');

    Route::get('settings', [SnkKilkanaSettingsController::class, 'show'])->middleware('can:show settings');
    Route::put('settings', [SnkKilkanaSettingsController::class, 'update'])->middleware('can:update settings');
});
