<?php

use App\Models\Categoria;
use App\Models\Egreso;
use App\Models\Empleado;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

/** Crea las 3 categorías de nómina sembradas para un team y devuelve [nombre => Categoria]. */
function categoriasNomina(int $teamId): array
{
    $cats = [
        'Nómina fiscal' => 'gasto_operativo',
        'Nómina complemento / real' => 'gasto_operativo',
        'Nómina técnica facturable' => 'costo_venta',
    ];
    $out = [];
    foreach ($cats as $nombre => $grupo) {
        $out[$nombre] = Categoria::factory()->create([
            'team_id' => $teamId, 'nombre' => $nombre, 'tipo' => 'egreso', 'grupo' => $grupo, 'activo' => true,
        ]);
    }

    return $out;
}

function empleado(User $user, array $attrs = []): Empleado
{
    return Empleado::factory()->create(array_merge([
        'team_id' => $user->current_team_id,
        'user_id' => $user->id,
    ], $attrs));
}

function nominaDe(Empleado $e)
{
    return Egreso::withoutGlobalScopes()->where('empleado_id', $e->id);
}

it('generates fiscal + complemento for both quincenas (fixed numeric case)', function () {
    Carbon::setTestNow('2026-06-30');
    $user = User::factory()->create();
    $cats = categoriasNomina($user->current_team_id);
    $e = empleado($user, [
        'salario_fiscal' => 20000, 'salario_real' => 24000,
        'clasificacion' => 'administrativa', 'fecha_entrada' => '2026-01-01',
    ]);

    $this->artisan('nomina:generar --month=2026-06')->assertSuccessful();

    $egresos = nominaDe($e)->get();
    expect($egresos)->toHaveCount(4); // 2 quincenas × (fiscal + complemento)
    expect((float) nominaDe($e)->sum('monto'))->toBe(24000.0);

    $fiscal = nominaDe($e)->where('concepto_nomina', 'fiscal')->where('fecha', '2026-06-15')->first();
    expect((float) $fiscal->monto)->toBe(10000.0);
    expect($fiscal->categoria_id)->toBe($cats['Nómina fiscal']->id);
    expect($fiscal->origen)->toBe('recurrente');

    $comp = nominaDe($e)->where('concepto_nomina', 'complemento')->where('fecha', '2026-06-15')->first();
    expect((float) $comp->monto)->toBe(2000.0);
    expect($comp->categoria_id)->toBe($cats['Nómina complemento / real']->id);
});

it('routes the fiscal part of a tecnica employee to the COGS category', function () {
    Carbon::setTestNow('2026-06-30');
    $user = User::factory()->create();
    $cats = categoriasNomina($user->current_team_id);
    $e = empleado($user, ['clasificacion' => 'tecnica', 'fecha_entrada' => '2026-01-01']);

    $this->artisan('nomina:generar --month=2026-06')->assertSuccessful();

    $fiscal = nominaDe($e)->where('concepto_nomina', 'fiscal')->first();
    expect($fiscal->categoria_id)->toBe($cats['Nómina técnica facturable']->id);
});

it('omits the complemento egreso when salario_real == salario_fiscal', function () {
    Carbon::setTestNow('2026-06-30');
    $user = User::factory()->create();
    categoriasNomina($user->current_team_id);
    $e = empleado($user, ['salario_fiscal' => 18000, 'salario_real' => 18000, 'fecha_entrada' => '2026-01-01']);

    $this->artisan('nomina:generar --month=2026-06')->assertSuccessful();

    expect(nominaDe($e)->where('concepto_nomina', 'complemento')->count())->toBe(0);
    expect(nominaDe($e)->where('concepto_nomina', 'fiscal')->count())->toBe(2);
});

it('is idempotent across re-runs', function () {
    Carbon::setTestNow('2026-06-30');
    $user = User::factory()->create();
    categoriasNomina($user->current_team_id);
    $e = empleado($user, ['fecha_entrada' => '2026-01-01']);

    $this->artisan('nomina:generar --month=2026-06')->assertSuccessful();
    $this->artisan('nomina:generar --month=2026-06')->assertSuccessful();

    expect(nominaDe($e)->count())->toBe(4);
});

