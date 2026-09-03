<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El boletín: los números y lo que cada suscriptor hizo con cada uno.
 *
 * Un número tiene dos estados y ninguno intermedio —borrador y enviado—: no hay marcha atrás
 * desde ninguno de los dos y fingir «enviando» o «programado» diría que la hay. Lo que va
 * dentro es una lista de piezas fijada al enviar (`post_ids`), y `recipients_count` es a
 * cuántos salió ese día, no cuántos hay hoy.
 *
 * Los eventos son una fila por suscriptor y hecho —se le entregó, abrió, pulsó tal pieza, se
 * dio de baja desde este número—, únicos, para que quien abre veinte veces cuente una: lo que
 * se mide es cuánta gente, no cuántas veces. `sent` es lo que hace idempotente la entrega: una
 * tanda que se reintente no vuelve a mandar a quien ya recibió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kilkana_newsletter_issues', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 200);
            $table->string('preheader', 300)->nullable();
            $table->string('status', 16)->default('draft');
            $table->json('post_ids')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->string('test_sent_to', 255)->nullable();
            $table->timestamp('test_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'sent_at']);
        });

        Schema::create('kilkana_newsletter_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained('kilkana_newsletter_issues')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained('kilkana_subscribers')->cascadeOnDelete();
            $table->string('kind', 12);
            // Cero y no nulo: dos nulos no chocan en un índice único, y sin choque la unicidad
            // no decide nada para abrir ni para darse de baja. Cero es «sin pieza».
            $table->unsignedBigInteger('post_id')->default(0);
            $table->timestamp('created_at');
            $table->unique(['issue_id', 'subscriber_id', 'kind', 'post_id'], 'kilkana_nl_events_once');
            $table->index(['issue_id', 'kind'], 'kilkana_nl_events_issue_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kilkana_newsletter_events');
        Schema::dropIfExists('kilkana_newsletter_issues');
    }
};
