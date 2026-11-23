<?php
/**
 * 2.5.85 staff dashboard (/staff/). Run with wp eval-file. Fake data only (example.com, "SD Test" names, Oct 2027).
 * - Access: logged-out and subscriber users get no data and no actions on every route; staff need the staff nonce to write.
 * - Les MUST FIX: Add booking / Add hold refused on booked, held, closed, blackout, add-on, and request-waiting days;
 *   Approve re-checks under the lock and refuses a taken date; holds are saved under GET_LOCK('brb_book').
 * - Actions never email the guest. Max's Nov 23 10:00 AM CT decision: a guest email sends only from the confirm
 *   screen; a send without a valid confirm token for the exact previewed message is refused.
 * - Add saves what its preview showed; Record payment keeps the 3% card fee separate; chip wording; pay link copy sends nothing.
 * - Page: noindex, no phone number, no 2212 address, no other-platform name.
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('brb_t')) {
    $GLOBALS['brb_fail'] = 0;
    $GLOBALS['brb_ok'] = 0;
    function brb_t($cond, $msg) {
        if ($cond) { $GLOBALS['brb_ok']++; echo "ok  $msg\n"; }
        else { $GLOBALS['brb_fail']++; echo "FAIL $msg\n"; }
    }
}
if (empty($_SERVER['SERVER_NAME'])) $_SERVER['SERVER_NAME'] = 'localhost';
global $wpdb;
$wpdb->hide_errors();

// Mail: count what would leave (after the dashboard guard at priority 1), never deliver.
$GLOBALS['sd_mail'] = [];
add_filter('pre_wp_mail', function ($pre, $atts) {
    // Anything still null here would have been delivered: record it and pretend it was (nothing leaves this box).
    if ($pre === null) { $GLOBALS['sd_mail'][] = is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to']; return true; }
    return $pre;
}, 5, 2);
// GET_LOCK evidence: see the original query before the SQLite shim rewrites it.
$GLOBALS['sd_locks'] = 0;
add_filter('query', function ($q) { if (stripos($q, "GET_LOCK('brb_book'") !== false) $GLOBALS['sd_locks']++; return $q; }, 0);

$DOMAIN = 'sd-test.example.com';
$snapshot = get_option('brb_settings');
$sent_snapshot = get_option('brb_sd_sent', null);
$env_secret = getenv('BRB_STRIPE_SECRET_KEY');

function sd_guest_mails() {
    return array_values(array_filter($GLOBALS['sd_mail'], function ($to) { return strpos($to, 'sd-test.example.com') !== false; }));
}
function sd_raw_booking($day, $name, $status, $extra = []) {
    global $wpdb;
    $row = array_merge([
        'token' => wp_generate_password(32, false), 'mode' => 'day',
        'start_dt' => $day . ' 10:00:00', 'end_dt' => $day . ' 23:30:00', 'guests' => 30,
        'name' => $name, 'email' => sanitize_title($name) . '@sd-test.example.com', 'phone' => '', 'notes' => '',
        'subtotal' => 850, 'total' => 850, 'amount_due' => 425, 'deposit_due' => 425, 'amount_paid' => 0,
        'status' => $status, 'source' => 'online', 'price_set' => 1, 'event_type' => 'birthday',
        'balance_due_on' => (new DateTimeImmutable($day))->modify('-7 days')->format('Y-m-d'),
        'created' => current_time('mysql', true),
    ], $extra);
    $wpdb->insert(brb_table(), $row);
    return (int) $wpdb->insert_id;
}
function sd_raw_hold($day, $name, $type) {
    global $wpdb;
    $wpdb->insert(brb_hold_table(), ['name' => $name, 'hold_type' => $type, 'start_dt' => $day . ' 00:00:00', 'end_dt' => $day . ' 23:59:00', 'notes' => '', 'created' => current_time('mysql', true)]);
    return (int) $wpdb->insert_id;
}
function sd_count($table, $where) {
    global $wpdb;
    return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where);
}
function sd_rest($method, $path, $body = null, $staff_nonce = true) {
    $req = new WP_REST_Request($method, '/brb/v1/staff/' . $path);
    if (strpos($path, '?') !== false) {
        list($p, $qs) = explode('?', $path, 2);
        $req = new WP_REST_Request($method, '/brb/v1/staff/' . $p);
        parse_str($qs, $q);
        $req->set_query_params($q);
    }
    if ($body !== null) {
        $req->set_header('content-type', 'application/json');
        $req->set_body(wp_json_encode($body));
    }
    if ($method !== 'GET' && $staff_nonce) $req->set_header('x-brb-staff-nonce', wp_create_nonce('brb_staff'));
    $res = rest_do_request($req);
    return [$res->get_status(), $res->get_data()];
}
function sd_cleanup() {
    global $wpdb;
    $ids = $wpdb->get_col("SELECT id FROM " . brb_table() . " WHERE email LIKE '%@sd-test.example.com'");
    foreach ($ids as $id) {
        $wpdb->delete(brb_pay_table(), ['booking_id' => (int) $id]);
        if (function_exists('brb_line_table')) $wpdb->delete(brb_line_table(), ['booking_id' => (int) $id]);
        $wpdb->delete(brb_table(), ['id' => (int) $id]);
    }
    $wpdb->query("DELETE FROM " . brb_hold_table() . " WHERE name LIKE 'SD Test%' OR notes LIKE '%SD Test%'");
    foreach (['sd-staff', 'sd-sub', 'sd-admin'] as $login) {
        $u = get_user_by('login', $login);
        if ($u) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u->ID); }
    }
}

try {
    sd_cleanup();
    // Live-like settings (damage $0, 50%, 3% fee) + one blackout for the test.
    $o = is_array($snapshot) ? $snapshot : [];
    $o['damage_deposit_amount'] = 0;
    $o['deposit_type'] = 'percent';
    $o['deposit_pct'] = 50;
    $o['card_fee_percent'] = 3;
    $o['blackouts'] = trim((string) ($o['blackouts'] ?? '') . "\n2027-10-11");
    update_option('brb_settings', $o);
    delete_option('brb_sd_sent');
    putenv('BRB_STRIPE_SECRET_KEY=sk_test_local_fake_not_a_key');

    brb_t(BRB_VER === '2.5.85', 'BRB_VER is 2.5.85');
    brb_t(sd_count(brb_table(), "start_dt >= '2027-10-01' AND start_dt < '2027-11-01' AND status NOT IN ('cancelled','declined','expired','spam')") === 0, 'Oct 2027 starts empty on this test box');

    // ---------- role, capability, users ----------
    do_action('init');
    brb_sd_install_role();
    $role = get_role('brb_staff');
    brb_t($role && $role->has_cap('brb_manage_bookings') && $role->has_cap('read'), 'brb_staff role has read + brb_manage_bookings');
    brb_t($role && !$role->has_cap('manage_options') && !$role->has_cap('edit_posts'), 'brb_staff cannot manage options or edit posts');
    brb_t(get_role('administrator')->has_cap('brb_manage_bookings'), 'administrators get brb_manage_bookings');
    $staff = wp_insert_user(['user_login' => 'sd-staff', 'user_email' => 'sd-staff@sd-test.example.com', 'user_pass' => wp_generate_password(24), 'role' => 'brb_staff', 'display_name' => 'SD Test Staff']);
    $sub = wp_insert_user(['user_login' => 'sd-sub', 'user_email' => 'sd-sub@sd-test.example.com', 'user_pass' => wp_generate_password(24), 'role' => 'subscriber']);
    brb_t(!is_wp_error($staff) && !is_wp_error($sub), 'fake staff and subscriber users created');
    brb_t(brb_sd_user_is_staff_only(get_user_by('id', $staff)), 'staff user is treated as staff-only (bounced from wp-admin)');
    brb_t(apply_filters('login_redirect', admin_url(), '', get_user_by('id', $staff)) === brb_sd_url(), 'staff login lands on /staff/, not wp-admin');
    brb_t(apply_filters('login_redirect', admin_url(), '', get_user_by('id', $sub)) === admin_url(), 'subscriber login redirect is unchanged');
    brb_t(brb_sd_url() === home_url('/staff/'), 'dashboard URL is /staff/');

    // ---------- fake data ----------
    $A = sd_raw_booking('2027-10-04', 'SD Test Booked A', 'approved', ['walkthrough_done' => 1]);
    sd_raw_hold('2027-10-05', 'SD Test Hold', 'hold');
    $W = sd_raw_booking('2027-10-06', 'SD Test Waiting W', 'review');
    sd_raw_hold('2027-10-07', 'SD Test Closed', 'blocked');
    $AD = sd_raw_booking('2027-10-21', 'SD Test Addon', 'approved', ['setup_addon' => 1]);

    // ---------- page + REST access: logged out / subscriber ----------
    wp_set_current_user(0);
    brb_t(brb_sd_page_state() === 'login', 'logged-out /staff/ goes to the login page');
    brb_t(strpos(wp_login_url(brb_sd_url()), 'redirect_to=' . rawurlencode(brb_sd_url())) !== false, 'login URL returns to /staff/');
    $paths = [];
    foreach (brb_sd_routes() as $r) $paths[] = [$r[1], str_replace('(?P<id>\d+)', (string) $W, $r[0])];
    brb_t(count($paths) >= 16, 'all staff routes enumerated (' . count($paths) . ')');
    $before = [sd_count(brb_table(), '1=1'), sd_count(brb_hold_table(), '1=1'), sd_count(brb_pay_table(), '1=1'), $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . brb_table() . ' WHERE id=%d', $W))];
    $leak = false; $bad = [];
    foreach ([[0, 401, 'logged-out'], [$sub, 403, 'subscriber']] as $who) {
        wp_set_current_user($who[0]);
        foreach ($paths as $p) {
            $body = $p[0] === 'POST' ? ['date' => '2027-10-12', 'kind' => 'hold', 'walkthrough' => 1, 'amount' => 10, 'method' => 'cash', 'kind' => 'approve'] : null;
            list($st, $data) = sd_rest($p[0], $p[1] . ($p[1] === 'day' ? '?date=2027-10-06' : ''), $body);
            if ($st !== $who[1]) $bad[] = $who[2] . ' ' . $p[0] . ' ' . $p[1] . ' => ' . $st;
            $json = wp_json_encode($data);
            // WP's error shape is {code, message, data: {status}}; anything more is a leak.
            $extra = is_array($data) ? array_diff(array_keys($data), ['code', 'message', 'data']) : ['?'];
            $inner = is_array($data['data'] ?? null) ? array_diff(array_keys($data['data']), ['status']) : [];
            if (strpos($json, 'SD Test') !== false || strpos($json, 'sd-test.example.com') !== false || $extra || $inner || !empty($data['ok'])) $leak = true;
        }
    }
    brb_t(!$bad, 'logged-out gets 401 and subscriber gets 403 on every route' . ($bad ? ': ' . implode('; ', array_slice($bad, 0, 4)) : ''));
    brb_t(!$leak, 'no booking names, emails or data in any refused response');
    $after = [sd_count(brb_table(), '1=1'), sd_count(brb_hold_table(), '1=1'), sd_count(brb_pay_table(), '1=1'), $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . brb_table() . ' WHERE id=%d', $W))];
    brb_t($before === $after, 'refused users changed nothing (bookings, holds, payments, request status)');
    wp_set_current_user($sub);
    brb_t(brb_sd_page_state() === 'forbidden', 'subscriber /staff/ shows "Staff only" with no data');
    $forb = brb_sd_forbidden_html();
    brb_t(strpos($forb, 'SD Test') === false && strpos($forb, 'noindex') !== false, 'forbidden page has no data and is noindex');

    // ---------- staff: reads ----------
    wp_set_current_user($staff);
    brb_t(brb_sd_page_state() === 'ok', 'staff user sees the dashboard');
    $html = brb_sd_page_html();
    brb_t(strpos($html, 'noindex, nofollow') !== false, 'page has robots noindex, nofollow');
    brb_t(strpos($html, 'brb-staff.css?ver=2.5.85') !== false && strpos($html, 'brb-staff.js?ver=2.5.85') !== false, 'assets are versioned with BRB_VER');
    brb_t(strpos($html, 'SD Test Waiting') === false, 'page shell has no booking data (it loads through the checked REST routes)');
    brb_t(!preg_match('/\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}/', $html) && stripos($html, '2212') === false && stripos($html, 'airbnb') === false, 'page has no phone number, no 2212 address, no other-platform name');
    brb_t(substr_count($html, 'data-jump="') === 9 && substr_count($html, '<nav class="nav"') === 1, 'six-item bottom nav plus three summary cards');
    brb_t(strpos($html, 'id="add-no-email" name="no_guest_email" checked') !== false, '"Don\'t email the guest" is checked by default on Add');
    brb_t(strpos($html, '<h2 class="big-heading">All Upcoming Reservations</h2>') !== false, 'upcoming section is headed "All Upcoming Reservations" (large heading)');
    brb_t(stripos($html, 'type="tel"') === false && stripos($html, 'name="phone"') === false, 'no phone field anywhere');
    $plain_post = sd_rest('POST', 'add-preview', ['date' => '2027-10-12'], false);
    brb_t($plain_post[0] === 403, 'a staff POST without the staff nonce is refused (403)');

    list($st, $cal) = sd_rest('GET', 'calendar?month=2027-10');
    $kinds = [];
    foreach ($cal['data']['days'] as $d) $kinds[$d['date']] = $d['kind'];
    brb_t($st === 200 && count($cal['data']['days']) === 31, 'calendar returns October 2027 (31 days)');
    brb_t($kinds['2027-10-04'] === 'booked' && $kinds['2027-10-05'] === 'held' && $kinds['2027-10-06'] === 'waiting' && $kinds['2027-10-07'] === 'closed' && $kinds['2027-10-11'] === 'closed' && $kinds['2027-10-12'] === 'open', 'calendar kinds: booked, held, request waiting, closed (hold and blackout), open');
    brb_t($cal['data']['days'][0]['dow'] === 5, 'Oct 1 2027 is a Friday (US week, Sunday first)');
    list($st, $day) = sd_rest('GET', 'day?date=2027-10-06');
    brb_t($st === 200 && $day['data']['kind'] === 'waiting' && !$day['data']['can_add'] && strpos($day['data']['add_blocked'], 'request is waiting') !== false, 'day detail for a waiting request says it cannot take another booking');
    $item = $day['data']['items'][0];
    brb_t($item['can_approve'] && $item['can_decline'], 'Approve and Decline are reachable from day detail');
    brb_t($item['hours'] === '10:00 AM – 11:30 PM' && $item['end_time'] === '', 'event times come from the booking data; no invented end time');
    list($st, $dayA) = sd_rest('GET', 'day?date=2027-10-04');
    brb_t(!empty($dayA['data']['items'][0]['can_pay']), 'Record payment and Copy pay link are reachable from day detail');
    brb_t($dayA['data']['rate_text'] === '$850 for the day', 'day detail rate comes from the calendar rates ($850 Mon)');

    // ---------- MUST FIX: Add refused on taken days ----------
    $refused = [
        '2027-10-04' => 'booked', '2027-10-05' => 'held', '2027-10-06' => 'waiting',
        '2027-10-07' => 'closed', '2027-10-11' => 'closed', '2027-10-20' => 'addon',
    ];
    $b0 = sd_count(brb_table(), '1=1'); $h0 = sd_count(brb_hold_table(), '1=1');
    foreach ($refused as $date => $why) {
        list($st, $r) = sd_rest('POST', 'add', ['kind' => 'booking', 'date' => $date, 'name' => 'SD Test Second', 'email' => 'second@sd-test.example.com', 'guests' => 20, 'event_type' => 'birthday']);
        brb_t($st === 409 && $r['code'] === 'busy' && $r['reason'] === $why, "Add booking refused on $date ($why): " . ($r['message'] ?? ''));
        list($st, $r) = sd_rest('POST', 'add', ['kind' => 'hold', 'hold_type' => 'hold', 'date' => $date, 'name' => 'SD Test Second hold']);
        brb_t($st === 409 && $r['code'] === 'busy', "Add hold refused on $date ($why)");
        list($st, $r) = sd_rest('POST', 'add', ['kind' => 'hold', 'hold_type' => 'blocked', 'date' => $date, 'name' => 'SD Test Second close']);
        brb_t($st === 409 && $r['code'] === 'busy', "Close day refused on $date ($why)");
    }
    brb_t(sd_count(brb_table(), '1=1') === $b0 && sd_count(brb_hold_table(), '1=1') === $h0, 'refused adds saved no booking and no hold');
    list($st, $pv) = sd_rest('POST', 'add-preview', ['kind' => 'booking', 'date' => '2027-10-06', 'guests' => 10]);
    brb_t($st === 200 && strpos($pv['data']['blocked'], 'request is waiting') !== false, 'Add preview warns before save on a request-waiting day');

    // Open day: first add works, second is refused because a request is now waiting.
    list($st, $r) = sd_rest('POST', 'add', ['kind' => 'booking', 'date' => '2027-10-12', 'name' => 'SD Test New', 'email' => 'new@sd-test.example.com', 'guests' => 40, 'event_type' => 'birthday']);
    brb_t($st === 200 && $r['data']['booking']['status'] === 'review', 'Add booking on an open day saves a request waiting');
    list($st, $r2) = sd_rest('POST', 'add', ['kind' => 'booking', 'date' => '2027-10-12', 'name' => 'SD Test Again', 'email' => 'again@sd-test.example.com', 'guests' => 10]);
    brb_t($st === 409 && $r2['reason'] === 'waiting', 'a second Add on the same day is refused (request waiting)');
    list($st, $r2) = sd_rest('POST', 'add', ['kind' => 'hold', 'date' => '2027-10-12']);
    brb_t($st === 409, 'a hold on that day is refused too');
    $locks = $GLOBALS['sd_locks'];
    list($st, $hr) = sd_rest('POST', 'add', ['kind' => 'hold', 'hold_type' => 'hold', 'date' => '2027-10-19', 'name' => 'SD Test Held by staff']);
    brb_t($st === 200 && $hr['data']['hold']['hold_type'] === 'hold', 'Add hold on an open day saves a date hold');
    brb_t($GLOBALS['sd_locks'] > $locks, 'the hold save took GET_LOCK(\'brb_book\')');
    list($st, $r2) = sd_rest('POST', 'add', ['kind' => 'booking', 'date' => '2027-10-19', 'name' => 'SD Test After hold', 'email' => 'afterhold@sd-test.example.com', 'guests' => 10]);
    brb_t($st === 409 && $r2['reason'] === 'held', 'a booking on the newly held day is refused');
    list($st, $r2) = sd_rest('POST', 'add', ['kind' => 'booking', 'date' => '2027-10-26', 'name' => 'SD Test Too many', 'email' => 'many@sd-test.example.com', 'guests' => 81]);
    brb_t($st === 409 && $r2['code'] === 'guests', '81 guests is refused (up to 80)');
    list($st, $r2) = sd_rest('POST', 'add', ['kind' => 'booking', 'date' => '2025-01-07', 'name' => 'SD Test Past', 'email' => 'past@sd-test.example.com', 'guests' => 10]);
    brb_t($st === 409, 'a past date is refused');

    // ---------- MUST FIX: Approve re-checks under the lock ----------
    $R1 = sd_raw_booking('2027-10-13', 'SD Test Rival One', 'review');
    $R2 = sd_raw_booking('2027-10-13', 'SD Test Rival Two', 'review'); // two public requests can wait on one day
    list($st, $r) = sd_rest('POST', "booking/$R1/approve", ['walkthrough' => 0]);
    brb_t($st === 409 && $r['code'] === 'walkthrough', 'Approve needs the walk-through checkbox');
    $locks = $GLOBALS['sd_locks'];
    list($st, $r) = sd_rest('POST', "booking/$R1/approve", ['walkthrough' => 1]);
    brb_t($st === 200 && $r['data']['status'] === 'approved' && $r['data']['walkthrough'], 'Approve with the walk-through ticked approves the first request');
    brb_t($GLOBALS['sd_locks'] > $locks, 'Approve took GET_LOCK(\'brb_book\')');
    list($st, $r) = sd_rest('POST', "booking/$R2/approve", ['walkthrough' => 1]);
    brb_t($st === 409 && $r['code'] === 'busy' && $r['reason'] === 'booked', 'Approve of the rival request on the same day is refused: ' . ($r['message'] ?? ''));
    $r2row = brb_booking($R2);
    brb_t($r2row->status === 'review' && empty($r2row->walkthrough_done), 'refused rival stays waiting, and its walk-through tick is rolled back');
    $R3 = sd_raw_booking('2027-10-14', 'SD Test Then Held', 'review', ['walkthrough_done' => 1]);
    sd_raw_hold('2027-10-14', 'SD Test Late hold', 'hold');
    list($st, $r) = sd_rest('POST', "booking/$R3/approve", ['walkthrough' => 1]);
    brb_t($st === 409 && $r['reason'] === 'held' && brb_booking($R3)->status === 'review', 'Approve refused when a hold was added after the request');
    $R4 = sd_raw_booking('2027-10-18', 'SD Test Then Booked', 'review', ['walkthrough_done' => 1]);
    sd_raw_booking('2027-10-18', 'SD Test Booked Elsewhere', 'deposit_paid', ['amount_paid' => 425]);
    list($st, $r) = sd_rest('POST', "booking/$R4/approve", ['walkthrough' => 1]);
    brb_t($st === 409 && $r['reason'] === 'booked' && brb_booking($R4)->status === 'review', 'Approve refused when another booking took the date after the request');
    // Core layer re-check (inside brb_approve_booking, under the same lock), bypassing the staff pre-check.
    $core = brb_approve_booking($R4, false);
    brb_t(is_wp_error($core) && $core->get_error_code() === 'busy', 'the core approve also refuses the taken date (second check under the lock)');
    $R5 = sd_raw_booking('2027-10-27', 'SD Test Closed later', 'review', ['walkthrough_done' => 1]);
    $o2 = get_option('brb_settings'); $o2['blackouts'] .= "\n2027-10-27"; update_option('brb_settings', $o2);
    list($st, $r) = sd_rest('POST', "booking/$R5/approve", ['walkthrough' => 1]);
    brb_t($st === 409 && $r['reason'] === 'closed', 'Approve refused when the day was closed after the request');

    // ---------- Add saves what the preview showed ----------
    $cases = [['2027-10-22', 'full', 1400.00, 1400.00, 42.00], ['2027-10-25', 'deposit', 850.00, 425.00, 12.75], ['2027-10-29', 'deposit', 1400.00, 700.00, 21.00]];
    $saved = [];
    foreach ($cases as $c) {
        $p = ['kind' => 'booking', 'date' => $c[0], 'name' => 'SD Test Preview ' . $c[0], 'email' => 'pv' . str_replace('-', '', $c[0]) . '@sd-test.example.com', 'guests' => 50, 'event_type' => 'shower', 'charge_now' => $c[1]];
        list($st, $pv) = sd_rest('POST', 'add-preview', $p);
        $q = $pv['data'];
        brb_t($st === 200 && abs($q['total'] - $c[2]) < 0.001 && abs($q['due_now'] - $c[3]) < 0.001 && abs($q['card_fee'] - $c[4]) < 0.001, "preview {$c[0]} {$c[1]}: total {$q['text']['total']}, now {$q['text']['due_now']}, card fee {$q['text']['card_fee']}, by card {$q['text']['card_total']}");
        list($st, $r) = sd_rest('POST', 'add', $p);
        $b = brb_booking($r['data']['booking']['id']);
        $saved[$c[0]] = (int) $b->id;
        brb_t(abs((float) $b->total - $q['total']) < 0.001 && abs((float) $b->deposit_due - $q['due_now']) < 0.001 && (string) $b->charge_now_mode === $q['charge_now_mode'] && (string) $b->balance_due_on === $q['balance_due_on'], "saved {$c[0]} matches its preview (total, charge now, due now, balance date)");
        brb_t($r['data']['quote'] == $q || (array_diff_key($q, ['blocked' => 1]) == $r['data']['quote']), "save response repeats the same quote for {$c[0]}");
    }
    brb_t(abs($cases[2][3] + $cases[2][4] - 721.00) < 0.001, 'Les check: $1,400 at 50% = $700 + $21 = $721 by card');

    // ---------- Record payment: fee separate, chip wording ----------
    $P = $saved['2027-10-25'];
    list($st, $r) = sd_rest('POST', "booking/$P/payment", ['amount' => 425, 'method' => 'cash']);
    brb_t($st === 409 && $r['code'] === 'status', 'Record payment waits for approval');
    sd_rest('POST', "booking/$P/approve", ['walkthrough' => 1]);
    list($st, $pv) = sd_rest('POST', "booking/$P/payment-preview", ['amount' => 425, 'method' => 'card']);
    brb_t($st === 200 && $pv['data']['fee'] == 12.75 && $pv['data']['charged'] == 437.75 && strpos($pv['data']['text']['fee'], '3%') !== false, 'payment preview shows the 3% card fee separately ($425 + $12.75 = $437.75)');
    list($st, $pv2) = sd_rest('POST', "booking/$P/payment-preview", ['amount' => 425, 'method' => 'check']);
    brb_t($pv2['data']['fee'] == 0 && $pv2['data']['text']['fee'] === 'No card fee', 'check payments have no card fee');
    list($st, $r) = sd_rest('POST', "booking/$P/payment", ['amount' => 425, 'method' => 'card']);
    $pay = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . brb_pay_table() . ' WHERE booking_id=%d', $P));
    brb_t($st === 200 && $pay && (float) $pay->amount == 425.0 && (float) $pay->fee == 12.75 && $pay->method === 'card', 'card payment stored as $425.00 with a separate $12.75 fee');
    brb_t($r['data']['status'] === 'deposit_paid' && $r['data']['chip']['text'] === 'Deposit paid, $425.00 due by Mon, Oct 18, 2027', 'chip reads "' . $r['data']['chip']['text'] . '"');
    brb_t(strpos($r['data']['money']['card_fees_text'], '$12.75') !== false, 'booking money shows card fees apart from the rental');
    list($st, $r) = sd_rest('POST', "booking/$P/payment", ['amount' => 900, 'method' => 'cash']);
    brb_t($st === 409 && $r['code'] === 'amount', 'a payment over what is owed is refused');
    list($st, $r) = sd_rest('POST', "booking/$P/payment", ['amount' => 100, 'method' => 'check']);
    brb_t($st === 409 && $r['code'] === 'check', 'a check payment needs the check number');
    list($st, $r) = sd_rest('POST', "booking/$P/payment", ['amount' => 425, 'method' => 'check', 'check_number' => '1001']);
    brb_t($st === 200 && $r['data']['chip']['text'] === 'Paid in full', 'paying the balance shows "Paid in full"');

    // ---------- Pay link: copy only ----------
    $L = $saved['2027-10-22'];
    sd_rest('POST', "booking/$L/approve", ['walkthrough' => 1]);
    $m0 = count($GLOBALS['sd_mail']);
    list($st, $r) = sd_rest('POST', "booking/$L/pay-link", []);
    brb_t($st === 200 && strpos($r['data']['url'], 'br_pay=') !== false && strpos($r['data']['url'], 'br_sig=') !== false, 'Copy pay link returns the signed link');
    brb_t($r['data']['due'] === '$1,400.00' && $r['data']['fee'] === '$42.00' && $r['data']['charged'] === '$1,442.00', 'pay link shows $1,400 + $42 fee = $1,442 (pay in full)');
    brb_t(count($GLOBALS['sd_mail']) === $m0, 'copying the pay link sends no email at all');
    list($st, $r) = sd_rest('POST', "booking/$W/pay-link", []);
    brb_t($st === 200 && $r['data']['url'] === '' && in_array('Request approved', $r['data']['blockers'], true), 'no link for a waiting request; it says what is missing');

    // ---------- Decline; actions never email; confirm-then-send (Max, Nov 23 10:00 AM CT) ----------
    $m0 = count(sd_guest_mails());
    list($st, $r) = sd_rest('POST', "booking/$W/decline", ['message' => 'SD Test note', 'email_guest' => 1]);
    brb_t($st === 200 && $r['data']['status'] === 'declined', 'Decline works');
    list($st, $xr) = sd_rest('POST', 'add', ['kind' => 'booking', 'date' => '2027-10-28', 'name' => 'SD Test Approve mail', 'email' => 'approvemail@sd-test.example.com', 'guests' => 25, 'no_guest_email' => 0]);
    $X = (int) $xr['data']['booking']['id'];
    sd_rest('POST', "booking/$X/approve", ['walkthrough' => 1, 'email_guest' => 1, 'charge_now' => 'full']);
    $xb = brb_booking($X);
    brb_t($xb->status === 'approved' && $xb->charge_now_mode === 'full' && abs((float) $xb->deposit_due - 850) < 0.001, 'Approve can set Pay in full (deposit due becomes $850)');
    brb_t(strpos((string) $xb->audit_log, 'guest email skipped') !== false, 'approve audit says the guest email was skipped');
    brb_t(count(sd_guest_mails()) === $m0, 'choosing "Email the guest" on Add, Approve or Decline sends nothing by itself');

    list($st, $d) = sd_rest('POST', "booking/$X/draft", ['kind' => 'approve']);
    $dr = $d['data'];
    brb_t($st === 200 && $dr['to'] === 'approvemail@sd-test.example.com' && $dr['subject'] === brb_approve_guest_subject($xb) && $dr['body'] === brb_approve_guest_body($xb) && $dr['confirm'] !== '', 'draft returns the full plugin approval email, its recipient and a confirm token');
    brb_t(count(sd_guest_mails()) === $m0, 'opening the confirm screen (draft) sends nothing');
    list($st, $rq) = sd_rest('POST', "booking/$X/draft", ['kind' => 'request']);
    brb_t($st === 200 && strpos($rq['data']['subject'], 'We received your Boardroom request') === 0 && count(sd_guest_mails()) === $m0, 'request-received draft is the plugin text, rendered without sending');
    $send = ['kind' => 'approve', 'to' => $dr['to'], 'subject' => $dr['subject'], 'body' => $dr['body'], 'confirm' => $dr['confirm'], 'confirmed' => true];
    $bad_cases = [
        'no confirm step' => array_diff_key($send, ['confirm' => 1, 'confirmed' => 1]),
        'confirm token but no confirmed flag' => array_diff_key($send, ['confirmed' => 1]),
        'forged token' => array_merge($send, ['confirm' => (time() + 600) . '.' . str_repeat('a', 64)]),
        'expired token' => array_merge($send, ['confirm' => '1000.' . explode('.', $dr['confirm'])[1]]),
        'body changed after preview' => array_merge($send, ['body' => $dr['body'] . "\nExtra line"]),
        'subject changed after preview' => array_merge($send, ['subject' => 'Different']),
        'different recipient' => array_merge($send, ['to' => 'someone-else@sd-test.example.com']),
        'different kind' => array_merge($send, ['kind' => 'decline']),
    ];
    foreach ($bad_cases as $label => $body) {
        list($st, $r) = sd_rest('POST', "booking/$X/send", $body);
        brb_t($st === 409 && in_array($r['code'], ['confirm'], true), "send refused: $label");
    }
    list($st, $r) = sd_rest('POST', "booking/$A/send", $send);
    brb_t($st === 409, 'send refused: token from another booking');
    brb_t(count(sd_guest_mails()) === $m0, 'no refused send reached the guest');
    wp_set_current_user($sub);
    list($st, $r) = sd_rest('POST', "booking/$X/send", $send);
    brb_t($st === 403 && count(sd_guest_mails()) === $m0, 'a subscriber cannot send even with a valid token');
    $admin_id = wp_insert_user(['user_login' => 'sd-admin', 'user_email' => 'sd-admin@sd-test.example.com', 'user_pass' => wp_generate_password(24), 'role' => 'administrator']);
    wp_set_current_user($admin_id);
    list($st, $r) = sd_rest('POST', "booking/$X/send", $send);
    brb_t($st === 409, 'the token is tied to the staff user who saw the preview');
    wp_set_current_user($staff);
    list($st, $r) = sd_rest('POST', "booking/$X/send", $send);
    $guest_now = sd_guest_mails();
    brb_t($st === 200 && $r['data']['sent'] === true && count($guest_now) === $m0 + 1 && end($guest_now) === 'approvemail@sd-test.example.com', 'after the confirm step the exact previewed email sends once to the guest');
    brb_t(strpos((string) brb_booking($X)->audit_log, 'approval email sent to the guest after the confirm screen') !== false, 'the send is logged on the booking with who and when');
    list($st, $r) = sd_rest('POST', "booking/$X/send", $send);
    brb_t($st === 409 && count(sd_guest_mails()) === $m0 + 1, 'the same confirm cannot send twice');
    list($st, $dd) = sd_rest('POST', "booking/$W/draft", ['kind' => 'decline', 'note' => 'SD Test note']);
    brb_t($st === 200 && strpos($dd['data']['body'], 'SD Test note') !== false, 'decline draft carries the message to the guest');
    list($st, $r) = sd_rest('POST', "booking/$W/draft", ['kind' => 'approve']);
    brb_t($st === 409, 'no approval email for a declined booking');
    $blk = sd_raw_booking('2027-10-31', 'SD Test Blocked addr', 'approved', ['email' => 'contiveros8@gmail.com']);
    list($st, $r) = sd_rest('POST', "booking/$blk/draft", ['kind' => 'approve']);
    brb_t($st === 409 && $r['code'] === 'to', 'the dashboard refuses to email a never-email address');
    $wpdb->delete(brb_table(), ['id' => $blk]);
    list($st, $msg) = sd_rest('GET', 'messages');
    brb_t($st === 200 && count($msg['data']['sent']) === 1 && !empty($msg['data']['suggested']), 'Messages lists sends and suggestions');
    list($st, $r) = sd_rest('POST', "booking/$X/draft", ['kind' => 'send-now']);
    brb_t($st === 409, 'unknown message types are refused');
    $plinks = array_values(array_filter($msg['data']['suggested'], function ($x) { return empty($x['blocked']); }));
    list($st, $pd) = sd_rest('POST', 'booking/' . $plinks[0]['booking_id'] . '/draft', ['kind' => 'paylink']);
    brb_t($st === 200 && strpos($pd['data']['body'], 'br_pay=') !== false, 'pay link email draft carries the signed link');
    brb_t(true, 'check-address note: approval body has 2212: ' . (strpos($dr['body'], '2212') !== false ? 'yes' : 'no') . ', pay link body: ' . (strpos($pd['data']['body'], '2212') !== false ? 'yes' : 'no'));
    brb_t(count(sd_guest_mails()) === $m0 + 1, 'exactly one guest email left during the whole run, the confirmed one (' . count($GLOBALS['sd_mail']) . ' mails incl. staff notices)');
    $GLOBALS['brb_sd_guarded'] = ['guard@sd-test.example.com'];
    $blocked = wp_mail('guard@sd-test.example.com', 'x', 'y');
    $blocked_cc = wp_mail('staff-only@example.com', 'x', 'y', ['Cc: guard@sd-test.example.com']);
    brb_sd_release_guard();
    brb_t($blocked === false && $blocked_cc === false && count(sd_guest_mails()) === $m0 + 1, 'mail guard blocks the guest address in To and Cc during actions');

    // ---------- summary, lists, upcoming ----------
    list($st, $s) = sd_rest('GET', 'summary');
    brb_t($st === 200 && $s['data']['requests'] >= 3 && isset($s['data']['next']), 'summary counts requests waiting and shows the next event');
    list($st, $rq) = sd_rest('GET', 'requests');
    brb_t($st === 200 && !in_array($W, array_column($rq['data'], 'id'), true), 'declined requests leave the Requests list');
    list($st, $pl) = sd_rest('GET', 'payments');
    brb_t($st === 200 && in_array($A, array_column($pl['data'], 'id'), true) && !in_array($P, array_column($pl['data'], 'id'), true), 'Payments lists money owed and drops paid-in-full');
    list($st, $up) = sd_rest('GET', 'upcoming');
    brb_t($st === 200 && is_array($up['data']), 'Upcoming loads');

    // ---------- text safety ----------
    $dirty = "Call 563-555-0100 or (563) 555 0199. Mail checks to 2212 37th Street, Moline. Airbnb door code: 4821. Total \$1,400.00 on 2027-10-22.";
    $clean = brb_sd_scrub($dirty);
    brb_t(strpos($clean, '555') === false && stripos($clean, '2212') === false && stripos($clean, 'airbnb') === false && strpos($clean, '4821') === false, 'scrub hides phone numbers, the 2212 address, other-platform names and entry codes');
    brb_t(strpos($clean, '$1,400.00') !== false && strpos($clean, '2027-10-22') !== false, 'scrub keeps money and dates');
    $hid = sd_raw_hold('2027-10-30', 'SD Test Airbnb 563-555-0123', 'reserved');
    list($st, $d30) = sd_rest('GET', 'day?date=2027-10-30');
    $j = wp_json_encode($d30);
    brb_t(strpos($j, '555-0123') === false && stripos($j, 'airbnb') === false, 'day detail never shows a phone number or other-platform name from hold names');
    list($st, $all) = sd_rest('GET', 'calendar?month=2027-10');
    brb_t(stripos(wp_json_encode($all), 'airbnb') === false, 'calendar tags are scrubbed too');

    // ---------- not public ----------
    $server = rest_get_server();
    $index = $server->get_index(new WP_REST_Request('GET', '/'))->get_data();
    brb_t(!preg_grep('#^/brb/v1/staff#', array_keys($index['routes'])), 'staff routes are not listed in the public REST index');
    brb_t(!get_page_by_path('staff') || in_array((int) get_page_by_path('staff')->ID, (array) (apply_filters('wp_sitemaps_posts_query_args', [], 'page')['post__not_in'] ?? []), true), '/staff/ is not a sitemap page');
    $providers = wp_sitemaps_get_server()->registry->get_providers();
    $urls = isset($providers['posts']) ? wp_json_encode($providers['posts']->get_url_list(1, 'page')) : '';
    brb_t(strpos($urls, '/staff') === false, 'core sitemap has no /staff/ URL');
} finally {
    wp_set_current_user(0);
    sd_cleanup();
    if ($snapshot !== false) update_option('brb_settings', $snapshot);
    if ($sent_snapshot === null) delete_option('brb_sd_sent'); else update_option('brb_sd_sent', $sent_snapshot, false);
    if ($env_secret === false) putenv('BRB_STRIPE_SECRET_KEY'); else putenv('BRB_STRIPE_SECRET_KEY=' . $env_secret);
}
echo "RESULT ok=" . $GLOBALS['brb_ok'] . " fail=" . $GLOBALS['brb_fail'] . "\n";
