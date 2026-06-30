<?php

use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function ownerConEmpresa(): array
{
    $owner = User::factory()->create();
    $empresa = Empresa::factory()->create(['team_id' => $owner->current_team_id]);

    return [$owner, $empresa];
}

it('lets the team owner create an employee', function () {
    [$owner, $empresa] = ownerConEmpresa();

    actingAs($owner)->get(route('employees.index'))->assertOk();

    actingAs($owner)->post(route('employees.store'), [
        'nombre' => 'Ada Lovelace', 'empresa_id' => $empresa->id,
        'fecha_entrada' => '2026-01-01', 'salario_fiscal' => 20000, 'salario_real' => 24000,
        'clasificacion' => 'tecnica', 'activo' => true,
    ])->assertRedirect(route('employees.index'));

    $emp = Empleado::withoutGlobalScopes()->where('nombre', 'Ada Lovelace')->first();
    expect($emp->team_id)->toBe($owner->current_team_id);
    expect($emp->user_id)->toBe($owner->id);
});

it('forbids a non-owner member from viewing or creating employees', function () {
    [$owner, $empresa] = ownerConEmpresa();
    $member = User::factory()->create();
    $member->forceFill(['current_team_id' => $owner->current_team_id])->saveQuietly();

    actingAs($member)->get(route('employees.index'))->assertForbidden();
    actingAs($member)->get(route('employees.create'))->assertForbidden();
    actingAs($member)->post(route('employees.store'), [
        'nombre' => 'X', 'empresa_id' => $empresa->id, 'fecha_entrada' => '2026-01-01',
        'salario_fiscal' => 1, 'salario_real' => 1,
    ])->assertForbidden();
});

it('rejects salaries <= 0, real < fiscal, missing empresa, and baja before entrada', function () {
    [$owner, $empresa] = ownerConEmpresa();
    $base = ['nombre' => 'X', 'empresa_id' => $empresa->id, 'fecha_entrada' => '2026-01-01'];

    actingAs($owner)->post(route('employees.store'), $base + ['salario_fiscal' => 0, 'salario_real' => 10])
        ->assertSessionHasErrors('salario_fiscal');
    actingAs($owner)->post(route('employees.store'), $base + ['salario_fiscal' => 20000, 'salario_real' => 10000])
        ->assertSessionHasErrors('salario_real');
    actingAs($owner)->post(route('employees.store'), ['nombre' => 'X', 'fecha_entrada' => '2026-01-01', 'salario_fiscal' => 1, 'salario_real' => 1])
        ->assertSessionHasErrors('empresa_id');
    actingAs($owner)->post(route('employees.store'), $base + ['salario_fiscal' => 1, 'salario_real' => 1, 'fecha_baja' => '2025-12-01'])
        ->assertSessionHasErrors('fecha_baja');
});

it('denies access to an employee from another team (404)', function () {
    [$ownerA, $empresaA] = ownerConEmpresa();
    $empA = Empleado::factory()->create(['team_id' => $ownerA->current_team_id, 'user_id' => $ownerA->id, 'empresa_id' => $empresaA->id]);

    $ownerB = User::factory()->create();
    actingAs($ownerB)->delete(route('employees.destroy', $empA->id))->assertNotFound();
});
