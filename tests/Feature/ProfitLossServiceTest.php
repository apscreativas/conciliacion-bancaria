<?php

use App\Models\Categoria;
use App\Models\Conciliacion;
use App\Models\Egreso;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\IngresoManual;
use App\Models\Movimiento;
use App\Models\User;
use App\Services\Finance\ProfitLossService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * Crea una conciliación enlazando un movimiento (fechado para el periodo) y una factura.
 * Los `monto` de movimiento/factura se siembran ABSURDOS y distintos de `monto_aplicado`
 * a propósito: el P&L debe sumar SOLO `conciliacions.monto_aplicado`, nunca esos.
 */
function plConciliacion(int $teamId, int $userId, ?int $empresaId, float $montoAplicado, string $movFecha, string $tipo = 'abono', string $estatus = 'conciliado'): Conciliacion
{
    $movimiento = Movimiento::factory()->create([
        'team_id' => $teamId,
        'fecha' => $movFecha,
        'tipo' => $tipo,
        'monto' => 99999, // distinto de monto_aplicado a propósito
    ]);

    $factura = Factura::factory()->create([
        'team_id' => $teamId,
        'monto' => 88888, // distinto de monto_aplicado a propósito
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
        'fecha_conciliacion' => '2026-06-15',
    ]);
}

/** Categoría de egreso de un grupo específico. */
function plCategoriaEgreso(int $teamId, string $grupo): Categoria
{
    return Categoria::factory()->create(['team_id' => $teamId, 'grupo' => $grupo]);
}

/** Egreso fijo en una fecha/empresa/grupo. */
function plEgreso(int $teamId, int $userId, ?int $empresaId, ?int $categoriaId, float $monto, string $fecha): Egreso
{
    return Egreso::factory()->create([
        'team_id' => $teamId,
        'user_id' => $userId,
        'empresa_id' => $empresaId,
        'categoria_id' => $categoriaId,
        'monto' => $monto,
        'fecha' => $fecha,
    ]);
}

/** Ingreso manual (efectivo) fijo. */
function plIngresoManual(int $teamId, int $userId, ?int $empresaId, float $monto, string $fecha): IngresoManual
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

