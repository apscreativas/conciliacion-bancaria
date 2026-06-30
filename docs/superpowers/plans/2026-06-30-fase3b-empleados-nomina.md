# Fase 3B — Empleados + nómina quincenal — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrar la plantilla de personal (`empleados`) y generar la nómina quincenal (parte fiscal + complemento) como `egresos`, de forma idempotente y por empresa/centro de costo.

**Architecture:** Tabla `empleados` (TeamOwned) + CRUD `/employees` solo-owner. Comando `nomina:generar` (síncrono, schedule diario) que, para cada quincena (día 15 + último día del mes, ajustada a día hábil anterior) dentro de una ventana móvil de 40 días, crea por empleado activo dos egresos (fiscal + complemento) idempotentes vía un discriminador `concepto_nomina` y un índice único. Reusa los patrones de hardening de Fase 3.

**Tech Stack:** Laravel 12, Pest 4, MySQL, Inertia v2 + Vue 3 (TS), Carbon.

**Base branch:** `feature/finanzas-fase3b` (ya creada desde `develop`). SDD fuente: `docs/sdd/04-empleados-nomina.md`.

**Convenciones clave (de CLAUDE.md y Fase 3):**
- Dominio en español (`Empleado`, `salario_fiscal`); rutas/Vue en inglés (`employees`, `Employees/`).
- Tests: Pest, `RefreshDatabase`, `actingAs`, factories. MySQL local vía Herd (arrancar el servicio MySQL antes de correr tests).
- Correr: `php artisan test --compact --filter=<X>`. Pint: `vendor/bin/pint --dirty`. Build: `npm run build`.
- Commit trailer: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- Baseline: 13 tests fallan de base; criterio = **0 regresiones nuevas**.

---

## File Structure

**Crear:**
- `database/migrations/2026_06_30_000001_create_empleados_table.php`
- `database/migrations/2026_06_30_000002_add_empleado_to_egresos_table.php`
- `app/Models/Empleado.php`
- `app/Policies/EmpleadoPolicy.php`
- `app/Services/Finance/PayrollCalculator.php`
- `app/Console/Commands/GenerarNomina.php`
- `app/Http/Controllers/EmpleadoController.php`
- `app/Http/Requests/EmpleadoRequest.php`
- `database/factories/EmpleadoFactory.php`
- `resources/js/Pages/Employees/Index.vue`
- `resources/js/Pages/Employees/Create.vue`
- `tests/Unit/PayrollCalculatorTest.php`
- `tests/Feature/GenerarNominaTest.php`
- `tests/Feature/EmpleadoTest.php`

**Modificar:**
- `app/Models/Egreso.php` (fillable `empleado_id`, `concepto_nomina`; relación `empleado()`)
- `routes/web.php` (resource `employees`)
- `routes/console.php` (schedule `nomina:generar`)
- `lang/es.json`, `lang/en.json` (claves UI)
- `docs/domain.md`, `docs/endpoints.md`, `docs/operations.md`, `docs/business-rules.md`, `docs/security.md`, `docs/prd/finanzas-egresos-multiempresa.md`

---

## Task 1: Migración `empleados`

**Files:**
- Create: `database/migrations/2026_06_30_000001_create_empleados_table.php`

- [ ] **Step 1: Escribir la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas')->nullOnDelete();

            $table->string('nombre');
            $table->string('puesto')->nullable();
            $table->date('fecha_entrada');
            $table->date('fecha_baja')->nullable();
            $table->decimal('salario_fiscal', 15, 2); // mensual
            $table->decimal('salario_real', 15, 2);   // mensual
            $table->enum('clasificacion', ['tecnica', 'administrativa'])->nullable();
            $table->boolean('activo')->default(true);

            // nullOnDelete: borrar al usuario creador NO borra al empleado (registro financiero).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
```

- [ ] **Step 2: Correr la migración para verificar que aplica**

Run: `php artisan migrate`
Expected: `... create_empleados_table ... DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_30_000001_create_empleados_table.php
git commit -m "feat(finanzas): migración empleados (Fase 3B)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Migración — alterar `egresos` (empleado_id, concepto_nomina, índice único, user_id nullable)

**Files:**
- Create: `database/migrations/2026_06_30_000002_add_empleado_to_egresos_table.php`

