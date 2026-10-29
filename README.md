# SuperAhorro GT — E-Commerce distribuido (Supermercado en línea)

Proyecto Final No. 2 — Auditoría y Seguridad de Sistemas, UMG.
Arquitectura de microservicios con API Gateway (Nginx), JWT Zero Trust, Redis, MariaDB y un servicio de auditoría dedicado.

## Requisitos

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (en Windows, con WSL 2)
- PHP 8+ solo para generar el `.env` y correr las pruebas (sirve el de XAMPP)

## Puesta en marcha

```bash
php scripts/generar-env.php        # crea .env con secretos aleatorios e imprime la contraseña del admin
docker compose up --build -d       # levanta toda la plataforma
docker compose ps                  # todos deben quedar "healthy"
```

Abrir <http://localhost>. La tienda permite ver el catálogo sin sesión; con una cuenta de cliente se usa el carrito, se confirma el pedido y se consulta el historial. El admin (credenciales en `.env`) entra a **Administración**: productos (precio, stock, imagen), pedidos (estados y exportación CSV) y bitácora de seguridad.

## Servicios

| Servicio | Puerto interno | Expuesto al host | Estado |
|---|---|---|---|
| gateway (Nginx) | 80 | **Sí** (único) | Listo |
| auth-service | 8081 | No | Listo |
| catalogo-service | 8082 | No | Listo |
| pedidos-service | 8083 | No | Listo |
| pagos-service | 8084 | No | Listo (Stripe test o modo demo gratis) |
| notificaciones-service | 8085 | No | Listo (EmailJS + OpenWeather) |
| security-audit-service | 8086 | No | Listo |
| mariadb-master | 3306 | No | Listo |
| mariadb-slave | 3306 | No | Listo (réplica read-only) |
| mariadb-audit | 3306 | No | Listo (volumen separado) |
| redis | 6379 | No | Listo |

## API (a través del gateway)

| Método | Ruta | Auth | Descripción |
|---|---|---|---|
| POST | `/api/auth/registro` | — | Registro de cliente (`nombre`, `email`, `password`) |
| POST | `/api/auth/login` | — | Devuelve un JWT de 1 h. 5 fallos → bloqueo de 15 min |
| GET | `/api/auth/me` | JWT | Perfil del usuario autenticado |
| POST | `/api/auth/logout` | JWT | Revoca el token (lista negra en Redis) |
| GET | `/api/auth/admin/acceso` | JWT admin | Registra `ACCESO_ADMIN_PANEL` |
| GET | `/api/auditoria/eventos` | JWT admin | Bitácora con filtros: `accion`, `resultado`, `servicio`, `usuario_id`, `pedido_id`, `desde`, `hasta`, `limite`, `offset` |
| GET | `/api/auditoria/eventos/resumen` | JWT admin | Conteo por tipo de evento (evidencia SEC-2) |
| GET | `/api/catalogo/categorias` | — | Categorías con conteo de productos (caché Redis) |
| GET | `/api/catalogo/productos` | — | Filtros `categoria`, `q`, `destacados`, `pagina`, `por_pagina`; indica `fuente_bd` y `cache` |
| GET | `/api/catalogo/productos/{id}` | — | Detalle de un producto |
| GET/POST | `/api/catalogo/admin/productos` | JWT admin | Listar todos / crear producto (`PRECIO_MODIFICADO`) |
| PUT/DELETE | `/api/catalogo/admin/productos/{id}` | JWT admin | Editar precio, stock, imagen… / desactivar |
| POST | `/api/catalogo/admin/cloudinary/firma` | JWT admin | Firma para subir imágenes directo a Cloudinary |
| GET | `/api/pedidos/carrito` | JWT | Carrito con precios actuales del catálogo |
| PUT | `/api/pedidos/carrito/items` | JWT | `producto_id`, `cantidad` (0 elimina) |
| DELETE | `/api/pedidos/carrito` | JWT | Vaciar carrito |
| POST | `/api/pedidos/` | JWT | Confirmar pedido con datos de entrega (`direccion`, `zona`, `municipio`, `telefono`, `referencias`, `notas`) |
| GET | `/api/pedidos/` | JWT | Mis pedidos |
| GET | `/api/pedidos/{uuid}` | JWT | Detalle (solo el dueño o el admin) |
| GET | `/api/pedidos/admin/pedidos` | JWT admin | Todos los pedidos, filtro `estado` |
| PUT | `/api/pedidos/admin/pedidos/{uuid}/estado` | JWT admin | Cambiar estado (`PEDIDO_ESTADO_CAMBIADO`) |
| GET | `/api/pedidos/admin/clientes/exportar` | JWT admin | CSV de clientes (`EXPORTACION_CLIENTES`) |

