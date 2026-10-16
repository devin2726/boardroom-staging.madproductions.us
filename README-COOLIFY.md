# The Boardroom: Coolify deploy (WordPress + MariaDB)

## What's in here
| Path | Purpose |
|---|---|
| `docker-compose.yml` | Coolify stack: `wordpress` (built from `wordpress/Dockerfile`) + `db` (built from `db/Dockerfile`). Named volumes `wordpress-files`, `mariadb-data`. Healthchecks on both. |
| `wordpress/Dockerfile` | `wordpress:php8.3-apache` + wp-cli + the Boardroom theme, `boardroom-booking` plugin and bundled uploads. |
| `wordpress/boardroom-entrypoint.sh` | Wraps the official entrypoint. On every start it syncs the theme and plugin into the volume, copies uploads without overwriting, then rewrites the stored site URL to `$WP_HOME` (idempotent: does nothing if already correct). It also optionally sets the admin password and sets "discourage search engines". |
| `db/Dockerfile` + `db/boardroom-db.sql` | `mariadb:11.4`. The SQL lives in `/docker-entrypoint-initdb.d/` and is imported **only on first start** (empty volume). |
| `docker-compose.local.yml` | Local testing only (publishes port 8080). Do **not** use it in Coolify. |
| `.env.example` | Variables for local runs. Coolify generates the `SERVICE_*` values itself. |

Coolify magic variables used: `SERVICE_FQDN_WORDPRESS_80` (domain → container port 80, also feeds `WP_HOME`), `SERVICE_USER_MYSQL`, `SERVICE_PASSWORD_MYSQL`, `SERVICE_PASSWORD_ROOT`. If the domain has no scheme, `https://` is assumed. The proxy's `X-Forwarded-Proto: https` is honored, so there's no redirect loop.

## Deploy steps (Coolify v4)
1. Push this folder to a Git repo (root = this folder), or have it ready to upload.
2. Coolify → Project → **+ New Resource** → **Docker Compose** (from Git: "Public/Private Repository" → Build Pack **Docker Compose**, compose file `/docker-compose.yml`. Or "Docker Compose Empty" and paste the file. Building requires the repo, so Git is preferred).
3. In the resource, open the **wordpress** service → **Domains**: set e.g. `https://boardroom.madproductions.us`. The DNS A record for `boardroom` must point to the Coolify server first; Coolify/Traefik issues the Let's Encrypt cert.
4. (Optional) Environment Variables: set `WP_ADMIN_PASSWORD` (applied once, on first boot) and `WP_ADMIN_EMAIL`. Leave `DISCOURAGE_SEARCH=1` for staging.
5. **Deploy**. The first boot imports the DB, then the log shows `[boardroom] rewriting URLs: http://localhost:8088 -> https://boardroom.madproductions.us` and `[boardroom] init done`. Both containers should turn **healthy**.
6. Open `https://<domain>/wp-admin` and log in as **admin**. The password is `boardroom-local` unless you set `WP_ADMIN_PASSWORD`. **Change it right away**: Users → Profile, and set your real email.
7. **Bookings → Settings**: enter real rates, cleaning fee, tax %, deposit %, hours, admin email, cancellation policy and blackout dates, plus the Stripe **test** keys `pk_test_…` / `sk_test_…`.
8. **Stripe Dashboard (Test mode) → Developers → Webhooks → Add endpoint**: `https://<domain>/wp-json/brb/v1/stripe-webhook`, events `checkout.session.completed` and `checkout.session.expired`. Copy the signing secret `whsec_…` into Bookings → Settings.
9. **SMTP**: Plugins → Add New → "WP Mail SMTP" (or FluentSMTP). Configure the mail provider (Google Workspace/Postmark/SES/GoDaddy SMTP) so confirmations get delivered. The container has no local mail server.
10. **Search engines**: staging stays hidden (`blog_public=0` is enforced on every start while `DISCOURAGE_SEARCH=1`). At launch set `DISCOURAGE_SEARCH=0`, redeploy, and untick Settings → Reading.
11. Test a booking with card `4242 4242 4242 4242`. It should show as Confirmed under Bookings → All Bookings.

## Changing domain later
Change the domain in Coolify and redeploy. The entrypoint search-replaces the old stored URL with the new one automatically.

## Updating code
Edit the theme/plugin in `wordpress/wp-content/…`, push, and redeploy. The theme and plugin are re-synced on every start. Media uploads and the database live in volumes and are kept.

## Backups
Back up the `mariadb-data` and `wordpress-files` volumes (Coolify → Storages / scheduled DB backups for the `db` service, using the root password from `SERVICE_PASSWORD_ROOT`).

## Notes
- Re-running the SQL import never happens once `mariadb-data` has data. To start fresh, delete the volume.
- The theme/plugin file editor in wp-admin is disabled (`DISALLOW_FILE_EDIT`). Change code via Git.
- Local test: `cp .env.example .env && docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build` → http://localhost:8080
