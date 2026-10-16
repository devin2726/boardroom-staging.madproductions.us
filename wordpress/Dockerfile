FROM wordpress:php8.3-apache
RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
 && chmod +x /usr/local/bin/wp \
 && apt-get update && apt-get install -y --no-install-recommends mariadb-client less && rm -rf /var/lib/apt/lists/*
# Boardroom code + bundled uploads (synced into the volume on every start by boardroom-entrypoint.sh)
COPY wp-content/ /usr/src/boardroom/wp-content/
COPY boardroom-entrypoint.sh /usr/local/bin/boardroom-entrypoint.sh
RUN chmod +x /usr/local/bin/boardroom-entrypoint.sh
ENTRYPOINT ["boardroom-entrypoint.sh"]
CMD ["apache2-foreground"]