- [ ] **Step 1: Escribir la migración**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('egresos', function (Blueprint $table) {
            $table->foreignId('empleado_id')->nullable()->after('egreso_recurrente_id')
                ->constrained('empleados')->nullOnDelete();

            // Discriminador de nómina: desacopla la idempotencia de la categoría (mutable
            // por clasificacion). NULL para egresos manuales/recurrentes.
            $table->enum('concepto_nomina', ['fiscal', 'complemento'])->nullable()->after('origen');

            // Idempotencia en DB: un egreso de nómina por (empleado, fecha, concepto).
            // NULLs múltiples permitidos → no afecta egresos manuales/recurrentes.
            $table->unique(['empleado_id', 'fecha', 'concepto_nomina'], 'egresos_empleado_periodo_unique');
        });

        // user_id pasa a nullable + nullOnDelete: el generador puede insertar user_id null
        // (empleado.user_id es nullOnDelete) y un registro financiero debe sobrevivir al
        // borrado de su creador. Cierra el hallazgo #9 de Fase 3.
        Schema::table('egresos', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('egresos', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });
        Schema::table('egresos', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('egresos', function (Blueprint $table) {
            $table->dropUnique('egresos_empleado_periodo_unique');
            $table->dropForeign(['empleado_id']);
            $table->dropColumn(['empleado_id', 'concepto_nomina']);
        });

        // Best-effort: restaurar user_id a cascadeOnDelete (no NOT NULL si hay filas null).
        Schema::table('egresos', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('egresos', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
```

- [ ] **Step 2: Correr la migración**

Run: `php artisan migrate`
Expected: `... add_empleado_to_egresos_table ... DONE`

- [ ] **Step 3: Verificar que la suite de egresos sigue verde (user_id nullable es backward-compatible)**

Run: `php artisan test --compact --filter=EgresoTest`
Expected: PASS (sin regresiones; `EgresoController::store`/`EgresoFactory` siempre setean `user_id`).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_30_000002_add_empleado_to_egresos_table.php
git commit -m "feat(finanzas): egresos.empleado_id + concepto_nomina + user_id nullable (Fase 3B)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Modelos `Empleado` + `Egreso` + `EmpleadoFactory`

**Files:**
- Create: `app/Models/Empleado.php`
- Create: `database/factories/EmpleadoFactory.php`
- Modify: `app/Models/Egreso.php`

- [ ] **Step 1: Crear el modelo `Empleado`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    use \App\Models\Traits\TeamOwned;
    use HasFactory;

    protected $table = 'empleados';

    protected $fillable = [
        'team_id',
        'empresa_id',
        'nombre',
        'puesto',
        'fecha_entrada',
        'fecha_baja',
        'salario_fiscal',
        'salario_real',
        'clasificacion',
        'activo',
        'user_id',
    ];

    protected $casts = [
        'fecha_entrada' => 'date',
        'fecha_baja' => 'date',
        'salario_fiscal' => 'decimal:2',
        'salario_real' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function egresos()
    {
        return $this->hasMany(Egreso::class);
    }
}
```

- [ ] **Step 2: Crear la factory**

```php
<?php

namespace Database\Factories;

use App\Models\Empresa;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Empleado>
 */
class EmpleadoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'empresa_id' => null,
            'nombre' => $this->faker->name(),
            'puesto' => $this->faker->optional()->jobTitle(),
            'fecha_entrada' => '2026-01-01',
            'fecha_baja' => null,
            'salario_fiscal' => 20000,
            'salario_real' => 24000,
            'clasificacion' => 'administrativa',
            'activo' => true,
            'user_id' => User::factory(),
        ];
    }
}
```

- [ ] **Step 3: Añadir `empleado_id`/`concepto_nomina` al fillable de `Egreso` y la relación `empleado()`**

En `app/Models/Egreso.php`, dentro de `$fillable` agregar `'empleado_id'` y `'concepto_nomina'` justo después de `'egreso_recurrente_id'`:

```php
    protected $fillable = [
        'team_id',
        'empresa_id',
        'categoria_id',
        'egreso_recurrente_id',
        'empleado_id',
        'concepto_nomina',
        'fecha',
        'monto',
        'descripcion',
        'proveedor',
        'metodo_pago',
        'comprobante_path',
        'origen',
        'user_id',
    ];
```

Y añadir la relación después de `egresoRecurrente()`:

```php
    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }
```

- [ ] **Step 4: Verificar que carga sin errores**

Run: `php artisan test --compact --filter=EgresoTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Empleado.php database/factories/EmpleadoFactory.php app/Models/Egreso.php
git commit -m "feat(finanzas): modelo Empleado + factory + relaciones en Egreso (Fase 3B)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: `EmpleadoPolicy` (solo-owner en TODAS las habilidades)

**Files:**
- Create: `app/Policies/EmpleadoPolicy.php`

- [ ] **Step 1: Crear la policy**

> A diferencia de `EmpresaPolicy`, `viewAny`/`view` también gatean en `ownsCurrentTeam` (los salarios son sensibles). Laravel 12 auto-descubre la policy por convención de nombres (`Empleado` → `EmpleadoPolicy`); no requiere registro manual.

```php
<?php

namespace App\Policies;

use App\Models\Empleado;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamOwnership;

class EmpleadoPolicy
{
    use ChecksTeamOwnership;

    public function viewAny(User $user): bool
    {
        return $this->ownsCurrentTeam($user);
    }

    public function view(User $user, Empleado $empleado): bool
    {
        return $this->ownsCurrentTeam($user) && $empleado->team_id === $user->current_team_id;
    }

    public function create(User $user): bool
    {
        return $this->ownsCurrentTeam($user);
    }

    public function update(User $user, Empleado $empleado): bool
    {
        return $this->ownsCurrentTeam($user) && $empleado->team_id === $user->current_team_id;
    }

    public function delete(User $user, Empleado $empleado): bool
    {
        return $this->ownsCurrentTeam($user) && $empleado->team_id === $user->current_team_id;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Policies/EmpleadoPolicy.php
git commit -m "feat(finanzas): EmpleadoPolicy solo-owner en todas las habilidades (Fase 3B)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: `PayrollCalculator` (fechas de quincena) — TDD

**Files:**
- Create: `tests/Unit/PayrollCalculatorTest.php`
- Create: `app/Services/Finance/PayrollCalculator.php`

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

use App\Services\Finance\PayrollCalculator;
use App\Services\Finance\RecurrenceCalculator;

function calc(): PayrollCalculator
{
    return new PayrollCalculator(new RecurrenceCalculator());
}

it('returns the two quincena dates of a month with no weekend adjustment', function () {
    // Junio 2026: día 15 = lunes, fin de mes 30 = martes (sin ajuste).
    $q = calc()->quincenas(2026, 6);

    expect($q)->toHaveCount(2);
    expect($q[0]['nominal']->toDateString())->toBe('2026-06-15');
    expect($q[0]['pago']->toDateString())->toBe('2026-06-15');
    expect($q[1]['nominal']->toDateString())->toBe('2026-06-30');
    expect($q[1]['pago']->toDateString())->toBe('2026-06-30');
});

it('adjusts an end-of-month that falls on Sunday to the previous Friday', function () {
    // Mayo 2026: fin de mes 31 = domingo → pago viernes 29.
    $q = calc()->quincenas(2026, 5);

    expect($q[1]['nominal']->toDateString())->toBe('2026-05-31');
    expect($q[1]['pago']->toDateString())->toBe('2026-05-29');
});

it('adjusts a day-15 that falls on Saturday to the previous Friday', function () {
    // Agosto 2026: día 15 = sábado → pago viernes 14.
    $q = calc()->quincenas(2026, 8);

    expect($q[0]['nominal']->toDateString())->toBe('2026-08-15');
    expect($q[0]['pago']->toDateString())->toBe('2026-08-14');
});

it('uses the real last day for short months', function () {
    // Febrero 2026 (no bisiesto) → fin de mes 28.
    $q = calc()->quincenas(2026, 2);

    expect($q[1]['nominal']->toDateString())->toBe('2026-02-28');
});
```

- [ ] **Step 2: Correr el test y verificar que falla**

Run: `php artisan test --compact --filter=PayrollCalculatorTest`
Expected: FAIL ("Class PayrollCalculator not found").

- [ ] **Step 3: Implementar `PayrollCalculator`**

```php
<?php

namespace App\Services\Finance;

use Carbon\Carbon;

/**
 * Fechas de pago de la nómina quincenal (Finanzas Fase 3B): día 15 y último día del mes,
 * ajustadas al día hábil anterior si caen en fin de semana. Reusa el ajuste de día hábil
 * de RecurrenceCalculator (CLAUDE.md §3.5, no duplicar lógica). Sin festivos en v1.
 */
class PayrollCalculator
{
    public function __construct(private RecurrenceCalculator $habil) {}

    /**
     * Las dos quincenas del mes como pares ['nominal' => Carbon, 'pago' => Carbon].
     * 'nominal' define elegibilidad/periodo; 'pago' es la fecha del egreso.
     *
     * @return array<int, array{nominal: Carbon, pago: Carbon}>
     */
    public function quincenas(int $year, int $month): array
    {
        $q1 = Carbon::create($year, $month, 15)->startOfDay();
        $q2 = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();

        return [
            ['nominal' => $q1, 'pago' => $this->habil->applyDiaHabil($q1, 'habil_anterior')],
            ['nominal' => $q2, 'pago' => $this->habil->applyDiaHabil($q2, 'habil_anterior')],
        ];
    }
}
```

- [ ] **Step 4: Correr el test y verificar que pasa**

Run: `php artisan test --compact --filter=PayrollCalculatorTest`
Expected: PASS (4 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Finance/PayrollCalculator.php tests/Unit/PayrollCalculatorTest.php
git commit -m "feat(finanzas): PayrollCalculator (fechas de quincena con día hábil) (Fase 3B)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Comando `nomina:generar` — TDD

**Files:**
- Create: `app/Console/Commands/GenerarNomina.php`
- Create: `tests/Feature/GenerarNominaTest.php`

- [ ] **Step 1: Escribir los tests que fallan**

```php
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
```

- [ ] **Step 2: Correr los tests y verificar que fallan**

Run: `php artisan test --compact --filter=GenerarNominaTest`
Expected: FAIL ("Command 'nomina:generar' is not defined").

- [ ] **Step 3: Implementar el comando**

```php
<?php

namespace App\Console\Commands;

use App\Models\Categoria;
use App\Models\Egreso;
use App\Models\Empleado;
use App\Services\Finance\PayrollCalculator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerarNomina extends Command
{
    protected $signature = 'nomina:generar
        {--month= : Mes objetivo YYYY-MM (omite la ventana móvil; default: ventana de 40 días)}
        {--dry-run : Reporta sin persistir}';

    protected $description = 'Genera los egresos de nómina quincenal (fiscal + complemento) por empleado activo (idempotente).';

    /** Ventana móvil de catch-up en días (outage más largo → usar --month). */
    private const VENTANA_DIAS = 40;

    private const CAT_FISCAL = 'Nómina fiscal';
    private const CAT_TECNICA = 'Nómina técnica facturable';
    private const CAT_COMPLEMENTO = 'Nómina complemento / real';

    public function handle(PayrollCalculator $calc): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = now()->startOfDay();

        [$desde, $quincenas] = $this->quincenasObjetivo($calc, $today);

        $creados = 0;
        $omitidosCategoria = 0;
        $omitidosComplemento = 0;
        $catCache = []; // team_id => [nombre => id|null]

        // Sin Auth en un comando → desactivamos el global scope explícitamente (CLAUDE.md §1.3).
        $empleados = Empleado::withoutGlobalScopes()->where('activo', true)->get();

        foreach ($quincenas as $q) {
            $nominal = $q['nominal'];
            $pago = $q['pago'];
            $qLabel = $nominal->day === 15 ? 'Q1' : 'Q2';

            foreach ($empleados as $emp) {
                // Elegibilidad por fecha NOMINAL.
                if ($emp->fecha_entrada->copy()->startOfDay()->gt($nominal)) {
                    continue;
                }
                if ($emp->fecha_baja && $nominal->gt($emp->fecha_baja->copy()->startOfDay())) {
                    continue;
                }

                $cats = $catCache[$emp->team_id] ??= $this->resolverCategorias($emp->team_id);

                // Parte fiscal.
                $catFiscalNombre = $emp->clasificacion === 'tecnica' ? self::CAT_TECNICA : self::CAT_FISCAL;
                $montoFiscal = round(((float) $emp->salario_fiscal) / 2, 2);
                if ($cats[$catFiscalNombre] === null) {
                    $omitidosCategoria++;
                    Log::warning("[nomina:generar] Team #{$emp->team_id} sin categoría '{$catFiscalNombre}'; se omite fiscal de empleado #{$emp->id}.");
                } else {
                    $creados += $this->generar($emp, $pago, 'fiscal', $cats[$catFiscalNombre], $montoFiscal, "Nómina fiscal {$qLabel} — {$emp->nombre}", $dryRun);
                }

                // Parte complemento (solo si > 0).
                $complemento = (float) $emp->salario_real - (float) $emp->salario_fiscal;
                if ($complemento <= 0) {
                    $omitidosComplemento++;
                } elseif ($cats[self::CAT_COMPLEMENTO] === null) {
                    $omitidosCategoria++;
                    Log::warning("[nomina:generar] Team #{$emp->team_id} sin categoría '".self::CAT_COMPLEMENTO."'; se omite complemento de empleado #{$emp->id}.");
                } else {
                    $montoComp = round($complemento / 2, 2);
                    $creados += $this->generar($emp, $pago, 'complemento', $cats[self::CAT_COMPLEMENTO], $montoComp, "Nómina complemento {$qLabel} — {$emp->nombre}", $dryRun);
                }
            }
        }

        $prefix = $dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}Nómina desde {$desde->toDateString()}: {$creados} egresos creados; omitidos por categoría: {$omitidosCategoria}; complemento ≤ 0: {$omitidosComplemento}.");

        return self::SUCCESS;
    }

    /**
     * Devuelve [fechaDesde, quincenas]. Con --month apunta a ese mes; sin él, la ventana móvil.
     * Siempre filtra nominal <= hoy (no pre-genera futuro).
     *
     * @return array{0: Carbon, 1: array<int, array{nominal: Carbon, pago: Carbon}>}
     */
    private function quincenasObjetivo(PayrollCalculator $calc, Carbon $today): array
    {
        if ($mes = $this->option('month')) {
            $ref = Carbon::createFromFormat('Y-m', $mes)->startOfMonth();
            $desde = $ref->copy()->startOfMonth();
            $quincenas = array_filter(
                $calc->quincenas((int) $ref->year, (int) $ref->month),
                fn ($q) => $q['nominal']->lte($today),
            );

            return [$desde, array_values($quincenas)];
        }

        $desde = $today->copy()->subDays(self::VENTANA_DIAS);
        $quincenas = [];
        $cursor = $desde->copy()->startOfMonth();
        while ($cursor->lte($today)) {
            foreach ($calc->quincenas((int) $cursor->year, (int) $cursor->month) as $q) {
                if ($q['nominal']->betweenIncluded($desde, $today)) {
                    $quincenas[] = $q;
                }
            }
            $cursor->addMonthNoOverflow();
        }

        return [$desde, $quincenas];
    }

    /** Resuelve las 3 categorías de nómina del team (activas) por nombre exacto. */
    private function resolverCategorias(int $teamId): array
    {
        $base = Categoria::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('tipo', 'egreso')
            ->where('activo', true)
            ->whereIn('nombre', [self::CAT_FISCAL, self::CAT_TECNICA, self::CAT_COMPLEMENTO])
            ->pluck('id', 'nombre');

        return [
            self::CAT_FISCAL => $base[self::CAT_FISCAL] ?? null,
            self::CAT_TECNICA => $base[self::CAT_TECNICA] ?? null,
            self::CAT_COMPLEMENTO => $base[self::CAT_COMPLEMENTO] ?? null,
        ];
    }

    /** Crea un egreso de nómina idempotente. Devuelve 1 si lo creó, 0 si ya existía. */
    private function generar(Empleado $emp, Carbon $pago, string $concepto, int $categoriaId, float $monto, string $descripcion, bool $dryRun): int
    {
        $yaExiste = Egreso::query()
            ->where('empleado_id', $emp->id)
            ->where('fecha', $pago->toDateString())
            ->where('concepto_nomina', $concepto)
            ->exists();

        if ($yaExiste || $dryRun) {
            return 0;
        }

        return DB::transaction(function () use ($emp, $pago, $concepto, $categoriaId, $monto, $descripcion) {
            try {
                Egreso::create([
                    'team_id' => $emp->team_id,
                    'empresa_id' => $emp->empresa_id,
                    'categoria_id' => $categoriaId,
                    'empleado_id' => $emp->id,
                    'concepto_nomina' => $concepto,
                    'fecha' => $pago->toDateString(),
                    'monto' => $monto,
                    'descripcion' => $descripcion,
                    'origen' => 'recurrente',
                    'user_id' => $emp->user_id,
                ]);

                return 1;
            } catch (QueryException $e) {
                // Carrera (manual vs cron): el índice único rechaza el duplicado.
                if ($this->isDuplicate($e)) {
                    return 0;
                }
                throw $e;
            }
        });
    }

    /** Violación de UNIQUE (SQLSTATE 23000 / código MySQL 1062). */
    private function isDuplicate(QueryException $e): bool
    {
        return $e->getCode() === '23000' || (int) ($e->errorInfo[1] ?? 0) === 1062;
    }
}
```

- [ ] **Step 4: Correr los tests y verificar que pasan**

Run: `php artisan test --compact --filter=GenerarNominaTest`
Expected: PASS (12 passed).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/GenerarNomina.php tests/Feature/GenerarNominaTest.php
git commit -m "feat(finanzas): comando nomina:generar quincenal idempotente (Fase 3B)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: CRUD `/employees` — `EmpleadoRequest` + `EmpleadoController` + ruta — TDD

**Files:**
- Create: `app/Http/Requests/EmpleadoRequest.php`
- Create: `app/Http/Controllers/EmpleadoController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/EmpleadoTest.php`

- [ ] **Step 1: Escribir los tests que fallan**

```php
<?php

use App\Models\Categoria;
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
```

> Nota: `Categoria` y `Empresa` son del mismo team; `empresa_id` se valida con `Rule::exists` scoped al team. El test de 404 cross-team funciona porque el route-model binding aplica el global scope de `TeamOwned` (igual que en Fase 3).

- [ ] **Step 2: Correr los tests y verificar que fallan**

Run: `php artisan test --compact --filter=EmpleadoTest`
Expected: FAIL ("Route [employees.index] not defined").

- [ ] **Step 3: Crear `EmpleadoRequest`**

```php
<?php

namespace App\Http\Requests;

use App\Models\Empleado;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmpleadoRequest extends FormRequest
{
    /**
     * Autz vía EmpleadoPolicy ANTES de validar (un no-owner recibe 403, no 422).
     */
    public function authorize(): bool
    {
        $empleado = $this->route('employee');

        return $empleado
            ? $this->user()->can('update', $empleado)
            : $this->user()->can('create', Empleado::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'empresa_id' => $this->input('empresa_id') ?: null,
            'fecha_baja' => $this->input('fecha_baja') ?: null,
            'clasificacion' => $this->input('clasificacion') ?: null,
            'activo' => $this->boolean('activo'),
        ]);
    }

    public function rules(): array
    {
        $teamId = $this->user()->current_team_id;

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'puesto' => ['nullable', 'string', 'max:255'],
            'empresa_id' => [
                'required',
                Rule::exists('empresas', 'id')->where(fn ($q) => $q->where('team_id', $teamId)),
            ],
            'fecha_entrada' => ['required', 'date'],
            'fecha_baja' => ['nullable', 'date', 'after_or_equal:fecha_entrada'],
            'salario_fiscal' => ['required', 'numeric', 'gt:0'],
            'salario_real' => ['required', 'numeric', 'gt:0', 'gte:salario_fiscal'],
            'clasificacion' => ['nullable', Rule::in(['tecnica', 'administrativa'])],
            'activo' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'empresa_id.required' => 'Selecciona la empresa / centro de costo.',
            'salario_real.gte' => 'El salario real no puede ser menor que el fiscal.',
            'fecha_baja.after_or_equal' => 'La fecha de baja no puede ser anterior a la de entrada.',
        ];
    }
}
```

- [ ] **Step 4: Crear `EmpleadoController`**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmpleadoRequest;
use App\Models\Empleado;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EmpleadoController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Empleado::class);
        $teamId = auth()->user()->current_team_id;

        $empleados = Empleado::where('team_id', $teamId)
            ->with(['empresa:id,nombre,color'])
            ->orderByDesc('activo')
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Employees/Index', [
            'empleados' => $empleados,
            'empresas' => $this->empresasActivas($teamId),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Empleado::class);
        $teamId = auth()->user()->current_team_id;

        return Inertia::render('Employees/Create', [
            'empresas' => $this->empresasActivas($teamId),
        ]);
    }

    public function store(EmpleadoRequest $request): RedirectResponse
    {
        Empleado::create($request->validated() + [
            'team_id' => auth()->user()->current_team_id,
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('employees.index')->with('success', 'Empleado creado exitosamente.');
    }

    public function edit(Empleado $employee): Response
    {
        $this->authorize('update', $employee);
        $teamId = auth()->user()->current_team_id;

        return Inertia::render('Employees/Create', [
            'empleado' => $employee->load(['empresa:id,nombre']),
            'empresas' => $this->empresasActivas($teamId),
        ]);
    }

    public function update(EmpleadoRequest $request, Empleado $employee): RedirectResponse
    {
        $employee->update($request->validated());

        return redirect()->route('employees.index')->with('success', 'Empleado actualizado exitosamente.');
    }

    public function destroy(Empleado $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return back()->with('success', 'Empleado eliminado.');
    }

    private function empresasActivas(int $teamId)
    {
        return Empresa::where('team_id', $teamId)
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'color']);
    }
}
```

- [ ] **Step 5: Registrar la ruta**

En `routes/web.php`, dentro del grupo `Route::middleware('auth')->group(...)`, después de la línea del resource `recurring-expenses`, agregar:

```php
    // Empleados — Finanzas Fase 3B (plantilla + fuente de nómina recurrente, solo owner)
    Route::resource('employees', \App\Http\Controllers\EmpleadoController::class)->except('show');
```

- [ ] **Step 6: Correr los tests y verificar que pasan**

Run: `php artisan test --compact --filter=EmpleadoTest`
Expected: PASS (4 passed).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/EmpleadoRequest.php app/Http/Controllers/EmpleadoController.php routes/web.php tests/Feature/EmpleadoTest.php
git commit -m "feat(finanzas): CRUD /employees solo-owner (Fase 3B)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Vistas Vue `Employees/Index` + `Employees/Create` + i18n

**Files:**
- Create: `resources/js/Pages/Employees/Index.vue`
- Create: `resources/js/Pages/Employees/Create.vue`
- Modify: `lang/es.json`, `lang/en.json`

- [ ] **Step 1: Crear `Employees/Index.vue`**

```vue
<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, router } from "@inertiajs/vue3";
import Modal from "@/Components/Modal.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import DangerButton from "@/Components/DangerButton.vue";
import EmptyState from "@/Components/EmptyState.vue";
import { formatCurrency, formatDate } from "@/utils/format";
import { ref } from "vue";

interface Option {
    id: number;
    nombre: string;
    color?: string | null;
}

interface Empleado {
    id: number;
    nombre: string;
    puesto: string | null;
    fecha_entrada: string;
    fecha_baja: string | null;
    salario_fiscal: number;
    salario_real: number;
    clasificacion: string | null;
    activo: boolean;
    empresa: Option | null;
}

defineProps<{
    empleados: { data: Empleado[]; links: Array<any> };
    empresas: Option[];
}>();

function paginationLabel(html: string): string {
    return html.replace(/&laquo;/g, "«").replace(/&raquo;/g, "»").replace(/<[^>]*>/g, "");
}

const confirmingDeletion = ref(false);
const toDelete = ref<Empleado | null>(null);
const confirmDeletion = (e: Empleado) => {
    toDelete.value = e;
    confirmingDeletion.value = true;
};
const closeModal = () => {
    confirmingDeletion.value = false;
    toDelete.value = null;
};
const destroy = () => {
    if (!toDelete.value) return;
    router.delete(route("employees.destroy", toDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
};
</script>

<template>
    <Head :title="$t('Empleados')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $t("Empleados") }}</h2>
                <Link :href="route('employees.create')">
                    <PrimaryButton>{{ $t("Nuevo empleado") }}</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="mb-6">
                    <p class="text-gray-500 dark:text-gray-400 text-sm">
                        {{ $t("La nómina (fiscal + complemento) se genera sola cada quincena a partir de los empleados activos.") }}
                    </p>
                </div>

                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <EmptyState
                        v-if="empleados.data.length === 0"
                        :title="$t('No hay empleados registrados.')"
                        :description="$t('Registra empleados para que su nómina se genere cada quincena.')"
                    />
                    <table v-else class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Nombre") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Empresa") }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">{{ $t("Salario fiscal") }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">{{ $t("Salario real") }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">{{ $t("Estado") }}</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase">{{ $t("Acciones") }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <tr v-for="e in empleados.data" :key="e.id" :class="{ 'opacity-50': !e.activo }">
                                <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                                    <div class="font-medium">{{ e.nombre }}</div>
                                    <div v-if="e.puesto" class="text-xs text-gray-400">{{ e.puesto }}</div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-400">
                                    <span v-if="e.empresa" class="text-xs" :style="{ color: e.empresa.color || '#9ca3af' }">{{ e.empresa.nombre }}</span>
                                    <span v-else class="text-xs text-gray-400">{{ $t("Sin asignar") }}</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-right text-sm text-gray-600 dark:text-gray-400">{{ formatCurrency(e.salario_fiscal) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-right text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatCurrency(e.salario_real) }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span
                                        class="inline-flex px-2 text-xs font-semibold rounded-full"
                                        :class="e.activo ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400'"
                                    >{{ e.activo ? $t("Activo") : $t("Inactivo") }}</span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                                    <Link :href="route('employees.edit', e.id)" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400">{{ $t("Editar") }}</Link>
                                    <button @click="confirmDeletion(e)" class="ml-4 text-red-600 hover:text-red-900 dark:text-red-400">{{ $t("Eliminar") }}</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="empleados.links.length > 3" class="mt-6 flex flex-wrap justify-center -space-x-px">
                    <template v-for="(link, key) in empleados.links" :key="key">
                        <div v-if="link.url === null" class="px-4 py-2 text-sm text-gray-400 border dark:border-gray-700" v-text="paginationLabel(link.label)" />
                        <Link
                            v-else
                            :href="link.url"
                            preserve-scroll
                            class="px-4 py-2 text-sm border dark:border-gray-700"
                            :class="link.active ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'"
                            v-text="paginationLabel(link.label)"
                        />
                    </template>
                </div>
            </div>
        </div>

        <Modal :show="confirmingDeletion" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $t("¿Eliminar este empleado?") }}</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $t("La nómina ya generada se conserva. Esta acción es irreversible.") }}</p>
                <div class="mt-6 flex justify-end">
                    <SecondaryButton @click="closeModal">{{ $t("Cancelar") }}</SecondaryButton>
                    <DangerButton class="ml-3" @click="destroy">{{ $t("Eliminar") }}</DangerButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Crear `Employees/Create.vue`**

```vue
<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import SecondaryButton from "@/Components/SecondaryButton.vue";
import InputLabel from "@/Components/InputLabel.vue";
import TextInput from "@/Components/TextInput.vue";
import InputError from "@/Components/InputError.vue";
import { computed } from "vue";

interface Option {
    id: number;
    nombre: string;
}

interface Empleado {
    id: number;
    empresa_id: number | null;
    empresa?: Option | null;
    nombre: string;
    puesto: string | null;
    fecha_entrada: string;
    fecha_baja: string | null;
    salario_fiscal: number;
    salario_real: number;
    clasificacion: string | null;
    activo: boolean;
}

const props = defineProps<{
    empleado?: Empleado;
    empresas: Option[];
}>();

const isEdit = computed(() => !!props.empleado);

const empresaOptions = computed<Option[]>(() => {
    const cur = props.empleado?.empresa;
    return cur && !props.empresas.some((e) => e.id === cur.id) ? [cur, ...props.empresas] : props.empresas;
});

const selectClass =
    "mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500";

const form = useForm({
    nombre: props.empleado?.nombre ?? "",
    puesto: props.empleado?.puesto ?? "",
    empresa_id: props.empleado?.empresa_id ?? "",
    fecha_entrada: props.empleado?.fecha_entrada?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
    fecha_baja: props.empleado?.fecha_baja?.slice(0, 10) ?? "",
    salario_fiscal: props.empleado?.salario_fiscal ?? "",
    salario_real: props.empleado?.salario_real ?? "",
    clasificacion: props.empleado?.clasificacion ?? "",
    activo: props.empleado?.activo ?? true,
});

const submit = () => {
    if (isEdit.value) {
        form.put(route("employees.update", props.empleado!.id));
    } else {
        form.post(route("employees.store"));
    }
};
</script>

<template>
    <Head :title="isEdit ? $t('Editar empleado') : $t('Nuevo empleado')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ isEdit ? $t("Editar empleado") : $t("Nuevo empleado") }}
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <form @submit.prevent="submit" class="space-y-6">
                            <div>
                                <InputLabel for="nombre" :value="$t('Nombre')" />
                                <TextInput id="nombre" type="text" class="mt-1 block w-full" v-model="form.nombre" required autofocus />
                                <InputError class="mt-2" :message="form.errors.nombre" />
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="puesto" :value="$t('Puesto')" />
                                    <TextInput id="puesto" type="text" class="mt-1 block w-full" v-model="form.puesto" />
                                    <InputError class="mt-2" :message="form.errors.puesto" />
                                </div>
                                <div>
                                    <InputLabel for="empresa_id" :value="$t('Empresa')" />
                                    <select id="empresa_id" v-model="form.empresa_id" required :class="selectClass">
                                        <option value="" disabled>{{ $t("Selecciona…") }}</option>
                                        <option v-for="e in empresaOptions" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                                    </select>
                                    <InputError class="mt-2" :message="form.errors.empresa_id" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="salario_fiscal" :value="$t('Salario fiscal')" />
                                    <input id="salario_fiscal" type="number" step="0.01" min="0.01" v-model="form.salario_fiscal" required :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.salario_fiscal" />
                                </div>
                                <div>
                                    <InputLabel for="salario_real" :value="$t('Salario real')" />
                                    <input id="salario_real" type="number" step="0.01" min="0.01" v-model="form.salario_real" required :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.salario_real" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel for="fecha_entrada" :value="$t('Fecha de entrada')" />
                                    <input id="fecha_entrada" type="date" v-model="form.fecha_entrada" required :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.fecha_entrada" />
                                </div>
                                <div>
                                    <InputLabel for="fecha_baja" :value="$t('Fecha de baja')" />
                                    <input id="fecha_baja" type="date" v-model="form.fecha_baja" :class="selectClass" />
                                    <InputError class="mt-2" :message="form.errors.fecha_baja" />
                                </div>
                            </div>

                            <div>
                                <InputLabel for="clasificacion" :value="$t('Clasificación')" />
                                <select id="clasificacion" v-model="form.clasificacion" :class="selectClass">
                                    <option value="">{{ $t("Sin clasificar") }}</option>
                                    <option value="tecnica">{{ $t("Técnica (facturable)") }}</option>
                                    <option value="administrativa">{{ $t("Administrativa") }}</option>
                                </select>
                                <InputError class="mt-2" :message="form.errors.clasificacion" />
                            </div>

                            <label class="flex items-center gap-2">
                                <input type="checkbox" v-model="form.activo" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $t("Activo") }}</span>
                            </label>

                            <div class="flex items-center gap-4">
                                <PrimaryButton :disabled="form.processing">{{ $t("Guardar") }}</PrimaryButton>
                                <Link :href="route('employees.index')">
                                    <SecondaryButton type="button">{{ $t("Cancelar") }}</SecondaryButton>
                                </Link>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 3: Añadir claves i18n**