function plPeriodo(): array
{
    return [Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')];
}

// ─────────────────────────────────────────────────────────────────────────────
// CASO 1 — Maestro consolidado (al centavo). Todos los esperados derivados a mano.
// ─────────────────────────────────────────────────────────────────────────────
it('computes the consolidated P&L for the period down to the cent', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    actingAs($user);

    $empresaA = Empresa::factory()->create(['team_id' => $team->id]);
    $empresaB = Empresa::factory()->create(['team_id' => $team->id]);

    // ── Ingreso bancario conciliado (SUM monto_aplicado, fechado por movimientos.fecha)
    plConciliacion($team->id, $user->id, $empresaA->id, 5000, '2026-06-10');
    plConciliacion($team->id, $user->id, $empresaA->id, 3000, '2026-06-20'); // 2 filas mismo empresa → SUM sin dedup
    plConciliacion($team->id, $user->id, $empresaB->id, 2000, '2026-06-15');
    plConciliacion($team->id, $user->id, null, 1000, '2026-06-05');           // sin asignar
    plConciliacion($team->id, $user->id, $empresaA->id, 9999, '2026-07-01');  // FUERA del periodo → no cuenta
    // bancario_conciliado = 5000 + 3000 + 2000 + 1000 = 11000

    // ── Ingreso manual (efectivo)
    plIngresoManual($team->id, $user->id, $empresaA->id, 1500, '2026-06-08');
    plIngresoManual($team->id, $user->id, $empresaB->id, 500, '2026-06-09');
    plIngresoManual($team->id, $user->id, null, 250, '2026-06-12');
    plIngresoManual($team->id, $user->id, $empresaA->id, 7777, '2026-05-31'); // FUERA → no cuenta
    // manual = 1500 + 500 + 250 = 2250

    // ingresos.total = 11000 + 2250 = 13250

    // ── Egresos por grupo
    $cv = plCategoriaEgreso($team->id, 'costo_venta');
    $op = plCategoriaEgreso($team->id, 'gasto_operativo');
    $ab = plCategoriaEgreso($team->id, 'abajo_ebitda');

    plEgreso($team->id, $user->id, $empresaA->id, $cv->id, 3000, '2026-06-03');
    plEgreso($team->id, $user->id, $empresaB->id, $cv->id, 1500, '2026-06-04');
    // costo_venta = 3000 + 1500 = 4500

    plEgreso($team->id, $user->id, $empresaA->id, $op->id, 1000, '2026-06-06');
    plEgreso($team->id, $user->id, null, $op->id, 500, '2026-06-07');
    // gasto_operativo = 1000 + 500 = 1500

    plEgreso($team->id, $user->id, $empresaB->id, $ab->id, 800, '2026-06-11');
    // abajo_ebitda = 800

    plEgreso($team->id, $user->id, null, null, 200, '2026-06-13'); // sin categoría → sin_clasificar
    // sin_clasificar = 200

    plEgreso($team->id, $user->id, $empresaA->id, $cv->id, 6666, '2026-07-15'); // FUERA → no cuenta
    // egresos_total = 4500 + 1500 + 800 + 200 = 7000

    [$desde, $hasta] = plPeriodo();
    $pl = (new ProfitLossService)->forPeriod($desde, $hasta);

    expect($pl['desde'])->toBe('2026-06-01')
        ->and($pl['hasta'])->toBe('2026-06-30')
        ->and($pl['empresa_id'])->toBeNull();

    // Ingresos
    expect($pl['ingresos']['bancario_conciliado'])->toBe(11000.0) // 5000+3000+2000+1000
        ->and($pl['ingresos']['manual'])->toBe(2250.0)            // 1500+500+250
        ->and($pl['ingresos']['total'])->toBe(13250.0);          // 11000+2250

    // Egresos / renglones
    expect($pl['costo_venta'])->toBe(4500.0)        // 3000+1500
        ->and($pl['gasto_operativo'])->toBe(1500.0) // 1000+500
        ->and($pl['abajo_ebitda'])->toBe(800.0)     // 800
        ->and($pl['sin_clasificar'])->toBe(200.0)   // 200
        ->and($pl['egresos_total'])->toBe(7000.0);  // 4500+1500+800+200

    // Utilidades
    expect($pl['utilidad_bruta'])->toBe(8750.0)  // 13250 - 4500
        ->and($pl['ebitda'])->toBe(7250.0)       // 8750 - 1500
        ->and($pl['utilidad_neta'])->toBe(6250.0); // 7250 - 800 - 200

    // Identidad maestra: utilidad_neta = ingresos.total − egresos_total
    expect($pl['utilidad_neta'])->toBe(round($pl['ingresos']['total'] - $pl['egresos_total'], 2)); // 13250 - 7000 = 6250

    // Márgenes (ratio, round 4)
    expect($pl['margen_bruto'])->toBe(0.6604)   // 8750/13250 = 0.660377...
        ->and($pl['margen_ebitda'])->toBe(0.5472) // 7250/13250 = 0.547169...
        ->and($pl['margen_neto'])->toBe(0.4717);  // 6250/13250 = 0.471698...
});

