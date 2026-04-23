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

Archivo ──1 Factura (file_id_xml)    # XMLs tienen 1 factura
Archivo ──* Movimiento (file_id)     # Estados de cuenta tienen muchos movimientos
Archivo ──? Banco (banco_id)         # Solo para statements
Archivo ──? BankFormat (bank_format_id)

Factura ──* Conciliacion             # Una factura puede aplicarse a múltiples movimientos
Movimiento ──* Conciliacion          # Un movimiento puede cubrir múltiples facturas
Conciliacion ──1 User (user_id)      # Quién conciló
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

---

## Casts importantes

- `Factura`: `fecha_emision => date`, `monto => decimal:2`, `verificado => bool`
- `Movimiento`: `fecha => date`, `monto => decimal:2`
- `Conciliacion`: `monto_aplicado => decimal:2`, `fecha_conciliacion => datetime`
- `Team`: `personal_team => bool`
- `ExportRequest`: `filters => array`
- `Tolerancia`: `monto => decimal:2`, `dias => integer`
- `User`: `email_verified_at => datetime`, `password => hashed` (via `casts()` method en Laravel 12)

---

## Factories

Localizadas en `database/factories/`:

- `UserFactory` — genera user con team personal automático por virtud de `User@booted`.
- `TeamFactory`, `FacturaFactory`, `MovimientoFactory`, `ArchivoFactory`, `BancoFactory`, `ExportRequestFactory`.

No hay factories para `Conciliacion`, `BankFormat`, `Tolerancia`, `TeamInvitation` — se crean con `forceCreate` en los tests.

---

## Seeders

Solo `DatabaseSeeder.php` existe. No hay seed de datos demo.

---

## Referencias

- Traits: `app/Models/Traits/`
- Modelos: `app/Models/`
- Migraciones: `database/migrations/`
- Factories: `database/factories/`
