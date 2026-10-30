<?php
/**
 * Run inside WordPress: wp eval-file tests/check-payment-flow.php
 * Exercises a request → approval → deposit → balance → paid, the 48h notice rule, and blackout preservation.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Load this with wp eval-file\n");
    exit(1);
}

$GLOBALS['brb_fail'] = 0;
$GLOBALS['brb_ok'] = 0;
function brb_screen($overrides = []) {
    return array_merge([
        'public_event' => 'no',
        'free_admission' => '1',
        'alcohol' => 'yes',
        'end_time' => '21:30',
        'host_age' => 'age_25',
        'event_about' => 'A private dinner for family.',
    ], $overrides);
}
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
$banned = [$wire, $other];

global $wpdb;
$snapshot = get_option('brb_settings');
$created_ids = [];
add_filter('pre_wp_mail', function ($short, $atts) {
    $GLOBALS['brb_mails'][] = $atts;
    return true;
}, 10, 2);
$GLOBALS['brb_mails'] = [];

try {
    brb_t(get_option('brb_version') === '2.5.17', 'plugin storage version is 2.5.17');
    brb_t(get_option('blog_public') === '0' || get_option('blog_public') === 0, 'staging discourages search engines');
    $cols = $wpdb->get_col('SHOW COLUMNS FROM ' . brb_table(), 0);
    foreach (['deposit_due', 'amount_paid', 'source', 'hold_until', 'balance_due_on', 'price_set', 'status', 'event_type'] as $col) {
        brb_t(in_array($col, $cols, true), "column $col exists");
    }
    brb_t($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', brb_pay_table())) === brb_pay_table(), 'checks table exists');
    $pay_cols = $wpdb->get_col('SHOW COLUMNS FROM ' . brb_pay_table(), 0);
    brb_t(in_array('method', $pay_cols, true), 'payments table stores a method');
    brb_t(!wp_next_scheduled('brb_cron_expire'), 'unpaid requests are not expired on a timer');

    $stored = get_option('brb_settings');
    brb_t(is_array($stored) && !array_key_exists($brand . '_pk', $stored) && !array_key_exists($brand . '_sk', $stored), 'saved settings no longer keep processor keys');
    brb_t(brb_opt('deposit_type') === 'percent' && (string) brb_opt('deposit_pct') === '50', 'the deposit default is 50 percent of the agreed total');
    brb_t((string) brb_opt('min_notice_hours') === '48', 'minimum notice defaults to 48');
    brb_t((string) brb_opt('hold_hours') === '48', 'hold length defaults to 48');
    brb_t(strpos((string) brb_opt('payee'), '[VERIFY]') !== false, 'payee placeholder is marked [VERIFY]');
    brb_t((string) brb_opt('venue_address') === '2220 37th Street, Moline, IL 61265', 'venue address defaults to 2220 37th Street');
    brb_t((string) brb_opt('pay_location') === '2212 37th Street, Moline, IL 61265', 'check address defaults to 2212 37th Street');
    brb_t(brb_check_sentence() === 'Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265.', 'the default check sentence names Mad Productions and 2212 37th Street');
    brb_t(strpos((string) brb_opt('pay_hours'), '[VERIFY]') !== false, 'hours placeholder is marked [VERIFY]');
    $blank_instructions = brb_opt();
    $blank_instructions['pay_instructions'] = '[VERIFY] Extra instructions for bringing the check.';
    update_option('brb_settings', $blank_instructions);
    brb_migrate_settings();
    brb_t((string) brb_opt('pay_instructions') === '', 'the extra-instructions placeholder is cleared on upgrade');
    $hidden_preview = implode("\n", brb_preview_lines(['total' => 0, 'due' => 0], brb_now()->modify('+10 days')));
    brb_t(strpos($hidden_preview, 'Extra instructions for bringing the check') === false, 'an empty extra-instructions setting is hidden on the review step');
    $kept = brb_opt();
    $kept['pay_location'] = '[VERIFY] In-person location';
    update_option('brb_settings', $kept);
    brb_migrate_settings();
    brb_t((string) brb_opt('pay_location') === '2212 37th Street, Moline, IL 61265', 'a placeholder drop-off address is replaced on upgrade');
    $kept = brb_opt();
    $kept['pay_location'] = '2212 47 Ave';
    update_option('brb_settings', $kept);
    brb_migrate_settings();
    brb_t((string) brb_opt('pay_location') === '2212 37th Street, Moline, IL 61265', 'the old 2212 47 Ave drop-off is replaced on upgrade');
    $kept = brb_opt();
    $kept['pay_location'] = 'Side door';
    update_option('brb_settings', $kept);
    brb_migrate_settings();
    brb_t((string) brb_opt('pay_location') === 'Side door', 'a custom drop-off location is kept');
    $kept['pay_location'] = '2212 37th Street, Moline, IL 61265';
    update_option('brb_settings', $kept);

    $dates = [];
    for ($i = 1; $i <= 24; $i++) $dates[] = sprintf('2030-01-%02d', $i);
    $old = [
        'mode' => 'both',
        'day_rate' => '500',
        'hourly_rate' => '75',
        'starting_price' => '750',
        'typical_price' => 'often near $1,500',
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
    brb_t(($migrated['starting_price'] ?? '') === '750', 'a saved starting price is kept');
    brb_t(($migrated['typical_price'] ?? '') === 'often near $1,500', 'a saved typical price text is kept');
    brb_t(($migrated['policy'] ?? '') === 'Keep this policy text.', 'policy text is kept');
    $balance_policy = brb_opt();
    $balance_before = (string) $balance_policy['policy'];
    $balance_policy['policy'] = 'The invoice notes require full payment 30 days before the event. Keep the rest.';
    update_option('brb_settings', $balance_policy);
    brb_migrate_settings();
    brb_t(strpos((string) brb_opt('policy'), 'week of the event, no later than 48 hours before the event') !== false && strpos((string) brb_opt('policy'), '30 days before the event') === false && strpos((string) brb_opt('policy'), 'Keep the rest.') !== false, 'a saved 30-day balance sentence becomes the week-of-event rule');
    $balance_policy = brb_opt();
    $balance_policy['policy'] = $balance_before;
    update_option('brb_settings', $balance_policy);
    brb_t(($migrated['admin_email'] ?? '') === 'admin@example.com', 'admin email is kept');
    brb_t(!array_key_exists($brand . '_sk', $migrated) && !isset($migrated['hold_minutes']), 'legacy keys are dropped');
    brb_t(($migrated['deposit_type'] ?? '') === 'percent' && (string) ($migrated['deposit_pct'] ?? '') === '50', 'a legacy full deposit becomes a 50 percent deposit');
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
    unset($_POST['day_rate'], $_POST['hourly_rate'], $_POST['cleaning_fee'], $_POST['tax_rate']);
    $_POST['brb_save'] = '1';
    $_POST['blackouts'] = implode("\n", $dates);
    $_POST['starting_price'] = '600';
    $_POST['typical_price'] = 'most events around $1,200';
    unset($_POST['clear_blackouts']);
    $_REQUEST['_wpnonce'] = wp_create_nonce('brb_settings');
    $_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
    ob_start();
    brb_admin_settings();
    ob_end_clean();
    brb_t((string) brb_opt('day_rate') === '500', 'saving the form keeps the stored day rate');
    brb_t((string) brb_opt('hourly_rate') === '75' && (string) brb_opt('cleaning_fee') === '100' && (string) brb_opt('tax_rate') === '7', 'saving the form keeps hourly, cleaning, and tax figures');
    brb_t(brb_price_sentence() === 'Pricing from $600 per day (most events around $1,200), final price confirmed based on date, guest count and features.', 'public price sentence uses the starting and typical text');

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
        'policy' => '1', 'waiver' => '1',
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
    $missing_type = brb_process_public_booking([
        'date' => brb_now()->modify('+18 days')->format('Y-m-d'),
        'type' => 'day',
        'days' => 1,
        'guests' => 4,
        'name' => 'Flow Test',
        'email' => 'flow-test@example.com',
        'policy' => '1', 'waiver' => '1',
    ]);
    brb_t(is_wp_error($missing_type) && $missing_type->get_error_code() === 'event_type', 'a public save requires an event type');
    $half_ok = brb_process_public_booking([
        'date' => '2030-06-12',
        'type' => 'half',
        'half' => 'day',
        'guests' => 8,
        'name' => 'June Half',
        'email' => 'june-half@example.com',
        'policy' => '1', 'waiver' => '1',
        'event_type' => 'wedding',
    ] + brb_screen());
    brb_t(!is_wp_error($half_ok) && $half_ok && $half_ok->mode === 'half' && strpos($half_ok->start_dt, '10:00:00') !== false && strpos($half_ok->end_dt, '16:00:00') !== false, 'a June half day runs from 10:00 AM to 4:00 PM');
    if ($half_ok && !is_wp_error($half_ok)) $created_ids[] = (int) $half_ok->id;
    $half_no = brb_process_public_booking([
        'date' => '2030-07-12',
        'type' => 'half',
        'half' => 'eve',
        'guests' => 8,
        'name' => 'July Half',
        'email' => 'july-half@example.com',
        'policy' => '1', 'waiver' => '1',
        'event_type' => 'wedding',
    ] + brb_screen());
    brb_t(is_wp_error($half_no) && $half_no->get_error_code() === 'half', 'half days outside June are not offered');

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
        'policy' => '1', 'waiver' => '1',
        'event_type' => 'wedding',
    ];
    $post = array_merge($post, brb_screen());
    $GLOBALS['brb_mails'] = [];
    $b = brb_process_public_booking($post);
    brb_t(!is_wp_error($b) && $b && $b->status === 'review', 'a request is pending review and does not hold the date');
    if ($b && !is_wp_error($b)) {
        $created_ids[] = (int) $b->id;
        brb_t($b->public_event === 'no' && ($b->free_admission ?? '') === '1' && $b->alcohol === 'yes' && $b->end_time === '21:30' && $b->host_age === 'age_25' && strpos((string) $b->event_about, 'private dinner') !== false, 'screening answers are stored on the booking');
        brb_t(brb_balance_due_display($b) === 'Price to be confirmed', 'an unpriced request does not show a balance due date');
        if ($user) {
            ob_start();
            brb_admin_detail((int) $b->id);
            $unpriced_admin = ob_get_clean();
            brb_t(strpos($unpriced_admin, 'Price to be confirmed') !== false && strpos($unpriced_admin, 'Balance due') !== false, 'staff detail says the balance date waits for a price');
            $past_label = brb_balance_due_label($b->balance_due_on);
            brb_t($past_label !== '' && strpos($unpriced_admin, $past_label) === false, 'staff detail does not show a balance date before a price exists');
        }
        $late_day = brb_now()->modify('+21 days')->format('Y-m-d');
        $late = brb_process_public_booking(array_merge($post, ['date' => $late_day, 'end_time' => '01:35']));
        brb_t(is_wp_error($late) && $late->get_error_code() === 'end_time' && strpos($late->get_error_message(), '11:30 PM') !== false && stripos($late->get_error_message(), 'midnight') === false, '1:35 AM is rejected without mentioning midnight');
        $too_late = brb_process_public_booking(array_merge($post, ['date' => $late_day, 'end_time' => '23:45']));
        brb_t(is_wp_error($too_late) && $too_late->get_error_code() === 'end_time', 'an end time after 11:30 PM is rejected');
        $on_time = brb_process_public_booking(array_merge($post, ['date' => brb_now()->modify('+22 days')->format('Y-m-d'), 'end_time' => '23:30']));
        brb_t(!is_wp_error($on_time) && $on_time && $on_time->end_time === '23:30', '11:30 PM is the latest accepted end time');
        if ($on_time && !is_wp_error($on_time)) $created_ids[] = (int) $on_time->id;
        $before_start = brb_process_public_booking(array_merge($post, ['date' => brb_now()->modify('+23 days')->format('Y-m-d'), 'type' => 'hourly', 'start_hour' => 18, 'hours' => 2, 'end_time' => '17:00']));
        brb_t(is_wp_error($before_start) && $before_start->get_error_code() === 'end_time' && strpos($before_start->get_error_message(), 'before the booking starts') !== false, 'an end time before the hourly start is rejected');
        $js = file_get_contents(WP_PLUGIN_DIR . '/boardroom-booking/assets/brb.js');
        brb_t(is_string($js) && strpos($js, '23 * 60 + 30') !== false && strpos($js, 'early-morning') !== false && strpos($js, 'before the booking starts') !== false, 'the booking form checks the end time in the browser');
        $soon_day = brb_now()->modify('+4 days')->format('Y-m-d');
        $soon = brb_process_public_booking(array_merge($post, ['date' => $soon_day, 'name' => 'Soon Balance', 'email' => 'soon-balance@example.com']));
        if ($soon && !is_wp_error($soon)) {
            $created_ids[] = (int) $soon->id;
            $soon_priced = brb_set_agreed_price($soon->id, 800);
            $past_due = brb_now()->modify('-3 days')->format('Y-m-d');
            $wpdb->update(brb_table(), ['balance_due_on' => $past_due], ['id' => (int) $soon->id]);
            $soon_priced = brb_booking($soon->id);
            brb_t(!is_wp_error($soon_priced) && brb_balance_due_display($soon_priced) === 'Due now', 'a balance date before today is shown as due now');
            $soon_past = brb_balance_due_label($soon_priced->balance_due_on);
            ob_start();
            brb_admin_detail((int) $soon_priced->id);
            $soon_admin = ob_get_clean();
            brb_t(strpos($soon_admin, 'Due now') !== false && strpos($soon_admin, $soon_past) === false, 'staff detail never shows a balance date in the past');
            brb_t(strpos($soon_admin, 'Private, invitation-only') !== false && strpos($soon_admin, 'Free admission') !== false && strpos($soon_admin, 'Acknowledged') !== false, 'staff detail shows the admission acknowledgment and the private-gathering answer');
        } else {
            brb_t(false, 'a near-term request can be priced to check the balance date');
        }
        $guest_mail = null;
        $admin_mail = null;
        $ref_mail = brb_ref($b);
        foreach ($GLOBALS['brb_mails'] as $m) {
            $blob = ($m['subject'] ?? '') . "\n" . ($m['message'] ?? '');
            if (strpos($blob, $ref_mail) === false) continue;
            if (stripos($m['to'], 'flow-test@example.com') !== false && stripos($m['subject'], 'request') !== false) $guest_mail = $m;
            if (stripos($m['to'], 'admin@example.com') !== false) $admin_mail = $m;
        }
        brb_t((bool) $guest_mail, 'guest request email was sent');
        if ($guest_mail) {
            $body = $guest_mail['message'];
            brb_t(strpos($body, 'held for 48 hours') === false && strpos($body, 'Your date is held until') === false, 'request email does not describe a 48 hour hold');
            brb_t(strpos($body, 'Thank you for contacting The Boardroom!') === 0 && strpos($body, 'we meet every renter in person') !== false && strpos($body, 'once the meeting is complete and your deposit is received and approved') !== false, 'request email opens with thanks and the walk-through');
            brb_t(strpos($body, 'Event type: Wedding') !== false, 'request email names the event type');
            brb_t(strpos($body, 'Private, invitation-only: Yes') !== false && strpos($body, 'Free admission acknowledged: Yes') !== false && strpos($body, 'Alcohol served: Yes') !== false && strpos($body, 'Host age bracket: 25 or older') !== false && strpos($body, 'Planned end time: 9:30 PM') !== false && strpos($body, 'A private dinner for family.') !== false && strpos($body, '11:30 PM') !== false && strpos($body, 'no tickets') !== false, 'request email includes the screening answers and the house rules');
            brb_t(strpos($body, '[VERIFY] Test instructions.') === false && strpos($body, 'payable to [VERIFY] Test Payee') === false, 'request email does not include payment instructions');
            brb_t(strpos($body, 'Extra instructions for bringing the check') === false, 'the default extra-instructions placeholder is not in the request email');
            brb_t(stripos($body, 'price to be confirmed') !== false, 'email says price to be confirmed');
            brb_t(strpos($body, 'No overnight stays. The Boardroom is an event space only.') !== false, 'request email states the no-overnight rule');
            brb_t(stripos($body, 'check-in') === false && stripos($body, 'check-out') === false && stripos($body, 'per night') === false && stripos($body, 'per-night') === false && stripos($body, 'guests staying') === false && strpos($body, '[VERIFY] latest end time') === false && strpos($body, '[VERIFY: attorney review]') === false, 'request email uses event wording and has no attorney note');
            brb_t(strpos($body, '$0.00') === false && strpos($body, '$500') === false && strpos($body, '$75') === false && strpos($body, '$100') === false, 'request email has no placeholder or zero total');
            brb_t((int) $b->price_set === 0 && (float) $b->total == 0.0, 'a public request does not store a guessed total');
            brb_t($b->event_type === 'wedding', 'the event type is stored with the booking');
            brb_t($b->phone === '', 'a public save does not store a phone number');
        }
        brb_t($admin_mail && strpos($admin_mail['message'], 'pending review') !== false && strpos($admin_mail['message'], 'The date is not held.') !== false && strpos($admin_mail['message'], 'A private dinner for family.') !== false, 'staff email shows the screening answers and that the date is not held');
        $range_s = new DateTimeImmutable($b->start_dt, brb_tz());
        $range_e = new DateTimeImmutable($b->end_dt, brb_tz());
        brb_t(brb_is_free($range_s, $range_e), 'a pending request does not block the date');
        $dup = brb_process_public_booking($post);
        brb_t(!is_wp_error($dup) && $dup && $dup->status === 'review', 'a second request for the same date is allowed before any deposit');
        if ($dup && !is_wp_error($dup)) $created_ids[] = (int) $dup->id;

        $_GET = ['br_token' => $b->token];
        $html = do_shortcode('[boardroom_booking]');
        brb_t(strpos($html, 'held for 48 hours') === false && strpos($html, 'Your date is held until') === false, 'confirmation page does not describe a 48 hour hold');
        brb_t(strpos($html, 'Thank you for contacting The Boardroom!') !== false && strpos($html, 'we meet every renter in person') !== false, 'confirmation page thanks the guest and explains the walk-through');
        brb_t(strpos($html, 'Wedding') !== false, 'confirmation page shows the event type');
        brb_t(strpos($html, '[VERIFY] Test instructions.') === false && strpos($html, 'Extra instructions for bringing the check') === false, 'confirmation hides payment instructions until the request is approved');
        brb_t(strpos($html, 'Pending review') !== false, 'confirmation page says the request is pending review');
        brb_t(strpos($html, 'No overnight stays. The Boardroom is an event space only.') !== false && strpos($html, 'guests attending') !== false, 'confirmation states the no-overnight rule and guests attending');
        brb_t(stripos($html, 'check-in') === false && stripos($html, 'check-out') === false && stripos($html, 'per night') === false && stripos($html, 'guests staying') === false && strpos($html, '[VERIFY] latest end time') === false && strpos($html, '[VERIFY: attorney review]') === false, 'confirmation uses event wording and has no attorney note');
        brb_t(strpos($html, brb_ref($b)) !== false && strpos($html, 'memo line') === false, 'an unapproved confirmation does not show payment instructions');
        brb_t(stripos($html, 'price to be confirmed') !== false, 'confirmation says price to be confirmed');
        brb_t(strpos($html, '$0.00') === false && strpos($html, '$500') === false && strpos($html, '$75') === false && strpos($html, '$100') === false && strpos($html, '7%') === false, 'confirmation has no placeholder amounts');
        foreach ($banned as $w) brb_t(stripos($html, $w) === false, "confirmation HTML has no $w");

        $blocked_pay = brb_record_payment($b->id, 100, '1000', brb_now()->format('Y-m-d'), 'Too soon', true);
        brb_t(is_wp_error($blocked_pay) && $blocked_pay->get_error_code() === 'price', 'a check cannot be recorded before the agreed price is set');
        $blocked_paid = brb_mark_paid_full($b->id);
        brb_t(is_wp_error($blocked_paid), 'mark paid waits for an agreed price');

        $GLOBALS['brb_mails'] = [];
        $priced = brb_set_agreed_price($b->id, 1200);
        brb_t(!is_wp_error($priced) && $priced && (int) $priced->price_set === 1, 'staff can set the agreed price');
        brb_t($priced && abs((float) $priced->total - 1200) < 0.01 && abs((float) $priced->deposit_due - 600) < 0.01, 'deposit is half of the agreed price');
        $price_mail = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'flow-test@example.com') !== false && stripos($m['subject'], 'price is confirmed') !== false) $price_mail = $m;
        }
        brb_t($price_mail && strpos($price_mail['message'], '$1,200.00') !== false && strpos($price_mail['message'], '$600.00') !== false && strpos($price_mail['message'], 'Payment instructions are sent when the request is approved.') !== false, 'price email states the agreed price and waits for approval');
        $GLOBALS['brb_mails'] = [];
        $wpdb->update(brb_table(), ['walkthrough_done' => 1], ['id' => (int) $priced->id]);
        $approved = brb_approve_booking($priced->id);
        brb_t(!is_wp_error($approved) && $approved && $approved->status === 'approved', 'staff can approve a pending request');
        brb_t($approved && brb_is_free($range_s, $range_e, (int) $approved->id), 'an approved unpaid request still does not block the date');
        $approve_mail = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'flow-test@example.com') !== false && stripos($m['subject'], 'approved') !== false) $approve_mail = $m;
        }
        brb_t($approve_mail && strpos($approve_mail['message'], 'Thank you for contacting The Boardroom!') === 0 && strpos($approve_mail['message'], 'we meet every renter in person') !== false, 'the approval email thanks the guest and mentions the walk-through');
        brb_t($approve_mail && strpos($approve_mail['message'], 'payable to [VERIFY] Test Payee') !== false && strpos($approve_mail['message'], '[VERIFY] Test Window') !== false && strpos($approve_mail['message'], '[VERIFY] Test Hours') !== false, 'the approval email names the payee and the drop-off place and hours');
        brb_t($approve_mail && strpos($approve_mail['message'], 'Write booking reference ' . brb_ref($priced) . ' on the memo line.') !== false && strpos($approve_mail['message'], '[VERIFY] Test instructions.') !== false, 'the approval email includes the memo line and custom instructions');
        brb_t($approve_mail && strpos($approve_mail['message'], '60 or more days before the event') !== false && strpos($approve_mail['message'], 'less than 60 days before the event') !== false && strpos($approve_mail['message'], 'Refunds are issued manually.') !== false && strpos($approve_mail['message'], '[VERIFY: attorney review]') === false, 'the approval email states the 60-day deposit rule and has no attorney note');
        brb_t($approve_mail && strpos($approve_mail['message'], 'Rental checklist') !== false && strpos($approve_mail['message'], '8:00 AM') !== false && strpos($approve_mail['message'], 'https://moline.il.us/244/Liquor-Licensing') !== false && strpos($approve_mail['message'], 'fee $50') === false && strpos($approve_mail['message'], 'minimum host age is 25') !== false, 'the approval email includes the rental checklist');
        brb_t($approve_mail && strpos($approve_mail['message'], 'a check for $850.00') !== false && strpos($approve_mail['message'], 'That amount is the $600.00 deposit plus the $250.00 refundable damage deposit.') !== false && strpos($approve_mail['message'], 'the remaining balance of $600.00') !== false, 'the approval email asks for the deposit plus the unpaid damage deposit, then the rental remainder');
        $sample = clone $approved;
        $sample->total = 1000;
        $sample->deposit_due = 500;
        $sample->amount_paid = 0;
        $sample->damage_deposit = 250;
        $sample->damage_paid = 0;
        $sample->damage_refunded = 0;
        $sample_text = implode("\n", brb_payment_lines($sample));
        brb_t(strpos($sample_text, 'a check for $750.00') !== false && strpos($sample_text, 'That amount is the $500.00 deposit plus the $250.00 refundable damage deposit.') !== false && strpos($sample_text, 'the remaining balance of $500.00') !== false && strpos($sample_text, 'payable to') !== false && strpos($sample_text, 'on the memo line.') !== false, 'a $1,000 booking asks for $750.00 now and leaves a $500.00 rental balance');
        $b = $approved;

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
        brb_t(abs((float) $after->damage_paid) < 0.01, 'a check for exactly the rental deposit does not pay the damage deposit');
        brb_t(abs(brb_amount_due_now($after) - ($second + brb_damage_left($after))) < 0.01, 'the balance due includes the unpaid damage deposit');
        brb_t($dep_mail && strpos($dep_mail['message'], 'unpaid refundable damage deposit') !== false, 'the deposit receipt says the unpaid damage deposit is still due');
        $rental_line = brb_find_line_kind($after->id, 'rental');
        $damage_row = brb_find_line_kind($after->id, 'damage_deposit');
        $coverage = brb_line_coverage($after);
        brb_t($rental_line && ($coverage[(int) $rental_line->id]['paid'] ?? null) !== null && brb_line_state_text($coverage[(int) $rental_line->id]['paid'], $coverage[(int) $rental_line->id]['amount']) === 'partially paid, $600.00 of $1,200.00', 'a half-paid rental line is partially paid');
        brb_t($damage_row && brb_line_state_text($coverage[(int) $damage_row->id]['paid'], $coverage[(int) $damage_row->id]['amount']) === 'unpaid', 'an unpaid damage deposit line stays unpaid');
        if ($user) {
            ob_start();
            brb_admin_detail((int) $after->id);
            $partial_admin = ob_get_clean();
            brb_t(strpos($partial_admin, 'partially paid, $600.00 of $1,200.00') !== false && strpos($partial_admin, 'Rental <em>(paid)</em>') === false, 'the rental line shows the partial payment instead of paid in full');
            brb_t(strpos($partial_admin, '(unpaid)') !== false, 'the unpaid damage deposit line is not marked paid');
        }
        $balance_charge = brb_checkout_lines($after);
        $damage_line = null;
        foreach ($balance_charge['lines'] as $line) {
            if ($line['name'] === 'Refundable damage deposit') $damage_line = $line;
        }
        brb_t($damage_line && (int) $damage_line['cents'] === 25000 && (int) $balance_charge['base_cents'] === (int) round(($second + 250) * 100), 'the balance checkout itemizes the unpaid damage deposit');
        brb_t(brb_card_fee_cents($balance_charge['base_cents']) === (int) round(($second + 250) * 3), 'the balance card fee includes 3 percent of the unpaid damage deposit');

        $GLOBALS['brb_mails'] = [];
        $with_damage = round($second + brb_damage_left($after), 2);
        $done = brb_record_payment($after->id, $with_damage, '1002', brb_now()->format('Y-m-d'), 'Balance check', true);
        brb_t(!is_wp_error($done) && $done->status === 'paid', 'recording the balance and the unpaid damage deposit marks the booking paid in full');
        brb_t(abs((float) $done->total - (float) $done->amount_paid) < 0.02 && abs((float) $done->damage_paid - 250) < 0.01, 'the balance check pays the rental remainder and the damage deposit');
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
            brb_t(strpos($admin_html, 'Event type') !== false && strpos($admin_html, 'Wedding') !== false, 'admin detail shows the event type');
            brb_t(strpos($admin_html, 'Payments recorded') !== false && strpos($admin_html, '>Check<') !== false, 'payments list shows the check method');
            brb_t(strpos($admin_html, 'Delete booking') === false, 'a paid booking has no delete action');
            foreach ($banned as $w) brb_t(stripos($admin_html, $w) === false, "admin detail has no $w");
            ob_start();
            brb_admin_settings();
            $settings_html = ob_get_clean();
            brb_t(strpos($settings_html, 'Minimum notice') !== false && strpos($settings_html, 'Hold length') === false, 'settings keep the notice rule and do not offer a hold length');
            brb_t(strpos($admin_html, '[VERIFY: attorney review]') !== false && strpos($settings_html, '[VERIFY: attorney review]') !== false, 'the attorney note is visible to staff');
            brb_t(strpos($settings_html, '11:30 PM') !== false && strpos($settings_html, '[VERIFY] latest end time') === false, 'settings state the 11:30 PM departure rule');
            brb_t(strpos($settings_html, 'Starting price') !== false && strpos($settings_html, 'Typical price text') !== false, 'settings show starting and typical price');
            brb_t(strpos($settings_html, 'Full-day rate') === false && strpos($settings_html, '$75') === false && strpos($settings_html, 'name="checklist"') !== false, 'settings do not show the old public rates and include the checklist');
            brb_t(strpos($settings_html, 'name="damage_deposit_amount"') !== false && strpos($settings_html, 'Damage deposit amount') !== false, 'settings include the damage deposit amount');
            foreach ($banned as $w) brb_t(stripos($settings_html, $w) === false, "settings HTML has no $w");
            $kept_line = brb_find_line_kind($b->id, 'damage_deposit');
            $_POST = brb_opt();
            $_POST['brb_save'] = '1';
            $_POST['damage_deposit_amount'] = '300';
            $_REQUEST['_wpnonce'] = wp_create_nonce('brb_settings');
            $_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
            ob_start();
            brb_admin_settings();
            ob_end_clean();
            brb_t((string) brb_opt('damage_deposit_amount') === '300.00', 'a damage deposit amount saves as dollars');
            $fresh_day = brb_now()->modify('+45 days')->format('Y-m-d');
            $fresh = brb_process_public_booking([
                'date' => $fresh_day,
                'type' => 'day',
                'days' => 1,
                'guests' => 8,
                'name' => 'Damage Amount',
                'email' => 'flow-test@example.com',
                'policy' => '1',
                'waiver' => '1',
                'event_type' => 'birthday',
            ] + brb_screen());
            if ($fresh && !is_wp_error($fresh)) $created_ids[] = (int) $fresh->id;
            $fresh_line = ($fresh && !is_wp_error($fresh)) ? brb_find_line_kind($fresh->id, 'damage_deposit') : null;
            $kept_after = brb_find_line_kind($b->id, 'damage_deposit');
            brb_t($fresh_line && abs((float) $fresh_line->amount - 300) < 0.01 && $kept_line && $kept_after && abs((float) $kept_after->amount - (float) $kept_line->amount) < 0.01, 'a new damage deposit amount applies to new bookings only');
            $_POST = brb_opt();
            $_POST['brb_save'] = '1';
            $_POST['damage_deposit_amount'] = '-20';
            $_REQUEST['_wpnonce'] = wp_create_nonce('brb_settings');
            $_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
            ob_start();
            brb_admin_settings();
            ob_end_clean();
            brb_t((string) brb_opt('damage_deposit_amount') === '0.00', 'a negative damage deposit amount is saved as zero');
            $_POST = brb_opt();
            $_POST['brb_save'] = '1';
            $_POST['damage_deposit_amount'] = '250';
            $_REQUEST['_wpnonce'] = wp_create_nonce('brb_settings');
            $_POST['_wpnonce'] = $_REQUEST['_wpnonce'];
            ob_start();
            brb_admin_settings();
            ob_end_clean();
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
        'policy' => '1', 'waiver' => '1',
        'event_type' => 'corporate',
    ] + brb_screen());
    brb_t(!is_wp_error($exp) && $exp && $exp->status === 'review' && $exp->mode === 'hourly', 'an hourly request stays pending review');
    if ($exp && !is_wp_error($exp)) {
        $created_ids[] = (int) $exp->id;
        $es = new DateTimeImmutable($exp->start_dt, brb_tz());
        $ee = new DateTimeImmutable($exp->end_dt, brb_tz());
        brb_t(brb_is_free($es, $ee), 'an hourly request does not block the calendar');
        $GLOBALS['brb_mails'] = [];
        brb_expire_holds();
        $still = brb_booking($exp->id);
        brb_t($still && $still->status === 'review' && count($GLOBALS['brb_mails']) === 0, 'the old expiry job does not release or email a request');
        $extended = brb_extend_hold($exp->id, 48);
        brb_t(is_wp_error($extended), 'staff cannot extend a hold because requests do not hold the date');
        $priced_exp = brb_set_agreed_price($exp->id, 400);
        $first_pay = brb_record_payment($exp->id, 200, '8801', brb_now()->format('Y-m-d'), 'First deposit', true);
        brb_t(!is_wp_error($priced_exp) && !is_wp_error($first_pay) && $first_pay->status === 'deposit_paid', 'the first paid deposit reserves the date');
        brb_t(!brb_is_free($es, $ee), 'a paid deposit blocks the calendar');
        $loser = brb_process_public_booking([
            'date' => $exp_day,
            'type' => 'hourly',
            'start_hour' => 18,
            'hours' => 2,
            'guests' => 4,
            'name' => 'Second Guest',
            'email' => 'flow-test@example.com',
            'policy' => '1', 'waiver' => '1',
            'event_type' => 'other',
        ] + brb_screen(['event_about' => 'A second request after a deposit.']));
        brb_t(is_wp_error($loser) && $loser->get_error_code() === 'busy', 'a new request is refused once a deposit has reserved the date');
        $late = brb_record_payment($exp->id, 50, '8802', brb_now()->format('Y-m-d'), 'Overlap', true);
        brb_t(!is_wp_error($late), 'the booking that already reserved the date can still take another payment');
        $GLOBALS['brb_mails'] = [];
        $declined = brb_decline_booking($exp->id);
        brb_t(!is_wp_error($declined) && $declined->status === 'declined' && strpos((string) $declined->attention, 'Resolve it manually. [VERIFY: attorney review]') !== false, 'declining after a payment flags it for staff and does not promise a refund in the flag');
        $decline_mail = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'flow-test@example.com') !== false) $decline_mail = $m;
        }
        brb_t($decline_mail && strpos($decline_mail['message'], 'not reserved') !== false && strpos($decline_mail['message'], '60 or more days before the event') !== false && strpos($decline_mail['message'], 'less than 60 days before the event') !== false && strpos($decline_mail['message'], 'Refunds are issued manually.') !== false && strpos($decline_mail['message'], 'resolve it manually') !== false, 'the decline email states the 60-day rule and says refunds are manual');
        brb_t(brb_is_free($es, $ee), 'declining the paid request releases the date');
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
        brb_t(!is_wp_error($manual) && $manual->source === 'phone' && $manual->status === 'review', 'a phone booking is pending review and stores its source');
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
    $cal_js = file_get_contents(dirname((new ReflectionFunction('brb_view_select'))->getFileName()) . '/assets/brb.js');
    brb_t(is_string($cal_js) && strpos($cal_js, "credentials: 'omit'") !== false && strpos($cal_js, 'X-WP-Nonce') === false && strpos($cal_js, 'no-store') !== false, 'the calendar request omits the login cookie and does not send a nonce that only matches that cookie');
    $prev_user = get_current_user_id();
    $admin_user = get_user_by('login', 'admin');
    if ($admin_user) wp_set_current_user($admin_user->ID);
    $logged_nonce = wp_create_nonce('wp_rest');
    wp_set_current_user(0);
    $cal_url = 'http://127.0.0.1/wp-json/brb/v1/availability?month=' . rawurlencode(brb_now()->format('Y-m'));
    $open_cal = wp_remote_get($cal_url, ['timeout' => 15, 'headers' => ['Accept' => 'application/json']]);
    $nonce_only = wp_remote_get($cal_url, ['timeout' => 15, 'headers' => ['Accept' => 'application/json', 'X-WP-Nonce' => $logged_nonce]]);
    wp_set_current_user($prev_user);
    brb_t(!is_wp_error($open_cal) && (int) wp_remote_retrieve_response_code($open_cal) === 200 && strpos((string) wp_remote_retrieve_body($open_cal), '"days"') !== false, 'availability loads with no login cookie and no nonce');
    brb_t(!is_wp_error($nonce_only) && (int) wp_remote_retrieve_response_code($nonce_only) === 403, 'a logged-in REST nonce without its cookie is rejected');
    $routes = rest_get_server()->get_routes();
    $hook = '/brb/v1/' . $brand . '-webhook';
    $post_only = isset($routes[$hook]);
    if ($post_only) {
        foreach ($routes[$hook] as $endpoint) {
            $methods = isset($endpoint['methods']) && is_array($endpoint['methods']) ? $endpoint['methods'] : [];
            if (!empty($methods['GET']) || empty($methods['POST'])) $post_only = false;
        }
    }
    brb_t($post_only, 'the card webhook route is registered for POST only');

    $dirty = 'Payments are processed by ' . ucfirst($brand) . '; we do not store card numbers. Please pay securely online.';
    $clean = brb_scrub_copy_string($dirty);
    foreach ($banned as $w) brb_t(stripos($clean, $w) === false, "scrubbed copy has no $w");
    brb_t(stripos($clean, $brand) === false, 'scrubbed processor sentence does not keep the old processor name');
    brb_t(stripos($clean, 'check') !== false, 'scrubbed copy talks about a check');

    $faq = get_page_by_path('faq');
    $faq_html = $faq ? (string) $faq->post_content : '';
    brb_t(stripos($faq_html, 'How much does it cost?') !== false && stripos($faq_html, '$600') !== false && stripos($faq_html, '$1,200') !== false && stripos($faq_html, '$2,000') !== false, 'faq explains the price range');
    brb_t(stripos($faq_html, 'arrives not set up') !== false && stripos($faq_html, 'extra fee') === false && stripos($faq_html, 'Staff cannot set the room up') !== false && stripos($faq_html, '8:00 AM') !== false && stripos($faq_html, 'minimum host age is 25') !== false, 'faq explains setup and seating');
    brb_t(strpos($faq_html, 'Card payments get an emailed receipt') !== false && strpos($faq_html, 'Sales tax is included in the price.') !== false && stripos($faq_html, 'sales tax is added') === false, 'faq offers a receipt and says sales tax is included');
    brb_t(stripos($faq_html, 'Christmas decorations are up in December') !== false, 'faq explains decorations');
    brb_t(stripos($faq_html, 'take the garbage out') !== false && stripos($faq_html, 'following day') !== false, 'faq explains cleanup');
    brb_t(stripos($faq_html, '48 hours') !== false && stripos($faq_html, 'price to be confirmed') !== false, 'faq explains the hold, the notice, and an unconfirmed price');
    brb_t(strpos($faq_html, 'Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265.') !== false && strpos($faq_html, '2220 37th Street') === false, 'faq tells guests where to mail or drop off checks');
    brb_t(strpos($faq_html, 'online by card with a 3% processing fee') !== false && strpos($faq_html, '60 or more days before the event') !== false && strpos($faq_html, 'less than 60 days before the event') !== false && strpos($faq_html, '[VERIFY with attorney]') === false && strpos($faq_html, '[VERIFY: attorney review]') === false, 'faq states the 60-day deposit rule and has no attorney note');
    brb_t(strpos($faq_html, 'Are overnight stays allowed?') !== false && strpos($faq_html, 'No overnight stays. The Boardroom is an event space only.') !== false && stripos($faq_html, 'check-in') === false && stripos($faq_html, 'check-out') === false && strpos($faq_html, '[VERIFY] latest end time') === false, 'faq states the no-overnight rule without a public end time');
    brb_t(stripos($faq_html, 'How many guests can The Boardroom hold') !== false && stripos($faq_html, 'Up to 75') !== false, 'faq keeps the capacity answer');
    brb_t(strpos($faq_html, 'No cooking is allowed in the venue') !== false && strpos($faq_html, 'The stove top may not be used') !== false && strpos($faq_html, 'Food warmers (for keeping prepared food warm)') !== false, 'faq says no cooking and allows food warmers');
    brb_t(strpos($faq_html, 'https://moline.il.us/244/Liquor-Licensing') !== false && strpos($faq_html, '309-524-2004') !== false && strpos($faq_html, 'fee $50') === false && strpos($faq_html, '/rental-checklist/') !== false, 'faq points guests to the City of Moline and the rental checklist');
    if ($faq) {
        wp_update_post(['ID' => (int) $faq->ID, 'post_content' => '<p>Custom FAQ without the seed question.</p>']);
        clean_post_cache((int) $faq->ID);
        brb_refresh_faq();
        $custom = get_post((int) $faq->ID);
        brb_t($custom && strpos((string) $custom->post_content, 'Custom FAQ') !== false, 'a rewritten faq is not replaced');
        wp_update_post(['ID' => (int) $faq->ID, 'post_content' => brb_faq_markup()]);
        clean_post_cache((int) $faq->ID);
    } else {
        brb_t(false, 'a rewritten faq is not replaced');
    }

    brb_t(!get_page_by_path('privacy-policy') && !get_page_by_path('terms-and-conditions'), 'old privacy and terms slugs are not still published');
    $terms = get_page_by_path('terms');
    $privacy = get_page_by_path('privacy');
    $agreement = get_page_by_path('rental-agreement');
    brb_t($terms && $terms->post_title === 'Rental Terms', 'terms page is titled Rental Terms');
    brb_t($privacy && (int) get_option('wp_page_for_privacy_policy') === (int) $privacy->ID, 'privacy page is the site privacy policy');
    $terms_html = $terms ? (string) $terms->post_content : '';
    $privacy_html = $privacy ? (string) $privacy->post_content : '';
    $agreement_html = $agreement ? (string) $agreement->post_content : '';
    foreach ([
        'Rock Island County' => $terms_html,
        'Up to 75 guests' => $terms_html,
        'Glitter' => $terms_html,
        'Food, drinks, and equipment' => $terms_html,
        'stove-top' => $terms_html,
        'https://moline.il.us/244/Liquor-Licensing' => $terms_html,
        '2212 37th Street, Moline, IL 61265' => $terms_html,
        '2220 37th Street, Moline, IL 61265' => $terms_html,
        'Christmas décor is up' => $terms_html,
        'isn\'t set up' => $terms_html,
        'can\'t set the room for you' => $terms_html,
        'The host must be 25 or older' => $terms_html,
        'no later than 48 hours before' => $terms_html,
        'Balloons, yes' => $terms_html,
        'Wall tape' => $terms_html,
        'Sales tax is included in the price.' => $terms_html,
        'Last updated: October 28, 2026' => $terms_html,
        '60 or more days before your event' => $terms_html,
        'MAD Productions, LLC' => $privacy_html,
        'boardroomqc@gmail.com' => $privacy_html,
        'We never see or store card numbers' => $privacy_html,
        'FACILITY EVENT SPACE RENTAL AGREEMENT' => $agreement_html,
        'Rental Date:' => $agreement_html,
    ] as $needle => $hay) {
        brb_t(strpos($hay, $needle) !== false, 'legal copy contains ' . $needle);
    }
    brb_t(strpos($terms_html, 'No cooking or stove-top use') !== false && strpos($terms_html, 'Food warmers and the bar area are yours to use') !== false, 'terms say no cooking and allow food warmers');
    brb_t(strpos($terms_html, 'https://moline.il.us/244/Liquor-Licensing') !== false && strpos($terms_html, '309-524-2004') !== false && strpos($terms_html, 'fee $50') === false && strpos($agreement_html, 'https://moline.il.us/244/Liquor-Licensing') !== false && strpos($agreement_html, 'fee $50') === false, 'terms and the rental agreement point guests to the City of Moline without a liquor fee');
    brb_t(strpos($terms_html, '[VERIFY current fee and issuing office]') === false, 'liquor license fee is no longer marked for verification');
    brb_t(strpos($terms_html, '2220 37th Street, Moline, IL 61265') !== false && strpos($terms_html, 'MAD Productions, LLC') !== false, 'terms name the venue at 2220 37th Street');
    brb_t(strpos($terms_html, 'Make checks payable to Mad Productions and mail or drop them off at 2212 37th Street, Moline, IL 61265.') !== false, 'terms tell guests where to mail or drop off checks');
    brb_t(strpos($terms_html, '[VERIFY address.') === false && strpos($terms_html, '2212 47 Ave') === false && strpos($terms_html, '2212 37th St,') === false, 'terms drop the old unverified addresses');
    brb_t(strpos($privacy_html, 'at 2220 37th Street, Moline, IL 61265') !== false, 'privacy names the venue address');
    brb_t(strpos($privacy_html, 'Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265.') !== false, 'privacy names the check address separately');
    brb_t(strpos($agreement_html, '2220 37th Street, Moline, Illinois 61265') !== false && strpos($agreement_html, '2220 37th Street, Moline, IL 61265') !== false, 'signed agreement body keeps 2220 37th Street');
    brb_t(strpos($agreement_html, 'Staff cannot set the room up') !== false && strpos($agreement_html, 'The minimum host age is 25 for every booking') !== false && strpos($agreement_html, 'no later than 48 hours before the event') !== false && strpos($agreement_html, 'Balloons are allowed') !== false && strpos($agreement_html, 'wall tape') !== false, 'the rental agreement states setup, host age, balance, balloons, and wall tape');
    brb_t(strpos($agreement_html, '2212 37th Street') === false && strpos($agreement_html, '2212 47 Ave') === false, 'signed agreement body is not rewritten to the site address');
    $contact = get_page_by_path('contact');
    $contact_html = $contact ? (string) $contact->post_content : '';
    brb_t(strpos($contact_html, '2220 37th Street') === false && strpos($contact_html, 'mailto:') === false && strpos($contact_html, 'Get directions') === false && strpos($contact_html, 'PLACEHOLDER') === false, 'stored contact content does not repeat the address, email, or directions');
    brb_t(strpos($contact_html, '[boardroom_tour]') === false && strpos($contact_html, '[boardroom_contact]') === false && stripos($contact_html, 'Schedule a tour') === false, 'stored contact content no longer includes the old forms');
    $map = brb_map_embed();
    $contact_template = file_get_contents(get_stylesheet_directory() . '/page-contact.php');
    brb_t(strpos($map, '<iframe') !== false && strpos($map, 'loading="lazy"') !== false && strpos($map, 'google.com/maps/embed') !== false && strpos($map, '41.4913116') !== false && strpos($map, '-90.4820481') !== false && stripos($map, 'Load map') === false, 'the contact map iframe loads from Google with the venue coordinates');
    brb_t(is_string($contact_template) && strpos($contact_template, "brb_chat_markup('inline')") !== false && strpos($contact_template, 'brb_tour_form') !== false && strpos($contact_template, 'brb_map_embed') !== false && strpos($contact_template, "brb_chat_markup('inline')") < strpos($contact_template, 'brb_tour_form') && strpos($contact_template, 'brb_tour_form') < strpos($contact_template, 'brb_map_embed') && strpos($contact_template, 'mailto:') === false && strpos($contact_template, 'brb_directions_markup') === false && strpos($contact_template, 'br_tour_video_html') === false, 'the contact template is chat, then the form, then the map');
    $footer = file_get_contents(get_stylesheet_directory() . '/footer.php');
    brb_t(is_string($footer) && strpos($footer, '2220 37th Street, Moline, IL 61265') !== false && strpos($footer, '2212 37th Street') === false, 'footer shows the venue address');
    brb_t(is_string($footer) && strpos($footer, '/rental-checklist/') !== false, 'footer links to the rental checklist');
    $book_page = file_get_contents(get_stylesheet_directory() . '/page.php');
    brb_t(is_string($book_page) && strpos($book_page, '/rental-checklist/') !== false, 'the book page links to the rental checklist');
    $checklist_page = get_page_by_path('rental-checklist');
    $checklist_html = $checklist_page ? do_shortcode((string) $checklist_page->post_content) : '';
    brb_t($checklist_page && $checklist_page->post_title === 'Rental Checklist' && strpos($checklist_html, '8:00 AM') !== false && strpos($checklist_html, '309-524-2004') !== false && strpos($checklist_html, 'fee $50') === false && strpos($checklist_html, brb_whole_money(brb_addon_price())) !== false, 'the rental checklist page lists the house rules without a liquor fee');
    $remind_day = brb_now()->modify('+7 days')->format('Y-m-d');
    $remind = brb_process_public_booking([
        'date' => $remind_day,
        'type' => 'day',
        'days' => 1,
        'guests' => 6,
        'name' => 'Checklist Renter',
        'email' => 'checklist-renter@example.com',
        'policy' => '1', 'waiver' => '1',
        'event_type' => 'other',
    ] + brb_screen());
    brb_t(!is_wp_error($remind) && $remind, 'a booking a week out can be created for the checklist reminder');
    if (!is_wp_error($remind) && $remind) {
        $created_ids[] = (int) $remind->id;
        $wpdb->update(brb_table(), ['walkthrough_done' => 1], ['id' => (int) $remind->id]);
        $remind = brb_approve_booking($remind->id);
        $GLOBALS['brb_mails'] = [];
        brb_checklist_reminders();
        $remind_mail = null;
        $remind_mails = 0;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'checklist-renter@example.com') !== false && stripos($m['subject'], 'checklist') !== false) {
                $remind_mails++;
                $remind_mail = $m;
            }
        }
        $reminded = brb_booking((int) $remind->id);
        brb_t($remind_mails === 1 && $remind_mail && strpos($remind_mail['message'], 'about a week away') !== false && $reminded && !empty($reminded->checklist_reminded), 'the checklist reminder is sent about 7 days before the event');
        brb_checklist_reminders();
        $again = 0;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'checklist-renter@example.com') !== false && stripos($m['subject'], 'checklist') !== false) $again++;
        }
        brb_t($again === 1, 'the checklist reminder is sent once');
    } else {
        brb_t(false, 'the checklist reminder is sent about 7 days before the event');
        brb_t(false, 'the checklist reminder is sent once');
    }
    brb_t(is_string($footer) && strpos($footer, 'https://maps.app.goo.gl/nVqUsGTcy48nPzc18') !== false && strpos($footer, 'Get directions') !== false && strpos($footer, 'footer-map') !== false, 'footer links to Google Maps');
    ob_start();
    brb_place_schema();
    $schema = ob_get_clean();
    brb_t(strpos($schema, '41.4913116') !== false && strpos($schema, '-90.4820481') !== false && strpos($schema, '2220 37th Street') !== false && strpos($schema, '2212 37th Street') === false, 'schema geo uses the map coordinates and the venue address');
    brb_t(strpos($privacy_html, 'mailto:boardroomqc@gmail.com') !== false && strpos($privacy_html, '[VERIFY]</span>') === false, 'privacy email is confirmed without a verification marker');
    brb_t(strpos($privacy_html, 'sets no advertising, analytics, or tracking cookies of its own') !== false && strpos($privacy_html, 'embedded Google Maps in the footer and on the Contact page may set cookies from Google') !== false && strpos($privacy_html, "Google's privacy policy applies") !== false && strpos($privacy_html, 'not asked to accept cookies') !== false, 'privacy explains our cookies and the Google map');
    brb_t(strpos($privacy_html, 'does not process payments online') === false && strpos($privacy_html, 'Stripe processes card payments') !== false && strpos($privacy_html, 'Stripe may set cookies on its own checkout page') !== false, 'privacy says Stripe processes cards and may set cookies on its checkout page');
    brb_t(strpos($terms_html, 'Card payments add a 3% processing fee on top of the amount paid, and that fee isn\'t refundable.') !== false && strpos($terms_html, '[VERIFY with attorney]') === false && strpos($terms_html, '[VERIFY: attorney review]') === false && strpos($terms_html, '[ATTORNEY REVIEW]') === false, 'terms describe the card fee without an attorney note');
    brb_t(strpos($terms_html, '60 or more days before your event') !== false && strpos($terms_html, 'the deposit isn\'t refundable') !== false && strpos($agreement_html, 'sixty (60) days or more before the event') !== false && strpos($agreement_html, 'less than sixty (60) days before the event, the deposit is not refundable') !== false && strpos($privacy_html, '60 or more days before the event') !== false, 'terms, the rental agreement, and privacy state the 60-day deposit rule');
    brb_t(strpos($agreement_html, 'may be entitled to a refund of amounts paid') === false && strpos($agreement_html, 'shall refund amounts paid') === false, 'the rental agreement does not promise a deposit refund');
    brb_t(stripos($terms_html, 'no overnight stays') !== false && strpos($agreement_html, 'Overnight stays are not permitted. The Venue is an event space only.') !== false, 'terms and the rental agreement forbid overnight stays');
    brb_t(strpos($terms_html, '[VERIFY] latest end time') === false && strpos($agreement_html, '[VERIFY] latest end time') === false && stripos($terms_html, 'check-in') === false && stripos($agreement_html, 'check-in') === false, 'terms and the rental agreement do not publish an end time or check-in wording');
    brb_t(strpos($privacy_html, 'does not embed a map') === false && strpos($privacy_html, 'does not load Google') === false && strpos($privacy_html, 'does not use advertising, analytics, or tracking cookies.') === false && stripos($privacy_html, 'phone') === false, 'privacy no longer says the site uses no cookies or collects a phone number');
    brb_t(strpos($privacy_html, '[VERIFY if analytics added]') === false, 'analytics cookies are no longer marked for verification');
    foreach ([$terms_html, $privacy_html, $agreement_html] as $hay) {
        foreach ($banned as $w) brb_t(stripos($hay, $w) === false, 'legal copy has no ' . $w);
    }
    $refused = brb_process_public_booking([
        'date' => $later,
        'type' => 'day',
        'days' => 1,
        'guests' => 4,
        'name' => 'Flow Test',
        'email' => 'flow-test@example.com',
    ]);
    brb_t(is_wp_error($refused) && $refused->get_error_code() === 'policy', 'saving a date requires agreement to the terms and privacy policy');

    foreach ([
        'faq' => 'price to be confirmed',
        'privacy' => 'We never see or store card numbers',
        'terms' => 'The signed agreement controls',
        'rental-agreement' => 'IN WITNESS WHEREOF',
    ] as $slug => $needle) {
        $page = get_page_by_path($slug);
        $content = $page ? (string) $page->post_content : '';
        brb_t($page && stripos($content, $needle) !== false, "$slug says \"$needle\"");
        foreach ($banned as $w) brb_t(stripos($content, $w) === false, "$slug content has no $w");
    }
    $review_settings = brb_opt();
    $review_settings['pay_instructions'] = '[VERIFY] Test instructions.';
    update_option('brb_settings', $review_settings);
    $_GET = ['date' => $later, 'type' => 'day', 'days' => 1, 'guests' => 4];
    $quote_html = do_shortcode('[boardroom_booking]');
    brb_t(strpos($quote_html, 'Submit request') !== false, 'review page submit button is Submit request');
    brb_t(strpos($quote_html, 'name="phone"') === false && stripos($quote_html, 'autocomplete="tel"') === false, 'the public booking form does not ask for a phone number');
    brb_t(strpos($quote_html, 'I agree to the') !== false && strpos($quote_html, '>Terms<') !== false && strpos($quote_html, '>Rental Agreement<') !== false && strpos($quote_html, '>Privacy Policy<') !== false && strpos($quote_html, home_url('/terms/')) !== false && strpos($quote_html, home_url('/rental-agreement/')) !== false && strpos($quote_html, home_url('/privacy/')) !== false, 'review page links the terms, rental agreement, and privacy policy');
    brb_t(strpos($quote_html, 'No overnight stays. The Boardroom is an event space only.') !== false && strpos($quote_html, 'guests attending') !== false, 'review step states the no-overnight rule and guests attending');
    brb_t(stripos($quote_html, 'check-in') === false && stripos($quote_html, 'check-out') === false && stripos($quote_html, 'per night') === false && stripos($quote_html, 'per-night') === false && stripos($quote_html, 'guests staying') === false && strpos($quote_html, '[VERIFY] latest end time') === false, 'review step uses event wording');
    brb_t(strpos($quote_html, 'name="event_type"') !== false && strpos($quote_html, 'Wedding') !== false && strpos($quote_html, 'Birthday') !== false && strpos($quote_html, 'Corporate') !== false && strpos($quote_html, 'Fundraiser') !== false && strpos($quote_html, 'Other') !== false, 'review page asks for an event type');
    brb_t(strpos($quote_html, 'name="private_event"') !== false && strpos($quote_html, 'name="public_event"') === false && strpos($quote_html, 'name="free_admission"') !== false && strpos($quote_html, 'open to the public or ticketed') === false && strpos($quote_html, 'name="alcohol"') !== false && strpos($quote_html, 'name="end_time"') !== false && strpos($quote_html, 'max="23:30"') !== false && strpos($quote_html, '11:30 PM') !== false && strpos($quote_html, 'name="host_age"') !== false && strpos($quote_html, 'Tell us about your event') !== false && strpos($quote_html, 'Under 21') === false && strpos($quote_html, '21 to 24') === false && strpos($quote_html, 'Under 25') !== false && strpos($quote_html, '25 or older') !== false && strpos($quote_html, 'minimum host age is 25') !== false && strpos($quote_html, 'invitation-only') !== false, 'review page asks the screening questions');
    brb_t(brb_host_age_label('under_21') === 'Under 21' && brb_host_age_label('age_21_24') === '21 to 24' && brb_host_age_label('under_25') === 'Under 25' && !isset(brb_host_ages()['age_21_24']), 'older host-age answers still display and new bookings use 25');
    brb_t(strpos($quote_html, '60 or more days before the event') !== false && strpos($quote_html, 'less than 60 days before the event') !== false && strpos($quote_html, 'subject to approval') !== false, 'review page states approval and the 60-day deposit rule');
    brb_t(strpos($quote_html, '[VERIFY] Test instructions.') === false && strpos($quote_html, 'Extra instructions for bringing the check') === false && strpos($quote_html, '[VERIFY: attorney review]') === false, 'review page hides payment instructions and the attorney note');
    brb_t(stripos($quote_html, 'cash') === false, 'the public review step stays check-only');
    brb_t(strpos($quote_html, 'Pricing from $600 per day (most events around $1,200), final price confirmed based on date, guest count and features.') !== false, 'review page shows the from price');
    brb_t(stripos($quote_html, 'price to be confirmed') !== false, 'review page says price to be confirmed');
    brb_t(strpos($quote_html, '$500') === false && strpos($quote_html, '$75') === false && strpos($quote_html, '$100') === false && strpos($quote_html, '7%') === false && strpos($quote_html, 'Cleaning fee') === false, 'review page has no placeholder rate, fee, or tax');
    foreach ($banned as $w) brb_t(stripos($quote_html, $w) === false, "quote HTML has no $w");
    $_GET = [];
    $picker = do_shortcode('[boardroom_booking]');
    brb_t(strpos($picker, 'Continue') !== false && strpos($picker, 'hours from now') !== false, 'calendar explains the minimum notice');
    brb_t(strpos($picker, 'Pricing from $600 per day') !== false && strpos($picker, 'no separate hourly price') !== false, 'calendar uses the from price for a shorter block');
    brb_t(strpos($picker, '$500') === false && strpos($picker, '$75') === false && strpos($picker, '/hr') === false, 'calendar has no hourly dollar rate');

    $legacy = $wpdb->insert(brb_table(), [
        'token' => 'legacyprice' . wp_generate_password(8, false),
        'mode' => 'day',
        'start_dt' => '2031-04-04 00:00:00',
        'end_dt' => '2031-04-05 00:00:00',
        'guests' => 4,
        'name' => 'Legacy',
        'email' => 'flow-test@example.com',
        'subtotal' => '500.00',
        'cleaning' => '100.00',
        'tax' => '42.00',
        'total' => '642.00',
        'amount_due' => '642.00',
        'deposit_due' => '642.00',
        'amount_paid' => '0.00',
        'status' => 'held',
        'source' => 'online',
        'price_set' => 0,
        'created' => current_time('mysql', true),
    ]);
    brb_t((bool) $legacy, 'a historical priced row can be inserted');
    if ($legacy) {
        $legacy_id = (int) $wpdb->insert_id;
        $created_ids[] = $legacy_id;
        brb_migrate_prices();
        $kept = brb_booking($legacy_id);
        brb_t($kept && (int) $kept->price_set === 1 && abs((float) $kept->total - 642) < 0.01, 'an existing priced booking keeps its total');
    }

    $cash_day = brb_now()->modify('+21 days')->format('Y-m-d');
    $cash_b = brb_process_public_booking([
        'date' => $cash_day,
        'type' => 'day',
        'days' => 1,
        'guests' => 8,
        'name' => 'Cash Test',
        'email' => 'flow-test@example.com',
        'policy' => '1', 'waiver' => '1',
        'event_type' => 'birthday',
    ] + brb_screen());
    brb_t(!is_wp_error($cash_b) && $cash_b && $cash_b->event_type === 'birthday', 'a birthday booking stores its event type');
    if ($cash_b && !is_wp_error($cash_b)) {
        $created_ids[] = (int) $cash_b->id;
        $priced_cash = brb_set_agreed_price($cash_b->id, 800);
        brb_t(!is_wp_error($priced_cash), 'staff can price a booking before recording cash');
        $GLOBALS['brb_mails'] = [];
        $cash_after = brb_record_payment($cash_b->id, 400, '9999', brb_now()->format('Y-m-d'), 'Walk-in cash', true, 'cash');
        brb_t(!is_wp_error($cash_after) && $cash_after->status === 'deposit_paid' && abs((float) $cash_after->amount_paid - 400) < 0.01, 'cash uses the same deposit and balance rules');
        $cash_rows = brb_payments($cash_b->id);
        brb_t(count($cash_rows) === 1 && $cash_rows[0]->method === 'cash' && $cash_rows[0]->check_number === '', 'cash stores no check number');
        $cash_mail = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'flow-test@example.com') !== false) $cash_mail = $m;
        }
        brb_t($cash_mail && strpos($cash_mail['message'], 'We recorded cash for') !== false && strpos($cash_mail['message'], '9999') === false, 'the guest receipt says cash and omits a check number');
        brb_t($cash_mail && strpos($cash_mail['message'], 'Event type: Birthday') !== false, 'the cash receipt names the event type');
        brb_t($cash_mail && strpos($cash_mail['message'], 'Thank you for contacting The Boardroom!') === 0 && strpos($cash_mail['message'], 'Amount: ' . brb_money(400)) !== false && strpos($cash_mail['message'], 'Date: ' . brb_now()->format('F j, Y')) !== false && strpos($cash_mail['message'], 'Method: Cash') !== false && strpos($cash_mail['message'], 'Booking reference: ' . brb_ref($cash_b)) !== false && strpos($cash_mail['message'], 'Event date:') !== false && strpos($cash_mail['message'], 'Balance remaining:') !== false && stripos($cash_mail['message'], 'sales tax') === false, 'the cash receipt lists the payment and does not mention sales tax');
        $staff_receipt = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'flow-test@example.com') === false && strpos($m['message'], 'Method: Cash') !== false) $staff_receipt = $m;
        }
        brb_t((bool) $staff_receipt, 'staff receive a copy of the receipt');
        if ($user) {
            ob_start();
            brb_admin_detail((int) $cash_b->id);
            $cash_admin = ob_get_clean();
            brb_t(strpos($cash_admin, 'Record payment') !== false && strpos($cash_admin, 'name="method"') !== false && strpos($cash_admin, '>Cash<') !== false && strpos($cash_admin, 'Delete booking') === false, 'staff can record cash and cannot delete an open booking');
            brb_t(strpos($cash_admin, 'name="check_number" id="check_number" class="regular-text"') !== false && strpos($cash_admin, 'name="check_number" id="check_number" class="regular-text" required') === false, 'the check number field is present and not required');
        }
        $_GET = ['br_token' => $cash_after && !is_wp_error($cash_after) ? $cash_after->token : ''];
        $cash_html = do_shortcode('[boardroom_booking]');
        brb_t(strpos($cash_html, 'We have recorded a cash payment') !== false && strpos($cash_html, 'remaining balance as a check') !== false, 'the confirmation names the cash payment and still asks for the balance by check');
    }

    $del_day = brb_now()->modify('+22 days')->format('Y-m-d');
    $del = brb_process_public_booking([
        'date' => $del_day,
        'type' => 'day',
        'days' => 1,
        'guests' => 5,
        'name' => 'Delete Test',
        'email' => 'flow-test@example.com',
        'policy' => '1', 'waiver' => '1',
        'event_type' => 'other',
    ] + brb_screen());
    brb_t(!is_wp_error($del) && $del, 'a booking can be created for the delete test');
    if ($del && !is_wp_error($del)) {
        $created_ids[] = (int) $del->id;
        $priced_del = brb_set_agreed_price($del->id, 500);
        $paid_del = brb_record_payment($del->id, 100, '4242', brb_now()->format('Y-m-d'), 'Partial', true);
        brb_t(!is_wp_error($priced_del) && !is_wp_error($paid_del), 'the delete test booking has a payment row');
        $denied = brb_delete_booking($del->id);
        brb_t(is_wp_error($denied) && $denied->get_error_code() === 'status', 'a booking that is not cancelled cannot be deleted');
        if ($user) {
            ob_start();
            brb_admin_detail((int) $del->id);
            $open_html = ob_get_clean();
            brb_t(strpos($open_html, 'Delete booking') === false, 'delete stays off the page until the booking is cancelled');
        }
        $cancelled = brb_cancel_booking($del->id);
        brb_t(!is_wp_error($cancelled) && $cancelled->status === 'cancelled', 'staff can cancel before deleting');
        if ($user) {
            ob_start();
            brb_admin_detail((int) $del->id);
            $cancelled_html = ob_get_clean();
            brb_t(strpos($cancelled_html, 'Delete booking') !== false && strpos($cancelled_html, 'value="brb_delete"') !== false && strpos($cancelled_html, 'This cannot be undone') !== false && strpos($cancelled_html, '_wpnonce') !== false, 'a cancelled booking offers a confirmed delete');
        }
        $removed = brb_delete_booking($del->id);
        brb_t($removed === true && !brb_booking($del->id) && brb_payments($del->id) === [], 'deleting a cancelled booking removes it and its payment rows');
    }

    $smtp_keys = ['BRB_SMTP_HOST', 'BRB_SMTP_PORT', 'BRB_SMTP_USER', 'BRB_SMTP_PASS', 'BRB_SMTP_FROM', 'BRB_SMTP_FROM_NAME'];
    $smtp_clear = function () use ($smtp_keys) {
        foreach ($smtp_keys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    };
    $smtp_clear();
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    }
    $plain = new PHPMailer\PHPMailer\PHPMailer(true);
    brb_configure_phpmailer($plain);
    brb_t($plain->Mailer !== 'smtp', 'smtp stays off until the mail user and password are set');
    $secret = 'smtp-secret-not-real';
    putenv('BRB_SMTP_USER=boardroomqc@gmail.com');
    putenv('BRB_SMTP_PASS=' . $secret);
    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    brb_configure_phpmailer($mailer);
    brb_t($mailer->Mailer === 'smtp' && $mailer->Host === 'smtp.gmail.com' && (int) $mailer->Port === 587, 'smtp defaults to smtp.gmail.com port 587');
    brb_t($mailer->SMTPAuth === true && $mailer->SMTPSecure === PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS, 'smtp uses STARTTLS authentication');
    brb_t($mailer->From === 'boardroomqc@gmail.com' && $mailer->FromName === 'The Boardroom', 'smtp from defaults to The Boardroom at boardroomqc@gmail.com');
    brb_t($mailer->Password === $secret && $mailer->SMTPDebug === 0, 'smtp keeps the password on the mailer and turns debug off');
    putenv('BRB_SMTP_HOST=smtp.example.test');
    putenv('BRB_SMTP_PORT=2525');
    putenv('BRB_SMTP_FROM=desk@example.com');
    putenv('BRB_SMTP_FROM_NAME=Desk');
    $custom = new PHPMailer\PHPMailer\PHPMailer(true);
    brb_configure_phpmailer($custom);
    brb_t($custom->Host === 'smtp.example.test' && (int) $custom->Port === 2525 && $custom->From === 'desk@example.com' && $custom->FromName === 'Desk', 'smtp host, port, and from follow the env vars');
    $mail_settings = brb_opt();
    $mail_settings['admin_email'] = 'admin@example.com';
    update_option('brb_settings', $mail_settings);
    unset($_POST['brb_save']);
    if ($user) {
        ob_start();
        brb_admin_settings();
        $mail_html = ob_get_clean();
        brb_t(strpos($mail_html, 'Send test email') !== false && strpos($mail_html, 'value="brb_test_email"') !== false && strpos($mail_html, '_wpnonce') !== false, 'settings include a test email button and nonce');
        brb_t(strpos($mail_html, $secret) === false, 'settings do not show the smtp password');
        $previous_user = get_current_user_id();
        wp_set_current_user(0);
        ob_start();
        brb_admin_settings();
        $guest_html = ob_get_clean();
        brb_t($guest_html === '', 'settings and the test email button are admin only');
        add_filter('wp_die_handler', function () {
            return function ($message) {
                throw new RuntimeException(is_string($message) ? $message : 'die');
            };
        });
        $refused = false;
        try { brb_admin_gate('brb_test_email'); } catch (RuntimeException $e) { $refused = true; }
        brb_t($refused, 'the test email action refuses a guest');
        wp_set_current_user($previous_user);
        $bad_nonce = false;
        try { brb_admin_gate('brb_test_email'); } catch (RuntimeException $e) { $bad_nonce = true; }
        brb_t($bad_nonce, 'the test email action requires its nonce');
        $_REQUEST['_wpnonce'] = wp_create_nonce('brb_test_email');
        $allowed = true;
        try { brb_admin_gate('brb_test_email'); } catch (RuntimeException $e) { $allowed = false; }
        brb_t($allowed, 'an admin with the nonce can send a test email');
        remove_all_filters('wp_die_handler');
    }
    $mail_list_saved = get_option('brb_settings');
    $mail_list = is_array($mail_list_saved) ? $mail_list_saved : [];
    $mail_list['admin_email'] = "one@example.com, bad-address\ntwo@example.com; three@example.com  four@example.com";
    update_option('brb_settings', $mail_list);
    $parsed_list = brb_parse_email_list(brb_opt('admin_email'));
    brb_t($parsed_list['valid'] === ['one@example.com', 'two@example.com', 'three@example.com', 'four@example.com'], 'staff addresses split on commas, semicolons, spaces, and new lines');
    brb_t($parsed_list['invalid'] === ['bad-address'], 'an address without @ is kept out of the recipient list');
    $mashed_by_wp = sanitize_email('first@example.com,second@example.com');
    $split_pair = brb_parse_email_list('first@example.com,second@example.com');
    brb_t($split_pair['valid'] === ['first@example.com', 'second@example.com'] && $split_pair['invalid'] === [] && $mashed_by_wp !== 'first@example.com,second@example.com', 'a comma-separated list is two addresses, and it is split before WordPress can mash it');
    ob_start();
    echo brb_admin_email_notice_html();
    $notice_html = ob_get_clean();
    brb_t(strpos($notice_html, 'notice-error') !== false && strpos($notice_html, 'bad-address') !== false && strpos($notice_html, 'not a valid email') !== false && strpos($notice_html, 'Invalid entries are not emailed.') !== false, 'an invalid staff address shows an admin warning');
    $GLOBALS['brb_mails'] = [];
    brb_mail_staff('Staff list check', 'Separate copies.');
    $staff_tos = [];
    foreach ($GLOBALS['brb_mails'] as $m) {
        if (($m['subject'] ?? '') === 'Staff list check') $staff_tos[] = $m['to'];
    }
    brb_t($staff_tos === ['one@example.com', 'two@example.com', 'three@example.com', 'four@example.com'], 'each valid staff address gets its own message');
    brb_t(implode('', $staff_tos) !== '' && strpos(implode('|', $staff_tos), 'one@example.comtwo@example.com') === false, 'staff recipients are not concatenated into one address');
    update_option('brb_settings', $mail_list_saved);
    $GLOBALS['brb_mails'] = [];
    $sent = brb_send_test_email();
    brb_t($sent === ['admin@example.com'], 'the test email goes to the admin notification email');
    brb_t(isset($GLOBALS['brb_mails'][0]['subject']) && $GLOBALS['brb_mails'][0]['subject'] === 'Boardroom email test', 'the test email has a fixed subject');
    add_filter('pre_wp_mail', function ($short, $atts) {
        do_action('wp_mail_failed', new WP_Error('wp_mail_failed', 'SMTP Error: Could not authenticate using ' . brb_env('BRB_SMTP_PASS')));
        return false;
    }, 20, 2);
    $failed = brb_send_test_email();
    remove_all_filters('pre_wp_mail', 20);
    brb_t(is_wp_error($failed) && strpos($failed->get_error_message(), 'SMTP Error: Could not authenticate') !== false, 'a failed test email shows the PHPMailer error');
    brb_t(is_wp_error($failed) && strpos($failed->get_error_message(), '[redacted]') !== false && strpos($failed->get_error_message(), $secret) === false, 'a failed test email does not include the password');
    $smtp_clear();
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
    foreach (['BRB_SMTP_HOST', 'BRB_SMTP_PORT', 'BRB_SMTP_USER', 'BRB_SMTP_PASS', 'BRB_SMTP_FROM', 'BRB_SMTP_FROM_NAME'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

echo "\n{$GLOBALS['brb_ok']} passed, {$GLOBALS['brb_fail']} failed\n";
exit($GLOBALS['brb_fail'] ? 1 : 0);
