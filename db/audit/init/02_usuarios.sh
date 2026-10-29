#!/bin/bash
# El usuario del security-audit-service solo puede insertar y leer: nunca UPDATE ni DELETE.

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" <<-EOSQL
    CREATE USER IF NOT EXISTS '${AUDIT_DB_USER}'@'%' IDENTIFIED BY '${AUDIT_DB_PASSWORD}';
    GRANT SELECT, INSERT ON audit_db.security_audit_logs TO '${AUDIT_DB_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL
