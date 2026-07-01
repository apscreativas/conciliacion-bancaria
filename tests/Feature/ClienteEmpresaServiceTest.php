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

uses(RefreshDatabase::class);

/**
 * Crea una conciliación (grupo) enlazando una factura (con rfc/nombre) y un movimiento.
 * team_id/user_id explícitos → funciona con o sin auth (queue-safe).
 */
function ceConciliacion(int $teamId, int $userId, string $groupId, string $rfc, string $nombre, ?int $empresaId = null): Conciliacion
{
    $archivoMov = Archivo::factory()->create(['team_id' => $teamId, 'user_id' => $userId]);
    $movimiento = Movimiento::factory()->create([
        'team_id' => $teamId,
        'user_id' => $userId,
        'file_id' => $archivoMov->id,
        'tipo' => 'abono',
    ]);

    $archivoFac = Archivo::factory()->create(['team_id' => $teamId, 'user_id' => $userId]);
    $factura = Factura::factory()->create([
        'team_id' => $teamId,
        'user_id' => $userId,
        'file_id_xml' => $archivoFac->id,
        'rfc' => $rfc,
        'nombre' => $nombre,
    ]);

    return Conciliacion::create([
        'team_id' => $teamId,
        'user_id' => $userId,
        'group_id' => $groupId,
        'empresa_id' => $empresaId,
        'factura_id' => $factura->id,
        'movimiento_id' => $movimiento->id,
        'monto_aplicado' => 100,
        'estatus' => 'conciliado',
        'tipo' => 'manual',
        'fecha_conciliacion' => '2026-06-15',
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// recordar
// ─────────────────────────────────────────────────────────────────────────────
it('recordar crea el mapeo por rfc con nombre, empresa, user, fecha y veces=1', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);

    (new ClienteEmpresaService)->recordar($team->id, $user->id, [
        ['rfc' => 'AAA010101AAA', 'nombre' => 'Cliente Uno'],
    ], $empresa->id);

    $row = ClienteEmpresa::withoutGlobalScopes()->where('team_id', $team->id)->where('rfc', 'AAA010101AAA')->first();
    expect($row)->not->toBeNull()
        ->and($row->nombre)->toBe('Cliente Uno')
        ->and($row->empresa_id)->toBe($empresa->id)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->veces)->toBe(1)
        ->and($row->ultima_asignacion_at)->not->toBeNull();
});

it('recordar es last-wins por rfc e incrementa veces solo al cambiar de empresa', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresaA = Empresa::factory()->create(['team_id' => $team->id]);
    $empresaB = Empresa::factory()->create(['team_id' => $team->id]);
    $svc = new ClienteEmpresaService;

    $svc->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'Nombre Viejo']], $empresaA->id);
    $svc->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'Nombre Nuevo']], $empresaB->id);

    $row = ClienteEmpresa::withoutGlobalScopes()->where('team_id', $team->id)->where('rfc', 'AAA010101AAA')->first();
    expect($row->empresa_id)->toBe($empresaB->id) // last-wins
        ->and($row->nombre)->toBe('Nombre Nuevo')
        ->and($row->veces)->toBe(2); // nueva (1) + cambio A→B (2)
});

it('recordar NO incrementa veces al re-asignar la MISMA empresa (solo refresca nombre/fecha)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);
    $svc = new ClienteEmpresaService;

    $svc->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'Nombre Viejo']], $empresa->id);
    $svc->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'Nombre Nuevo']], $empresa->id);

    $row = ClienteEmpresa::withoutGlobalScopes()->where('team_id', $team->id)->where('rfc', 'AAA010101AAA')->first();
    expect($row->empresa_id)->toBe($empresa->id)
        ->and($row->nombre)->toBe('Nombre Nuevo') // se refresca el nombre
        ->and($row->veces)->toBe(1); // misma empresa → NO incrementa
});

