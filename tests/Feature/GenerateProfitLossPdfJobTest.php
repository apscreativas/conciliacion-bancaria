<?php

use App\Jobs\GenerateProfitLossPdfJob;
use App\Models\Categoria;
use App\Models\Egreso;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\ExportRequest;
use App\Models\IngresoManual;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Smoke del job de PDF del Estado de Resultados con la analítica temporal (Dashboard v2,
 * BLOQUE 3). Verifica que corre en cola (sin auth, team_id explícito), termina en
 * `completed` y genera un PDF; y que la Blade con las tablas de serie/desgloses NO
 * truena por variable indefinida cuando SÍ hay datos.
 */
it('genera el PDF con serie mensual y desgloses en tablas (job queue-safe)', function () {
    Storage::fake();

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $empresa = Empresa::factory()->create(['team_id' => $team->id]);
    $cv = Categoria::factory()->create([
        'team_id' => $team->id,
        'tipo' => 'egreso',
        'grupo' => 'costo_venta',
        'naturaleza' => 'variable',
        'nombre' => 'Materiales',
    ]);
    $empleado = Empleado::factory()->create(['team_id' => $team->id, 'empresa_id' => $empresa->id]);

    // Datos en junio 2026 (el mes ancla del export).
    IngresoManual::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'empresa_id' => $empresa->id,
        'categoria_id' => Categoria::factory()->ingreso()->create(['team_id' => $team->id])->id,
        'monto' => 5000,
        'fecha' => '2026-06-10',
    ]);
    Egreso::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'empresa_id' => $empresa->id,
        'categoria_id' => $cv->id,
        'monto' => 2000,
        'fecha' => '2026-06-05',
        'proveedor' => 'CFE',
    ]);
    Egreso::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'empresa_id' => $empresa->id,
        'categoria_id' => null,
        'monto' => 3000,
        'fecha' => '2026-06-08',
        'empleado_id' => $empleado->id,
        'concepto_nomina' => 'fiscal',
    ]);

    $exportRequest = ExportRequest::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'type' => 'pl_pdf',
        'status' => 'queued',
        'file_path' => null,
        'filters' => [
            'granularidad' => 'mensual',
            'empresa_id' => null,
            'month' => 6,
            'year' => 2026,
            'months' => 12,
            'team_id' => $team->id,
        ],
    ]);

    // Sin actingAs → replica la cola: el global scope de TeamOwned está inactivo.
    GenerateProfitLossPdfJob::dispatchSync($exportRequest);

    $exportRequest->refresh();

    expect($exportRequest->status)->toBe('completed')
        ->and($exportRequest->file_path)->not->toBeNull()
        ->and($exportRequest->file_name)->toBe('estado_resultados_2026_6.pdf');

    Storage::assertExists($exportRequest->file_path);
    expect(strlen(Storage::get($exportRequest->file_path)))->toBeGreaterThan(0);
});

it('genera el PDF sin datos sin truncar la Blade (secciones vacías)', function () {
    Storage::fake();

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $exportRequest = ExportRequest::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'type' => 'pl_pdf',
        'status' => 'queued',
        'file_path' => null,
        'filters' => [
            'granularidad' => 'mensual',
            'empresa_id' => null,
            'month' => 6,
            'year' => 2026,
            'months' => 6,
            'team_id' => $team->id,
        ],
    ]);

    GenerateProfitLossPdfJob::dispatchSync($exportRequest);

    $exportRequest->refresh();

    expect($exportRequest->status)->toBe('completed');
    Storage::assertExists($exportRequest->file_path);
});
