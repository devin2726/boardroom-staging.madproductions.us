# The Boardroom (staging)

WordPress site for The Boardroom event space, deployed with Docker Compose (WordPress + MariaDB). The custom theme is `boardroom` (black and gold, EB Garamond). The custom plugin is `boardroom-booking`.

Guests **save a date on the website and pay by check in person**. The site does not take payment itself.

## Guest flow

1. Pick a full day or an hourly block on the calendar. Starts inside the minimum notice (default **48 hours**, America/Chicago) are unavailable.
2. Review the quote (time, cleaning fee, tax, total, and the check to bring).
3. Enter contact details, accept the cancellation policy, and press **Save this date**.
4. The date is **held** so nobody else can book it. The confirmation page and the guest email state:
   - the exact hold deadline in America/Chicago
   - the check amount, who it is payable to, and where / during which hours to bring it
   - the booking reference to write on the memo
   - that the hold is released if the check is not received by then
5. If a deposit is configured, the email also states the remaining balance and the date it is due.

## Staff

Bookings → All Bookings shows **Held (awaiting check)**, **Deposit paid**, **Paid in full**, **Cancelled**, and **Expired**.

- **Record check payment** stores the amount, check number, date received, and notes. A deposit and the later balance are two records. Each one emails the guest. The row shows paid and balance.
- **Mark paid in full** records the remaining balance and emails the guest.
- **Extend hold** pushes the deadline out (or puts an expired hold back if the date is still free).
- **Add booking** is for phone and walk-in requests. It has a source field. Staff can override the minimum notice; the public form cannot.
- Unpaid holds expire through WP-Cron and again on each page load. The date is released and the guest and the admin are emailed.

## Settings the admin must fill in

Bookings → Settings. Anything still marked `[VERIFY]` is shown to guests until it is replaced. Do not invent these; the owner has to supply them.

| Setting | Default | Notes |
|---|---|---|
| Check payable to | `[VERIFY] Payable to` | Name on the check |
| Where to bring the check | `[VERIFY] In-person location` | |
| In-person hours | `[VERIFY] In-person hours` | |
| Payment instructions | `[VERIFY] …` | Free text on the confirmation page and in email |
| Amount that secures the date | Full amount | Or a percent, or a fixed dollar amount |
| Balance due | 7 days before the event | `[VERIFY]` Used only when the first check is less than the total |
| Minimum notice (hours) | 48 | Booking must **start** at least this many hours from now. This is the “not inside 48 hours” rule. Change the number here if the rule changes. |
| Hold length (hours) | 48 | How long an unpaid saved date stays reserved |
| Day rate, hourly rate, cleaning fee, tax % | Placeholders | Replace with real figures |
| Admin notification email | WordPress admin email | Hold, check, and expiry mail |
| Cancellation policy | Placeholder | Shown before save and in email |
| Blackout dates | Whatever is already saved | **Upgrades do not wipe this list.** Emptying the box does not delete saved dates unless “Clear all blackout dates” is ticked. |

Full-day bookings start at 12:00 AM America/Chicago and block the whole day. Hourly bookings use the open/close hours. Both modes, the quote math, the calendar, and double-booking prevention stay in place.

## Deploy

Coolify deploys the **`main`** branch from `docker-compose.yml`. See [README-COOLIFY.md](README-COOLIFY.md). Redeploying syncs the theme and plugin. It does not replace the database volume, so bookings and blackout dates stay.

Staging stays out of search indexes while `DISCOURAGE_SEARCH=1` (the default).

Local test:

```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build
```

Then open http://localhost:8080.
