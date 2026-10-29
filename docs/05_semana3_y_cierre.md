# Semana 3–4 — Pagos, notificaciones, evidencias y cierre

## APIs externas (todas con capa gratuita)

| API | Uso | Costo |
|---|---|---|
| **Stripe** (test) | Checkout + webhook firmado | Q0 (tarjetas de prueba) |
| **EmailJS** | Correo de pago confirmado | Q0 (plan free) |
| **OpenWeatherMap** | Clima en checkout (Guatemala) | Q0 (API key free) |
| **Cloudinary** | Imágenes de productos | Q0 (plan free) |

Sin claves Stripe el sistema usa **modo demo** (`/api/pagos/demo-confirmar`) para que puedas presentar el flujo completo sin gastar ni crear cuenta aún. Con claves test, el flujo real redirige a Stripe Checkout.

## Eventos de auditoría (14/14)

Tras un ciclo completo (registro, login, pedido, pago demo/Stripe, cambio de estado, export CSV) deben aparecer los 14 códigos, incluidos:

- `PAGO_INICIADO`
- `PAGO_CONFIRMADO`
- `PAGO_FALLIDO` (si pruebas un fallo)
- `WEBHOOK_STRIPE_RECIBIDO` (con Stripe CLI + webhook secret)

## OWASP ZAP (gratis)

```powershell
powershell -ExecutionPolicy Bypass -File scripts\zap-baseline.ps1
```

Genera `docs/evidencias/zap-report.html`. Requiere Docker.

## Hosting gratis (lo haces tú con cuenta)

Opciones sin tarjeta o con trial:

1. **Render** / **Railway** / **Fly.io** — subir el repo y usar Docker Compose o servicios separados.
2. Mientras tanto, la entrega local en `http://localhost` + capturas cumple el avance; el hosting es requisito de cierre.

Pasos mínimos:

1. Crear cuenta gratis en el proveedor.
2. Conectar el repo privado de GitHub.
3. Definir las mismas variables de `.env` en el panel del hosting (nunca en el código).
4. Abrir solo el puerto 80/443 del gateway.

## Checklist de entrega PDF

- [ ] Justificación opción B + DFD/STRIDE (`docs/01_…`)
- [ ] OWASP A01–A03 semana 2 (`docs/02_…`)
- [ ] BCP/DRP (`docs/03_…`)
- [ ] ALE/ROSI (`docs/04_…`)
- [ ] Capturas: `docker ps` (sin puertos BD/Redis), panel admin Chart.js, bitácora 30+ registros
- [ ] Reporte ZAP
- [ ] Video o capturas del flujo: comprar → pagar → admin prepara → entrega
