# SDD — <Nombre del módulo / Fase NN>

> SDD-lite. Sale del paso PLAN/VALIDATE de CLAUDE.md. 1 página para CRUD; ampliar §6–§7 en módulos sensibles a dinero (Fase 1, 3, 3B, 5).
> Módulo: · Fase: · Autor: · Fecha: · Estado: borrador | aprobado | implementado

## 1. Objetivo
Qué resuelve este módulo en 2-3 líneas. Liga al PRD (`docs/prd/finanzas-egresos-multiempresa.md`, Fase NN).

## 2. Alcance
- Incluye:
- NO incluye (no-goals):

## 3. Modelo de datos (delta)
Tablas/columnas nuevas o modificadas. Confirmar `team_id` + `TeamOwned` si es dominio.

```
<migración / columnas>
```

## 4. Endpoints / rutas
| Método | Ruta | Controller | Notas (auth, throttle, ownership) |
|---|---|---|---|

## 5. Archivos a tocar (orden)
1.
2.
(>3 archivos → confirmar con el usuario antes, §2 CLAUDE.md)

## 6. Reglas de negocio y casos borde
Reglas que el módulo debe cumplir. **Ampliar en módulos de dinero.**
- 
- Casos borde (ej. fin de mes en domingo → viernes; idempotencia; doble conteo; baja a mitad de periodo):

## 7. Plan de pruebas
- Pest (feature/unit):
- Tenancy:
- E2E Playwright (si UI):
- **Casos numéricos fijos (módulos de dinero):** entradas → resultado esperado al centavo.

## 8. Impacto en lo existente
- ¿Toca tenancy / transacciones / colas / migraciones / contratos Inertia? (§2.4 CLAUDE.md → VALIDATE)
- ¿Toca el motor de conciliación? (debe ser NO, salvo Fase 1 que solo agrega columna)

## 9. Riesgos y mitigaciones
| Riesgo | Impacto | Mitigación |
|---|---|---|

## 10. Definition of Done
Aplica el DoD §8 del PRD (A pruebas → B docs → C commit/reviews → D gate financiero). Marcar al cerrar.
