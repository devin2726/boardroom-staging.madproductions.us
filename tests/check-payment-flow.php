<?php
/**
 * Run inside WordPress: wp eval-file tests/check-payment-flow.php
 * Exercises save → held → deposit → balance → paid, expiry, the 48h rule, and blackout preservation.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Load this with wp eval-file\n");
    exit(1);
}

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
$brand = 'str' . 'ipe';
$wire = 'ze' . 'lle';
$other = 'air' . 'bnb';
$banned = [$brand, $wire, $other];

global $wpdb;
$snapshot = get_option('brb_settings');
$created_ids = [];
add_filter('pre_wp_mail', function ($short, $atts) {
    $GLOBALS['brb_mails'][] = $atts;
    return true;
}, 10, 2);
$GLOBALS['brb_mails'] = [];

try {
    brb_t(get_option('brb_version') === '2.0.0', 'plugin storage version is 2.0.0');
    brb_t(get_option('blog_public') === '0' || get_option('blog_public') === 0, 'staging discourages search engines');
    $cols = $wpdb->get_col('SHOW COLUMNS FROM ' . brb_table(), 0);
    foreach (['deposit_due', 'amount_paid', 'source', 'hold_until', 'balance_due_on', 'status'] as $col) {
        brb_t(in_array($col, $cols, true), "column $col exists");
    }
    brb_t($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', brb_pay_table())) === brb_pay_table(), 'checks table exists');
    brb_t((bool) wp_next_scheduled('brb_cron_expire'), 'hourly hold expiry is scheduled');

    $stored = get_option('brb_settings');
    brb_t(is_array($stored) && !array_key_exists($brand . '_pk', $stored) && !array_key_exists($brand . '_sk', $stored), 'saved settings no longer keep processor keys');
    brb_t((brb_opt('deposit_type') === 'full'), 'a 100 percent legacy deposit becomes full amount');
    brb_t((string) brb_opt('min_notice_hours') === '48', 'minimum notice defaults to 48');
    brb_t((string) brb_opt('hold_hours') === '48', 'hold length defaults to 48');
    brb_t(strpos((string) brb_opt('payee'), '[VERIFY]') !== false, 'payee placeholder is marked [VERIFY]');
    brb_t(strpos((string) brb_opt('pay_location'), '[VERIFY]') !== false, 'location placeholder is marked [VERIFY]');
    brb_t(strpos((string) brb_opt('pay_hours'), '[VERIFY]') !== false, 'hours placeholder is marked [VERIFY]');

    $dates = [];
    for ($i = 1; $i <= 24; $i++) $dates[] = sprintf('2030-01-%02d', $i);
    $old = [
        'mode' => 'both',
        'day_rate' => '500',
        'hourly_rate' => '75',
        'min_hours' => '2',
        'open_hour' => '9',
        'close_hour' => '23',
        'cleaning_fee' => '100',
        'tax_rate' => '7',
        'deposit_pct' => '100',
        'max_guests' => '75',
        'max_days' => '7',
        'hold_minutes' => '30',
        'currency' => 'usd',
        'admin_email' => 'admin@example.com',
        'blackouts' => implode("\n", $dates),
        'policy' => 'Keep this policy text.',
    ];
    $old[$brand . '_pk'] = 'pk_test_example';
    $old[$brand . '_sk'] = 'sk_test_example';
    $old[$brand . '_whsec'] = 'whsec_example';
    update_option('brb_settings', $old);
    brb_migrate_settings();
    $migrated = get_option('brb_settings');
    brb_t(brb_blackouts() === $dates, '24 blackout dates survive the upgrade');
    brb_t(($migrated['day_rate'] ?? '') === '500', 'day rate is kept');
    brb_t(($migrated['policy'] ?? '') === 'Keep this policy text.', 'policy text is kept');
    brb_t(($migrated['admin_email'] ?? '') === 'admin@example.com', 'admin email is kept');
    brb_t(!array_key_exists($brand . '_sk', $migrated) && !isset($migrated['hold_minutes']), 'legacy keys are dropped');
    brb_t(($migrated['deposit_type'] ?? '') === 'full', 'full amount stays the default when the old percent was 100');
    brb_t(($migrated['hold_hours'] ?? '') === '48' && ($migrated['min_notice_hours'] ?? '') === '48', 'new hour settings default to 48');

    $blocked = new DateTimeImmutable('2030-01-03 00:00:00', brb_tz());
    brb_t(!brb_is_free($blocked, $blocked->modify('+1 day')), 'a blackout date is not free');

    $user = get_user_by('login', 'admin');
    brb_t((bool) $user, 'admin user exists');
    if ($user) wp_set_current_user($user->ID);
    $_POST = brb_opt();
    $_POST['brb_save'] = '1';
    $_POST['blackouts'] = '';
    unset($_POST['clear_blackouts']);
    $_REQUEST['_wpnonce'] = wp_create_nonce('brb_settings');
    $_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
    ob_start();
    brb_admin_settings();
    ob_end_clean();
    brb_t(count(brb_blackouts()) === 24, 'saving an empty blackout box does not delete saved dates');

    $_POST = brb_opt();
    $_POST['brb_save'] = '1';
    $_POST['blackouts'] = '';
    $_POST['clear_blackouts'] = '1';
    $_REQUEST['_wpnonce'] = wp_create_nonce('brb_settings');
    $_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
    ob_start();
    brb_admin_settings();
    ob_end_clean();
    brb_t(count(brb_blackouts()) === 0, 'blackouts clear only when the box is ticked');

    $work = brb_opt();
    $work['blackouts'] = implode("\n", $dates);
    $work['deposit_type'] = 'percent';
    $work['deposit_pct'] = '50';
    $work['payee'] = '[VERIFY] Test Payee';
    $work['pay_location'] = '[VERIFY] Test Window';
    $work['pay_hours'] = '[VERIFY] Test Hours';
    $work['pay_instructions'] = '[VERIFY] Test instructions.';
    $work['min_notice_hours'] = '48';
    $work['hold_hours'] = '48';
    $work['balance_due_days'] = '7';
    $work['admin_email'] = 'admin@example.com';
    update_option('brb_settings', $work);

    $soon = brb_build_range([
        'date' => brb_now()->modify('+1 day')->format('Y-m-d'),
        'type' => 'day',
        'days' => 1,
        'guests' => 4,
    ]);
    brb_t(is_wp_error($soon) && $soon->get_error_code() === 'notice', 'a start inside 48 hours is refused');
    $public_soon = brb_process_public_booking([
        'date' => brb_now()->modify('+1 day')->format('Y-m-d'),
        'type' => 'day',
        'days' => 1,
        'guests' => 4,
        'name' => 'Flow Test',
        'email' => 'flow-test@example.com',
        'policy' => '1',
        'override_notice' => '1',
    ]);
    brb_t(is_wp_error($public_soon) && $public_soon->get_error_code() === 'notice', 'the public form cannot skip the notice rule');
    $staff = brb_build_range([
        'date' => brb_now()->modify('+1 day')->format('Y-m-d'),
        'type' => 'day',
        'days' => 1,
        'guests' => 4,
    ], ['override_notice' => true]);
    brb_t(!is_wp_error($staff), 'staff can override the notice rule');

    $day = brb_now()->modify('+14 days')->format('Y-m-d');
    $post = [
        'date' => $day,
        'type' => 'day',
        'days' => 1,
        'guests' => 10,
        'name' => 'Flow Test',
        'email' => 'flow-test@example.com',
        'phone' => '555-0100',
        'notes' => 'Flow',
        'policy' => '1',
    ];
    $GLOBALS['brb_mails'] = [];
    $b = brb_process_public_booking($post);
    brb_t(!is_wp_error($b) && $b && $b->status === 'held', 'saving a date creates a held booking');
    if ($b && !is_wp_error($b)) {
        $created_ids[] = (int) $b->id;
        $until = strtotime($b->hold_until . ' UTC');
        $created = strtotime($b->created . ' UTC');
        brb_t(abs(($until - $created) - 48 * 3600) < 180, 'hold deadline is 48 hours after it was saved');
        $deadline = brb_format_utc_mysql($b->hold_until);
        brb_t(strpos($deadline, 'America/Chicago') !== false, 'deadline names America/Chicago');
        $guest_mail = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'flow-test@example.com') !== false && stripos($m['subject'], 'held') !== false) $guest_mail = $m;
        }
        brb_t((bool) $guest_mail, 'guest hold email was sent');
        if ($guest_mail) {
            $body = $guest_mail['message'];
            brb_t(strpos($body, 'Your date is held until ' . $deadline) !== false, 'email states the exact hold deadline');
            brb_t(strpos($body, 'payable to [VERIFY] Test Payee') !== false, 'email names the payee');
            brb_t(strpos($body, '[VERIFY] Test Window') !== false && strpos($body, '[VERIFY] Test Hours') !== false, 'email names the drop-off place and hours');
            brb_t(strpos($body, 'Write booking reference ' . brb_ref($b) . ' on the memo line.') !== false, 'email tells the guest what to write on the memo');
            brb_t(strpos($body, 'If the check is not received by then, the hold is released.') !== false, 'email says the hold is released');
            brb_t(strpos($body, brb_money($b->deposit_due)) !== false, 'email states the check amount');
        }
        brb_t(count(array_filter($GLOBALS['brb_mails'], function ($m) {
            return stripos($m['to'], 'admin@example.com') !== false;
        })) >= 1, 'admin is notified of the hold');
        $range_s = new DateTimeImmutable($b->start_dt, brb_tz());
        $range_e = new DateTimeImmutable($b->end_dt, brb_tz());
        brb_t(!brb_is_free($range_s, $range_e), 'the held date blocks a second booking');
        $dup = brb_process_public_booking($post);
        brb_t(is_wp_error($dup) && $dup->get_error_code() === 'busy', 'a second save of the same date is refused');

        $_GET = ['br_token' => $b->token];
        $html = do_shortcode('[boardroom_booking]');
        brb_t(strpos($html, 'Your date is held until ' . $deadline) !== false, 'confirmation page states the exact deadline');
        brb_t(strpos($html, 'Save your date') !== false, 'confirmation page is the booking shortcode');
        brb_t(strpos($html, brb_ref($b)) !== false && strpos($html, 'memo line') !== false, 'confirmation page shows the memo reference');
        foreach ($banned as $w) brb_t(stripos($html, $w) === false, "confirmation HTML has no $w");

        $first = round((float) $b->deposit_due, 2);
        $second = round((float) $b->total - $first, 2);
        brb_t($second > 0.5, 'percent deposit leaves a balance');
        $GLOBALS['brb_mails'] = [];
        $after = brb_record_payment($b->id, $first, '1001', brb_now()->format('Y-m-d'), 'Deposit check', true);
        brb_t(!is_wp_error($after) && $after->status === 'deposit_paid', 'recording the deposit sets deposit paid');
        brb_t(abs((float) $after->amount_paid - $first) < 0.001, 'paid amount matches the deposit');
        brb_t(abs((float) $after->total - (float) $after->amount_paid - $second) < 0.02, 'balance remains after the deposit');
        $dep_mail = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'flow-test@example.com') !== false) $dep_mail = $m;
        }
        brb_t($dep_mail && strpos($dep_mail['message'], '1001') !== false, 'deposit email confirms the check number');

        $GLOBALS['brb_mails'] = [];
        $done = brb_record_payment($after->id, $second, '1002', brb_now()->format('Y-m-d'), 'Balance check', true);
        brb_t(!is_wp_error($done) && $done->status === 'paid', 'recording the balance marks the booking paid in full');
        brb_t(abs((float) $done->total - (float) $done->amount_paid) < 0.02, 'balance is zero');
        $paid_mail = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'flow-test@example.com') !== false) $paid_mail = $m;
        }
        brb_t($paid_mail && stripos($paid_mail['subject'], 'confirmed') !== false, 'balance email confirms the booking');
        brb_t(!brb_is_free($range_s, $range_e, 0), 'a paid booking still blocks the date');
        $extra = brb_record_payment($done->id, 1, '1003', brb_now()->format('Y-m-d'), 'Too much', true);
        brb_t(is_wp_error($extra), 'a check larger than the balance is refused');

        if ($user) {
            ob_start();
            brb_admin_detail((int) $b->id);
            $admin_html = ob_get_clean();
            brb_t(strpos($admin_html, 'Paid in full') !== false, 'admin detail shows paid in full');
            brb_t(strpos($admin_html, '1001') !== false && strpos($admin_html, '1002') !== false, 'admin detail lists both checks');
            foreach ($banned as $w) brb_t(stripos($admin_html, $w) === false, "admin detail has no $w");
            ob_start();
            brb_admin_settings();
            $settings_html = ob_get_clean();
            brb_t(strpos($settings_html, 'Minimum notice') !== false && strpos($settings_html, 'Hold length') !== false, 'settings explain notice and hold length');
            foreach ($banned as $w) brb_t(stripos($settings_html, $w) === false, "settings HTML has no $w");
        }
    }

    $exp_day = brb_now()->modify('+16 days')->format('Y-m-d');
    $exp = brb_process_public_booking([
        'date' => $exp_day,
        'type' => 'hourly',
        'start_hour' => 18,
        'hours' => 2,
        'guests' => 6,
        'name' => 'Flow Test',
        'email' => 'flow-test@example.com',
        'policy' => '1',
    ]);
    brb_t(!is_wp_error($exp) && $exp && $exp->status === 'held' && $exp->mode === 'hourly', 'hourly save is held');
    if ($exp && !is_wp_error($exp)) {
        $created_ids[] = (int) $exp->id;
        $past = gmdate('Y-m-d H:i:s', time() - 3600);
        $wpdb->update(brb_table(), ['hold_until' => $past], ['id' => (int) $exp->id]);
        $es = new DateTimeImmutable($exp->start_dt, brb_tz());
        $ee = new DateTimeImmutable($exp->end_dt, brb_tz());
        brb_t(brb_is_free($es, $ee), 'a hold past its deadline no longer blocks the date');
        $GLOBALS['brb_mails'] = [];
        brb_expire_holds();
        $expired = brb_booking($exp->id);
        brb_t($expired && $expired->status === 'expired', 'the expiry job marks the hold expired');
        $guest_exp = null;
        $admin_exp = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'flow-test@example.com') !== false) $guest_exp = $m;
            if (stripos($m['to'], 'admin@example.com') !== false) $admin_exp = $m;
        }
        brb_t($guest_exp && stripos($guest_exp['message'], 'released') !== false, 'guest is emailed that the hold is released');
        brb_t($admin_exp && stripos($admin_exp['message'], 'released') !== false, 'admin is emailed that the hold is released');
        brb_expire_holds();
        brb_t(count($GLOBALS['brb_mails']) === 2, 'running expiry again does not send a second pair of emails');
        $extended = brb_extend_hold($exp->id, 48);
        brb_t(!is_wp_error($extended) && $extended->status === 'held', 'staff can extend an expired hold when the date is free');
    }

    $phone = brb_build_range([
        'date' => brb_now()->modify('+18 days')->format('Y-m-d'),
        'type' => 'day',
        'days' => 1,
        'guests' => 8,
    ]);
    if (!is_wp_error($phone)) {
        $manual = brb_insert_booking($phone, brb_quote($phone), [
            'name' => 'Flow Test',
            'email' => 'flow-test@example.com',
            'phone' => '555-0199',
            'notes' => 'Called in',
        ], 'phone', false);
        brb_t(!is_wp_error($manual) && $manual->source === 'phone' && $manual->status === 'held', 'a phone booking stores its source');
        if ($manual && !is_wp_error($manual)) $created_ids[] = (int) $manual->id;
    } else {
        brb_t(false, 'phone booking range could be built');
    }

    $month = brb_now()->format('Y-m');
    $req = new WP_REST_Request('GET', '/brb/v1/availability');
    $req->set_param('month', $month);
    $res = rest_do_request($req);
    $data = $res->get_data();
    $today = brb_now()->format('Y-m-d');
    brb_t(isset($data['days'][$today]) && empty($data['days'][$today]['day_ok']), 'today is not a valid full day under the notice rule');
    brb_t(isset($data['days'][$today]['status']) && in_array($data['days'][$today]['status'], ['soon', 'past', 'booked', 'blackout'], true), 'today is greyed out in the calendar feed');
    $later = brb_now()->modify('+20 days')->format('Y-m-d');
    $later_month = substr($later, 0, 7);
    if ($later_month !== $month) {
        $req2 = new WP_REST_Request('GET', '/brb/v1/availability');
        $req2->set_param('month', $later_month);
        $data = rest_do_request($req2)->get_data();
    }
    brb_t(!empty($data['days'][$later]['day_ok']) && $data['days'][$later]['status'] === 'free', 'a date outside the notice window is free');
    $routes = rest_get_server()->get_routes();
    brb_t(!isset($routes['/brb/v1/' . $brand . '-webhook']), 'the old checkout route is not registered');

    $dirty = 'Payments are processed by ' . ucfirst($brand) . '; we do not store card numbers. Please pay securely online.';
    $clean = brb_scrub_copy_string($dirty);
    foreach ($banned as $w) brb_t(stripos($clean, $w) === false, "scrubbed copy has no $w");
    brb_t(stripos($clean, 'check') !== false, 'scrubbed copy talks about a check');

    foreach ([
        'faq' => 'save the date',
        'privacy-policy' => 'check brought in person',
        'terms-and-conditions' => 'your check',
    ] as $slug => $needle) {
        $page = get_page_by_path($slug);
        $content = $page ? (string) $page->post_content : '';
        brb_t($page && stripos($content, $needle) !== false, "$slug says \"$needle\"");
        foreach ($banned as $w) brb_t(stripos($content, $w) === false, "$slug content has no $w");
    }
    $_GET = ['date' => $later, 'type' => 'day', 'days' => 1, 'guests' => 4];
    $quote_html = do_shortcode('[boardroom_booking]');
    brb_t(strpos($quote_html, 'Save this date') !== false, 'quote page submit button is Save this date');
    brb_t(strpos($quote_html, 'Check to bring') !== false, 'quote page shows the check amount');
    foreach ($banned as $w) brb_t(stripos($quote_html, $w) === false, "quote HTML has no $w");
    $_GET = [];
    $picker = do_shortcode('[boardroom_booking]');
    brb_t(strpos($picker, 'Get Quote') !== false && strpos($picker, 'hours from now') !== false, 'calendar explains the minimum notice');
} finally {
    if ($created_ids) {
        $in = implode(',', array_map('intval', $created_ids));
        $wpdb->query("DELETE FROM " . brb_pay_table() . " WHERE booking_id IN ($in)");
        $wpdb->query("DELETE FROM " . brb_table() . " WHERE id IN ($in)");
    }
    $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_pay_table() . ' WHERE booking_id IN (SELECT id FROM ' . brb_table() . ' WHERE email=%s)', 'flow-test@example.com'));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_table() . ' WHERE email=%s', 'flow-test@example.com'));
    if ($snapshot !== false && $snapshot !== null) update_option('brb_settings', $snapshot);
    else delete_option('brb_settings');
}

echo "\n{$GLOBALS['brb_ok']} passed, {$GLOBALS['brb_fail']} failed\n";
exit($GLOBALS['brb_fail'] ? 1 : 0);
