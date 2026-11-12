FROM wordpress:php8.3-apache
RUN curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
 && chmod +x /usr/local/bin/wp \
 && apt-get update && apt-get install -y --no-install-recommends mariadb-client less && rm -rf /var/lib/apt/lists/*
# Boardroom code + bundled uploads (synced into the volume on every start by boardroom-entrypoint.sh)
COPY wp-content/ /usr/src/boardroom/wp-content/
COPY boardroom-entrypoint.sh /usr/local/bin/boardroom-entrypoint.sh
# Pinned Yoast SEO. The entrypoint copies this into the volume on every start and activates it.
ARG YOAST_VERSION=28.8
RUN curl -fsSL -o /tmp/wordpress-seo.zip "https://downloads.wordpress.org/plugin/wordpress-seo.${YOAST_VERSION}.zip" \
 && php -r '$zip=new ZipArchive(); $ok=$zip->open("/tmp/wordpress-seo.zip"); if($ok!==true){fwrite(STDERR,"yoast zip\\n"); exit(1);} $zip->extractTo("/usr/src/boardroom/wp-content/plugins"); $zip->close();' \
 && rm -f /tmp/wordpress-seo.zip \
 && test -f /usr/src/boardroom/wp-content/plugins/wordpress-seo/wp-seo.php
RUN chmod +x /usr/local/bin/boardroom-entrypoint.sh
ENTRYPOINT ["boardroom-entrypoint.sh"]
CMD ["apache2-foreground"]