// ─────────────────────────────────────────────────────────────────────────────
// CASO 2 — Por empresa, y consolidado = empresaA + empresaB + sin-asignar.
// ─────────────────────────────────────────────────────────────────────────────
it('isolates per-empresa totals and consolidates as A + B + unassigned', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    actingAs($user);

    $a = Empresa::factory()->create(['team_id' => $team->id]);
    $b = Empresa::factory()->create(['team_id' => $team->id]);
    $cv = plCategoriaEgreso($team->id, 'costo_venta');

    // Empresa A
    plConciliacion($team->id, $user->id, $a->id, 5000, '2026-06-10');
    plIngresoManual($team->id, $user->id, $a->id, 1500, '2026-06-08');
    plEgreso($team->id, $user->id, $a->id, $cv->id, 3000, '2026-06-03');
    // A: ingresos 6500, costo_venta 3000, utilidad_neta 3500

    // Empresa B
    plConciliacion($team->id, $user->id, $b->id, 2000, '2026-06-15');
    plIngresoManual($team->id, $user->id, $b->id, 500, '2026-06-09');
    plEgreso($team->id, $user->id, $b->id, $cv->id, 1500, '2026-06-04');
    // B: ingresos 2500, costo_venta 1500, utilidad_neta 1000

    // Sin asignar
    plConciliacion($team->id, $user->id, null, 1000, '2026-06-05');
    plIngresoManual($team->id, $user->id, null, 250, '2026-06-12');
    plEgreso($team->id, $user->id, null, $cv->id, 700, '2026-06-06');
    // null: ingresos 1250, costo_venta 700, utilidad_neta 550

    [$desde, $hasta] = plPeriodo();
    $svc = new ProfitLossService;

    $plA = $svc->forPeriod($desde, $hasta, $a->id);
    expect($plA['empresa_id'])->toBe($a->id)
        ->and($plA['ingresos']['total'])->toBe(6500.0)  // 5000 + 1500
        ->and($plA['costo_venta'])->toBe(3000.0)
        ->and($plA['egresos_total'])->toBe(3000.0)
        ->and($plA['utilidad_neta'])->toBe(3500.0);     // 6500 - 3000

    $plB = $svc->forPeriod($desde, $hasta, $b->id);
    expect($plB['ingresos']['total'])->toBe(2500.0)     // 2000 + 500
        ->and($plB['costo_venta'])->toBe(1500.0)
        ->and($plB['utilidad_neta'])->toBe(1000.0);     // 2500 - 1500

    $cons = $svc->forPeriod($desde, $hasta);
    expect($cons['ingresos']['total'])->toBe(10250.0)   // 6500 + 2500 + 1250
        ->and($cons['costo_venta'])->toBe(5200.0)       // 3000 + 1500 + 700
        ->and($cons['egresos_total'])->toBe(5200.0)
        ->and($cons['utilidad_neta'])->toBe(5050.0);    // 10250 - 5200

    // consolidado = A + B + sin-asignar (550 = 1250 - 700)
    expect($cons['utilidad_neta'])->toBe(round($plA['utilidad_neta'] + $plB['utilidad_neta'] + 550, 2)); // 3500 + 1000 + 550
    expect($cons['ingresos']['total'])->toBe(round($plA['ingresos']['total'] + $plB['ingresos']['total'] + 1250, 2)); // 6500 + 2500 + 1250
});

// ─────────────────────────────────────────────────────────────────────────────
// CASO 3 — Anti-doble-conteo: SUM(monto_aplicado), nunca movimiento/factura.monto;
// un movimiento tipo='cargo' suelto NO afecta egresos ni utilidad.
// ─────────────────────────────────────────────────────────────────────────────
it('never double-counts: uses monto_aplicado, ignores cargo movimientos and factura/movimiento monto', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    actingAs($user);

    // 2 conciliaciones: monto_aplicado 5000 + 3000; mov.monto=99999 y fact.monto=88888 (absurdos)
    plConciliacion($team->id, $user->id, null, 5000, '2026-06-10');
    plConciliacion($team->id, $user->id, null, 3000, '2026-06-12');
    // bancario_conciliado = 5000 + 3000 = 8000 (NO 99999/88888, NO inflado)

    // Movimiento tipo='cargo' en el periodo, suelto (no en egresos): no debe sumar nada.
    Movimiento::factory()->create([
        'team_id' => $team->id,
        'fecha' => '2026-06-15',
        'tipo' => 'cargo',
        'monto' => 4444,
    ]);

    $cv = plCategoriaEgreso($team->id, 'costo_venta');
    plEgreso($team->id, $user->id, null, $cv->id, 1000, '2026-06-05');
    // egresos_total = 1000

    [$desde, $hasta] = plPeriodo();
    $pl = (new ProfitLossService)->forPeriod($desde, $hasta);

    expect($pl['ingresos']['bancario_conciliado'])->toBe(8000.0) // 5000 + 3000
        ->and($pl['ingresos']['manual'])->toBe(0.0)
        ->and($pl['egresos_total'])->toBe(1000.0)               // el cargo 4444 NO entra
        ->and($pl['costo_venta'])->toBe(1000.0)
        ->and($pl['utilidad_neta'])->toBe(7000.0);             // 8000 - 1000
});

