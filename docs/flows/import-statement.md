# Flow: Import Bank Statement

Flujo para subir un estado de cuenta bancario (Excel/CSV) y parsearlo en movimientos.

**Entry point**: `POST /upload/files` con fields `statement` (archivo) y `bank_code` (ID del `BankFormat`).
**Controller**: `app/Http/Controllers/FileUploadController.php`
**Job**: `app/Jobs/ProcessBankStatement.php` (cola `imports`)
**Parser**: `app/Services/Parsers/DynamicStatementParser.php`
**Factory**: `app/Services/Parsers/StatementParserFactory.php`

---

## Diagrama

```
[UI UploadModal.vue]
        │  multipart POST /upload/files (statement + bank_code)
        ▼
┌─────────────────────────────────────────────────────┐
│  FileUploadController::store  (síncrono)             │
│  ─────────────────────────────                       │
│  1. Validar bank_code presente                       │
│  2. Resolver BankFormat (del team actual)            │
│  3. Validar que format tenga banco_id                │
│  4. ── VALIDACIÓN SINCRÓNICA ──                      │
│     Copiar archivo a tmp con la ext correcta         │
│     parser = StatementParserFactory::make(format_id) │
│     preview = parser->parse(tempPath)                │
│     Si empty() → 422, abort                          │
│  5. storeAs() en storage                             │
│  6. Dedup por checksum MD5 del archivo               │
│     Si existe y estatus != 'fallido' → warning toast │
│  7. Crear Archivo (pendiente) + dispatch job ────┐   │
│                                                  │   │
│  Response: { success, toasts[] }                 │   │
└──────────────────────────────────────────────────┼───┘
                                                   │
                                                   ▼ (async)
┌─────────────────────────────────────────────────────┐
│  ProcessBankStatement::handle  (cola 'imports')      │
│  ─────────────────────────                           │
│  1. archivo.estatus = 'procesando'                   │
│  2. Download a tmp path con ext correcta             │
│  3. Resolver parser:                                 │
│       a. por bank_format_id si existe               │
│       b. fallback por banco.codigo                   │
│  4. parser->parse(tempPath) → array de movimientos   │
│  5. Si empty() → throw exception                     │
│  6. DB::transaction: por cada movimiento             │
│       - Dedup (team_id + fecha + monto + descripcion)│
│       - Si no duplicado: Movimiento::create(...)     │
│  7. archivo.estatus = 'procesado'                    │
│  finally: unlink(tmpPath)                            │
└─────────────────────────────────────────────────────┘
```

---

## `BankFormat` — configuración

Campos en `bank_formats`:

| Campo | Tipo | Uso |
|---|---|---|
| `name` | string unique(team_id) | Identificador UI |
| `banco_id` | FK | Banco asociado (auto-creado desde name si no existe) |
| `start_row` | int (1-based) | Primera fila con datos (excluye header) |
| `date_column` | letra Excel | Columna de fecha |
| `description_column` | letra | Columna de descripción |
| `amount_column` | letra \| null | Monto con signo (+ abono, − cargo) |
| `debit_column` | letra \| null | Alternativa a `amount_column`: cargo |
| `credit_column` | letra \| null | Alternativa a `amount_column`: abono |
| `reference_column` | letra \| null | Referencia/folio opcional |
| `type_column` | letra \| null | Indica abono/cargo vía texto |
| `color` | hex | Para UI (default `#3b82f6`) |

**Regla**: `amount_column` **o** (`debit_column` + `credit_column`), mutuamente exclusivos. El validator exige `amount_column required_without_all:debit_column,credit_column` y `debit_column required_without:amount_column`.

---

## Parsing (DynamicStatementParser)

### Formatos de fecha soportados

| Pattern | Ejemplo | Método |
|---|---|---|
| Numérico Excel | `45301` | `PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject` |
| `d/m/y` | `26/01/26` | `Carbon::createFromFormat('d/m/y', ...)` |
| `d/m/Y` | `26/01/2026` | `Carbon::createFromFormat('d/m/Y', ...)` |
| `d-m-y` | `26-01-26` | `Carbon::createFromFormat('d-m-y', ...)` |
| `d-m-Y` | `26-01-2026` | `Carbon::createFromFormat('d-m-Y', ...)` |
| ISO / otros | `2026-01-26` | `Carbon::parse` fallback |

Si falla → la fila se **ignora** (no falla todo el parser).

### Lógica de monto y tipo

