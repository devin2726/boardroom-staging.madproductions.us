<?php
/**
 * [brb_owner_view]: a read-only bookings calendar for the owner.
 *
 * Meant for one password-protected page (planned slug: /admin-calendar/).
 * It shows a month calendar (booked, held, blocked or free per day), that
 * day's bookings and calendar holds, and a short upcoming list.
 *
 * Read-only by design:
 * - It runs SELECT queries only. It links to nothing in wp-admin, has no
 *   forms, and never calls the approve, decline, cancel, payment or hold code.
 * - The only links are month navigation (?brb_ov_m=YYYY-MM), in-page day
 *   anchors, tel: and mailto:.
 * - Mail is blocked while it renders.
 *
 * Privacy:
 * - It renders nothing unless the page has a post password and the visitor
 *   has entered it, and never in REST, feeds, search, embeds or the admin.
 * - Any page containing the shortcode is noindex/nofollow, Cache-Control
 *   no-store/private, kept out of the core and Yoast sitemaps, site search,
 *   feeds, oEmbed and automatic page lists. Menus: never add the page to a
 *   menu (see docs/owner-view.md).
 * - No prices or payment amounts are shown. Calendar holds show only their
 *   status (Held, Reserved, Date block), never their name or notes.
 * - On the page: no "Protected: " title prefix and no Ask Walter chat.
 */
if (!defined('ABSPATH')) exit;

define('BRB_OV_TAG', 'brb_owner_view');

function brb_ov_closed_statuses() {
    return ['cancelled', 'declined', 'expired', 'spam'];
}
/** IDs of posts/pages whose content contains the shortcode. */
function brb_ov_post_ids($refresh = false) {
    static $ids = null;
    if ($ids !== null && !$refresh) return $ids;
    global $wpdb;
    $like = '%' . $wpdb->esc_like('[' . BRB_OV_TAG) . '%';
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type NOT IN ('revision','nav_menu_item') AND post_status NOT IN ('trash','auto-draft')",
        $like
    ));
    $ids = array_values(array_filter(array_map('intval', (array) $rows)));
    return $ids;
}
function brb_ov_is_owner_post($post) {
    $post = get_post($post);
    if (!$post) return false;
    return has_shortcode((string) $post->post_content, BRB_OV_TAG) || in_array((int) $post->ID, brb_ov_post_ids(), true);
}
function brb_ov_context_blocked() {
    if (is_admin() && !wp_doing_ajax()) return true;
    if (defined('REST_REQUEST') && REST_REQUEST) return true;
    if (function_exists('wp_is_json_request') && wp_is_json_request()) return true;
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return true;
    if (is_feed() || is_search() || is_embed()) return true;
    return false;
}

/* ---------- data (SELECT only) ---------- */

