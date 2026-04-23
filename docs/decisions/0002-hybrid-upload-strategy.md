# ADR 0002 — Upload híbrido (sync validation + async processing)

**Estado**: Aceptada
**Fecha**: 2026-02 (posterior a `AUDITORIA_TECNICA`)

## Contexto

Antes: la carga de XMLs y estados de cuenta se procesaba síncronamente en la request HTTP. Resultado: timeouts (504) con lotes de 50+ XMLs, y bloqueo del pool PHP-FPM durante parsing de Excels grandes. Reportado como hallazgo crítico en `AUDITORIA_TECNICA_2026-02-12.md`.

Queríamos:
1. Feedback inmediato al usuario (no hacer polling para cosas que se pueden validar rápido).
2. No bloquear la request con trabajo pesado.
3. Preservar experiencia simple en UI (drop files → get result).

## Decisión

Adoptar flujo **híbrido sync + async**:

### Parte síncrona (controller)

Todo lo que puede fallar rápido con feedback claro:
- Validaciones de formato (extensión, MIME, tamaño).
- Parseo inicial del XML con `CfdiParserService` (milisegundos).
- Dedup por UUID o checksum.
- Validación de RFC y rechazo PPD.

Si falla: error inmediato con mensaje específico (200 con `file_errors[]` o 422 para statement).

### Parte asíncrona (job sobre cola `imports`)

Solo la persistencia de datos, que puede demorar en batches grandes o con locking:
- Crear `Factura` / `Movimiento` dentro de transacción.
- Re-validar defensivamente (dedup, RFC) en caso de race conditions.
- Actualizar `Archivo.estatus`.

El UI hace polling sobre `estatus` del `Archivo` (cada 5s) para reflejar progreso.

## Alternativas consideradas

- **Todo síncrono**: descartado por timeouts.
- **Todo asíncrono**: rompe UX — usuario sube un XML y no sabe si tiene el RFC correcto hasta 30s después.
- **WebSockets / SSE**: excesivo para este volumen. Polling cada 5s es suficiente.

## Consecuencias

✅ Usuario ve errores comunes (XML malformado, PPD, RFC mismatch, duplicado) al instante.
✅ Subir 100 XMLs no bloquea la app.
✅ Los jobs tienen retry con backoff, así que glitches transitorios se auto-recuperan.

⚠️ Doble parseo del XML (sync + async) — costo aceptable, el parseo es barato.
⚠️ Dedup sync + async puede tener falsos negativos en race conditions (dos uploads simultáneos del mismo UUID). El job tiene un segundo check dentro de transacción que cubre esto.
⚠️ Si el worker está caído, los uploads quedan en `pendiente` indefinidamente — mitigado con badge "Worker Offline?" tras 2min y `queue:cleanup-stuck` cada 2h.

## Tests de protección

- `tests/Feature/FileUploadTest.php`
- `tests/Feature/RegressionTest.php` (todo el archivo).

## Referencias

- `app/Http/Controllers/FileUploadController.php`
- `app/Jobs/ProcessXmlUpload.php`
- `app/Jobs/ProcessBankStatement.php`
- `docs/flows/import-xml.md`
- `docs/flows/import-statement.md`
