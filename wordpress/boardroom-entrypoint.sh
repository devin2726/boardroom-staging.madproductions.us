#!/bin/bash
# Wraps the official WordPress entrypoint:
#  1) syncs the Boardroom theme/plugin (always) and bundled uploads (no overwrite) into the volume
#  2) after WP files + DB are ready, rewrites the site URL from whatever is stored to $WP_HOME (idempotent)
set -e
WP_DIR=/var/www/html
post_start() {
  for i in $(seq 1 90); do [ -f "$WP_DIR/wp-config.php" ] && [ -d "$WP_DIR/wp-content" ] && break; sleep 2; done
  mkdir -p "$WP_DIR/wp-content/themes" "$WP_DIR/wp-content/plugins" "$WP_DIR/wp-content/uploads"
  rm -rf "$WP_DIR/wp-content/themes/boardroom" "$WP_DIR/wp-content/plugins/boardroom-booking"
  cp -a /usr/src/boardroom/wp-content/themes/boardroom "$WP_DIR/wp-content/themes/"
  cp -a /usr/src/boardroom/wp-content/plugins/boardroom-booking "$WP_DIR/wp-content/plugins/"
  [ -d /usr/src/boardroom/wp-content/uploads ] && cp -an /usr/src/boardroom/wp-content/uploads/. "$WP_DIR/wp-content/uploads/"
  chown -R www-data:www-data "$WP_DIR/wp-content"
  WP="wp --path=$WP_DIR --allow-root --skip-themes --skip-plugins"
  for i in $(seq 1 90); do $WP db check >/dev/null 2>&1 && $WP core is-installed 2>/dev/null && break; sleep 2; done
  if [ -n "$WP_HOME" ]; then
    NEW="${WP_HOME%/}"; case "$NEW" in http://*|https://*) ;; *) NEW="https://$NEW";; esac
    OLD=$($WP db query "SELECT option_value FROM ${WORDPRESS_TABLE_PREFIX:-wp_}options WHERE option_name='siteurl'" --skip-column-names 2>/dev/null | tr -d '\r')
    OLD="${OLD%/}"
    if [ -n "$OLD" ] && [ "$OLD" != "$NEW" ]; then
      echo "[boardroom] rewriting URLs: $OLD -> $NEW"
      $WP search-replace "$OLD" "$NEW" --all-tables --skip-columns=guid --precise --quiet
      # also catch scheme-less/escaped variants of the old host
      OLDHOST="${OLD#*://}"; NEWHOST="${NEW#*://}"
      $WP search-replace "//$OLDHOST" "//$NEWHOST" --all-tables --skip-columns=guid --quiet || true
      $WP cache flush >/dev/null 2>&1 || true
    else
      echo "[boardroom] site URL already $NEW"
    fi
  fi
  if [ -n "$WP_ADMIN_PASSWORD" ] && [ ! -f "$WP_DIR/wp-content/.boardroom-admin-pw-set" ]; then
    $WP user update admin --user_pass="$WP_ADMIN_PASSWORD" --skip-email >/dev/null && touch "$WP_DIR/wp-content/.boardroom-admin-pw-set" && echo "[boardroom] admin password set from WP_ADMIN_PASSWORD"
  fi
  if [ -n "$WP_ADMIN_EMAIL" ]; then $WP user update admin --user_email="$WP_ADMIN_EMAIL" --skip-email >/dev/null || true; fi
  [ "${DISCOURAGE_SEARCH:-1}" = "1" ] && $WP option update blog_public 0 >/dev/null
  $WP rewrite flush --hard >/dev/null 2>&1 || true
  echo "[boardroom] init done"
}
if [ "$1" = "apache2-foreground" ]; then post_start & fi
exec docker-entrypoint.sh "$@"