function brb_ov_month_param() {
    $now = brb_now();
    $raw = isset($_GET['brb_ov_m']) ? sanitize_text_field(wp_unslash((string) $_GET['brb_ov_m'])) : '';
    $min = $now->modify('first day of this month')->modify('-' . brb_ov_months_back() . ' months')->format('Y-m');
    $max = $now->modify('first day of this month')->modify('+' . brb_ov_months_ahead() . ' months')->format('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) return $now->format('Y-m');
    if ($raw < $min) return $min;
    if ($raw > $max) return $max;
    return $raw;
}
function brb_ov_months_back() { return (int) apply_filters('brb_ov_months_back', 3); }
function brb_ov_months_ahead() { return (int) apply_filters('brb_ov_months_ahead', 24); }

function brb_ov_bookings_between($from, $to) {
    global $wpdb;
    $closed = "'" . implode("','", brb_ov_closed_statuses()) . "'";
    return (array) $wpdb->get_results($wpdb->prepare(
        'SELECT id, mode, start_dt, end_dt, guests, name, email, phone, notes, status, event_type, end_time, event_about FROM ' . brb_table() . " WHERE start_dt < %s AND end_dt > %s AND status NOT IN ($closed) ORDER BY start_dt ASC, id ASC LIMIT 500",
        $to,
        $from
    ));
}
function brb_ov_booking_on_day($b, $ymd) {
    $day = $ymd . ' 00:00:00';
    $next = (new DateTimeImmutable($day, brb_tz()))->modify('+1 day')->format('Y-m-d H:i:s');
    return $b->start_dt < $next && $b->end_dt > $day;
}
function brb_ov_is_blocking($status) {
    return in_array((string) $status, brb_blocking_statuses(), true);
}
/** Day status for the calendar: booked, held, blocked or free. */
function brb_ov_day_status($ymd, $day_bookings) {
    foreach ($day_bookings as $b) if (brb_ov_is_blocking($b->status)) return 'booked';
    $hold = brb_date_status($ymd); // plugin block data: blackouts + calendar holds
    if ($hold === 'blocked') return 'blocked';
    if ($hold === 'reserved') return 'booked';
    if ($hold === 'hold') return 'held';
    return 'free';
}
function brb_ov_day_label($status) {
    $map = ['booked' => 'Booked', 'held' => 'Held', 'blocked' => 'Blocked', 'free' => 'Free'];
    return $map[$status] ?? 'Free';
}
function brb_ov_badge_class($status) {
    if (in_array($status, ['paid', 'confirmed'], true)) return 'paid';
    if ($status === 'deposit_paid') return 'deposit';
    if (in_array($status, ['approved', 'held'], true)) return 'approved';
    return 'review';
}
/** Calendar hold status label. Holds never show a name or notes. */
function brb_ov_hold_label($type) {
    $map = ['hold' => 'Held', 'reserved' => 'Reserved', 'blocked' => 'Date block'];
    return $map[$type] ?? 'Date block';
}
function brb_ov_hold_type($raw) {
    return isset(brb_hold_types()[(string) $raw]) ? (string) $raw : 'blocked';
}
/** Calendar holds/blocks for one day: status and time only. */
function brb_ov_day_holds($ymd) {
    $out = [];
    if (in_array($ymd, brb_blackouts(), true)) {
        $out[] = ['label' => 'Date block', 'type' => 'blocked', 'start_dt' => $ymd . ' 00:00:00', 'end_dt' => $ymd . ' 23:59:00'];
    }
    foreach (brb_holds_touching_day($ymd) as $h) {
        $type = brb_ov_hold_type($h->hold_type);
        $out[] = ['label' => brb_ov_hold_label($type), 'type' => $type, 'start_dt' => $h->start_dt, 'end_dt' => $h->end_dt];
    }
    return $out;
}
/** Upcoming bookings and holds from today on, sorted by start. */
function brb_ov_upcoming_items($limit = 200) {
    global $wpdb;
    $today = brb_now()->setTime(0, 0)->format('Y-m-d H:i:s');
    $far = '9999-12-31 00:00:00';
    $items = [];
    $active = brb_ov_bookings_between($today, $far);
    foreach ($active as $b) {
        $items[] = ['kind' => 'booking', 'start_dt' => $b->start_dt, 'end_dt' => $b->end_dt, 'name' => (string) $b->name, 'status' => (string) $b->status, 'label' => brb_status_label($b->status)];
    }
    $holds = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT id, hold_type, start_dt, end_dt FROM ' . brb_hold_table() . ' WHERE end_dt > %s ORDER BY start_dt ASC, id ASC LIMIT 500',
        $today
    ));
    foreach ($holds as $h) {
        $overlap = false;
        foreach ($active as $b) if ($b->start_dt < $h->end_dt && $b->end_dt > $h->start_dt) { $overlap = true; break; }
        if ($overlap) continue;
        $type = brb_ov_hold_type($h->hold_type);
        $items[] = ['kind' => 'hold', 'start_dt' => $h->start_dt, 'end_dt' => $h->end_dt, 'name' => '', 'status' => $type, 'label' => brb_ov_hold_label($type)];
    }
    $todayYmd = substr($today, 0, 10);
    foreach (brb_blackouts() as $ymd) {
        if ($ymd < $todayYmd) continue;
        $items[] = ['kind' => 'hold', 'start_dt' => $ymd . ' 00:00:00', 'end_dt' => $ymd . ' 23:59:00', 'name' => '', 'status' => 'blocked', 'label' => 'Date block'];
    }
    usort($items, function ($a, $b) { return strcmp($a['start_dt'], $b['start_dt']); });
    return array_slice($items, 0, $limit);
}

/* ---------- formatting ---------- */

