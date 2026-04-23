# Flow: Export (Excel / PDF)

Generación asíncrona de reportes de conciliación.

**Controller**: `app/Http/Controllers/ReconciliationController.php` (métodos `export`, `checkExportStatus`, `downloadExport`)
**Jobs**: `GenerateReconciliationExcelExportJob`, `GenerateReconciliationPdfExportJob` (cola `exports`)
**Excel**: `app/Exports/ReconciliationExport.php` + 5 sheets
**PDF**: `app/Exports/ReconciliationPdfExport.php` + view Blade `resources/views/exports/reconciliation/pdf_report.blade.php`

---

## Diagrama

```
[UI Reconciliation/History.vue or Status.vue]
        │  GET /reconciliation/export?format=xlsx&month=4&year=2026&...
        │  (throttle:10,1)
        ▼
┌─────────────────────────────────────────────────────┐
│  ReconciliationController::export                    │
│  ────────────────────────────                        │
│  1. Validate query params                            │
│  2. Create ExportRequest(status='queued', filters)   │
│  3. dispatch(GenerateReconciliation{Excel|Pdf}Job)   │
│  4. Response JSON:                                   │
│     { id, status: 'queued', message }                │
└──────────────────────────────────────────┬──────────┘
                                           │
                                           ▼ (async, cola 'exports')
┌─────────────────────────────────────────────────────┐
│  GenerateReconciliation{Excel|Pdf}ExportJob::handle  │
│  ──────────────────────                              │
│  1. exportRequest.status = 'processing'              │
│  2. Construir path: exports/{team}/{user}/{uuid}.ext │
│  3. Excel:  Excel::store(new ReconciliationExport..) │
│     PDF:    Pdf::loadView(...)->output() → Storage   │
│  4. exportRequest.status = 'completed'               │
│     exportRequest.file_path = path                   │
│     exportRequest.file_name = 'conciliacion_*.ext'   │
│                                                      │
│  En error: status='failed', error_message, throw     │
└─────────────────────────────────────────────────────┘

[UI polling cada N segundos]
        │  GET /reconciliation/export/{id}/status
        ▼
  { status: 'queued' | 'processing' | 'completed' | 'failed',
    error_message: string | null,
    is_offline: bool (true si queued > 2min) }

[Al completar]
        │  GET /reconciliation/export/{id}/download
        ▼
  Storage::download(file_path, file_name)
```

---

## Parámetros de request

```
GET /reconciliation/export
  ?format=xlsx | pdf          (default: xlsx)
  &month=1..12                (opcional)
  &year=2000..2100            (opcional)
  &date_from=YYYY-MM-DD       (opcional, toma precedencia sobre month/year)
  &date_to=YYYY-MM-DD
  &search=string
  &amount_min=number
  &amount_max=number
```

Validación backend en `ReconciliationController::export:509-517`.

---

## Estructura del Excel (ReconciliationExport)

5 hojas (`app/Exports/Sheets/`):

1. **SummarySheet** — totales, conteos, rango de fechas, filtros aplicados.
2. **ConciliatedInvoicesSheet** — facturas conciliadas en el período filtrado.
3. **ConciliatedMovementsSheet** — movimientos conciliados.
4. **PendingInvoicesSheet** — facturas pendientes (no conciliadas) del período.
5. **PendingMovementsSheet** — movimientos pendientes tipo abono.

Los `groupIds` se calculan primero en `ReconciliationExport::getMatchingGroupIds()` para scoping consistente entre sheets.

Styling vía `app/Exports/Traits/ExcelStylingHelper.php`.

---

## Estructura del PDF

Vista Blade: `resources/views/exports/reconciliation/pdf_report.blade.php`.
Formato: A4 portrait (`$pdf->setPaper('a4', 'portrait')`).

Data preparada por `ReconciliationPdfExport::view()->getData()` — mismo dominio que Excel pero serializado para Blade.

---

## Estados de `ExportRequest.status`

```
queued   → processing → completed
                     └→ failed       (excepción en job, o cleanup-stuck tras 2h)
```

### `is_offline` flag

`checkExportStatus` retorna `is_offline=true` si `status=queued` **y** `created_at > 2 minutes ago`. Señal de worker caído para que el UI pueda avisar al usuario.

---

## Storage de archivos generados

- Path: `exports/{team_id}/{user_id}/{uuid}.{ext}`.
- Disk: default (`local` en dev, `s3` en prod si configurado).
- **No hay cleanup automático de archivos viejos** — considerar un command futuro para purgar exports >30 días.

---

## Autorización de descarga

`ReconciliationController::downloadExport`:

1. `ExportRequest` debe existir **dentro del team** del usuario (404 si no — gracias a `TeamOwned` + filtro explícito).
2. `user_id` del export debe ser `Auth::id()` (403 si no). **Otros miembros del team NO pueden descargar exports de otro**.
3. `status` debe ser `completed` y `file_path` presente (404 si no).
4. Archivo debe existir en Storage (404 si no).

Tests: `tests/Feature/ReconciliationExportTest.php`.

---

## Rate limiting

`throttle:10,1` en `GET /reconciliation/export` — 10 solicitudes por minuto. Evita abuso (los jobs son costosos).

Los endpoints de polling (`/status`, `/download`) **no** tienen throttle dedicado — corren bajo el rate limit global.

---

## Retry / fallos

Jobs configurados con:
- `tries = 3`
- `backoff = [30, 120, 300]`
- `timeout = 600` (10 minutos)

`failed()` callback actualiza `ExportRequest.status = 'failed'` con `error_message`.

Para exports "zombie" que se quedaron en `processing` >2h: correr `php artisan queue:cleanup-stuck`.

---

## Cleanup manual de archivos

```php
// En tinker, eliminar archivos de exports viejos (>30 días):
use App\Models\ExportRequest;
use Illuminate\Support\Facades\Storage;

ExportRequest::where('created_at', '<', now()->subDays(30))
    ->where('status', 'completed')
    ->each(function ($export) {
        if ($export->file_path && Storage::exists($export->file_path)) {
            Storage::delete($export->file_path);
        }
        $export->delete();
    });
```

---

## Tests relevantes

- `tests/Feature/ReconciliationExportTest.php` — checkExportStatus authorization (403 vs 404).
- `tests/Feature/SecurityAuditTest.php` — `export jobs have retry and backoff configured`.
- `tests/Feature/ExcelAmountCorrectnessTest.php` — verifica que los montos exportados a Excel respeten el formato decimal.

---

## Referencias

- Controller: `app/Http/Controllers/ReconciliationController.php`
- Jobs: `app/Jobs/GenerateReconciliation{Excel,Pdf}ExportJob.php`
- Excel: `app/Exports/ReconciliationExport.php` + `app/Exports/Sheets/`
- PDF: `app/Exports/ReconciliationPdfExport.php` + Blade view
- Modelo: `app/Models/ExportRequest.php`
- Migración: `database/migrations/2026_02_12_223751_create_export_requests_table.php`
