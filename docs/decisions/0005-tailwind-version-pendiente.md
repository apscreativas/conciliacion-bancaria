# ADR 0005 — Tailwind v3 + v4 coexistiendo (PENDIENTE)

**Estado**: 🟡 **Sin resolver** — decisión pospuesta
**Fecha**: 2026-02 (detectado en auditoría), sigue vigente

## Contexto

`package.json` declara:

```json
"devDependencies": {
  "@tailwindcss/forms": "^0.5.3",
  "@tailwindcss/vite": "^4.0.0",          // Tailwind v4 plugin
  "tailwindcss": "^3.2.1",                // Tailwind v3 core
  ...
}
```

Esto es una configuración "Frankenstein":
- El paquete `@tailwindcss/vite@4` está diseñado para usarse con Tailwind v4 (que cambió completamente arquitectura: no más config file, PostCSS plugin nativo).
- El paquete `tailwindcss@3` sigue usando el pipeline v3 con `tailwind.config.js`, PostCSS, `@tailwind base/components/utilities`, etc.

Revisando el repo:
- `tailwind.config.js` existe → configuración estilo v3.
- `postcss.config.js` existe → pipeline v3.
- Las clases del proyecto (`resources/css/app.css`, componentes Vue) usan sintaxis v3.

Esto significa que `@tailwindcss/vite@4` probablemente **no se está usando** (o solo como plugin pasivo). Pero su presencia:
- Aumenta tamaño de node_modules y tiempo de instalación.
- Confunde a agentes/devs nuevos que no saben qué versión es "la buena".
- Tiene riesgo de breaking cambios si se actualiza accidentalmente.

## Decisión

**Dejarlo como está** hasta que haya una razón de negocio para migrar.

Razones para **no** actualizar a v4 ahora:
- v4 es relativamente nueva, con cambios grandes (no config file, PostCSS diferente, clases movidas).
- No hay bug ni bloqueador que requiera v4.
- Migrar implicaría touch & go en todo el frontend para verificar.

Razones para **no** remover `@tailwindcss/vite@4`:
- Podría estar siendo usado silenciosamente por el build.
- Sin verificación exhaustiva, removerlo puede romper el bundle en producción.

## Acción requerida antes de actualizar/limpiar

Cualquier cambio a la configuración de Tailwind debe:

1. Verificar con `npm run build` limpio que el output CSS es el esperado.
2. Revisar diff visual de al menos 10 páginas representativas.
3. Documentar esta ADR como "Resuelta" con la decisión final (v3-only, v4-only, o migración gradual).

## Consecuencias

⚠️ Tech debt visible. Dev nuevo que llegue al repo se pregunta "v3 o v4?".
⚠️ Un `npm update` podría accidentalmente "resolver" esto de forma incorrecta.
✅ El sistema funciona — no hay bug activo.
✅ No se asume riesgo de migración prematura.

## Referencias

- `package.json`
- `tailwind.config.js`
- `postcss.config.js`
- `vite.config.js`
- Reporte original: `docs/_archive/AUDITORIA_TECNICA_2026-02-12.md` §C#5