function brb_ov_tel_href($phone) {
    $digits = preg_replace('/[^0-9+]/', '', (string) $phone);
    return $digits === '' ? '' : 'tel:' . $digits;
}
function brb_ov_time_span($start_dt, $end_dt) {
    try {
        $s = new DateTimeImmutable($start_dt, brb_tz());
        $e = new DateTimeImmutable($end_dt, brb_tz());
    } catch (Exception $ex) {
        return '';
    }
    if ($s->format('H:i') === '00:00' && in_array($e->format('H:i'), ['00:00', '23:59'], true)) return 'All day';
    $span = $s->format('g:i A') . ' – ' . $e->format('g:i A');
    if ($e->format('Y-m-d') !== $s->format('Y-m-d') && !($e->format('H:i') === '00:00' && $e->modify('-1 day')->format('Y-m-d') === $s->format('Y-m-d'))) {
        $span = $s->format('M j, g:i A') . ' – ' . $e->format('M j, g:i A');
    }
    return $span;
}
function brb_ov_text_block($text) {
    $text = trim((string) $text);
    return $text === '' ? '' : nl2br(esc_html($text));
}
function brb_ov_month_url($ym, $anchor = '') {
    $base = get_permalink();
    if (!$base) $base = home_url('/');
    $url = add_query_arg('brb_ov_m', $ym, remove_query_arg('brb_ov_m', $base));
    return $url . ($anchor !== '' ? '#' . $anchor : '');
}

/* ---------- rendering ---------- */

