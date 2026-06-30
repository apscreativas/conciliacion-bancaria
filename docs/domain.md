# Domain Model

## Modelos Eloquent

Todos los modelos de dominio usan el trait `TeamOwned` salvo los marcados como "Global" o "Tenencia".

| Modelo | Tabla | Tenancy | Traits | Descripción |
|---|---|---|---|---|
| `User` | `users` | Tenencia | `HasFactory`, `Notifiable` | Usuario del sistema. Al crearse genera un personal team automáticamente |
| `Team` | `teams` | Tenencia | `HasFactory` | Unidad de tenencia. Tiene `rfc` opcional usado para validación de XMLs |
| `TeamInvitation` | `team_invitations` | Tenencia | `HasFactory` | Invitación con token autogenerado (32 chars) |
| `Factura` | `facturas` | TeamOwned | `HasCreator`, `HasFactory` | CFDI parseado. UUID único por team |
| `Movimiento` | `movimientos` | TeamOwned | `HasCreator`, `HasFactory` | Movimiento bancario. Dedup por `(team_id, fecha, monto, descripcion)` |
| `Conciliacion` | `conciliacions` | TeamOwned | — | Pivote factura↔movimiento. `group_id` UUID agrupa operaciones N-M |
| `Archivo` | `archivos` | TeamOwned | `HasCreator`, `HasFactory` | Archivo subido (XML o Excel/CSV). Estados: `pendiente`, `procesando`, `procesado`, `fallido`, `duplicado`, `rechazado` |
| `Banco` | `bancos` | **Global** (tiene team_id pero sin trait) | `HasFactory` | Catálogo de bancos. `Banco::factory()` no aplica scope |
| `BankFormat` | `bank_formats` | TeamOwned | `HasFactory` | Mapeo de columnas para parsear Excel/CSV bancario |
| `ExportRequest` | `export_requests` | TeamOwned | `HasFactory` | Registro de solicitudes de exportación async |
| `Tolerancia` | `tolerancias` | TeamOwned | — | Configuración `(monto, dias)` por team. Solo 1 registro por team |
| `Empresa` | `empresas` | TeamOwned | `HasFactory` | Unidad de negocio del grupo (Finanzas Fase 0). Dimensión para clasificar ingresos/egresos por empresa |
| `Categoria` | `categorias` | TeamOwned | `HasFactory` | Catálogo de cuentas gerencial (Finanzas Fase 0). Clasifica ingresos y egresos; arma el Estado de Resultados |
| `Egreso` | `egresos` | TeamOwned | `HasFactory` | Gasto manual o generado (Finanzas Fase 2/3/3B). Clasificado por `empresa_id` (opcional) + `categoria_id` (egreso). `origen` manual/recurrente; `egreso_recurrente_id` si vino de plantilla recurrente; `empleado_id` (nullOnDelete) + `concepto_nomina` (`fiscal`/`complemento`) si vino de `nomina:generar` (Fase 3B). Índice único `egresos_recurrente_periodo_unique (egreso_recurrente_id, fecha)` para recurrentes; índice único `egresos_empleado_periodo_unique (empleado_id, fecha, concepto_nomina)` para nómina (NULLs múltiples permitidos → no afecta egresos manuales/recurrentes). `user_id` es nullable + `nullOnDelete` (Fase 3B) — un registro financiero sobrevive al borrado de su creador |
| `EgresoRecurrente` | `egresos_recurrentes` | TeamOwned | `HasFactory` | Plantilla de gasto recurrente (Finanzas Fase 3). El comando `egresos:generar-recurrentes` crea egresos según `frecuencia`/`proxima_generacion`/vigencia. `user_id` es `nullOnDelete` (borrar al creador no borra la plantilla) |
| `Empleado` | `empleados` | TeamOwned | `HasFactory` | Plantilla de personal (Finanzas Fase 3B); fuente del comando `nomina:generar`. `salario_fiscal`/`salario_real` mensuales, `clasificacion` (`tecnica`/`administrativa`/null), `fecha_entrada`/`fecha_baja`, `activo`. `user_id` y `empresa_id` `nullOnDelete` (borrar al creador o la empresa no borra al empleado, que es registro financiero) |
| `IngresoManual` | `ingresos_manuales` | TeamOwned | `HasFactory` | Ingreso real en efectivo que NO pasa por banco (Finanzas Fase 4; espejo de `Egreso`). Clasificado por `empresa_id` (opcional) + `categoria_id` (tipo=ingreso, requerida a nivel app). Campos `cliente` (nullable) y `metodo` enum(`efectivo`,`otro`) default `efectivo`. FKs `empresa_id`/`categoria_id` `nullOnDelete`; `user_id` (creador) nullable + `nullOnDelete` — un registro financiero sobrevive al borrado de su creador/catálogo. Index `(team_id, fecha)`. Es una de las fuentes de ingresos del P&L (Fase 5) |

