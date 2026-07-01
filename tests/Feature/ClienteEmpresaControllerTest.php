<?php

use App\Models\Archivo;
use App\Models\ClienteEmpresa;
use App\Models\Conciliacion;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\Movimiento;
use App\Models\User;
use App\Services\Finance\ClienteEmpresaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/** Crea un miembro NO-owner del team dado (el catálogo no requiere owner). */
function ceMiembro(int $teamId): User
{
    $member = User::factory()->create();
    $member->forceFill(['current_team_id' => $teamId])->saveQuietly();

    return $member;
}

/** Siembra una factura emitida (ingreso) del team para un rfc en una fecha dada. */
function ceFactura(int $teamId, int $userId, string $rfc, string $nombre, string $fecha): Factura
{
    $archivo = Archivo::factory()->create(['team_id' => $teamId, 'user_id' => $userId]);

    return Factura::factory()->create([
        'team_id' => $teamId,
        'user_id' => $userId,
        'file_id_xml' => $archivo->id,
        'rfc' => $rfc,
        'nombre' => $nombre,
        'fecha_emision' => $fecha,
    ]);
}

/** Crea una conciliación (grupo) enlazando factura + movimiento; empresa_id opcional. */
function ceGrupo(int $teamId, int $userId, string $groupId, string $rfc, ?int $empresaId = null): void
{
    $archivoMov = Archivo::factory()->create(['team_id' => $teamId, 'user_id' => $userId]);
    $mov = Movimiento::factory()->create(['team_id' => $teamId, 'user_id' => $userId, 'file_id' => $archivoMov->id, 'tipo' => 'abono']);
    $factura = ceFactura($teamId, $userId, $rfc, 'Cliente', '2026-06-15');

    Conciliacion::create([
        'team_id' => $teamId,
        'user_id' => $userId,
        'group_id' => $groupId,
        'empresa_id' => $empresaId,
        'factura_id' => $factura->id,
        'movimiento_id' => $mov->id,
        'monto_aplicado' => 100,
        'estatus' => 'conciliado',
        'tipo' => 'manual',
        'fecha_conciliacion' => '2026-06-15',
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// index
// ─────────────────────────────────────────────────────────────────────────────
it('renders the Clients/Index page with catalogo, empresas and recurrentes', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);

    (new ClienteEmpresaService)->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'Cliente Uno']], $empresa->id);

    actingAs($user)->get(route('clients.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clients/Index')
            ->has('catalogo', 1)
            ->has('empresas')
            ->has('recurrentes')
        );
});

it('allows any team member (non-owner) to view the catalog', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $member = ceMiembro($team->id);

    actingAs($member)->get(route('clients.index'))->assertOk();
});

// ─────────────────────────────────────────────────────────────────────────────
// update (override manual del default)
// ─────────────────────────────────────────────────────────────────────────────
it('lets a member override the default empresa of a catalog entry', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $member = ceMiembro($team->id);
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);

    $client = ClienteEmpresa::factory()->create(['team_id' => $team->id, 'empresa_id' => null]);

    actingAs($member)->patch(route('clients.update', $client->id), ['empresa_id' => $empresa->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($client->fresh()->empresa_id)->toBe($empresa->id);
});

it('rejects an empresa from another team (422)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $client = ClienteEmpresa::factory()->create(['team_id' => $team->id, 'empresa_id' => null]);

    $otherUser = User::factory()->create();
    $otherEmpresa = Empresa::factory()->create(['team_id' => $otherUser->current_team_id]);

    actingAs($user)->patch(route('clients.update', $client->id), ['empresa_id' => $otherEmpresa->id])
        ->assertSessionHasErrors('empresa_id');
});

