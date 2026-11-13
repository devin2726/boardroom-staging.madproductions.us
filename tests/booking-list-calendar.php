<?php
/**
 * Run inside WordPress: wp eval-file tests/booking-list-calendar.php
 * All Bookings list: read-only calendar rows, filters, search, dedupe, no store writes.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Load this with wp eval-file\n");
    exit(1);
}

$GLOBALS['brb_fail'] = 0;
$GLOBALS['brb_ok'] = 0;
if (!function_exists('brb_t')) {
    function brb_t($cond, $msg) {
        if ($cond) {
            $GLOBALS['brb_ok']++;
            echo "ok  $msg\n";
        } else {
            $GLOBALS['brb_fail']++;
            echo "FAIL $msg\n";
        }
    }
}

function brb_list_cal_insert_booking($day, $name, $email, $status, $source = 'online') {
    global $wpdb;
    $ok = $wpdb->insert(brb_table(), [
        'token' => wp_generate_password(32, false),
        'mode' => 'day',
        'start_dt' => $day . ' 10:00:00',
        'end_dt' => $day . ' 23:30:00',
        'guests' => 8,
        'name' => $name,
        'email' => $email,
        'phone' => '555-0100',
        'notes' => '',
        'subtotal' => 1200,
        'cleaning' => 0,
        'tax' => 0,
        'total' => 1200,
        'amount_due' => 1200,
        'deposit_due' => 600,
        'amount_paid' => in_array($status, ['deposit_paid', 'paid', 'confirmed'], true) ? 600 : 0,
        'status' => $status,
        'source' => $source,
        'price_set' => 1,
        'created' => current_time('mysql', true),
    ]);
    return $ok ? (int) $wpdb->insert_id : 0;
}

function brb_list_cal_insert_hold($day, $name, $type, $notes = '') {
    global $wpdb;
    $ok = $wpdb->insert(brb_hold_table(), [
        'name' => $name,
        'hold_type' => $type,
        'start_dt' => $day . ' 00:00:00',
        'end_dt' => $day . ' 23:59:00',
        'notes' => $notes,
        'created' => current_time('mysql', true),
    ]);
    return $ok ? (int) $wpdb->insert_id : 0;
}

function brb_list_cal_render($args = []) {
    $_GET = array_merge(['page' => 'brb'], $args);
    $_REQUEST = $_GET;
    ob_start();
    brb_admin_list();
    $html = ob_get_clean();
    $_GET = [];
    $_REQUEST = [];
    $_POST = [];
    return $html;
}

function brb_list_cal_fingerprint() {
    global $wpdb;
    $bookings = $wpdb->get_results('SELECT id, status, start_dt, end_dt, total, amount_paid, name, email FROM ' . brb_table() . ' ORDER BY id ASC', ARRAY_A);
    $holds = $wpdb->get_results('SELECT id, name, hold_type, start_dt, end_dt, notes FROM ' . brb_hold_table() . ' ORDER BY id ASC', ARRAY_A);
    $payments = $wpdb->get_results('SELECT id, booking_id, amount FROM ' . brb_pay_table() . ' ORDER BY id ASC', ARRAY_A);
    return [
        'bookings' => count((array) $bookings),
        'holds' => count((array) $holds),
        'payments' => count((array) $payments),
        'booking_sig' => wp_json_encode($bookings),
        'hold_sig' => wp_json_encode($holds),
        'payment_sig' => wp_json_encode($payments),
        'blackouts' => (string) brb_opt('blackouts'),
    ];
}

global $wpdb;
$snapshot = get_option('brb_settings');
$created_booking_ids = [];
$created_hold_ids = [];
$admin = get_user_by('login', 'admin');
if (!$admin) {
    $admin_id = wp_insert_user([
        'user_login' => 'admin',
        'user_pass' => wp_generate_password(24, true, true),
        'user_email' => 'admin@example.com',
        'role' => 'administrator',
    ]);
    $admin = is_wp_error($admin_id) ? null : get_user_by('id', $admin_id);
}
if ($admin) wp_set_current_user($admin->ID);

try {
    brb_t((bool) $admin && current_user_can('manage_options'), 'an administrator is available for the bookings list');
    brb_t(get_option('brb_version') === '2.5.73', 'plugin storage version is 2.5.73');
    brb_install_hold_table();

    $today = brb_now()->setTime(0, 0);
    $kim_day = $today->modify('+10 days')->format('Y-m-d');
    $sara_day = $today->modify('+11 days')->format('Y-m-d');
    $block_day = $today->modify('+12 days')->format('Y-m-d');
    $past_hold_day = $today->modify('-2 days')->format('Y-m-d');
    $blackout_day = $today->modify('+13 days')->format('Y-m-d');
    $past_blackout = $today->modify('-3 days')->format('Y-m-d');
    $overlap_day = $today->modify('+14 days')->format('Y-m-d');
    $cancel_day = $today->modify('+15 days')->format('Y-m-d');

    $maya_id = brb_list_cal_insert_booking($overlap_day, 'Maya Direct', 'list-cal-maya@example.com', 'deposit_paid', 'phone');
    $cancel_id = brb_list_cal_insert_booking($cancel_day, 'Cancelled Person', 'list-cal-cancel@example.com', 'cancelled', 'walk-in');
    $created_booking_ids = array_filter([$maya_id, $cancel_id]);
    brb_t($maya_id > 0 && $cancel_id > 0, 'seed bookings were inserted');

    $kim_id = brb_list_cal_insert_hold($kim_day, 'Kimberly Townsend', 'reserved');
    $sara_id = brb_list_cal_insert_hold($sara_day, 'Sara', 'hold');
    $secret = 'Airbnb Secret Guest';
    $block_id = brb_list_cal_insert_hold($block_day, $secret, 'blocked', 'do not leak this note');
    $past_id = brb_list_cal_insert_hold($past_hold_day, 'Past Name', 'reserved');
    $overlap_hold_id = brb_list_cal_insert_hold($overlap_day, 'Overlap Hold', 'reserved');
    $kept_id = brb_list_cal_insert_hold($cancel_day, 'Kept After Cancel', 'reserved');
    $created_hold_ids = array_filter([$kim_id, $sara_id, $block_id, $past_id, $overlap_hold_id, $kept_id]);
    brb_t(count($created_hold_ids) === 6, 'seed holds were inserted');

    $opt = brb_opt();
    $opt['blackouts'] = $past_blackout . "\n" . $blackout_day;
    update_option('brb_settings', $opt);
    brb_t(in_array($blackout_day, brb_blackouts(), true) && in_array($past_blackout, brb_blackouts(), true), 'seed blackouts were saved');

    $before = brb_list_cal_fingerprint();

    $all = brb_list_cal_render();
    brb_t(strpos($all, 'Bookings only') !== false && strpos($all, 'Calendar only') !== false, 'the list offers All, Bookings only, and Calendar only filters');
    brb_t(strpos($all, 'Kimberly Townsend') !== false && strpos($all, 'Calendar - Reserved') !== false, 'a reserved hold appears with its name and Calendar - Reserved');
    brb_t(strpos($all, '>Sara<') !== false || strpos($all, '>Sara</a>') !== false, 'an unpaid hold appears by name');
    brb_t(strpos($all, 'Calendar - Hold') !== false, 'an unpaid hold is labeled Calendar - Hold');
    brb_t(strpos($all, 'Calendar - Date block') !== false && strpos($all, 'Date block') !== false, 'blocked holds and blackouts are labeled Date block');
    brb_t(strpos($all, $secret) === false && strpos($all, 'do not leak this note') === false, 'a blocked hold does not show its name or notes');
    brb_t(strpos($all, 'Past Name') === false, 'a past hold is omitted from the default list');
    brb_t(strpos($all, $past_blackout) === false, 'a past blackout is omitted from the default list');
    brb_t(strpos($all, 'Overlap Hold') === false, 'a hold that overlaps a non-cancelled booking is omitted');
    brb_t(strpos($all, 'Kept After Cancel') !== false, 'a hold that overlaps only a cancelled booking still appears');
    brb_t(strpos($all, 'Maya Direct') !== false && strpos($all, 'Phone') !== false, 'existing booking rows still appear with their source');
    brb_t(strpos($all, 'Open') !== false && strpos($all, 'booking=' . $maya_id) !== false, 'booking rows still link to the booking detail');
    brb_t(preg_match('/brb-cal-list-row[\s\S]{0,400}—[\s\S]{0,200}—[\s\S]{0,80}—/', $all) === 1, 'calendar rows show em dashes for total, paid, and balance');
    brb_t(strpos($all, 'page=brb-cal') !== false && (strpos($all, 'hold=' . $kim_id) !== false || strpos($all, 'hold%3D' . $kim_id) !== false), 'calendar rows link to the existing hold editor');
    brb_t(strpos($all, 'blackout=' . $blackout_day) !== false || strpos($all, 'blackout%3D' . $blackout_day) !== false, 'unnamed blackout rows link to the date editor');
    brb_t(preg_match('/brb-cal-list-row[\s\S]{0,80}name="booking_ids\[\]"/', $all) !== 1, 'calendar rows have no bulk-delete checkbox');
    brb_t(strpos($all, 'script>alert') === false, 'the list output does not include raw script tags');
    $cal_rows = preg_match_all('/class="brb-cal-list-row"/', $all);
    brb_t($cal_rows === 5, 'the default list shows five future calendar rows (reserved, hold, date block, blackout, and the cancelled-overlap hold)');
    brb_t(strpos($all, 'brb-pager') === false && strpos($all, 'Page 1 of') === false, 'a short merged list does not show a pager');

    $guest_href = '';
    if (preg_match('/<th><a href="([^"]+)">Guest/', $all, $m)) $guest_href = html_entity_decode($m[1], ENT_QUOTES);
    brb_t($guest_href !== '' && (strpos($guest_href, 'orderby=guest') !== false || strpos($guest_href, 'orderby%3Dguest') !== false), 'the Guest header link carries orderby=guest');
    $guest_asc = brb_list_cal_render(['orderby' => 'guest', 'order' => 'asc']);
    $guest_asc_href = '';
    if (preg_match('/<th><a href="([^"]+)">Guest/', $guest_asc, $m)) $guest_asc_href = html_entity_decode($m[1], ENT_QUOTES);
    brb_t(
        (strpos($guest_asc, 'Guest ↑') !== false || strpos($guest_asc, 'Guest &uarr;') !== false)
        && (strpos($guest_asc_href, 'order=desc') !== false || strpos($guest_asc_href, 'order%3Ddesc') !== false),
        'a Guest sort in ascending order toggles the header to descending'
    );
    $guest_desc = brb_list_cal_render(['orderby' => 'guest', 'order' => 'desc']);
    $guest_desc_href = '';
    if (preg_match('/<th><a href="([^"]+)">Guest/', $guest_desc, $m)) $guest_desc_href = html_entity_decode($m[1], ENT_QUOTES);
    brb_t(
        (strpos($guest_desc, 'Guest ↓') !== false || strpos($guest_desc, 'Guest &darr;') !== false)
        && (strpos($guest_desc_href, 'order=asc') !== false || strpos($guest_desc_href, 'order%3Dasc') !== false),
        'a Guest sort in descending order toggles the header to ascending'
    );

    $bookings_only = brb_list_cal_render(['entries' => 'bookings']);
    brb_t(strpos($bookings_only, 'Kimberly Townsend') === false && strpos($bookings_only, 'Calendar -') === false && strpos($bookings_only, 'Maya Direct') !== false, 'Bookings only hides calendar rows and keeps booking rows');

    $calendar_only = brb_list_cal_render(['entries' => 'calendar']);
    brb_t(strpos($calendar_only, 'Kimberly Townsend') !== false && strpos($calendar_only, 'Maya Direct') === false && strpos($calendar_only, 'Cancelled Person') === false, 'Calendar only shows calendar rows and hides booking rows');
    brb_t(strpos($calendar_only, $secret) === false, 'Calendar only still hides blocked-hold names');

    $search = brb_list_cal_render(['q' => 'Kimberly']);
    brb_t(strpos($search, 'Kimberly Townsend') !== false && strpos($search, 'Sara') === false && strpos($search, 'Maya Direct') === false, 'search matches hold names and does not pull unrelated rows');

    $secret_search = brb_list_cal_render(['q' => $secret]);
    brb_t(preg_match('/class="brb-cal-list-row"/', $secret_search) !== 1 && strpos($secret_search, 'Calendar - Date block') === false, 'search does not match a hidden blocked-hold name');

    $block_search = brb_list_cal_render(['q' => 'Date block']);
    brb_t(strpos($block_search, 'Calendar - Date block') !== false && strpos($block_search, $secret) === false && strpos($block_search, 'Kimberly Townsend') === false, 'searching Date block finds blocks without revealing hidden names');

    $after = brb_list_cal_fingerprint();
    brb_t($before === $after, 'rendering the list does not write bookings, holds, payments, or blackouts');

    $page_ids = [];
    $page_day = $today->modify('+40 days')->format('Y-m-d');
    for ($i = 1; $i <= 50; $i++) {
        $id = brb_list_cal_insert_booking($page_day, sprintf('Page Guest %02d', $i), sprintf('list-cal-page-%02d@example.com', $i), 'review');
        if ($id > 0) $page_ids[] = $id;
    }
    $created_booking_ids = array_merge($created_booking_ids, $page_ids);
    brb_t(count($page_ids) === 50, 'enough extra bookings were inserted to paginate the merged list');
    $page1 = brb_list_cal_render();
    $page2 = brb_list_cal_render(['paged' => 2]);
    $cal1 = preg_match_all('/class="brb-cal-list-row"/', $page1);
    $cal2 = preg_match_all('/class="brb-cal-list-row"/', $page2);
    $book1 = preg_match_all('/class="brb-row-check"/', $page1);
    $book2 = preg_match_all('/class="brb-row-check"/', $page2);
    brb_t(strpos($page1, '57 bookings. Page 1 of 2.') !== false && strpos($page1, 'Next') !== false, 'a merged list over 50 rows shows a pager');
    brb_t(($cal1 + $book1) === 50 && ($cal2 + $book2) === 7 && $page1 !== $page2, 'page 2 differs from page 1 and splits the 57 merged rows 50 then 7');
    $repeat_names = 0;
    foreach (['Kimberly Townsend', 'Sara', 'Kept After Cancel'] as $name) {
        if (strpos($page1, $name) !== false && strpos($page2, $name) !== false) $repeat_names++;
    }
    brb_t($repeat_names === 0 && ($cal1 + $cal2) === 5, 'calendar rows appear once across pages and do not repeat');
} finally {
    if ($created_hold_ids) {
        $in = implode(',', array_map('intval', $created_hold_ids));
        $wpdb->query('DELETE FROM ' . brb_hold_table() . " WHERE id IN ($in)");
    }
    if ($created_booking_ids) {
        $in = implode(',', array_map('intval', $created_booking_ids));
        $wpdb->query('DELETE FROM ' . brb_pay_table() . " WHERE booking_id IN ($in)");
        $wpdb->query('DELETE FROM ' . brb_table() . " WHERE id IN ($in)");
    }
    foreach (['list-cal-maya@example.com', 'list-cal-cancel@example.com'] as $email) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_pay_table() . ' WHERE booking_id IN (SELECT id FROM ' . brb_table() . ' WHERE email=%s)', $email));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_table() . ' WHERE email=%s', $email));
    }
    $wpdb->query("DELETE FROM " . brb_pay_table() . " WHERE booking_id IN (SELECT id FROM " . brb_table() . " WHERE email LIKE 'list-cal-page-%@example.com')");
    $wpdb->query("DELETE FROM " . brb_table() . " WHERE email LIKE 'list-cal-page-%@example.com'");
    $wpdb->query("DELETE FROM " . brb_hold_table() . " WHERE name IN ('Kimberly Townsend','Sara','Airbnb Secret Guest','Past Name','Overlap Hold','Kept After Cancel')");
    if ($snapshot !== false && $snapshot !== null) update_option('brb_settings', $snapshot);
    else delete_option('brb_settings');
}

echo "\n{$GLOBALS['brb_ok']} passed, {$GLOBALS['brb_fail']} failed\n";
exit($GLOBALS['brb_fail'] ? 1 : 0);