En `lang/es.json`, agregar al inicio del objeto (tras la llave `{`) estas claves (mismo valor en español):

```json
    "Empleados": "Empleados",
    "Nuevo empleado": "Nuevo empleado",
    "Editar empleado": "Editar empleado",
    "La nómina (fiscal + complemento) se genera sola cada quincena a partir de los empleados activos.": "La nómina (fiscal + complemento) se genera sola cada quincena a partir de los empleados activos.",
    "No hay empleados registrados.": "No hay empleados registrados.",
    "Registra empleados para que su nómina se genere cada quincena.": "Registra empleados para que su nómina se genere cada quincena.",
    "Salario fiscal": "Salario fiscal",
    "Salario real": "Salario real",
    "Puesto": "Puesto",
    "Clasificación": "Clasificación",
    "Sin clasificar": "Sin clasificar",
    "Técnica (facturable)": "Técnica (facturable)",
    "Administrativa": "Administrativa",
    "Fecha de entrada": "Fecha de entrada",
    "Fecha de baja": "Fecha de baja",
    "¿Eliminar este empleado?": "¿Eliminar este empleado?",
    "La nómina ya generada se conserva. Esta acción es irreversible.": "La nómina ya generada se conserva. Esta acción es irreversible.",
```

En `lang/en.json`, agregar al inicio las mismas claves con traducción al inglés:

