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
    brb_t(brb_public_price_range() === 'Starting at $850', 'public price line is Starting at $850 (Max, Nov 24, 3:16 PM CT)');
    brb_t(brb_walk_price_line() === 'Starting at $850. We quote your exact price in person at your walk-through.', 'walk price sentence reads naturally');
    brb_t(array_keys(brb_walk_days()) === ['mon', 'tue', 'wed', 'thu', 'fri'], 'walk-through days are Monday to Friday only');
    brb_t(brb_walk_windows() === ['morning' => 'Morning (8 AM–12 PM)', 'afternoon' => 'Afternoon (12–4 PM)'], 'walk-through windows are 8–12 and 12–4');

    // ---- public copy
    $how = brb_how_booking_works_html();
    brb_t(strpos($how, 'Hold a Date') !== false && strpos($how, 'Prices&nbsp;on') === false && strpos($how, '50%') === false, 'Book page facts say Hold a Date, no calendar prices or 50%');
    brb_t(strpos($how, 'Starting at $850') !== false && strpos($how, '1,500') === false, 'Book page shows Starting at $850, no top of range');
    $steps = implode(' ', brb_how_booking_works_steps());
    brb_t(strpos($steps, 'tentative hold') !== false && strpos($steps, 'walk-through') !== false && !preg_match('/3%|50%|7 days/', $steps), 'booking steps describe tentative hold + walk-through, no fee/deposit/balance');
    $GLOBALS['brb_np_test_faq'] = true;
    $faq = brb_walk_faq_swap(brb_faq_markup());
    brb_t(strpos($faq, 'The price shows right on the calendar') === false, 'FAQ no longer says the price is on the calendar');
    brb_t(strpos($faq, 'How is pricing set?') !== false && strpos($faq, 'Starting at $850') !== false && strpos($faq, '1,500') === false, 'FAQ How is pricing set? says Starting at $850');
    brb_t(strpos($faq, 'What is the walk-through?') !== false, 'FAQ explains the walk-through');
    brb_t(strpos($faq, '3% processing fee') === false && strpos($faq, 'The deposit (50% of the agreed total)') === false, 'FAQ How do I pay has no 3% or 50% figure');
    preg_match_all('/\$\s?[0-9][0-9,]*/', wp_strip_all_tags($faq), $m);
    $amounts = array_values(array_unique($m[0]));
    sort($amounts);
    brb_t(array_values(array_diff($amounts, ['$850', '$1,000,000', '$2,000,000'])) === [], 'FAQ dollar figures are only $850 and the insurance limits');
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
    brb_t(strpos($q, 'Rental Price') === false && strpos($q, 'Starting at $850') !== false && strpos($q, '1,500') === false, 'Your date card shows Starting at $850, not a Rental Price');
    brb_t(substr_count($q, 'name="walk_days[]"') === 5 && substr_count($q, 'name="walk_window[]"') === 2, 'form has Mon–Fri and morning/afternoon choices');
    brb_t(strpos($q, 'not set until we reach out and confirm') !== false, 'form says the walk-through is not set until we confirm');
    brb_t(strpos($q, 'Send Hold a Date request') !== false, 'button says Send Hold a Date request');

    // ---- QA round 2 (Les, Nov 24): chat, checklist, pay window, FAQ cancellation, guest cap
    $chat = function ($q) { $r = brb_chat_answer($q); return (string) $r['reply']; };
    $no_old_pay = function ($t) { return strpos($t, '50%') === false && strpos($t, '3%') === false && stripos($t, 'within 1 day') === false && stripos($t, 'deposit') === false; };
    foreach (['How much is the deposit?', 'How do I pay?', 'Do you take credit cards?', 'What is the cancellation policy?'] as $q) {
        $a = $chat($q);
        brb_t($no_old_pay($a), 'chat walk mode: "' . $q . '" has no 50%, 1 day, 3% or deposit (' . substr($a, 0, 90) . ')');
    }
    $a = brb_chat_walk_payment_text(false);
    brb_t(strpos($a, '$850') !== false && stripos($a, 'walk-through') !== false && stripos($a, 'payment details') !== false && stripos($a, 'in person') !== false, 'chat payment answer: range, walk-through, payment details covered at the in-person quote');
    $GLOBALS['brb_chat_detail'] = false;
    $short_ok = $no_old_pay(brb_chat_payment_text(false)) && $no_old_pay(brb_chat_cancel_text());
    $GLOBALS['brb_chat_detail'] = true;
    brb_t($short_ok && $no_old_pay(brb_chat_payment_text(true)) && $no_old_pay(brb_chat_cancel_text()), 'chat payment and cancel texts (short and detailed) carry no deposit terms');
    brb_t(stripos(brb_chat_tour_text(), 'Hold a Date') !== false, 'chat walk-through answer points to Hold a Date');
    brb_t(stripos(brb_chat_suggestions(), 'deposit') === false, 'chat suggestions do not mention the deposit');
    $chips = wp_list_pluck(brb_chat_chips('price'), 'label');
    brb_t(!in_array('Deposit', $chips, true), 'chat chips: no Deposit chip');
    $ck = do_shortcode('[brb_checklist]');
    brb_t(strpos($ck, 'The deposit is received and approved.') === false && strpos($ck, 'tentatively held') !== false, 'checklist page shows the tentative-hold line, not the deposit line');
    brb_t(strpos(implode("\n", brb_checklist_lines()), 'The deposit is received and approved.') !== false, 'stored checklist text is unchanged (display-time swap only)');
    $faq2 = brb_walk_faq_swap(brb_faq_markup());
    preg_match('/<details><summary>What is the cancellation policy\?<\/summary>.*?<\/details>/s', $faq2, $cm);
    brb_t(!empty($cm[0]) && stripos($cm[0], 'deposit') === false && strpos($cm[0], '60') !== false, 'FAQ cancellation answer is neutral (no deposit) and keeps the 60-day rule');
    $ymd_l = $open_weekday(45);
    $wpdb->insert(brb_table(), ['token' => wp_generate_password(20, false), 'name' => 'Np Lapse', 'email' => 'nopricing-lapse@example.com', 'start_dt' => $ymd_l . ' 10:00:00', 'end_dt' => $ymd_l . ' 23:30:00', 'mode' => 'day', 'guests' => 10, 'status' => 'approved', 'total' => 900, 'deposit_due' => 450, 'amount_paid' => 0, 'price_set' => 1, 'hold_until' => gmdate('Y-m-d H:i:s', time() - 7200), 'pay_link_sent_at' => gmdate('Y-m-d H:i:s', time() - 180000), 'created' => current_time('mysql')]);
    $lapse_id = (int) $wpdb->insert_id; $created[] = $lapse_id;
    $lb = brb_booking($lapse_id);
    brb_t(!brb_hold_lapsed($lb) && brb_sd_hold_info($lb) === null && brb_sd_lapsed() === [], 'walk mode: no pay-window clock, no lapse flag, nothing in the Pay window passed list');
    $rel = brb_sd_release($lapse_id, ['confirmed' => true]);
    brb_t(is_wp_error($rel) && brb_booking($lapse_id)->status === 'approved', 'walk mode: release is refused and the booking stays put');
    ob_start(); brb_view_select(); $sel = ob_get_clean();
    brb_t(strpos($sel, 'max="80"') !== false && strpos($sel, 'max="200"') === false, 'Hold a Date form caps guests at 80');
    $r80 = brb_build_range(['date' => $ymd, 'type' => 'day', 'guests' => 81], ['public' => true]);
    brb_t(is_wp_error($r80) && $r80->get_error_code() === 'guests', 'server refuses 81 guests on the public form');
    brb_t(!is_wp_error(brb_build_range(['date' => $ymd, 'type' => 'day', 'guests' => 80], ['public' => true])), '80 guests is accepted');
    brb_t(!is_wp_error(brb_build_range(['date' => $ymd, 'type' => 'day', 'guests' => 120], ['override_notice' => true])), 'staff entry can still go over 80 (open house, graduation)');

    // ---- QA round 3 (Les round 2 findings, Nov 24): no hold claimed without a request; check address first
    $ans = function ($q, $det) { $GLOBALS['brb_chat_detail'] = $det; $r = brb_chat_answer($q, [], $det); $GLOBALS['brb_chat_detail'] = false; return $r; };
    foreach (['Is December 12 available?', 'Can I book a tour?', 'Can you change my booking?', 'How do I book?', 'Any open dates in December?'] as $q) {
        foreach ([false, true] as $det) {
            $a = (string) $ans($q, $det)['reply'];
            brb_t(strpos($a, 'Your date is tentatively held.') === false, 'chat ' . ($det ? 'detail' : 'short') . ' "' . $q . '" does not claim a hold exists');
        }
    }
    brb_t(strpos((string) $ans('How do I book?', true)['reply'], 'Once you send a Hold a Date request') !== false, 'chat detail booking answer uses the conditional Once you send a Hold a Date request line');
    $chk = (string) $ans('Where do I mail a check?', false)['reply'];
    brb_t(strpos($chk, 'Mail or drop off checks') !== false && strpos($chk, '$850') === false && strpos($chk, 'Rentals run') === false, 'mail-a-check answer: address, no range first (short)');
    $chk = (string) $ans('Where do I mail a check?', true)['reply'];
    brb_t(strpos($chk, 'Mail or drop off checks') !== false && (strpos($chk, 'Rentals run') === false || strpos($chk, 'Rentals run') > strpos($chk, 'Mail or drop off checks')), 'mail-a-check answer: address leads (detail)');
    $chk2 = (string) $ans('Where can I drop off a check?', false)['reply'];
    brb_t(strpos($chk2, 'Rentals run') === false || strpos($chk2, 'Rentals run') > strpos($chk2, 'checks'), 'drop-off-a-check answer leads with the address');
    $walk_change_intent = $ans('Can you change my booking?', false)['intent'];

    // ---- Starting at $850 (Max, Nov 24, 3:16 PM CT): no $1,500 / top of range anywhere in walk-mode output
    $walk_out = [brb_how_booking_works_html(), brb_walk_faq_swap(brb_faq_markup()), implode("\n", brb_walk_steps())];
    $GLOBALS['brb_chat_detail'] = true;
    foreach (['How much does it cost?', 'How much is the deposit?', 'How do I pay?', 'Can I book a tour?', 'What is the price for a Saturday?', 'How much is December 12?', 'Price for a wedding?'] as $q) $walk_out[] = (string) brb_chat_answer($q, [], true)['reply'];
    $GLOBALS['brb_chat_detail'] = false;
    foreach (['How much does it cost?', 'How much is the deposit?', 'How much is December 12?'] as $q) $walk_out[] = (string) brb_chat_answer($q)['reply'];
    ob_start(); brb_place_schema(); $schema = ob_get_clean();
    $walk_out[] = $schema;
    if (function_exists('brb_llms_txt_body')) $walk_out[] = (string) brb_llms_txt_body();
    $blob = implode("\n", $walk_out);
    brb_t(!preg_match('/1,500|\b1500\b|seasonal availability|Rentals run/', $blob), 'walk mode: no $1,500, 1500, "seasonal availability" or "Rentals run" in page, FAQ, chat, schema or llms output');
    brb_t(strpos($schema, 'priceRange') === false && strpos($schema, 'maxPrice') === false && strpos($schema, 'highPrice') === false, 'EventVenue schema has no priceRange in the new flow (Les: not valid on EventVenue; Max Nov 11: not decided)');
    $GLOBALS['brb_chat_detail'] = false;
    foreach (['How much is the deposit?', 'How much is the deposit and how do I pay?', 'Can I pay with a card?'] as $q) {
        foreach ([false, true] as $det) {
            $GLOBALS['brb_chat_detail'] = $det;
            $a = (string) brb_chat_answer($q, [], $det)['reply'];
            brb_t(substr_count($a, 'We quote your exact price') === 1 && substr_count($a, 'Starting at $850') === 1, 'chat ' . ($det ? 'detail' : 'short') . ' "' . $q . '" says the price and "We quote your exact price" once');
        }
    }
    $GLOBALS['brb_chat_detail'] = false;
    brb_t(strpos((string) brb_chat_answer('Saturday price?')['reply'], 'Starting at $850') !== false, 'chat: "Saturday price?" answers with Starting at $850 (no empty reply)');
    $o2 = get_option('brb_settings'); $sp_keep = $o2['starting_price'] ?? '850'; $o2['starting_price'] = '900'; update_option('brb_settings', $o2);
    brb_t(brb_public_price_range() === 'Starting at $900', 'the existing Starting price ($ per day) setting drives the new-flow line (one source)');
    $o2['starting_price'] = $sp_keep; update_option('brb_settings', $o2);
    brb_t(!array_key_exists('public_price_from', brb_defaults()) && (string) brb_defaults()['starting_price'] === '850', 'no separate public_price_from setting; starting_price default stays 850');

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
    $GLOBALS['brb_chat_detail'] = true;
    brb_t(strpos(brb_chat_payment_text(true), '50%') !== false, 'old flow: chat quotes the 50% deposit again');
    brb_t(strpos(do_shortcode('[brb_checklist]'), 'The deposit is received and approved.') !== false, 'old flow: checklist deposit line is back');
    $lb = brb_booking($lapse_id);
    brb_t(brb_hold_lapsed($lb) && !empty(brb_sd_hold_info($lb)['lapsed']), 'old flow: pay-window lapse flag is back');
    ob_start(); brb_view_select(); $sel = ob_get_clean();
    brb_t(strpos($sel, 'max="200"') !== false, 'old flow: guest field max 200 is back');
    ob_start(); brb_place_schema(); $schema_old = ob_get_clean();
    brb_t(strpos($schema_old, 'priceRange') === false, 'old flow: venue schema unchanged (no priceRange)');
    $oa = function ($q, $det) { $GLOBALS['brb_chat_detail'] = $det; $r = brb_chat_answer($q, [], $det); $GLOBALS['brb_chat_detail'] = false; return $r; };
    brb_t(strpos((string) $oa('Is December 12 available?', true)['reply'], 'We review your request. Once it\'s approved') !== false, 'old flow: detail availability answer keeps the original review line');
    brb_t(strpos((string) $oa('Where do I mail a check?', false)['reply'], 'The deposit is 50%') === 0, 'old flow: mail-a-check answer unchanged (81776f6 wording)');
    brb_t($oa('Can you change my booking?', false)['intent'] === $walk_change_intent, '"Can you change my booking?" routes the same as the old flow / 81776f6 (' . $walk_change_intent . ')');
    $GLOBALS['brb_chat_detail'] = false;
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