it('recordar deduplica rfc dentro del mismo lote (una sola fila, veces=1)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);

    (new ClienteEmpresaService)->recordar($team->id, $user->id, [
        ['rfc' => 'AAA010101AAA', 'nombre' => 'Cliente'],
        ['rfc' => 'AAA010101AAA', 'nombre' => 'Cliente Dup'],
    ], $empresa->id);

    $rows = ClienteEmpresa::withoutGlobalScopes()->where('team_id', $team->id)->where('rfc', 'AAA010101AAA')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->veces)->toBe(1);
});

it('recordar acepta modelos Factura además de arrays', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);
    $archivo = Archivo::factory()->create(['team_id' => $team->id, 'user_id' => $user->id]);
    $factura = Factura::factory()->create(['team_id' => $team->id, 'user_id' => $user->id, 'file_id_xml' => $archivo->id, 'rfc' => 'BBB020202BBB', 'nombre' => 'Cliente Modelo']);

    (new ClienteEmpresaService)->recordar($team->id, $user->id, [$factura], $empresa->id);

    $row = ClienteEmpresa::withoutGlobalScopes()->where('team_id', $team->id)->where('rfc', 'BBB020202BBB')->first();
    expect($row)->not->toBeNull()
        ->and($row->empresa_id)->toBe($empresa->id)
        ->and($row->nombre)->toBe('Cliente Modelo');
});

// ─────────────────────────────────────────────────────────────────────────────
// sugerirEmpresa
// ─────────────────────────────────────────────────────────────────────────────
it('sugerirEmpresa devuelve la empresa para un rfc conocido (mono-rfc)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);
    (new ClienteEmpresaService)->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'C']], $empresa->id);

    expect((new ClienteEmpresaService)->sugerirEmpresa($team->id, ['AAA010101AAA']))->toBe($empresa->id);
});

it('sugerirEmpresa devuelve la empresa cuando varios rfc mapean a la misma', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);
    $svc = new ClienteEmpresaService;
    $svc->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'C1']], $empresa->id);
    $svc->recordar($team->id, $user->id, [['rfc' => 'BBB020202BBB', 'nombre' => 'C2']], $empresa->id);

    expect($svc->sugerirEmpresa($team->id, ['AAA010101AAA', 'BBB020202BBB']))->toBe($empresa->id);
});

it('sugerirEmpresa devuelve null cuando los rfc mapean a empresas distintas (ambiguo)', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresaA = Empresa::factory()->create(['team_id' => $team->id]);
    $empresaB = Empresa::factory()->create(['team_id' => $team->id]);
    $svc = new ClienteEmpresaService;
    $svc->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'C1']], $empresaA->id);
    $svc->recordar($team->id, $user->id, [['rfc' => 'BBB020202BBB', 'nombre' => 'C2']], $empresaB->id);

    expect($svc->sugerirEmpresa($team->id, ['AAA010101AAA', 'BBB020202BBB']))->toBeNull();
});

it('sugerirEmpresa devuelve null cuando ningún rfc está en el catálogo', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    expect((new ClienteEmpresaService)->sugerirEmpresa($team->id, ['ZZZ999999ZZZ']))->toBeNull();
});

it('sugerirEmpresa es estricto: multi-rfc con uno sin mapeo → null', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);
    (new ClienteEmpresaService)->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'C']], $empresa->id);

    // AAA mapea; ZZZ no. Al haber un RFC sin mapeo, NO se sugiere empresa (estricto):
    // estampar la empresa de AAA a todo el grupo mal-etiquetaría el ingreso de ZZZ.
    expect((new ClienteEmpresaService)->sugerirEmpresa($team->id, ['AAA010101AAA', 'ZZZ999999ZZZ']))->toBeNull();
});

