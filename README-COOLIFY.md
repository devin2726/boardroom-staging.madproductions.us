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
7. **Bookings → Settings**: replace every `[VERIFY]` placeholder. Guests see that text on the confirmation page and in email until you do. Fill in the check payable-to name, where to bring the check, in-person hours, and the free-text payment instructions. Set the starting price and the typical-price sentence (defaults: from $600 per day, most events around $1,200). On each booking, set the agreed price before recording a check. Choose the check that secures the date (full amount of that price is the default, or a percent, or a fixed amount) and how many days before the event the balance is due. Confirm the admin notification email and the cancellation policy.
8. **Do not clear blackout dates.** They live in the database (Bookings → Settings), not in Git. Saving other settings keeps the list. The box under the list is the only way to delete every saved date. Plugin upgrades do not replace this option.
9. **Minimum notice and hold length** both default to 48 hours. Minimum notice means a booking must start at least that many hours from now (the rule the owner described as not renting inside 48 hours). Hold length is how long a saved, unpaid date stays reserved. Change either number under Bookings → Settings if the rule changes.
10. **SMTP**: In Coolify’s environment variables, set `BRB_SMTP_HOST` (`smtp.gmail.com`), `BRB_SMTP_PORT` (`587`), `BRB_SMTP_USER`, `BRB_SMTP_PASS`, `BRB_SMTP_FROM` (`boardroomqc@gmail.com`), and `BRB_SMTP_FROM_NAME` (`The Boardroom`). `docker-compose.yml` passes them into the wordpress service. SMTP (STARTTLS) turns on only when user and password are both set. The password is not stored in WordPress or in git. Bookings → Settings → **Send test email** sends to the admin notification email and shows the mail error on failure. Inquiry mail uses that From address and sets Reply-To to the guest.
11. **Card payments (optional)**: In Coolify’s environment variables, set `BRB_STRIPE_SECRET_KEY` and `BRB_STRIPE_WEBHOOK_SECRET`. `BRB_STRIPE_PUBLISHABLE_KEY` is passed through and is not used, because Checkout is a hosted Stripe page. Online payment stays off until the secret key is set, so the site can be deployed before the keys exist. Do not commit the keys. In the Stripe dashboard, add a webhook endpoint for the public site, for example `https://boardroom.madproductions.us/wp-json/brb/v1/stripe-webhook`, and subscribe to `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `charge.refunded`, `charge.dispute.created`, `charge.dispute.updated`, and `charge.dispute.closed`. Paste Stripe’s signing secret into `BRB_STRIPE_WEBHOOK_SECRET`. Bookings → Settings shows only whether Stripe is connected and whether the key is test or live. Refunds are issued by staff in the Stripe dashboard. The card processing fee defaults to 3% and is not part of the booking balance.
12. **Search engines**: staging stays hidden (`blog_public=0` is enforced on every start while `DISCOURAGE_SEARCH=1`). At launch set `DISCOURAGE_SEARCH=0`, redeploy, and untick Settings → Reading.
13. Test on the Book page: pick a date at least 48 hours out, save it, and confirm the guest sees “Pricing from …” and **price to be confirmed**, plus the hold deadline in America/Chicago, who the check is payable to, where and when to bring it, and the booking reference for the memo. In wp-admin, open the booking (status **Held (awaiting check)**), set the agreed price, record the deposit check, then record the balance. The status becomes **Paid in full** and the guest is emailed. A start inside the minimum notice is refused. Unpaid holds expire on their own (WP-Cron, and again on each page load) and the date opens up. With the Stripe secret key set, the price email and the confirmation page also offer Pay online.

## Changing domain later
Change the domain in Coolify and redeploy. The entrypoint search-replaces the old stored URL with the new one automatically.

## Updating code
Edit the theme/plugin in `wordpress/wp-content/…`, push, and redeploy. The theme and plugin are re-synced on every start. Media uploads and the database live in volumes and are kept.

This repo has no GitHub Action and no Coolify webhook. A push to `main` does not restart staging by itself. In Coolify, redeploy the resource so the image rebuilds and the container starts. The entrypoint then copies the theme and plugin into the volume. The start log should include `[boardroom] booking version`, and the Book page should load `brb.css` with that same `ver=`. Deleting `mariadb-data` is not part of a code update. It would wipe bookings, blackout dates, and pages.

## Backups
Back up the `mariadb-data` and `wordpress-files` volumes (Coolify → Storages / scheduled DB backups for the `db` service, using the root password from `SERVICE_PASSWORD_ROOT`).

## Notes
- Re-running the SQL import never happens once `mariadb-data` has data. Deleting that volume wipes bookings, blackout dates, and pages. Do not delete it to deploy this change.
- Payment is by check brought in person. Saving a date holds it (default 48 hours) so it cannot be double-booked. The website does not collect payment and does not show a guessed total. Staff set the agreed price, and until then the guest sees “price to be confirmed.”
- The theme/plugin file editor in wp-admin is disabled (`DISALLOW_FILE_EDIT`). Change code via Git.
- Local test: `cp .env.example .env && docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build` → http://localhost:8080