it('does not duplicate the fiscal egreso when clasificacion changes between runs', function () {
    Carbon::setTestNow('2026-06-30');
    $user = User::factory()->create();
    categoriasNomina($user->current_team_id);
    $e = empleado($user, ['clasificacion' => 'administrativa', 'fecha_entrada' => '2026-01-01']);

    $this->artisan('nomina:generar --month=2026-06')->assertSuccessful();
    $e->update(['clasificacion' => 'tecnica']);
    $this->artisan('nomina:generar --month=2026-06')->assertSuccessful();

    // Misma quincena, mismo concepto → un solo egreso fiscal por fecha (clave concepto_nomina).
    expect(nominaDe($e)->where('concepto_nomina', 'fiscal')->where('fecha', '2026-06-15')->count())->toBe(1);
});

it('enforces one nomina egreso per (empleado, fecha, concepto) at the DB level', function () {
    $user = User::factory()->create();
    $cats = categoriasNomina($user->current_team_id);
    $e = empleado($user);

    $payload = [
        'team_id' => $user->current_team_id, 'categoria_id' => $cats['Nómina fiscal']->id,
        'empleado_id' => $e->id, 'concepto_nomina' => 'fiscal', 'fecha' => '2026-06-15',
        'monto' => 100, 'descripcion' => 'x', 'origen' => 'recurrente', 'user_id' => $user->id,
    ];
    Egreso::create($payload);

    expect(fn () => Egreso::create(['descripcion' => 'y'] + $payload))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('does not generate a quincena before fecha_entrada (nominal eligibility)', function () {
    Carbon::setTestNow('2026-06-30');
    $user = User::factory()->create();
    categoriasNomina($user->current_team_id);
    // Alta el 16 de junio: la quincena Q1 (nominal 15) NO aplica; Q2 (nominal 30) sí.
    $e = empleado($user, ['fecha_entrada' => '2026-06-16']);

    $this->artisan('nomina:generar --month=2026-06')->assertSuccessful();

    expect(nominaDe($e)->where('fecha', '2026-06-15')->count())->toBe(0);
    expect(nominaDe($e)->where('fecha', '2026-06-30')->count())->toBe(2);
});

it('stops generating after fecha_baja', function () {
    Carbon::setTestNow('2026-06-30');
    $user = User::factory()->create();
    categoriasNomina($user->current_team_id);
    // Baja el 20 de junio: Q1 (nominal 15 <= 20) aplica; Q2 (nominal 30 > 20) no.
    $e = empleado($user, ['fecha_entrada' => '2026-01-01', 'fecha_baja' => '2026-06-20']);

    $this->artisan('nomina:generar --month=2026-06')->assertSuccessful();

    expect(nominaDe($e)->where('fecha', '2026-06-15')->count())->toBe(2);
    expect(nominaDe($e)->where('fecha', '2026-06-30')->count())->toBe(0);
});

it('skips inactive employees', function () {
    Carbon::setTestNow('2026-06-30');
    $user = User::factory()->create();
    categoriasNomina($user->current_team_id);
    $e = empleado($user, ['activo' => false, 'fecha_entrada' => '2026-01-01']);

    $this->artisan('nomina:generar --month=2026-06')->assertSuccessful();

    expect(nominaDe($e)->count())->toBe(0);
});

it('does not crash and skips when a required category is missing', function () {
    Carbon::setTestNow('2026-06-30');
    $user = User::factory()->create();
    // Sin categorías de nómina sembradas.
    $e = empleado($user, ['fecha_entrada' => '2026-01-01']);

    $this->artisan('nomina:generar --month=2026-06')->assertSuccessful();

    expect(nominaDe($e)->count())->toBe(0);
});

it('generates per team with the correct team_id and empleado_id', function () {
    Carbon::setTestNow('2026-06-30');
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    categoriasNomina($userA->current_team_id);
    categoriasNomina($userB->current_team_id);
    $eA = empleado($userA, ['fecha_entrada' => '2026-01-01']);
    $eB = empleado($userB, ['fecha_entrada' => '2026-01-01']);

    $this->artisan('nomina:generar --month=2026-06')->assertSuccessful();

    expect(nominaDe($eA)->first()->team_id)->toBe($userA->current_team_id);
    expect(nominaDe($eB)->first()->team_id)->toBe($userB->current_team_id);
});

it('does not persist anything on --dry-run', function () {
    Carbon::setTestNow('2026-06-30');
    $user = User::factory()->create();
    categoriasNomina($user->current_team_id);
    $e = empleado($user, ['fecha_entrada' => '2026-01-01']);

    $this->artisan('nomina:generar --month=2026-06 --dry-run')->assertSuccessful();

    expect(nominaDe($e)->count())->toBe(0);
});
