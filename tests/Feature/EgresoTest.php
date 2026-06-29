<?php

use App\Models\Categoria;
use App\Models\Egreso;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function egresoCategoria(int $teamId, string $nombre = 'Renta'): Categoria
{
    return Categoria::factory()->create(['team_id' => $teamId, 'nombre' => $nombre]);
}

it('lets a team member create, edit and delete an egreso', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;

    // miembro NO-owner del mismo team (los egresos no requieren owner)
    $member = User::factory()->create();
    $member->forceFill(['current_team_id' => $team->id])->saveQuietly();

    $cat = egresoCategoria($team->id);
    $empresa = Empresa::create(['team_id' => $team->id, 'nombre' => 'Domoticap', 'slug' => 'domoticap']);

    actingAs($member)->get(route('expenses.index'))->assertOk();

    actingAs($member)->post(route('expenses.store'), [
        'fecha' => '2026-06-10',
        'monto' => 1500.50,
        'descripcion' => 'Servidor mensual',
        'categoria_id' => $cat->id,
        'empresa_id' => $empresa->id,
        'metodo_pago' => 'transferencia',
    ])->assertRedirect(route('expenses.index'));

    $egreso = Egreso::withoutGlobalScopes()->where('descripcion', 'Servidor mensual')->first();
    expect($egreso->team_id)->toBe($team->id);
    expect($egreso->user_id)->toBe($member->id); // el creador
    expect($egreso->origen)->toBe('manual');

    actingAs($member)->put(route('expenses.update', $egreso->id), [
        'fecha' => '2026-06-11',
        'monto' => 1600,
        'descripcion' => 'Servidor mensual (ajuste)',
        'categoria_id' => $cat->id,
    ])->assertRedirect(route('expenses.index'));

    $this->assertDatabaseHas('egresos', ['id' => $egreso->id, 'monto' => 1600.00]);

    actingAs($member)->delete(route('expenses.destroy', $egreso->id))->assertRedirect();
    $this->assertDatabaseMissing('egresos', ['id' => $egreso->id]);
});

it('rejects monto <= 0 and a missing categoria', function () {
    $user = User::factory()->create();
    $cat = egresoCategoria($user->current_team_id);

    actingAs($user)->post(route('expenses.store'), [
        'fecha' => '2026-06-10', 'monto' => 0, 'descripcion' => 'X', 'categoria_id' => $cat->id,
    ])->assertSessionHasErrors('monto');

    actingAs($user)->post(route('expenses.store'), [
        'fecha' => '2026-06-10', 'monto' => 100, 'descripcion' => 'X',
    ])->assertSessionHasErrors('categoria_id');
});

it('rejects an ingreso category for an egreso', function () {
    $user = User::factory()->create();
    $ingreso = Categoria::factory()->ingreso()->create(['team_id' => $user->current_team_id, 'nombre' => 'Ventas']);

    actingAs($user)->post(route('expenses.store'), [
        'fecha' => '2026-06-10', 'monto' => 100, 'descripcion' => 'X', 'categoria_id' => $ingreso->id,
    ])->assertSessionHasErrors('categoria_id');
});

it('rejects an empresa or categoria from another team (422)', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherCat = egresoCategoria($other->current_team_id);
    $otherEmpresa = Empresa::create(['team_id' => $other->current_team_id, 'nombre' => 'Ajena', 'slug' => 'ajena']);
    $myCat = egresoCategoria($user->current_team_id);

    actingAs($user)->post(route('expenses.store'), [
        'fecha' => '2026-06-10', 'monto' => 100, 'descripcion' => 'X', 'categoria_id' => $otherCat->id,
    ])->assertSessionHasErrors('categoria_id');

    actingAs($user)->post(route('expenses.store'), [
        'fecha' => '2026-06-10', 'monto' => 100, 'descripcion' => 'X', 'categoria_id' => $myCat->id, 'empresa_id' => $otherEmpresa->id,
    ])->assertSessionHasErrors('empresa_id');
});

it('denies access to an egreso from another team (404)', function () {
    $userA = User::factory()->create();
    $cat = egresoCategoria($userA->current_team_id);
    $egresoA = Egreso::factory()->create([
        'team_id' => $userA->current_team_id, 'categoria_id' => $cat->id, 'user_id' => $userA->id,
    ]);

    $userB = User::factory()->create();
    actingAs($userB)->delete(route('expenses.destroy', $egresoA->id))->assertNotFound();
});

it('filters by empresa and categoria and computes the period total', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $catA = egresoCategoria($team->id, 'Renta');
    $catB = egresoCategoria($team->id, 'Marketing');
    $empresa = Empresa::create(['team_id' => $team->id, 'nombre' => 'TC', 'slug' => 'tc']);

    Egreso::factory()->create(['team_id' => $team->id, 'user_id' => $user->id, 'categoria_id' => $catA->id, 'empresa_id' => $empresa->id, 'monto' => 1000, 'fecha' => '2026-06-05']);
    Egreso::factory()->create(['team_id' => $team->id, 'user_id' => $user->id, 'categoria_id' => $catB->id, 'empresa_id' => null, 'monto' => 500, 'fecha' => '2026-06-06']);

    // Sin filtros (mes/año 06/2026) → 2 egresos, total 1500
    actingAs($user)->get(route('expenses.index', ['month' => 6, 'year' => 2026]))
        ->assertInertia(fn (Assert $p) => $p->component('Expenses/Index')->has('egresos.data', 2)->where('total', 1500));

    // Filtro por categoría A → 1 egreso, total 1000
    actingAs($user)->get(route('expenses.index', ['month' => 6, 'year' => 2026, 'categoria_id' => $catA->id]))
        ->assertInertia(fn (Assert $p) => $p->has('egresos.data', 1)->where('total', 1000));

    // Filtro por empresa → 1 egreso
    actingAs($user)->get(route('expenses.index', ['month' => 6, 'year' => 2026, 'empresa_id' => $empresa->id]))
        ->assertInertia(fn (Assert $p) => $p->has('egresos.data', 1)->where('total', 1000));
});

it('does not 500 on a junk per_page value', function () {
    $user = User::factory()->create();
    actingAs($user)->get(route('expenses.index', ['per_page' => 'abc']))->assertOk();
});

it('includes uncategorized egresos in the total and breakdown', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $cat = egresoCategoria($team->id, 'Renta');

    Egreso::factory()->create(['team_id' => $team->id, 'user_id' => $user->id, 'categoria_id' => $cat->id, 'monto' => 1000, 'fecha' => '2026-06-05']);
    Egreso::factory()->create(['team_id' => $team->id, 'user_id' => $user->id, 'categoria_id' => null, 'monto' => 250, 'fecha' => '2026-06-06']);

    actingAs($user)->get(route('expenses.index', ['month' => 6, 'year' => 2026]))
        ->assertInertia(fn (Assert $p) => $p->where('total', 1250)->has('totalsByCategoria', 2));
});
