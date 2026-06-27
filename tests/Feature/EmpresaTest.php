<?php

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('owner can list, create, edit and delete empresas', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    actingAs($user)->get(route('settings.empresas.index'))->assertOk();

    actingAs($user)->post(route('settings.empresas.store'), [
        'nombre' => 'Domoticap',
        'color' => '#f59e0b',
        'activo' => true,
        'orden' => 1,
    ])->assertRedirect(route('settings.empresas.index'));

    $this->assertDatabaseHas('empresas', [
        'team_id' => $team->id,
        'nombre' => 'Domoticap',
        'slug' => 'domoticap',
    ]);

    $empresa = Empresa::withoutGlobalScopes()->where('nombre', 'Domoticap')->first();

    actingAs($user)->put(route('settings.empresas.update', $empresa->id), [
        'nombre' => 'Domoticap Seguridad',
        'activo' => false,
        'orden' => 2,
    ])->assertRedirect(route('settings.empresas.index'));

    $this->assertDatabaseHas('empresas', [
        'id' => $empresa->id,
        'nombre' => 'Domoticap Seguridad',
        'activo' => false,
    ]);

    actingAs($user)->delete(route('settings.empresas.destroy', $empresa->id))->assertRedirect();
    $this->assertDatabaseMissing('empresas', ['id' => $empresa->id]);
});

it('validates required nombre and unique per team', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('settings.empresas.store'), ['nombre' => ''])
        ->assertSessionHasErrors('nombre');

    actingAs($user)->post(route('settings.empresas.store'), ['nombre' => 'Acme'])->assertRedirect();
    actingAs($user)->post(route('settings.empresas.store'), ['nombre' => 'Acme'])
        ->assertSessionHasErrors('nombre');
});

it('denies a non-owner member of the same team (403)', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;

    $member = User::factory()->create();
    $member->forceFill(['current_team_id' => $team->id])->saveQuietly();

    actingAs($member)->post(route('settings.empresas.store'), ['nombre' => 'X'])
        ->assertForbidden();
});

it('denies access to an empresa from another team (404)', function () {
    $userA = User::factory()->create();
    $empresaA = Empresa::create([
        'team_id' => $userA->current_team_id,
        'nombre' => 'Team A Co',
        'slug' => 'team-a-co',
    ]);

    $userB = User::factory()->create();

    actingAs($userB)->put(route('settings.empresas.update', $empresaA->id), ['nombre' => 'Hack'])
        ->assertNotFound();

    actingAs($userB)->delete(route('settings.empresas.destroy', $empresaA->id))
        ->assertNotFound();
});