function brb_ov_render_booking_card($b) {
    $badge = brb_ov_badge_class($b->status);
    $h = '<article class="brb-ov-card">';
    $h .= '<div class="brb-ov-card-top"><span class="brb-ov-time">' . esc_html(brb_ov_time_span($b->start_dt, $b->end_dt)) . '</span>';
    $h .= '<span class="brb-ov-badge brb-ov-badge-' . esc_attr($badge) . '">' . esc_html(brb_status_label($b->status)) . '</span></div>';
    $h .= '<h4 class="brb-ov-name">' . esc_html((string) $b->name) . '</h4>';
    $h .= '<dl class="brb-ov-dl">';
    $tel = brb_ov_tel_href($b->phone ?? '');
    if ($tel !== '') $h .= '<dt>Phone</dt><dd><a href="' . esc_url($tel, ['tel']) . '">' . esc_html((string) $b->phone) . '</a></dd>';
    $email = trim((string) ($b->email ?? ''));
    if ($email !== '' && is_email($email) && sanitize_email($email) === $email) {
        $h .= '<dt>Email</dt><dd><a href="' . esc_url('mailto:' . $email, ['mailto']) . '">' . esc_html($email) . '</a></dd>';
    } elseif ($email !== '') {
        $h .= '<dt>Email</dt><dd>' . esc_html($email) . '</dd>';
    }
    $type = brb_event_type_label($b->event_type ?? '');
    if ($type !== '') $h .= '<dt>Event</dt><dd>' . esc_html($type) . '</dd>';
    $h .= '<dt>Guests</dt><dd>' . esc_html((string) (int) $b->guests) . '</dd>';
    if (trim((string) ($b->end_time ?? '')) !== '') $h .= '<dt>Planned end</dt><dd>' . esc_html(brb_end_time_label($b->end_time)) . '</dd>';
    $h .= '<dt>Ref</dt><dd>' . esc_html(brb_ref($b)) . '</dd>';
    $h .= '</dl>';
    $about = brb_ov_text_block($b->event_about ?? '');
    if ($about !== '') $h .= '<div class="brb-ov-note"><strong>About the event</strong><p>' . $about . '</p></div>';
    $notes = brb_ov_text_block($b->notes ?? '');
    if ($notes !== '') $h .= '<div class="brb-ov-note"><strong>Notes</strong><p>' . $notes . '</p></div>';
    $h .= '</article>';
    return $h;
}
function brb_ov_render_hold_card($hold) {
    $h = '<article class="brb-ov-card brb-ov-card-hold brb-ov-hold-' . esc_attr($hold['type']) . '">';
    $h .= '<div class="brb-ov-card-top"><span class="brb-ov-time">' . esc_html(brb_ov_time_span($hold['start_dt'], $hold['end_dt'])) . '</span>';
    $h .= '<span class="brb-ov-badge brb-ov-badge-' . esc_attr($hold['type']) . '">' . esc_html($hold['label']) . '</span></div>';
    $h .= '</article>';
    return $h;
}
function brb_ov_css() {
    return '<style id="brb-ov-css">'
        . '.brb-ov{max-width:720px;margin:0 auto;font-size:16px;line-height:1.4;color:#1d1d1f;overflow-wrap:anywhere}'
        . '.brb-ov *{box-sizing:border-box}'
        . '.brb-ov h2{font-size:1.25rem;margin:0 0 .25rem}.brb-ov h3{font-size:1.05rem;margin:1.25rem 0 .5rem}'
        . '.brb-ov-sub{margin:0 0 .75rem;color:#555;font-size:.9rem}'
        . '.brb-ov-nav{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin:.5rem 0}'
        . '.brb-ov-nav a{display:inline-block;padding:.6rem .9rem;border:1px solid #ccc;border-radius:8px;text-decoration:none;min-width:44px;text-align:center}'
        . '.brb-ov-nav .brb-ov-off{visibility:hidden}.brb-ov-month{font-weight:700;font-size:1.1rem}'
        . '.brb-ov-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:3px;touch-action:pan-y}'
        . '.brb-ov-dow{font-size:.7rem;text-align:center;color:#666;padding:2px 0}'
        . '.brb-ov-day{display:flex;flex-direction:column;align-items:center;justify-content:flex-start;min-height:48px;padding:4px 1px;border-radius:6px;text-decoration:none;color:inherit;background:#f1f5f1;font-size:.85rem}'
        . '.brb-ov-day b{font-weight:600}.brb-ov-day small{font-size:.6rem;line-height:1.1;margin-top:2px}'
        . '.brb-ov-free{background:#eef6ee}.brb-ov-booked{background:#c62828;color:#fff}.brb-ov-held{background:#f9a825;color:#1d1d1f}.brb-ov-blocked{background:#555;color:#fff}'
        . '.brb-ov-past{opacity:.55}.brb-ov-today{outline:2px solid #1565c0;outline-offset:-2px}.brb-ov-empty{background:transparent}'
        . '.brb-ov-req{display:block;width:6px;height:6px;border-radius:50%;background:#1565c0;margin-top:2px}'
        . '.brb-ov-legend{display:flex;flex-wrap:wrap;gap:.5rem .9rem;font-size:.8rem;margin:.5rem 0 0;padding:0;list-style:none}'
        . '.brb-ov-legend i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:4px;vertical-align:-1px}'
        . '.brb-ov-dayblock{scroll-margin-top:80px;margin:1rem 0;padding-top:.25rem;border-top:1px solid #ddd}'
        . '.brb-ov-dayblock:target{background:#fffbe6}'
        . '.brb-ov-dayhead{display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap}'
        . '.brb-ov-card{border:1px solid #ddd;border-radius:10px;padding:.75rem;margin:.5rem 0;background:#fff}'
        . '.brb-ov-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem;flex-wrap:wrap;font-size:.9rem}'
        . '.brb-ov-name{margin:.35rem 0;font-size:1.05rem}'
        . '.brb-ov-dl{display:grid;grid-template-columns:auto 1fr;gap:.2rem .75rem;margin:0}.brb-ov-dl dt{color:#666}.brb-ov-dl dd{margin:0}'
        . '.brb-ov-dl a{display:inline-block;padding:.15rem 0}'
        . '.brb-ov-note{margin-top:.5rem;font-size:.92rem}.brb-ov-note p{margin:.2rem 0 0}'
        . '.brb-ov-badge{display:inline-block;padding:.1rem .5rem;border-radius:999px;font-size:.75rem;font-weight:600;background:#e3f2fd;color:#0d47a1;white-space:nowrap}'
        . '.brb-ov-badge-paid{background:#e8f5e9;color:#1b5e20}.brb-ov-badge-deposit{background:#e0f2f1;color:#004d40}'
        . '.brb-ov-badge-approved{background:#fff3e0;color:#8a4b00}.brb-ov-badge-review{background:#e3f2fd;color:#0d47a1}'
        . '.brb-ov-badge-blocked{background:#eee;color:#333}.brb-ov-badge-hold{background:#fff8e1;color:#6d4c00}.brb-ov-badge-reserved{background:#fde0dc;color:#8e1b10}'
        . '.brb-ov-badge-booked{background:#fde0dc;color:#8e1b10}.brb-ov-badge-held{background:#fff8e1;color:#6d4c00}.brb-ov-badge-free{background:#eef6ee;color:#1b5e20}'
        . '.brb-ov-list{list-style:none;margin:0;padding:0}.brb-ov-list li{border-bottom:1px solid #eee}'
        . '.brb-ov-list a{display:flex;justify-content:space-between;gap:.5rem;padding:.55rem 0;text-decoration:none;color:inherit;flex-wrap:wrap}'
        . '.brb-ov-none{color:#666}'
        . '</style>';
}
function brb_ov_swipe_js() {
    // Navigation only: a horizontal swipe on the calendar follows the prev/next link.
    return '<script>(function(){var g=document.getElementById("brb-ov-grid");if(!g)return;var x=null,y=null;'
        . 'g.addEventListener("touchstart",function(e){var t=e.changedTouches[0];x=t.clientX;y=t.clientY;},{passive:true});'
        . 'g.addEventListener("touchend",function(e){if(x===null)return;var t=e.changedTouches[0],dx=t.clientX-x,dy=t.clientY-y;x=null;'
        . 'if(Math.abs(dx)<60||Math.abs(dx)<Math.abs(dy)*1.5)return;var a=document.querySelector(dx<0?".brb-ov-next":".brb-ov-prev");'
        . 'if(a&&a.href)window.location.href=a.href;},{passive:true});})();</script>';
}
function brb_ov_render() {
    $tz = brb_tz();
    $ym = brb_ov_month_param();
    $first = new DateTimeImmutable($ym . '-01 00:00:00', $tz);
    $next = $first->modify('+1 month');
    $today = brb_now()->format('Y-m-d');
    $nowMonth = brb_now()->modify('first day of this month');
    $minYm = $nowMonth->modify('-' . brb_ov_months_back() . ' months')->format('Y-m');
    $maxYm = $nowMonth->modify('+' . brb_ov_months_ahead() . ' months')->format('Y-m');
    $prevYm = $first->modify('-1 month')->format('Y-m');
    $nextYm = $next->format('Y-m');

    $bookings = brb_ov_bookings_between($first->format('Y-m-d H:i:s'), $next->format('Y-m-d H:i:s'));
    $days = [];
    for ($d = $first; $d < $next; $d = $d->modify('+1 day')) {
        $ymd = $d->format('Y-m-d');
        $dayB = array_values(array_filter($bookings, function ($b) use ($ymd) { return brb_ov_booking_on_day($b, $ymd); }));
        $holds = brb_ov_day_holds($ymd);
        $status = brb_ov_day_status($ymd, $dayB);
        $req = false;
        foreach ($dayB as $b) if (!brb_ov_is_blocking($b->status)) $req = true;
        $days[$ymd] = ['date' => $d, 'bookings' => $dayB, 'holds' => $holds, 'status' => $status, 'request' => $req];
    }

    $h = brb_ov_css();
    $h .= '<div class="brb-ov" id="brb-ov">';
    $h .= '<h2>Bookings calendar</h2><p class="brb-ov-sub">View only. Tap a day to see its bookings. Times are Central.</p>';
    $h .= '<div class="brb-ov-nav">';
    $h .= $prevYm >= $minYm ? '<a class="brb-ov-prev" href="' . esc_url(brb_ov_month_url($prevYm)) . '" aria-label="Previous month">&larr;</a>' : '<span class="brb-ov-off">&larr;</span>';
    $h .= '<span class="brb-ov-month">' . esc_html($first->format('F Y')) . '</span>';
    $h .= $nextYm <= $maxYm ? '<a class="brb-ov-next" href="' . esc_url(brb_ov_month_url($nextYm)) . '" aria-label="Next month">&rarr;</a>' : '<span class="brb-ov-off">&rarr;</span>';
    $h .= '</div>';
    $h .= '<div class="brb-ov-grid" id="brb-ov-grid" role="grid" aria-label="' . esc_attr($first->format('F Y')) . '">';
    foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow) $h .= '<div class="brb-ov-dow">' . esc_html($dow) . '</div>';
    for ($i = 0, $pad = (int) $first->format('w'); $i < $pad; $i++) $h .= '<div class="brb-ov-day brb-ov-empty" aria-hidden="true"></div>';
    foreach ($days as $ymd => $day) {
        $cls = 'brb-ov-day brb-ov-' . $day['status'];
        if ($ymd < $today) $cls .= ' brb-ov-past';
        if ($ymd === $today) $cls .= ' brb-ov-today';
        $has = $day['bookings'] || $day['holds'];
        $inner = '<b>' . esc_html($day['date']->format('j')) . '</b><small>' . esc_html(brb_ov_day_label($day['status'])) . '</small>';
        if ($day['request']) $inner .= '<span class="brb-ov-req" title="Request in review"></span>';
        $label = $day['date']->format('l, F j') . ': ' . brb_ov_day_label($day['status']) . ($day['request'] ? ', request in review' : '');
        if ($has) $h .= '<a class="' . esc_attr($cls) . '" href="#brb-ov-d-' . esc_attr($ymd) . '" aria-label="' . esc_attr($label) . '">' . $inner . '</a>';
        else $h .= '<div class="' . esc_attr($cls) . '" aria-label="' . esc_attr($label) . '">' . $inner . '</div>';
    }
    $h .= '</div>';
    $h .= '<ul class="brb-ov-legend"><li><i class="brb-ov-booked"></i>Booked</li><li><i class="brb-ov-held"></i>Held</li><li><i class="brb-ov-blocked"></i>Blocked</li><li><i class="brb-ov-free"></i>Free</li><li><span class="brb-ov-req" style="display:inline-block;margin-right:4px"></span>Request in review</li></ul>';

    $h .= '<h3>' . esc_html($first->format('F Y')) . ' by day</h3>';
    $any = false;
    foreach ($days as $ymd => $day) {
        if (!$day['bookings'] && !$day['holds']) continue;
        $any = true;
        $h .= '<section class="brb-ov-dayblock" id="brb-ov-d-' . esc_attr($ymd) . '">';
        $h .= '<div class="brb-ov-dayhead"><h3>' . esc_html($day['date']->format('D, M j, Y')) . '</h3><span class="brb-ov-badge brb-ov-badge-' . esc_attr($day['status']) . '">' . esc_html(brb_ov_day_label($day['status'])) . '</span></div>';
        foreach ($day['bookings'] as $b) $h .= brb_ov_render_booking_card($b);
        foreach ($day['holds'] as $hold) $h .= brb_ov_render_hold_card($hold);
        $h .= '</section>';
    }
    if (!$any) $h .= '<p class="brb-ov-none">Nothing on the calendar this month.</p>';

    $h .= '<h3>Upcoming</h3>';
    $items = brb_ov_upcoming_items();
    if (!$items) {
        $h .= '<p class="brb-ov-none">No upcoming bookings or holds.</p>';
    } else {
        $h .= '<ul class="brb-ov-list">';
        foreach ($items as $it) {
            $s = new DateTimeImmutable($it['start_dt'], $tz);
            $ymd = $s->format('Y-m-d');
            if ($ymd < $today) $ymd = $today;
            $badge = $it['kind'] === 'booking' ? brb_ov_badge_class($it['status']) : $it['status'];
            $h .= '<li><a href="' . esc_url(brb_ov_month_url(substr($ymd, 0, 7), 'brb-ov-d-' . $ymd)) . '"><span>' . esc_html($s->format('D, M j, Y')) . ($it['name'] !== '' ? ' · ' . esc_html($it['name']) : '') . '</span><span class="brb-ov-badge brb-ov-badge-' . esc_attr($badge) . '">' . esc_html($it['label']) . '</span></a></li>';
        }
        $h .= '</ul>';
    }
    $h .= '</div>';
    $h .= brb_ov_swipe_js();
    return $h;
}
function brb_ov_block_mail($pre) { return false; }
function brb_ov_shortcode($atts = [], $content = null) {
    $post = get_post();
    if (!$post) return '';
    if (brb_ov_context_blocked()) return '';
    if (post_password_required($post)) return '';
    if ((string) $post->post_password === '') {
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return '<p class="brb-ov-admin-note">This bookings view only shows on a password-protected page. Set a page password to use it.</p>';
        }
        return '';
    }
    add_filter('pre_wp_mail', 'brb_ov_block_mail', PHP_INT_MAX);
    try {
        $html = brb_ov_render();
    } finally {
        remove_filter('pre_wp_mail', 'brb_ov_block_mail', PHP_INT_MAX);
    }
    return $html;
}
add_shortcode(BRB_OV_TAG, 'brb_ov_shortcode');

