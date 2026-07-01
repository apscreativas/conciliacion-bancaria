<?php

use App\Jobs\GenerateProfitLossPdfJob;
use App\Models\Archivo;
use App\Models\Categoria;
use App\Models\Conciliacion;
use App\Models\Egreso;
use App\Models\Empresa;
use App\Models\ExportRequest;
use App\Models\Factura;
use App\Models\IngresoManual;
use App\Models\Movimiento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

// ── Helpers de siembra (team_id/user_id explícitos: funcionan con o sin auth). ──

function execConciliacion(int $teamId, int $userId, ?int $empresaId, float $montoAplicado, string $movFecha): Conciliacion
{
    $archivoMov = Archivo::factory()->create(['team_id' => $teamId, 'user_id' => $userId]);
    $movimiento = Movimiento::factory()->create([
        'team_id' => $teamId, 'user_id' => $userId, 'file_id' => $archivoMov->id,
        'fecha' => $movFecha, 'tipo' => 'abono', 'monto' => 99999,
    ]);

    $archivoFac = Archivo::factory()->create(['team_id' => $teamId, 'user_id' => $userId]);
    $factura = Factura::factory()->create([
        'team_id' => $teamId, 'user_id' => $userId, 'file_id_xml' => $archivoFac->id, 'monto' => 88888,
    ]);

    return Conciliacion::create([
        'team_id' => $teamId, 'user_id' => $userId, 'empresa_id' => $empresaId,
        'factura_id' => $factura->id, 'movimiento_id' => $movimiento->id,
        'monto_aplicado' => $montoAplicado, 'estatus' => 'conciliado', 'tipo' => 'automatico',
        'fecha_conciliacion' => '2026-06-15',
    ]);
}

function execIngresoManual(int $teamId, int $userId, ?int $empresaId, float $monto, string $fecha): IngresoManual
{
    $cat = Categoria::factory()->ingreso()->create(['team_id' => $teamId]);

    return IngresoManual::factory()->create([
        'team_id' => $teamId, 'user_id' => $userId, 'empresa_id' => $empresaId,
        'categoria_id' => $cat->id, 'monto' => $monto, 'fecha' => $fecha,
    ]);
}

function execEgreso(int $teamId, int $userId, ?int $empresaId, string $grupo, float $monto, string $fecha): Egreso
{
    $cat = Categoria::factory()->create(['team_id' => $teamId, 'grupo' => $grupo]);

    return Egreso::factory()->create([
        'team_id' => $teamId, 'user_id' => $userId, 'empresa_id' => $empresaId,
        'categoria_id' => $cat->id, 'monto' => $monto, 'fecha' => $fecha,
    ]);
}

/** Crea un miembro no-owner del team del owner. */
function execMiembroDe(User $owner): User
{
    $member = User::factory()->create();
    $member->forceFill(['current_team_id' => $owner->current_team_id])->saveQuietly();

    return $member;
}

// ─────────────────────────────────────────────────────────────────────────────

