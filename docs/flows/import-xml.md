# Flow: Import XML (CFDI)

Flujo híbrido para subir facturas electrónicas CFDI.

**Entry point**: `POST /upload/files` con field `files[]` (uno o varios archivos XML).
**Controller**: `app/Http/Controllers/FileUploadController.php`
**Job**: `app/Jobs/ProcessXmlUpload.php` (cola `imports`)
**Parser**: `app/Services/Xml/CfdiParserService.php`

---

## Diagrama

```
[UI UploadModal.vue]
        │  multipart POST /upload/files
        ▼
┌─────────────────────────────────────────────────────┐
│  FileUploadController::store   (síncrono)            │
│  ─────────────────────────────                       │
│  Por cada archivo XML:                               │
│    1. Validar extensión .xml                         │
│    2. Validar MIME whitelist                         │
│    3. Validar tamaño ≤ 10MB                          │
│    4. Parsear con CfdiParserService (sync)           │
│    5. Rechazar si tipo=I & metodo=PPD                │
│    6. Validar RFC del team (emisor o receptor)       │
│    7. Check duplicado por UUID                       │
│    8. storeAs() en storage                           │
│    9. Crear Archivo con estatus='pendiente'          │
│   10. dispatch(ProcessXmlUpload)     ───────────┐    │
│                                                 │    │
│  Response: { success, results, toasts }         │    │
└─────────────────────────────────────────────────┼────┘
                                                  │
                                                  ▼ (async)
┌─────────────────────────────────────────────────────┐
│  ProcessXmlUpload::handle  (cola 'imports')          │
│  ─────────────────────────                           │
│  1. archivo.estatus = 'procesando'                   │
│  2. Storage::get() del XML                           │
│  3. Re-parsear con CfdiParserService                 │
│  4. Defensa: re-validar PPD, marcar rechazado       │
│  5. DB::transaction:                                 │
│     5a. Re-check duplicado UUID                      │
│     5b. Re-validar RFC del team                      │
│     5c. Factura::create(...)                         │
│     5d. archivo.estatus = 'procesado'                │
│                                                      │
│  En error: estatus='fallido', throw                  │
└─────────────────────────────────────────────────────┘
```

---

## Validaciones (FileUploadController)

| # | Check | Error message (en español) | HTTP status |
|---|---|---|---|
| 1 | Extensión = `xml` | "Error ({file}): No es un archivo XML." | 200 con `results.file_errors` |
| 2 | MIME en whitelist | "El tipo de archivo no es XML válido (MIME: {mime})." | 200 |
| 3 | Tamaño ≤ 10 MB | "El archivo excede el tamaño máximo de 10MB." | 200 |
| 4 | XML parseable | "XML Inválido - {razón}" | 200 |
| 5 | No es PPD tipo I | "Rechazado: Esta factura es PPD (...). Suba el Complemento de Pago correspondiente." | 200 |
| 6 | RFC coincide (team configurado) | "El RFC del equipo ({rfc}) no coincide con el Emisor ({e}) ni con el Receptor ({r}) del XML." | 200 |
| 7 | UUID no duplicado | "Duplicado: Esta factura ya fue registrada anteriormente (UUID: {uuid})." | 200 |

**Nota**: errores por archivo devuelven **200 con `file_errors[]`** (permite mostrar feedback granular para batches). Solo errores inesperados (DB down, etc.) devuelven 500 con `{ message }`.

---

## Qué extrae `CfdiParserService::parse`

```php
[
    'uuid' => string,               // UUID del TimbreFiscalDigital
    'folio' => string,              // Atributo Folio del CFDI
    'fecha_emision' => 'Y-m-d',     // Del atributo Fecha; para tipo P viene de pago20:Pago
    'total' => float,               // Atributo Total; para tipo P suma de <Pago Monto>
    'tipo_comprobante' => 'I'|'E'|'P'|'T'|'N',
    'metodo_pago' => 'PUE'|'PPD'|null,
    'rfc_emisor' => string,
    'nombre_emisor' => string,
    'rfc_receptor' => string,
    'nombre_receptor' => string,
]
```

### Caso especial: Complemento de Pago (tipo P)

- El `Total` del root es 0.
- El parser busca nodos `pago20:Pago` (CFDI 4.0) o `pago10:Pago` (CFDI 3.3).
- Suma `Monto` de todos los `<Pago>`.
- `fecha_emision` = primer `FechaPago` encontrado (si múltiples, se usa el primero; ver TODO en el código).
- Si la suma es 0 o no hay nodos, **rechaza** el complemento.

### Response frontend

```json
{
  "success": true,
  "results": {
    "xml_processed": 3,         // archivos exitosamente encolados
    "xml_xml_duplicates": 1,    // duplicados detectados
    "xml_other_errors": 0,
    "file_errors": [...]        // mensajes legibles para UI
  },
  "toasts": [...],
  "processed_xml_count": 3
}
```

`success = true` si al menos 1 archivo fue procesado o un statement fue encolado o detectado como duplicado.

---

## Estados del Archivo XML

```
pendiente   →   procesando   →   procesado
                             └──►  duplicado    (UUID ya existe en team)
                             └──►  rechazado    (PPD tipo I)
                             └──►  fallido      (error en job)
```

El UI (`Invoices/Index.vue`) hace polling cada 5s mientras haya archivos `pendiente`/`procesando`. Badge "Worker Offline?" aparece si `estatus=pendiente` y `created_at > 2min` (ver `MovimientoController::index:62-64` para lógica similar).

---

## Re-subir un archivo eliminado

- Al borrar un archivo XML (soft delete **no está activo**), la `Factura` asociada se borra por cascade (`onDelete('cascade')` en FK).
- El UUID queda liberado, así que re-subir el mismo XML pasa todas las validaciones sin problema.

---

## Tests relevantes

- `tests/Feature/FileUploadTest.php` — flujo end-to-end básico.
- `tests/Feature/RegressionTest.php`:
    - `test_xml_stores_receptor_rfc` — verifica que se guarda el RFC del receptor (no del emisor).
    - `test_xml_rejects_mismatch_rfc`
    - `test_xml_allows_matching_rfc`
- `tests/Feature/SecurityAuditTest.php`:
    - `xml upload rejects non-xml mime type`
    - `xml upload rejects oversized files`

---

## Troubleshooting

| Síntoma | Causa probable | Solución |
|---|---|---|
| "Worker Offline?" en UI | Worker `queue-imports` caído | Ver `operations.md §Troubleshooting` |
| "El RFC del equipo no coincide..." | Team tiene `rfc` configurado que no matchea | Editar team RFC o subir XMLs del RFC correcto |
| "Esta factura es PPD" | CFDI tipo I con método PPD | Subir el Complemento de Pago (tipo P) correspondiente |
| "Duplicado" | UUID ya registrado | Eliminar la factura anterior primero, o revisar si es realmente la misma |
| Archivo queda `pendiente` >5min | Worker procesando otra cosa o caído | `docker logs conciliacion-bancaria-queue-imports-1` |

---

## Referencias

- Controller: `app/Http/Controllers/FileUploadController.php`
- Job: `app/Jobs/ProcessXmlUpload.php`
- Parser: `app/Services/Xml/CfdiParserService.php`
- UI Upload: `resources/js/Components/UploadModal.vue`
- UI List: `resources/js/Pages/Invoices/Index.vue`
