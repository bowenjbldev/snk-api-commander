<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El marco de recorte, al lado del punto de interés y con la misma forma.
 *
 * Son las dos mitades de una sola decisión y estaban en sitios distintos: el punto vivía en
 * la fila desde el primer día y el marco solo en la memoria del panel, así que acotar una
 * foto y recargar la deshacía sin decir nada. Fracciones del original, no píxeles: el
 * fichero se puede reemplazar por uno de otro tamaño y el encuadre sigue significando lo
 * mismo.
 *
 * Por defecto la foto entera, que es lo que se publica mientras nadie acote nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->decimal('crop_x', 5, 4)->default(0)->after('focal_y');
            $table->decimal('crop_y', 5, 4)->default(0)->after('crop_x');
            $table->decimal('crop_w', 5, 4)->default(1)->after('crop_y');
            $table->decimal('crop_h', 5, 4)->default(1)->after('crop_w');
        });
    }

    public function down(): void
    {
        Schema::table('kilkana_media', function (Blueprint $table) {
            $table->dropColumn(['crop_x', 'crop_y', 'crop_w', 'crop_h']);
        });
    }
};
