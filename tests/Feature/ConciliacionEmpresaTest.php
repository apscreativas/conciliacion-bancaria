<?php

use App\Models\Archivo;
use App\Models\Banco;
use App\Models\Conciliacion;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\Movimiento;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * Crea una conciliación real (vía el endpoint, que pasa por MatcherService) y
 * devuelve su group_id. $montos define cuántos movimientos (y montos) se concilian
 * contra una factura por la suma total → permite probar grupos multi-fila.
 */
function makeReconciledGroup(User $user, Team $team, array $montos = [500.00]): string
{
    $archivo = Archivo::forceCreate([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'path' => 'dummy.xml',
        'mime' => 'application/xml',
        'size' => 123,
        'checksum' => Str::random(16),
        'estatus' => 'procesado',
    ]);

    $banco = Banco::forceCreate([
        'nombre' => 'Banco '.Str::random(4),
        'codigo' => Str::upper(Str::random(5)),
        'estatus' => 'activo',
    ]);

    $factura = Factura::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'file_id_xml' => $archivo->id,
        'uuid' => (string) Str::uuid(),
        'monto' => array_sum($montos),
        'fecha_emision' => '2026-01-10',
        'rfc' => 'AAA010101AAA',
        'nombre' => 'Cliente',
    ]);

    $movementIds = [];
    foreach ($montos as $monto) {
        $mov = Movimiento::create([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'banco_id' => $banco->id,
            'file_id' => $archivo->id,
            'fecha' => '2026-01-12',
            'monto' => $monto,
            'tipo' => 'abono',
            'descripcion' => 'Pago '.Str::random(4),
            'hash' => Str::random(40),
        ]);
        $movementIds[] = $mov->id;
    }

    actingAs($user)->post(route('reconciliation.store'), [
        'invoice_ids' => [$factura->id],
        'movement_ids' => $movementIds,
    ])->assertSessionHasNoErrors();

    return Conciliacion::withoutGlobalScopes()->where('factura_id', $factura->id)->firstOrFail()->group_id;
}

it('assigns an empresa to every row of a reconciled group', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::create(['team_id' => $team->id, 'nombre' => 'Tu Checador', 'slug' => 'tu-checador']);

    // grupo multi-fila (1 factura, 2 movimientos)
    $groupId = makeReconciledGroup($user, $team, [300.00, 200.00]);

    $rowCount = Conciliacion::withoutGlobalScopes()->where('group_id', $groupId)->count();
    expect($rowCount)->toBeGreaterThan(1);

    actingAs($user)->patch(route('reconciliation.group.empresa.update', $groupId), [
        'empresa_id' => $empresa->id,
    ])->assertRedirect();

    // TODAS las filas del grupo quedan con empresa_id
    expect(Conciliacion::withoutGlobalScopes()->where('group_id', $groupId)->where('empresa_id', $empresa->id)->count())
        ->toBe($rowCount);
});

it('unassigns the empresa when empresa_id is null', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::create(['team_id' => $team->id, 'nombre' => 'Domoticap', 'slug' => 'domoticap']);
    $groupId = makeReconciledGroup($user, $team);

    actingAs($user)->patch(route('reconciliation.group.empresa.update', $groupId), ['empresa_id' => $empresa->id])->assertRedirect();
    actingAs($user)->patch(route('reconciliation.group.empresa.update', $groupId), ['empresa_id' => null])->assertRedirect();

    expect(Conciliacion::withoutGlobalScopes()->where('group_id', $groupId)->whereNotNull('empresa_id')->count())->toBe(0);
});

it('does not alter monto_aplicado or row count when assigning empresa (gate financiero)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::create(['team_id' => $team->id, 'nombre' => 'AC', 'slug' => 'ac']);
    $groupId = makeReconciledGroup($user, $team, [300.00, 200.00]);

    $before = Conciliacion::withoutGlobalScopes()->where('group_id', $groupId)->get(['id', 'monto_aplicado']);

    actingAs($user)->patch(route('reconciliation.group.empresa.update', $groupId), ['empresa_id' => $empresa->id])->assertRedirect();

    $after = Conciliacion::withoutGlobalScopes()->where('group_id', $groupId)->get(['id', 'monto_aplicado']);
    expect($after->count())->toBe($before->count());
    expect($after->sum('monto_aplicado'))->toBe($before->sum('monto_aplicado'));
});

it('rejects an empresa from another team (422)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $groupId = makeReconciledGroup($user, $team);

    $otherUser = User::factory()->create();
    $otherEmpresa = Empresa::create(['team_id' => $otherUser->current_team_id, 'nombre' => 'Ajena', 'slug' => 'ajena']);

    actingAs($user)->patch(route('reconciliation.group.empresa.update', $groupId), [
        'empresa_id' => $otherEmpresa->id,
    ])->assertSessionHasErrors('empresa_id');

    expect(Conciliacion::withoutGlobalScopes()->where('group_id', $groupId)->whereNotNull('empresa_id')->count())->toBe(0);
});

it('returns 404 when assigning to a group from another team', function () {
    $userA = User::factory()->create();
    $teamA = $userA->currentTeam;
    $groupId = makeReconciledGroup($userA, $teamA);
    $empresaA = Empresa::create(['team_id' => $teamA->id, 'nombre' => 'A', 'slug' => 'a']);

    $userB = User::factory()->create();

    actingAs($userB)->patch(route('reconciliation.group.empresa.update', $groupId), [
        'empresa_id' => null,
    ])->assertNotFound();
});

it('is idempotent: re-assigning the same empresa does not 404 (MySQL changed-rows guard)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::create(['team_id' => $team->id, 'nombre' => 'TC', 'slug' => 'tc']);
    $groupId = makeReconciledGroup($user, $team);

    actingAs($user)->patch(route('reconciliation.group.empresa.update', $groupId), ['empresa_id' => $empresa->id])->assertRedirect();
    // Misma empresa otra vez → 0 filas CAMBIADAS en MySQL, pero el grupo existe → debe seguir OK.
    actingAs($user)->patch(route('reconciliation.group.empresa.update', $groupId), ['empresa_id' => $empresa->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('unassigning an already-unassigned group succeeds (no false 404)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $groupId = makeReconciledGroup($user, $team); // empresa_id arranca null

    actingAs($user)->patch(route('reconciliation.group.empresa.update', $groupId), ['empresa_id' => null])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('requires the empresa_id key to be present (empty payload does not silently unassign)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::create(['team_id' => $team->id, 'nombre' => 'X', 'slug' => 'x']);
    $groupId = makeReconciledGroup($user, $team);

    actingAs($user)->patch(route('reconciliation.group.empresa.update', $groupId), ['empresa_id' => $empresa->id])->assertRedirect();

    // PATCH sin la clave empresa_id → 422 (no des-asigna en silencio)
    actingAs($user)->patch(route('reconciliation.group.empresa.update', $groupId), [])
        ->assertSessionHasErrors('empresa_id');

    expect(Conciliacion::withoutGlobalScopes()->where('group_id', $groupId)->whereNotNull('empresa_id')->count())
        ->toBeGreaterThan(0);
});