// ─────────────────────────────────────────────────────────────────────────────
// CASO 4 — Bordes de fecha: desde/hasta inclusivos; un día antes/después no cuenta.
// ─────────────────────────────────────────────────────────────────────────────
it('includes the exact desde/hasta boundaries and excludes the day before/after', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    actingAs($user);

    $cv = plCategoriaEgreso($team->id, 'costo_venta');

    // Bancario
    plConciliacion($team->id, $user->id, null, 100, '2026-06-01'); // borde inferior → cuenta
    plConciliacion($team->id, $user->id, null, 200, '2026-06-30'); // borde superior → cuenta
    plConciliacion($team->id, $user->id, null, 999, '2026-05-31'); // día antes → no
    plConciliacion($team->id, $user->id, null, 999, '2026-07-01'); // día después → no
    // bancario = 100 + 200 = 300

    // Manual
    plIngresoManual($team->id, $user->id, null, 10, '2026-06-01');
    plIngresoManual($team->id, $user->id, null, 20, '2026-06-30');
    plIngresoManual($team->id, $user->id, null, 999, '2026-05-31');
    plIngresoManual($team->id, $user->id, null, 999, '2026-07-01');
    // manual = 10 + 20 = 30

    // Egresos
    plEgreso($team->id, $user->id, null, $cv->id, 5, '2026-06-01');
    plEgreso($team->id, $user->id, null, $cv->id, 7, '2026-06-30');
    plEgreso($team->id, $user->id, null, $cv->id, 999, '2026-05-31');
    plEgreso($team->id, $user->id, null, $cv->id, 999, '2026-07-01');
    // egresos_total = 5 + 7 = 12

    [$desde, $hasta] = plPeriodo();
    $pl = (new ProfitLossService)->forPeriod($desde, $hasta);

    expect($pl['ingresos']['bancario_conciliado'])->toBe(300.0) // 100 + 200
        ->and($pl['ingresos']['manual'])->toBe(30.0)           // 10 + 20
        ->and($pl['ingresos']['total'])->toBe(330.0)
        ->and($pl['egresos_total'])->toBe(12.0)                // 5 + 7
        ->and($pl['costo_venta'])->toBe(12.0)
        ->and($pl['utilidad_neta'])->toBe(318.0);              // 330 - 12
});

// ─────────────────────────────────────────────────────────────────────────────
// CASO 5 — sin_clasificar: egreso con categoria_id null entra ahí, no en COGS/OPEX.
// ─────────────────────────────────────────────────────────────────────────────
it('routes uncategorized egresos into sin_clasificar and keeps the identity', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    actingAs($user);

    plIngresoManual($team->id, $user->id, null, 1000, '2026-06-10');

    $cv = plCategoriaEgreso($team->id, 'costo_venta');
    plEgreso($team->id, $user->id, null, $cv->id, 200, '2026-06-05');
    plEgreso($team->id, $user->id, null, null, 300, '2026-06-06'); // sin categoría
    // egresos_total = 500; costo_venta = 200; sin_clasificar = 500 - 200 = 300

    [$desde, $hasta] = plPeriodo();
    $pl = (new ProfitLossService)->forPeriod($desde, $hasta);

    expect($pl['ingresos']['total'])->toBe(1000.0)
        ->and($pl['costo_venta'])->toBe(200.0)
        ->and($pl['gasto_operativo'])->toBe(0.0)
        ->and($pl['abajo_ebitda'])->toBe(0.0)
        ->and($pl['sin_clasificar'])->toBe(300.0)   // 500 - 200
        ->and($pl['egresos_total'])->toBe(500.0)
        ->and($pl['utilidad_neta'])->toBe(500.0);   // 1000 - 500

    // Identidad se mantiene aun con egreso sin categoría
    expect($pl['utilidad_neta'])->toBe(round($pl['ingresos']['total'] - $pl['egresos_total'], 2));
});