```json
    "Empleados": "Employees",
    "Nuevo empleado": "New employee",
    "Editar empleado": "Edit employee",
    "La nómina (fiscal + complemento) se genera sola cada quincena a partir de los empleados activos.": "Payroll (fiscal + complement) is generated automatically each fortnight from active employees.",
    "No hay empleados registrados.": "No employees registered.",
    "Registra empleados para que su nómina se genere cada quincena.": "Register employees so their payroll is generated every fortnight.",
    "Salario fiscal": "Fiscal salary",
    "Salario real": "Actual salary",
    "Puesto": "Position",
    "Clasificación": "Classification",
    "Sin clasificar": "Unclassified",
    "Técnica (facturable)": "Technical (billable)",
    "Administrativa": "Administrative",
    "Fecha de entrada": "Start date",
    "Fecha de baja": "Termination date",
    "¿Eliminar este empleado?": "Delete this employee?",
    "La nómina ya generada se conserva. Esta acción es irreversible.": "Payroll already generated is kept. This action is irreversible.",
```

> Nota: claves ya existentes que reusan las vistas (`Empresa`, `Activo`, `Inactivo`, `Editar`, `Eliminar`, `Cancelar`, `Guardar`, `Nombre`, `Estado`, `Acciones`, `Sin asignar`, `Selecciona…`) NO se duplican.

