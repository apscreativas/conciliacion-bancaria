<?php

use App\Models\Categoria;
use App\Models\Egreso;
use App\Models\EgresoRecurrente;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function plantillaRecurrente(User $user, array $attrs = []): EgresoRecurrente
{
    $cat = Categoria::factory()->create(['team_id' => $user->current_team_id]); // tipo=egreso por default

    return EgresoRecurrente::factory()->create(array_merge([
        'team_id' => $user->current_team_id,
        'user_id' => $user->id,
        'categoria_id' => $cat->id,
        'ajuste_dia_habil' => 'ninguno',
    ], $attrs));
}

function egresosDe(EgresoRecurrente $t)
{
    return Egreso::withoutGlobalScopes()->where('egreso_recurrente_id', $t->id);
}

it('generates the due egreso once and is idempotent on re-run', function () {
    Carbon::setTestNow('2026-06-15');
    $user = User::factory()->create();
    $t = plantillaRecurrente($user, [
        'frecuencia' => 'mensual', 'dia_del_mes' => 1, 'monto' => 1000,
        'fecha_inicio' => '2026-06-01', 'proxima_generacion' => '2026-06-01',
    ]);

    $this->artisan('egresos:generar-recurrentes')->assertSuccessful();

    $egresos = egresosDe($t)->get();
    expect($egresos)->toHaveCount(1);
    expect($egresos->first()->origen)->toBe('recurrente');
    expect($egresos->first()->fecha->toDateString())->toBe('2026-06-01');
    expect((float) $egresos->first()->monto)->toBe(1000.0);

    $t->refresh();
    expect($t->proxima_generacion->toDateString())->toBe('2026-07-01');
    expect($t->pagos_generados)->toBe(1);

    // Re-correr el mismo día NO duplica (proxima_generacion ya avanzó).
    $this->artisan('egresos:generar-recurrentes')->assertSuccessful();
    expect(egresosDe($t)->count())->toBe(1);
});

it('catches up all missed periods up to today', function () {
    Carbon::setTestNow('2026-06-15');
    $user = User::factory()->create();
    $t = plantillaRecurrente($user, [
        'frecuencia' => 'mensual', 'dia_del_mes' => 1, 'proxima_generacion' => '2026-04-01',
    ]);

    $this->artisan('egresos:generar-recurrentes')->assertSuccessful();

    expect(egresosDe($t)->count())->toBe(3); // abr, may, jun
    $t->refresh();
    expect($t->proxima_generacion->toDateString())->toBe('2026-07-01');
    expect($t->pagos_generados)->toBe(3);
});

it('stops after num_pagos and deactivates', function () {
    Carbon::setTestNow('2026-06-15');
    $user = User::factory()->create();
    $t = plantillaRecurrente($user, [
        'frecuencia' => 'mensual', 'dia_del_mes' => 1, 'proxima_generacion' => '2026-04-01',
        'vigencia_tipo' => 'num_pagos', 'num_pagos' => 2,
    ]);

    $this->artisan('egresos:generar-recurrentes')->assertSuccessful();

    expect(egresosDe($t)->count())->toBe(2);
    $t->refresh();
    expect($t->activo)->toBeFalse();
});

it('stops at fecha_fin and deactivates', function () {
    Carbon::setTestNow('2026-06-15');
    $user = User::factory()->create();
    $t = plantillaRecurrente($user, [
        'frecuencia' => 'mensual', 'dia_del_mes' => 1, 'proxima_generacion' => '2026-04-01',
        'vigencia_tipo' => 'hasta_fecha', 'fecha_fin' => '2026-05-31',
    ]);

    $this->artisan('egresos:generar-recurrentes')->assertSuccessful();

    expect(egresosDe($t)->count())->toBe(2); // abr, may (jun > fecha_fin)
    $t->refresh();
    expect($t->activo)->toBeFalse();
});

it('adjusts a weekend nominal date to the previous business day', function () {
    $sat = Carbon::parse('2026-06-01')->next(Carbon::SATURDAY);
    expect($sat->isWeekend())->toBeTrue();

    Carbon::setTestNow($sat->copy()->addDays(3));
    $user = User::factory()->create();
    $t = plantillaRecurrente($user, [
        'frecuencia' => 'mensual', 'dia_del_mes' => $sat->day,
        'fecha_inicio' => $sat->toDateString(), 'proxima_generacion' => $sat->toDateString(),
        'ajuste_dia_habil' => 'habil_anterior',
    ]);

    $this->artisan('egresos:generar-recurrentes')->assertSuccessful();

    $egreso = egresosDe($t)->first();
    expect($egreso->fecha->toDateString())->toBe($sat->copy()->subDay()->toDateString()); // viernes
    expect($egreso->fecha->isWeekend())->toBeFalse();
});

