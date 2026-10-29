-- catalogo_db: categorías y productos del supermercado (sección 11 del enunciado)
SET NAMES utf8mb4;
USE catalogo_db;

CREATE TABLE IF NOT EXISTS categorias (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre  VARCHAR(60) NOT NULL,
    icono   VARCHAR(8) NOT NULL DEFAULT '🛒',
    activa  TINYINT(1) NOT NULL DEFAULT 1,
    orden   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uk_categorias_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS productos (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    categoria_id INT UNSIGNED NOT NULL,
    nombre       VARCHAR(120) NOT NULL,
    descripcion  VARCHAR(500) NULL,
    precio       DECIMAL(10,2) NOT NULL,
    unidad       ENUM('unidad', 'lb', 'kg', 'litro', 'paquete', 'docena', 'galon') NOT NULL DEFAULT 'unidad',
    stock        INT UNSIGNED NOT NULL DEFAULT 0,
    imagen_url   VARCHAR(255) NULL,
    destacado    TINYINT(1) NOT NULL DEFAULT 0,
    activo       TINYINT(1) NOT NULL DEFAULT 1,
    updated_by   INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_productos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias (id),
    CONSTRAINT ck_productos_precio CHECK (precio > 0),
    INDEX idx_productos_categoria (categoria_id, activo),
    INDEX idx_productos_nombre (nombre)
) ENGINE=InnoDB;

INSERT INTO categorias (id, nombre, icono, orden) VALUES
    (1, 'Frutas y Verduras',  '🥦', 1),
    (2, 'Lácteos y Huevos',   '🥛', 2),
    (3, 'Carnes y Embutidos', '🥩', 3),
    (4, 'Panadería',          '🍞', 4),
    (5, 'Abarrotes',          '🥫', 5),
    (6, 'Bebidas',            '🧃', 6),
    (7, 'Limpieza y Hogar',   '🧽', 7);

INSERT INTO productos (categoria_id, nombre, descripcion, precio, unidad, stock, destacado) VALUES
    (1, 'Banano',                      'Banano maduro nacional, ideal para licuados.',            3.50, 'lb',      200, 1),
    (1, 'Tomate',                      'Tomate fresco de cosecha local.',                          6.00, 'lb',      150, 0),
    (1, 'Aguacate Hass',               'Aguacate Hass en su punto.',                               8.50, 'unidad',  80,  1),
    (1, 'Cebolla blanca',              'Cebolla blanca mediana.',                                  5.00, 'lb',      120, 0),
    (1, 'Papa',                        'Papa lavada, ideal para sopas y frituras.',                4.75, 'lb',      180, 0),
    (2, 'Leche entera 1 L',            'Leche entera pasteurizada.',                              12.50, 'litro',   100, 1),
    (2, 'Huevos blancos',              'Cartón de 15 huevos frescos.',                            26.00, 'paquete', 60,  1),
    (2, 'Queso fresco',                'Queso fresco artesanal.',                                 28.00, 'lb',      40,  0),
    (2, 'Crema 500 ml',                'Crema pura de leche.',                                    17.50, 'unidad',  50,  0),
    (2, 'Yogur natural 1 L',           'Yogur natural sin azúcar añadida.',                       24.00, 'unidad',  35,  0),
    (3, 'Pechuga de pollo',            'Pechuga de pollo sin hueso.',                             24.00, 'lb',      70,  1),
    (3, 'Carne molida de res',         'Carne molida especial 90/10.',                            32.00, 'lb',      50,  0),
    (3, 'Chorizo tipo argentino',      'Paquete de 6 unidades.',                                  38.00, 'paquete', 30,  0),
    (3, 'Jamón de pavo',               'Jamón de pavo rebanado.',                                 29.00, 'lb',      25,  0),
    (4, 'Pan francés',                 'Bolsa de 10 panes recién horneados.',                     10.00, 'paquete', 90,  0),
    (4, 'Tortillas de maíz',           'Paquete de 30 tortillas.',                                12.00, 'paquete', 80,  1),
    (4, 'Pan de manteca',              'Pan dulce tradicional guatemalteco.',                      8.00, 'paquete', 45,  0),
    (5, 'Arroz blanco 2 lb',           'Arroz grano largo.',                                      11.50, 'unidad',  120, 0),
    (5, 'Frijol negro 2 lb',           'Frijol negro seleccionado.',                              16.00, 'unidad',  110, 1),
    (5, 'Aceite vegetal 1 L',          'Aceite vegetal para cocinar.',                            21.00, 'litro',   75,  0),
    (5, 'Azúcar 5 lb',                 'Azúcar blanca refinada.',                                 22.50, 'unidad',  90,  0),
    (5, 'Café molido de Antigua 400 g','Café 100 % guatemalteco, tostado medio.',                 45.00, 'unidad',  40,  1),
    (6, 'Agua pura 3 L',               'Agua purificada.',                                        11.00, 'unidad',  140, 0),
    (6, 'Jugo de naranja 1 L',         'Jugo de naranja natural.',                                18.50, 'litro',   55,  0),
    (7, 'Detergente en polvo 1 kg',    'Detergente para ropa blanca y de color.',                 27.00, 'kg',      60,  0),
    (7, 'Papel higiénico 4 rollos',    'Papel higiénico doble hoja.',                             19.50, 'paquete', 100, 0);