### Nota sobre `Banco`

`Banco` tiene columna `team_id` pero **no** usa `TeamOwned`. En la práctica `BankFormat::firstOrCreate` crea bancos por nombre+team cuando es necesario, pero el modelo no se auto-filtra. Si lees `Banco::all()` verás bancos de todos los teams.

---

## Relaciones clave

```
User ──* Team (pivot team_user, con role)
User ──1 Team (current_team_id)
Team ──* Team (ownedTeams por user_id)
Team ──* Factura, Movimiento, Conciliacion, Archivo, BankFormat, ExportRequest, Tolerancia
Team ──* TeamInvitation
Team ──* Empresa, Categoria       # Finanzas Fase 0 (tablas nuevas, sin relación aún con el dominio existente)
Team ──* Egreso                   # Finanzas Fase 2
Team ──* EgresoRecurrente         # Finanzas Fase 3
Team ──* Empleado                 # Finanzas Fase 3B
Team ──* IngresoManual            # Finanzas Fase 4
EgresoRecurrente ──< Egreso (egreso_recurrente_id)  # plantilla → egresos generados (nullOnDelete)
Empleado ──< Egreso (empleado_id) # plantilla de personal → egresos de nómina (Fase 3B, nullOnDelete)
Empresa ──? Egreso (empresa_id)   # opcional; Categoria ──? Egreso (categoria_id); User ──? Egreso (creador, nullOnDelete)
Empresa ──? Empleado (empresa_id) # opcional, nullOnDelete; User ──? Empleado (creador, nullOnDelete)
Empresa ──< IngresoManual (empresa_id)  # opcional, nullOnDelete; Categoria ──< IngresoManual (categoria_id, tipo=ingreso, nullOnDelete)
IngresoManual ──1 User (user_id)  # quién lo registró (nullOnDelete)

Archivo ──1 Factura (file_id_xml)    # XMLs tienen 1 factura
Archivo ──* Movimiento (file_id)     # Estados de cuenta tienen muchos movimientos
Archivo ──? Banco (banco_id)         # Solo para statements
Archivo ──? BankFormat (bank_format_id)

Factura ──* Conciliacion             # Una factura puede aplicarse a múltiples movimientos
Movimiento ──* Conciliacion          # Un movimiento puede cubrir múltiples facturas
Conciliacion ──1 User (user_id)      # Quién conciló
Conciliacion ──? Empresa (empresa_id) # Unidad de negocio asignada (Finanzas Fase 1, nullable)
Conciliacion.group_id (UUID)         # Agrupa toda la operación N-M

BankFormat ──1 Banco (banco_id)
```

---

## Traits custom (`app/Models/Traits/`)

### `TeamOwned`

Aplica un **global scope** que filtra por `team_id = Auth::user()->current_team_id` en todas las queries. También setea `team_id` automáticamente al crear.

```php
// Ejemplo de uso:
Factura::where('uuid', $uuid)->exists();
// Se traduce a:
// SELECT * FROM facturas WHERE uuid = ? AND team_id = ? (auto)
```

Para bypass (solo en migrations, commands, tests):
```php
Factura::withoutGlobalScopes()->where(...)->get();
```

### `HasCreator`

Setea `user_id = Auth::id()` al crear. Define `user()` belongsTo.

### `UserOwned`

Similar a `TeamOwned` pero por `user_id`. **No se usa hoy** en ningún modelo de dominio (existe solo como utility).

---

## Estados del modelo `Archivo` (`estatus`)

Transiciones definidas en los jobs:

```
pendiente  ──(job start)──►  procesando  ──►  procesado
                                          ├──►  fallido
                                          ├──►  duplicado       (XML con UUID ya existente)
                                          └──►  rechazado       (XML PPD tipo I)
```

El comando `queue:cleanup-stuck` mueve `procesando` → `fallido` si `updated_at > 2h`.

---

## Estados de `ExportRequest.status`

```
queued ──►  processing  ──►  completed
                         └──►  failed
```

El polling frontend considera "worker offline" si `status=queued` y `created_at > 2min`.

---

## Esquema de BD (migraciones)

### Tablas principales con columnas críticas

