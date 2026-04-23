# Documentación técnica — Conciliación Bancaria

> **Fuente de verdad viva** del proyecto. Todo cambio de comportamiento documentado aquí.
> Para setup y despliegue ver `/README.md`. Para reglas de agentes IA ver `/CLAUDE.md` y `/AGENTS.md`.

---

## Mapa de documentos

| Documento | Propósito |
|---|---|
| [`architecture.md`](architecture.md) | Stack, tenancy, middleware, shared Inertia props, providers, i18n |
| [`domain.md`](domain.md) | Modelos Eloquent, relaciones, traits, tablas, migraciones, índices |
| [`endpoints.md`](endpoints.md) | Tabla completa de rutas con `controller@action`, middleware y nombres |
| [`business-rules.md`](business-rules.md) | Reglas de negocio: conciliación, dedup, RFC, PPD, tolerancia, scoring |
| [`operations.md`](operations.md) | Colas, workers, comandos artisan, troubleshooting operativo |
| [`security.md`](security.md) | Tenancy, CSRF, rate limit, MIME, CSV injection, sesiones |
| [`flows/import-xml.md`](flows/import-xml.md) | Flujo híbrido sync+async para cargar CFDI |
| [`flows/import-statement.md`](flows/import-statement.md) | Validación sync + job async para estados de cuenta |
| [`flows/reconciliation.md`](flows/reconciliation.md) | Conciliación manual y automática |
| [`flows/export.md`](flows/export.md) | ExportRequest lifecycle, polling y descarga |
| [`decisions/`](decisions/) | ADRs — decisiones arquitectónicas no obvias |
| [`_archive/`](_archive/) | Auditorías y versiones históricas — no editar |

---

## Cambié X ¿dónde actualizo?

| Cambio | Documento a editar |
|---|---|
| Nueva ruta en `routes/web.php` | `endpoints.md` |
| Nueva migración o relación Eloquent | `domain.md` |
| Nueva regla de negocio (validación, lógica financiera) | `business-rules.md` |
| Nuevo comando artisan | `operations.md` |
| Nuevo middleware o provider | `architecture.md` |
| Cambio en validaciones, rate limits, autorización | `security.md` |
| Cambio en `MatcherService` | `business-rules.md` + `flows/reconciliation.md` |
| Cambio en `FileUploadController` o parsers | `flows/import-xml.md` o `flows/import-statement.md` |
| Cambio en `GenerateReconciliation*Job` o `ExportRequest` | `flows/export.md` |
| Decisión arquitectónica nueva | `decisions/NNNN-titulo.md` |

---

## Convenciones de estilo

- Todas las rutas relativas al repo (ej. `app/Services/Reconciliation/MatcherService.php:120`).
- Cuando cites una ruta, incluye línea si es precisa (`:120`).
- Tablas antes que prosa cuando haya múltiples entidades.
- Si detectas drift entre código y un doc, pon un bloque `> ⚠️ DOC DRIFT: fecha — descripción` arriba del doc afectado en vez de silenciar el problema.

---

## Estado actual

- **Última auditoría forense:** Feb 2026 (ver `_archive/`).
- **Rewrite documental:** Abril 2026 — estructura modular actual.
- **Proyectos conocidos pendientes:** unificar Tailwind v3/v4 (`decisions/0005`).
