> ⚠️ **SUPERSEDED / HISTÓRICO**
>
> Esta versión (v2, Feb 2026) fue reemplazada por el conjunto modular en `docs/` — ver `docs/INDEX.md`.
>
> Gaps conocidos de esta versión:
> - Falta encabezado de la sección 3 (frontend) — contenido roto.
> - Omite `TeamOwned` global scope, middleware `SetGlobalDateFilters`, commands artisan, flujo async de exports, rechazo PPD, Complemento de Pago, flag `confirm_multi_rfc`, batch-destroy endpoints, i18n.
>
> **No editar** — archivo histórico.

---

# Fuente de Verdad Técnica (Source of Truth)

**Última Auditoría:** 12 Febrero 2026  
**Versión del Documento:** 2.0 (Forensic Audit)  
**Alcance:** Repositorio Completo (Backend, Frontend, Base de Datos, Configuración)

Este documento es la **única fuente de verdad** técnica del proyecto. Describe la arquitectura, modelos de datos y flujos de negocio tal como existen en el código, con evidencia explícita.

---

## 1. Mapa del Repositorio

Estructura de alto nivel y responsabilidades clave detectadas en la auditoría.

| Directorio                      | Responsabilidad / Propósito                      | Archivos Clave (Evidencia)                                                          |
| :------------------------------ | :----------------------------------------------- | :---------------------------------------------------------------------------------- |
| **app/Models**                  | Definición de Entidades Eloquent y Scopes.       | `Factura.php`, `Movimiento.php`, `Conciliacion.php`, `Team.php`                     |
| **app/Http/Controllers**        | Manejo de peticiones HTTP y renderizado Inertia. | `ReconciliationController.php`, `FacturaController.php`, `MovimientoController.php` |
| **app/Services/Reconciliation** | Lógica pura de negocio para cruce de datos.      | `MatcherService.php`                                                                |
| **app/Services/Parsers**        | Lógica de lectura de archivos bancarios.         | `StatementParserFactory.php`, `DynamicStatementParser.php`                          |
| **app/Exports**                 | Generación de reportes Excel/PDF.                | `ReconciliationExport.php`, `ReconciliationPdfExport.php`                           |
| **database/migrations**         | Definición estricta del esquema de BD.           | Migraciones de 2026.                                                                |
| **resources/js/Pages**          | Vistas Frontend (Vue 3).                         | `Reconciliation/Workbench.vue`, `Movements/Index.vue`                               |
| **routes**                      | Definición de puntos de entrada.                 | `web.php`                                                                           |

---

## 2. Arquitectura de Backend (Laravel 12)

### 2.0 Colas y Procesamiento (Background Jobs)

El sistema utiliza **Laravel Queues** para tareas pesadas, divididas en canales específicos para garantizar que las exportaciones no bloqueen a las importaciones y viceversa.

| Cola (Queue) | Propósito                                                                 | Jobs Asignados                                                               | Comando de Worker (Producción)                         |
| :----------- | :------------------------------------------------------------------------ | :--------------------------------------------------------------------------- | :----------------------------------------------------- |
| **imports**  | Procesamiento de archivos subidos (XML, Excel). Alta prioridad operativa. | `ProcessXmlUpload`, `ProcessBankStatement`                                   | `php artisan queue:work --queue=imports --timeout=600` |
| **exports**  | Generación de reportes bajo demanda. Baja prioridad.                      | `GenerateReconciliationExcelExportJob`, `GenerateReconciliationPdfExportJob` | `php artisan queue:work --queue=exports --timeout=600` |
| **default**  | Tareas generales o notificaciones (si aplica).                            | N/A por ahora.                                                               | `php artisan queue:work --queue=default`               |

> **Nota de Safeguard:** Si un archivo (`Archivo`) o exportación (`ExportRequest`) permanece en estado pendiente por más de **2 minutos**, el sistema reportará un posible "Worker Offline" o retraso en la UI.

### 2.1 Módulos y Rutas

El sistema está construido alrededor de un **Tenancy por Equipo** (`team_id`).

#### A. Módulo de Conciliación (`ReconciliationController`)

Recurso principal para la operación de cruce.