#### `teams`
- `id`, `user_id` (owner), `name`, `rfc` (nullable), `personal_team` (bool)
- Evidencia: `2026_01_25_230000_create_teams_table.php`, `2026_01_30_160201_add_rfc_to_teams_table.php`

#### `team_user` (pivot)
- `team_id`, `user_id`, `role` (default `member`), timestamps
- Evidencia: `2026_01_26_151116_create_team_user_table.php`

#### `team_invitations`
- `team_id`, `email`, `role`, `token` (32 chars, auto-generado)
- Evidencia: `2026_01_26_151116_create_team_invitations_table.php`

#### `facturas`
- `id`, `user_id`, `team_id`, `file_id_xml` (FK archivos, cascade)
- `uuid` (string, CFDI), `tipo_comprobante`, `metodo_pago`
- `monto` (decimal 15,2), `fecha_emision` (date)
- `rfc` (receptor), `nombre` (receptor), `verificado` (bool)
- **Unique**: `(team_id, uuid)`
- Evidencia: `2026_01_23_211433_create_facturas_table.php`, `2026_01_23_214702_add_uuid_to_facturas_table.php`, `2026_01_27_220458_change_uuid_unique_on_facturas_table.php`, `2026_03_09_000001_add_tipo_comprobante_and_metodo_pago_to_facturas_table.php`

#### `movimientos`
- `id`, `user_id`, `team_id`, `banco_id`, `file_id` (FK archivos, cascade)
- `fecha` (date), `monto` (decimal 15,2), `tipo` (`abono` | `cargo`)
- `referencia` (nullable), `descripcion` (nullable)
- `hash` (sha256, mantenido por compat)
- **Unique**: `(team_id, hash)`
- **Index**: `movimientos_dedup_index (team_id, fecha, monto, descripcion)` — fuente real de dedup
- Evidencia: `2026_01_23_211434_create_movimientos_table.php`, `2026_01_26_143336_add_hash_to_movimientos_table.php`, `2026_01_27_225400_change_hash_unique_on_movimientos_table.php`, `2026_03_23_165654_add_dedup_index_to_movimientos_table.php`

#### `conciliacions` (sic — typo histórico)
- `id`, `user_id`, `team_id`, `factura_id`, `movimiento_id`
- `group_id` (UUID) — agrupa operaciones N-M
- `empresa_id` (FK nullable, **Finanzas Fase 1**) — unidad de negocio asignada al grupo post-conciliación. `nullOnDelete` (borrar empresa NO borra conciliaciones). null = "sin asignar". El matcher NO lo setea. Evidencia: `2026_06_29_000001_add_empresa_id_to_conciliacions_table.php`
- `monto_aplicado` (decimal 15,2) — calculado por `MatcherService` con saldo restante
- `estatus` (`conciliado` | `pendiente_revision`, default `conciliado`)
- `tipo` (`automatico` | `manual`)
- `fecha_conciliacion` (timestamp)
- Evidencia: `2026_01_23_211435_create_conciliacions_table.php`, `2026_01_29_191400_add_group_id_to_conciliaciones_table.php` (con backfill por `created_at+user_id`), `2026_02_04_172744_add_team_id_to_conciliacions_table.php` (con backfill desde `facturas`)

#### `archivos`
- `id`, `user_id`, `team_id`, `banco_id` (nullable — XMLs no tienen), `bank_format_id` (nullable)
- `path`, `original_name`, `mime`, `size`, `checksum` (md5)
- `estatus` (ver lista arriba)
- Evidencia: `2026_01_23_211433_create_archivos_table.php` + sucesivas

#### `bank_formats`
- `id`, `team_id`, `banco_id`, `name` (unique por team)
- `start_row` (int, 1-based), `date_column`, `description_column`
- `amount_column` | (`debit_column` + `credit_column`) — mutuamente exclusivos
- `reference_column`, `type_column` (nullable)
- `color` (hex, para UI)
- Evidencia: `2026_01_30_161505_create_bank_formats_table.php`, `2026_02_04_044150_add_debit_credit_columns_to_bank_formats_table.php`, `2026_03_10_000001_add_banco_id_to_bank_formats_table.php`

#### `export_requests`
- `id`, `team_id`, `user_id`, `type` (`xlsx` | `pdf`)
- `status` (ver lista arriba), `file_path`, `file_name`
- `filters` (JSON con month/year/date_from/date_to/search/amount_min/amount_max)
- `error_message` (nullable)
- Evidencia: `2026_02_12_223751_create_export_requests_table.php`

