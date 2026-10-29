# Qué te toca hacer a ti (todo gratis)

El código de las semanas 1–3 ya está. Tú solo creas cuentas **gratuitas**, pegas claves en `.env` y generas evidencias para la nota.

## 1. Obligatorio para presentar (sin gastar)

| # | Qué | Tiempo |
|---|---|---|
| 1 | Recargar http://localhost (Ctrl+F5) y probar: comprar → **Pagar ahora** (modo demo) → admin cambia estados | 10 min |
| 2 | Correr `scripts\prueba-semana1.ps1`, `prueba-semana2.ps1`, `prueba-semana3.ps1` y capturar la pantalla | 10 min |
| 3 | Correr `scripts\zap-baseline.ps1` y guardar `docs/evidencias/zap-report.html` | 15 min |
| 4 | Captura de `docker compose ps` (solo el gateway con puerto 80) | 2 min |
| 5 | Crear **repo privado** en GitHub y subir el proyecto **sin** el archivo `.env` | 15 min |
| 6 | Armar el PDF final con los docs de la carpeta `docs/` | 1–2 h |

## 2. Opcional pero recomendado (sigue siendo gratis)

| Servicio | Para qué | Dónde sacar la key |
|---|---|---|
| **Stripe** (modo test) | Pago real con tarjeta de prueba `4242…` | https://dashboard.stripe.com (cuenta free) → Developers → API keys |
| **EmailJS** | Correo al confirmar pago | https://www.emailjs.com |
| **OpenWeather** | Clima real en el checkout | https://openweathermap.org/api |
| **Cloudinary** | Fotos de productos | https://cloudinary.com |

Pégalas en `.env` y luego:

```powershell
docker compose up -d pagos-service notificaciones-service catalogo-service
```

## 3. Hosting en la nube (gratis, lo haces tú)

No se puede terminar el deploy desde aquí sin **tu** cuenta en Render/Railway/Fly.io.

1. Crea cuenta gratis.
2. Conecta el repo de GitHub.
3. Copia las variables de `.env` al panel (no las subas al repo).
4. Captura la URL HTTPS para el PDF.

Mientras tanto, localhost + capturas sirven para avanzar.

## 4. Lo que NO debes hacer

- Subir `.env` a GitHub  
- Usar `docker compose down -v` (borra bitácora y pedidos)  
- Guardar números de tarjeta en ningún lado (Stripe se encarga)  

## Credenciales locales

- Admin: las de tu `.env` (`ADMIN_EMAIL` / `ADMIN_PASSWORD`)  
- Cliente: regístrate desde la web  
