<?php
/**
 * Blocked addresses (2.5.80): contiveros8@gmail.com and stephens818@gmail.com
 * never receive any Boardroom email. Every recipient list, staff copies
 * (including the price email's), CC/BCC/Reply-To headers, raw wp_mail calls,
 * the settings save, and the upgrade all drop them. Mail is intercepted;
 * nothing is sent.
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

// Capture what wp_mail would send, after the blocklist filters ran. A message
// the blocklist stops (pre_wp_mail === false) is recorded as stopped.
$GLOBALS['brb_be_mail'] = [];
add_filter('pre_wp_mail', function ($pre, $atts) {
    $GLOBALS['brb_be_mail'][] = ['stopped' => $pre === false, 'atts' => $atts];
    return $pre === false ? false : true;
}, PHP_INT_MAX, 2);
function brb_be_sent_blob($from = 0) {
    $out = '';
    foreach (array_slice($GLOBALS['brb_be_mail'], $from) as $m) {
        if ($m['stopped']) continue;
        $a = $m['atts'];
        $out .= 'TO ' . implode(',', (array) $a['to']) . "\n" . (is_array($a['headers']) ? implode("\n", $a['headers']) : (string) $a['headers']) . "\n";
    }
    return strtolower($out);
}
function brb_be_has_blocked($blob) {
    return strpos($blob, 'stephens818@gmail.com') !== false || strpos($blob, 'contiveros8@gmail.com') !== false;
}

global $wpdb;
$kept_show = $wpdb->hide_errors();
$saved_opt = get_option('brb_settings');
$saved_wp_admin = get_option('admin_email');
$created = [];
try {
    brb_t(get_option('brb_version') === '2.5.80', 'plugin storage version is 2.5.80');
    $list = brb_blocked_inquiry_cc();
    brb_t(is_array($list) && in_array('contiveros8@gmail.com', $list, true) && in_array('stephens818@gmail.com', $list, true), 'the blocklist holds contiveros8@gmail.com and stephens818@gmail.com');
    brb_t(brb_is_blocked_email('Stephens818@Gmail.com') && brb_is_blocked_email(' contiveros8@gmail.com ') && !brb_is_blocked_email('devin2726@gmail.com') && !brb_is_blocked_email(''), 'blocked check is case-insensitive and leaves other addresses alone');

    $o = is_array($saved_opt) ? $saved_opt : brb_opt();
    $o['admin_email'] = "devin2726@gmail.com, Stephens818@gmail.com; contiveros8@gmail.com";
    $o['inquiry_cc'] = "kept@example.com\nstephens818@gmail.com";
    $o['tour_email'] = 'stephens818@gmail.com';
    $o['weekly_summary'] = '1';
    $o['weekly_summary_emails'] = "stephens818@gmail.com, team@example.com";
    update_option('brb_settings', $o);

    $admins = array_map('strtolower', brb_admin_emails());
    brb_t($admins === ['devin2726@gmail.com'], 'admin_email: staff list drops both blocked addresses and keeps Devin');
    brb_t(array_map('strtolower', brb_inquiry_cc_list()) === ['kept@example.com'], 'inquiry_cc: CC list drops stephens818 and keeps the rest');
    $tour = array_map('strtolower', brb_tour_recipients());
    brb_t(!in_array('stephens818@gmail.com', $tour, true) && in_array('boardroomqc@gmail.com', $tour, true) && in_array('devin2726@gmail.com', $tour, true), 'tour_email: a blocked inquiry address falls back to boardroomqc@gmail.com');

    $n = count($GLOBALS['brb_be_mail']);
    brb_weekly_summary_send();
    $blob = brb_be_sent_blob($n);
    brb_t(strpos($blob, 'team@example.com') !== false && !brb_be_has_blocked($blob), 'weekly_summary_emails: the weekly summary skips stephens818 and still reaches the team');

    $n = count($GLOBALS['brb_be_mail']);
    brb_mail_staff('Staff test', 'Body', 'guest@example.com');
    $blob = brb_be_sent_blob($n);
    brb_t(strpos($blob, 'devin2726@gmail.com') !== false && !brb_be_has_blocked($blob), 'staff copies never go to a blocked address');

    // The price email's staff copy (the BR-6 path).
    $wpdb->insert(brb_table(), ['token' => wp_generate_password(32, false), 'mode' => 'day', 'start_dt' => '2027-06-12 10:00:00', 'end_dt' => '2027-06-12 23:30:00', 'guests' => 30, 'name' => 'Block Test Guest', 'email' => 'block-guest@example.com', 'phone' => '', 'notes' => '', 'status' => 'review', 'source' => 'online', 'created' => current_time('mysql', true)]);
    $id = (int) $wpdb->insert_id;
    $created[] = $id;
    brb_set_agreed_price($id, 850, false);
    $n = count($GLOBALS['brb_be_mail']);
    brb_send_price_email(brb_booking($id));
    $blob = brb_be_sent_blob($n);
    brb_t(strpos($blob, 'block-guest@example.com') !== false && strpos($blob, 'devin2726@gmail.com') !== false && !brb_be_has_blocked($blob), 'price email: the guest and Devin get it; no blocked staff copy');

    $n = count($GLOBALS['brb_be_mail']);
    $ok = brb_mail('stephens818@gmail.com', 'Direct', 'Body');
    brb_t($ok === false && count($GLOBALS['brb_be_mail']) === $n, 'brb_mail refuses a blocked To');
    $n = count($GLOBALS['brb_be_mail']);
    brb_mail('guest@example.com', 'Copy', 'Body', 'stephens818@gmail.com', '', ['stephens818@gmail.com', 'kept@example.com']);
    $blob = brb_be_sent_blob($n);
    brb_t(strpos($blob, 'cc: kept@example.com') !== false && !brb_be_has_blocked($blob), 'brb_mail drops blocked CC and Reply-To and keeps the rest');

    // Send layer: raw wp_mail from any path.
    $n = count($GLOBALS['brb_be_mail']);
    wp_mail(['Stephens818@gmail.com', 'ok@example.com'], 'Raw', 'Body', ['Cc: stephens818@gmail.com, cc-ok@example.com', 'Bcc: Steph <stephens818@gmail.com>', 'Reply-To: contiveros8@gmail.com']);
    $blob = brb_be_sent_blob($n);
    brb_t(strpos($blob, 'to ok@example.com') !== false && strpos($blob, 'cc: cc-ok@example.com') !== false && strpos($blob, 'bcc:') === false && strpos($blob, 'reply-to:') === false && !brb_be_has_blocked($blob), 'wp_mail: blocked To, CC, BCC, and Reply-To entries are removed (array headers)');
    $n = count($GLOBALS['brb_be_mail']);
    wp_mail('ok@example.com, stephens818@gmail.com', 'Raw string', 'Body', "From: The Boardroom <boardroomqc@gmail.com>\r\nBcc: stephens818@gmail.com\r\nCc: contiveros8@gmail.com");
    $blob = brb_be_sent_blob($n);
    brb_t(strpos($blob, 'to ok@example.com') !== false && strpos($blob, 'from: the boardroom') !== false && !brb_be_has_blocked($blob), 'wp_mail: string To and string headers are cleaned too');
    $n = count($GLOBALS['brb_be_mail']);
    $res = wp_mail('stephens818@gmail.com', 'Only blocked', 'Body');
    $last = end($GLOBALS['brb_be_mail']);
    brb_t($res === false && count($GLOBALS['brb_be_mail']) === $n + 1 && $last['stopped'], 'wp_mail: a message to only a blocked address is not sent');

    // WordPress's own admin email as the last-resort fallback.
    $o2 = brb_opt();
    $o2['admin_email'] = 'stephens818@gmail.com';
    update_option('brb_settings', $o2);
    update_option('admin_email', 'stephens818@gmail.com');
    brb_t(brb_admin_emails() === [], 'admin fallback: a blocked site admin email is not used either');
    update_option('admin_email', $saved_wp_admin);

    // Upgrade drops blocked addresses from every saved recipient setting.
    $o3 = brb_opt();
    $o3['admin_email'] = "devin2726@gmail.com\nstephens818@gmail.com";
    $o3['inquiry_cc'] = "STEPHENS818@gmail.com, kept@example.com, contiveros8@gmail.com";
    $o3['tour_email'] = 'stephens818@gmail.com';
    $o3['weekly_summary_emails'] = 'team@example.com;stephens818@gmail.com';
    update_option('brb_settings', $o3);
    brb_drop_blocked_emails();
    $s = get_option('brb_settings');
    $all = strtolower($s['admin_email'] . ' ' . $s['inquiry_cc'] . ' ' . $s['tour_email'] . ' ' . $s['weekly_summary_emails']);
    brb_t(!brb_be_has_blocked($all), 'upgrade: no saved recipient setting still holds a blocked address');
    brb_t(strpos($s['admin_email'], 'devin2726@gmail.com') !== false && strpos($s['inquiry_cc'], 'kept@example.com') !== false && strpos($s['weekly_summary_emails'], 'team@example.com') !== false && $s['tour_email'] === 'boardroomqc@gmail.com', 'upgrade: other addresses are kept and tour_email falls back to boardroomqc@gmail.com');
    brb_drop_blocked_emails();
    brb_t(get_option('brb_settings') === $s, 'upgrade: running it twice changes nothing');
    brb_drop_blocked_inquiry_cc();
    brb_t(get_option('brb_settings') === $s, 'the old upgrade name still works and changes nothing more');

    // Settings save helper.
    brb_t(brb_strip_blocked_text("a@example.com, stephens818@gmail.com")[0] === 'a@example.com' && brb_strip_blocked_text('a@example.com, b@example.com') === ['a@example.com, b@example.com', false], 'settings save: blocked addresses are stripped and clean lists are left as typed');
} finally {
    foreach ($created as $cid) {
        $wpdb->delete(brb_line_table(), ['booking_id' => (int) $cid]);
        $wpdb->delete(brb_table(), ['id' => (int) $cid]);
    }
    update_option('brb_settings', $saved_opt);
    update_option('admin_email', $saved_wp_admin);
    $wpdb->show_errors($kept_show);
    echo 'ok ' . (int) $GLOBALS['brb_ok'] . ' fail ' . (int) $GLOBALS['brb_fail'] . "\n";
}
