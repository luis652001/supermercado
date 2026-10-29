-- Mercado Verde: productores, presentaciones, lotes FEFO, envases y seguimiento.
-- Idempotente: sirve en una instalación nueva y también si se aplica sobre datos ya creados.
SET NAMES utf8mb4;

USE catalogo_db;

CREATE TABLE IF NOT EXISTS productores (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(120) NOT NULL,
    region      VARCHAR(80) NOT NULL,
    descripcion VARCHAR(500) NOT NULL,
    impacto     VARCHAR(300) NULL,
    activo      TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

ALTER TABLE productos ADD COLUMN IF NOT EXISTS productor_id INT UNSIGNED NULL;
ALTER TABLE productos ADD COLUMN IF NOT EXISTS retornable TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE productos ADD COLUMN IF NOT EXISTS deposito_envase DECIMAL(10,2) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS presentaciones (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id INT UNSIGNED NOT NULL,
    nombre      VARCHAR(80) NOT NULL,
    factor      INT UNSIGNED NOT NULL,
    precio      DECIMAL(10,2) NOT NULL,
    activa      TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uk_presentacion (producto_id, nombre),
    INDEX idx_presentacion_producto (producto_id),
    CONSTRAINT ck_presentacion_factor CHECK (factor >= 1),
    CONSTRAINT ck_presentacion_precio CHECK (precio > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lotes (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id        INT UNSIGNED NOT NULL,
    codigo             VARCHAR(40) NOT NULL,
    cantidad           INT UNSIGNED NOT NULL,
    fecha_vencimiento  DATE NOT NULL,
    temperatura        DECIMAL(4,1) NULL,
    estado             ENUM('recibido', 'en_camara', 'listo', 'merma') NOT NULL DEFAULT 'en_camara',
    nota               VARCHAR(200) NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_lote_codigo (codigo),
    INDEX idx_lotes_vence (fecha_vencimiento, estado)
) ENGINE=InnoDB;

INSERT INTO productores (id, nombre, region, descripcion, impacto) VALUES
    (1, 'Huertos de Almolonga', 'Quetzaltenango',
     'Hortalizas cortadas de madrugada en el valle de Almolonga y enviadas el mismo día.',
     'La compra llega directo a familias productoras del altiplano.'),
    (2, 'Finca El Bosque', 'Antigua Guatemala',
     'Café de altura, tueste medio, de parcelas pequeñas en los alrededores de Antigua.',
     'Precio de origen publicado: el productor recibe la mayor parte del valor.'),
    (3, 'Granja Los Pinos', 'Chimaltenango',
     'Huevos y lácteos de una granja a menos de dos horas de la ciudad.',
     'Cadena corta: del corral a la cámara fría de Zona 10.')
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre), region = VALUES(region),
    descripcion = VALUES(descripcion), impacto = VALUES(impacto);

UPDATE productos SET productor_id = 1 WHERE nombre IN ('Banano', 'Tomate', 'Aguacate Hass', 'Cebolla blanca', 'Papa');
UPDATE productos SET productor_id = 2 WHERE nombre LIKE 'Café%';
UPDATE productos SET productor_id = 3 WHERE nombre IN ('Leche entera 1 L', 'Huevos blancos', 'Queso fresco', 'Crema 500 ml', 'Yogur natural 1 L');

INSERT INTO productos (categoria_id, nombre, descripcion, precio, unidad, stock, destacado, productor_id, retornable, deposito_envase)
SELECT 6, 'Agua pura en envase de vidrio', 'Botella retornable. Al devolverla se acredita el depósito en tu saldo.', 14.00, 'unidad', 80, 1, NULL, 1, 5.00
WHERE NOT EXISTS (SELECT 1 FROM productos WHERE nombre = 'Agua pura en envase de vidrio');

INSERT INTO productos (categoria_id, nombre, descripcion, precio, unidad, stock, destacado, productor_id, retornable, deposito_envase)
SELECT 1, 'Canasta de verdura de Almolonga', 'Mezcla de temporada en canasta retornable. El depósito se devuelve al entregarla.', 45.00, 'unidad', 25, 1, 1, 1, 15.00
WHERE NOT EXISTS (SELECT 1 FROM productos WHERE nombre = 'Canasta de verdura de Almolonga');

INSERT INTO presentaciones (producto_id, nombre, factor, precio)
SELECT p.id, 'Red de 5 lb', 5, 16.00 FROM productos p
WHERE p.nombre = 'Banano'
  AND NOT EXISTS (SELECT 1 FROM presentaciones x WHERE x.producto_id = p.id AND x.nombre = 'Red de 5 lb');

INSERT INTO presentaciones (producto_id, nombre, factor, precio)
SELECT p.id, 'Mano de 4', 4, 30.00 FROM productos p
WHERE p.nombre = 'Aguacate Hass'
  AND NOT EXISTS (SELECT 1 FROM presentaciones x WHERE x.producto_id = p.id AND x.nombre = 'Mano de 4');

INSERT INTO presentaciones (producto_id, nombre, factor, precio)
SELECT p.id, 'Dos cartones', 2, 48.00 FROM productos p
WHERE p.nombre = 'Huevos blancos'
  AND NOT EXISTS (SELECT 1 FROM presentaciones x WHERE x.producto_id = p.id AND x.nombre = 'Dos cartones');

INSERT INTO presentaciones (producto_id, nombre, factor, precio)
SELECT p.id, 'Dos bolsas', 2, 85.00 FROM productos p
WHERE p.nombre LIKE '%Antigua%'
  AND NOT EXISTS (SELECT 1 FROM presentaciones x WHERE x.producto_id = p.id AND x.nombre = 'Dos bolsas');

INSERT INTO lotes (producto_id, codigo, cantidad, fecha_vencimiento, temperatura, estado, nota)
SELECT p.id, 'LT-BAN-014', 80, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 12.0, 'en_camara', 'Cámara de fruta'
FROM productos p WHERE p.nombre = 'Banano'
  AND NOT EXISTS (SELECT 1 FROM lotes l WHERE l.codigo = 'LT-BAN-014');

INSERT INTO lotes (producto_id, codigo, cantidad, fecha_vencimiento, temperatura, estado, nota)
SELECT p.id, 'LT-TOM-001', 40, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 4.2, 'en_camara', 'Prioridad FEFO'
FROM productos p WHERE p.nombre = 'Tomate'
  AND NOT EXISTS (SELECT 1 FROM lotes l WHERE l.codigo = 'LT-TOM-001');

INSERT INTO lotes (producto_id, codigo, cantidad, fecha_vencimiento, temperatura, estado, nota)
SELECT p.id, 'LT-AGU-003', 20, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 8.0, 'listo', 'Listo para surtido'
FROM productos p WHERE p.nombre = 'Aguacate Hass'
  AND NOT EXISTS (SELECT 1 FROM lotes l WHERE l.codigo = 'LT-AGU-003');

INSERT INTO lotes (producto_id, codigo, cantidad, fecha_vencimiento, temperatura, estado, nota)
SELECT p.id, 'LT-LEC-009', 30, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 3.8, 'en_camara', 'Cadena de frío'
FROM productos p WHERE p.nombre = 'Leche entera 1 L'
  AND NOT EXISTS (SELECT 1 FROM lotes l WHERE l.codigo = 'LT-LEC-009');

USE pedidos_db;

ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS descuento_envases DECIMAL(10,2) NOT NULL DEFAULT 0;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS repartidor_nombre VARCHAR(80) NULL;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS lat DECIMAL(9,6) NULL;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS lng DECIMAL(9,6) NULL;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS calificacion TINYINT NULL;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS propina DECIMAL(10,2) NULL;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS comentario VARCHAR(300) NULL;
ALTER TABLE pedidos ADD COLUMN IF NOT EXISTS pase_envases CHAR(8) NULL;

ALTER TABLE detalle_pedidos ADD COLUMN IF NOT EXISTS factor SMALLINT UNSIGNED NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS envases_movimientos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_cliente  INT UNSIGNED NOT NULL,
    tipo        ENUM('acreditacion', 'uso', 'reverso') NOT NULL,
    envases     INT NOT NULL DEFAULT 0,
    monto       DECIMAL(10,2) NOT NULL,
    pedido_uuid CHAR(36) NULL,
    nota        VARCHAR(160) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_envases_cliente (id_cliente),
    INDEX idx_envases_pedido (pedido_uuid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mensajes_pedido (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id  INT UNSIGNED NOT NULL,
    autor      ENUM('cliente', 'equipo') NOT NULL,
    texto      VARCHAR(300) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mensajes_pedido (pedido_id),
    CONSTRAINT fk_mensajes_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id) ON DELETE CASCADE
) ENGINE=InnoDB;
