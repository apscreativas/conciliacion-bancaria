# ADR 0003 — MatcherService con algoritmo de saldo restante

**Estado**: Aceptada
**Fecha**: 2026-02 (fix post-auditoría)

## Contexto

La implementación inicial de `MatcherService::reconcile` usaba dos bucles anidados que creaban una `Conciliacion` por cada combinación (factura × movimiento). Con selección N×M esto causaba **double-application** de fondos.

Ejemplo problemático: 2 facturas $100 + 2 movimientos $100.
- Loop naive crearía 4 registros de $100 cada uno → $400 "aplicados" para $200 reales.

Reportado como **hallazgo crítico A#1** en `AUDITORIA_TECNICA_2026-02-12.md`.

## Decisión

Refactorizar a algoritmo de **saldo restante**:

1. Antes del loop: calcular `invoiceRemaining[id] = monto` y `movementRemaining[id] = monto`.
2. En cada iteración del par (invoice, movement):
    - Si alguno de los dos tiene remaining < epsilon, skip.
    - `amountToApply = min(invoiceRemaining, movementRemaining)` redondeado a 2 decimales.
    - Crear `Conciliacion` con `monto_aplicado = amountToApply`.
    - Restar `amountToApply` de ambos remainders.
    - Si el invoice queda pagado, break al siguiente.
3. Todo dentro de `DB::transaction` con `lockForUpdate` sobre facturas y movimientos para prevenir race conditions.

### Manejo de floats

- `epsilon = 0.001` para comparaciones (evita falsos positivos por drift de float).
- `round(amount, 2)` en `amountToApply` y en cada resta.

## Alternativas consideradas

- **Agrupar todo en una sola `Conciliacion`**: rompería el modelo pivot (un registro por par). Se descartó.
- **Pre-validar que `sum(invoices) == sum(movements)`**: poco flexible (no permite conciliación parcial).
- **Usar `decimal` nativo de BD para el cálculo**: Laravel no soporta arithmetic nativo. Seguimos en floats con epsilon.

## Consecuencias

✅ Ejemplo 2×2: genera 2 registros de $100 = $200 total aplicado. Correcto.
✅ Casos 1:N y N:1 funcionan bien (reparte montos proporcionalmente según orden de iteración).
✅ Locking previene race conditions en concurrencia.

⚠️ El orden de iteración afecta qué facturas se marcan primero como pagadas. Para conciliaciones donde el orden importa (ej. FIFO contable), el usuario debe controlar el orden de `$invoiceIds` al llamar.
⚠️ Conciliación parcial no se expresa explícitamente (no hay `pendiente_revision` activo hoy) — un invoice con remaining > 0 al final del loop no queda pagado, pero sí tiene registros parciales en `conciliacions`. En el listado de pendientes se usa `doesntHave('conciliaciones')`, lo que excluye incorrectamente invoices con pagos parciales.

## TODO futuro

Evaluar cambiar el scope "pendientes" de `doesntHave('conciliaciones')` a algo como `whereRaw('monto > (select sum(monto_aplicado) from conciliacions where factura_id = facturas.id)')` para soportar pagos parciales explícitamente.

## Tests de protección

- `tests/Unit/ReconciliationLogicTest.php`
- `tests/Feature/MatcherServiceTest.php`

## Referencias

- `app/Services/Reconciliation/MatcherService.php:166-251`
- `docs/business-rules.md` §5
- `docs/flows/reconciliation.md`