| Ruta (URI)                   | Método | Acción         | Descripción (Input -> Output)                                                                    |
| :--------------------------- | :----- | :------------- | :----------------------------------------------------------------------------------------------- |
| `/reconciliation`            | GET    | `index`        | Renderiza **`Reconciliation/Workbench`**. Carga facturas/movimientos pendientes.                 |
| `/reconciliation/auto`       | GET    | `auto`         | Renderiza **`Reconciliation/Matches`**. Ejecuta `MatcherService@findMatches`.                    |
| `/reconciliation`            | POST   | `store`        | Guarda conciliación **Manual**. Valida RFCs idénticos. Llama `MatcherService@reconcile`.         |
| `/reconciliation/batch`      | POST   | `batch`        | Guarda conciliación **Automática**. Procesa arreglo de matches sugeridos.                        |
| `/reconciliation/history`    | GET    | `history`      | Renderiza **`Reconciliation/History`**. Lista grupos conciliados paginados.                      |
| `/reconciliation/status`     | GET    | `status`       | Renderiza **`Reconciliation/Status`**. Muestra dashboard de pendientes vs conciliados.           |
| `/reconciliation/export`     | GET    | `export`       | Descarga Excel (`xlsx`) o PDF (`pdf`) usando `ReconciliationExport` o `ReconciliationPdfExport`. |
| `/reconciliation/{id}`       | DELETE | `destroy`      | Desconcilia un registro individual (soft delete o hard delete según logica de modelo).           |
| `/reconciliation/group/{id}` | DELETE | `destroyGroup` | Desconcilia todo un grupo (deshace el match N-M).                                                |

#### B. Módulo de Facturas (`FacturaController`)

| Ruta (URI)         | Método | Acción    | Descripción                                                   |
| :----------------- | :----- | :-------- | :------------------------------------------------------------ |
| `/invoices`        | GET    | `index`   | Renderiza **`Invoices/Index`**. Listado paginado con filtros. |
| `/invoices/{file}` | DELETE | `destroy` | Elimina un archivo de facturas y sus registros hijos.         |

#### C. Módulo de Movimientos (`MovimientoController`)

| Ruta (URI)          | Método | Acción  | Descripción                                                                   |
| :------------------ | :----- | :------ | :---------------------------------------------------------------------------- |
| `/movements`        | GET    | `index` | Renderiza **`Movements/Index`**. Vista dual (Archivos vs Movimientos planos). |
| `/movements/{file}` | GET    | `show`  | API JSON. Retorna los movimientos de un archivo específico para modales.      |

### 2.2 Servicios Clave

#### `App\Services\Reconciliation\MatcherService`

- **Responsabilidad:** Contiene la lógica "dura" de comparación.
- **Método `findMatches($teamId, $tolerance, $month, $year)`:**
    - Busca Facturas y Movimientos _pendientes_.
    - Filtra estrictamente por **Mes y Año** (si se proveen).
    - Compara montos usando `abs($factura - $movimiento) <= $tolerance`.
- **Método `reconcile($invoiceIds, $movementIds, $type, $date)`:**
    - Genera un `group_id` UUID único.
    - **Algoritmo de Saldos Restantes:** Al conciliar múltiples facturas con múltiples movimientos (N-M), el sistema calcula el "Monto Restante" de cada ítem en memoria.
    - Crea relaciones `Conciliacion` donde `monto_aplicado = min(factura_restante, movimiento_restante)`.
    - Esto previene la aplicación duplicada de fondos y garantiza integridad financiera.

#### `App\Services\Parsers\StatementParserFactory`

- **Responsabilidad:** Factory Pattern para instanciar el parser correcto.
- **Lógica:**
    - Recibe un `identifier` (ID del `BankFormat` o Código de Banco).
    - Busca el modelo `BankFormat` por ID o Nombre (si es string).
    - Retorna `new DynamicStatementParser($bankFormat)`.
- **Nota:** Permite parsear CSV/Excel basándose en columnas configuradas dinámicamente en BD (`start_row`, `date_column`, etc).

#### `App\Http\Controllers\FileUploadController` (Hybrid Dedupe)

- **Responsabilidad:** Recepción de archivos y feedback inmediato.
- **Lógica Híbrida:**
    - **Paso 1 (Síncrono):** Parsea el XML y verifica si el UUID ya existe en el equipo.
    - **Paso 2 (Feedback):** Retorna estado `duplicado` inmediatamente si existe.
    - **Paso 3 (Asíncrono):** Si es nuevo, encola `ProcessXmlUpload` y retorna `queued` (tratado como éxito visual).
    - **Soft Deletes:** No activados en Facturas. Borrar permite re-importar.