it('sugerirEmpresa mono-rfc sin mapeo → null', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    expect((new ClienteEmpresaService)->sugerirEmpresa($team->id, ['ZZZ999999ZZZ']))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// aplicarASinEmpresa
// ─────────────────────────────────────────────────────────────────────────────
it('aplicarASinEmpresa asigna grupos sin empresa con sugerencia unívoca y deja ambiguos/sin-mapeo', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresaA = Empresa::factory()->create(['team_id' => $team->id]);
    $empresaB = Empresa::factory()->create(['team_id' => $team->id]);
    $svc = new ClienteEmpresaService;

    // Catálogo aprendido
    $svc->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'C-A']], $empresaA->id);
    $svc->recordar($team->id, $user->id, [['rfc' => 'BBB020202BBB', 'nombre' => 'C-B']], $empresaB->id);

    // Grupo 1: rfc conocido → debe asignarse a empresaA
    ceConciliacion($team->id, $user->id, 'g1', 'AAA010101AAA', 'C-A');
    // Grupo 2: multi-rfc ambiguo (A y B) → debe quedar null
    ceConciliacion($team->id, $user->id, 'g2', 'AAA010101AAA', 'C-A');
    ceConciliacion($team->id, $user->id, 'g2', 'BBB020202BBB', 'C-B');
    // Grupo 3: rfc desconocido → debe quedar null
    ceConciliacion($team->id, $user->id, 'g3', 'ZZZ999999ZZZ', 'Desconocido');
    // Grupo 4: ya tiene empresa → no se toca ni se cuenta
    ceConciliacion($team->id, $user->id, 'g4', 'AAA010101AAA', 'C-A', $empresaB->id);

    $count = $svc->aplicarASinEmpresa($team->id);

    expect($count)->toBe(1);
    expect(Conciliacion::withoutGlobalScopes()->where('group_id', 'g1')->value('empresa_id'))->toBe($empresaA->id);
    expect(Conciliacion::withoutGlobalScopes()->where('group_id', 'g2')->value('empresa_id'))->toBeNull();
    expect(Conciliacion::withoutGlobalScopes()->where('group_id', 'g3')->value('empresa_id'))->toBeNull();
    // g4 permanece con su empresa original
    expect(Conciliacion::withoutGlobalScopes()->where('group_id', 'g4')->value('empresa_id'))->toBe($empresaB->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Exclusión (respetar etiquetas individuales)
// ─────────────────────────────────────────────────────────────────────────────
it('recordar salta un rfc excluido: conserva empresa, nombre, veces y fecha intactos', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresaA = Empresa::factory()->create(['team_id' => $team->id]);
    $empresaB = Empresa::factory()->create(['team_id' => $team->id]);

    $cliente = ClienteEmpresa::factory()->excluido()->create([
        'team_id' => $team->id,
        'rfc' => 'XAXX010101000',
        'nombre' => 'Nombre Original',
        'empresa_id' => $empresaA->id,
        'veces' => 2,
        'ultima_asignacion_at' => '2026-06-01 10:00:00',
    ]);

    (new ClienteEmpresaService)->recordar($team->id, $user->id, [
        ['rfc' => 'XAXX010101000', 'nombre' => 'Nombre Nuevo'],
    ], $empresaB->id);

    $fresh = $cliente->fresh();
    expect($fresh->empresa_id)->toBe($empresaA->id) // NO last-wins
        ->and($fresh->nombre)->toBe('Nombre Original')
        ->and($fresh->veces)->toBe(2)
        ->and($fresh->ultima_asignacion_at->toDateTimeString())->toBe('2026-06-01 10:00:00')
        ->and($fresh->excluido)->toBeTrue();
});

it('recordar en lote mixto aprende los rfc no excluidos y salta el excluido', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresaA = Empresa::factory()->create(['team_id' => $team->id]);
    $empresaB = Empresa::factory()->create(['team_id' => $team->id]);

    ClienteEmpresa::factory()->excluido()->create([
        'team_id' => $team->id,
        'rfc' => 'XAXX010101000',
        'empresa_id' => $empresaA->id,
        'veces' => 2,
    ]);

    (new ClienteEmpresaService)->recordar($team->id, $user->id, [
        ['rfc' => 'XAXX010101000', 'nombre' => 'Público'],
        ['rfc' => 'AAA010101AAA', 'nombre' => 'Cliente Normal'],
    ], $empresaB->id);

    // El excluido no cambió; el normal sí aprendió.
    expect(ClienteEmpresa::withoutGlobalScopes()->where('team_id', $team->id)->where('rfc', 'XAXX010101000')->value('empresa_id'))->toBe($empresaA->id);
    expect(ClienteEmpresa::withoutGlobalScopes()->where('team_id', $team->id)->where('rfc', 'AAA010101AAA')->value('empresa_id'))->toBe($empresaB->id);
});

