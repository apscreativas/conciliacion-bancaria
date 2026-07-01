<?php

use App\Models\Archivo;
use App\Models\Banco;
use App\Models\ClienteEmpresa;
use App\Models\Conciliacion;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\Movimiento;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('workbench page retrieves unreconciled items', function () {
    $user = User::factory()->create();
    $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Test Team', 'personal_team' => true]);
    $user->current_team_id = $team->id;
    $user->save();

    $archivo = Archivo::forceCreate([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'path' => 'dummy.xml',
        'mime' => 'application/xml',
        'size' => 123,
        'checksum' => 'hash',
        'estatus' => 'processed',
    ]);

    // Create Invoice
    Factura::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'file_id_xml' => $archivo->id,
        'uuid' => 'UUID-1',
        'monto' => 100.00,
        'fecha_emision' => '2026-01-15',
        'rfc' => 'TEST',
        'nombre' => 'Client',
    ]);

    $response = $this->actingAs($user)
        ->get(route('reconciliation.index', ['month' => 1, 'year' => 2026]));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reconciliation/Workbench')
            ->has('invoices', 1)
            ->has('movements', 0)
        );
});

test('auto reconciliation endpoint returns matches', function () {
    $user = User::factory()->create();
    $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Test Team', 'personal_team' => true]);
    $user->current_team_id = $team->id;
    $user->save();

    $archivo = Archivo::forceCreate([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'path' => 'dummy.xml',
        'mime' => 'application/xml',
        'size' => 123,
        'checksum' => 'hash',
        'estatus' => 'processed',
    ]);

    $banco = Banco::forceCreate(['nombre' => 'Bank', 'codigo' => 'B001']);

    // Match Pair
    $factura = Factura::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'file_id_xml' => $archivo->id,
        'uuid' => 'UUID-MATCH',
        'monto' => 100.00,
        'fecha_emision' => '2026-01-15',
        'rfc' => 'TEST',
        'nombre' => 'Client',
    ]);

    $movimiento = Movimiento::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'banco_id' => $banco->id,
        'file_id' => $archivo->id,
        'fecha' => '2026-01-15',
        'monto' => 100.00,
        'tipo' => 'abono',
        'descripcion' => 'Payment',
        'hash' => 'hash1',
    ]);

    // Use POST for auto
    $response = $this->actingAs($user)
        ->post(route('reconciliation.auto'), ['month' => 1, 'year' => 2026]);

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reconciliation/Matches')
            ->has('matches', 1)
            ->where('matches.0.invoice.uuid', 'UUID-MATCH')
        );
});

test('manual reconciliation stores record', function () {
    $user = User::factory()->create();
    $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Test Team', 'personal_team' => true]);
    $user->current_team_id = $team->id;
    $user->save();

    $archivo = Archivo::forceCreate([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'path' => 'dummy.xml',
        'mime' => 'application/xml',
        'size' => 123,
        'checksum' => 'hash',
        'estatus' => 'processed',
    ]);

    $banco = Banco::forceCreate(['nombre' => 'Bank', 'codigo' => 'B001']);

    $factura = Factura::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'file_id_xml' => $archivo->id,
        'uuid' => 'UUID-MANUAL',
        'monto' => 500.00,
        'fecha_emision' => '2026-01-10',
        'rfc' => 'TEST',
        'nombre' => 'Client',
    ]);

    $movimiento = Movimiento::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'banco_id' => $banco->id,
        'file_id' => $archivo->id,
        'fecha' => '2026-01-12',
        'monto' => 500.00,
        'tipo' => 'abono',
        'descripcion' => 'Payment Manual',
        'hash' => 'hash2',
    ]);

    $response = $this->actingAs($user)
        ->post(route('reconciliation.store'), [
            'invoice_ids' => [$factura->id],
            'movement_ids' => [$movimiento->id],
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('conciliacions', [ // Corrected table name
        'factura_id' => $factura->id,
        'movimiento_id' => $movimiento->id,
        'monto_aplicado' => 500.00,
        'estatus' => 'conciliado',
    ]);
});

test('multi-rfc reconciliation is blocked without confirmation', function () {
    $user = User::factory()->create();
    $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Test Team', 'personal_team' => true]);
    $user->current_team_id = $team->id;
    $user->save();

    $archivo = Archivo::forceCreate([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'path' => 'dummy.xml',
        'mime' => 'application/xml',
        'size' => 123,
        'checksum' => 'hash',
        'estatus' => 'processed',
    ]);

    $banco = Banco::forceCreate(['nombre' => 'Bank', 'codigo' => 'B001']);

    $facturaA = Factura::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'file_id_xml' => $archivo->id,
        'uuid' => 'UUID-A',
        'monto' => 300.00,
        'fecha_emision' => '2026-01-10',
        'rfc' => 'RFC-AAA',
        'nombre' => 'Client A',
    ]);

    $facturaB = Factura::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'file_id_xml' => $archivo->id,
        'uuid' => 'UUID-B',
        'monto' => 200.00,
        'fecha_emision' => '2026-01-11',
        'rfc' => 'RFC-BBB',
        'nombre' => 'Client B',
    ]);

    $movimiento = Movimiento::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'banco_id' => $banco->id,
        'file_id' => $archivo->id,
        'fecha' => '2026-01-12',
        'monto' => 500.00,
        'tipo' => 'abono',
        'descripcion' => 'Stripe Payout',
        'hash' => 'hash-multi-blocked',
    ]);

    $response = $this->actingAs($user)
        ->post(route('reconciliation.store'), [
            'invoice_ids' => [$facturaA->id, $facturaB->id],
            'movement_ids' => [$movimiento->id],
        ]);

    $response->assertSessionHasErrors('error');
    $this->assertDatabaseCount('conciliacions', 0);
});