- **Manejo de Errores (Respuesta JSON):**
    - **Try-catch interno (por archivo):** Captura errores de parseo, validación RFC, duplicados. Retorna `200` con `results.file_errors[]` y contadores.
    - **Try-catch externo (global):** Captura errores inesperados (DB, sesión). Retorna `500` con `{ message: "..." }`.
    - **Frontend (`UploadModal.vue`):** El catch de axios lee `error.response.data.message` y `error.response.status` para mostrar el error real. Identifica 419 (sesión expirada) como caso especial.

#### `App\Jobs\GenerateReconciliation[Excel|Pdf]ExportJob`

- **Responsabilidad:** Generación de reportes en segundo plano para evitar timeouts.
- **Flujo:**
    1.  Usuario solicita exportación -> `ExportRequest` creado (estado `queued`).
    2.  Job procesa la data y guarda el archivo en `storage/app/exports/{team}/{user}/{uuid}.{ext}`.
    3.  Actualiza estado a `completed`.
    4.  Frontend pollea estado y descarga cuando está listo.

#### `App\Jobs\ProcessBankStatement`

Inventario de páginas principales y sus contratos de datos (Props).

### 3.1 `Reconciliation/Workbench.vue`

Interfaz principal de trabajo "Manual".

- **Props Recibidas:**
    - `invoices`: Array (Facturas pendientes).
    - `movements`: Array (Movimientos pendientes, filtro 'abono').
    - `tolerance`: Number (Configuración del equipo).
    - `filters`: Object (Estado actual de filtros month, year, date_range).
- **Componentes Clave:**
    - `WorkbenchColumns`: Divide la pantalla en dos listas seleccionables.
    - `WorkbenchSelectionSummary`: Barra inferior con totales seleccionados y botón de acción.
    - `ReconciliationModal`: Modal de confirmación final.
- **Estado Local:** `selectedInvoices`, `selectedMovements`. Calcula diferencia en tiempo real.

### 3.2 `Reconciliation/History.vue`

Historial de conciliaciones pasadas.

- **Props Recibidas:**
    - `reconciledGroups`: Object (Paginator). Contiene estructura transformada con `invoices` y `movements` anidados por grupo.
    - `filters`: Object.

### 3.3 `Movements/Index.vue`

Listado de cargas bancarias.

- **Props Recibidas:**
    - `files`: Array (Archivos de estados de cuenta subidos).
    - `movements`: Paginator (Vista plana de movimientos).
    - `filters`: Object.
- **Funcionalidad:**
    - Tiene un toggle `viewMode` ('files' vs 'movements').
    - Usa `MovementTable.vue` para la vista detallada.
    - **Indicador de Procesamiento:** La columna MOVIMIENTOS muestra badges según `estatus` del archivo: "Procesando..." (amber con spinner) para `pendiente`/`procesando`, "Error" (rojo) para `fallido`, "Worker Offline?" para archivos pendientes >2min, o el conteo numérico para `procesado`.
    - **Auto-polling:** Mientras existan archivos con `estatus` `pendiente` o `procesando`, la página refresca automáticamente cada 5 segundos vía `router.reload()`. El polling se detiene cuando todos los archivos terminan de procesar.
    - Permite eliminar archivos completos (`App\Models\Archivo` tiene borrado en cascada).

---

## 4. Modelo de Datos (Esquema de BD)

Basado en evidencia de `/database/migrations`. Todas las tablas principales tienen `timestamps`.

### Tablas Críticas

#### `teams`

- **PK:** `id`
- **Cols:** `user_id` (Dueño), `name`, `rfc`, `personal_team`.
- **Evidencia:** `create_teams_table`, `add_rfc_to_teams_table`.

#### `facturas`

- **PK:** `id`
- **FKs:** `team_id`, `user_id`, `file_id_xml`.
- **Datos:** `uuid` (Fiscal), `monto` (decimal 15,2), `fecha_emision` (date), `rfc` (Cliente/Receptor), `nombre`.
- **Evidencia:** `create_facturas_table`, `add_team_id_to_tables`.

#### `movimientos`