// ─────────────────────────────────────────────────────────────────────────────
// CASO 6 — Periodo vacío: todo 0, márgenes 0 (sin división por cero).
// ─────────────────────────────────────────────────────────────────────────────
it('returns all zeros and zero margins for an empty period', function () {
    $user = User::factory()->create();
    actingAs($user);

    [$desde, $hasta] = plPeriodo();
    $pl = (new ProfitLossService)->forPeriod($desde, $hasta);

    expect($pl['ingresos']['total'])->toBe(0.0)
        ->and($pl['ingresos']['bancario_conciliado'])->toBe(0.0)
        ->and($pl['ingresos']['manual'])->toBe(0.0)
        ->and($pl['costo_venta'])->toBe(0.0)
        ->and($pl['gasto_operativo'])->toBe(0.0)
        ->and($pl['abajo_ebitda'])->toBe(0.0)
        ->and($pl['sin_clasificar'])->toBe(0.0)
        ->and($pl['egresos_total'])->toBe(0.0)
        ->and($pl['utilidad_bruta'])->toBe(0.0)
        ->and($pl['ebitda'])->toBe(0.0)
        ->and($pl['utilidad_neta'])->toBe(0.0)
        ->and($pl['margen_bruto'])->toBe(0.0)
        ->and($pl['margen_ebitda'])->toBe(0.0)
        ->and($pl['margen_neto'])->toBe(0.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// CASO 8 — Definición de ingreso: solo conciliaciones confirmadas (estatus='conciliado')
// contra un movimiento 'abono'. Excluye pendiente_revision y movimientos 'cargo'.
// ─────────────────────────────────────────────────────────────────────────────
it('counts only confirmed conciliaciones against abono movimientos as bank income', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    actingAs($user);

    plConciliacion($team->id, $user->id, null, 5000, '2026-06-10');                            // válida → cuenta
    plConciliacion($team->id, $user->id, null, 9000, '2026-06-11', 'abono', 'pendiente_revision'); // no confirmada → NO
    plConciliacion($team->id, $user->id, null, 7000, '2026-06-12', 'cargo');                    // movimiento cargo → NO

    [$desde, $hasta] = plPeriodo();
    $pl = (new ProfitLossService)->forPeriod($desde, $hasta);

    expect($pl['ingresos']['bancario_conciliado'])->toBe(5000.0)
        ->and($pl['ingresos']['total'])->toBe(5000.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// CASO 7 — Tenancy: datos de otro team NO entran (TeamOwned + actingAs).
// ─────────────────────────────────────────────────────────────────────────────
it('excludes data from other teams via TeamOwned scope', function () {
    // Team B (ajeno) sembrado con dinero grande
    $userB = User::factory()->create();
    $teamB = $userB->currentTeam;
    actingAs($userB);
    plConciliacion($teamB->id, $userB->id, null, 9000, '2026-06-10');
    plIngresoManual($teamB->id, $userB->id, null, 5000, '2026-06-10');
    $cvB = plCategoriaEgreso($teamB->id, 'costo_venta');
    plEgreso($teamB->id, $userB->id, null, $cvB->id, 3000, '2026-06-05');

    // Team A (actor) con un solo ingreso
    $userA = User::factory()->create();
    $teamA = $userA->currentTeam;
    actingAs($userA);
    plIngresoManual($teamA->id, $userA->id, null, 1000, '2026-06-10');

    [$desde, $hasta] = plPeriodo();
    $pl = (new ProfitLossService)->forPeriod($desde, $hasta);

    // Solo cuenta lo del team A
    expect($pl['ingresos']['manual'])->toBe(1000.0)
        ->and($pl['ingresos']['bancario_conciliado'])->toBe(0.0)
        ->and($pl['ingresos']['total'])->toBe(1000.0)
        ->and($pl['egresos_total'])->toBe(0.0)
        ->and($pl['utilidad_neta'])->toBe(1000.0);
});
