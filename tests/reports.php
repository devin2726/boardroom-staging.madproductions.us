<?php
/**
 * Report queries and funnel math. Run with wp eval-file.
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

global $wpdb;
$created = [];
$inquiry_ids = [];
try {
    brb_t(brb_report_rate(1, 4) === 25.0, 'one of four is 25 percent');
    brb_t(brb_report_rate(0, 0) === null, 'an empty step has no conversion rate');
    brb_t(brb_origin_label('google', '', 'contact form') === 'Google', 'a google campaign is labeled Google');
    brb_t(brb_origin_label('vrbo', '', 'contact form') === 'VRBO', 'a vrbo campaign is labeled VRBO');
    brb_t(brb_origin_label('', '', 'chat lead') === 'Chat', 'a chat inquiry is labeled Chat');
    brb_t(brb_origin_label('', '', 'contact form') === 'Direct', 'a visit with no referrer is Direct');
    brb_t(brb_weekly_summary_send() === false, 'the weekly summary stays off');
    brb_t((string) brb_opt('weekly_summary') === '0', 'the weekly summary setting defaults to off');
    brb_t(brb_ga_tag_allowed() === false, 'the analytics tag stays off until a measurement id is saved');

    $day = '2032-03-03';
    $stamp = '2032-03-03 15:00:00';
    $book = brb_table();
    $pay = brb_pay_table();
    $token = function () { return 'rpt' . wp_generate_password(12, false); };
    $insert = function ($row) use ($wpdb, $book, $stamp, $token, &$created) {
        $base = [
            'token' => $token(),
            'mode' => 'day',
            'guests' => 10,
            'name' => 'Report Guest',
            'email' => 'report-guest@example.com',
            'phone' => '555-0199',
            'status' => 'paid',
            'source' => 'online',
            'total' => '1200.00',
            'deposit_due' => '600.00',
            'amount_paid' => '1200.00',
            'price_set' => 1,
            'event_type' => 'wedding',
            'damage_deposit' => '250.00',
            'damage_paid' => '0.00',
            'damage_refunded' => 0,
            'created' => $stamp,
        ];
        $wpdb->insert($book, array_merge($base, $row));
        $id = (int) $wpdb->insert_id;
        $created[] = $id;
        return $id;
    };
    $paid = $insert([
        'start_dt' => $day . ' 10:00:00',
        'end_dt' => $day . ' 23:30:00',
        'email' => 'repeat-report@example.com',
        'event_type' => 'wedding',
        'damage_paid' => '250.00',
    ]);
    $insert([
        'start_dt' => brb_now()->modify('+3 days')->format('Y-m-d') . ' 10:00:00',
        'end_dt' => brb_now()->modify('+3 days')->format('Y-m-d') . ' 23:30:00',
        'email' => 'repeat-report@example.com',
        'event_type' => 'birthday',
        'status' => 'deposit_paid',
        'amount_paid' => '600.00',
        'total' => '1000.00',
        'damage_paid' => '0.00',
    ]);
    $open = $insert([
        'start_dt' => brb_now()->modify('+10 days')->format('Y-m-d') . ' 10:00:00',
        'end_dt' => brb_now()->modify('+10 days')->format('Y-m-d') . ' 23:30:00',
        'email' => 'open-report@example.com',
        'status' => 'approved',
        'amount_paid' => '0.00',
        'total' => '800.00',
        'deposit_due' => '400.00',
        'event_type' => 'corporate',
        'created' => brb_now()->modify('-3 days')->format('Y-m-d H:i:s'),
    ]);
    $returned = $insert([
        'start_dt' => brb_now()->modify('-2 days')->format('Y-m-d') . ' 10:00:00',
        'end_dt' => brb_now()->modify('-2 days')->format('Y-m-d') . ' 23:30:00',
        'email' => 'returned-report@example.com',
        'damage_paid' => '250.00',
        'damage_refunded' => 1,
    ]);
    unset($open, $returned);
    $wpdb->insert($pay, [
        'booking_id' => $paid,
        'amount' => '850.00',
        'received_on' => $day,
        'method' => 'card',
        'fee' => '25.50',
        'created' => $stamp,
    ]);
    $wpdb->insert($pay, [
        'booking_id' => $paid,
        'amount' => '350.00',
        'received_on' => $day,
        'method' => 'check',
        'fee' => '0.00',
        'check_number' => '1001',
        'created' => $stamp,
    ]);
    $wpdb->insert(brb_tour_table(), [
        'name' => 'Inquiry Report',
        'email' => 'repeat-report@example.com',
        'phone' => '555-0144',
        'notes' => 'A wedding question',
        'status' => 'new',
        'created' => '2032-03-03 09:00:00',
        'source' => 'contact form',
        'topic' => 'event',
        'lead_status' => 'quoted',
        'event_type' => 'wedding',
        'party_size' => 20,
        'utm_source' => 'google',
    ]);
    $inquiry_ids[] = (int) $wpdb->insert_id;
    brb_event_hit('booking_step_date', $day);
    brb_event_hit('booking_step_date', $day);
    brb_event_hit('booking_step_review', $day);
    $wpdb->insert(brb_views_table(), [
        'view_day' => $day,
        'path' => '/book/',
        'referrer_host' => 'google.com',
        'views' => 4,
    ]);
    $wpdb->insert(brb_chatlog_table(), [
        'question' => 'How does the deposit work?',
        'intent' => 'payment',
        'unanswered' => 0,
        'created' => $stamp,
    ]);
    $wpdb->insert(brb_chatlog_table(), [
        'question' => 'Who owns the building?',
        'intent' => 'unknown',
        'unanswered' => 1,
        'created' => $stamp,
    ]);

    $report = brb_report_build($day, $day);
    brb_t(abs($report['financials']['gross'] - 1200) < 0.01, 'gross adds the card and check payments');
    brb_t(abs($report['financials']['fees'] - 25.50) < 0.01, 'card fees are totaled separately');
    brb_t(isset($report['financials']['by_type']['Wedding']) && abs($report['financials']['by_type']['Wedding'] - 1200) < 0.01, 'revenue is grouped by event type');
    brb_t($report['financials']['outstanding'] >= 800, 'an approved unpaid booking stays outstanding');
    brb_t($report['financials']['damage_held'] >= 250 && $report['financials']['damage_refunded'] >= 250, 'held and refunded damage deposits are both counted');
    brb_t($report['bookings']['repeat'] >= 1, 'a guest with two bookings counts as a repeat');
    brb_t($report['bookings']['inquiry_to_book_days'] !== null, 'days from inquiry to booking are averaged');
    brb_t($report['leads']['funnel'][0]['n'] >= 4, 'the funnel starts from page views');
    brb_t($report['leads']['funnel'][1]['rate'] === brb_report_rate($report['leads']['funnel'][1]['n'], $report['leads']['funnel'][0]['n']), 'inquiry conversion uses the page-view count');
    $step = $report['leads']['steps'][0];
    brb_t($step['from'] >= 2 && $step['to'] >= 1 && $step['rate'] === brb_report_rate($step['to'], $step['from']), 'booking-step drop-off compares starts with the next step');
    brb_t($report['attention']['deposit_overdue'] >= 1, 'an approved booking with no deposit is overdue after a day');
    $csv = brb_report_csv('financials', $report);
    brb_t(strpos($csv, 'Gross collected') !== false && strpos($csv, 'Wedding') !== false && stripos($csv, 'sk_') === false, 'the financial CSV lists totals and does not include a secret key');

    $fake = '{"client_email":"reports@example.com","private_key":"-----BEGIN PRIVATE KEY-----\nTESTKEYONLY\n-----END PRIVATE KEY-----\n"}';
    brb_t(brb_ga_store_credentials($fake) === true, 'a service account JSON can be saved');
    $user = get_user_by('login', 'admin');
    if ($user) {
        wp_set_current_user($user->ID);
        ob_start();
        brb_admin_settings();
        $html = ob_get_clean();
        brb_t(strpos($html, 'TESTKEYONLY') === false && strpos($html, 'private_key') === false && strpos($html, 'A service account JSON is saved') !== false, 'the saved service account is not printed back');
    } else {
        brb_t(false, 'the saved service account is not printed back');
    }
    delete_option('brb_ga_credentials');

    $saved_env = getenv('BRB_ENV');
    putenv('BRB_ENV=staging');
    $_ENV['BRB_ENV'] = 'staging';
    $_SERVER['BRB_ENV'] = 'staging';
    $settings = brb_opt();
    $settings['ga_measurement_id'] = 'G-TEST123';
    update_option('brb_settings', $settings);
    brb_t(brb_ga_tag_allowed() === false, 'staging does not print the analytics tag while search indexing is off');
    putenv('BRB_ENV=production');
    $_ENV['BRB_ENV'] = 'production';
    $_SERVER['BRB_ENV'] = 'production';
    brb_t(brb_ga_tag_allowed() === true, 'the analytics tag is allowed when the measurement id is set and indexing is on');
    if ($saved_env === false || $saved_env === '') {
        putenv('BRB_ENV');
        unset($_ENV['BRB_ENV'], $_SERVER['BRB_ENV']);
    } else {
        putenv('BRB_ENV=' . $saved_env);
        $_ENV['BRB_ENV'] = $saved_env;
        $_SERVER['BRB_ENV'] = $saved_env;
    }
    $settings['ga_measurement_id'] = '';
    update_option('brb_settings', $settings);
} finally {
    if ($created) {
        $in = implode(',', array_map('intval', $created));
        $wpdb->query("DELETE FROM " . brb_pay_table() . " WHERE booking_id IN ($in)");
        $wpdb->query("DELETE FROM " . brb_table() . " WHERE id IN ($in)");
    }
    $wpdb->query("DELETE FROM " . brb_table() . " WHERE email IN ('repeat-report@example.com','open-report@example.com','returned-report@example.com','report-guest@example.com')");
    if ($inquiry_ids) {
        $wpdb->query('DELETE FROM ' . brb_tour_table() . ' WHERE id IN (' . implode(',', array_map('intval', $inquiry_ids)) . ')');
    }
    $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_views_table() . ' WHERE path = %s AND view_day = %s', '/book/', '2032-03-03'));
    $wpdb->query("DELETE FROM " . brb_chatlog_table() . " WHERE question IN ('How does the deposit work?','Who owns the building?')");
    echo 'ok ' . (int) $GLOBALS['brb_ok'] . ' fail ' . (int) $GLOBALS['brb_fail'] . "\n";
}
