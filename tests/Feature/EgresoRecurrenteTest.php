<?php

use App\Models\Categoria;
use App\Models\EgresoRecurrente;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function catEgreso(int $teamId): Categoria
{
    return Categoria::factory()->create(['team_id' => $teamId]);
}

it('lets a team member create a template and computes proxima_generacion', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $member = User::factory()->create();
    $member->forceFill(['current_team_id' => $team->id])->saveQuietly();
    $cat = catEgreso($team->id);

    actingAs($member)->get(route('recurring-expenses.index'))->assertOk();

    actingAs($member)->post(route('recurring-expenses.store'), [
        'descripcion' => 'Servidor cloud',
        'monto' => 1200,
        'categoria_id' => $cat->id,
        'frecuencia' => 'mensual',
        'dia_del_mes' => 1,
        'ajuste_dia_habil' => 'habil_anterior',
        'fecha_inicio' => '2026-06-10',
        'vigencia_tipo' => 'indefinida',
        'activo' => true,
    ])->assertRedirect(route('recurring-expenses.index'));

    $plantilla = EgresoRecurrente::withoutGlobalScopes()->where('descripcion', 'Servidor cloud')->first();
    expect($plantilla->user_id)->toBe($member->id);
    expect($plantilla->pagos_generados)->toBe(0);
    // primera ocurrencia: día 1 ya pasó en junio (inicio el 10) → 2026-07-01
    expect($plantilla->proxima_generacion->toDateString())->toBe('2026-07-01');
});

it('rejects monto <= 0, a missing categoria, and an ingreso categoria', function () {
    $user = User::factory()->create();
    $cat = catEgreso($user->current_team_id);
    $ingreso = Categoria::factory()->ingreso()->create(['team_id' => $user->current_team_id, 'nombre' => 'Ventas']);

    $base = ['descripcion' => 'X', 'frecuencia' => 'mensual', 'dia_del_mes' => 1, 'ajuste_dia_habil' => 'ninguno', 'fecha_inicio' => '2026-06-01', 'vigencia_tipo' => 'indefinida'];

    actingAs($user)->post(route('recurring-expenses.store'), $base + ['monto' => 0, 'categoria_id' => $cat->id])
        ->assertSessionHasErrors('monto');
    actingAs($user)->post(route('recurring-expenses.store'), $base + ['monto' => 100])
        ->assertSessionHasErrors('categoria_id');
    actingAs($user)->post(route('recurring-expenses.store'), $base + ['monto' => 100, 'categoria_id' => $ingreso->id])
        ->assertSessionHasErrors('categoria_id');
});

it('requires fecha_fin / num_pagos depending on vigencia_tipo', function () {
    $user = User::factory()->create();
    $cat = catEgreso($user->current_team_id);
    $base = ['descripcion' => 'X', 'monto' => 100, 'categoria_id' => $cat->id, 'frecuencia' => 'mensual', 'dia_del_mes' => 1, 'ajuste_dia_habil' => 'ninguno', 'fecha_inicio' => '2026-06-01'];

    actingAs($user)->post(route('recurring-expenses.store'), $base + ['vigencia_tipo' => 'hasta_fecha'])
        ->assertSessionHasErrors('fecha_fin');
    actingAs($user)->post(route('recurring-expenses.store'), $base + ['vigencia_tipo' => 'num_pagos'])
        ->assertSessionHasErrors('num_pagos');
});

it('rejects quincenal frequency in fase 3', function () {
    $user = User::factory()->create();
    $cat = catEgreso($user->current_team_id);

    actingAs($user)->post(route('recurring-expenses.store'), [
        'descripcion' => 'X', 'monto' => 100, 'categoria_id' => $cat->id,
        'frecuencia' => 'quincenal', 'dia_del_mes' => 1, 'ajuste_dia_habil' => 'ninguno',
        'fecha_inicio' => '2026-06-01', 'vigencia_tipo' => 'indefinida',
    ])->assertSessionHasErrors('frecuencia');
});

it('on reactivation resumes proxima_generacion from today, not the stale past date', function () {
    Carbon::setTestNow('2026-06-15');
    $user = User::factory()->create();
    $cat = catEgreso($user->current_team_id);
    $t = EgresoRecurrente::factory()->create([
        'team_id' => $user->current_team_id, 'user_id' => $user->id, 'categoria_id' => $cat->id,
        'frecuencia' => 'mensual', 'dia_del_mes' => 1, 'ajuste_dia_habil' => 'ninguno',
        'fecha_inicio' => '2026-01-01', 'vigencia_tipo' => 'num_pagos', 'num_pagos' => 2,
        'pagos_generados' => 2, 'activo' => false, 'proxima_generacion' => '2026-03-01',
    ]);

    actingAs($user)->put(route('recurring-expenses.update', $t->id), [
        'descripcion' => $t->descripcion, 'monto' => $t->monto, 'categoria_id' => $cat->id,
        'frecuencia' => 'mensual', 'dia_del_mes' => 1, 'ajuste_dia_habil' => 'ninguno',
        'fecha_inicio' => '2026-01-01', 'vigencia_tipo' => 'num_pagos', 'num_pagos' => 5,
        'activo' => true,
    ])->assertRedirect(route('recurring-expenses.index'));

    $t->refresh();
    expect($t->activo)->toBeTrue();
    // No reanuda desde 2026-03-01 (vencido) → no inunda con egresos retroactivos.
    expect($t->proxima_generacion->toDateString())->toBe('2026-07-01');
});

it('denies access to a template from another team (404)', function () {
    $userA = User::factory()->create();
    $cat = catEgreso($userA->current_team_id);
    $tA = EgresoRecurrente::factory()->create(['team_id' => $userA->current_team_id, 'user_id' => $userA->id, 'categoria_id' => $cat->id]);

    $userB = User::factory()->create();
    actingAs($userB)->delete(route('recurring-expenses.destroy', $tA->id))->assertNotFound();
});
