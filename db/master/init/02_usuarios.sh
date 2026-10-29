#!/bin/bash
# Usuarios de BD con mínimo privilegio: cada servicio solo accede a su propia base
# y ninguno tiene DELETE, DROP ni ALTER. Estos usuarios se replican al Slave.

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<-EOSQL
    CREATE USER IF NOT EXISTS '${AUTH_DB_USER}'@'%' IDENTIFIED BY '${AUTH_DB_PASSWORD}';
    GRANT SELECT, INSERT, UPDATE ON auth_db.* TO '${AUTH_DB_USER}'@'%';

    CREATE USER IF NOT EXISTS '${CATALOGO_DB_USER}'@'%' IDENTIFIED BY '${CATALOGO_DB_PASSWORD}';
    GRANT SELECT, INSERT, UPDATE ON catalogo_db.* TO '${CATALOGO_DB_USER}'@'%';

    CREATE USER IF NOT EXISTS '${PEDIDOS_DB_USER}'@'%' IDENTIFIED BY '${PEDIDOS_DB_PASSWORD}';
    GRANT SELECT, INSERT, UPDATE ON pedidos_db.* TO '${PEDIDOS_DB_USER}'@'%';

    CREATE USER IF NOT EXISTS '${PAGOS_DB_USER}'@'%' IDENTIFIED BY '${PAGOS_DB_PASSWORD}';
    GRANT SELECT, INSERT, UPDATE ON pagos_db.* TO '${PAGOS_DB_USER}'@'%';

    FLUSH PRIVILEGES;
EOSQL
