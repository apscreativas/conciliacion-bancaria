<?php

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('owner can list, create, edit and delete empresas', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    actingAs($user)->get(route('settings.companies.index'))->assertOk();

    actingAs($user)->post(route('settings.companies.store'), [
        'nombre' => 'Domoticap',
        'color' => '#f59e0b',
        'activo' => true,
        'orden' => 1,
    ])->assertRedirect(route('settings.companies.index'));

    $this->assertDatabaseHas('empresas', [
        'team_id' => $team->id,
        'nombre' => 'Domoticap',
        'slug' => 'domoticap',
    ]);

    $empresa = Empresa::withoutGlobalScopes()->where('nombre', 'Domoticap')->first();

    actingAs($user)->put(route('settings.companies.update', $empresa->id), [
        'nombre' => 'Domoticap Seguridad',
        'activo' => false,
        'orden' => 2,
    ])->assertRedirect(route('settings.companies.index'));

    $this->assertDatabaseHas('empresas', [
        'id' => $empresa->id,
        'nombre' => 'Domoticap Seguridad',
        'slug' => 'domoticap-seguridad',
        'activo' => false,
    ]);

    actingAs($user)->delete(route('settings.companies.destroy', $empresa->id))->assertRedirect();
    $this->assertDatabaseMissing('empresas', ['id' => $empresa->id]);
});

it('validates required nombre and unique per team', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('settings.companies.store'), ['nombre' => ''])
        ->assertSessionHasErrors('nombre');

    actingAs($user)->post(route('settings.companies.store'), ['nombre' => 'Acme'])->assertRedirect();
    actingAs($user)->post(route('settings.companies.store'), ['nombre' => 'Acme'])
        ->assertSessionHasErrors('nombre');
});

it('returns a validation error (not a 500) when two distinct names slugify to the same slug', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('settings.companies.store'), ['nombre' => 'Acme Co'])->assertRedirect();

    // "Acme  Co" (doble espacio) y "Acmé Co" producen el mismo slug 'acme-co'
    actingAs($user)->post(route('settings.companies.store'), ['nombre' => 'Acmé Co'])
        ->assertSessionHasErrors('slug');

    expect(Empresa::withoutGlobalScopes()->where('team_id', $user->current_team_id)->count())->toBe(1);
});

it('rejects a name with no alphanumeric characters (empty slug)', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('settings.companies.store'), ['nombre' => '###'])
        ->assertSessionHasErrors('slug');
});

it('denies a non-owner member of the same team (403)', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;

    $member = User::factory()->create();
    $member->forceFill(['current_team_id' => $team->id])->saveQuietly();

    actingAs($member)->post(route('settings.companies.store'), ['nombre' => 'X'])
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

    actingAs($userB)->put(route('settings.companies.update', $empresaA->id), ['nombre' => 'Hack'])
        ->assertNotFound();

    actingAs($userB)->delete(route('settings.companies.destroy', $empresaA->id))
        ->assertNotFound();
});
