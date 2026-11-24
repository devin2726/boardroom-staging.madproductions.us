<?php
/**
 * Run inside WordPress: wp eval-file tests/no-pricing-walkthrough.php
 * 2.5.91: Hold a Date + walk-through flow (Max, Nov 24, 2026, 1:44 PM CT) and the reinstall switch.
 * Throwaway bookings only (nopricing-*@example.com); no real email is sent.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Load this with wp eval-file\n");
    exit(1);
}
$GLOBALS['brb_fail'] = 0;
$GLOBALS['brb_ok'] = 0;
if (!function_exists('brb_t')) {
    function brb_t($cond, $msg) {
        if ($cond) { $GLOBALS['brb_ok']++; echo "ok  $msg\n"; }
        else { $GLOBALS['brb_fail']++; echo "FAIL $msg\n"; }
    }
}
global $wpdb;
$snapshot = get_option('brb_settings');
$created = [];
$GLOBALS['brb_np_mails'] = [];
add_filter('pre_wp_mail', function ($short, $atts) { $GLOBALS['brb_np_mails'][] = $atts; return true; }, 5, 2);
$set_mode = function ($mode) {
    $o = get_option('brb_settings');
    $o['pricing_mode'] = $mode;
    update_option('brb_settings', $o);
};
$open_weekday = function ($offset) {
    $d = brb_now()->modify('+' . (int) $offset . ' days');
    for ($i = 0; $i < 60; $i++) {
        $ymd = $d->format('Y-m-d');
        if (brb_public_date_is_open($ymd)) return $ymd;
        $d = $d->modify('+1 day');
    }
    return '';
};
$post_for = function ($ymd, $email, $extra = []) {
    return array_merge([
        'date' => $ymd, 'type' => 'day', 'days' => 1, 'guests' => 25,
        'name' => 'Np Tester', 'email' => $email, 'phone' => '555-0100',
        'policy' => '1', 'waiver' => '1', 'free_admission' => '1',
        'event_about' => 'A throwaway test birthday party.',
    ], $extra);
};
wp_set_current_user(1);
try {
    // ---- defaults and migration
    $d = brb_defaults();
    brb_t(($d['pricing_mode'] ?? '') === 'walkthrough', 'default booking flow is walkthrough');
    $o = get_option('brb_settings');
    unset($o['pricing_mode']);
    update_option('brb_settings', $o);
    brb_migrate_settings();
    brb_t((get_option('brb_settings')['pricing_mode'] ?? '') === 'walkthrough', 'upgrade without a stored flow switches to walkthrough');
    $set_mode('walkthrough');
    brb_t(brb_walkthrough_mode(), 'walkthrough mode is on');
    brb_t(brb_public_price_range() === "$850\u{2013}$1,500 depending on seasonal availability", 'public price range line is $850–$1,500 depending on seasonal availability');
    brb_t(array_keys(brb_walk_days()) === ['mon', 'tue', 'wed', 'thu', 'fri'], 'walk-through days are Monday to Friday only');
    brb_t(brb_walk_windows() === ['morning' => 'Morning (8 AM–12 PM)', 'afternoon' => 'Afternoon (12–4 PM)'], 'walk-through windows are 8–12 and 12–4');

    // ---- public copy
    $how = brb_how_booking_works_html();
    brb_t(strpos($how, 'Hold a Date') !== false && strpos($how, 'Prices&nbsp;on') === false && strpos($how, '50%') === false, 'Book page facts say Hold a Date, no calendar prices or 50%');
    brb_t(strpos($how, '$850–$1,500 depending on seasonal availability') !== false, 'Book page shows the range line');
    $steps = implode(' ', brb_how_booking_works_steps());
    brb_t(strpos($steps, 'tentative hold') !== false && strpos($steps, 'walk-through') !== false && !preg_match('/3%|50%|7 days/', $steps), 'booking steps describe tentative hold + walk-through, no fee/deposit/balance');
    $GLOBALS['brb_np_test_faq'] = true;
    $faq = brb_walk_faq_swap(brb_faq_markup());
    brb_t(strpos($faq, 'The price shows right on the calendar') === false, 'FAQ no longer says the price is on the calendar');
    brb_t(strpos($faq, 'How is pricing set?') !== false && strpos($faq, '$850–$1,500 depending on seasonal availability') !== false, 'FAQ has the pricing range question');
    brb_t(strpos($faq, 'What is the walk-through?') !== false, 'FAQ explains the walk-through');
    brb_t(strpos($faq, '3% processing fee') === false && strpos($faq, 'The deposit (50% of the agreed total)') === false, 'FAQ How do I pay has no 3% or 50% figure');
    preg_match_all('/\$\s?[0-9][0-9,]*/', wp_strip_all_tags($faq), $m);
    $amounts = array_values(array_unique($m[0]));
    sort($amounts);
    brb_t(array_values(array_diff($amounts, ['$850', '$1,500', '$1,000,000', '$2,000,000'])) === [], 'FAQ dollar figures are only the range and the insurance limits');
    $llms = function_exists('brb_llms_body') ? brb_llms_body() : '';
    if ($llms !== '') brb_t(strpos($llms, 'see the price on the calendar') === false && strpos($llms, 'Hold a Date') !== false, 'llms.txt says Hold a Date, not calendar price');

    // ---- REST: no prices, tentative hold
    $ymd = $open_weekday(40);
    brb_t($ymd !== '', 'found an open test date');
    $req = new WP_REST_Request('GET', '/brb/v1/availability');
    $req['month'] = substr($ymd, 0, 7);
    $res = brb_rest_availability($req)->get_data();
    $prices = array_filter(array_map(function ($x) { return $x['price']; }, $res['days']));
    brb_t(!$prices, 'availability feed has no prices in walkthrough mode');
    brb_t(($res['pricing'] ?? '') === 'walkthrough', 'availability feed says pricing=walkthrough');

    // ---- required walk-through fields
    $e = brb_process_public_booking($post_for($ymd, 'nopricing-a@example.com'));
    brb_t(is_wp_error($e) && $e->get_error_code() === 'walk_days', 'request without a weekday is refused');
    $e = brb_process_public_booking($post_for($ymd, 'nopricing-a@example.com', ['walk_days' => ['mon', 'sat']]));
    brb_t(is_wp_error($e) && $e->get_error_code() === 'walk_window', 'request without a time window is refused (Saturday ignored)');
    $GLOBALS['brb_np_mails'] = [];
    $b = brb_process_public_booking($post_for($ymd, 'nopricing-a@example.com', ['walk_days' => ['wed', 'mon', 'sun'], 'walk_window' => ['afternoon']]));
    brb_t($b && !is_wp_error($b), 'Hold a Date request is saved');
    if ($b && !is_wp_error($b)) {
        $created[] = (int) $b->id;
        brb_t($b->status === 'review', 'request status is review (tentative hold)');
        brb_t($b->walk_days === 'mon,wed' && $b->walk_window === 'afternoon', 'walk-through preference stored as mon,wed / afternoon');
        brb_t((int) $b->price_set === 0 && (float) $b->total === 0.0, 'no price is set on the request (quoted in person)');
        brb_t(brb_walk_pref_label($b) === 'Monday, Wednesday · Afternoon (12–4 PM)', 'staff label reads Monday, Wednesday · Afternoon (12–4 PM)');
        brb_t(!brb_public_date_is_open($ymd), 'the date is tentatively held (no second request)');
        $res = brb_rest_availability($req)->get_data();
        brb_t(($res['days'][$ymd]['status'] ?? '') === 'tentative', 'calendar shows the date as tentative');
        $guest = null; $staff = null;
        foreach ($GLOBALS['brb_np_mails'] as $mail) {
            $to = is_array($mail['to']) ? implode(',', $mail['to']) : (string) $mail['to'];
            if (strpos($to, 'nopricing-a@example.com') !== false) $guest = $mail; else $staff = $mail;
        }
        $gb = $guest ? (string) $guest['message'] : '';
        brb_t($guest !== null, 'guest confirmation email is produced');
        brb_t(strpos($gb, 'tentatively held') !== false && strpos($gb, 'walk-through') !== false && strpos($gb, 'quote your price') !== false, 'guest email: received, tentatively held, walk-through, price quoted there');
        brb_t(strpos($gb, "Please don't count on the walk-through time until we confirm it") !== false, 'guest email says not to count on the time until confirmed');
        brb_t(!preg_match('/\$\s?\d|deposit|3%|50%|price to be confirmed|payment link/i', wp_strip_all_tags($gb)), 'guest email has no price, deposit, fee or pay-link wording');
        brb_t(strpos($gb, 'Monday, Wednesday · Afternoon (12–4 PM)') !== false, 'guest email repeats the walk-through preference');
        $sb = $staff ? (string) $staff['message'] : '';
        brb_t(strpos($sb, 'TENTATIVELY HELD') !== false && strpos($sb, 'Walk-through preference: Monday, Wednesday · Afternoon (12–4 PM)') !== false, 'staff email shows tentative hold and walk-through preference');
        brb_t(count($GLOBALS['brb_np_mails']) === 2, 'only the guest confirmation and the staff notice are produced');
        $detail = brb_sd_booking_detail($b);
        brb_t(($detail['walk_pref'] ?? '') === 'Monday, Wednesday · Afternoon (12–4 PM)', 'staff dashboard detail has the walk-through preference');
        // Confirmation page
        $_GET = ['br_token' => $b->token];
        ob_start(); brb_view_confirmation($b->token); $page = ob_get_clean();
        brb_t(strpos($page, 'tentatively held') !== false && strpos($page, 'quoted in person') !== false && !preg_match('/\$\s?\d/', wp_strip_all_tags($page)), 'status page: tentatively held, price quoted in person, no dollar amount');
        $_GET = [];
        // Staff can still price and approve the normal way (Stripe/invoice path intact)
        brb_set_agreed_price($b->id, 1200, false);
        $p = brb_booking($b->id);
        brb_t((int) $p->price_set === 1 && abs((float) $p->total - 1200) < 0.01, 'staff Agreed price still works after the walk-through');
        // Declining releases the tentative hold
        brb_decline_booking($b->id, ['notify' => false]);
        brb_t(brb_public_date_is_open($ymd), 'declining the request releases the tentative hold');
    }
    // ---- quote view markup
    $_GET = ['date' => $ymd, 'type' => 'day', 'guests' => '20'];
    $_SERVER['REQUEST_URI'] = '/reserve-the-boardroom-quad-cities/?date=' . $ymd;
    ob_start(); brb_view_quote(); $q = ob_get_clean();
    $_GET = [];
    brb_t(strpos($q, 'Rental Price') === false && strpos($q, '$850–$1,500 depending on seasonal availability') !== false, 'Your date card shows the range, not a Rental Price');
    brb_t(substr_count($q, 'name="walk_days[]"') === 5 && substr_count($q, 'name="walk_window[]"') === 2, 'form has Mon–Fri and morning/afternoon choices');
    brb_t(strpos($q, 'not set until we reach out and confirm') !== false, 'form says the walk-through is not set until we confirm');
    brb_t(strpos($q, 'Send Hold a Date request') !== false, 'button says Send Hold a Date request');

    // ---- reinstall switch: old flow comes back
    $set_mode('calendar');
    brb_t(!brb_walkthrough_mode(), 'switching to calendar mode turns the walk-through flow off');
    brb_t(strpos(brb_how_booking_works_html(), 'Prices&nbsp;on&nbsp;the&nbsp;Calendar') !== false, 'old flow: Prices on the Calendar is back');
    $res = brb_rest_availability($req)->get_data();
    $prices = array_filter(array_map(function ($x) { return $x['price']; }, $res['days']));
    brb_t((bool) $prices, 'old flow: calendar prices are back');
    brb_t(!in_array('review', brb_blocking_statuses(), true), 'old flow: a request does not hold the date');
    $_GET = ['date' => $ymd, 'type' => 'day', 'guests' => '20'];
    ob_start(); brb_view_quote(); $q = ob_get_clean();
    $_GET = [];
    brb_t(strpos($q, 'Rental Price') !== false && strpos($q, 'walk_days') === false, 'old flow: Rental Price back, no walk-through fields');
} catch (Throwable $t) {
    brb_t(false, 'exception: ' . $t->getMessage() . ' @' . $t->getLine());
} finally {
    foreach ($created as $id) {
        $wpdb->delete(brb_table(), ['id' => $id]);
        if (function_exists('brb_line_table')) $wpdb->delete(brb_line_table(), ['booking_id' => $id]);
    }
    update_option('brb_settings', $snapshot);
}
echo $GLOBALS['brb_ok'] . ' passed, ' . $GLOBALS['brb_fail'] . " failed\n";
