FROM mariadb:11.4
# Imported automatically only on first start (empty data volume)
COPY boardroom-db.sql /docker-entrypoint-initdb.d/10-boardroom.sql
