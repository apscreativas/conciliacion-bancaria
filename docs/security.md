# Security

Controles de seguridad que ya están en el código. Cualquier cambio que debilite estos controles debe tener justificación explícita y un ADR en `decisions/`.

---

## 1. Tenancy (control principal)

Ver también `architecture.md §Tenancy` y `business-rules.md §1`.

### Defensa en capas

1. **Global scope `TeamOwned`** — filtra automáticamente por `team_id` en queries Eloquent. Aplicado en `Factura`, `Movimiento`, `Conciliacion`, `Archivo`, `BankFormat`, `ExportRequest`, `Tolerancia`.
2. **Filtros explícitos en controllers** — `where('team_id', auth()->user()->current_team_id)` aun sobre modelos que tienen global scope. No eliminar esta redundancia.
3. **Validación de ownership previa a operaciones masivas** — `ReconciliationController::batch`, `::store`, `::destroyGroup` verifican que cada ID del request pertenezca al team.

### Respuestas 403 vs 404

- `403` cuando el recurso existe pero el usuario no tiene permiso (ej. otro team tiene acceso).
- `404` cuando el recurso no está en el team del usuario — previene enumeration attacks.

### Tests

- `tests/Feature/ReconciliationTenancyTest.php`
- `tests/Feature/SecurityAuditTest.php` (bloque "destroy group only deletes own team records")

---

## 2. CSRF

- Laravel aplica CSRF a todas las rutas POST/PUT/PATCH/DELETE del grupo `web`.
- **Invitación de team two-step** (`business-rules.md §2`) previene CSRF:
    - GET landing (seguro) sólo renderiza una página.
    - POST requiere CSRF token válido para efectivamente unir al usuario.
- Tests: `SecurityAuditTest.php` "invitation GET shows landing page without auto-joining".

---

## 3. Mass assignment

### Protecciones explícitas

- `User`: `$fillable = ['name', 'email', 'password']`. `current_team_id` **no** es fillable — se asigna con `forceFill`.
- `TeamInvitation`: `$fillable = ['email', 'role']`. `token` **no** es fillable — se genera en `booted()`.

### Tests

- `SecurityAuditTest.php` "user model does not allow mass assignment of current_team_id"
- `SecurityAuditTest.php` "team invitation token is not mass-assignable"

---

## 4. Rate limiting

| Endpoint | Limit |
|---|---|
| `GET /reconciliation/export` | `throttle:10,1` (10/min) |
| Auth email verify/send | `throttle:6,1` (6/min) |
| Resto de rutas auth | Global 60/min (Laravel default) |

El endpoint `POST /upload/files` **no** tiene throttle explícito actualmente — hay un test `SecurityAuditTest` que espera `status=429` al enviar 31 requests, pero **no hay middleware `throttle` en `routes/web.php` sobre esta ruta**. Si el test pasa es por el rate limit global de 60/min. Considerar añadir `throttle:30,1` dedicado.

---

## 5. Upload de archivos

### Validaciones en `FileUploadController` (ver `business-rules.md §3`)

- Extensión `.xml` para facturas; extensión reconocible (`xlsx`/`xls`/`csv`) para statements.
- MIME whitelist (XML solamente acepta `application/xml`, `text/xml`, `text/plain`).
- Tamaño máximo: **10 MB** (tanto XML como statement vía `AbstractBankParser`).
- RFC del team debe coincidir con emisor o receptor del CFDI (si team tiene RFC configurado).
- UUID único por team.
- Checksum MD5 del archivo → dedup (evita re-subir el mismo archivo).
- Rechazo explícito de facturas PPD.

### Tests

- `SecurityAuditTest.php` "xml upload rejects non-xml mime type"
- `SecurityAuditTest.php` "xml upload rejects oversized files"
- `RegressionTest.php` "test_xml_rejects_mismatch_rfc"
- `RegressionTest.php` "test_bank_statement_controller_rejects_duplicates"

---

## 6. CSV / Excel formula injection

