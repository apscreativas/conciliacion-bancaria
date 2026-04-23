# ADR 0004 — Dedup de movimientos por comparación directa de columnas

**Estado**: Aceptada
**Fecha**: 2026-03-23

## Contexto

Originalmente la deduplicación de movimientos bancarios se hacía vía una columna `hash` SHA-256 calculada sobre `fecha + monto + descripcion`, con un índice `UNIQUE(team_id, hash)`.

Problemas detectados:
1. **Fragilidad ante cambios de formato**: si normalizábamos la descripción distinto (espacios, case), el hash cambiaba y los "duplicados" lógicos pasaban como nuevos.
2. **Fragilidad ante cambios de tipo**: `number_format` del monto con distintas configuraciones generaba hashes diferentes.
3. **Debuggability pobre**: ver "por qué dos movimientos son iguales" requería recalcular hashes manualmente.
4. **Cambiar la fórmula del hash** requería una migración intrusiva y potencialmente recrear el índice único.

## Decisión

Usar **comparación directa de columnas** para la detección de duplicados:

```php
Movimiento::where('team_id', $teamId)
    ->where('fecha', $movData['fecha'])
    ->where('monto', $movData['monto'])
    ->where('descripcion', $movData['descripcion'])
    ->exists();
```

Soportado por un índice compuesto:

```sql
INDEX movimientos_dedup_index (team_id, fecha, monto, descripcion)
```

Migración: `2026_03_23_165654_add_dedup_index_to_movimientos_table.php`.

### Qué pasa con el `hash`

- Se mantiene por compatibilidad (el constraint `UNIQUE(team_id, hash)` sigue activo).
- `ProcessBankStatement` calcula y guarda el hash al crear movimientos nuevos.
- **No** es la fuente de verdad para dedup.

### Comando de migración

`app:recalculate-movement-hashes` (`app/Console/Commands/RecalculateMovementHashes.php`) permite:
- Recalcular hashes tras cambios de fórmula.
- Detectar duplicados lógicos (mismo team+fecha+monto+descripcion pero distinto hash histórico).
- Migrar `conciliacions` al registro más antiguo antes de borrar duplicados.

## Alternativas consideradas

- **Seguir con hash recalculable**: cada vez que cambiemos la normalización habría que migrar. Fatal.
- **Dedup en aplicación con hashmap**: consume memoria en batches grandes; se descartó.
- **Dedup vía constraint UNIQUE en columnas**: sería `UNIQUE(team_id, fecha, monto, descripcion)`. No se escogió porque `descripcion` puede ser NULL (en algunos formatos), y MySQL trata NULLs en constraints uniquely como distintos. Además la exception del DB es fea de manejar.

## Consecuencias

✅ Cambios de normalización ya no requieren re-calcular hashes.
✅ Fácil de debuggear: un `SELECT` directo muestra los duplicados.
✅ El índice `movimientos_dedup_index` cubre la query y mantiene performance.

⚠️ Descripciones que difieren por espacios o case **no** se detectan como duplicados. Si esto fuera problema, habría que normalizar la `descripcion` en el parser antes de guardarla.
⚠️ Dos dedup-sources (columnas + hash) que pueden desincronizarse. El comando `recalculate-movement-hashes` existe precisamente para este caso.

## Tests de protección

- `tests/Feature/BankFormatTest.php`
- `tests/Feature/RegressionTest.php` (`test_bank_statement_controller_rejects_duplicates`)
- `tests/Feature/CleanupStuckJobsTest.php`

## Referencias

- `app/Jobs/ProcessBankStatement.php:82-90`
- `app/Console/Commands/RecalculateMovementHashes.php`
- `database/migrations/2026_03_23_165654_add_dedup_index_to_movimientos_table.php`
- `docs/flows/import-statement.md`
- `docs/business-rules.md` §4