/* ---------- privacy filters ---------- */

function brb_ov_current_is_owner_page() {
    if (!is_singular()) return false;
    return brb_ov_is_owner_post(get_queried_object_id());
}
function brb_ov_send_headers() {
    if (!brb_ov_current_is_owner_page() || headers_sent()) return;
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Referrer-Policy: no-referrer');
}
add_action('template_redirect', 'brb_ov_send_headers', 0);

function brb_ov_wp_robots($robots) {
    if (!brb_ov_current_is_owner_page()) return $robots;
    unset($robots['index'], $robots['follow'], $robots['max-image-preview']);
    $robots['noindex'] = true;
    $robots['nofollow'] = true;
    $robots['noarchive'] = true;
    return $robots;
}
add_filter('wp_robots', 'brb_ov_wp_robots', 999);
add_filter('wpseo_robots', function ($robots) {
    return brb_ov_current_is_owner_page() ? 'noindex, nofollow' : $robots;
}, 999);
add_filter('wpseo_robots_array', function ($robots) {
    if (!brb_ov_current_is_owner_page()) return $robots;
    return array_merge((array) $robots, ['index' => 'noindex', 'follow' => 'nofollow']);
}, 999);
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $brb_ov_f) {
    add_filter($brb_ov_f, function ($v) { return brb_ov_current_is_owner_page() ? '' : $v; }, 999);
}
unset($brb_ov_f);
add_action('wp_head', function () {
    if (brb_ov_current_is_owner_page()) echo '<meta name="referrer" content="no-referrer">' . "\n";
}, 1);