test('multi-rfc reconciliation succeeds when explicitly confirmed', function () {
    $user = User::factory()->create();
    $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Test Team', 'personal_team' => true]);
    $user->current_team_id = $team->id;
    $user->save();

    $archivo = Archivo::forceCreate([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'path' => 'dummy.xml',
        'mime' => 'application/xml',
        'size' => 123,
        'checksum' => 'hash',
        'estatus' => 'processed',
    ]);

    $banco = Banco::forceCreate(['nombre' => 'Bank', 'codigo' => 'B001']);

    $facturaA = Factura::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'file_id_xml' => $archivo->id,
        'uuid' => 'UUID-A2',
        'monto' => 300.00,
        'fecha_emision' => '2026-01-10',
        'rfc' => 'RFC-AAA',
        'nombre' => 'Client A',
    ]);

    $facturaB = Factura::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'file_id_xml' => $archivo->id,
        'uuid' => 'UUID-B2',
        'monto' => 200.00,
        'fecha_emision' => '2026-01-11',
        'rfc' => 'RFC-BBB',
        'nombre' => 'Client B',
    ]);

    $movimiento = Movimiento::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'banco_id' => $banco->id,
        'file_id' => $archivo->id,
        'fecha' => '2026-01-12',
        'monto' => 500.00,
        'tipo' => 'abono',
        'descripcion' => 'Stripe Payout',
        'hash' => 'hash-multi-ok',
    ]);

    $response = $this->actingAs($user)
        ->post(route('reconciliation.store'), [
            'invoice_ids' => [$facturaA->id, $facturaB->id],
            'movement_ids' => [$movimiento->id],
            'confirm_multi_rfc' => true,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('conciliacions', [
        'factura_id' => $facturaA->id,
        'movimiento_id' => $movimiento->id,
    ]);
    $this->assertDatabaseHas('conciliacions', [
        'factura_id' => $facturaB->id,
        'movimiento_id' => $movimiento->id,
    ]);
});

test('cannot reconcile items from another team', function () {
    $user = User::factory()->create();
    $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'My Team', 'personal_team' => true]);
    $user->current_team_id = $team->id;
    $user->save();

    $otherUser = User::factory()->create();
    $otherTeam = Team::forceCreate(['user_id' => $otherUser->id, 'name' => 'Other Team', 'personal_team' => true]); // Added personal_team

    $archivo = Archivo::forceCreate([
        'user_id' => $otherUser->id,
        'team_id' => $otherTeam->id,
        'path' => 'dummy.xml',
        'mime' => 'application/xml',
        'size' => 123,
        'checksum' => 'hash',
        'estatus' => 'processed',
    ]);

    // Invoice belongs to OTHER team
    $factura = Factura::create([
        'user_id' => $otherUser->id,
        'team_id' => $otherTeam->id,
        'file_id_xml' => $archivo->id,
        'uuid' => 'UUID-OTHER',
        'monto' => 100.00,
        'fecha_emision' => '2026-01-15',
        'rfc' => 'TEST',
        'nombre' => 'Client',
    ]);

    $banco = Banco::forceCreate(['nombre' => 'Bank', 'codigo' => 'B001']);

    // Movement belongs to MY team (valid)
    $movimiento = Movimiento::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'banco_id' => $banco->id,
        'file_id' => $archivo->id,
        'fecha' => '2026-01-15',
        'monto' => 100.00,
        'tipo' => 'abono',
        'descripcion' => 'Payment',
        'hash' => 'hash3',
    ]);

    // Try to reconcile My Movement with Other Invoice
    // Expecting 500 or Exception because IDOR check -> 'Invalid or unauthorized records selected.'
    // Since MatcherService throws generic Exception, Laravel might render 500 in test.
    // However, without handling, it bubbles up.

    // Let's expect an exception.
    $this->withoutExceptionHandling();
    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid or unauthorized records selected');

    $this->actingAs($user)
        ->post(route('reconciliation.store'), [
            'invoice_ids' => [$factura->id],
            'movement_ids' => [$movimiento->id],
        ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Catálogo cliente→empresa: aprendizaje y auto-asignación (solo ingresos, aditivo).
// ─────────────────────────────────────────────────────────────────────────────
function ceSetup(): array
{
    $user = User::factory()->create();
    $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'CE Team', 'personal_team' => true]);
    $user->current_team_id = $team->id;
    $user->save();

    $archivo = Archivo::forceCreate([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'path' => 'dummy.xml',
        'mime' => 'application/xml',
        'size' => 123,
        'checksum' => 'hash-ce-'.uniqid(),
        'estatus' => 'processed',
    ]);
    $banco = Banco::firstOrCreate(['codigo' => 'B001'], ['nombre' => 'Bank']);

    return [$user, $team, $archivo, $banco];
}