**Opción 1: `amount_column` único**
- `monto = abs(raw)`.
- Si `type_column` existe: busca "abono/depósito/deposito/crédito" case-insensitive → `tipo='abono'`, default `'cargo'`.
- Sin `type_column`: `tipo = (raw >= 0) ? 'abono' : 'cargo'`.

**Opción 2: `debit_column` + `credit_column`**
- Si `debit > 0` → `monto=debit`, `tipo='cargo'`.
- Si `credit > 0` → `monto=credit`, `tipo='abono'`.
- Si ambos = 0 → fila ignorada.

### Validación estricta de `start_row`

Si la fila en `start_row` no genera un movimiento válido (fecha vacía, monto 0, etc.) el parser **lanza excepción**. Esto previene que un formato mal configurado pase validación silenciosa.

### CSV injection

Celdas que empiezan con `=`, `+`, `-`, `@`, `\t`, `\r` se prefijan con `'`. Ver `security.md §6`.

### Límites

- Tamaño archivo: **10 MB** (`AbstractBankParser::parse:20`).
- Delimitador CSV: auto-detecta `,` o `;` leyendo la primera línea.

---

## Dedup de archivo (sync)

```php
Archivo::where('team_id', $teamId)
    ->where('checksum', $hash)
    ->where('estatus', '!=', 'fallido')
    ->first();
```

Si existe → toast warning, **no** se crea un nuevo Archivo ni se dispatcha job.

Si el archivo previo es `fallido`, **sí** se permite reintentar.

---

## Dedup de movimientos individuales (async, en job)

```php
Movimiento::where('team_id', $teamId)
    ->where('fecha', $movData['fecha'])
    ->where('monto', $movData['monto'])
    ->where('descripcion', $movData['descripcion'])
    ->exists();
```

Cubierto por el índice `movimientos_dedup_index`. Si `exists()`, el movimiento se omite.

Nota: se calcula `hash` SHA-256 igual y se guarda (restricción `UNIQUE(team_id, hash)` sigue activa), pero la fuente real de dedup es la comparación directa.

Ver también `decisions/0004-movement-dedup-direct-columns.md`.

---

## Estados del Archivo statement

```
pendiente   →   procesando   →   procesado
                             └──►  fallido      (error, 0 movimientos, etc.)
```

**No** existe `duplicado` para statements (se rechaza en el controller antes de crear Archivo). `rechazado` es solo para XMLs PPD.

---

## Tests relevantes

- `tests/Feature/BankFormatTest.php`
- `tests/Feature/RegressionTest.php`:
    - `test_bank_statement_persists_banco_id`
    - `test_bank_statement_job_creates_movements`
    - `test_bank_statement_job_fails_validation_on_empty_parse_result`
    - `test_bank_statement_controller_rejects_duplicates`
    - `test_bank_statement_controller_rejects_invalid_format_sync`
- `tests/Unit/DynamicStatementParserTest.php`
- `tests/Unit/ParsersTest.php`
- `tests/Feature/ExcelAmountCorrectnessTest.php`
- `tests/Feature/SecurityAuditTest.php` — `dynamic parser sanitizes formula-like cell values`

---

## Troubleshooting

| Síntoma | Causa | Solución |
|---|---|---|
| "El archivo no contiene movimientos válidos" (sync) | `start_row` mal configurado o columnas incorrectas | Ajustar `BankFormat` con `POST /bank-formats/preview` para ver las primeras 100 filas reales |
| "Formato incorrecto: La Fila Inicial (X) no contiene datos válidos" | La fila `start_row` está vacía o tiene texto | Mover `start_row` a la primera fila real de datos |
| Archivo queda `fallido` tras upload | Ver `storage/logs/worker-imports.log` o `docker logs queue-imports` | — |
| Movimientos duplicados de un upload previo aparecen otra vez | El índice `movimientos_dedup_index` está roto o faltante | Verificar con `SHOW INDEX FROM movimientos`; re-correr migración `2026_03_23_165654_add_dedup_index_to_movimientos_table.php` |

---

## Referencias

- Controller: `app/Http/Controllers/FileUploadController.php`
- Job: `app/Jobs/ProcessBankStatement.php`
- Parser: `app/Services/Parsers/DynamicStatementParser.php`
- Factory: `app/Services/Parsers/StatementParserFactory.php`
- Abstract: `app/Services/Parsers/AbstractBankParser.php`
- UI: `resources/js/Components/UploadModal.vue`, `resources/js/Pages/BankFormats/Create.vue`