- [ ] **Step 4: Verificar el typecheck/build**

Run: `npm run build`
Expected: `✓ built` sin errores de vue-tsc.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Employees lang/es.json lang/en.json
git commit -m "feat(finanzas): vistas /employees (Index + Create) + i18n (Fase 3B)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: Scheduler `nomina:generar`

**Files:**
- Modify: `routes/console.php`

- [ ] **Step 1: Registrar el schedule**

En `routes/console.php`, al final, después del schedule de `egresos:generar-recurrentes`, agregar:

```php
// Finanzas Fase 3B: genera la nómina quincenal (fiscal + complemento) por empleado activo.
// Diario; ventana móvil de 40 días + idempotencia. onOneServer: solo un host en multi-servidor.
// Para backfill de meses fuera de la ventana: `php artisan nomina:generar --month=YYYY-MM`.
Schedule::command('nomina:generar')->dailyAt('01:30')->withoutOverlapping()->onOneServer();
```

- [ ] **Step 2: Verificar que el schedule se registra**

Run: `php artisan schedule:list`
Expected: aparece `nomina:generar ... 01:30`.

- [ ] **Step 3: Commit**

```bash
git add routes/console.php
git commit -m "feat(finanzas): schedule diario de nomina:generar (Fase 3B)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: Documentación + PRD + verificación final

**Files:**
- Modify: `docs/domain.md`, `docs/endpoints.md`, `docs/operations.md`, `docs/business-rules.md`, `docs/security.md`, `docs/prd/finanzas-egresos-multiempresa.md`

- [ ] **Step 1: `docs/domain.md`** — añadir fila de `Empleado` en la tabla de modelos y notar los cambios en `Egreso`:
  - Modelo `Empleado` / `empleados` / TeamOwned / `HasFactory` — "Plantilla de personal (Finanzas Fase 3B); fuente del comando `nomina:generar`. `user_id` y `empresa_id` `nullOnDelete`."
  - En la fila/sección de `Egreso`: mencionar `empleado_id` (nullOnDelete), `concepto_nomina` (fiscal/complemento), índice único `egresos_empleado_periodo_unique (empleado_id, fecha, concepto_nomina)`, y que `user_id` pasó a nullable/nullOnDelete.
  - En el diagrama de relaciones: `Empleado (1) ──< Egreso (empleado_id)` (ya previsto en PRD §3.8).

- [ ] **Step 2: `docs/endpoints.md`** — añadir el resource `/employees` (index/create/store/edit/update/destroy), nota "solo owner (EmpleadoPolicy)".

- [ ] **Step 3: `docs/operations.md`** — nueva subsección `### nomina:generar (Finanzas Fase 3B)`:
  - Qué hace (quincena día 15 + fin de mes hábil, fiscal + complemento por empleado activo, todos los teams con `withoutGlobalScopes`).
  - Idempotencia (índice único `(empleado_id, fecha, concepto_nomina)` + exists + try/catch).
  - Ventana móvil 40 días; `--month=YYYY-MM` para backfill; `--dry-run`.
  - Resumen de corrida (creados / omitidos por categoría / complemento ≤ 0).
  - Limitación: outage > 40 días → usar `--month`.
  - Añadir la línea del schedule (`dailyAt('01:30')->withoutOverlapping()->onOneServer()`).