// Sitemaps: core and Yoast.
add_filter('wp_sitemaps_posts_query_args', function ($args) {
    $ids = brb_ov_post_ids();
    if ($ids) $args['post__not_in'] = array_values(array_unique(array_merge((array) ($args['post__not_in'] ?? []), $ids)));
    return $args;
});
add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($ids) {
    return array_values(array_unique(array_merge(array_map('intval', (array) $ids), brb_ov_post_ids())));
});

// Search (front end and REST /wp/v2/search) and feeds.
add_action('pre_get_posts', function ($q) {
    if (is_admin() && !wp_doing_ajax()) return;
    if (!$q->is_search() && !$q->is_feed()) return;
    $ids = brb_ov_post_ids();
    if (!$ids) return;
    $q->set('post__not_in', array_values(array_unique(array_merge((array) $q->get('post__not_in'), $ids))));
});

// Automatic page lists (wp_list_pages, page-list block, get_pages on the front end).
add_filter('wp_list_pages_excludes', function ($ex) {
    return array_values(array_unique(array_merge(array_map('intval', (array) $ex), brb_ov_post_ids())));
});
add_filter('get_pages', function ($pages) {
    if (is_admin()) return $pages;
    $ids = brb_ov_post_ids();
    if (!$ids) return $pages;
    return array_values(array_filter((array) $pages, function ($p) use ($ids) { return !in_array((int) $p->ID, $ids, true); }));
});

