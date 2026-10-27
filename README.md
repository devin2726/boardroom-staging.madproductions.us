# The Boardroom (staging)

WordPress site for The Boardroom event space, deployed with Docker Compose (WordPress + MariaDB). The custom theme is `boardroom` (black and gold, EB Garamond). The custom plugin is `boardroom-booking`.

Guests **save a date on the website and mail or drop off a check**. The site does not take payment itself. The venue is 2220 37th Street, Moline, IL 61265. Checks go to 2212 37th Street, Moline, IL 61265.

Contact is one form on `/contact/`: name, email, message, an optional “What’s this about?” choice, and an optional preferred date or time. It does not hold a date. Bookings → Inquiries lists those messages. The notification subject is “New contact inquiry”. The Contact page also shows the venue address, `boardroomqc@gmail.com`, a Google Map that loads on its own, and the directions link. The privacy page says this site sets no advertising, analytics, or tracking cookies of its own, and that the embedded map may set cookies from Google. There is no cookie banner.

## Guest flow

1. Pick a full day or a shorter block on the calendar. Starts inside the minimum notice (default **48 hours**, America/Chicago) are unavailable.
2. Review the date. The page shows **Pricing from $600 per day (most events around $1,200), final price confirmed based on date, guest count and features.** The starting amount and the parenthetical text are settings. Until staff set a price, the guest sees **price to be confirmed**. There is no calculated cleaning fee, tax, or total on the website.
3. Enter contact details, choose an event type (Wedding, Birthday, Corporate, Fundraiser, or Other), agree to the Terms, the Rental Agreement, and the Privacy Policy, and press **Save this date**.
4. The date is **held** so nobody else can book it. The confirmation page and the guest email state:
   - that the date is held for 48 hours (or the hold length in settings), and the exact deadline time in America/Chicago
   - that the price is to be confirmed, or the check amount once staff have set it
   - who the check is payable to, and where to mail or drop it off
   - the booking reference to write on the memo
   - that the hold is released if the check is not received by then
5. A shorter block still reserves only part of the day (availability, notice, and double-booking work as before). The owner prices the event, not the hour, so both choices use the same “from” sentence. There is no public hourly rate.
6. After staff set the agreed price, the guest is emailed the check amount. If a deposit is configured, that email also states the remaining balance and the date it is due.

## Staff

Bookings → All Bookings shows **Held (awaiting check)**, **Deposit paid**, **Paid in full**, **Cancelled**, and **Expired**.

- **Set agreed price** stores the final price for that booking and emails the guest the check amount. The hold and recorded checks use this price. Recording a check or marking paid is refused until a price is set.
- **Record payment** stores the amount, method (Check or Cash), date received, and notes. The check number is optional and is kept only for a check. Cash is an in-person staff record; the public pages still tell guests to mail or drop off a check. A deposit and the later balance are two records. Each one emails the guest, and that receipt names the method. Paid and balance use the same totals either way.
- **Delete booking** is available only after a booking is Cancelled. It asks for confirmation, checks the staff nonce and capability, and removes that booking’s payment rows.
- **Mark paid in full** records the remaining balance and emails the guest.
- **Extend hold** pushes the deadline out (or puts an expired hold back if the date is still free).
- **Add booking** is for phone and walk-in requests. It has a source field and an agreed-price field (leave the price blank to hold the date as price to be confirmed). Staff can override the minimum notice; the public form cannot.
- Unpaid holds expire through WP-Cron and again on each page load. The date is released and the guest and the admin are emailed.

## Settings the admin must fill in

Bookings → Settings. Anything still marked `[VERIFY]` is shown to guests until it is replaced. Do not invent these; the owner has to supply them.

| Setting | Default | Notes |
|---|---|---|
| Check payable to | `[VERIFY] Name on the check` | Name on the check |
| Venue address | 2220 37th Street, Moline, IL 61265 | The event space. Footer, Contact, Terms, and Privacy. A blank value, a placeholder, or the check address saved here by mistake is replaced on upgrade. |
| Map | https://maps.app.goo.gl/nVqUsGTcy48nPzc18 | Contact embeds a Google Map for the Boardroom (41.4913116, -90.4820481) with `loading="lazy"`. The same short link is the “Get directions / View on Google Maps” fallback on Contact and in the footer. Schema geo uses those coordinates. |
| Where to mail or drop off checks | 2212 37th Street, Moline, IL 61265 | Checks only. A saved placeholder, or the old 2212 47 Ave line, is replaced on upgrade. |
| In-person hours | `[VERIFY] In-person hours` | |
| Payment instructions | blank | Optional extra text on the review step, confirmation page, and hold email. The old “[VERIFY] Extra instructions for bringing the check.” placeholder is cleared on upgrade and the line stays hidden while this is blank. |
| Starting price | $600 per day | Shown to guests as “from” this amount. Whole dollars display without cents. |
| Typical price text | most events around $1,200 | Shown in parentheses. Edit the words here. |
| Amount that secures the date | Full amount of the agreed price | Or a percent, or a fixed dollar amount. Applied only after staff set the price. |
| Balance due | 7 days before the event | `[VERIFY]` Used only when the first check is less than the agreed price |
| Minimum notice (hours) | 48 | Booking must **start** at least this many hours from now. This is the “not inside 48 hours” rule. Change the number here if the rule changes. |
| Hold length (hours) | 48 | How long an unpaid saved date stays reserved |
| Admin notification email | WordPress admin email | Hold, price, check, expiry, and contact inquiry mail. Separate extra addresses with commas. |
| Inquiry email | boardroomqc@gmail.com | Contact inquiries are emailed here as well. A blank or invalid saved value is replaced on upgrade. |
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