`DynamicStatementParser::sanitizeCellValue` (`app/Services/Parsers/DynamicStatementParser.php:212-220`) prefija con `'` cualquier celda que empiece con `=`, `+`, `-`, `@`, `\t`, `\r` antes de guardarla. Esto previene que una celda maliciosa se evalúe como fórmula cuando el movimiento se re-exporta a Excel.

Test: `SecurityAuditTest.php` "dynamic parser sanitizes formula-like cell values".

---

## 7. XXE (XML External Entity)

`CfdiParserService::parse` (`app/Services/Xml/CfdiParserService.php:74-78`) usa:

```php
new SimpleXMLElement($content, LIBXML_NONET | LIBXML_NOWARNING);
```

- `LIBXML_NONET` bloquea carga por red.
- No se habilita `LIBXML_NOENT` ni `LIBXML_DTDLOAD`.
- libxml 2.9+ tiene entidades deshabilitadas por default.

---

## 8. Sesiones (hardened)

Configuración default en `.env.example`:

```env
SESSION_DRIVER=database
SESSION_LIFETIME=60         # 60 min
SESSION_ENCRYPT=true        # sesiones encriptadas en DB
```

Test: `SecurityAuditTest.php` "env example has hardened session defaults".

### Axios interceptor (frontend)

`resources/js/bootstrap.ts` tiene un interceptor que detecta 401/419 y redirige a `/login` automáticamente (evita mostrar errores crípticos cuando la sesión expira durante una operación).

Test: `SecurityAuditTest.php` "bootstrap includes session expiration interceptor".

---

## 9. XSS

- Inertia/Vue **no** usa `v-html` con datos de usuario — `SecurityAuditTest.php` "pagination components do not use v-html" asegura que la paginación (que antes sí lo hacía con `link.label`) ya no lo tiene.
- Vue auto-escapa por default en interpolación `{{ }}`.
- Toasts y flash messages se pasan como string y se renderizan sin `v-html`.

---

## 10. Password

- Hash: `bcrypt` via casts `'password' => 'hashed'` en `User` (`User.php:63`).
- Cost: `BCRYPT_ROUNDS=12` (default seguro).
- Reset: flujo estándar de Breeze con notificación custom `ResetPasswordNotification`.

---

## 11. Authorization en recursos

No hay Policies extensivas — la protección vive en controllers. El único Policy es `TeamPolicy::update` (verificado con `ownsTeam`). Otras verificaciones explícitas:

- `BankFormatController::edit/update/destroy` — check `team_id` + abort 403.
- `ToleranciaController::edit/update` — check `user_id === team.user_id`, abort 403.
- `TeamController::update` — `$this->authorize('update', $team)` + check explícito (redundante pero defensivo).
- `TeamMemberController::store/destroy` — check owner del team, o self-remove.
- `ReconciliationController::store/batch` — ownership de cada ID.
- `ReconciliationController::downloadExport/checkExportStatus` — `user_id === auth::id()` (no basta con estar en el team).

---

## 12. HTTPS en ambientes con tunneling

`AppServiceProvider::boot()` fuerza HTTPS cuando `request()->getHost()` contiene `ngrok-free.dev`. Evita URLs rotas cuando se expone la app con ngrok para testing/demo.

---

## 13. Secretos

- `.env` nunca se commitea (standard Laravel).
- `.env.example` documenta variables sin valores reales.
- Nunca usar `env()` fuera de `config/*.php`.

---

## 14. Actualizaciones de seguridad pendientes

| Item | Estado | Referencia |
|---|---|---|
| Rate limit en `/upload/files` | Implícito (rate limit global). Considerar dedicado | `security.md §4` |
| Tailwind v3 + v4 coexistiendo | Bug latente, no de seguridad | `decisions/0005` |
| Política formal de logs (PII) | No documentada | TODO |
| 2FA | No implementado | TODO |

---

## Referencias

- `app/Http/Controllers/FileUploadController.php`
- `app/Services/Parsers/DynamicStatementParser.php`
- `app/Services/Xml/CfdiParserService.php`
- `app/Models/Traits/TeamOwned.php`
- `tests/Feature/SecurityAuditTest.php`
- `tests/Feature/RegressionTest.php`
- `tests/Feature/ReconciliationTenancyTest.php`
- `.env.example`