// oEmbed: never embed the page.
add_filter('oembed_request_post_id', function ($post_id) {
    return ($post_id && in_array((int) $post_id, brb_ov_post_ids(), true)) ? 0 : $post_id;
});
add_filter('embed_oembed_discover', function ($discover) {
    return brb_ov_current_is_owner_page() ? false : $discover;
});
add_action('wp_head', function () {
    if (brb_ov_current_is_owner_page()) remove_action('wp_head', 'wp_oembed_add_discovery_links');
}, 0);

// REST: the rendered content and excerpt of these pages are always empty.
function brb_ov_rest_prepare($response, $post, $request) {
    if (!brb_ov_is_owner_post($post)) return $response;
    $data = $response->get_data();
    foreach (['content', 'excerpt'] as $k) {
        if (isset($data[$k]) && is_array($data[$k]) && array_key_exists('rendered', $data[$k])) $data[$k]['rendered'] = '';
    }
    $response->set_data($data);
    return $response;
}
add_filter('rest_prepare_page', 'brb_ov_rest_prepare', 999, 3);
add_filter('rest_prepare_post', 'brb_ov_rest_prepare', 999, 3);

// No "Protected: " prefix on the owner page's title.
add_filter('protected_title_format', function ($format, $post = null) {
    return ($post && brb_ov_is_owner_post($post)) ? '%s' : $format;
}, 10, 2);
// The theme footer's "Ask Walter" button opens the chat, which is not loaded here.
add_action('wp_head', function () {
    if (brb_ov_current_is_owner_page()) echo '<style id="brb-ov-no-walter">.footer-ask-wrap,.brb-chat,#ask-walter{display:none!important}</style>' . "\n";
}, 99);
