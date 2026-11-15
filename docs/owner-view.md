# Owner bookings view: `[brb_owner_view]` (plugin 2.5.78)

A read-only, phone-first bookings calendar for the owner. Planned page: `/admin-calendar/` (created later in wp-admin, not by this PR).

## What it shows
- A month calendar, current month by default. Prev/next arrows (and a left/right swipe on a phone) move a month at a time, from 3 months back to 24 months ahead (`brb_ov_months_back` / `brb_ov_months_ahead` filters).
- Each day is **Booked**, **Held**, **Blocked** or **Free**:
  - Booked: an approved, deposit-paid or paid booking (the plugin's `brb_blocking_statuses()`), or a "Reserved" calendar hold.
  - Held: a "Hold, unpaid" calendar hold.
  - Blocked: a blackout date or a "Blocked" calendar hold (`brb_date_status()`, the same block data the admin calendar uses).
  - Free: none of the above. A blue dot means a request is in review (it does not reserve the date).
- Tapping a day jumps to that day's cards: time, status, name, phone (tel: link), email (mailto: link), event type, guest count, planned end time, ref, "About the event" and booking notes.
- An upcoming list (today onward) below the calendar.
- Never shown: cancelled, declined, expired or spam requests; deleted bookings; prices or payment amounts; any calendar hold's name or notes (holds show only Held, Reserved or Date block).

## On the page
No "Protected: " prefix on the title, and no Ask Walter chat (the plugin skips the chat assets and bubble; the theme footer button is hidden). Past days are dimmed.

## Read-only
SELECT queries only. No forms, buttons or wp-admin links. The only links are day anchors, tel:, mailto: and `?brb_ov_m=YYYY-MM` month navigation. Mail is blocked while it renders.

## Privacy
- Renders nothing unless the page has a post password and the visitor entered it. On a page with no password it renders nothing (admins see a one-line note).
- Never renders in REST, feeds, search results, embeds or wp-admin.
- Pages containing the shortcode: `noindex, nofollow` (core `wp_robots`, Yoast), `X-Robots-Tag`, `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`; excluded from the core and Yoast sitemaps, site and REST search, feeds, oEmbed, and automatic page lists (`wp_list_pages`, `get_pages`). REST `content.rendered` and `excerpt.rendered` are always empty, even with `?password=`.

## Setting up the page (later, after QA and Max's go-ahead)
1. Pages → Add New. Title "Admin calendar", slug `admin-calendar`, content `[brb_owner_view]`.
2. Visibility → Password protected, using the password Max chose.
3. Yoast: Advanced → "Allow search engines to show this page?" → No (belt and braces; the plugin already forces noindex).
4. **Menus:** do not add the page to any menu (Appearance → Menus / Site Editor navigation). Automatic page lists already skip it, but a hand-built menu item would still show the link (never the data).