#### `tolerancias`
- `id`, `user_id`, `team_id`, `monto` (decimal), `dias` (int)
- Evidencia: `2026_01_23_211435_create_tolerancias_table.php`, `2026_01_26_164929_add_team_id_to_tolerancias_table.php`, `2026_01_26_165132_add_dias_to_tolerancias_table.php`

#### `bancos`
- `id`, `team_id`, `nombre`, `codigo`, `estatus`
- Evidencia: `2026_01_23_211432_create_bancos_table.php`

#### `empresas` (Finanzas Fase 0)
- `id`, `team_id` (FK, cascade), `nombre`, `slug`, `color` (hex, nullable)
- `activo` (bool, default true), `orden` (int, default 0)
- **Unique**: `(team_id, slug)`
- Evidencia: `2026_06_26_000001_create_empresas_table.php`

#### `egresos` (Finanzas Fase 2)
- `id`, `team_id` (FK cascade)
- `empresa_id` (FK empresas, nullable, nullOnDelete), `categoria_id` (FK categorias, nullable DB + **requerida a nivel app**, nullOnDelete)
- `fecha` (date), `monto` (decimal 15,2), `descripcion`, `proveedor` (nullable)
- `metodo_pago` enum(`transferencia`,`efectivo`,`tarjeta`,`otro`) nullable
- `comprobante_path` (nullable, futuro), `origen` enum(`manual`,`recurrente`) default `manual`
- `egreso_recurrente_id` (FK egresos_recurrentes, nullable, nullOnDelete, **Finanzas Fase 3**) — plantilla que lo generó (`origen='recurrente'`)
- `empleado_id` (FK empleados, nullable, nullOnDelete, **Finanzas Fase 3B**) — empleado cuya nómina lo generó
- `concepto_nomina` enum(`fiscal`,`complemento`) nullable (**Finanzas Fase 3B**) — discriminador de nómina; desacopla la idempotencia de la categoría (mutable por clasificación). NULL para egresos manuales/recurrentes
- `user_id` (creador, **nullable + nullOnDelete desde Fase 3B**), timestamps. **Index**: `(team_id, fecha)`
- **Unique**: `egresos_empleado_periodo_unique (empleado_id, fecha, concepto_nomina)` (**Fase 3B**) — un egreso de nómina por (empleado, fecha, concepto); NULLs múltiples permitidos → no afecta egresos manuales/recurrentes
- Evidencia: `2026_06_29_000002_create_egresos_table.php`, `2026_06_29_000004_add_egreso_recurrente_id_to_egresos_table.php`, `2026_06_30_000002_add_empleado_to_egresos_table.php`

#### `egresos_recurrentes` (Finanzas Fase 3)
- `id`, `team_id` (FK cascade), `empresa_id` (FK nullable nullOnDelete), `categoria_id` (FK nullable nullOnDelete, requerida app, tipo=egreso)
- `descripcion`, `proveedor` (nullable), `monto` (decimal 15,2)
- `frecuencia` enum(`quincenal`,`mensual`,`bimestral`,`trimestral`,`anual`) default `mensual` (Fase 3 usa mensual+; quincenal→Fase 3B)
- `dia_del_mes` (tinyint 1–31, clamp al mes), `ajuste_dia_habil` enum(`ninguno`,`habil_anterior`,`habil_siguiente`) default `habil_anterior`
- `fecha_inicio` (date), `vigencia_tipo` enum(`indefinida`,`hasta_fecha`,`num_pagos`), `fecha_fin` (nullable), `num_pagos` (nullable), `pagos_generados` (int default 0)
- `activo` (bool), `proxima_generacion` (date), `user_id`, timestamps. **Index**: `(team_id, activo, proxima_generacion)`
- Evidencia: `2026_06_29_000003_create_egresos_recurrentes_table.php`

#### `empleados` (Finanzas Fase 3B)
- `id`, `team_id` (FK cascade), `empresa_id` (FK empresas, nullable, nullOnDelete)
- `nombre`, `puesto` (nullable)
- `fecha_entrada` (date), `fecha_baja` (date, nullable)
- `salario_fiscal` (decimal 15,2, mensual), `salario_real` (decimal 15,2, mensual)
- `clasificacion` enum(`tecnica`,`administrativa`) nullable — define a qué categoría va la parte fiscal de la nómina
- `activo` (bool, default true)
- `user_id` (creador, nullable, nullOnDelete), timestamps. **Index**: `(team_id, activo)`
- Evidencia: `2026_06_30_000001_create_empleados_table.php`