- [ ] **Step 4: `docs/business-rules.md`** — añadir reglas de nómina: quincenas y ajuste a día hábil anterior; salario mensual → mitad por quincena; mapeo de categoría por clasificación (técnica→COGS facturable, admin/null→Nómina fiscal; complemento→Nómina complemento/real); complemento ≤ 0 omitido; elegibilidad por fecha nominal; baja a mitad de periodo.

- [ ] **Step 5: `docs/security.md`** — añadir: módulo Empleados es **solo owner** del team en todas las habilidades (EmpleadoPolicy); justificación: privacidad de salarios (fiscal/real). Captura operativa de egresos/recurrentes sigue siendo cualquier miembro.

- [ ] **Step 6: PRD** — marcar `### Fase 3B` como `✅ CERRADA (2026-06-30)` con una línea de resumen; y corregir el typo en §3.7 línea 143 `"Nómina complemento/real"` → `"Nómina complemento / real"` (con espacios, para cuadrar con el seeder).

- [ ] **Step 7: Commit docs**

```bash
git add docs/
git commit -m "docs(finanzas): documentar Fase 3B (empleados + nómina) y marcar en PRD

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 8: Pint sobre lo cambiado**

Run: `vendor/bin/pint --dirty`
Expected: `{"result":"pass"}` (o que formatee y quede limpio).

- [ ] **Step 9: Suite completa — verificar 0 regresiones nuevas**

Run: `php artisan test --compact`
Expected: los tests nuevos de Fase 3B (PayrollCalculator 4, GenerarNomina 12, Empleado 4) en verde; el resto = baseline (13 fallos preexistentes en `RegressionTest`/`ReconciliationTest`/`ReconciliationExportTest`/`ExcelAmountCorrectnessTest`), **0 regresiones nuevas**.

- [ ] **Step 10: Build final**

Run: `npm run build`
Expected: `✓ built` sin errores.

- [ ] **Step 11: Commit final si Pint formateó algo**

```bash
git add -A
git commit -m "style(finanzas): pint Fase 3B

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Cierre de fase (fuera del plan, requiere al usuario)
Tras completar las tareas: `/code-review develop` sobre `feature/finanzas-fase3b`, aplicar correcciones, y merge `--no-ff` a `develop` (ojo conflicto típico en `lang/*.json`). Actualizar la memoria de progreso.

