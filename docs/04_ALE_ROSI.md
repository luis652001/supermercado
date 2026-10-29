# Presupuesto de riesgo — ALE y ROSI (SuperAhorro GT)

Cálculos académicos con cifras ilustrativas en quetzales. Sirven para justificar las controles de seguridad del proyecto.

## Fórmulas

- **SLE** (Single Loss Expectancy) = Valor del activo × Factor de exposición  
- **ALE** (Annualized Loss Expectancy) = SLE × ARO (veces al año)  
- **ROSI** (Return on Security Investment) = (ALE_antes − ALE_despues − Costo_control) / Costo_control  

## Escenarios

### 1. Robo de tarjetas (si se guardaran en la BD)

| Concepto | Valor |
|---|---|
| Activo | Base de datos de clientes / reputación ≈ Q150,000 |
| Exposición | 80% (filtración completa) |
| SLE | 150,000 × 0.8 = **Q120,000** |
| ARO sin control | 1 (una filtración plausible/año en un e-commerce mal diseñado) |
| ALE sin control | **Q120,000** |

**Control:** nunca almacenar PAN/CVV; Stripe Checkout (PCI-DSS). Costo ≈ Q0 (plan test/gratis académico).

| Después | |
|---|---|
| ARO | 0.05 (riesgo residual operativo) |
| ALE | 120,000 × 0.05 = **Q6,000** |
| ROSI | (120,000 − 6,000 − 0) / 1 ≈ **infinito / muy alto** → control obligatorio |

### 2. Fuerza bruta al login de admin

| Concepto | Valor |
|---|---|
| SLE | Compromiso del panel ≈ Q40,000 (cambios de precio + fuga de clientes) |
| ARO sin control | 12 (intentos exitosos/año si no hay bloqueo) |
| ALE | 40,000 × 12 = **Q480,000** |

**Controles:** bcrypt, bloqueo 5 intentos / 15 min, rate limit Nginx, JWT firmado. Costo ≈ Q500/año (tiempo de implementación prorrateado).

| Después | |
|---|---|
| ARO | 0.2 |
| ALE | 40,000 × 0.2 = **Q8,000** |
| ROSI | (480,000 − 8,000 − 500) / 500 ≈ **943 (94,300%)** |

### 3. Manipulación de precios en el carrito

| Concepto | Valor |
|---|---|
| SLE | Pedidos fraudulentos en un mes ≈ Q8,000 |
| ARO sin control | 24 |
| ALE | **Q192,000** |

**Control:** precios solo desde catalogo-service; rechazo FRAUDE auditado. Costo ≈ Q300.

| Después | |
|---|---|
| ALE | ≈ **Q4,000** |
| ROSI | (192,000 − 4,000 − 300) / 300 ≈ **626** |

### 4. Caída prolongada de la tienda

| Concepto | Valor |
|---|---|
| SLE | 1 día sin ventas ≈ Q5,000 |
| ARO sin HA | 6 |
| ALE | **Q30,000** |

**Controles:** Master-Slave + failover, healthchecks, BCP/DRP. Costo ≈ Q1,200 (tiempo + hosting free tier).

| Después | |
|---|---|
| ALE | ≈ **Q5,000** |
| ROSI | (30,000 − 5,000 − 1,200) / 1,200 ≈ **19.8 (1,980%)** |

## Resumen ejecutivo

| Amenaza | ALE antes | ALE después | ROSI |
|---|---|---|---|
| Filtración de tarjetas | Q120,000 | Q6,000 | Muy alto |
| Fuerza bruta admin | Q480,000 | Q8,000 | ~943 |
| Fraude de precios | Q192,000 | Q4,000 | ~626 |
| Indisponibilidad | Q30,000 | Q5,000 | ~20 |

**Conclusión:** los controles implementados (Stripe sin PAN, JWT Zero Trust, auditoría append-only, réplica, rate limiting) reducen el ALE anual de forma clara con costo casi nulo en el entorno académico (capas gratuitas).
