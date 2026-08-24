<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Packages\Kilkana\Enums\SnkKilkanaThemeStatusEnum;

/**
 * El tema: la unidad vendible, y un dato entero.
 *
 * **Un tema es un `spec` y no una familia de pieles.** No hay variantes: si dos pieles no se
 * reconocen como el mismo periodico son dos temas, y si se reconocen, lo que las separa
 * —densidad, acento, tamano del titular— es lo que ajusta el inquilino sobre el tema que ya
 * lleva puesto. Un nivel intermedio obligaba a elegir dos veces antes de ver un periodico y
 * triplicaba el trabajo de disenar cada tema.
 *
 * Y el tema baja entero a la base porque ya era JSON: tokens, tipografia, rejilla, `masthead`
 * (un enum de dos valores) y las plantillas de arranque son todo dato serializable. Publicar un
 * tema no exige desplegar el frontend.
 *
 * `status` existe por el mismo motivo que en las secciones: un tema se prepara antes de
 * ofrecerlo, y retirarlo no puede romper a quien ya lo viste. Como vestirse guarda una copia
 * congelada del spec en `kilkana_settings`, `deprecated` deja de venderlo sin tocar ningun
 * periodico vivo.
 *
 * `popularity` es un numero CURADO, no medido. Con el catalogo replicado en la base de cada
 * inquilino no hay donde contar cuantos periodicos visten un tema: cada base solo se ve a si
 * misma. Medirlo de verdad exigiria un contador global en el control plane.
 *
 * `name` nulo significa «el que envia el producto» y evita congelar una traduccion concreta en
 * cada base de inquilino.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_themes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name')->nullable();
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', SnkKilkanaThemeStatusEnum::values())
                ->default(SnkKilkanaThemeStatusEnum::Draft->value);
            // El tema entero, tal y como lo dibuja el frontend. Nulo solo mientras el
            // sincronizador escribe la fila: un tema sin spec no pasa el validador.
            $table->json('spec')->nullable();
            $table->boolean('featured')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedInteger('popularity')->default(0);
            // La del fichero maestro. El sincronizador compara contra esta y no reescribe un
            // tema que no ha cambiado: en un catalogo de 300, publicar uno escribe una fila.
            $table->string('version', 16)->default('1.0.0');
            $table->string('thumbnail_path')->nullable();
            $table->json('screenshots')->nullable();
            $table->string('demo_url', 2048)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // Las dos parrillas del catalogo: la curada y la de novedades.
            $table->index(['status', 'position']);
            $table->index(['status', 'published_at']);
        });

        // El buscador del catalogo, sin motor externo — mismo patron que `kilkana_posts`,
        // guarda de driver incluida: sqlite no compila FULLTEXT y tumbaría la migración.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('kilkana_themes', function (Blueprint $table) {
                $table->fullText(['name', 'tagline', 'description']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_themes');
    }
};
