# Operations

Todo lo necesario para operar la aplicación en dev y producción.

---

## Colas

### Conexión default

- `QUEUE_CONNECTION=redis` en producción.
- `QUEUE_CONNECTION=sync` o `database` en dev/tests según `.env`.

### Colas definidas

| Queue | Propósito | Jobs | Timeout |
|---|---|---|---|
| `imports` | Procesar archivos subidos (XML, Excel, CSV) | `ProcessXmlUpload`, `ProcessBankStatement` | 600s |
| `exports` | Generar reportes pesados | `GenerateReconciliationExcelExportJob`, `GenerateReconciliationPdfExportJob` | 600s |
| `default` | Tareas generales (ningún job hoy) | — | — |

### Config de jobs

Todos los jobs tienen:
- `tries = 3`
- `backoff = [30, 120, 300]` (30s, 2min, 5min entre reintentos)
- `failed()` callback que marca el recurso asociado como `fallido`/`failed`

### Workers en desarrollo (Sail)

Los servicios `queue-imports` y `queue-exports` del `compose.yaml` se levantan automáticamente con `vendor/bin/sail up -d`.

```bash
# Logs del worker de imports
docker logs conciliacion-bancaria-queue-imports-1 -f

# Logs del worker de exports
docker logs conciliacion-bancaria-queue-exports-1 -f
```

### Workers en producción (Supervisor)

Ver `README.md` sección "Configurar Queue Workers (Supervisor)" para la config completa.

```ini
[program:conciliacion-imports]
command=php artisan queue:work --queue=imports --sleep=3 --tries=3 --timeout=600
numprocs=2

[program:conciliacion-exports]
command=php artisan queue:work --queue=exports --sleep=3 --tries=3 --timeout=600
numprocs=1

[program:conciliacion-default]
command=php artisan queue:work --queue=default --sleep=3 --tries=3
numprocs=1
```

Después de deploy:
```bash
sudo supervisorctl restart conciliacion-imports:* conciliacion-exports:* conciliacion-default:*
```

---

## Comandos Artisan custom

### `facturas:backfill-cfdi-types`

**Archivo**: `app/Console/Commands/BackfillCfdiTypes.php`

Re-parsea XMLs almacenados para popular `tipo_comprobante` y `metodo_pago` en facturas viejas que no tienen estos campos.

**Opciones**:
- `--dry-run` — muestra qué haría sin tocar la BD.
- `--delete-ppd` — elimina facturas tipo `I` con `metodo_pago=PPD` (cascade también borra sus conciliaciones).

**Ejemplo**:
```bash
vendor/bin/sail artisan facturas:backfill-cfdi-types --dry-run
vendor/bin/sail artisan facturas:backfill-cfdi-types --delete-ppd
```

Para Complementos de Pago (tipo `P`), también actualiza `monto` y `fecha_emision` con los valores reales del nodo `<Pago>`.

---

### `queue:cleanup-stuck`

**Archivo**: `app/Console/Commands/CleanupStuckJobs.php`

Marca como fallidos los jobs que llevan >2 horas en estado `processing`/`procesando`. Limpia dos tipos:

1. `ExportRequest.status='processing'` → `'failed'` con `error_message='Job abandoned by worker (timeout or crash).'`
2. `Archivo.estatus='procesando'` → `'fallido'`

**Uso recomendado**: cron cada 15 minutos o ejecución manual tras un crash de worker.

```bash
vendor/bin/sail artisan queue:cleanup-stuck
```

Crontab sugerido:
```cron
*/15 * * * * cd /var/www/conciliacion && php artisan queue:cleanup-stuck >> storage/logs/cleanup.log 2>&1
```

---

### `app:recalculate-movement-hashes`

**Archivo**: `app/Console/Commands/RecalculateMovementHashes.php`

Recalcula hashes SHA-256 de todos los movimientos usando la fórmula actual (`fecha` + `monto` + `descripcion`) y resuelve duplicados migrando las `conciliacions` al registro más antiguo antes de borrar los duplicados.

**Fases**:
1. Añade columna temporal `new_hash` si no existe.
2. Calcula nuevos hashes chunkeando en grupos de 500.
3. Agrupa por `(team_id, new_hash)`; los que aparecen >1 vez son duplicados.
4. Pide confirmación antes de resolver.
5. Dentro de transacción: migra conciliaciones de duplicados al `keepId` (más antiguo) y borra.
6. Copia `new_hash → hash` y elimina columna temporal.

**Opciones**:
- `--dry-run` — solo reporta, no toca BD.

**Cuándo usarlo**: después de cambiar la fórmula de hash o si hay sospecha de duplicados lógicos.

```bash
vendor/bin/sail artisan app:recalculate-movement-hashes --dry-run
vendor/bin/sail artisan app:recalculate-movement-hashes
```

### `egresos:generar-recurrentes` (Finanzas Fase 3)

