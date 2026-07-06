<?php

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * Crea un miembro del team del owner CON fila en el pivot team_user y el rol dado.
 * (A diferencia de los helpers viejos que solo hacen forceFill de current_team_id.)
 */
function tarMiembro(User $owner, string $role = 'member'): User
{
    $member = User::factory()->create();
    $member->forceFill(['current_team_id' => $owner->current_team_id])->saveQuietly();
    $owner->currentTeam->users()->attach($member->id, ['role' => $role]);

    return $member;
}

// ─────────────────────────────────────────────────────────────────────────────
// Rol admin = owner-equivalente en los módulos "solo owner"
// ─────────────────────────────────────────────────────────────────────────────
it('lets an admin member view the executive dashboard', function () {
    $owner = User::factory()->create();
    $admin = tarMiembro($owner, 'admin');

    actingAs($admin)->get(route('executive', ['month' => 6, 'year' => 2026]))->assertOk();
});

it('still forbids a plain member from the executive dashboard', function () {
    $owner = User::factory()->create();
    $member = tarMiembro($owner, 'member');

    actingAs($member)->get(route('executive', ['month' => 6, 'year' => 2026]))->assertForbidden();
});

it('lets an admin member view employees and forbids a plain member', function () {
    $owner = User::factory()->create();
    $admin = tarMiembro($owner, 'admin');
    $member = tarMiembro($owner, 'member');

    actingAs($admin)->get(route('employees.index'))->assertOk();
    actingAs($member)->get(route('employees.index'))->assertForbidden();
});

it('lets an admin member view and update the tolerance', function () {
    $owner = User::factory()->create();
    $admin = tarMiembro($owner, 'admin');

    actingAs($admin)->get(route('settings.tolerance'))->assertOk();
    actingAs($admin)->post(route('settings.tolerance.update'), ['monto' => 12.5])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('lets an admin member mutate empresas (policy create)', function () {
    $owner = User::factory()->create();
    $admin = tarMiembro($owner, 'admin');

    actingAs($admin)->post(route('settings.companies.store'), [
        'nombre' => 'Empresa Admin',
        'color' => '#112233',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Empresa::withoutGlobalScopes()
        ->where('team_id', $owner->current_team_id)
        ->where('nombre', 'Empresa Admin')
        ->exists())->toBeTrue();
});

it('does not grant admin of one team anything in another team', function () {
    $ownerA = User::factory()->create();
    $adminA = tarMiembro($ownerA, 'admin');

    // Cambia su contexto a un team ajeno (sin membresía): no debe administrar nada.
    $ownerB = User::factory()->create();
    $adminA->forceFill(['current_team_id' => $ownerB->current_team_id])->saveQuietly();

    actingAs($adminA)->get(route('executive', ['month' => 6, 'year' => 2026]))->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Prop compartido manages_team (sidebar)
// ─────────────────────────────────────────────────────────────────────────────
it('shares manages_team=true for owner and admin, false for plain member', function () {
    $owner = User::factory()->create();
    $admin = tarMiembro($owner, 'admin');
    $member = tarMiembro($owner, 'member');

    actingAs($owner)->get(route('clients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.manages_team', true));
    actingAs($admin)->get(route('clients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.manages_team', true));
    actingAs($member)->get(route('clients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.manages_team', false));
});

// ─────────────────────────────────────────────────────────────────────────────
// Comando team:member-role
// ─────────────────────────────────────────────────────────────────────────────
it('sets a member role to admin via the artisan command', function () {
    $owner = User::factory()->create();
    $member = tarMiembro($owner, 'member');

    $this->artisan('team:member-role', ['email' => $member->email, 'role' => 'admin'])
        ->assertSuccessful();

    expect($member->fresh()->teams()->first()->pivot->role)->toBe('admin');
});

it('rejects an invalid role, a missing user and a team owner', function () {
    $owner = User::factory()->create();
    $member = tarMiembro($owner, 'member');

    $this->artisan('team:member-role', ['email' => $member->email, 'role' => 'owner'])
        ->assertFailed();

    $this->artisan('team:member-role', ['email' => 'nadie@x.com', 'role' => 'admin'])
        ->assertFailed();

    // El owner no tiene fila pivot en su propio team → no gestionable por comando.
    $this->artisan('team:member-role', ['email' => $owner->email, 'role' => 'admin'])
        ->assertFailed();
});
