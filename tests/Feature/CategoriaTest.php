<?php

use App\Models\Categoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('owner can create, edit and delete categorias', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    actingAs($user)->get(route('settings.categories.index'))->assertOk();

    actingAs($user)->post(route('settings.categories.store'), [
        'nombre' => 'Renta y servicios',
        'tipo' => 'egreso',
        'grupo' => 'gasto_operativo',
        'naturaleza' => 'fijo',
        'activo' => true,
        'orden' => 1,
    ])->assertRedirect(route('settings.categories.index'));

    $this->assertDatabaseHas('categorias', [
        'team_id' => $team->id,
        'nombre' => 'Renta y servicios',
        'tipo' => 'egreso',
        'grupo' => 'gasto_operativo',
        'naturaleza' => 'fijo',
    ]);

    $categoria = Categoria::withoutGlobalScopes()->where('nombre', 'Renta y servicios')->first();

    actingAs($user)->put(route('settings.categories.update', $categoria->id), [
        'nombre' => 'Servicios de desarrollo',
        'tipo' => 'ingreso',
        'grupo' => 'ingreso',
        'naturaleza' => null,
        'activo' => true,
        'orden' => 1,
    ])->assertRedirect(route('settings.categories.index'));

    $this->assertDatabaseHas('categorias', [
        'id' => $categoria->id,
        'tipo' => 'ingreso',
        'grupo' => 'ingreso',
        'naturaleza' => null,
    ]);

    actingAs($user)->delete(route('settings.categories.destroy', $categoria->id))->assertRedirect();
    $this->assertDatabaseMissing('categorias', ['id' => $categoria->id]);
});

it('rejects invalid enum values', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('settings.categories.store'), [
        'nombre' => 'Mala',
        'tipo' => 'otro',
        'grupo' => 'inexistente',
        'naturaleza' => 'rara',
    ])->assertSessionHasErrors(['tipo', 'grupo', 'naturaleza']);
});

it('rejects incoherent tipo/grupo/naturaleza combinations', function () {
    $user = User::factory()->create();

    // ingreso no puede vivir bajo un grupo de egreso ni llevar naturaleza
    actingAs($user)->post(route('settings.categories.store'), [
        'nombre' => 'Ingreso raro',
        'tipo' => 'ingreso',
        'grupo' => 'gasto_operativo',
        'naturaleza' => 'fijo',
    ])->assertSessionHasErrors(['grupo', 'naturaleza']);

    // egreso debe indicar naturaleza
    actingAs($user)->post(route('settings.categories.store'), [
        'nombre' => 'Egreso sin naturaleza',
        'tipo' => 'egreso',
        'grupo' => 'costo_venta',
        'naturaleza' => null,
    ])->assertSessionHasErrors('naturaleza');
});

it('denies a non-owner member of the same team (403)', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;

    $member = User::factory()->create();
    $member->forceFill(['current_team_id' => $team->id])->saveQuietly();

    actingAs($member)->post(route('settings.categories.store'), [
        'nombre' => 'X',
        'tipo' => 'egreso',
        'grupo' => 'gasto_operativo',
        'naturaleza' => 'fijo',
    ])->assertForbidden();
});

it('denies access to a categoria from another team (404)', function () {
    $userA = User::factory()->create();
    $categoriaA = Categoria::create([
        'team_id' => $userA->current_team_id,
        'nombre' => 'Team A Cat',
        'tipo' => 'egreso',
        'grupo' => 'gasto_operativo',
        'naturaleza' => 'fijo',
    ]);

    $userB = User::factory()->create();

    actingAs($userB)->put(route('settings.categories.update', $categoriaA->id), [
        'nombre' => 'Hack',
        'tipo' => 'egreso',
        'grupo' => 'gasto_operativo',
        'naturaleza' => 'fijo',
    ])->assertNotFound();
});