Genera egresos a partir de las plantillas `egresos_recurrentes` **vencidas** (`activo` y `proxima_generacion <= hoy`), de **todos** los teams (recorre con `withoutGlobalScopes()`, no por efecto colateral de "no hay sesión"). **Idempotente** (no duplica) y con **catch-up** (genera los periodos faltantes hasta hoy, tope 24/plantilla). Marca cada egreso con `origen='recurrente'` y `egreso_recurrente_id`, avanza `proxima_generacion` y aplica vigencia (`num_pagos`/`hasta_fecha` → `activo=false`). Frecuencias mensual/bimestral/trimestral/anual + ajuste a día hábil por fin de semana.

Detalles de robustez (Fase 3, hardening post-review):
- **Idempotencia respaldada en DB:** índice único `egresos_recurrente_periodo_unique` sobre `egresos(egreso_recurrente_id, fecha)`. El `exists()` del comando es la ruta normal; si una corrida concurrente (manual vs cron) gana la carrera, el `INSERT` duplicado se rechaza y se trata como "ya generado". Un periodo ya existente **cuenta** para `num_pagos` (no se sobre-genera).
- **Vigencia `hasta_fecha`** se evalúa contra la **fecha de pago ajustada** (no el día nominal): con `habil_siguiente`, un nominal de fin de mes que caería después de `fecha_fin` **no** se genera.
- **Tope de catch-up (24):** al alcanzarlo, además del `warn` en consola se escribe `Log::warning` (el stdout del scheduler se descarta).

```bash
php artisan egresos:generar-recurrentes --dry-run   # reporta sin persistir
php artisan egresos:generar-recurrentes             # genera
```

### `nomina:generar` (Finanzas Fase 3B)

**Archivo**: `app/Console/Commands/GenerarNomina.php`

Genera los egresos de **nómina quincenal** por cada empleado **activo** de **todos** los teams (recorre con `withoutGlobalScopes()`, no por sesión). Por cada quincena del periodo crea hasta **dos** egresos: la parte **fiscal** (`concepto_nomina='fiscal'`) y, si aplica, el **complemento** (`concepto_nomina='complemento'`). Marca `origen='recurrente'` y `empleado_id`.

- **Fechas de quincena** (vía `PayrollCalculator`): día **15** y **último día del mes**; si la fecha de pago cae en fin de semana se ajusta al **día hábil anterior** (reusa `RecurrenceCalculator::applyDiaHabil`). Sin festivos en v1.
- **Montos**: salario **mensual** → **mitad por quincena**. Fiscal = `salario_fiscal / 2`; complemento = `(salario_real - salario_fiscal) / 2`.
- **Mapeo de categoría** (por nombre exacto, activas, `tipo=egreso`): fiscal de empleado `clasificacion='tecnica'` → **"Nómina técnica facturable"** (COGS); `administrativa`/null → **"Nómina fiscal"**; complemento → **"Nómina complemento / real"**. Si falta la categoría del team se **omite** ese egreso (con `Log::warning`), no truena.
- **Complemento ≤ 0** (salario real == fiscal) → se **omite** (no se crea egreso de complemento).
- **Elegibilidad** por **fecha nominal** (no la de pago): no genera quincenas con `nominal < fecha_entrada` ni `nominal > fecha_baja`.
- **Idempotencia**: respaldada en DB por el índice único `egresos_empleado_periodo_unique (empleado_id, fecha, concepto_nomina)`. El comando hace `exists()` (ruta normal) y envuelve el `INSERT` en `try/catch` de `QueryException` (carrera manual vs cron → duplicado rechazado y tratado como "ya generado"). El discriminador `concepto_nomina` desacopla la idempotencia de la categoría, así que cambiar `clasificacion` entre corridas **no** duplica el egreso fiscal.
- **Ventana móvil de catch-up: 40 días** (default, sin `--month`): recorre las quincenas con fecha nominal entre `hoy-40d` y `hoy`. Nunca pre-genera futuro (`nominal <= hoy`).
- **`--month=YYYY-MM`**: apunta a ese mes (ignora la ventana móvil) — para backfill de meses fuera de la ventana.
- **`--dry-run`**: reporta sin persistir.
- **Resumen** de corrida: egresos creados / omitidos por categoría faltante / complemento ≤ 0.
- **Limitación**: un outage > 40 días deja huecos; reponerlos con `--month=YYYY-MM` por cada mes faltante.

```bash
php artisan nomina:generar --dry-run            # reporta sin persistir (ventana 40d)
php artisan nomina:generar                       # genera la ventana móvil
php artisan nomina:generar --month=2026-06       # backfill de un mes concreto
```

---

## Scheduler

Definido en `routes/console.php` (Laravel 12 no usa `Console/Kernel`):

```php
Schedule::command('egresos:generar-recurrentes')->dailyAt('01:00')->withoutOverlapping()->onOneServer();
Schedule::command('nomina:generar')->dailyAt('01:30')->withoutOverlapping()->onOneServer();
```

Ambos se corren **diario** y son **idempotentes**, así que correr de más es inocuo: `egresos:generar-recurrentes` decide qué generar vía `proxima_generacion`; `nomina:generar` vía la ventana móvil de 40 días + el índice único. `withoutOverlapping` evita solapes en un host; `onOneServer` evita que en despliegue multi-servidor cada host genere el mismo periodo (requiere un cache store con locks: database/redis). `nomina:generar` corre a las 01:30 (tras los recurrentes) para no solapar.