it('sugerirEmpresa devuelve null para un rfc excluido aunque tenga empresa mapeada', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);

    ClienteEmpresa::factory()->excluido()->create([
        'team_id' => $team->id,
        'rfc' => 'XAXX010101000',
        'empresa_id' => $empresa->id,
    ]);

    expect((new ClienteEmpresaService)->sugerirEmpresa($team->id, ['XAXX010101000']))->toBeNull();
});

it('sugerirEmpresa trata el rfc excluido como bloqueante en grupo multi-rfc', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);
    $svc = new ClienteEmpresaService;

    // Ambos mapean a la MISMA empresa, pero uno está excluido → null igual.
    $svc->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'C']], $empresa->id);
    ClienteEmpresa::factory()->excluido()->create([
        'team_id' => $team->id,
        'rfc' => 'XAXX010101000',
        'empresa_id' => $empresa->id,
    ]);

    expect($svc->sugerirEmpresa($team->id, ['AAA010101AAA', 'XAXX010101000']))->toBeNull();
});

it('aplicarASinEmpresa salta los grupos que contienen un rfc excluido y asigna el resto', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);
    $svc = new ClienteEmpresaService;

    $svc->recordar($team->id, $user->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'C']], $empresa->id);
    ClienteEmpresa::factory()->excluido()->create([
        'team_id' => $team->id,
        'rfc' => 'XAXX010101000',
        'empresa_id' => $empresa->id,
    ]);

    // Grupo 1: rfc normal → se asigna. Grupo 2: rfc excluido → se salta.
    ceConciliacion($team->id, $user->id, 'g1', 'AAA010101AAA', 'C');
    ceConciliacion($team->id, $user->id, 'g2', 'XAXX010101000', 'Público');

    $count = $svc->aplicarASinEmpresa($team->id);

    expect($count)->toBe(1);
    expect(Conciliacion::withoutGlobalScopes()->where('group_id', 'g1')->value('empresa_id'))->toBe($empresa->id);
    expect(Conciliacion::withoutGlobalScopes()->where('group_id', 'g2')->value('empresa_id'))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// rfcsDeGrupo
// ─────────────────────────────────────────────────────────────────────────────
it('rfcsDeGrupo devuelve rfc/nombre únicos por rfc del grupo', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    ceConciliacion($team->id, $user->id, 'grp', 'AAA010101AAA', 'Cliente A');
    ceConciliacion($team->id, $user->id, 'grp', 'AAA010101AAA', 'Cliente A dup'); // mismo rfc
    ceConciliacion($team->id, $user->id, 'grp', 'BBB020202BBB', 'Cliente B');

    $rfcs = (new ClienteEmpresaService)->rfcsDeGrupo('grp', $team->id);

    expect(collect($rfcs)->pluck('rfc')->sort()->values()->all())->toBe(['AAA010101AAA', 'BBB020202BBB']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Tenancy
// ─────────────────────────────────────────────────────────────────────────────
it('no cruza tenancy: catálogo de otro team no entra en sugerirEmpresa', function () {
    $userA = User::factory()->create();
    $teamA = $userA->currentTeam;
    $userB = User::factory()->create();
    $teamB = $userB->currentTeam;

    $empresaB = Empresa::factory()->create(['team_id' => $teamB->id]);
    (new ClienteEmpresaService)->recordar($teamB->id, $userB->id, [['rfc' => 'AAA010101AAA', 'nombre' => 'C-B']], $empresaB->id);

    // Team A no conoce ese rfc.
    expect((new ClienteEmpresaService)->sugerirEmpresa($teamA->id, ['AAA010101AAA']))->toBeNull();
});
