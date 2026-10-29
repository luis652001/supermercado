FROM mariadb:10.6

COPY init/ /docker-entrypoint-initdb.d/
# Los scripts editados en Windows pueden traer CRLF y romper el shebang de bash.
RUN sed -i 's/\r$//' /docker-entrypoint-initdb.d/* \
    && chmod 644 /docker-entrypoint-initdb.d/*