it('renders the executive dashboard for the owner with matching P&L figures', function () {
    $owner = User::factory()->create();
    $teamId = $owner->current_team_id;
    $empresa = Empresa::factory()->create(['team_id' => $teamId]);

    execConciliacion($teamId, $owner->id, $empresa->id, 5000, '2026-06-10'); // bancario
    execIngresoManual($teamId, $owner->id, $empresa->id, 2000, '2026-06-08'); // manual
    execEgreso($teamId, $owner->id, $empresa->id, 'costo_venta', 1000, '2026-06-05');
    // ingresos.total = 7000; egresos_total = 1000; utilidad_neta = 6000

    actingAs($owner)->get(route('executive', ['month' => 6, 'year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Executive/Index')
            ->has('pnl')
            ->has('pnlPrev')
            ->has('pnlYoY')
            ->has('porEmpresa', 1)
            ->has('empresas', 1)
            // Analítica temporal (Dashboard v2, BLOQUE 2).
            ->has('series')
            ->has('ingresoEmpresaSeries')
            ->has('egresosPorCategoria')
            ->has('egresosPorNaturaleza')
            ->has('topProveedores')
            ->has('nominaRollup')
            ->where('filters.months', 12)
            // Inertia serializa floats enteros como int en JSON → comparar con int.
            ->where('pnl.ingresos.total', 7000)
            ->where('pnl.utilidad_neta', 6000)
        );
});

it('normalizes an invalid months window to the default of 12', function () {
    $owner = User::factory()->create();

    actingAs($owner)->get(route('executive', ['month' => 6, 'year' => 2026, 'months' => 99]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.months', 12)
        );
});

it('accepts a 6-month trend window', function () {
    $owner = User::factory()->create();

    actingAs($owner)->get(route('executive', ['month' => 6, 'year' => 2026, 'months' => 6]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.months', 6)
            ->has('series', 6)
        );
});

it('forbids a non-owner member from viewing the executive dashboard', function () {
    $owner = User::factory()->create();
    $member = execMiembroDe($owner);

    actingAs($member)->get(route('executive', ['month' => 6, 'year' => 2026]))
        ->assertForbidden();
});

it('forbids a non-owner member from exporting', function () {
    $owner = User::factory()->create();
    $member = execMiembroDe($owner);

    actingAs($member)->getJson(route('executive.export', ['month' => 6, 'year' => 2026]))
        ->assertForbidden();
});

it('isolates P&L from other teams', function () {
    // Team ajeno con montos grandes.
    $other = User::factory()->create();
    execConciliacion($other->current_team_id, $other->id, null, 99000, '2026-06-10');
    execIngresoManual($other->current_team_id, $other->id, null, 88000, '2026-06-08');

    // Owner del team objetivo con un solo ingreso.
    $owner = User::factory()->create();
    execIngresoManual($owner->current_team_id, $owner->id, null, 1500, '2026-06-09');

    actingAs($owner)->get(route('executive', ['month' => 6, 'year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pnl.ingresos.total', 1500)
            ->where('pnl.ingresos.bancario_conciliado', 0)
        );
});

it('creates a pl_pdf ExportRequest and dispatches the job on export', function () {
    Queue::fake();

    $owner = User::factory()->create();

    actingAs($owner)->getJson(route('executive.export', [
        'granularidad' => 'mensual', 'month' => 6, 'year' => 2026,
    ]))->assertOk()->assertJson(['status' => 'queued']);

    $export = ExportRequest::withoutGlobalScopes()->where('team_id', $owner->current_team_id)->first();
    expect($export)->not->toBeNull()
        ->and($export->type)->toBe('pl_pdf')
        ->and($export->filters['team_id'])->toBe($owner->current_team_id)
        ->and($export->filters['granularidad'])->toBe('mensual');

    Queue::assertPushed(GenerateProfitLossPdfJob::class);
});

it('returns export status for the owner and forbids non-owner members', function () {
    $owner = User::factory()->create();
    $export = ExportRequest::create([
        'team_id' => $owner->current_team_id, 'user_id' => $owner->id,
        'type' => 'pl_pdf', 'status' => 'queued', 'filters' => [],
    ]);

    actingAs($owner)->getJson(route('executive.export.status', $export->id))
        ->assertOk()->assertJson(['status' => 'queued']);

    $member = execMiembroDe($owner);
    actingAs($member)->getJson(route('executive.export.status', $export->id))
        ->assertForbidden();
});

it('downloads a completed export and 404s when not ready', function () {
    Storage::fake();

    $owner = User::factory()->create();

    $completed = ExportRequest::create([
        'team_id' => $owner->current_team_id, 'user_id' => $owner->id,
        'type' => 'pl_pdf', 'status' => 'completed',
        'file_path' => 'exports/test.pdf', 'file_name' => 'estado_resultados.pdf', 'filters' => [],
    ]);
    Storage::put('exports/test.pdf', '%PDF-1.4 fake');

    actingAs($owner)->get(route('executive.export.download', $completed->id))->assertOk();

    $queued = ExportRequest::create([
        'team_id' => $owner->current_team_id, 'user_id' => $owner->id,
        'type' => 'pl_pdf', 'status' => 'queued', 'filters' => [],
    ]);
    actingAs($owner)->get(route('executive.export.download', $queued->id))->assertNotFound();
});

it('generates the PDF and marks the export completed (job)', function () {
    Storage::fake();

    $owner = User::factory()->create();
    $teamId = $owner->current_team_id;
    execIngresoManual($teamId, $owner->id, null, 1000, '2026-06-09');

    $export = ExportRequest::create([
        'team_id' => $teamId, 'user_id' => $owner->id, 'type' => 'pl_pdf', 'status' => 'queued',
        'filters' => ['granularidad' => 'mensual', 'empresa_id' => null, 'month' => 6, 'year' => 2026, 'team_id' => $teamId],
    ]);

    (new GenerateProfitLossPdfJob($export))->handle(
        app(\App\Services\Finance\PeriodResolver::class),
        app(\App\Services\Finance\ProfitLossService::class),
        app(\App\Services\Finance\FinanceAnalyticsService::class),
    );

    $export->refresh();
    expect($export->status)->toBe('completed')
        ->and($export->file_path)->not->toBeNull();
    Storage::assertExists($export->file_path);
});