// ─────────────────────────────────────────────────────────────────────────────
// update — exclusión (respetar etiquetas individuales)
// ─────────────────────────────────────────────────────────────────────────────
it('lets a member toggle excluido on and off via PATCH', function () {
    $owner = User::factory()->create();
    $team = $owner->currentTeam;
    $member = ceMiembro($team->id);

    $client = ClienteEmpresa::factory()->create(['team_id' => $team->id]);

    actingAs($member)->patch(route('clients.update', $client->id), ['excluido' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect($client->fresh()->excluido)->toBeTrue();

    actingAs($member)->patch(route('clients.update', $client->id), ['excluido' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect($client->fresh()->excluido)->toBeFalse();
});

it('toggling excluido does not clear the mapped empresa (queda inerte)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);
    $client = ClienteEmpresa::factory()->create(['team_id' => $team->id, 'empresa_id' => $empresa->id]);

    actingAs($user)->patch(route('clients.update', $client->id), ['excluido' => true])
        ->assertSessionHasNoErrors();

    $fresh = $client->fresh();
    expect($fresh->excluido)->toBeTrue()
        ->and($fresh->empresa_id)->toBe($empresa->id);
});

it('updating empresa_id alone does not reset excluido (PATCH parcial)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);
    $client = ClienteEmpresa::factory()->excluido()->create(['team_id' => $team->id, 'empresa_id' => null]);

    actingAs($user)->patch(route('clients.update', $client->id), ['empresa_id' => $empresa->id])
        ->assertSessionHasNoErrors();

    $fresh = $client->fresh();
    expect($fresh->empresa_id)->toBe($empresa->id)
        ->and($fresh->excluido)->toBeTrue();
});

it('rejects a PATCH without any recognized key (no silent no-op)', function () {
    $user = User::factory()->create();
    $client = ClienteEmpresa::factory()->create(['team_id' => $user->current_team_id]);

    // Payload vacío o con solo claves desconocidas (typo) → 422, no falso éxito.
    actingAs($user)->patch(route('clients.update', $client->id), [])
        ->assertSessionHasErrors('empresa_id');

    actingAs($user)->patch(route('clients.update', $client->id), ['excluded' => true])
        ->assertSessionHasErrors('empresa_id');

    expect($client->fresh()->excluido)->toBeFalse();
});

it('index exposes excluido in the catalogo payload', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    ClienteEmpresa::factory()->excluido()->create(['team_id' => $team->id]);

    actingAs($user)->get(route('clients.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clients/Index')
            ->where('catalogo.0.excluido', true)
        );
});

it('recurrentes shows no empresa for an excluded client', function () {
    Carbon::setTestNow('2026-07-15');

    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);

    // Cliente recurrente (may/jun/jul) con mapeo en catálogo pero excluido.
    ClienteEmpresa::factory()->excluido()->create([
        'team_id' => $team->id,
        'rfc' => 'XAXX010101000',
        'empresa_id' => $empresa->id,
    ]);
    ceFactura($team->id, $user->id, 'XAXX010101000', 'Público', '2026-05-10');
    ceFactura($team->id, $user->id, 'XAXX010101000', 'Público', '2026-06-10');
    ceFactura($team->id, $user->id, 'XAXX010101000', 'Público', '2026-07-10');

    actingAs($user)->get(route('clients.index'))
        ->assertInertia(function (Assert $page) {
            $recurrentes = collect($page->toArray()['props']['recurrentes']);
            $row = $recurrentes->firstWhere('rfc', 'XAXX010101000');

            expect($row)->not->toBeNull()
                ->and($row['empresa'])->toBeNull(); // el mapeo excluido no se muestra
        });

    Carbon::setTestNow();
});

// ─────────────────────────────────────────────────────────────────────────────
// aplicarSugerencias
// ─────────────────────────────────────────────────────────────────────────────
it('applies the catalog to reconciliations without a company and redirects', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);

    (new ClienteEmpresaService)->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'C']], $empresa->id);
    ceGrupo($team->id, $user->id, 'g1', 'AAA010101AAA');

    actingAs($user)->post(route('clients.apply'))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Conciliacion::withoutGlobalScopes()->where('group_id', 'g1')->value('empresa_id'))->toBe($empresa->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Tenancy
// ─────────────────────────────────────────────────────────────────────────────
it('does not leak another team catalog into the index', function () {
    $userA = User::factory()->create();
    $teamA = $userA->currentTeam;
    $empresaB = Empresa::factory()->create(['team_id' => $userA->current_team_id]);

    $userB = User::factory()->create();
    (new ClienteEmpresaService)->recordar($userB->current_team_id, $userB->id, [['rfc' => 'BBB020202BBB', 'nombre' => 'C-B']], Empresa::factory()->create(['team_id' => $userB->current_team_id])->id);

    // Team A no tiene entradas de catálogo.
    actingAs($userA)->get(route('clients.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Clients/Index')->has('catalogo', 0));
});

it('returns 404 when updating a catalog entry from another team', function () {
    $userA = User::factory()->create();
    $clientA = ClienteEmpresa::factory()->create(['team_id' => $userA->current_team_id]);

    $userB = User::factory()->create();

    actingAs($userB)->patch(route('clients.update', $clientA->id), ['empresa_id' => null])
        ->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Detección "dejó de facturar"
// ─────────────────────────────────────────────────────────────────────────────
it('flags a monthly client that skipped the current month and clears one that billed', function () {
    Carbon::setTestNow('2026-07-15');

    $user = User::factory()->create();
    $team = $user->currentTeam;

    // RFC recurrente con hueco el mes actual (facturó abr/may/jun, no jul).
    ceFactura($team->id, $user->id, 'AAA010101AAA', 'Recurrente Hueco', '2026-04-10');
    ceFactura($team->id, $user->id, 'AAA010101AAA', 'Recurrente Hueco', '2026-05-10');
    ceFactura($team->id, $user->id, 'AAA010101AAA', 'Recurrente Hueco', '2026-06-10');

    // RFC recurrente que sí facturó este mes (may/jun/jul).
    ceFactura($team->id, $user->id, 'BBB020202BBB', 'Recurrente Ok', '2026-05-10');
    ceFactura($team->id, $user->id, 'BBB020202BBB', 'Recurrente Ok', '2026-06-10');
    ceFactura($team->id, $user->id, 'BBB020202BBB', 'Recurrente Ok', '2026-07-10');

    actingAs($user)->get(route('clients.index'))
        ->assertInertia(function (Assert $page) {
            $recurrentes = collect($page->toArray()['props']['recurrentes']);

            $hueco = $recurrentes->firstWhere('rfc', 'AAA010101AAA');
            $ok = $recurrentes->firstWhere('rfc', 'BBB020202BBB');

            expect($hueco)->not->toBeNull()
                ->and($hueco['sin_factura_mes_actual'])->toBeTrue()
                ->and($ok)->not->toBeNull()
                ->and($ok['sin_factura_mes_actual'])->toBeFalse();
        });

    Carbon::setTestNow();
});
