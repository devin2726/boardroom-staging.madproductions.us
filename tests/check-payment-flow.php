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
        'phone' => '555-0100',
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
    brb_t(get_option('brb_version') === '2.5.54', 'plugin storage version is 2.5.54');
    $cc_before = (string) brb_opt('inquiry_cc');
    delete_option('brb_inquiry_cc_seeded');
    $cc_settings = get_option('brb_settings');
    if (!is_array($cc_settings)) $cc_settings = [];
    $cc_settings['inquiry_cc'] = 'kept@example.com';
    update_option('brb_settings', $cc_settings);
    brb_ensure_inquiry_cc();
    $cc_list = array_map('strtolower', brb_inquiry_cc_list());
    brb_t(in_array('contiveros8@gmail.com', $cc_list, true) && in_array('kept@example.com', $cc_list, true) && count(array_keys($cc_list, 'contiveros8@gmail.com', true)) === 1, 'new inquiries copy contiveros8@gmail.com once and keep a saved address');
    brb_ensure_inquiry_cc();
    brb_t(count(array_keys(array_map('strtolower', brb_inquiry_cc_list()), 'contiveros8@gmail.com', true)) === 1, 'the inquiry copy upgrade does not duplicate the address');
    $cc_settings['inquiry_cc'] = 'kept@example.com';
    update_option('brb_settings', $cc_settings);
    brb_ensure_inquiry_cc();
    brb_t(!in_array('contiveros8@gmail.com', array_map('strtolower', brb_inquiry_cc_list()), true), 'staff can remove the inquiry copy and it stays removed');
    brb_t(strpos(brb_faq_markup(), 'contiveros8@gmail.com') === false && strpos(brb_hours_line(), 'contiveros8@gmail.com') === false, 'the inquiry copy address is not in public copy');
    $cc_settings['inquiry_cc'] = $cc_before;
    update_option('brb_settings', $cc_settings);
    $example = brb_first_payment_parts(1400);
    brb_t($example['deposit'] === 700.0 && $example['damage'] === 250.0 && $example['base'] === 950.0 && abs($example['card'] - 978.50) < 0.001, 'a $1,400 rental first card payment is 700 + 250 plus 3 percent');
    brb_t(brb_latest_start_hour() === 21, 'the latest shorter-block start is 9:00 PM');
    $saved_settings = brb_opt();
    $bo = $saved_settings;
    $bo['blackouts'] = "2031-01-02\n2027-04-17\n2026-12-06\n2027-02-07";
    update_option('brb_settings', $bo);
    brb_import_named_blackouts();
    brb_import_named_blackouts();
    $left = brb_blackouts();
    brb_t(in_array('2031-01-02', $left, true) && !in_array('2027-04-17', $left, true) && !in_array('2026-12-06', $left, true) && !in_array('2027-02-07', $left, true), 'named blackouts are removed and other blackouts stay');
    $hold_names = [];
    $hold_ids = [];
    foreach (brb_holds_overlapping('2026-01-01 00:00:00', '2028-01-01 00:00:00') as $hold) {
        if (($hold->hold_type ?? '') === 'reserved') {
            $hold_names[] = $hold->name;
            $hold_ids[] = (int) $hold->id;
        }
    }
    brb_t(in_array('Karyn Miller (paid elsewhere)', $hold_names, true) && in_array('Kevin Iams', $hold_names, true) && in_array('Brody', $hold_names, true) && count(array_keys($hold_names, 'Brody', true)) === 1, 'the three dates become one reserved hold each');
    foreach ($hold_ids as $hold_id) brb_delete_hold($hold_id);
    update_option('brb_settings', $saved_settings);
    brb_t(get_option('blog_public') === '0' || get_option('blog_public') === 0, 'staging discourages search engines');
    brb_t(!brb_search_indexing_allowed(), 'indexing stays off unless production mode or search discouragement is turned off');
    putenv('BRB_ENV=production');
    $_ENV['BRB_ENV'] = 'production';
    $_SERVER['BRB_ENV'] = 'production';
    brb_apply_search_visibility();
    brb_t(brb_search_indexing_allowed() && ((string) get_option('blog_public') === '1'), 'BRB_ENV=production allows search indexing');
    putenv('BRB_ENV');
    unset($_ENV['BRB_ENV'], $_SERVER['BRB_ENV']);
    putenv('DISCOURAGE_SEARCH=0');
    $_ENV['DISCOURAGE_SEARCH'] = '0';
    $_SERVER['DISCOURAGE_SEARCH'] = '0';
    brb_apply_search_visibility();
    brb_t(brb_search_indexing_allowed() && ((string) get_option('blog_public') === '1'), 'DISCOURAGE_SEARCH=0 allows search indexing');
    putenv('DISCOURAGE_SEARCH=1');
    $_ENV['DISCOURAGE_SEARCH'] = '1';
    $_SERVER['DISCOURAGE_SEARCH'] = '1';
    brb_apply_search_visibility();
    brb_t(!brb_search_indexing_allowed() && ((string) get_option('blog_public') === '0'), 'staging without BRB_ENV stays noindex');
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
    brb_t(strpos((string) brb_opt('payee'), '[VERIFY]') === false, 'the stored payee is not a placeholder');
    $payee_check = brb_opt();
    $payee_check['payee'] = '[VERIFY] Name on the check';
    update_option('brb_settings', $payee_check);
    brb_migrate_settings();
    brb_t((string) brb_opt('payee') === 'Mad Productions', 'an existing placeholder payee becomes Mad Productions');
    brb_t((string) brb_opt('venue_address') === '2220 37th Street, Moline, IL 61265', 'venue address defaults to 2220 37th Street');
    brb_t((string) brb_opt('pay_location') === '2220 37th Street, Moline, IL 61265', 'check drop-off defaults to the venue at 2220 37th Street');
    brb_t(brb_check_sentence() === 'Mail or drop off checks payable to Mad Productions at The Boardroom, 2220 37th Street, Moline, IL 61265.', 'the default check sentence names Mad Productions and The Boardroom');
    brb_t(strpos((string) brb_opt('pay_hours'), '[VERIFY]') === false && strpos(brb_appointment_line(), 'by appointment') !== false && strpos(brb_appointment_line(), 'set hours') !== false, 'in-person hours are not a placeholder; guests are told meetings are by appointment');
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
    brb_t((string) brb_opt('pay_location') === '2220 37th Street, Moline, IL 61265', 'a placeholder drop-off address is replaced on upgrade');
    $kept = brb_opt();
    $kept['pay_location'] = '2212 47 Ave';
    update_option('brb_settings', $kept);
    brb_migrate_settings();
    brb_t((string) brb_opt('pay_location') === '2220 37th Street, Moline, IL 61265', 'the old 2212 47 Ave drop-off is replaced on upgrade');
    $kept = brb_opt();
    $kept['pay_location'] = 'Side door';
    update_option('brb_settings', $kept);
    brb_migrate_settings();
    brb_t((string) brb_opt('pay_location') === 'Side door', 'a custom drop-off location is kept');
    $kept['pay_location'] = '2220 37th Street, Moline, IL 61265';
    $kept['policy'] = 'Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265. Keep the rest.';
    update_option('brb_settings', $kept);
    brb_migrate_settings();
    brb_t(strpos((string) brb_opt('policy'), 'Mail or drop off checks payable to Mad Productions at The Boardroom, 2220 37th Street, Moline, IL 61265.') !== false && strpos((string) brb_opt('policy'), '2212') === false && strpos((string) brb_opt('policy'), 'Keep the rest.') !== false, 'a saved summary check address moves to The Boardroom');
    $kept = brb_opt();
    $kept['policy'] = 'Keep this policy text.';
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
    brb_t(strpos((string) brb_opt('policy'), 'no later than one week before the event') !== false && strpos((string) brb_opt('policy'), '30 days before the event') === false && strpos((string) brb_opt('policy'), '48 hours before the event') === false && strpos((string) brb_opt('policy'), 'Keep the rest.') !== false, 'a saved 30-day balance sentence becomes the one-week rule');
    $balance_policy = brb_opt();
    $balance_policy['policy'] = $balance_before;
    update_option('brb_settings', $balance_policy);
    brb_t(($migrated['admin_email'] ?? '') === 'admin@example.com', 'admin email is kept');
    brb_t(!array_key_exists($brand . '_sk', $migrated) && !isset($migrated['hold_minutes']), 'legacy keys are dropped');
    brb_t(($migrated['deposit_type'] ?? '') === 'percent' && (string) ($migrated['deposit_pct'] ?? '') === '50', 'a legacy full deposit becomes a 50 percent deposit');
    brb_t(($migrated['hold_hours'] ?? '') === '48' && ($migrated['min_notice_hours'] ?? '') === '48', 'new hour settings default to 48');

    $blocked = new DateTimeImmutable('2030-01-03 00:00:00', brb_tz());
    brb_t(!brb_is_free($blocked, $blocked->modify('+1 day')), 'a blackout date is not free');
    $hold = brb_save_hold([
        'name' => 'Private rehearsal',
        'hold_type' => 'hold',
        'start_date' => '2030-06-20',
        'start_time' => '14:00',
        'end_date' => '2030-06-20',
        'end_time' => '17:00',
        'notes' => 'Unpaid hold',
    ]);
    brb_t(!is_wp_error($hold) && $hold && $hold->name === 'Private rehearsal', 'staff can save a named hold');
    $hold_start = new DateTimeImmutable('2030-06-20 14:00:00', brb_tz());
    $hold_end = new DateTimeImmutable('2030-06-20 17:00:00', brb_tz());
    brb_t(!is_wp_error($hold) && !brb_is_free($hold_start, $hold_end), 'a named hold blocks that time');
    $cal_range = brb_build_range(['date' => '2030-06-15', 'type' => 'day', 'days' => 1, 'guests' => 10]);
    $cal_guest = is_wp_error($cal_range) ? $cal_range : brb_insert_booking($cal_range, brb_quote($cal_range), [
        'name' => 'Calendar Guest',
        'email' => 'cal-guest@example.com',
        'phone' => '',
        'notes' => '',
    ], 'phone', false);
    if ($cal_guest && !is_wp_error($cal_guest)) {
        $created_ids[] = (int) $cal_guest->id;
        $wpdb->update(brb_table(), ['status' => 'paid'], ['id' => (int) $cal_guest->id]);
    }
    $feed_req = new WP_REST_Request('GET', '/brb/v1/availability');
    $feed_req->set_param('month', '2030-06');
    $feed = rest_do_request($feed_req);
    $feed_blob = wp_json_encode($feed->get_data());
    $feed_days = $feed->get_data()['days'] ?? [];
    brb_t(is_string($feed_blob) && strpos($feed_blob, 'Private rehearsal') === false && strpos($feed_blob, 'Calendar Guest') === false, 'the public calendar does not publish names');
    brb_t(isset($feed_days['2030-06-20']['status']) && $feed_days['2030-06-20']['status'] !== 'free', 'a named hold is unavailable to guests');
    $saved_get = $_GET;
    $_GET = ['page' => 'brb-cal', 'm' => '2030-06'];
    ob_start();
    brb_admin_cal();
    $cal_html = ob_get_clean();
    $_GET = ['page' => 'brb-cal', 'm' => '2030-01'];
    ob_start();
    brb_admin_cal();
    $jan_html = ob_get_clean();
    $_GET = $saved_get;
    brb_t(strpos($cal_html, 'Calendar Guest') !== false && strpos($cal_html, 'Paid in full') !== false && strpos($cal_html, 'page=brb') !== false && strpos($cal_html, 'booking=') !== false, 'the admin calendar shows the guest and links to the booking');
    brb_t(strpos($cal_html, 'Private rehearsal') !== false && strpos($cal_html, 'brb-chip-hold') !== false && strpos($cal_html, '>Today<') !== false, 'the admin calendar shows the named hold and a Today button');
    brb_t(strpos($jan_html, '<strong>Blocked</strong>') !== false, 'a bare blackout is shown as Blocked');
    if ($hold && !is_wp_error($hold)) brb_delete_hold($hold->id);

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
        'phone' => '555-0100',
        'policy' => '1', 'waiver' => '1',
        'free_admission' => '1',
        'event_about' => 'A family dinner.',
    ]);
    brb_t(!is_wp_error($missing_type) && $missing_type && ($missing_type->event_type ?? '') === '' && ($missing_type->alcohol ?? '') === '', 'a public save does not require an event type or alcohol');
    if ($missing_type && !is_wp_error($missing_type)) $created_ids[] = (int) $missing_type->id;
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
    brb_t(is_wp_error($half_ok) && $half_ok->get_error_code() === 'type', 'a half-day request is rejected');
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
    brb_t(is_wp_error($half_no) && $half_no->get_error_code() === 'type', 'an evening half day is rejected');

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
        brb_t($b->public_event === '' && ($b->free_admission ?? '') === '1' && ($b->event_type ?? '') === '' && ($b->alcohol ?? '') === '' && $b->end_time === '' && $b->host_age === '' && strpos((string) $b->event_about, 'private dinner') !== false, 'free admission is stored, and event type, alcohol, and the removed questions are not');
        if ((int) $b->price_set === 1) {
            brb_t((float) $b->total > 0 && brb_balance_due_display($b) !== 'Price to be confirmed', 'a calendar price is saved and the balance due date is shown');
        } else {
            brb_t(brb_balance_due_display($b) === 'Price to be confirmed', 'an unpriced request does not show a balance due date');
        }
        if ($user) {
            ob_start();
            brb_admin_detail((int) $b->id);
            $unpriced_admin = ob_get_clean();
            $past_label = brb_balance_due_label($b->balance_due_on);
            if ((int) $b->price_set === 1) {
                brb_t(strpos($unpriced_admin, 'Balance due') !== false && $past_label !== '' && strpos($unpriced_admin, $past_label) !== false, 'staff detail shows the balance date for a calendar price');
                brb_t(strpos($unpriced_admin, brb_money($b->total)) !== false, 'staff detail shows the stored calendar price');
            } else {
                brb_t(strpos($unpriced_admin, 'Price to be confirmed') !== false && strpos($unpriced_admin, 'Balance due') !== false, 'staff detail says the balance date waits for a price');
                brb_t($past_label !== '' && strpos($unpriced_admin, $past_label) === false, 'staff detail does not show a balance date before a price exists');
            }
        }
        $late_day = brb_now()->modify('+21 days')->format('Y-m-d');
        $late = brb_process_public_booking(array_merge($post, ['date' => $late_day, 'end_time' => '01:35', 'host_age' => 'nope', 'private_event' => 'maybe']));
        brb_t(!is_wp_error($late) && $late && $late->end_time === '' && $late->host_age === '' && $late->public_event === '', 'an end time, host age, or private-gathering answer is ignored');
        if ($late && !is_wp_error($late)) $created_ids[] = (int) $late->id;
        $multi = brb_process_public_booking(array_merge($post, ['date' => brb_now()->modify('+22 days')->format('Y-m-d'), 'days' => 3]));
        brb_t(is_wp_error($multi) && $multi->get_error_code() === 'days', 'more than one day is rejected');
        $before_start = brb_process_public_booking(array_merge($post, ['date' => brb_now()->modify('+23 days')->format('Y-m-d'), 'type' => 'hourly', 'start_hour' => 18, 'hours' => 2]));
        brb_t(is_wp_error($before_start) && $before_start->get_error_code() === 'type', 'a shorter block is rejected');
        $js = file_get_contents(WP_PLUGIN_DIR . '/boardroom-booking/assets/brb.js');
        brb_t(is_string($js) && strpos($js, 'Half days') === false && strpos($js, 'switch to hourly') === false, 'the booking script does not offer a shorter block');
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
            brb_t(strpos($soon_admin, 'Private, invitation-only') === false && strpos($soon_admin, 'Estimated Guests') !== false && strpos($soon_admin, 'Free admission') !== false && strpos($soon_admin, 'Acknowledged') !== false, 'staff detail shows estimated guests and the admission acknowledgment');
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
            brb_t(strpos($body, 'Thank you for contacting The Boardroom!') === 0 && strpos($body, 'email you a payment link') !== false && strpos($body, 'once your deposit is received and approved') !== false && strpos($body, 'email a price') === false && strpos($body, 'meet every renter in person') === false, 'request email opens with thanks and the review steps');
            brb_t(strpos($body, 'Event type:') === false && strpos($body, 'Alcohol served:') === false, 'request email leaves out event type and alcohol');
            brb_t(strpos($body, 'Private, invitation-only') === false && strpos($body, 'Host age bracket') === false && strpos($body, 'Planned end time') === false && strpos($body, 'Estimated Guests:') !== false && strpos($body, 'Free admission acknowledged: Yes') !== false && strpos($body, 'A private dinner for family.') !== false && strpos($body, '11:30 PM') !== false && strpos($body, 'no tickets') !== false, 'request email includes the remaining screening answers and the house rules');
            brb_t(strpos($body, '[VERIFY] Test instructions.') === false && strpos($body, 'payable to [VERIFY] Test Payee') === false, 'request email does not include payment instructions');
            brb_t(strpos($body, 'Extra instructions for bringing the check') === false, 'the default extra-instructions placeholder is not in the request email');
            if ((int) $b->price_set === 1) {
                brb_t(strpos($body, brb_money($b->total)) !== false, 'email shows the calendar price');
            } else {
                brb_t(stripos($body, 'price to be confirmed') !== false, 'email says price to be confirmed');
            }
            brb_t(strpos($body, 'No overnight stays. The Boardroom is an event space only.') !== false, 'request email states the no-overnight rule');
            brb_t(stripos($body, 'check-in') === false && stripos($body, 'check-out') === false && stripos($body, 'per night') === false && stripos($body, 'per-night') === false && stripos($body, 'guests staying') === false && strpos($body, '[VERIFY] latest end time') === false && strpos($body, '[VERIFY: attorney review]') === false, 'request email uses event wording and has no attorney note');
            brb_t(strpos($body, '$0.00') === false && strpos($body, '$500') === false && strpos($body, '$75') === false && strpos($body, '$100') === false, 'request email has no placeholder or zero total');
            $calendar_rate = brb_rate_for_date(substr((string) $b->start_dt, 0, 10), 'full');
            if ($calendar_rate !== null) {
                brb_t((int) $b->price_set === 1 && abs((float) $b->total - $calendar_rate) < 0.01, 'a public request stores the calendar rate');
            } else {
                brb_t((int) $b->price_set === 0 && (float) $b->total == 0.0, 'a public request does not invent a total when the date has no rate');
            }
            brb_t(($b->event_type ?? '') === '', 'a new public booking does not store an event type');
            brb_t($b->phone === '555-0100', 'a public save stores the guest phone number');
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
        brb_t(strpos($html, 'Your request is in.') !== false && strpos($html, 'Nothing is charged yet') !== false && strpos($html, 'email you a payment link') !== false && strpos($html, 'email a price') === false && strpos($html, 'How booking works') === false, 'confirmation page thanks the guest and says nothing is charged yet');
        brb_t(strpos($html, 'Wedding') === false && strpos($html, 'Alcohol served') === false, 'confirmation page leaves out event type and alcohol for a new booking');
        brb_t(strpos($html, '[VERIFY] Test instructions.') === false && strpos($html, 'Extra instructions for bringing the check') === false, 'confirmation hides payment instructions until the request is approved');
        brb_t(strpos($html, 'Pending review') !== false, 'confirmation page says the request is pending review');
        brb_t(strpos($html, 'No overnight stays. The Boardroom is an event space only.') !== false && strpos($html, 'Estimated Guests:') !== false, 'confirmation states the no-overnight rule and estimated guests');
        brb_t(stripos($html, 'check-in') === false && stripos($html, 'check-out') === false && stripos($html, 'per night') === false && stripos($html, 'guests staying') === false && strpos($html, '[VERIFY] latest end time') === false && strpos($html, '[VERIFY: attorney review]') === false, 'confirmation uses event wording and has no attorney note');
        brb_t(strpos($html, brb_ref($b)) !== false && strpos($html, 'memo line') === false, 'an unapproved confirmation does not show payment instructions');
        if ((int) $b->price_set === 1) {
            brb_t(strpos($html, brb_money($b->total)) !== false, 'confirmation shows the calendar price');
        } else {
            brb_t(stripos($html, 'price to be confirmed') !== false, 'confirmation says price to be confirmed');
        }
        brb_t(strpos($html, '$500') === false && strpos($html, '$75') === false && strpos($html, '$100') === false && strpos($html, '7%') === false && ((int) $b->price_set === 1 || strpos($html, '$0.00') === false), 'confirmation has no placeholder amounts');
        foreach ($banned as $w) brb_t(stripos($html, $w) === false, "confirmation HTML has no $w");

        if ((int) $b->price_set !== 1) {
            $blocked_pay = brb_record_payment($b->id, 100, '1000', brb_now()->format('Y-m-d'), 'Too soon', true);
            brb_t(is_wp_error($blocked_pay) && $blocked_pay->get_error_code() === 'price', 'a check cannot be recorded before the agreed price is set');
            $blocked_paid = brb_mark_paid_full($b->id);
            brb_t(is_wp_error($blocked_paid), 'mark paid waits for an agreed price');
        } else {
            brb_t((float) $b->total > 0, 'the calendar rate is already stored, so staff can still replace it');
            brb_t(true, 'mark paid waits for an agreed price');
        }

        $GLOBALS['brb_mails'] = [];
        $priced = brb_set_agreed_price($b->id, 1400);
        brb_t(!is_wp_error($priced) && $priced && (int) $priced->price_set === 1, 'staff can set the agreed price');
        brb_t($priced && abs((float) $priced->total - 1400) < 0.01 && abs((float) $priced->deposit_due - 700) < 0.01, 'deposit is half of the agreed price');
        $price_mail = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'flow-test@example.com') !== false && stripos($m['subject'], 'price is confirmed') !== false) $price_mail = $m;
        }
        brb_t($price_mail && strpos($price_mail['message'], '$1,400.00') !== false && strpos($price_mail['message'], '$700.00') !== false && strpos($price_mail['message'], '$600.00') === false && strpos($price_mail['message'], 'Payment instructions are sent when the request is approved.') !== false, 'price email states the agreed price and waits for approval');
        $GLOBALS['brb_mails'] = [];
        $wpdb->update(brb_table(), ['walkthrough_done' => 1], ['id' => (int) $priced->id]);
        $approved = brb_approve_booking($priced->id);
        brb_t(!is_wp_error($approved) && $approved && $approved->status === 'approved', 'staff can approve a pending request');
        brb_t($approved && brb_is_free($range_s, $range_e, (int) $approved->id), 'an approved unpaid request still does not block the date');
        $approve_mail = null;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'flow-test@example.com') !== false && stripos($m['subject'], 'approved') !== false) $approve_mail = $m;
        }
        brb_t($approve_mail && strpos($approve_mail['message'], 'Thank you for contacting The Boardroom!') === 0 && strpos($approve_mail['message'], 'email you a payment link') !== false && strpos($approve_mail['message'], 'email a price') === false, 'the approval email thanks the guest and explains the next step');
        brb_t($approve_mail && strpos($approve_mail['message'], 'payable to [VERIFY] Test Payee') !== false && strpos($approve_mail['message'], '[VERIFY] Test Window') !== false && strpos($approve_mail['message'], '[VERIFY] Test Hours') === false && strpos($approve_mail['message'], 'by appointment') !== false, 'the approval email names the payee and the drop-off place, and meetings are by appointment');
        brb_t($approve_mail && strpos($approve_mail['message'], 'Write booking reference ' . brb_ref($priced) . ' on the memo line.') !== false && strpos($approve_mail['message'], '[VERIFY] Test instructions.') !== false, 'the approval email includes the memo line and custom instructions');
        brb_t($approve_mail && strpos($approve_mail['message'], '60 or more days before the event') !== false && strpos($approve_mail['message'], 'less than 60 days before the event') !== false && strpos($approve_mail['message'], 'Refunds are issued manually.') !== false && strpos($approve_mail['message'], '[VERIFY: attorney review]') === false, 'the approval email states the 60-day deposit rule and has no attorney note');
        brb_t($approve_mail && strpos($approve_mail['message'], 'Your request is approved.') !== false && strpos($approve_mail['message'], 'Rental checklist') !== false && strpos($approve_mail['message'], '/rental-checklist/') !== false && strpos($approve_mail['message'], 'Renters insurance covering your event is required.') !== false && strpos($approve_mail['message'], 'Optional setup or takedown') === false && strpos($approve_mail['message'], 'one-day liquor license') === false && strpos($approve_mail['message'], 'fee $50') === false, 'the approval email includes the updated rental checklist');
        brb_t($approve_mail && strpos($approve_mail['message'], 'a check for $950.00') !== false && strpos($approve_mail['message'], 'That amount is the $700.00 deposit plus the $250.00 refundable damage deposit.') !== false && strpos($approve_mail['message'], 'the remaining balance of $700.00') !== false && strpos($approve_mail['message'], '$600') === false, 'the approval email asks for the deposit plus the unpaid damage deposit, then the rental remainder');
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
        brb_t($rental_line && ($coverage[(int) $rental_line->id]['paid'] ?? null) !== null && brb_line_state_text($coverage[(int) $rental_line->id]['paid'], $coverage[(int) $rental_line->id]['amount']) === 'partially paid, $700.00 of $1,400.00', 'a half-paid rental line is partially paid');
        brb_t($damage_row && brb_line_state_text($coverage[(int) $damage_row->id]['paid'], $coverage[(int) $damage_row->id]['amount']) === 'unpaid', 'an unpaid damage deposit line stays unpaid');
        if ($user) {
            ob_start();
            brb_admin_detail((int) $after->id);
            $partial_admin = ob_get_clean();
            brb_t(strpos($partial_admin, 'partially paid, $700.00 of $1,400.00') !== false && strpos($partial_admin, 'Rental <em>(paid)</em>') === false, 'the rental line shows the partial payment instead of paid in full');
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
            brb_t(strpos($admin_html, 'Event type') === false && strpos($admin_html, 'Alcohol served') === false, 'admin detail hides event type and alcohol when a new booking has none');
            global $wpdb;
            $wpdb->update(brb_table(), ['event_type' => 'wedding', 'alcohol' => 'yes'], ['id' => (int) $b->id]);
            ob_start();
            brb_admin_detail((int) $b->id);
            $saved_admin = ob_get_clean();
            brb_t(strpos($saved_admin, 'Event type') !== false && strpos($saved_admin, 'Wedding') !== false && strpos($saved_admin, 'Alcohol served') !== false && strpos($saved_admin, '>Yes<') !== false, 'admin detail still shows a saved event type and alcohol answer');
            brb_t(strpos($admin_html, 'Payments recorded') !== false && strpos($admin_html, '>Check<') !== false, 'payments list shows the check method');
            brb_t(strpos($admin_html, 'Delete booking') !== false && strpos($admin_html, 'value="brb_delete"') !== false && strpos($admin_html, 'This cannot be undone') !== false && strpos($admin_html, '_wpnonce') !== false, 'a paid booking can be deleted from its page');
            foreach ($banned as $w) brb_t(stripos($admin_html, $w) === false, "admin detail has no $w");
            ob_start();
            brb_admin_settings();
            $settings_html = ob_get_clean();
            brb_t(strpos($settings_html, 'Minimum notice') !== false && strpos($settings_html, 'Hold length') === false, 'settings keep the notice rule and do not offer a hold length');
            brb_t(strpos($admin_html, '[VERIFY: attorney review]') === false && strpos($settings_html, '[VERIFY: attorney review]') === false && strpos($admin_html, '[ATTORNEY REVIEW]') === false && strpos($settings_html, 'name="pay_hours"') === false && strpos($settings_html, 'In-person hours') === false, 'attorney review markers and the in-person hours setting are gone');
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
        'type' => 'day',
        'days' => 1,
        'guests' => 6,
        'name' => 'Flow Test',
        'email' => 'flow-test@example.com',
        'policy' => '1', 'waiver' => '1',
        'event_type' => 'corporate',
    ] + brb_screen());
    brb_t(!is_wp_error($exp) && $exp && $exp->status === 'review' && $exp->mode === 'day', 'a full-day request stays pending review');
    if ($exp && !is_wp_error($exp)) {
        $created_ids[] = (int) $exp->id;
        $es = new DateTimeImmutable($exp->start_dt, brb_tz());
        $ee = new DateTimeImmutable($exp->end_dt, brb_tz());
        brb_t(brb_is_free($es, $ee), 'a request does not block the calendar');
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
            'type' => 'day',
            'days' => 1,
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
        brb_t(!is_wp_error($declined) && $declined->status === 'declined' && strpos((string) $declined->attention, 'Resolve it manually.') !== false && strpos((string) $declined->attention, 'attorney review') === false, 'declining after a payment flags it for staff and does not promise a refund in the flag');
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
    $saved_date_get = $_GET;
    $_GET = ['date' => $later];
    $picked = do_shortcode('[boardroom_booking]');
    brb_t(strpos($picked, 'Submit request') !== false && strpos($picked, 'Your date') !== false, 'a valid date query opens the review step');
    $_GET = ['date' => 'not-a-date'];
    $bad_date = do_shortcode('[boardroom_booking]');
    brb_t(strpos($bad_date, 'id="brb-cal"') !== false && strpos($bad_date, 'Loading calendar') !== false && strpos($bad_date, 'Continue') !== false && strpos($bad_date, 'Submit request') === false, 'an invalid date falls back to the booking calendar');
    $blocked_day = brb_now()->modify('+1 day')->format('Y-m-d');
    $_GET = ['date' => $blocked_day];
    $too_soon = do_shortcode('[boardroom_booking]');
    brb_t(!brb_public_date_is_open($blocked_day) && strpos($too_soon, 'Submit request') === false && strpos($too_soon, 'id="brb-cal"') !== false && strpos($too_soon, 'Inside ' . (int) brb_notice_hours() . 'h notice') !== false, 'a date inside the 48-hour notice falls back to the calendar');
    $taken = brb_now()->modify('+36 days')->format('Y-m-d');
    $blocked_settings = get_option('brb_settings');
    $with_block = is_array($blocked_settings) ? $blocked_settings : [];
    $with_block['blackouts'] = trim((string) ($with_block['blackouts'] ?? '') . "\n" . $taken);
    update_option('brb_settings', $with_block);
    $_GET = ['date' => $taken];
    $blocked_page = do_shortcode('[boardroom_booking]');
    brb_t(!brb_public_date_is_open($taken) && strpos($blocked_page, 'Submit request') === false && strpos($blocked_page, 'Continue') !== false && strpos($blocked_page, 'Available') !== false && strpos($blocked_page, 'Partly booked') === false && strpos($blocked_page, 'Unavailable') !== false, 'an unavailable date falls back to the booking calendar');
    update_option('brb_settings', $blocked_settings);
    $_GET = $saved_date_get;
    $home_src = file_get_contents(get_stylesheet_directory() . '/front-page.php');
    $home_cal_at = strpos($home_src, 'id="check-your-date"');
    $home_tour_at = strpos($home_src, 'br_tour_video_html');
    brb_t(is_string($home_src) && $home_cal_at !== false && $home_tour_at !== false && $home_cal_at < $home_tour_at && strpos($home_src, 'Check Your Date') !== false && strpos($home_src, "Pick a date to see your price. Nothing's charged until we approve.") === false && strpos($home_src, 'See full booking details') !== false, 'the homepage calendar sits under the hero and above the tour');
    $home_cal = brb_home_calendar_html();
    brb_t(strpos($home_cal, 'Loading calendar') !== false && strpos($home_cal, 'Available') !== false && strpos($home_cal, 'Partly booked') === false && strpos($home_cal, 'Unavailable') !== false && strpos($home_cal, 'Inside ' . (int) brb_notice_hours() . 'h notice') !== false && strpos($home_cal, 'data-lazy="1"') !== false && strpos($home_cal, 'data-book="' . esc_url(brb_book_url()) . '"') !== false, 'the homepage calendar reuses the book legend and links a date to /book/');
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
    brb_t(stripos($faq_html, 'How much does it cost?') !== false && stripos($faq_html, '$850') !== false && stripos($faq_html, '$600') === false && stripos($faq_html, '$1,200') !== false && stripos($faq_html, '$2,000') !== false, 'faq explains the price range');
    brb_t(stripos($faq_html, 'arrives not set up') !== false && stripos($faq_html, 'extra fee') === false && stripos($faq_html, 'Staff cannot set the room up') !== false && stripos($faq_html, '8:00 AM') !== false && stripos($faq_html, 'minimum host age is 25') !== false, 'faq explains setup and seating');
    brb_t(strpos($faq_html, 'Card payments get an emailed receipt') !== false && strpos($faq_html, 'Sales tax is included in the price.') !== false && stripos($faq_html, 'sales tax is added') === false, 'faq offers a receipt and says sales tax is included');
    brb_t(stripos($faq_html, 'Christmas decorations are up in December') !== false, 'faq explains decorations');
    brb_t(stripos($faq_html, 'take the garbage out') !== false && stripos($faq_html, 'following day') !== false, 'faq explains cleanup');
    brb_t(stripos($faq_html, '48 hours') !== false && stripos($faq_html, 'Sunday to Thursday from $850') !== false, 'faq explains the notice and the calendar rates');
    brb_t(strpos($faq_html, 'Mail or drop off checks payable to Mad Productions at The Boardroom, 2220 37th Street, Moline, IL 61265.') !== false && strpos($faq_html, '2212 37th Street') === false, 'faq tells guests to bring checks to The Boardroom');
    brb_t(strpos($faq_html, 'online by card with a 3% processing fee') !== false && strpos($faq_html, '60 or more days before the event') !== false && strpos($faq_html, 'less than 60 days before the event') !== false && strpos($faq_html, '[VERIFY with attorney]') === false && strpos($faq_html, '[VERIFY: attorney review]') === false, 'faq states the 60-day deposit rule and has no attorney note');
    brb_t(strpos($faq_html, 'Are overnight stays allowed?') !== false && strpos($faq_html, 'No overnight stays. The Boardroom is an event space only.') !== false && stripos($faq_html, 'check-in') === false && stripos($faq_html, 'check-out') === false && strpos($faq_html, '[VERIFY] latest end time') === false, 'faq states the no-overnight rule without a public end time');
    brb_t(stripos($faq_html, 'How many guests can The Boardroom hold') !== false && stripos($faq_html, '80 guests') !== false && stripos($faq_html, '75 to 80') === false && stripos($faq_html, 'Graduation parties or open houses can go over') !== false, 'faq says 80 guests and keeps the graduation note');
    brb_t(strpos($faq_html, 'Sunday to Thursday from $850') !== false && strpos($faq_html, 'Friday and Saturday $1,400') !== false && strpos($faq_html, 'holiday dates priced higher') !== false && strpos($faq_html, 'the full range is $850 to about $2,000') !== false, 'faq explains the calendar rates');
    brb_t(strpos($faq_html, 'You can use the microwave, blender, food warmers and the bar area.') !== false && strpos($faq_html, 'The stovetops and oven can\'t be used.') !== false && strpos($faq_html, 'Do I need to bring plates and utensils?') !== false && strpos($faq_html, 'Do I need insurance?') !== false && strpos($faq_html, 'Can I rent for a few hours?') !== false && strpos($faq_html, 'No wall tape, glitter or confetti.') !== false && strpos($faq_html, 'or by check.') !== false, 'faq matches the kitchen, insurance, rental length, decorations, and deposit rules');
    brb_t(strpos($faq_html, 'https://moline.il.us/244/Liquor-Licensing') === false && strpos($faq_html, 'one-day liquor license') === false && strpos($faq_html, 'The price shows right on the calendar.') !== false && strpos($faq_html, '/rental-checklist/') === false && strpos($faq_html, 'Where is the rental checklist?') === false && strpos($faq_html, 'handicapped accessible') !== false && strpos($faq_html, 'What decorations are allowed?') !== false && strpos($faq_html, 'Venmo') === false && strpos($faq_html, 'A full day runs from 10:00 AM to 11:30 PM.') !== false && strpos($faq_html, 'Half days') === false && strpos($faq_html, 'Setup can begin at 8:00 AM.') !== false, 'faq keeps the calendar price, decorations, and access, without the rental checklist, a liquor license, or half days');
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
        '80 guests' => $terms_html,
        'Glitter' => $terms_html,
        'Food, drinks, and equipment' => $terms_html,
        'stovetops' => $terms_html,
        'BYOB is welcome.' => $terms_html,
        'Mad Productions at The Boardroom, 2220 37th Street, Moline, IL 61265' => $terms_html,
        '2220 37th Street, Moline, IL 61265' => $terms_html,
        'Christmas décor is up' => $terms_html,
        'isn\'t set up' => $terms_html,
        'can\'t set the room for you' => $terms_html,
        'The host must be 25 or older' => $terms_html,
        'no later than one week before your event' => $terms_html,
        'Balloons, yes' => $terms_html,
        'Wall tape' => $terms_html,
        'Sales tax is included in the price.' => $terms_html,
        'Last updated: November 9, 2026' => $terms_html,
        '60 or more days before your event' => $terms_html,
        'MAD Productions, LLC' => $privacy_html,
        'boardroomqc@gmail.com' => $privacy_html,
        'We never see or store card numbers' => $privacy_html,
        'FACILITY EVENT SPACE RENTAL AGREEMENT' => $agreement_html,
        'Rental Date:' => $agreement_html,
    ] as $needle => $hay) {
        brb_t(strpos($hay, $needle) !== false, 'legal copy contains ' . $needle);
    }
    brb_t(strpos($terms_html, 'The microwave, blender, food warmers and bar area are yours to use.') !== false && strpos($terms_html, 'The stovetops and oven can\'t be used.') !== false && strpos($terms_html, 'Renters insurance covering your event is required.') !== false, 'terms allow the microwave and require renters insurance');
    brb_t(strpos($terms_html, 'https://moline.il.us/244/Liquor-Licensing') === false && strpos($terms_html, '309-524-2004') === false && strpos($terms_html, 'Half days') === false && strpos($terms_html, 'one-day liquor license') === false && strpos($agreement_html, 'https://moline.il.us/244/Liquor-Licensing') === false && strpos($agreement_html, 'Half days') === false && strpos($agreement_html, 'one-day liquor license') === false && strpos($privacy_html, 'host age bracket') === false && strpos($privacy_html, 'invitation-only') === false && strpos($privacy_html, 'what time the event will end') === false, 'legal pages drop half days, the city liquor license, and the removed booking questions');
    brb_t(strpos($terms_html, 'Prices are on the calendar.') !== false && strpos($terms_html, 'no later than one week before your event') !== false && strpos($terms_html, '2212') === false && strpos($terms_html, 'Venmo') === false && strpos($terms_html, 'through Stripe for card payments (less the card processing fee) or by check') !== false && strpos($terms_html, 'Book at least 48 hours ahead.') !== false && strpos($terms_html, 'Full days start at $850.') !== false, 'the terms page uses one week, the venue for checks, and the original-method refund');
    brb_t(strpos(brb_cancellation_summary(), 'no later than one week before the event') !== false && strpos(brb_cancellation_summary(), '48 hours before the event') === false && strpos(brb_cancellation_summary(), 'Mad Productions at The Boardroom, 2220 37th Street, Moline, IL 61265') !== false && strpos(brb_cancellation_summary(), '2212') === false && strpos(brb_agreement_html(), 'no later than one week before the event') !== false && strpos(brb_agreement_html(), '48 hours before the event') === false && strpos(brb_agreement_html(), '80 guests') !== false && strpos(brb_agreement_html(), '75 to 80') === false, 'the summary and the rental agreement use one week, 80 guests, and checks at The Boardroom');
    brb_t(strpos($terms_html, '[VERIFY current fee and issuing office]') === false, 'liquor license fee is no longer marked for verification');
    brb_t(strpos($terms_html, '2220 37th Street, Moline, IL 61265') !== false && strpos($terms_html, 'MAD Productions, LLC') !== false, 'terms name the venue at 2220 37th Street');
    brb_t(strpos($terms_html, 'Make checks payable to Mad Productions at The Boardroom, 2220 37th Street, Moline, IL 61265.') !== false, 'terms tell guests where to bring checks');
    brb_t(strpos($terms_html, '[VERIFY address.') === false && strpos($terms_html, '2212 47 Ave') === false && strpos($terms_html, '2212 37th St,') === false, 'terms drop the old unverified addresses');
    brb_t(strpos($privacy_html, 'at 2220 37th Street, Moline, IL 61265') !== false, 'privacy names the venue address');
    brb_t(strpos($privacy_html, 'Mail or drop off checks payable to Mad Productions at The Boardroom, 2220 37th Street, Moline, IL 61265.') !== false && strpos($privacy_html, '2212') === false, 'privacy tells guests to bring checks to The Boardroom');
    brb_t(strpos($agreement_html, '2220 37th Street, Moline, Illinois 61265') !== false && strpos($agreement_html, '2220 37th Street, Moline, IL 61265') !== false, 'signed agreement body keeps 2220 37th Street');
    brb_t(strpos($agreement_html, 'Staff cannot set the room up') !== false && strpos($agreement_html, 'The minimum host age is 25 for every booking') !== false && strpos($agreement_html, 'no later than one week before the event') !== false && strpos($agreement_html, 'Balloons are allowed') !== false && strpos($agreement_html, 'wall tape') !== false, 'the rental agreement states setup, host age, balance, balloons, and wall tape');
    brb_t(strpos($privacy_html, 'event type') === false && strpos($privacy_html, 'whether alcohol will be served') === false && strpos($privacy_html, 'confirmation that the event is free to attend') !== false && strpos($privacy_html, 'event date and time') !== false, 'privacy no longer says the booking form asks for event type or alcohol');
    brb_t(strpos($agreement_html, 'Renter may bring and put up Renter') !== false && strpos($agreement_html, 'Glitter and confetti are not allowed') !== false && strpos($agreement_html, 'glitter; wall tape; party confetti; confetti cannons; rice; birdseed; loose sequins;') !== false && strpos($terms_html, 'Glitter, confetti, confetti cannons, rice, birdseed, or loose sequins') !== false && strpos($terms_html, 'You may bring and put up your own decorations') !== false && strpos($faq_html, 'confetti cannons, rice, birdseed, and loose sequins') !== false, 'renters may decorate, and confetti cannons, rice, birdseed, and loose sequins are banned again');
    $recording = 'The Venue may use audio and video recording, including security cameras in common areas and exterior areas. Cameras are not used in restrooms.';
    $agreement_src = brb_agreement_html();
    brb_t(strpos($agreement_src, '22. Recording Notice. ' . $recording) !== false && strpos($agreement_html, '22. Recording Notice. ' . $recording) !== false && strpos($agreement_src, 'consent to that recording') !== false && strpos($agreement_html, 'consent to that recording') !== false && strpos($agreement_src, '21. Entire Agreement; Authority to Sign.') < strpos($agreement_src, '22. Recording Notice.') && strpos($agreement_src, '22. Recording Notice.') < strpos($agreement_src, 'IN WITNESS WHEREOF') && strpos($terms_html, '22. Recording') !== false && strpos($terms_html, 'Cameras are not used in restrooms.') !== false && strpos($terms_html, '21. The whole agreement') < strpos($terms_html, '22. Recording'), 'the rental agreement and the terms page both state the recording notice after section 21');
    brb_t(strpos($agreement_html, '2212 37th Street') === false && strpos($agreement_html, '2212 47 Ave') === false, 'signed agreement body is not rewritten to the site address');
    $contact = get_page_by_path('contact');
    $contact_html = $contact ? (string) $contact->post_content : '';
    brb_t(strpos($contact_html, '2220 37th Street') === false && strpos($contact_html, 'mailto:') === false && strpos($contact_html, 'Get directions') === false && strpos($contact_html, 'PLACEHOLDER') === false, 'stored contact content does not repeat the address, email, or directions');
    brb_t(strpos($contact_html, '[boardroom_tour]') === false && strpos($contact_html, '[boardroom_contact]') === false && stripos($contact_html, 'Schedule a tour') === false, 'stored contact content no longer includes the old forms');
    $map = brb_map_embed();
    $contact_template = file_get_contents(get_stylesheet_directory() . '/page-contact.php');
    brb_t(strpos($map, '<iframe') !== false && strpos($map, 'loading="lazy"') !== false && strpos($map, 'google.com/maps/embed') !== false && strpos($map, '41.4913116') !== false && strpos($map, '-90.4820481') !== false && stripos($map, 'Load map') === false, 'the venue map embed still loads from Google with the venue coordinates');
    $contact_ask = function_exists('br_contact_ask_button') ? br_contact_ask_button() : '';
    brb_t(is_string($contact_template) && strpos($contact_template, 'brb_tour_form') !== false && strpos($contact_template, 'brb_map_embed') === false && strpos($contact_template, "br_ask_walter_card('#contact-inquiry')") !== false && strpos($contact_template, "br_ask_walter_card('#contact-inquiry')") < strpos($contact_template, 'brb_tour_form') && strpos($contact_ask, 'data-brb-open-chat') !== false && strpos($contact_template, 'Thank You!') !== false && strpos($contact_template, 'We will call or email you shortly!') !== false && strpos($contact_template, '/rental-checklist/') === false && strpos($contact_template, 'brb_tour_form') < strpos($contact_template, 'br_contact_ask_button()') && strpos($contact_template, 'mailto:') === false && strpos($contact_template, 'tel:') === false && strpos($contact_template, '2212') === false && strpos($contact_template, 'brb_directions_markup') === false && strpos($contact_template, 'br_tour_video_html') === false, 'the contact page is Walter then the form, and the thank-you keeps Ask Walter');
    $footer = file_get_contents(get_stylesheet_directory() . '/footer.php');
    brb_t(is_string($footer) && strpos($footer, '2220 37th Street, Moline, IL 61265') !== false && strpos($footer, '2212 37th Street') === false, 'footer shows the venue address');
    brb_t(is_string($footer) && substr_count($footer, '/rental-checklist/') === 1 && strpos($footer, '>Rental Checklist</a>') !== false && strpos($footer, 'class="btn"') === false, 'the footer has one plain rental checklist link');
    $book_page = file_get_contents(get_stylesheet_directory() . '/page.php');
    $book_fn = file_get_contents(get_stylesheet_directory() . '/functions.php');
    brb_t(is_string($book_page) && strpos($book_page, 'br_book_shortcuts_html') === false && strpos($book_page, 'br_good_to_know_html') === false && strpos($book_page, "br_ask_walter_card(home_url('/contact/'))") !== false && strpos($book_page, 'the_content') < strpos($book_page, "br_ask_walter_card(home_url('/contact/#contact-inquiry'))"), 'the book page drops the checklist card and Good to know, and puts Walter after the calendar');
    $checklist_page = get_page_by_path('rental-checklist');
    $checklist_html = $checklist_page ? do_shortcode((string) $checklist_page->post_content) : '';
    brb_t($checklist_page && $checklist_page->post_title === 'Rental Checklist' && strpos($checklist_html, '8:00 AM') !== false && strpos($checklist_html, '309-524-2004') === false && strpos($checklist_html, 'one-day liquor license') === false && strpos($checklist_html, 'BYOB is allowed.') !== false && strpos($checklist_html, 'Optional setup or takedown') === false && strpos($checklist_html, 'The space is quiet after 11:30 PM') === false && strpos($checklist_html, 'Renters insurance covering your event is required.') !== false && strpos($checklist_html, 'Vendors (caterer, DJ, bartender) send proof of insurance at least 7 days before.') !== false && strpos($checklist_html, 'or by check.') !== false && strpos($checklist_html, 'The stovetops and oven can&#039;t be used.') !== false && substr_count($checklist_html, 'Balloons are fine, no glitter.') === 1 && strpos($checklist_html, 'Not allowed: Glitter,') === false && strpos($checklist_html, 'Not allowed: Confetti, confetti cannons, rice, birdseed, or loose sequins.') !== false, 'the rental checklist matches the terms on insurance, the kitchen, and the deposit');
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
            if (stripos($m['to'], 'checklist-renter@example.com') !== false && stripos($m['subject'], 'about a week away') !== false) {
                $remind_mails++;
                $remind_mail = $m;
            }
        }
        $reminded = brb_booking((int) $remind->id);
        brb_t($remind_mails === 1 && $remind_mail && strpos($remind_mail['message'], 'about a week away') !== false && strpos($remind_mail['message'], 'Rental checklist') !== false && strpos($remind_mail['message'], '/rental-checklist/') !== false && strpos($remind_mail['message'], 'Vendors (caterer, DJ, bartender) send proof of insurance at least 7 days before.') !== false && strpos($remind_mail['message'], 'Optional setup or takedown') === false && $reminded && !empty($reminded->checklist_reminded), 'the week-away reminder includes the updated rental checklist');
        brb_checklist_reminders();
        $again = 0;
        foreach ($GLOBALS['brb_mails'] as $m) {
            if (stripos($m['to'], 'checklist-renter@example.com') !== false && stripos($m['subject'], 'about a week away') !== false) $again++;
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
    brb_t(strpos($terms_html, 'Card payments add a 3% processing fee on top of the amount paid, and that fee isn\'t refundable.') !== false && strpos($terms_html, '[VERIFY with attorney]') === false && strpos($terms_html, '[VERIFY: attorney review]') === false && strpos($terms_html, '[ATTORNEY REVIEW]') === false && strpos($agreement_html, '[ATTORNEY REVIEW]') === false && strpos($agreement_html, 'Renter and guests assume all risk.') !== false, 'terms and the rental agreement keep the approved wording and have no attorney-review marker');
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
        'faq' => 'Sunday to Thursday from $850',
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
    brb_t(strpos($quote_html, 'name="phone"') !== false && strpos($quote_html, 'required') !== false && stripos($quote_html, 'autocomplete="tel"') !== false && strpos($quote_html, 'tel:') === false, 'the public booking form requires the guest phone and does not publish a business number');
    brb_t(strpos($quote_html, 'I agree to the') !== false && strpos($quote_html, '>Terms<') !== false && strpos($quote_html, '>Rental Agreement<') !== false && strpos($quote_html, '>Privacy Policy<') !== false && strpos($quote_html, home_url('/terms/')) !== false && strpos($quote_html, home_url('/rental-agreement/')) !== false && strpos($quote_html, home_url('/privacy/')) !== false, 'review page links the terms, rental agreement, and privacy policy');
    brb_t(strpos($quote_html, 'No overnight stays. The Boardroom is an event space only.') === false && strpos($quote_html, 'Estimated Guests:') !== false && strpos($quote_html, 'Rental Price:') !== false, 'review step keeps the rental price and drops the repeated overnight line');
    brb_t(stripos($quote_html, 'check-in') === false && stripos($quote_html, 'check-out') === false && stripos($quote_html, 'per night') === false && stripos($quote_html, 'per-night') === false && stripos($quote_html, 'guests staying') === false && strpos($quote_html, '[VERIFY] latest end time') === false, 'review step uses event wording');
    brb_t(strpos($quote_html, 'name="event_type"') === false && strpos($quote_html, 'name="alcohol"') === false && strpos($quote_html, 'Will alcohol be served?') === false, 'review page does not ask for an event type or alcohol');
    brb_t(strpos($quote_html, 'name="private_event"') === false && strpos($quote_html, 'name="end_time"') === false && strpos($quote_html, 'name="host_age"') === false && strpos($quote_html, 'name="days"') === false && strpos($quote_html, 'name="free_admission"') !== false && strpos($quote_html, 'liquor license') === false && strpos($quote_html, 'Tell us about your event') !== false && strpos($quote_html, 'Estimated Guests:') !== false && strpos($quote_html, 'Rental Price: ') !== false && strpos($quote_html, '(+deposit)') !== false && strpos($quote_html, 'Rental Price*:') === false && strpos($quote_html, '*plus deposit') === false, 'review page keeps free-to-attend and estimated guests, and labels the rental price');
    brb_t(brb_host_age_label('under_21') === 'Under 21' && brb_host_age_label('age_21_24') === '21 to 24' && brb_host_age_label('under_25') === 'Under 25' && !isset(brb_host_ages()['age_21_24']), 'older host-age answers still display and new bookings use 25');
    brb_t(strpos($quote_html, 'subject to approval') !== false && strpos($quote_html, '<h3>Good to know</h3>') === false && strpos($quote_html, '<strong>Cancellation:</strong>') === false && strpos($quote_html, 'Submit request') !== false && strpos($quote_html, '<h3>Pricing</h3>') === false && strpos($quote_html, '<h3>Payment</h3>') === false && strpos($quote_html, '<h3>Cancellation</h3>') === false, 'review page states approval and drops the Good to know list');
    brb_t(strpos($quote_html, '[VERIFY] Test instructions.') === false && strpos($quote_html, 'Extra instructions for bringing the check') === false && strpos($quote_html, '[VERIFY: attorney review]') === false, 'review page hides payment instructions and the attorney note');
    brb_t(stripos($quote_html, 'cash') === false, 'the public review step stays check-only');
    $later_rate = brb_rate_for_date($later, 'full');
    if ($later_rate !== null) {
        brb_t(strpos($quote_html, 'Rental Price: ' . brb_whole_money($later_rate) . ' (+deposit)') !== false && strpos($quote_html, '*plus deposit') === false, 'review shows the calendar price for the date');
    } else {
        brb_t(strpos($quote_html, 'Full days start at $600, and most events come to about $1,200. We confirm your final price by email.') === false && strpos($quote_html, '<h3>Good to know</h3>') === false, 'review page drops the old pricing paragraph and Good to know');
        brb_t(stripos($quote_html, 'price to be confirmed') !== false, 'review page says price to be confirmed');
    }
    $rate_saved = get_option('brb_settings');
    $rate_settings = is_array($rate_saved) ? $rate_saved : [];
    $rate_settings['calendar_rates'] = wp_json_encode(['rules' => [[
        'start' => $later,
        'end' => $later,
        'months' => '',
        'weekday_full' => '900',
        'weekend_full' => '900',
        'weekday_shorter' => '',
        'weekend_shorter' => '',
    ]], 'overrides' => []]);
    update_option('brb_settings', $rate_settings);
    $priced = brb_quote(brb_build_range(['date' => $later, 'type' => 'day', 'days' => 1, 'guests' => 4]));
    brb_t(!empty($priced['price_set']) && (float) $priced['total'] === 900.0, 'a saved calendar rate becomes the quote total');
    $priced_html = do_shortcode('[boardroom_booking]');
    brb_t(strpos($priced_html, 'Rental Price: $900 (+deposit)') !== false && strpos($priced_html, '*plus deposit') === false, 'review shows the date price when a rate is saved');
    update_option('brb_settings', $rate_saved);
    brb_t(brb_rate_for_date('2026-11-23', 'full') === 850.0, 'a Monday in the default season is 850');
    brb_t(brb_rate_for_date('2026-11-25', 'full') === 1500.0, 'November 25 uses its date rate');
    brb_t(brb_rate_for_date('2026-11-28', 'full') === 1050.0, 'November 28 uses its date rate');
    brb_t(brb_rate_for_date('2026-12-02', 'full') === 1200.0, 'a December weekday is 1200');
    brb_t(brb_rate_for_date('2026-12-06', 'full') === 850.0, 'a December Sunday stays 850');
    brb_t(brb_rate_for_date('2026-12-24', 'full') === 1500.0, 'December 24 uses its date rate');
    brb_t(brb_rate_for_date('2026-11-23', 'shorter') === null, 'a shorter block has no rate');
    $outside = brb_quote(brb_build_range(['date' => '2028-04-12', 'type' => 'day', 'days' => 1, 'guests' => 4]));
    brb_t(empty($outside['price_set']) && (float) $outside['total'] === 0.0, 'a date outside the rate window is not given a price');
    brb_t(strpos($quote_html, '$500') === false && strpos($quote_html, '$75') === false && strpos($quote_html, '$100') === false && strpos($quote_html, '7%') === false && strpos($quote_html, 'Cleaning fee') === false, 'review page has no placeholder rate, fee, or tax');
    foreach ($banned as $w) brb_t(stripos($quote_html, $w) === false, "quote HTML has no $w");
    $_GET = [];
    $picker = do_shortcode('[boardroom_booking]');
    brb_t(strpos($picker, 'Continue') !== false && strpos($picker, 'hours from now') !== false, 'calendar explains the minimum notice');
    $facts_order = ['Prices&nbsp;on&nbsp;the&nbsp;Calendar', 'No&nbsp;Charge&nbsp;to&nbsp;Request', '50%&nbsp;Holds&nbsp;Your&nbsp;Date', '$250&nbsp;Refundable&nbsp;Damage&nbsp;Deposit', 'Balance&nbsp;Due&nbsp;1&nbsp;Week&nbsp;Before'];
    $facts_at = strpos($picker, 'class="hero-facts"');
    $facts_html = $facts_at === false ? '' : substr($picker, $facts_at, 800);
    $facts_ok = $facts_at !== false;
    $facts_cursor = 0;
    foreach ($facts_order as $facts_item) {
        $facts_pos = strpos($facts_html, $facts_item);
        $facts_ok = $facts_ok && $facts_pos !== false && $facts_pos >= $facts_cursor;
        if ($facts_pos !== false) $facts_cursor = $facts_pos;
    }
    brb_t($facts_ok && strpos($picker, '<h2>Pick a Date</h2>') !== false && strpos($picker, 'Reserve Your Date') === false && strpos($picker, 'How booking works') === false && strpos($picker, 'Pick a date. Prices are on the calendar.') === false && strpos($picker, 'Pay the rest a week before your event.') === false && strpos($picker, 'Pick your date. The price shows right on the calendar.') === false && strpos($picker, '<h3>Good to know</h3>') === false && strpos($picker, 'This is firm, with no exceptions.') === false && strpos($picker, 'Open houses can go over.') === false && strpos($picker, 'Written notice is required.') === false && strpos($picker, 'Within 1 day of signing') === false && strpos($picker, 'Choose date') !== false && strpos($picker, 'id="brb-cal"') !== false && strpos($picker, 'id="brb-form"') !== false && strpos($picker, 'name="date"') !== false && strpos($picker, 'Continue') !== false, 'the book page keeps Pick a Date, the facts strip, and the calendar');
    brb_t(strpos($picker, 'Full days start at $600, and most events come to about $1,200. We confirm your final price by email.') === false && strpos($picker, 'To hold your date, pay 50% of the rental plus a $250 refundable damage deposit.') === false && strpos($picker, 'Cancellations made less than 60 days out are not refunded.') === false && strpos($picker, 'no separate hourly price') === false && strpos($picker, 'Half day') === false && strpos($picker, 'Number of days') === false && strpos($picker, 'Estimated Guests') !== false && strpos($picker, 'Pricing from $600 per day') === false && strpos($picker, 'Read the Rental Terms') === false, 'the book calendar drops the repeated policy notes and keeps a one-day form');
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
    brb_t(!is_wp_error($cash_b) && $cash_b && ($cash_b->event_type ?? '') === '', 'a new booking does not require an event type');
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
        brb_t($cash_mail && strpos($cash_mail['message'], 'Event type:') === false, 'the cash receipt leaves out an event type');
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
            brb_t(strpos($cash_admin, 'Record payment') !== false && strpos($cash_admin, 'name="method"') !== false && strpos($cash_admin, '>Cash<') !== false && strpos($cash_admin, 'Delete booking') !== false && strpos($cash_admin, 'value="brb_delete"') !== false, 'staff can record cash and delete an open booking from its page');
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
        if ($user) {
            ob_start();
            brb_admin_detail((int) $del->id);
            $open_html = ob_get_clean();
            brb_t(strpos($open_html, 'Delete booking') !== false && strpos($open_html, 'value="brb_delete"') !== false && strpos($open_html, 'This cannot be undone') !== false && strpos($open_html, '_wpnonce') !== false && strpos($open_html, 'Cancel booking') !== false, 'delete is on the booking page next to cancel, before the booking is cancelled');
        }
        $delete_mails = count($GLOBALS['brb_mails']);
        $removed = brb_delete_booking($del->id);
        brb_t($removed === true && !brb_booking($del->id) && brb_payments($del->id) === [] && count($GLOBALS['brb_mails']) === $delete_mails, 'deleting from the booking page removes it and its payments and sends no email');
    }

    $bulk_day = brb_now()->modify('+40 days')->format('Y-m-d');
    $keep_day = brb_now()->modify('+41 days')->format('Y-m-d');
    $bulk_a = brb_process_public_booking([
        'date' => $bulk_day,
        'type' => 'day',
        'days' => 1,
        'guests' => 6,
        'name' => 'Bulk Delete A',
        'email' => 'flow-test@example.com',
        'policy' => '1', 'waiver' => '1',
    ] + brb_screen());
    $bulk_b = brb_process_public_booking([
        'date' => $keep_day,
        'type' => 'day',
        'days' => 1,
        'guests' => 6,
        'name' => 'Bulk Keep',
        'email' => 'flow-test@example.com',
        'policy' => '1', 'waiver' => '1',
    ] + brb_screen());
    brb_t(!is_wp_error($bulk_a) && !is_wp_error($bulk_b) && $bulk_a && $bulk_b, 'bulk delete fixtures can be created');
    if ($bulk_a && !is_wp_error($bulk_a) && $bulk_b && !is_wp_error($bulk_b)) {
        $created_ids[] = (int) $bulk_a->id;
        $created_ids[] = (int) $bulk_b->id;
        brb_set_agreed_price($bulk_a->id, 800);
        $bulk_paid = brb_record_payment($bulk_a->id, 800, '5555', brb_now()->format('Y-m-d'), 'Bulk deposit', true);
        $bulk_a = brb_booking($bulk_a->id);
        $blocks = $bulk_a && in_array($bulk_a->status, ['deposit_paid', 'paid'], true);
        $start = $bulk_a ? new DateTimeImmutable($bulk_a->start_dt, brb_tz()) : null;
        $end = $bulk_a ? new DateTimeImmutable($bulk_a->end_dt, brb_tz()) : null;
        brb_t($blocks && $start && $end && !brb_is_free($start, $end), 'a paid bulk-delete booking blocks its date');
        brb_t(brb_lines($bulk_a->id) !== [] && brb_payments($bulk_a->id) !== [], 'the paid booking has line items and a payment row');
        $_GET['status'] = 'paid';
        ob_start();
        brb_admin_list();
        $paid_list = ob_get_clean();
        unset($_GET['status']);
        ob_start();
        brb_admin_list();
        $all_list = ob_get_clean();
        brb_t(substr_count($all_list, 'brb-select-all') === 2 && strpos($all_list, 'name="booking_ids[]"') !== false && strpos($all_list, 'Delete selected') !== false && strpos($all_list, 'value="' . (int) $bulk_a->id . '"') !== false && strpos($all_list, 'value="brb_bulk_delete"') !== false && strpos($paid_list, 'name="status" value="paid"') !== false, 'the bookings list has select-all, row checkboxes, and keeps the status filter');
        $mail_before = count($GLOBALS['brb_mails']);
        $removed_n = brb_bulk_delete_bookings([(string) $bulk_a->id, 0, 'nope', $bulk_a->id]);
        brb_t($removed_n === 1 && !brb_booking($bulk_a->id) && brb_payments($bulk_a->id) === [] && brb_lines($bulk_a->id) === [] && brb_booking($bulk_b->id) && count($GLOBALS['brb_mails']) === $mail_before, 'bulk delete removes only the selected booking, its payments and line items, and sends no email');
        brb_t($start && $end && brb_is_free($start, $end), 'deleting the booking frees its calendar date');
        $still_open = brb_process_public_booking([
            'date' => brb_now()->modify('+42 days')->format('Y-m-d'),
            'type' => 'day',
            'days' => 1,
            'guests' => 4,
            'name' => 'Still Open',
            'email' => 'flow-test@example.com',
            'policy' => '1', 'waiver' => '1',
        ] + brb_screen());
        if ($still_open && !is_wp_error($still_open)) {
            $created_ids[] = (int) $still_open->id;
            $open_mails = count($GLOBALS['brb_mails']);
            $open_removed = brb_delete_booking($still_open->id);
            brb_t($open_removed === true && !brb_booking($still_open->id) && count($GLOBALS['brb_mails']) === $open_mails, 'delete from the booking page works while the request is still open and sends no email');
        }
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

    $dup_day = brb_now()->modify('+55 days')->format('Y-m-d');
    $other_day = brb_now()->modify('+56 days')->format('Y-m-d');
    $dup_post = [
        'type' => 'day',
        'days' => 1,
        'guests' => 4,
        'name' => 'Duplicate Guest',
        'email' => 'Flow-Test@example.com',
        'phone' => '555-0199',
        'policy' => '1',
        'waiver' => '1',
        'free_admission' => '1',
        'event_about' => 'A private dinner for family.',
    ];
    $GLOBALS['brb_mails'] = [];
    $first_dup = brb_process_public_booking($dup_post + ['date' => $dup_day]);
    $mails_after_first = count($GLOBALS['brb_mails']);
    $rows_after_first = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_table() . ' WHERE LOWER(email)=%s AND start_dt >= %s AND start_dt < %s', 'flow-test@example.com', $dup_day . ' 00:00:00', $dup_day . ' 23:59:59'));
    $second_dup = brb_process_public_booking($dup_post + ['date' => $dup_day]);
    $rows_after_second = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_table() . ' WHERE LOWER(email)=%s AND start_dt >= %s AND start_dt < %s', 'flow-test@example.com', $dup_day . ' 00:00:00', $dup_day . ' 23:59:59'));
    brb_t(!is_wp_error($first_dup) && $first_dup && !is_wp_error($second_dup) && $second_dup && (int) $first_dup->id === (int) $second_dup->id && $rows_after_first === 1 && $rows_after_second === 1 && count($GLOBALS['brb_mails']) === $mails_after_first, 'a second Book Now within 10 minutes reuses the booking and sends no more email');
    $third_dup = brb_process_public_booking($dup_post + ['date' => $other_day]);
    brb_t(!is_wp_error($third_dup) && $third_dup && (int) $third_dup->id !== (int) $first_dup->id, 'the same guest can book a different date');
    if ($first_dup && !is_wp_error($first_dup)) $created_ids[] = (int) $first_dup->id;
    if ($third_dup && !is_wp_error($third_dup)) $created_ids[] = (int) $third_dup->id;

    $block_day = brb_now()->modify('+60 days')->format('Y-m-d');
    $block_month = substr($block_day, 0, 7);
    brb_t(brb_public_date_is_open($block_day), 'the day to block starts open');
    $_GET = ['page' => 'brb-cal', 'm' => $block_month];
    ob_start();
    brb_admin_cal();
    $open_cal = ob_get_clean();
    brb_t(strpos($open_cal, 'name="date" value="' . $block_day . '"') !== false && strpos($open_cal, 'value="brb_block_day"') !== false && strpos($open_cal, '>Block<') !== false && strpos($open_cal, '_wpnonce') !== false, 'an open future day has a nonce-protected Block button');
    $blocked = brb_block_day($block_day);
    brb_t(!is_wp_error($blocked) && $blocked && $blocked->hold_type === 'blocked' && $blocked->name === 'Blocked' && substr($blocked->start_dt, 11, 5) === '00:00' && substr($blocked->end_dt, 11, 5) === '23:59', 'Block creates a full-day hold named Blocked');
    brb_t(!brb_public_date_is_open($block_day), 'a blocked day cannot be booked');
    $block_req = new WP_REST_Request('GET', '/brb/v1/availability');
    $block_req->set_param('month', $block_month);
    $block_feed = brb_rest_availability($block_req)->get_data();
    brb_t(($block_feed['days'][$block_day]['status'] ?? '') === 'booked', 'the availability API marks a blocked day unavailable');
    ob_start();
    brb_admin_cal();
    $blocked_cal = ob_get_clean();
    brb_t(strpos($blocked_cal, 'value="' . (int) $blocked->id . '"') !== false && strpos($blocked_cal, '>Unblock<') !== false && strpos($blocked_cal, 'Unblock this day?') !== false && strpos($blocked_cal, 'name="date" value="' . $block_day . '"') === false, 'a blocked day shows Unblock instead of Block');
    $unblocked = brb_unblock_day($blocked->id);
    brb_t($unblocked === true && !brb_hold($blocked->id) && brb_public_date_is_open($block_day), 'Unblock removes that hold and the day is open again');
    $reserved = brb_save_hold([
        'name' => 'Paid elsewhere',
        'hold_type' => 'reserved',
        'start_date' => $block_day,
        'start_time' => '10:00',
        'end_date' => $block_day,
        'end_time' => '12:00',
    ]);
    $kept_reserved = brb_unblock_day($reserved && !is_wp_error($reserved) ? $reserved->id : 0);
    brb_t(is_wp_error($kept_reserved) && $reserved && !is_wp_error($reserved) && brb_hold($reserved->id), 'Unblock does not delete a reserved hold');
    if ($reserved && !is_wp_error($reserved)) brb_delete_hold($reserved->id);
    $past_block = brb_block_day(brb_now()->modify('-2 days')->format('Y-m-d'));
    brb_t(is_wp_error($past_block), 'a past day cannot be blocked from the calendar');

    $reopen_day = brb_now()->modify('+75 days')->format('Y-m-d');
    $reopen_hold = brb_save_hold([
        'name' => 'Reserved guest',
        'hold_type' => 'reserved',
        'start_date' => $reopen_day,
        'start_time' => '00:00',
        'end_date' => $reopen_day,
        'end_time' => '23:59',
    ]);
    brb_t(!is_wp_error($reopen_hold) && $reopen_hold && !brb_public_date_is_open($reopen_day), 'a reserved hold keeps the date from being booked');
    $_GET = ['page' => 'brb-cal', 'm' => substr($reopen_day, 0, 7), 'hold' => (int) $reopen_hold->id];
    ob_start();
    brb_admin_cal_editor(substr($reopen_day, 0, 7), 'month', false);
    $hold_editor = ob_get_clean();
    brb_t(strpos($hold_editor, '>Open<') !== false && strpos($hold_editor, 'value="open"') !== false && strpos($hold_editor, '>Delete<') !== false && strpos($hold_editor, 'Delete this hold? This cannot be undone.') !== false && strpos($hold_editor, 'value="brb_hold_delete"') !== false && strpos($hold_editor, '_wpnonce') !== false, 'the hold editor offers Open and a confirmed Delete');
    $reopened = brb_reopen_date($reopen_hold->id, '');
    brb_t($reopened === 'reopened' && !brb_hold($reopen_hold->id) && brb_public_date_is_open($reopen_day), 'Open deletes the hold and the date can be booked again');
    $holds_before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . brb_hold_table());
    brb_t(brb_reopen_date(0, '') === 'noop' && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . brb_hold_table()) === $holds_before, 'Open on a new hold does nothing');
    $blackout_day = brb_now()->modify('+76 days')->format('Y-m-d');
    $blackout_settings = brb_opt();
    $blackout_settings['blackouts'] = trim($blackout_settings['blackouts'] . "\n" . $blackout_day);
    update_option('brb_settings', $blackout_settings);
    brb_t(in_array($blackout_day, brb_blackouts(), true) && !brb_public_date_is_open($blackout_day), 'a settings blackout blocks the date');
    $_GET = ['page' => 'brb-cal', 'm' => substr($blackout_day, 0, 7), 'blackout' => $blackout_day];
    ob_start();
    brb_admin_cal_editor(substr($blackout_day, 0, 7), 'month', false);
    $blackout_editor = ob_get_clean();
    brb_t(strpos($blackout_editor, '>Open<') !== false && strpos($blackout_editor, '>Delete<') !== false && strpos($blackout_editor, 'name="blackout" value="' . $blackout_day . '"') !== false && strpos($blackout_editor, 'Delete this blocked date?') !== false, 'a blackout editor offers Open and Delete');
    brb_t(brb_reopen_date(0, $blackout_day) === 'reopened' && !in_array($blackout_day, brb_blackouts(), true) && brb_public_date_is_open($blackout_day), 'Open drops the blackout and the date can be booked again');
    $delete_day = brb_now()->modify('+77 days')->format('Y-m-d');
    $blocked_hold = brb_save_hold([
        'name' => 'Blocked',
        'hold_type' => 'blocked',
        'start_date' => $delete_day,
        'start_time' => '00:00',
        'end_date' => $delete_day,
        'end_time' => '23:59',
    ]);
    $_GET = ['page' => 'brb-cal', 'm' => substr($delete_day, 0, 7), 'hold' => (int) $blocked_hold->id];
    ob_start();
    brb_admin_cal_editor(substr($delete_day, 0, 7), 'month', false);
    $blocked_editor = ob_get_clean();
    brb_t(!is_wp_error($blocked_hold) && $blocked_hold && strpos($blocked_editor, '>Delete<') !== false && strpos($blocked_editor, 'value="brb_hold_delete"') !== false && strpos($blocked_editor, 'Delete this hold? This cannot be undone.') !== false, 'the hold editor offers Delete for a blocked day');
    brb_t(brb_delete_hold($blocked_hold->id) === true && !brb_hold($blocked_hold->id) && brb_public_date_is_open($delete_day), 'Delete from the hold editor removes a blocked hold and the date can be booked again');
    $reserved_day = brb_now()->modify('+78 days')->format('Y-m-d');
    $reserved_hold = brb_save_hold([
        'name' => 'Reserved guest',
        'hold_type' => 'reserved',
        'start_date' => $reserved_day,
        'start_time' => '12:00',
        'end_date' => $reserved_day,
        'end_time' => '16:00',
    ]);
    brb_t(!is_wp_error($reserved_hold) && $reserved_hold && brb_delete_hold($reserved_hold->id) === true && !brb_hold($reserved_hold->id) && brb_public_date_is_open($reserved_day), 'Delete from the hold editor removes a reserved hold');
    $drop_day = brb_now()->modify('+79 days')->format('Y-m-d');
    $drop_settings = brb_opt();
    $drop_settings['blackouts'] = trim($drop_settings['blackouts'] . "\n" . $drop_day);
    update_option('brb_settings', $drop_settings);
    brb_t(in_array($drop_day, brb_blackouts(), true), 'a blackout date is stored before Delete');
    brb_drop_blackout_date($drop_day);
    brb_t(!in_array($drop_day, brb_blackouts(), true) && brb_public_date_is_open($drop_day), 'Delete from a blackout editor drops that date');
    if ($user) {
        $status_booking = brb_process_public_booking([
            'date' => brb_now()->modify('+80 days')->format('Y-m-d'),
            'type' => 'day',
            'days' => 1,
            'guests' => 4,
            'name' => 'Status Delete',
            'email' => 'flow-test@example.com',
            'phone' => '555-0199',
            'policy' => '1',
            'waiver' => '1',
            'free_admission' => '1',
            'event_about' => 'A private dinner for family.',
        ]);
        brb_t(!is_wp_error($status_booking) && $status_booking, 'a booking can be created to check delete on every status');
        if ($status_booking && !is_wp_error($status_booking)) {
            $created_ids[] = (int) $status_booking->id;
            foreach (['review', 'approved', 'deposit_paid', 'paid', 'declined', 'cancelled', 'expired', 'held'] as $status) {
                $wpdb->update(brb_table(), ['status' => $status], ['id' => (int) $status_booking->id]);
                ob_start();
                brb_admin_detail((int) $status_booking->id);
                $status_html = ob_get_clean();
                brb_t(strpos($status_html, 'Delete booking') !== false && strpos($status_html, 'value="brb_delete"') !== false && strpos($status_html, 'This cannot be undone') !== false, 'delete shows on the booking page for status ' . $status);
            }
        }
    }
    unset($_GET['hold'], $_GET['blackout']);

    $gmail_booking = brb_process_public_booking([
        'date' => '2026-12-12',
        'type' => 'day',
        'days' => 1,
        'guests' => 8,
        'name' => 'Casey Nguyen',
        'email' => 'casey.nguyen@example.com',
        'phone' => '555-0142',
        'policy' => '1',
        'waiver' => '1',
    ] + brb_screen());
    brb_t(!is_wp_error($gmail_booking) && $gmail_booking, 'a booking can be created for the email customer link');
    if ($gmail_booking && !is_wp_error($gmail_booking)) {
        $created_ids[] = (int) $gmail_booking->id;
        $gmail_subject = 'The Boardroom – ' . brb_ref($gmail_booking) . ' – Sat Dec 12, 2026';
        $gmail_body = "Hi Casey,\n\n\n\n- Devin\nThe Boardroom\n";
        $gmail_auth = brb_gmail_authuser();
        brb_t(brb_guest_email_subject($gmail_booking) === $gmail_subject && strpos($gmail_subject, brb_ref($gmail_booking)) !== false, 'the Gmail subject helper includes the booking ref and event date');
        brb_t(brb_guest_email_body($gmail_booking) === $gmail_body, 'the Gmail body greets the guest by first name and is signed Devin');
        $gmail_url = 'https://mail.google.com/mail/?authuser=' . rawurlencode($gmail_auth)
            . '&view=cm&fs=1&to=' . rawurlencode('casey.nguyen@example.com')
            . '&su=' . rawurlencode($gmail_subject)
            . '&body=' . rawurlencode($gmail_body);
        brb_t(brb_gmail_compose_url($gmail_booking) === $gmail_url, 'the Gmail compose URL raw-encodes authuser, to, subject, and body');
        $mails_before_link = count($GLOBALS['brb_mails']);
        ob_start();
        brb_admin_detail((int) $gmail_booking->id);
        $gmail_detail = ob_get_clean();
        ob_start();
        brb_admin_list();
        $gmail_list = ob_get_clean();
        $encoded_to = 'to=' . rawurlencode('casey.nguyen@example.com');
        $encoded_su = 'su=' . rawurlencode($gmail_subject);
        $encoded_auth = 'authuser=' . rawurlencode($gmail_auth);
        brb_t(strpos($gmail_detail, 'Email customer') !== false && strpos($gmail_detail, 'target="_blank"') !== false && strpos($gmail_detail, 'rel="noopener"') !== false && strpos($gmail_detail, 'https://mail.google.com/mail/') !== false && strpos($gmail_detail, $encoded_to) !== false && strpos($gmail_detail, $encoded_su) !== false && strpos($gmail_detail, $encoded_auth) !== false && strpos($gmail_detail, 'body=' . rawurlencode($gmail_body)) !== false && count($GLOBALS['brb_mails']) === $mails_before_link, 'the booking page shows an Email customer link with the encoded Gmail values and sends nothing');
        brb_t(strpos($gmail_list, '>' . esc_html('casey.nguyen@example.com') . '</a>') !== false && strpos($gmail_list, $encoded_to) !== false && strpos($gmail_list, $encoded_su) !== false && strpos($gmail_list, $encoded_auth) !== false && strpos($gmail_list, 'target="_blank"') !== false && strpos($gmail_list, 'rel="noopener"') !== false, 'the bookings list links the guest email to the same Gmail compose URL');
        $reply_saved = get_option('brb_settings');
        $reply_opt = brb_opt();
        $reply_opt['mail_reply_to'] = 'replies@boardroomqc.example';
        update_option('brb_settings', $reply_opt);
        brb_t(brb_gmail_authuser() === 'replies@boardroomqc.example', 'authuser uses the guest reply-to address');
        ob_start();
        brb_admin_detail((int) $gmail_booking->id);
        $reply_html = ob_get_clean();
        brb_t(strpos($reply_html, 'authuser=' . rawurlencode('replies@boardroomqc.example')) !== false, 'the Email customer link uses the reply-to address as authuser');
        $reply_opt['mail_reply_to'] = 'not-an-email';
        $reply_opt['mail_from'] = '';
        update_option('brb_settings', $reply_opt);
        putenv('BRB_SMTP_FROM');
        unset($_ENV['BRB_SMTP_FROM'], $_SERVER['BRB_SMTP_FROM']);
        brb_t(brb_gmail_authuser() === 'boardroomqc@gmail.com', 'authuser falls back to boardroomqc@gmail.com');
        update_option('brb_settings', $reply_saved);
        $gmail_user = get_current_user_id();
        wp_set_current_user(0);
        ob_start();
        brb_admin_detail((int) $gmail_booking->id);
        $hidden_detail = ob_get_clean();
        ob_start();
        brb_admin_list();
        $hidden_list = ob_get_clean();
        wp_set_current_user($gmail_user);
        brb_t(strpos($hidden_detail, 'Email customer') === false && strpos($hidden_detail, 'mail.google.com') === false && strpos($hidden_list, 'mail.google.com') === false && strpos($hidden_list, 'casey.nguyen@example.com') !== false, 'the email customer link is only shown to administrators');
    }
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