test('updateGroupEmpresa aprende el mapeo cliente→empresa', function () {
    [$user, $team, $archivo, $banco] = ceSetup();
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);

    $factura = Factura::create([
        'user_id' => $user->id, 'team_id' => $team->id, 'file_id_xml' => $archivo->id,
        'uuid' => 'UUID-LEARN', 'monto' => 500.00, 'fecha_emision' => '2026-01-10',
        'rfc' => 'LEARN010101AAA', 'nombre' => 'Cliente Learn',
    ]);
    $movimiento = Movimiento::create([
        'user_id' => $user->id, 'team_id' => $team->id, 'banco_id' => $banco->id, 'file_id' => $archivo->id,
        'fecha' => '2026-01-12', 'monto' => 500.00, 'tipo' => 'abono', 'descripcion' => 'Pago', 'hash' => 'h-learn',
    ]);

    // Concilia manualmente (RFC desconocido → sin empresa aún)
    $this->actingAs($user)->post(route('reconciliation.store'), [
        'invoice_ids' => [$factura->id], 'movement_ids' => [$movimiento->id],
    ])->assertRedirect();

    $groupId = Conciliacion::where('factura_id', $factura->id)->value('group_id');

    // Asigna empresa al grupo → debe aprender el mapeo
    $this->actingAs($user)->patch(route('reconciliation.group.empresa.update', $groupId), [
        'empresa_id' => $empresa->id,
    ])->assertRedirect();

    $this->assertDatabaseHas('cliente_empresas', [
        'team_id' => $team->id,
        'rfc' => 'LEARN010101AAA',
        'empresa_id' => $empresa->id,
    ]);
});

test('store auto-asigna la empresa cuando el RFC ya está en el catálogo', function () {
    [$user, $team, $archivo, $banco] = ceSetup();
    $empresa = Empresa::factory()->create(['team_id' => $team->id]);

    // Catálogo pre-cargado: RFC conocido → empresa
    ClienteEmpresa::create([
        'team_id' => $team->id, 'rfc' => 'KNOWN010101AAA', 'nombre' => 'Cliente Conocido',
        'empresa_id' => $empresa->id, 'veces' => 1, 'ultima_asignacion_at' => now(), 'user_id' => $user->id,
    ]);

    $factura = Factura::create([
        'user_id' => $user->id, 'team_id' => $team->id, 'file_id_xml' => $archivo->id,
        'uuid' => 'UUID-KNOWN', 'monto' => 300.00, 'fecha_emision' => '2026-01-10',
        'rfc' => 'KNOWN010101AAA', 'nombre' => 'Cliente Conocido',
    ]);
    $movimiento = Movimiento::create([
        'user_id' => $user->id, 'team_id' => $team->id, 'banco_id' => $banco->id, 'file_id' => $archivo->id,
        'fecha' => '2026-01-12', 'monto' => 300.00, 'tipo' => 'abono', 'descripcion' => 'Pago', 'hash' => 'h-known',
    ]);

    $this->actingAs($user)->post(route('reconciliation.store'), [
        'invoice_ids' => [$factura->id], 'movement_ids' => [$movimiento->id],
    ])->assertRedirect();

    $this->assertDatabaseHas('conciliacions', [
        'factura_id' => $factura->id,
        'empresa_id' => $empresa->id,
    ]);
});

test('store deja el grupo sin empresa cuando el RFC es desconocido', function () {
    [$user, $team, $archivo, $banco] = ceSetup();

    $factura = Factura::create([
        'user_id' => $user->id, 'team_id' => $team->id, 'file_id_xml' => $archivo->id,
        'uuid' => 'UUID-UNK', 'monto' => 300.00, 'fecha_emision' => '2026-01-10',
        'rfc' => 'UNKNOWN010101X', 'nombre' => 'Cliente Nuevo',
    ]);
    $movimiento = Movimiento::create([
        'user_id' => $user->id, 'team_id' => $team->id, 'banco_id' => $banco->id, 'file_id' => $archivo->id,
        'fecha' => '2026-01-12', 'monto' => 300.00, 'tipo' => 'abono', 'descripcion' => 'Pago', 'hash' => 'h-unk',
    ]);

    $this->actingAs($user)->post(route('reconciliation.store'), [
        'invoice_ids' => [$factura->id], 'movement_ids' => [$movimiento->id],
    ])->assertRedirect();

    $this->assertDatabaseHas('conciliacions', [
        'factura_id' => $factura->id,
        'empresa_id' => null,
    ]);
});
