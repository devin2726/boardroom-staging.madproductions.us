<?php
/**
 * Staff follow-up log on bookings. Run with wp eval-file.
 * Save, append-only history, list column, escaping, nonce and capability,
 * and no change to status, payments, holds, Edit history, or email.
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('brb_t')) {
    $GLOBALS['brb_fail'] = 0;
    $GLOBALS['brb_ok'] = 0;
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

$GLOBALS['brb_fu_mail'] = 0;
add_filter('pre_wp_mail', function ($short) {
    $GLOBALS['brb_fu_mail']++;
    return true;
}, 1);

function brb_fu_insert($day, $name, $email, $status) {
    global $wpdb;
    $ok = $wpdb->insert(brb_table(), [
        'token' => wp_generate_password(32, false),
        'mode' => 'day',
        'start_dt' => $day . ' 10:00:00',
        'end_dt' => $day . ' 23:30:00',
        'guests' => 30,
        'name' => $name,
        'email' => $email,
        'phone' => '',
        'notes' => '',
        'subtotal' => 900,
        'total' => 900,
        'amount_due' => 900,
        'deposit_due' => 450,
        'amount_paid' => 0,
        'status' => $status,
        'source' => 'online',
        'price_set' => 1,
        'created' => current_time('mysql', true),
    ]);
    return $ok ? (int) $wpdb->insert_id : 0;
}
function brb_fu_row_without_log($id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . brb_table() . ' WHERE id=%d', $id), ARRAY_A);
    if (is_array($row)) unset($row['follow_up_log']);
    return $row;
}
function brb_fu_capture($fn) {
    ob_start();
    try { $fn(); } finally { $out = ob_get_clean(); }
    return $out;
}

global $wpdb;
// The harness SQLite driver rejects the list's GROUP_CONCAT(... ORDER BY) duplicate
// check (MySQL accepts it); keep that pre-existing noise out of this log.
$kept_show_errors = $wpdb->hide_errors();
$created = [];
$created_user = 0;
$kept_get = $_GET;
$kept_post = $_POST;
$kept_request = $_REQUEST;
try {
    brb_t(get_option('brb_version') === '2.5.78', 'plugin storage version is 2.5.78');
    $col = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM `' . brb_table() . '` LIKE %s', 'follow_up_log'));
    brb_t((bool) $col, 'the upgrade adds the follow_up_log column to the bookings table');

    $admin = get_user_by('login', 'brb_followup_max');
    if (!$admin) {
        $created_user = wp_insert_user(['user_login' => 'brb_followup_max', 'user_pass' => wp_generate_password(24), 'user_email' => 'followup-max@example.com', 'display_name' => 'Max', 'role' => 'administrator']);
        $admin = is_wp_error($created_user) ? null : get_user_by('id', $created_user);
    }
    if ($admin) wp_set_current_user($admin->ID);
    brb_t($admin && current_user_can('manage_options'), 'an administrator named Max is signed in');

    $day = brb_now()->modify('+40 days')->format('Y-m-d');
    $id = brb_fu_insert($day, 'Followup Guest', 'followup-guest@example.com', 'review');
    $created[] = $id;
    $other = brb_fu_insert(brb_now()->modify('+41 days')->format('Y-m-d'), 'Other Guest', 'followup-other@example.com', 'approved');
    $created[] = $other;
    brb_t($id > 0 && $other > 0, 'two test bookings are created');
    $b = brb_booking($id);
    $ref = brb_ref($b);
    $today = brb_now()->format('Y-m-d');
    $today_short = brb_now()->format('M j');

    // Statuses and the empty state.
    brb_t(array_values(brb_followup_statuses()) === ['Not contacted', 'Contacted', 'Tour offered', 'Tour scheduled', 'Waiting on guest', 'Follow up needed', 'Closed'], 'the follow-up statuses are the seven agreed labels in order');
    brb_t(brb_followup_entries($b) === [] && brb_followup_latest($b) === null, 'a new booking has no follow-up entries');

    // Detail screen placement and defaults.
    $detail = brb_fu_capture(function () use ($id) { brb_admin_detail($id); });
    $p_hold = strpos($detail, 'Date hold');
    $p_box = strpos($detail, '<h2 id="brb-followup">Follow-up</h2>');
    $p_money = strpos($detail, '<h2>Money</h2>');
    $p_edit = strpos($detail, '<h2>Edit history</h2>');
    $p_save = strpos($detail, '<h2>Save changes</h2>');
    brb_t($p_hold !== false && $p_box !== false && $p_money !== false && $p_hold < $p_box && $p_box < $p_money, 'the Follow-up box sits under the details table and above Money');
    brb_t($p_edit !== false && $p_save !== false && $p_box < $p_edit && $p_box < $p_save, 'the Follow-up box is above Edit history and Save changes');
    $box = substr($detail, $p_box, $p_money - $p_box);
    brb_t(strpos($box, 'name="action" value="brb_followup"') !== false && strpos($box, 'brb_save_changes') === false && substr_count($box, '<form') === 1 && strpos($box, '>Save follow-up</button>') !== false, 'the Follow-up box is its own form with its own Save follow-up button');
    brb_t(strpos($box, 'name="_wpnonce"') !== false && preg_match('/name="_wpnonce" value="([^"]+)"/', $box, $nm) && wp_verify_nonce($nm[1], 'brb_followup_' . $id), 'the Follow-up form carries a nonce for this booking');
    brb_t(preg_match('/<option value="not_contacted" selected=\'selected\'>Not contacted<\/option>/', $box) === 1, 'the status defaults to Not contacted');
    brb_t(strpos($box, 'name="followup_date" value="' . $today . '"') !== false, 'the date defaults to today in America/Chicago');
    brb_t(strpos($box, 'name="followup_by" maxlength="60" value="Max"') !== false, 'By fills in the signed-in user\'s display name');
    brb_t(strpos($box, 'placeholder="e.g. Emailed asking about a tour"') !== false && strpos($box, 'Note (optional)') !== false, 'the note is optional with an example');
    brb_t(strpos($box, 'No follow-up yet.') !== false && strpos($detail, 'No edits yet.') !== false, 'Follow-up history and Edit history are separate and both empty');

    // Save one entry.
    $before = brb_fu_row_without_log($id);
    $other_before = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . brb_table() . ' WHERE id=%d', $other), ARRAY_A);
    $holds_before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . brb_hold_table());
    $pays_before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . brb_pay_table());
    $mail_before = $GLOBALS['brb_fu_mail'];
    $e1 = brb_followup_add($id, ['followup_status' => 'contacted', 'followup_date' => $today, 'followup_by' => '', 'followup_note' => 'Emailed asking about a tour']);
    brb_t(is_array($e1) && $e1['status'] === 'contacted' && $e1['date'] === $today && $e1['by'] === 'Max' && $e1['note'] === 'Emailed asking about a tour', 'saving Contacted stores status, date, by, and note');
    brb_t(brb_followup_summary($e1) === 'Contacted · ' . $today_short . ' · Max', 'the summary reads "Contacted · ' . $today_short . ' · Max"');
    brb_t(brb_fu_row_without_log($id) === $before, 'saving a follow-up changes no other booking field (status, money, audit log)');
    brb_t(brb_booking($id)->status === 'review', 'the booking stays Pending review');
    brb_t($wpdb->get_row($wpdb->prepare('SELECT * FROM ' . brb_table() . ' WHERE id=%d', $other), ARRAY_A) === $other_before, 'another booking is untouched');
    brb_t((int) $wpdb->get_var('SELECT COUNT(*) FROM ' . brb_hold_table()) === $holds_before && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . brb_pay_table()) === $pays_before, 'no holds or payments are added');
    brb_t($GLOBALS['brb_fu_mail'] === $mail_before, 'saving a follow-up sends no email');

    // Append, never overwrite.
    $e2 = brb_followup_add($id, ['followup_status' => 'tour_scheduled', 'followup_date' => $today, 'followup_by' => 'Charlie', 'followup_note' => 'Tour set for Saturday']);
    $all = brb_followup_entries(brb_booking($id));
    brb_t(is_array($e2) && count($all) === 2 && $all[0]['status'] === 'tour_scheduled' && $all[1]['status'] === 'contacted' && $all[1]['note'] === 'Emailed asking about a tour', 'a second save appends; history keeps both, newest first');
    brb_t(brb_followup_latest(brb_booking($id))['by'] === 'Charlie', 'the latest entry is the newest save');

    // Bad input is rejected and appends nothing.
    $bad1 = brb_followup_add($id, ['followup_status' => 'approved', 'followup_date' => $today]);
    $bad2 = brb_followup_add($id, ['followup_status' => 'contacted', 'followup_date' => '2026-02-30']);
    $bad3 = brb_followup_add(999999, ['followup_status' => 'contacted']);
    brb_t(is_wp_error($bad1) && is_wp_error($bad2) && is_wp_error($bad3) && count(brb_followup_entries(brb_booking($id))) === 2, 'an unknown status, a bad date, or a missing booking is rejected and adds nothing');
    $blank_date = brb_followup_add($other, ['followup_status' => 'waiting_guest', 'followup_date' => '', 'followup_by' => '', 'followup_note' => '']);
    brb_t(is_array($blank_date) && $blank_date['date'] === $today && $blank_date['by'] === 'Max' && $blank_date['note'] === '', 'a blank date and by fall back to today and the signed-in user');
    $long = brb_followup_add($other, ['followup_status' => 'follow_up', 'followup_note' => str_repeat('x', 500), 'followup_by' => str_repeat('y', 100)]);
    brb_t(is_array($long) && strlen($long['note']) === 200 && strlen($long['by']) === 60, 'note and by are capped at 200 and 60 characters');

    // Sanitizing on save and escaping on output.
    $xss = brb_followup_add($id, ['followup_status' => 'follow_up', 'followup_by' => '<b>Eve</b>', 'followup_note' => 'Call back <script>alert(1)</script> "soon" & later']);
    brb_t(is_array($xss) && strpos($xss['note'], '<script') === false && strpos($xss['by'], '<b>') === false, 'tags are stripped from by and note on save');
    // Even a hand-edited row with markup is printed escaped.
    $log = json_decode((string) brb_booking($id)->follow_up_log, true);
    $log[] = ['status' => 'contacted', 'date' => $today, 'by' => '<img src=x onerror=alert(2)>', 'note' => '<script>alert(3)</script>', 'user_id' => 0, 'saved' => brb_now()->format('c')];
    $wpdb->update(brb_table(), ['follow_up_log' => wp_json_encode($log)], ['id' => $id]);
    $detail2 = brb_fu_capture(function () use ($id) { brb_admin_detail($id); });
    brb_t(strpos($detail2, '<img src=x') === false && strpos($detail2, '<script>alert(3)') === false && strpos($detail2, '&lt;img src=x onerror=alert(2)&gt;') !== false && strpos($detail2, '&lt;script&gt;alert(3)&lt;/script&gt;') !== false, 'stored markup is escaped in the follow-up history');
    $p_hist = strpos($detail2, 'Follow-up history');
    $p_money2 = strpos($detail2, '<h2>Money</h2>');
    $hist = substr($detail2, $p_hist, $p_money2 - $p_hist);
    brb_t(substr_count($hist, '<li>') === 4 && strpos($hist, 'Tour scheduled · ' . $today_short . ' · Charlie — Tour set for Saturday') !== false && strpos($hist, 'Contacted · ' . $today_short . ' · Max — Emailed asking about a tour') !== false && strpos($hist, 'Tour scheduled') < strpos($hist, 'Contacted · ' . $today_short . ' · Max'), 'the history under the box lists every entry, newest first');
    brb_t(strpos($detail2, 'No edits yet.') !== false, 'follow-up saves do not write to Edit history');
    brb_t(preg_match('/<option value="contacted" selected=\'selected\'>Contacted<\/option>/', $detail2) === 1, 'the dropdown starts on the latest follow-up status');

    // Bookings list column.
    $_GET = ['page' => 'brb', 'entries' => 'bookings', 'q' => $ref];
    $list = brb_fu_capture('brb_admin_list');
    brb_t(strpos($list, '>Follow-up') !== false && preg_match('/Status[^<]*<\/a><\/th><th><a href="[^"]*orderby=followup[^"]*">Follow-up<\/a><\/th><th>Reserved<\/th>/', $list) === 1, 'the Bookings list has a sortable Follow-up column after Status');
    brb_t(strpos($list, '<span class="brb-followup-latest">Contacted · ' . $today_short . ' · &lt;img src=x onerror=alert(2)&gt;</span>') !== false && strpos($list, '<img src=x') === false, 'the list shows the latest entry, escaped');
    $wpdb->update(brb_table(), ['follow_up_log' => wp_json_encode(array_slice($log, 0, 2))], ['id' => $id]);
    $list = brb_fu_capture('brb_admin_list');
    brb_t(strpos($list, '<span class="brb-followup-latest">Tour scheduled · ' . $today_short . ' · Charlie</span>') !== false, 'the list cell reads like "Tour scheduled · ' . $today_short . ' · Charlie"');
    $_GET = ['page' => 'brb', 'entries' => 'bookings', 'q' => 'Followup Guest'];
    $fresh_id = brb_fu_insert(brb_now()->modify('+42 days')->format('Y-m-d'), 'Followup Guest Two', 'followup-two@example.com', 'review');
    $created[] = $fresh_id;
    $list = brb_fu_capture('brb_admin_list');
    brb_t(strpos($list, '<span class="brb-followup-none">Not contacted</span>') !== false, 'a booking with no entries shows Not contacted');
    $_GET = ['page' => 'brb', 'entries' => 'bookings', 'q' => 'Followup Guest', 'orderby' => 'followup', 'order' => 'desc'];
    $sorted = brb_fu_capture('brb_admin_list');
    brb_t(strpos($sorted, brb_ref(brb_booking($id)) . '</td>') !== false && strpos($sorted, brb_ref(brb_booking($id)) . '</td>') < strpos($sorted, brb_ref(brb_booking($fresh_id)) . '</td>'), 'sorting by Follow-up puts followed-up bookings first (desc)');
    $_GET = ['page' => 'brb'];
    $all_list = brb_fu_capture('brb_admin_list');
    brb_t(preg_match('/<tr><th scope="row" class="check-column">.*?<\/tr>/s', $all_list, $row_m) && substr_count($row_m[0], '<td') === 11, 'booking rows have 12 cells including the checkbox');

    // The admin-post handler: capability, nonce, save, and redirect.
    $died = false;
    $die_handler = function () use (&$died) {
        return function () use (&$died) { $died = true; throw new RuntimeException('stopped'); };
    };
    add_filter('wp_die_handler', $die_handler);
    $redirect = '';
    $redirect_filter = function ($location) use (&$redirect) { $redirect = $location; throw new RuntimeException('redirect'); };
    add_filter('wp_redirect', $redirect_filter, 1);
    $count = count(brb_followup_entries(brb_booking($id)));
    $kept_user = get_current_user_id();
    wp_set_current_user(0);
    $_POST = ['action' => 'brb_followup', 'booking' => (string) $id, 'followup_status' => 'closed', 'followup_date' => $today];
    $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('brb_followup_' . $id)];
    try { brb_followup_handle_post(); } catch (RuntimeException $e) {}
    brb_t($died && count(brb_followup_entries(brb_booking($id))) === $count, 'a logged-out save is rejected and adds nothing');
    wp_set_current_user($kept_user);
    $died = false;
    $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('brb_followup_' . $other)];
    try { brb_followup_handle_post(); } catch (RuntimeException $e) {}
    brb_t($died && count(brb_followup_entries(brb_booking($id))) === $count, 'a nonce for another booking is rejected');
    $died = false;
    $_POST['followup_note'] = 'Guest said they booked elsewhere';
    $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce('brb_followup_' . $id)];
    $mail_before = $GLOBALS['brb_fu_mail'];
    try { brb_followup_handle_post(); } catch (RuntimeException $e) {}
    $latest = brb_followup_latest(brb_booking($id));
    brb_t(!$died && $latest['status'] === 'closed' && $latest['note'] === 'Guest said they booked elsewhere' && count(brb_followup_entries(brb_booking($id))) === $count + 1, 'an administrator with the right nonce saves through admin-post');
    brb_t(strpos($redirect, 'page=brb') !== false && strpos($redirect, 'booking=' . $id) !== false && strpos(urldecode($redirect), 'No email was sent.') !== false, 'the save returns to the booking with a "No email was sent." note');
    brb_t(brb_booking($id)->status === 'review' && $GLOBALS['brb_fu_mail'] === $mail_before, 'Closed as a follow-up does not change the booking status or send email');
    remove_filter('wp_die_handler', $die_handler);
    remove_filter('wp_redirect', $redirect_filter, 1);
} finally {
    $wpdb->show_errors($kept_show_errors);
    $_GET = $kept_get;
    $_POST = $kept_post;
    $_REQUEST = $kept_request;
    foreach ($created as $cid) if ($cid) $wpdb->delete(brb_table(), ['id' => (int) $cid]);
    if ($created_user && !is_wp_error($created_user)) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user((int) $created_user);
    }
    echo 'ok ' . (int) $GLOBALS['brb_ok'] . ' fail ' . (int) $GLOBALS['brb_fail'] . "\n";
}