it('generates per team with the correct team_id', function () {
    Carbon::setTestNow('2026-06-15');
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $tA = plantillaRecurrente($userA, ['dia_del_mes' => 1, 'proxima_generacion' => '2026-06-01']);
    $tB = plantillaRecurrente($userB, ['dia_del_mes' => 1, 'proxima_generacion' => '2026-06-01']);

    $this->artisan('egresos:generar-recurrentes')->assertSuccessful();

    expect(egresosDe($tA)->first()->team_id)->toBe($userA->current_team_id);
    expect(egresosDe($tB)->first()->team_id)->toBe($userB->current_team_id);
});

it('enforces one egreso per (plantilla, fecha) at the DB level', function () {
    $user = User::factory()->create();
    $t = plantillaRecurrente($user, ['dia_del_mes' => 1, 'proxima_generacion' => '2026-06-01']);

    $payload = [
        'team_id' => $user->current_team_id, 'categoria_id' => $t->categoria_id,
        'egreso_recurrente_id' => $t->id, 'fecha' => '2026-06-01', 'monto' => 100,
        'descripcion' => 'x', 'origen' => 'recurrente', 'user_id' => $user->id,
    ];
    Egreso::create($payload);

    expect(fn () => Egreso::create(['descripcion' => 'y'] + $payload))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('counts an already-existing period toward pagos_generados without duplicating it', function () {
    Carbon::setTestNow('2026-06-15');
    $user = User::factory()->create();
    $t = plantillaRecurrente($user, [
        'frecuencia' => 'mensual', 'dia_del_mes' => 1, 'proxima_generacion' => '2026-06-01',
        'vigencia_tipo' => 'num_pagos', 'num_pagos' => 2,
    ]);

    // El egreso de junio ya existe (alta manual o corrida previa interrumpida).
    Egreso::create([
        'team_id' => $user->current_team_id, 'categoria_id' => $t->categoria_id,
        'egreso_recurrente_id' => $t->id, 'fecha' => '2026-06-01', 'monto' => $t->monto,
        'descripcion' => $t->descripcion, 'origen' => 'recurrente', 'user_id' => $user->id,
    ]);

    $this->artisan('egresos:generar-recurrentes')->assertSuccessful();

    // No se duplica junio, y el periodo preexistente SÍ cuenta para la vigencia num_pagos.
    expect(egresosDe($t)->count())->toBe(1);
    $t->refresh();
    expect($t->pagos_generados)->toBe(1);
    expect($t->proxima_generacion->toDateString())->toBe('2026-07-01');
});

it('does not generate a payment dated after fecha_fin when habil_siguiente crosses the month', function () {
    // 2026-05-31 es domingo → habil_siguiente lo empuja a 2026-06-01 (> fecha_fin).
    Carbon::setTestNow('2026-06-15');
    $user = User::factory()->create();
    $t = plantillaRecurrente($user, [
        'frecuencia' => 'mensual', 'dia_del_mes' => 31, 'proxima_generacion' => '2026-05-31',
        'ajuste_dia_habil' => 'habil_siguiente',
        'vigencia_tipo' => 'hasta_fecha', 'fecha_fin' => '2026-05-31',
    ]);

    $this->artisan('egresos:generar-recurrentes')->assertSuccessful();

    expect(egresosDe($t)->count())->toBe(0); // el pago caería el 2026-06-01, fuera de vigencia
    $t->refresh();
    expect($t->activo)->toBeFalse();
});

it('does not persist anything on --dry-run', function () {
    Carbon::setTestNow('2026-06-15');
    $user = User::factory()->create();
    $t = plantillaRecurrente($user, ['dia_del_mes' => 1, 'proxima_generacion' => '2026-06-01']);

    $this->artisan('egresos:generar-recurrentes --dry-run')->assertSuccessful();

    expect(egresosDe($t)->count())->toBe(0);
    $t->refresh();
    expect($t->proxima_generacion->toDateString())->toBe('2026-06-01'); // sin avanzar
});