---

## Self-Review

**Spec coverage (SDD §2–§9):**
- Tabla `empleados` → Task 1 ✓
- `egresos`: `empleado_id` + `concepto_nomina` + índice único + `user_id` nullable → Task 2 ✓
- Modelos + factory → Task 3 ✓
- Policy solo-owner en todas las habilidades → Task 4 ✓
- Fechas de quincena + día hábil (reusa `applyDiaHabil`) → Task 5 ✓
- Generador: ventana móvil + `--month`, elegibilidad nominal, fiscal+complemento, mapeo por clasificación, complemento ≤ 0, categoría faltante, idempotencia (exists + unique + try/catch), `withoutGlobalScopes`, resumen → Task 6 ✓
- CRUD `/employees` + validación (empresa required, real ≥ fiscal, baja ≥ entrada) → Task 7 ✓
- Vistas + i18n → Task 8 ✓
- Schedule `onOneServer` → Task 9 ✓
- Docs (domain/endpoints/operations/business-rules/security/PRD) → Task 10 ✓

**Placeholder scan:** sin TBD/TODO; todos los pasos de código traen el código completo.

**Type/nombre consistency:** `quincenas()` devuelve `['nominal'=>, 'pago'=>]` y así se consume en el comando; `concepto_nomina` valores `'fiscal'|'complemento'` consistentes entre migración, comando y tests; rutas `employees.*` consistentes entre controller, request (`$this->route('employee')`), vistas y tests; índice `egresos_empleado_periodo_unique` consistente entre migración (up/down) y test de DB.