#### `categorias` (Finanzas Fase 0)
- `id`, `team_id` (FK, cascade), `nombre`
- `tipo` (`ingreso` | `egreso`)
- `grupo` (`ingreso` | `costo_venta` | `gasto_operativo` | `abajo_ebitda`) — arma el Estado de Resultados
- `naturaleza` (`fijo` | `variable`, **nullable** — aplica sobre todo a egresos; ingresos quedan en null)
- `activo` (bool, default true), `orden` (int, default 0)
- **Unique**: `(team_id, nombre)`
- Evidencia: `2026_06_26_000002_create_categorias_table.php`

#### `ingresos_manuales` (Finanzas Fase 4)
- `id`, `team_id` (FK cascade)
- `empresa_id` (FK empresas, nullable, nullOnDelete), `categoria_id` (FK categorias, nullable DB + **requerida a nivel app**, `tipo=ingreso`, nullOnDelete)
- `fecha` (date), `monto` (decimal 15,2), `descripcion`
- `cliente` (nullable)
- `metodo` enum(`efectivo`,`otro`) default `efectivo`
- `user_id` (creador, **nullable + nullOnDelete**), timestamps. **Index**: `(team_id, fecha)`
- Espejo de `egresos` con categorías `tipo=ingreso` (sin `proveedor`/`metodo_pago`/`comprobante_path`/`origen`/recurrencia/empleado). Captura el efectivo no bancario; es una de las fuentes de ingresos del P&L (Fase 5).
- Evidencia: `2026_07_01_000001_create_ingresos_manuales_table.php`

---

## Casts importantes

- `Factura`: `fecha_emision => date`, `monto => decimal:2`, `verificado => bool`
- `Movimiento`: `fecha => date`, `monto => decimal:2`
- `Conciliacion`: `monto_aplicado => decimal:2`, `fecha_conciliacion => datetime`
- `Team`: `personal_team => bool`
- `ExportRequest`: `filters => array`
- `Tolerancia`: `monto => decimal:2`, `dias => integer`
- `Empresa`: `activo => boolean`, `orden => integer`
- `Categoria`: `activo => boolean`, `orden => integer`
- `Egreso`: `fecha => date`, `monto => decimal:2`
- `EgresoRecurrente`: `monto => decimal:2`, `fecha_inicio`/`fecha_fin`/`proxima_generacion => date`, `activo => boolean`, `dia_del_mes`/`num_pagos`/`pagos_generados => integer`
- `Empleado`: `fecha_entrada`/`fecha_baja => date`, `salario_fiscal`/`salario_real => decimal:2`, `activo => boolean`
- `IngresoManual`: `fecha => date`, `monto => decimal:2`
- `User`: `email_verified_at => datetime`, `password => hashed` (via `casts()` method en Laravel 12)

---

## Factories

Localizadas en `database/factories/`:

- `UserFactory` — genera user con team personal automático por virtud de `User@booted`.
- `TeamFactory`, `FacturaFactory`, `MovimientoFactory`, `ArchivoFactory`, `BancoFactory`, `ExportRequestFactory`.
- `EmpresaFactory`, `CategoriaFactory` (Finanzas Fase 0; `CategoriaFactory::ingreso()` state para categorías de ingreso).
- `EgresoFactory` (Finanzas Fase 2; default `origen='manual'`, categoría de egreso).
- `EgresoRecurrenteFactory` (Finanzas Fase 3; default mensual, día 1, vigencia indefinida).
- `EmpleadoFactory` (Finanzas Fase 3B; default activo, `clasificacion='administrativa'`, salarios fiscal 20000 / real 24000).
- `IngresoManualFactory` (Finanzas Fase 4; categoría `tipo=ingreso` via `Categoria::factory()->ingreso()`, `cliente` opcional, `metodo` random efectivo/otro).

No hay factories para `Conciliacion`, `BankFormat`, `Tolerancia`, `TeamInvitation` — se crean con `forceCreate` en los tests.

---

## Seeders

- `DatabaseSeeder.php` — crea un usuario/team base y llama a `FinanzasCatalogoSeeder`.
- `FinanzasCatalogoSeeder.php` (Finanzas Fase 0) — siembra, **por cada team** e idempotentemente (`updateOrCreate`), las 3 empresas (Aplicaciones Creativas, Tu Checador, Domoticap) y el catálogo de 21 categorías del PRD §4.2. Ejecutable suelto: `php artisan db:seed --class=FinanzasCatalogoSeeder`.

---

## Referencias

- Traits: `app/Models/Traits/`
- Modelos: `app/Models/`
- Migraciones: `database/migrations/`
- Factories: `database/factories/`