Las rutas `/interno/*` de catálogo (consulta de precios y stock) solo las usa `pedidos-service` con un JWT de servicio; el gateway las bloquea desde fuera.

## Pruebas rápidas

```bash
php scripts/test-jwt.php     # firma, alg=none, rol alterado, expiración, emisor
```

Evidencia de hardening (SEC-5): `docker ps` debe mostrar solo `0.0.0.0:80->80/tcp` en el gateway; MariaDB y Redis no publican puertos.

Probar que la bitácora es append-only (PowerShell):

```powershell
'DELETE FROM security_audit_logs;' | docker compose exec -T mariadb-audit sh -c 'mariadb -u$AUDIT_DB_USER -p$AUDIT_DB_PASSWORD audit_db'
# → ERROR 1142: DELETE command denied
```

Pruebas completas (semana 1: 23 verificaciones; semana 2: 52 verificaciones de catálogo, pedidos, A01/A03, fraude de precios, replicación y failover):

```powershell
powershell -ExecutionPolicy Bypass -File scripts\prueba-semana1.ps1
powershell -ExecutionPolicy Bypass -File scripts\prueba-semana2.ps1
powershell -ExecutionPolicy Bypass -File scripts\prueba-semana3.ps1
powershell -ExecutionPolicy Bypass -File scripts\zap-baseline.ps1
```

Documentación de cierre: [`docs/03_BCP_DRP.md`](docs/03_BCP_DRP.md), [`docs/04_ALE_ROSI.md`](docs/04_ALE_ROSI.md), [`docs/05_semana3_y_cierre.md`](docs/05_semana3_y_cierre.md), [`docs/QUE_TE_TOCA.md`](docs/QUE_TE_TOCA.md).

## Imágenes con Cloudinary (opcional)

Crear una cuenta gratuita en <https://cloudinary.com>, copiar *Cloud name*, *API Key* y *API Secret* a `.env` (`CLOUDINARY_*`) y ejecutar `docker compose up -d catalogo-service`. Luego, en Administración → Productos → **Imagen**. Sin credenciales la tienda muestra íconos y todo lo demás funciona.

## Estructura

```
gateway/            nginx.conf (WAF, rate limiting, cabeceras), proxy.conf, html/ (frontend)
services/shared/    Config, Http, Router, Jwt, Guard (Zero Trust), Db (Master/Slave), RedisClient, Cache,
                    TokenBlacklist, AuditClient, ServiceClient (llamadas entre servicios con JWT de servicio)
services/<servicio>/public/index.php   rutas del servicio
services/<servicio>/app/               lógica (fuera del docroot)
db/master/init/     auth_db, catalogo_db, pedidos_db y usuarios de BD con mínimo privilegio
db/audit/init/      audit_db, triggers append-only y usuario solo INSERT/SELECT
docs/               STRIDE, DFD y documentación de seguridad
scripts/            generación de .env y pruebas
```

## Seguridad

- Ningún secreto está en el código ni en `docker-compose.yml`: todo se lee de `.env`, que está en `.gitignore`.
- Contenedores PHP con `USER www-data`, `no-new-privileges`, `mem_limit` y `cpu_shares`.
- Detalle del modelado de amenazas en [`docs/01_STRIDE_DFD_semana1.md`](docs/01_STRIDE_DFD_semana1.md).
- Controles OWASP A01/A02/A03, replicación y eventos de la semana 2 en [`docs/02_semana2.md`](docs/02_semana2.md).
