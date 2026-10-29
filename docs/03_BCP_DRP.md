# BCP / DRP — SuperAhorro GT

**BCP** = Plan de Continuidad del Negocio  
**DRP** = Plan de Recuperación ante Desastres  

Objetivo: que la tienda siga vendiendo (o se recupere rápido) si falla un componente.

## 1. Activos críticos

| Activo | Impacto si cae |
|---|---|
| Gateway Nginx | Nadie entra a la tienda |
| auth-service | No hay login ni pedidos nuevos |
| catalogo-service + MariaDB Master | No se ven productos / no se descuenta stock |
| pedidos-service + Redis | No hay carrito ni checkout |
| pagos-service + Stripe | No se cobra (el pedido puede quedar pendiente) |
| security-audit-service | Se pierde evidencia (negocio puede seguir con cola en logs) |
| MariaDB Slave | Lecturas más lentas; hay failover al Master |
| Redis | Sin caché y sin blacklist (tokens); carrito cae |

## 2. RTO / RPO (metas del proyecto)

| Métrica | Meta | Cómo se logra |
|---|---|---|
| **RTO** (tiempo máximo de caída) | ≤ 1 hora | `docker compose up -d`, volúmenes persistentes, healthchecks |
| **RPO** (datos que se pueden perder) | ≤ 5 minutos | Binlog ROW Master→Slave; volúmenes Docker; auditoría en volumen aparte |

## 3. Continuidad (BCP) — qué hace el sistema solo

1. **Slave caído:** `Db::read()` hace failover al Master; el catálogo sigue.
2. **Redis caído:** caché se degrada a BD; la blacklist de JWT falla cerrada (no acepta tokens si no puede verificar revocación) → prioriza seguridad.
3. **Un microservicio caído:** el gateway responde 503 JSON; el resto de rutas siguen.
4. **Stripe caído:** el pedido queda `pendiente`; se puede reintentar el pago o usar modo demo en laboratorio.
5. **Auditoría caída:** `AuditClient` no bloquea la venta; escribe `[AUDIT-PENDIENTE]` en logs del contenedor.

## 4. Recuperación (DRP) — pasos manuales

### Escenario A: reinicio del host / Docker Desktop

```powershell
cd C:\xampp\htdocs\proyecto_web_2025\supermercado-distribuido
docker compose up -d
docker compose ps
```

Los volúmenes `master_data`, `slave_data`, `audit_data`, `redis_data` conservan datos.

### Escenario B: corrupción del Master

1. Detener escrituras: `docker compose stop pedidos-service catalogo-service auth-service pagos-service`
2. Promover/recuperar desde backup del volumen o clonar desde Slave (en producción: backup `mariadb-dump` diario).
3. En este proyecto académico: restaurar volumen o `docker compose down` **sin** `-v` y recrear solo el contenedor.

### Escenario C: pérdida total (solo si se usó `down -v`)

Hay que volver a sembrar: `docker compose up --build -d` y cargar catálogo de nuevo. **Nunca usar `-v` en producción.**

### Escenario D: filtración de secretos

1. Rotar todas las claves en `.env` (JWT, BD, Redis, Stripe).
2. `docker compose up -d --force-recreate`
3. Forzar logout masivo (cambiar `JWT_SECRET` invalida tokens viejos).

## 5. Copias de seguridad recomendadas (gratis)

```powershell
# Backup lógico del Master (ejemplo)
docker compose exec -T mariadb-master sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --all-databases' > backup_master.sql
docker compose exec -T mariadb-audit sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" audit_db' > backup_audit.sql
```

Guardar esos archivos en otra carpeta o OneDrive/Google Drive gratuito.

## 6. Contactos / roles (académico)

| Rol | Responsabilidad |
|---|---|
| Administrador del sistema (tú) | Levantar Docker, rotar `.env`, restaurar backups |
| Docente / evaluador | Validar evidencias BCP/DRP y capturas |
