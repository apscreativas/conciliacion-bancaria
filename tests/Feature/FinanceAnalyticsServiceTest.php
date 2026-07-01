<?php

use App\Models\Archivo;
use App\Models\Categoria;
use App\Models\Conciliacion;
use App\Models\Egreso;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\IngresoManual;
use App\Models\Movimiento;
use App\Models\User;
use App\Services\Finance\FinanceAnalyticsService;
use App\Services\Finance\PeriodResolver;
use App\Services\Finance\ProfitLossService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers de siembra (mismo patrón que ProfitLossServiceTest: team_id/user_id
// explícitos → funcionan con o sin auth, queue-safe).
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Conciliación enlazando movimiento (fechado) + factura. Los `monto` de
 * movimiento/factura se siembran ABSURDOS a propósito: el ingreso bancario debe
 * sumar SOLO `conciliacions.monto_aplicado`.
 */
function faConciliacion(int $teamId, ?int $userId, ?int $empresaId, float $montoAplicado, string $movFecha, string $tipo = 'abono', string $estatus = 'conciliado'): Conciliacion
{
    $archivoMov = Archivo::factory()->create(['team_id' => $teamId, 'user_id' => $userId]);
    $movimiento = Movimiento::factory()->create([
        'team_id' => $teamId,
        'user_id' => $userId,
        'file_id' => $archivoMov->id,
        'fecha' => $movFecha,
        'tipo' => $tipo,
        'monto' => 99999,
    ]);

    $archivoFac = Archivo::factory()->create(['team_id' => $teamId, 'user_id' => $userId]);
    $factura = Factura::factory()->create([
        'team_id' => $teamId,
        'user_id' => $userId,
        'file_id_xml' => $archivoFac->id,
        'monto' => 88888,
    ]);

    return Conciliacion::create([
        'team_id' => $teamId,
        'user_id' => $userId,
        'empresa_id' => $empresaId,
        'factura_id' => $factura->id,
        'movimiento_id' => $movimiento->id,
        'monto_aplicado' => $montoAplicado,
        'estatus' => $estatus,
        'tipo' => 'automatico',
        'fecha_conciliacion' => $movFecha,
    ]);
}

/** Categoría de egreso con grupo y naturaleza explícitos. */
function faCategoria(int $teamId, string $grupo, ?string $naturaleza, string $nombre): Categoria
{
    return Categoria::factory()->create([
        'team_id' => $teamId,
        'tipo' => 'egreso',
        'grupo' => $grupo,
        'naturaleza' => $naturaleza,
        'nombre' => $nombre,
    ]);
}

/** Egreso fijo. `$extra` permite proveedor/empleado_id/concepto_nomina. */
function faEgreso(int $teamId, ?int $userId, ?int $empresaId, ?int $categoriaId, float $monto, string $fecha, array $extra = []): Egreso
{
    return Egreso::factory()->create(array_merge([
        'team_id' => $teamId,
        'user_id' => $userId,
        'empresa_id' => $empresaId,
        'categoria_id' => $categoriaId,
        'monto' => $monto,
        'fecha' => $fecha,
    ], $extra));
}

/** Ingreso manual (efectivo). */
function faIngresoManual(int $teamId, ?int $userId, ?int $empresaId, float $monto, string $fecha): IngresoManual
{
    $cat = Categoria::factory()->ingreso()->create(['team_id' => $teamId]);

    return IngresoManual::factory()->create([
        'team_id' => $teamId,
        'user_id' => $userId,
        'empresa_id' => $empresaId,
        'categoria_id' => $cat->id,
        'monto' => $monto,
        'fecha' => $fecha,
    ]);
}

function faService(): FinanceAnalyticsService
{
    return new FinanceAnalyticsService(new ProfitLossService, new PeriodResolver);
}

/**
 * Siembra 3 meses fijos (abr/may/jun 2026) para 2 empresas del team A, más ruido
 * fuera de la ventana (marzo, julio) y un team B ajeno con montos grandes.
 *
 * Derivaciones por mes (todas a mano):
 *
 *  ABRIL: bancario 4000(A)+2000(B)=6000; manual 1000(A)=1000; ingresos=7000
 *         egresos: CV 2000(A); OP 1000(A) → egresos_total=3000
 *         bruta=7000-2000=5000; ebitda=5000-1000=4000; neta=4000
 *  MAYO:  bancario 3000(A)+3000(B)=6000; manual 2000(B)=2000; ingresos=8000
 *         egresos: CV 2500(B); ABAJO 500(A); sin-cat 300 → egresos_total=3300
 *         bruta=8000-2500=5500; ebitda=5500-0=5500; neta=5500-500-300=4700
 *  JUNIO: bancario 5000(A)+3000(A)+2000(B)+1000(null)=11000; manual 1500(A)+500(B)+250(null)=2250; ingresos=13250
 *         egresos: CV 3000(A)+1500(B)=4500; OP 1000(A)+500(null)=1500; ABAJO 800(B); sin-cat 200 → egresos_total=7000
 *         bruta=13250-4500=8750; ebitda=8750-1500=7250; neta=7250-800-200=6250
 *
 * @return array{0: User, 1: \App\Models\Team, 2: Empresa, 3: Empresa, 4: \App\Models\Team}
 */
function faSeedThreeMonths(bool $withAuth = true): array
{
    $userA = User::factory()->create();
    $teamA = $userA->currentTeam;
    if ($withAuth) {
        actingAs($userA);
    }

    $empA = Empresa::factory()->create(['team_id' => $teamA->id, 'color' => '#111111']);
    $empB = Empresa::factory()->create(['team_id' => $teamA->id, 'color' => '#222222']);

    $cv = faCategoria($teamA->id, 'costo_venta', 'variable', 'Costo materiales');
    $op = faCategoria($teamA->id, 'gasto_operativo', 'fijo', 'Renta');
    $ab = faCategoria($teamA->id, 'abajo_ebitda', 'fijo', 'Intereses');

    // ── ABRIL 2026
    faConciliacion($teamA->id, $userA->id, $empA->id, 4000, '2026-04-10');
    faConciliacion($teamA->id, $userA->id, $empB->id, 2000, '2026-04-12');
    faIngresoManual($teamA->id, $userA->id, $empA->id, 1000, '2026-04-08');
    faEgreso($teamA->id, $userA->id, $empA->id, $cv->id, 2000, '2026-04-05');
    faEgreso($teamA->id, $userA->id, $empA->id, $op->id, 1000, '2026-04-06');

    // ── MAYO 2026
    faConciliacion($teamA->id, $userA->id, $empA->id, 3000, '2026-05-10');
    faConciliacion($teamA->id, $userA->id, $empB->id, 3000, '2026-05-15');
    faIngresoManual($teamA->id, $userA->id, $empB->id, 2000, '2026-05-09');
    faEgreso($teamA->id, $userA->id, $empB->id, $cv->id, 2500, '2026-05-04');
    faEgreso($teamA->id, $userA->id, $empA->id, $ab->id, 500, '2026-05-11');
    faEgreso($teamA->id, $userA->id, null, null, 300, '2026-05-13'); // sin categoría → sin_clasificar

    // ── JUNIO 2026
    faConciliacion($teamA->id, $userA->id, $empA->id, 5000, '2026-06-10');
    faConciliacion($teamA->id, $userA->id, $empA->id, 3000, '2026-06-20');
    faConciliacion($teamA->id, $userA->id, $empB->id, 2000, '2026-06-15');
    faConciliacion($teamA->id, $userA->id, null, 1000, '2026-06-05');
    faIngresoManual($teamA->id, $userA->id, $empA->id, 1500, '2026-06-08');
    faIngresoManual($teamA->id, $userA->id, $empB->id, 500, '2026-06-09');
    faIngresoManual($teamA->id, $userA->id, null, 250, '2026-06-12');
    faEgreso($teamA->id, $userA->id, $empA->id, $cv->id, 3000, '2026-06-03');
    faEgreso($teamA->id, $userA->id, $empB->id, $cv->id, 1500, '2026-06-04');
    faEgreso($teamA->id, $userA->id, $empA->id, $op->id, 1000, '2026-06-06');
    faEgreso($teamA->id, $userA->id, null, $op->id, 500, '2026-06-07');
    faEgreso($teamA->id, $userA->id, $empB->id, $ab->id, 800, '2026-06-11');
    faEgreso($teamA->id, $userA->id, null, null, 200, '2026-06-13');

    // ── Ruido fuera de la ventana (marzo y julio) → NO debe aparecer
    faConciliacion($teamA->id, $userA->id, $empA->id, 99999, '2026-03-10');
    faConciliacion($teamA->id, $userA->id, $empA->id, 88888, '2026-07-10');
    faEgreso($teamA->id, $userA->id, $empA->id, $cv->id, 77777, '2026-03-05');
    faEgreso($teamA->id, $userA->id, $empA->id, $cv->id, 66666, '2026-07-05');

    // ── Team B ajeno (montos grandes que NO deben filtrarse al team A)
    $userB = User::factory()->create();
    $teamB = $userB->currentTeam;
    $empBteam = Empresa::factory()->create(['team_id' => $teamB->id]);
    $cvB = faCategoria($teamB->id, 'costo_venta', 'variable', 'Costo B');
    faConciliacion($teamB->id, $userB->id, $empBteam->id, 500000, '2026-06-10');
    faIngresoManual($teamB->id, $userB->id, $empBteam->id, 400000, '2026-06-12');
    faEgreso($teamB->id, $userB->id, $empBteam->id, $cvB->id, 300000, '2026-06-05');

    // Re-fija el actor al team A (faIngresoManual/faConciliacion del team B usaron team_id explícito).
    if ($withAuth) {
        actingAs($userA);
    }

    return [$userA, $teamA, $empA, $empB, $teamB];
}

// ─────────────────────────────────────────────────────────────────────────────
// monthlySeries
// ─────────────────────────────────────────────────────────────────────────────
it('builds the monthly series in ascending chronological order down to the cent', function () {
    [$userA, $teamA] = faSeedThreeMonths();

    $series = faService()->monthlySeries(2026, 6, 3, null, $teamA->id);

    // Exactamente 3 meses, orden ascendente; marzo/julio (fuera de ventana) NO aparecen.
    expect($series)->toHaveCount(3)
        ->and(array_column($series, 'label'))->toBe(['2026-04', '2026-05', '2026-06']);

    // ── ABRIL
    expect($series[0]['year'])->toBe(2026)
        ->and($series[0]['month'])->toBe(4)
        ->and($series[0]['ingresos_total'])->toBe(7000.0)      // 6000 + 1000
        ->and($series[0]['ingresos_bancario'])->toBe(6000.0)   // 4000 + 2000
        ->and($series[0]['ingresos_manual'])->toBe(1000.0)
        ->and($series[0]['egresos_total'])->toBe(3000.0)       // 2000 + 1000
        ->and($series[0]['costo_venta'])->toBe(2000.0)
        ->and($series[0]['gasto_operativo'])->toBe(1000.0)
        ->and($series[0]['abajo_ebitda'])->toBe(0.0)
        ->and($series[0]['sin_clasificar'])->toBe(0.0)
        ->and($series[0]['utilidad_bruta'])->toBe(5000.0)      // 7000 - 2000
        ->and($series[0]['ebitda'])->toBe(4000.0)              // 5000 - 1000
        ->and($series[0]['utilidad_neta'])->toBe(4000.0)
        ->and($series[0]['margen_bruto'])->toBe(0.7143)        // 5000/7000
        ->and($series[0]['margen_ebitda'])->toBe(0.5714)       // 4000/7000
        ->and($series[0]['margen_neto'])->toBe(0.5714);

    // ── MAYO
    expect($series[1]['month'])->toBe(5)
        ->and($series[1]['ingresos_total'])->toBe(8000.0)      // 6000 + 2000
        ->and($series[1]['egresos_total'])->toBe(3300.0)       // 2500 + 500 + 300
        ->and($series[1]['costo_venta'])->toBe(2500.0)
        ->and($series[1]['gasto_operativo'])->toBe(0.0)
        ->and($series[1]['abajo_ebitda'])->toBe(500.0)
        ->and($series[1]['sin_clasificar'])->toBe(300.0)
        ->and($series[1]['utilidad_bruta'])->toBe(5500.0)      // 8000 - 2500
        ->and($series[1]['ebitda'])->toBe(5500.0)              // 5500 - 0
        ->and($series[1]['utilidad_neta'])->toBe(4700.0)       // 5500 - 500 - 300
        ->and($series[1]['margen_bruto'])->toBe(0.6875)        // 5500/8000
        ->and($series[1]['margen_neto'])->toBe(0.5875);        // 4700/8000

    // ── JUNIO
    expect($series[2]['month'])->toBe(6)
        ->and($series[2]['ingresos_total'])->toBe(13250.0)     // 11000 + 2250
        ->and($series[2]['ingresos_bancario'])->toBe(11000.0)
        ->and($series[2]['ingresos_manual'])->toBe(2250.0)
        ->and($series[2]['egresos_total'])->toBe(7000.0)       // 4500 + 1500 + 800 + 200
        ->and($series[2]['costo_venta'])->toBe(4500.0)
        ->and($series[2]['gasto_operativo'])->toBe(1500.0)
        ->and($series[2]['abajo_ebitda'])->toBe(800.0)
        ->and($series[2]['sin_clasificar'])->toBe(200.0)
        ->and($series[2]['utilidad_bruta'])->toBe(8750.0)      // 13250 - 4500
        ->and($series[2]['ebitda'])->toBe(7250.0)              // 8750 - 1500
        ->and($series[2]['utilidad_neta'])->toBe(6250.0)       // 7250 - 800 - 200
        ->and($series[2]['margen_bruto'])->toBe(0.6604)        // 8750/13250
        ->and($series[2]['margen_ebitda'])->toBe(0.5472)       // 7250/13250
        ->and($series[2]['margen_neto'])->toBe(0.4717);        // 6250/13250
});

it('respects the empresa filter in the monthly series', function () {
    [$userA, $teamA, $empA] = faSeedThreeMonths();

    // Solo empresa A. JUNIO A: bancario 5000+3000=8000; manual 1500; ingresos=9500
    // egresos A jun: CV 3000; OP 1000 → egresos_total=4000; neta=9500-3000-1000=5500
    $series = faService()->monthlySeries(2026, 6, 3, $empA->id, $teamA->id);

    expect($series[2]['ingresos_total'])->toBe(9500.0)
        ->and($series[2]['costo_venta'])->toBe(3000.0)
        ->and($series[2]['gasto_operativo'])->toBe(1000.0)
        ->and($series[2]['egresos_total'])->toBe(4000.0)
        ->and($series[2]['utilidad_neta'])->toBe(5500.0);
});

it('isolates the monthly series by explicit team_id even without auth (queue-safe)', function () {
    // Sin actingAs: el global scope de TeamOwned está inactivo; el team_id explícito aísla.
    [$userA, $teamA] = faSeedThreeMonths(withAuth: false);

    $series = faService()->monthlySeries(2026, 6, 3, null, $teamA->id);

    // Los 500000/400000/300000 del team B NO se filtran.
    expect($series[2]['ingresos_total'])->toBe(13250.0)
        ->and($series[2]['egresos_total'])->toBe(7000.0)
        ->and($series[2]['utilidad_neta'])->toBe(6250.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Invariante de consistencia: sum(serie) == forPeriod(rango completo)
// ─────────────────────────────────────────────────────────────────────────────
it('sums the monthly series to exactly the full-range P&L (no double counting)', function () {
    [$userA, $teamA] = faSeedThreeMonths();

    $series = faService()->monthlySeries(2026, 6, 3, null, $teamA->id);

    $sumIngresos = array_sum(array_column($series, 'ingresos_total'));   // 7000 + 8000 + 13250 = 28250
    $sumEgresos = array_sum(array_column($series, 'egresos_total'));     // 3000 + 3300 + 7000 = 13300
    $sumNeta = array_sum(array_column($series, 'utilidad_neta'));        // 4000 + 4700 + 6250 = 14950

    $full = (new ProfitLossService)->forPeriod(
        Carbon::parse('2026-04-01'),
        Carbon::parse('2026-06-30'),
        null,
        $teamA->id,
    );

    expect(round($sumIngresos, 2))->toBe(28250.0)
        ->and(round($sumEgresos, 2))->toBe(13300.0)
        ->and(round($sumNeta, 2))->toBe(14950.0)
        ->and($full['ingresos']['total'])->toBe(round($sumIngresos, 2))
        ->and($full['egresos_total'])->toBe(round($sumEgresos, 2))
        ->and($full['utilidad_neta'])->toBe(round($sumNeta, 2));
});

// ─────────────────────────────────────────────────────────────────────────────
// ingresoPorEmpresaMensual
// ─────────────────────────────────────────────────────────────────────────────
it('splits monthly income by empresa (with unassigned bucket) and resolves names/colors', function () {
    [$userA, $teamA, $empA, $empB] = faSeedThreeMonths();

    $rows = faService()->ingresoPorEmpresaMensual(2026, 6, 3, $teamA->id);

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'label'))->toBe(['2026-04', '2026-05', '2026-06']);

    // ── ABRIL: A = 4000(banc)+1000(man)=5000; B = 2000; sin_asignar 0
    expect($rows[0]['empresas'])->toHaveCount(2)
        ->and($rows[0]['empresas'][0])->toMatchArray([
            'empresa_id' => $empA->id,
            'nombre' => $empA->nombre,
            'color' => '#111111',
            'total' => 5000.0,
        ])
        ->and($rows[0]['empresas'][1])->toMatchArray([
            'empresa_id' => $empB->id,
            'color' => '#222222',
            'total' => 2000.0,
        ])
        ->and($rows[0]['sin_asignar'])->toBe(0.0);

    // ── MAYO: A = 3000; B = 3000(banc)+2000(man)=5000; sin_asignar 0
    expect($rows[1]['empresas'][0]['total'])->toBe(3000.0)     // A
        ->and($rows[1]['empresas'][1]['total'])->toBe(5000.0)  // B
        ->and($rows[1]['sin_asignar'])->toBe(0.0);

    // ── JUNIO: A = (5000+3000)+1500=9500; B = 2000+500=2500; sin_asignar = 1000+250=1250
    expect($rows[2]['empresas'][0]['total'])->toBe(9500.0)     // A
        ->and($rows[2]['empresas'][1]['total'])->toBe(2500.0)  // B
        ->and($rows[2]['sin_asignar'])->toBe(1250.0);

    // Cada mes cuadra con ingresos_total del P&L (A + B + sin_asignar).
    expect($rows[2]['empresas'][0]['total'] + $rows[2]['empresas'][1]['total'] + $rows[2]['sin_asignar'])
        ->toBe(13250.0);
});

it('isolates ingresoPorEmpresaMensual by explicit team_id without auth', function () {
    [$userA, $teamA] = faSeedThreeMonths(withAuth: false);

    $rows = faService()->ingresoPorEmpresaMensual(2026, 6, 3, $teamA->id);

    // El team B (500000/400000) no debe aparecer: junio consolidado sigue 13250.
    $junio = collect($rows[2]['empresas'])->sum('total') + $rows[2]['sin_asignar'];
    expect($junio)->toBe(13250.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// egresosPorCategoria + egresosPorNaturaleza (mismo seed enfocado en junio)
// ─────────────────────────────────────────────────────────────────────────────
it('breaks down egresos by categoria and by naturaleza, honoring empresa filter and tenancy', function () {
    $userA = User::factory()->create();
    $teamA = $userA->currentTeam;
    actingAs($userA);

    $empA = Empresa::factory()->create(['team_id' => $teamA->id]);
    $empB = Empresa::factory()->create(['team_id' => $teamA->id]);

    $cv = faCategoria($teamA->id, 'costo_venta', 'variable', 'Materiales');
    $op = faCategoria($teamA->id, 'gasto_operativo', 'fijo', 'Renta');

    faEgreso($teamA->id, $userA->id, $empA->id, $cv->id, 3000, '2026-06-03');
    faEgreso($teamA->id, $userA->id, $empB->id, $cv->id, 1500, '2026-06-04');
    faEgreso($teamA->id, $userA->id, $empA->id, $op->id, 1000, '2026-06-06');
    faEgreso($teamA->id, $userA->id, null, null, 200, '2026-06-13'); // sin categoría

    // Team B ajeno (no debe entrar)
    $userB = User::factory()->create();
    $teamB = $userB->currentTeam;
    $cvB = faCategoria($teamB->id, 'costo_venta', 'variable', 'Otro');
    faEgreso($teamB->id, $userB->id, null, $cvB->id, 99999, '2026-06-05');

    $desde = Carbon::parse('2026-06-01');
    $hasta = Carbon::parse('2026-06-30');
    $svc = faService();

    // ── egresosPorCategoria consolidado (orden desc): Materiales 4500, Renta 1000, Sin categoría 200
    $porCat = $svc->egresosPorCategoria($desde, $hasta, null, $teamA->id);
    expect($porCat)->toHaveCount(3)
        ->and($porCat[0])->toMatchArray(['nombre' => 'Materiales', 'grupo' => 'costo_venta', 'total' => 4500.0])
        ->and($porCat[1])->toMatchArray(['nombre' => 'Renta', 'grupo' => 'gasto_operativo', 'total' => 1000.0])
        ->and($porCat[2])->toMatchArray(['nombre' => 'Sin categoría', 'grupo' => null, 'total' => 200.0]);

    // ── egresosPorCategoria filtrado empresa A: Materiales 3000, Renta 1000
    $porCatA = $svc->egresosPorCategoria($desde, $hasta, $empA->id, $teamA->id);
    expect($porCatA)->toHaveCount(2)
        ->and($porCatA[0])->toMatchArray(['nombre' => 'Materiales', 'total' => 3000.0])
        ->and($porCatA[1])->toMatchArray(['nombre' => 'Renta', 'total' => 1000.0]);

    // ── egresosPorNaturaleza consolidado: variable 4500, fijo 1000, sin_clasificar 200
    expect($svc->egresosPorNaturaleza($desde, $hasta, null, $teamA->id))->toBe([
        'fijo' => 1000.0,
        'variable' => 4500.0,
        'sin_clasificar' => 200.0,
    ]);

    // ── egresosPorNaturaleza filtrado empresa A: variable 3000, fijo 1000, sin_clasificar 0
    expect($svc->egresosPorNaturaleza($desde, $hasta, $empA->id, $teamA->id))->toBe([
        'fijo' => 1000.0,
        'variable' => 3000.0,
        'sin_clasificar' => 0.0,
    ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// topProveedores
// ─────────────────────────────────────────────────────────────────────────────
it('ranks top proveedores, excludes null/empty, honors limit, empresa filter and tenancy', function () {
    $userA = User::factory()->create();
    $teamA = $userA->currentTeam;
    actingAs($userA);

    $empA = Empresa::factory()->create(['team_id' => $teamA->id]);
    $empB = Empresa::factory()->create(['team_id' => $teamA->id]);

    faEgreso($teamA->id, $userA->id, $empA->id, null, 2000, '2026-06-05', ['proveedor' => 'CFE']);
    faEgreso($teamA->id, $userA->id, $empB->id, null, 1000, '2026-06-06', ['proveedor' => 'CFE']); // CFE total 3000
    faEgreso($teamA->id, $userA->id, $empA->id, null, 1500, '2026-06-07', ['proveedor' => 'Telmex']);
    faEgreso($teamA->id, $userA->id, $empA->id, null, 5000, '2026-06-08', ['proveedor' => null]);  // excluido
    faEgreso($teamA->id, $userA->id, $empA->id, null, 4000, '2026-06-09', ['proveedor' => '']);    // excluido

    // Team B ajeno
    $userB = User::factory()->create();
    $teamB = $userB->currentTeam;
    faEgreso($teamB->id, $userB->id, null, null, 99999, '2026-06-05', ['proveedor' => 'CFE']);

    $desde = Carbon::parse('2026-06-01');
    $hasta = Carbon::parse('2026-06-30');
    $svc = faService();

    // Consolidado: CFE 3000, Telmex 1500 (null/'' excluidos, team B excluido).
    $top = $svc->topProveedores($desde, $hasta, null, $teamA->id);
    expect($top)->toHaveCount(2)
        ->and($top[0])->toBe(['proveedor' => 'CFE', 'total' => 3000.0])
        ->and($top[1])->toBe(['proveedor' => 'Telmex', 'total' => 1500.0]);

    // limit=1 → solo el primero.
    $top1 = $svc->topProveedores($desde, $hasta, null, $teamA->id, 1);
    expect($top1)->toHaveCount(1)
        ->and($top1[0]['proveedor'])->toBe('CFE');

    // Filtro empresa A: CFE 2000, Telmex 1500.
    $topA = $svc->topProveedores($desde, $hasta, $empA->id, $teamA->id);
    expect($topA)->toHaveCount(2)
        ->and($topA[0])->toBe(['proveedor' => 'CFE', 'total' => 2000.0])
        ->and($topA[1])->toBe(['proveedor' => 'Telmex', 'total' => 1500.0]);
});

// ─────────────────────────────────────────────────────────────────────────────
// nominaRollup
// ─────────────────────────────────────────────────────────────────────────────
it('rolls up nómina by concepto (fiscal/complemento/total), honoring empresa filter and tenancy', function () {
    $userA = User::factory()->create();
    $teamA = $userA->currentTeam;
    actingAs($userA);

    $empA = Empresa::factory()->create(['team_id' => $teamA->id]);
    $empB = Empresa::factory()->create(['team_id' => $teamA->id]);

    $emp1 = Empleado::factory()->create(['team_id' => $teamA->id, 'empresa_id' => $empA->id]);
    $emp2 = Empleado::factory()->create(['team_id' => $teamA->id, 'empresa_id' => $empB->id]);

    // emp1 (empresa A)
    faEgreso($teamA->id, $userA->id, $empA->id, null, 20000, '2026-06-01', ['empleado_id' => $emp1->id, 'concepto_nomina' => 'fiscal']);
    faEgreso($teamA->id, $userA->id, $empA->id, null, 4000, '2026-06-01', ['empleado_id' => $emp1->id, 'concepto_nomina' => 'complemento']);
    // emp2 (empresa B)
    faEgreso($teamA->id, $userA->id, $empB->id, null, 15000, '2026-06-01', ['empleado_id' => $emp2->id, 'concepto_nomina' => 'fiscal']);
    faEgreso($teamA->id, $userA->id, $empB->id, null, 3000, '2026-06-01', ['empleado_id' => $emp2->id, 'concepto_nomina' => 'complemento']);

    // Egreso NO nómina (empleado_id null) → NO cuenta.
    faEgreso($teamA->id, $userA->id, $empA->id, null, 999, '2026-06-02', ['proveedor' => 'X']);

    // Team B ajeno
    $userB = User::factory()->create();
    $teamB = $userB->currentTeam;
    $empBteam = Empleado::factory()->create(['team_id' => $teamB->id]);
    faEgreso($teamB->id, $userB->id, null, null, 99999, '2026-06-01', ['empleado_id' => $empBteam->id, 'concepto_nomina' => 'fiscal']);

    $desde = Carbon::parse('2026-06-01');
    $hasta = Carbon::parse('2026-06-30');
    $svc = faService();

    // Consolidado: fiscal 20000+15000=35000; complemento 4000+3000=7000; total 42000.
    expect($svc->nominaRollup($desde, $hasta, null, $teamA->id))->toBe([
        'fiscal' => 35000.0,
        'complemento' => 7000.0,
        'total' => 42000.0,
    ]);

    // Filtro empresa A (emp1): fiscal 20000; complemento 4000; total 24000.
    expect($svc->nominaRollup($desde, $hasta, $empA->id, $teamA->id))->toBe([
        'fiscal' => 20000.0,
        'complemento' => 4000.0,
        'total' => 24000.0,
    ]);
});