- **Producción:** una sola entrada de cron dispara TODOS los schedules:
  ```cron
  * * * * * cd /var/www/conciliacion && php artisan schedule:run >> /dev/null 2>&1
  ```
- **Local (Herd, sin cron):** `php artisan schedule:work` en una terminal (corre el scheduler en foreground), **o** ejecutar el comando a mano cuando se necesite.
- Ver lo programado: `php artisan schedule:list`.

---

## Storage

- **Default disk**: `local` en dev, `s3` opcional en prod (si `FILESYSTEM_DISK=s3`).
- Paths usados:
    - `uploads/teams/{teamId}/xml/{uuid}_{originalName}.xml` — XMLs subidos
    - `statements/{teamId}/...` — estados de cuenta
    - `exports/{teamId}/{userId}/{uuid}.{ext}` — reportes generados

### S3

Los paquetes `league/flysystem-aws-s3-v3 ^3.32` están instalados. Configurar:
```env
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
FILESYSTEM_DISK=s3
```

---

## Observabilidad

### Logs

- Driver: `stack` → `single` (todo a `storage/logs/laravel.log`).
- `LOG_LEVEL=debug` en dev, `error` en prod.
- Jobs usan `Log::error`, `Log::warning`, `Log::info`, `Log::debug` con contexto.

### Pail (laravel/pail)

`composer run dev` arranca pail. En producción usar `tail -f storage/logs/laravel.log`.

### Estados visibles a usuario

- **UI de movements** (`resources/js/Pages/Movements/Index.vue`):
    - Badge "Procesando..." si `estatus=pendiente|procesando`.
    - Badge "Error" si `estatus=fallido`.
    - Badge "Worker Offline?" si `estatus=pendiente` y `created_at > 2min`.
    - Auto-polling cada 5 segundos mientras haya archivos pendientes.

- **UI de exports** (`Reconciliation/Status.vue`):
    - Polling `GET /reconciliation/export/{id}/status` mientras `queued` o `processing`.
    - Flag `is_offline=true` cuando `queued > 2min` → sugerir al usuario verificar workers.

---

## Tests

### Ejecutar

```bash
# Todos
vendor/bin/sail artisan test --compact

# Un archivo
vendor/bin/sail artisan test --compact tests/Feature/MatcherServiceTest.php

# Filtro
vendor/bin/sail artisan test --compact --filter=test_xml_rejects_mismatch_rfc
```

### Estructura

- `tests/Feature/` — 19 archivos (Auth/, BankFormat, CleanupStuckJobs, ExcelAmountCorrectness, FileUpload, MatcherService, Profile, ReconciliationExport, ReconciliationTenancy, Reconciliation, Regression, SecurityAudit).
- `tests/Unit/` — `DynamicStatementParserTest`, `ParsersTest`, `ReconciliationLogicTest`.
- Pest 4. Usa `RefreshDatabase`.

### Tests que NO se pueden eliminar

- `ReconciliationTenancyTest`
- `SecurityAuditTest`
- `MatcherServiceTest`

Son pruebas de regresión sobre reglas críticas (tenancy, corrupción de datos).

---

## Deploy típico

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo supervisorctl restart conciliacion-imports:* conciliacion-exports:* conciliacion-default:*
```

---

## Troubleshooting común

### "Worker Offline?" aparece en la UI

1. Verificar que los contenedores `queue-imports`/`queue-exports` estén corriendo (Sail) o que Supervisor tenga los procesos activos (prod).
2. Revisar logs: `docker logs conciliacion-bancaria-queue-imports-1 --tail 200` o `storage/logs/worker-imports.log`.
3. Si un archivo quedó en `procesando` >2h, correr `queue:cleanup-stuck`.

### Exports que nunca terminan

1. Mismo check de workers.
2. Verificar `ExportRequest::latest()->first()->status` y `error_message`.
3. Correr `queue:cleanup-stuck` para los que llevan >2h.

### Movimientos duplicados tras re-subir

Esperado: el segundo upload lo detecta por `checksum` del archivo. Si **los movimientos individuales** quedaron duplicados (ej. re-cargaron con otro archivo), correr `app:recalculate-movement-hashes`.

### "El archivo no contiene movimientos válidos"

El `BankFormat` no está mapeando las columnas correctamente. Revisar en `/bank-formats/{id}/edit`:
- `start_row` debe apuntar a la primera fila de datos (no al header).
- `date_column`, `description_column` deben ser letras válidas (A, B, ..., AB, etc.).
- Si usa columnas separadas de débito/crédito, dejar `amount_column` vacío.

### XML rechazado por RFC

Si configuraste `team.rfc`, tanto emisor como receptor del XML son comparados contra él. Desactiva la validación dejando `team.rfc` vacío.

---

## Referencias

- `app/Console/Commands/`
- `composer.json:scripts`
- `README.md` (setup + deploy completo)
