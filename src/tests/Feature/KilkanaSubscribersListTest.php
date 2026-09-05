<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Packages\Core\Models\CoreUserModel;
use Packages\Kilkana\Http\Controllers\SnkKilkanaSubscribersController;
use Packages\Kilkana\Models\SnkKilkanaSubscriberModel;
use Packages\Kilkana\Services\SnkKilkanaRoleCatalogService;
use Tests\TestCase;

/**
 * La lista entra y sale como fichero. Lo que se fija: lo importado queda marcado `import`,
 * quien ya estaba no se toca —una baja no vuelve a alta por venir en la lista vieja—, una
 * dirección que no lo es se cuenta y no rompe, y sin declarar el consentimiento no entra nada.
 */
class KilkanaSubscribersListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::setDefaultConnection('tenant');
        foreach (['Core', 'Kilkana'] as $paquete) {
            $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => "packages/{$paquete}/database/migrations",
            ])->run();
        }
        app(SnkKilkanaRoleCatalogService::class)->sync();

        $marta = CoreUserModel::create(['name' => 'Marta', 'email' => 'marta@eldiario.example', 'password' => 'secret1234']);
        $marta->assignRole('director');
        $this->actingAs($marta, 'sanctum');
    }

    public function test_importar_marca_el_origen_salta_a_quien_ya_esta_y_cuenta_lo_invalido(): void
    {
        SnkKilkanaSubscriberModel::create(['email' => 'baja@lectores.example', 'status' => 'unsubscribed', 'consent_source' => 'public_form']);

        $csv = "email,status,consent_at\n"
            ."Ana@Lectores.example,confirmed,2025-03-01T10:00:00+00:00\n"
            ."baja@lectores.example,confirmed,\n"
            ."sin-arroba,confirmed,\n"
            ."pepe@lectores.example,pending,\n"
            ."ana@lectores.example,confirmed,\n";

        $resultado = $this->importar($csv)->getData(true);

        $this->assertSame(['imported' => 2, 'skipped' => 1, 'invalid' => 1], $resultado);
        $ana = SnkKilkanaSubscriberModel::query()->where('email', 'ana@lectores.example')->firstOrFail();
        $this->assertSame('import', $ana->consent_source->value);
        $this->assertSame('confirmed', $ana->status->value);
        $this->assertSame('2025-03-01 10:00:00', $ana->consent_at->toDateTimeString());
        $this->assertSame('pending', SnkKilkanaSubscriberModel::query()->where('email', 'pepe@lectores.example')->value('status')->value);
        $this->assertSame('unsubscribed', SnkKilkanaSubscriberModel::query()->where('email', 'baja@lectores.example')->value('status')->value, 'Una baja no vuelve por venir en la lista.');
        $this->assertSame(1, DB::table('kilkana_activity')->where('verb', 'imported subscribers')->count());
    }

    public function test_sin_cabecera_vale_y_sin_consentimiento_declarado_no_entra_nada(): void
    {
        $this->importar("uno@lectores.example\ndos@lectores.example\n");
        $this->assertSame(2, SnkKilkanaSubscriberModel::query()->where('consent_source', 'import')->count());

        $this->expectException(ValidationException::class);
        $this->importar("tres@lectores.example\n", consented: false);
    }

    public function test_exportar_lleva_correo_estado_origen_y_sello(): void
    {
        SnkKilkanaSubscriberModel::create(['email' => 'ana@lectores.example', 'status' => 'confirmed', 'consent_source' => 'public_form', 'consent_at' => '2026-01-02 03:04:05']);

        $csv = app(SnkKilkanaSubscribersController::class)->export()->getContent();

        $this->assertStringStartsWith("email,status,consent_source,consent_at\n", $csv);
        $this->assertStringContainsString('ana@lectores.example,confirmed,public_form,2026-01-02T03:04:05', $csv);

        // Una dirección que empieza como una fórmula sale como texto, no como fórmula.
        SnkKilkanaSubscriberModel::create(['email' => '=cmd@lectores.example', 'status' => 'confirmed', 'consent_source' => 'public_form']);
        $this->assertStringContainsString("'=cmd@lectores.example,", app(SnkKilkanaSubscribersController::class)->export()->getContent());
    }

    private function importar(string $csv, bool $consented = true)
    {
        $request = Request::create('/', 'POST', $consented ? ['consented' => '1'] : [], [], [
            'file' => UploadedFile::fake()->createWithContent('lista.csv', $csv),
        ]);

        return app(SnkKilkanaSubscribersController::class)->import($request);
    }
}