- **PK:** `id`
- **FKs:** `team_id`, `user_id`, `file_id` (Archivo origen), `banco_id`.
- **Datos:** `fecha` (date), `monto` (decimal 15,2), `tipo` ENUM('abono','cargo'), `referencia`, `descripcion`.
- **Integridad:** Columna `hash` única (por Team) para evitar duplicados.
- **Evidencia:** `create_movimientos_table`, `change_hash_unique_on_movimientos_table`.

#### `conciliacions` (Tabla Pivote Central)

- **PK:** `id`
- **FKs:** `team_id`, `factura_id`, `movimiento_id`.
- **Agrupación:** `group_id` (String/UUID) vincula múltiples filas en una sola operación de conciliación (N-M).
- **Datos:** `monto_aplicado`, `estatus` ('conciliado'), `tipo` ('automatico'/'manual'), `fecha_conciliacion`.
- **Evidencia:** `create_conciliacions_table`, `add_group_id_to_conciliaciones_table`.

#### `bank_formats`

- **PK:** `id`
- **FKs:** `team_id`.
- **Config:** `start_row`, `date_column`, `amount_column`, `debit_column`, `credit_column`.
- **Propósito:** Mapeo dinámico de columnas para importar Excel irreconocible.

#### `export_requests`

- **PK:** `id`
- **FKs:** `team_id`, `user_id`.
- **Datos:** `type` (xlsx/pdf), `status` (queued/processing/completed/failed), `file_path`, `filters` (JSON).
- **Propósito:** Gestión de colas para exportaciones pesadas asíncronas.

---

## 5. Reglas de Negocio y Seguridad

### 5.1 Tenancy (Seguridad)

- **Mecanismo:** Filtro explícito `where('team_id', auth()->user()->current_team_id)` en todos los controladores.
- **Validación:** En `ReconciliationController@batch` y `destroy`, se verifica manualmente que los recursos pertenezcan al equipo actual, lanzando `abort(403)` si no.

### 5.2 Conciliación

- **Validación de RFC:** En conciliación manual (`store`), el backend valida que todas las facturas seleccionadas tengan el **mismo RFC**.
- **Tolerancia:** Se permite una diferencia de montos definida en tabla `tolerancias`. El frontend advierte, pero el backend es quien permite o bloquea (aunque en `store` no se ve bloqueo explícito por tolerancia, solo advertencia en UI; la validación dura parece estar en la lógica de negocio o pendiente de implementar estricta en backend).
- **Fecha de Conciliación:** Se guarda la fecha en que se realiza la conciliación (`conciliacion_at`), o la fecha del movimiento si es automático.

### 5.3 Importación

- **Duplicidad:**
    - Archivos: Se calcula `checksum` (MD5/SHA) del archivo físico.
    - Movimientos: Deduplicación por comparación directa de columnas (`WHERE team_id = ? AND fecha = ? AND monto = ? AND descripcion = ?`). El índice `movimientos_dedup_index` optimiza esta consulta. La columna `hash` se mantiene por compatibilidad pero la deduplicación no depende de ella.

---

## 6. Limitaciones Conocidas

1.  **Validación de Tolerancia en Backend:** En `ReconciliationController@store` (Manual), no se observa una validación que _impida_ guardar si se excede la tolerancia. Solo se valida el RFC. La advertencia es visual en el Frontend.
2.  **Jobs Híbridos (Carga):** La carga de XML realiza una verificación de duplicados síncrona para feedback inmediato, pero delega la inserción a colas (`ProcessXmlUpload`).
3.  **Moneda:** No hay columna `moneda` en facturas ni movimientos. Se asume moneda única base (MXN) o implícita.

---

## 7. Índice de Evidencia

Archivos leídos físicamente para generar este reporte.

- `app/Http/Controllers/ReconciliationController.php`
- `app/Http/Controllers/FacturaController.php`
- `app/Http/Controllers/MovimientoController.php`
- `app/Services/Reconciliation/MatcherService.php`
- `app/Services/Parsers/StatementParserFactory.php`
- `app/Exports/ReconciliationPdfExport.php`
- `database/migrations/2026_01_23_211433_create_facturas_table.php`
- `database/migrations/2026_01_23_211434_create_movimientos_table.php`
- `database/migrations/2026_01_23_211435_create_conciliacions_table.php`
- `resources/js/Pages/Reconciliation/Workbench.vue`
- `resources/js/Pages/Movements/Index.vue`
- `resources/js/Pages/Invoices/Index.vue`
- `routes/web.php`
