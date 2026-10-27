# The Boardroom (staging)

WordPress site for The Boardroom event space, deployed with Docker Compose (WordPress + MariaDB). The custom theme is `boardroom` (black and gold, EB Garamond). The custom plugin is `boardroom-booking`.

Guests **save a date on the website and pay by check in person**. The site does not take payment itself.

## Guest flow

1. Pick a full day or a shorter block on the calendar. Starts inside the minimum notice (default **48 hours**, America/Chicago) are unavailable.
2. Review the date. The page shows **Pricing from $600 per day (most events around $1,200), final price confirmed based on date, guest count and features.** The starting amount and the parenthetical text are settings. Until staff set a price, the guest sees **price to be confirmed**. There is no calculated cleaning fee, tax, or total on the website.
3. Enter contact details, agree to the Terms and Privacy Policy, and press **Save this date**.
4. The date is **held** so nobody else can book it. The confirmation page and the guest email state:
   - the exact hold deadline in America/Chicago
   - that the price is to be confirmed, or the check amount once staff have set it
   - who the check is payable to, and where / during which hours to bring it
   - the booking reference to write on the memo
   - that the hold is released if the check is not received by then
5. A shorter block still reserves only part of the day (availability, notice, and double-booking work as before). The owner prices the event, not the hour, so both choices use the same “from” sentence. There is no public hourly rate.
6. After staff set the agreed price, the guest is emailed the check amount. If a deposit is configured, that email also states the remaining balance and the date it is due.

## Staff

Bookings → All Bookings shows **Held (awaiting check)**, **Deposit paid**, **Paid in full**, **Cancelled**, and **Expired**.

- **Set agreed price** stores the final price for that booking and emails the guest the check amount. The hold and recorded checks use this price. Recording a check or marking paid is refused until a price is set.
- **Record check payment** stores the amount, check number, date received, and notes. A deposit and the later balance are two records. Each one emails the guest. The row shows paid and balance.
- **Mark paid in full** records the remaining balance and emails the guest.
- **Extend hold** pushes the deadline out (or puts an expired hold back if the date is still free).
- **Add booking** is for phone and walk-in requests. It has a source field and an agreed-price field (leave the price blank to hold the date as price to be confirmed). Staff can override the minimum notice; the public form cannot.
- Unpaid holds expire through WP-Cron and again on each page load. The date is released and the guest and the admin are emailed.

## Settings the admin must fill in

Bookings → Settings. Anything still marked `[VERIFY]` is shown to guests until it is replaced. Do not invent these; the owner has to supply them.

| Setting | Default | Notes |
|---|---|---|
| Check payable to | `[VERIFY] Name on the check` | Name on the check |
| Where to bring the check | 2212 37th Street, Moline, IL 61265 | Venue and check drop-off. A saved placeholder, or the old 2212 47 Ave line, is replaced on upgrade. |
| In-person hours | `[VERIFY] In-person hours` | |
| Payment instructions | `[VERIFY] …` | Free text on the confirmation page and in email |
| Starting price | $600 per day | Shown to guests as “from” this amount. Whole dollars display without cents. |
| Typical price text | most events around $1,200 | Shown in parentheses. Edit the words here. |
| Amount that secures the date | Full amount of the agreed price | Or a percent, or a fixed dollar amount. Applied only after staff set the price. |
| Balance due | 7 days before the event | `[VERIFY]` Used only when the first check is less than the agreed price |
| Minimum notice (hours) | 48 | Booking must **start** at least this many hours from now. This is the “not inside 48 hours” rule. Change the number here if the rule changes. |
| Hold length (hours) | 48 | How long an unpaid saved date stays reserved |
| Admin notification email | WordPress admin email | Hold, price, check, expiry, and tour request mail. Separate extra addresses with commas. |
| Tour request email | boardroomqc@gmail.com | Tour requests are emailed here as well. A blank or invalid saved value is replaced on upgrade. |
| Cancellation policy | Summary of the signed rental agreement | Shown before save and in email. A saved placeholder is replaced on upgrade. The Rental Terms, Privacy Policy, and printable Rental Agreement pages are the guest-facing text. |
| Blackout dates | Whatever is already saved | **Upgrades do not wipe this list.** Emptying the box does not delete saved dates unless “Clear all blackout dates” is ticked. |

Full-day bookings start at 12:00 AM America/Chicago and block the whole day. Shorter blocks use the open/close hours. Both modes, the calendar, blackout dates, and double-booking prevention stay in place. Older day-rate, hourly-rate, cleaning-fee, and tax figures remain in the saved settings and are not shown to guests. Plugin upgrades do not delete them.

## Deploy

Coolify deploys the **`main`** branch from `docker-compose.yml`. See [README-COOLIFY.md](README-COOLIFY.md). Redeploying syncs the theme and plugin. It does not replace the database volume, so bookings and blackout dates stay.

Staging stays out of search indexes while `DISCOURAGE_SEARCH=1` (the default).

Local test:

```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build
```

Then open http://localhost:8080.
