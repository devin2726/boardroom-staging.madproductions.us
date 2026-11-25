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
    brb_t(brb_public_price_range() === 'Starting at $850+', 'public price line is Starting at $850+ (Max, Nov 25, 2026, 5:15 AM CT)');
    brb_t(brb_public_price_from() === '$850+', 'amount helper appends a single plus');
    brb_t(brb_walk_price_line() === 'Starting at $850+. We quote your exact price at the walk-through for first-time renters, or when we reach out if you\'ve rented with us before.', 'walk price sentence is first-time / returning, keeps Starting at $850+');
    brb_t(strpos(brb_public_price_range(), '++') === false && strpos(brb_walk_price_line(), '$850++') === false, 'no double plus');
    brb_t(array_keys(brb_walk_days()) === ['mon', 'tue', 'wed', 'thu', 'fri'], 'walk-through days are Monday to Friday only');
    brb_t(brb_walk_windows() === ['morning' => 'Morning (8 AM–12 PM)', 'afternoon' => 'Afternoon (12–4 PM)'], 'walk-through windows are 8–12 and 12–4');

    // ---- public copy
    $how = brb_how_booking_works_html();
    brb_t(strpos($how, 'Hold a Date') !== false && strpos($how, 'Prices&nbsp;on') === false && strpos($how, '50%') === false, 'Book page facts say Hold a Date, no calendar prices or 50%');
    brb_t(strpos($how, 'First time renting The Boardroom') !== false && strpos($how, 'no walk-through is needed') !== false && strpos($how, '!') === false, 'Book page intro has the first-time / returning renter line, no exclamation');

    brb_t(strpos($how, 'Starting at $850+') !== false && strpos($how, '1,500') === false && strpos($how, '$850++') === false, 'Book page shows Starting at $850+, no top of range');
    $steps = implode(' ', brb_how_booking_works_steps());
    brb_t(strpos($steps, 'tentative hold') !== false && strpos($steps, 'walk-through') !== false && !preg_match('/3%|50%|7 days/', $steps), 'booking steps describe tentative hold + walk-through, no fee/deposit/balance');
    brb_t(strpos($steps, brb_walk_first_time_line()) !== false && strpos($steps, 'no walk-through is needed') !== false, 'booking steps include first-time walk-through and returning renters can place a hold');
    brb_t(strpos($steps, 'automatically') === false && strpos($steps, 'confirmed immediately') === false && strpos($steps, '!') === false, 'steps do not promise auto-confirmation or use exclamation points');

    $GLOBALS['brb_np_test_faq'] = true;
    $faq = brb_walk_faq_swap(brb_faq_markup());
    brb_t(strpos($faq, 'The price shows right on the calendar') === false, 'FAQ no longer says the price is on the calendar');
    brb_t(strpos($faq, 'How is pricing set?') !== false && strpos($faq, 'Starting at $850+') !== false && strpos($faq, '1,500') === false, 'FAQ How is pricing set? says Starting at $850+');
    brb_t(strpos($faq, 'What is the walk-through?') !== false, 'FAQ explains the walk-through');
    brb_t(strpos($faq, 'First time renting The Boardroom') !== false && strpos($faq, 'no walk-through is needed') !== false, 'FAQ How do I book / What is the walk-through include the first-time / returning line');

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
        brb_t(strpos($gb, 'tentatively held') !== false && strpos($gb, 'If this is your first time renting The Boardroom') !== false && strpos($gb, 'confirm your hold') !== false, 'guest email: received, tentatively held, first-time walk-through or confirm the hold');
        brb_t(strpos($gb, "Please don't count on a walk-through time until we confirm it") !== false, 'guest email says not to count on the time until confirmed');
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
        brb_t(strpos($page, 'tentatively held') !== false && strpos($page, 'If this is your first time renting The Boardroom') !== false && strpos($page, 'confirm your hold') !== false && !preg_match('/\$\s?\d/', wp_strip_all_tags($page)), 'status page: tentatively held, first-time / returning follow-up, no dollar amount');
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
    brb_t(strpos($q, 'Rental Price') === false && strpos($q, 'Starting at $850+') !== false && strpos($q, '1,500') === false, 'Your date card shows Starting at $850+, not a Rental Price');
    brb_t(substr_count($q, 'name="walk_days[]"') === 5 && substr_count($q, 'name="walk_window[]"') === 2, 'form has Mon–Fri and morning/afternoon choices');
    brb_t(strpos($q, 'not set until we reach out and confirm') !== false, 'form says the walk-through is not set until we confirm');
    brb_t(strpos($q, 'can skip the walk-through') !== false, 'walk-through fields note that returning renters can skip the walk-through');
    brb_t(strpos($q, 'name="guest_says_returning"') !== false && strpos($q, 'rented with The Boardroom before') !== false, 'form has optional I\'ve rented with The Boardroom before checkbox');
    brb_t(strpos($q, 'the weekday and time are then optional') !== false, 'form note says weekday and time are optional when the box is checked');
    brb_t(strpos($q, 'name="returning_renter"') === false, 'public form does not include the staff-only returning_renter field');
    brb_t(strpos($q, 'if(claimed())return;') !== false && strpos($q, "input[name='guest_says_returning']") !== false, 'client submit guard skips walk-through weekday/time when the guest claim is checked');


    brb_t(strpos($q, 'Send Hold a Date request') !== false, 'button says Send Hold a Date request');

    // ---- guest claim checkbox (Max, Nov 25, 2026, 8:16 AM CT: "Add those")
    $ymd_claim = $open_weekday(43);
    $GLOBALS['brb_np_mails'] = [];
    $no_days = brb_process_public_booking($post_for($ymd_claim, 'nopricing-claim-req@example.com', ['guest_says_returning' => '1']));
    brb_t($no_days && !is_wp_error($no_days), 'checked guest claim: walk-through weekday and time are optional');
    if ($no_days && !is_wp_error($no_days)) {
        $created[] = (int) $no_days->id;
        brb_t(!empty($no_days->guest_says_returning) && empty($no_days->returning_renter), 'guest claim is stored and does not set the staff returning_renter flag');
        brb_t((string) ($no_days->walk_days ?? '') === '' && (string) ($no_days->walk_window ?? '') === '', 'optional walk-through fields may be left blank');
        $gb = ''; $gsub = '';
        foreach ($GLOBALS['brb_np_mails'] as $mail) {
            $to = is_array($mail['to']) ? implode(',', $mail['to']) : (string) $mail['to'];
            if (strpos($to, 'nopricing-claim-req@example.com') !== false) { $gb = (string) $mail['message']; $gsub = (string) $mail['subject']; }
        }
        brb_t(strpos($gsub, 'We received your Boardroom request') !== false, 'returning confirmation uses the same request-received subject');
        brb_t(strpos($gb, 'tentatively held') !== false && strpos($gb, 'A tentative hold is not a confirmed booking') !== false, 'returning email keeps tentative-hold wording');
        brb_t(strpos($gb, 'We\'ll reach out to confirm your hold and the quote, starting at $850+') !== false, 'returning email confirms the hold and the quote, starting at $850+');
        brb_t(strpos($gb, 'set up an in-person walk-through') === false && strpos($gb, 'set up a walk-through') === false && strpos($gb, 'your walk-through') === false, 'returning email does not mention setting up a walk-through');
        brb_t(strpos(str_replace('Thank you for contacting The Boardroom!', '', $gb), '!') === false, 'returning email body has no new exclamation points');
        $staffb = '';
        foreach ($GLOBALS['brb_np_mails'] as $mail) {
            $to = is_array($mail['to']) ? implode(',', $mail['to']) : (string) $mail['to'];
            if (strpos($to, 'nopricing-claim-req@example.com') === false) $staffb = (string) $mail['message'];
        }
        brb_t(strpos($staffb, 'Guest claim:') !== false && strpos($staffb, 'I\'ve rented with The Boardroom before') !== false && strpos($staffb, 'mark Returning renter') !== false, 'staff notice shows the guest claim and that staff still verify it');
        $_GET = ['br_token' => $no_days->token];
        ob_start(); brb_view_confirmation($no_days->token); $page = ob_get_clean();
        $_GET = [];
        brb_t(strpos($page, 'Nothing is charged') !== false && strpos($page, 'confirm your hold and the quote, starting at $850+') !== false, 'post-submit screen uses the returning thanks line');
        brb_t(strpos($page, 'set up an in-person walk-through') === false, 'post-submit screen does not mention setting up a walk-through');
        $denied = brb_approve_booking($no_days->id, false);
        brb_t(is_wp_error($denied) && $denied->get_error_code() === 'walkthrough', 'guest claim alone does not waive the staff walk-through approval gate');
        $detail = brb_sd_booking_detail($no_days);
        brb_t(!empty($detail['guest_says_returning']) && empty($detail['returning_renter']), 'staff dashboard detail shows the guest claim and not the staff flag');
        if (function_exists('brb_sd_draft')) {
            $draft = brb_sd_draft($no_days->id, 'request');
            $dbody = is_array($draft) ? (string) ($draft['body'] ?? '') : '';
            brb_t(!is_wp_error($draft) && strpos($dbody, 'confirm your hold and the quote, starting at $850+') !== false && strpos($dbody, 'set up an in-person walk-through') === false, 'staff request-email preview uses the returning confirmation body');
        }
    }
    $ymd_tamper = $open_weekday(44);
    $tamp = brb_process_public_booking($post_for($ymd_tamper, 'nopricing-tamper@example.com', ['returning_renter' => '1', 'walk_days' => ['mon'], 'walk_window' => ['morning']]));
    brb_t($tamp && !is_wp_error($tamp), 'tampered POST with returning_renter still saves when walk-through fields are present');
    if ($tamp && !is_wp_error($tamp)) {
        $created[] = (int) $tamp->id;
        brb_t(empty($tamp->returning_renter) && empty($tamp->guest_says_returning), 'a tampered returning_renter POST does not set the staff flag or the guest claim');
    }
    $ymd_both = $open_weekday(45);
    $both = brb_process_public_booking($post_for($ymd_both, 'nopricing-both@example.com', ['guest_says_returning' => '1', 'returning_renter' => '1']));
    brb_t($both && !is_wp_error($both), 'POST with both the guest claim and a tampered staff flag is accepted (walk fields optional)');
    if ($both && !is_wp_error($both)) {
        $created[] = (int) $both->id;
        brb_t(!empty($both->guest_says_returning) && empty($both->returning_renter), 'only the guest claim is stored; the staff flag stays off');
    }
    $ymd_off = $open_weekday(46);
    $still_req = brb_process_public_booking($post_for($ymd_off, 'nopricing-claim-off@example.com'));
    brb_t(is_wp_error($still_req) && $still_req->get_error_code() === 'walk_days', 'unchecked claim: walk-through weekday is still required');


    // ---- QA round 2 (Les, Nov 24): chat, checklist, pay window, FAQ cancellation, guest cap
    $chat = function ($q) { $r = brb_chat_answer($q); return (string) $r['reply']; };
    $no_old_pay = function ($t) { return strpos($t, '50%') === false && strpos($t, '3%') === false && stripos($t, 'within 1 day') === false && stripos($t, 'deposit') === false; };
    foreach (['How much is the deposit?', 'How do I pay?', 'Do you take credit cards?', 'What is the cancellation policy?'] as $q) {
        $a = $chat($q);
        brb_t($no_old_pay($a), 'chat walk mode: "' . $q . '" has no 50%, 1 day, 3% or deposit (' . substr($a, 0, 90) . ')');
    }
    $a = brb_chat_walk_payment_text(false);
    brb_t(strpos($a, '$850') !== false && stripos($a, 'walk-through') !== false && stripos($a, 'payment details') !== false && strpos($a, 'first-time renters') !== false, 'chat payment answer: range, first-time / returning walk-through, payment details');
    $GLOBALS['brb_chat_detail'] = false;
    $short_ok = $no_old_pay(brb_chat_payment_text(false)) && $no_old_pay(brb_chat_cancel_text());
    $GLOBALS['brb_chat_detail'] = true;
    brb_t($short_ok && $no_old_pay(brb_chat_payment_text(true)) && $no_old_pay(brb_chat_cancel_text()), 'chat payment and cancel texts (short and detailed) carry no deposit terms');
    brb_t(stripos(brb_chat_tour_text(), 'Hold a Date') !== false, 'chat walk-through answer points to Hold a Date');
    brb_t(strpos(brb_chat_tour_text(), brb_walk_first_time_line()) !== false, 'chat walk-through answer includes first-time / returning wording');
    brb_t(strpos(brb_chat_reserved_sentence(), brb_walk_first_time_line()) !== false, 'chat hold sentence includes first-time / returning wording');
    foreach (['Can I book a tour?', 'Do I need a walk-through?', 'I have rented before, can I just place a hold?', 'How do I hold a date?'] as $q) {
        $GLOBALS['brb_chat_detail'] = true;
        $a = (string) brb_chat_answer($q, [], true)['reply'];
        brb_t(strpos($a, 'no walk-through is needed') !== false && strpos($a, 'tentatively held.') === false && strpos($a, '!') === false, 'chat detail "' . $q . '" covers returning renters, does not claim a hold exists, no exclamation');
    }
    $GLOBALS['brb_chat_detail'] = false;
    $llms_body = function_exists('brb_llms_txt_body') ? brb_llms_txt_body() : '';
    if ($llms_body !== '') brb_t(strpos($llms_body, brb_walk_first_time_line()) !== false, 'llms.txt includes the first-time / returning line');

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

    // ---- Starting at $850+ (Max, Nov 25, 2026, 5:15 AM CT): no $1,500 / top of range anywhere in walk-mode output
    $walk_out = [brb_how_booking_works_html(), brb_walk_faq_swap(brb_faq_markup()), implode("\n", brb_walk_steps())];
    $GLOBALS['brb_chat_detail'] = true;
    foreach (['How much does it cost?', 'How much is the deposit?', 'How do I pay?', 'Can I book a tour?', 'What is the price for a Saturday?', 'How much is December 12?', 'Price for a wedding?'] as $q) $walk_out[] = (string) brb_chat_answer($q, [], true)['reply'];
    $GLOBALS['brb_chat_detail'] = false;
    foreach (['How much does it cost?', 'How much is the deposit?', 'How much is December 12?'] as $q) $walk_out[] = (string) brb_chat_answer($q)['reply'];
    ob_start(); brb_place_schema(); $schema = ob_get_clean();
    $walk_out[] = $schema;
    if (function_exists('brb_llms_txt_body')) $walk_out[] = (string) brb_llms_txt_body();
    $blob = implode("\n", $walk_out);
    brb_t(!preg_match('/1,500|\b1500\b|seasonal availability|Rentals run|\$850\+\+/', $blob), 'walk mode: no $1,500, 1500, seasonal range, Rentals run, or $850++');
    brb_t(strpos($blob, 'Starting at $850+') !== false || strpos($blob, 'starting at $850+') !== false, 'walk-mode output uses Starting at $850+');
    brb_t(strpos($schema, 'priceRange') === false && strpos($schema, 'maxPrice') === false && strpos($schema, 'highPrice') === false, 'EventVenue schema has no priceRange in the new flow (Les: not valid on EventVenue; Max Nov 11: not decided)');
    $GLOBALS['brb_chat_detail'] = false;
    foreach (['How much is the deposit?', 'How much is the deposit and how do I pay?', 'Can I pay with a card?'] as $q) {
        foreach ([false, true] as $det) {
            $GLOBALS['brb_chat_detail'] = $det;
            $a = (string) brb_chat_answer($q, [], $det)['reply'];
            brb_t(substr_count($a, 'We quote your exact price') === 1 && substr_count($a, 'Starting at $850+') === 1, 'chat ' . ($det ? 'detail' : 'short') . ' "' . $q . '" says the price and "We quote your exact price" once');
        }
    }
    $GLOBALS['brb_chat_detail'] = false;
    brb_t(strpos((string) brb_chat_answer('Saturday price?')['reply'], 'Starting at $850+') !== false, 'chat: "Saturday price?" answers with Starting at $850+ (no empty reply)');
    $GLOBALS['brb_chat_detail'] = false;
    foreach (['How much does it cost?', 'Saturday price?', 'How much to book a Saturday?'] as $q) {
        foreach ([false, true] as $det) {
            $GLOBALS['brb_chat_detail'] = $det;
            $a = (string) brb_chat_answer($q, [], $det)['reply'];
            brb_t(strpos($a, 'we\'ll reach out to set up the walk-through') === false && strpos($a, 'we\'ll reach out to set up your walk-through') === false, 'chat ' . ($det ? 'detail' : 'short') . ' "' . $q . '" does not say everyone gets a walk-through reach-out');
            brb_t(strpos($a, 'Starting at $850+') !== false && (strpos($a, 'first-time renters') !== false || strpos($a, 'first time renting The Boardroom') !== false || strpos($a, 'If this is your first time renting The Boardroom') !== false), 'chat ' . ($det ? 'detail' : 'short') . ' "' . $q . '" keeps Starting at $850+ and first-time / returning wording');
        }
    }
    $GLOBALS['brb_chat_detail'] = false;
    if (function_exists('br_seo_pages')) {
        $seo = br_seo_pages();
        brb_t(strpos($seo['home']['description'], 'we meet you for a walk-through') === false && strpos($seo['home']['description'], 'First-time renters') !== false && strpos($seo['home']['description'], 'rented with us before') !== false, 'home meta: first-time / returning, not everyone gets a walk-through');
        brb_t(strpos($seo['reserve-the-boardroom-quad-cities']['description'], 'set up an in-person walk-through, and quote your price there') === false && strpos($seo['reserve-the-boardroom-quad-cities']['description'], 'First-time renters') !== false, 'Book meta: first-time / returning, not everyone gets a walk-through');
    }
    $llms_txt = function_exists('brb_llms_body') ? brb_llms_body() : (function_exists('brb_llms_txt_body') ? brb_llms_txt_body() : '');
    if ($llms_txt !== '') brb_t(strpos($llms_txt, 'We quote the price at the walk-through') === false && strpos($llms_txt, 'Starting at $850+') !== false, 'llms.txt drops leftover everyone-walks price quote, keeps Starting at $850+');
    $check = brb_walk_checklist_hold_line();
    brb_t(strpos($check, 'after your walk-through') === false && strpos($check, 'no walk-through is needed') !== false, 'checklist hold line is first-time / returning');
    $faq_pay = brb_walk_faq_swap(brb_faq_markup());
    brb_t(strpos($faq_pay, 'After your walk-through, once you approve') === false && strpos($faq_pay, 'First-time renters approve it after the walk-through') !== false, 'FAQ How do I pay is first-time / returning');

    foreach (['What does it cost and how do I pay?', 'How much does it cost and how do I pay?'] as $q) {
        foreach ([false, true] as $det) {
            $GLOBALS['brb_chat_detail'] = $det;
            $a = (string) brb_chat_answer($q, [], $det)['reply'];
            brb_t(substr_count($a, 'We quote your exact price') === 1 && strpos($a, 'Starting at $850+') !== false && strpos($a, '$850++') === false, 'chat ' . ($det ? 'detail' : 'short') . ' "' . $q . '" says the price once (Les round 5 combined cost+pay)');
        }
    }
    $GLOBALS['brb_chat_detail'] = false;
    $o2 = get_option('brb_settings'); $sp_keep = $o2['starting_price'] ?? '850';
    $o2['starting_price'] = '1200'; update_option('brb_settings', $o2);
    brb_t(brb_public_price_range() === 'Starting at $1,200+' && brb_public_price_from() === '$1,200+', 'setting 1200 displays as Starting at $1,200+');
    $o2['starting_price'] = '1200.60'; update_option('brb_settings', $o2);
    brb_t(brb_public_price_range() === 'Starting at $1,201+', 'decimal 1200.60 rounds to $1,201+');
    $o2['starting_price'] = '900.40'; update_option('brb_settings', $o2);
    brb_t(brb_public_price_range() === 'Starting at $900+', 'decimal 900.40 rounds to $900+');
    $o2['starting_price'] = $sp_keep; update_option('brb_settings', $o2);
    brb_t(!array_key_exists('public_price_from', brb_defaults()) && !array_key_exists('public_price_range', brb_defaults()) && (string) brb_defaults()['starting_price'] === '850', 'no orphan price keys in defaults; starting_price default stays 850');
    $o3 = get_option('brb_settings');
    $o3['public_price_from'] = '999'; $o3['public_price_range'] = 'stale';
    update_option('brb_settings', $o3);
    brb_migrate_settings();
    $after = get_option('brb_settings');
    brb_t(!isset($after['public_price_from']) && !isset($after['public_price_range']), 'migrate unsets orphan public_price_from and public_price_range');


    // ---- staff-only returning renter (walk-through not needed)
    $ymd_ret = $open_weekday(42);
    $bret = brb_process_public_booking($post_for($ymd_ret, 'nopricing-return@example.com', ['walk_days' => ['tue'], 'walk_window' => ['morning']]));
    brb_t($bret && !is_wp_error($bret), 'returning-renter test request is saved');
    if ($bret && !is_wp_error($bret)) {
        $created[] = (int) $bret->id;
        brb_set_agreed_price($bret->id, 1200, false);
        $denied = brb_approve_booking($bret->id, false);
        brb_t(is_wp_error($denied) && $denied->get_error_code() === 'walkthrough', 'walk mode: approve without walkthrough_done is refused when returning renter is not set');
        $saved = brb_save_booking_changes($bret->id, [
            'date' => $ymd_ret, 'name' => $bret->name, 'email' => $bret->email, 'phone' => $bret->phone, 'notes' => $bret->notes,
            'walkthrough_done' => 0, 'returning_renter' => 1,
        ]);
        brb_t(!is_wp_error($saved) && !empty(brb_booking($bret->id)->returning_renter) && empty(brb_booking($bret->id)->walkthrough_done), 'admin save stores Returning renter (walk-through not needed) without marking the walk-through complete');
        $ok = brb_approve_booking($bret->id, false);
        brb_t(!is_wp_error($ok) && (string) $ok->status === 'approved' && empty($ok->walkthrough_done) && !empty($ok->returning_renter), 'walk mode: staff-marked returning renter can be approved without walkthrough_done');
        $blockers = brb_pay_link_blockers($ok);
        brb_t(!isset($blockers['walkthrough']), 'walk mode: pay-link walk-through blocker is waived for a returning renter');
    }

    // ---- reinstall switch: old flow comes back
    $set_mode('calendar');
    brb_t(!brb_walkthrough_mode(), 'switching to calendar mode turns the walk-through flow off');
    $ymd_old = $open_weekday(50);
    $bold = brb_process_public_booking($post_for($ymd_old, 'nopricing-oldreturn@example.com'));
    brb_t($bold && !is_wp_error($bold), 'old-flow returning-renter test request is saved');
    if ($bold && !is_wp_error($bold)) {
        $created[] = (int) $bold->id;
        brb_set_agreed_price($bold->id, 1200, false);
        global $wpdb;
        $wpdb->update(brb_table(), ['returning_renter' => 1, 'walkthrough_done' => 0], ['id' => (int) $bold->id]);
        $old_deny = brb_approve_booking($bold->id, false);
        brb_t(is_wp_error($old_deny) && $old_deny->get_error_code() === 'walkthrough', 'old flow: walkthrough_done is still required even if returning_renter is set (81776f6 gate)');
        $wpdb->update(brb_table(), ['walkthrough_done' => 1], ['id' => (int) $bold->id]);
        $old_ok = brb_approve_booking($bold->id, false);
        brb_t(!is_wp_error($old_ok) && (string) $old_ok->status === 'approved', 'old flow: approve still works once walkthrough_done is set');
    }

    brb_t(strpos(brb_how_booking_works_html(), 'Prices&nbsp;on&nbsp;the&nbsp;Calendar') !== false, 'old flow: Prices on the Calendar is back');
    brb_t(strpos(brb_how_booking_works_html(), brb_walk_first_time_line()) === false && strpos(implode(' ', brb_how_booking_works_steps()), 'no walk-through is needed') === false, 'old flow: first-time / returning walk-through line is gone');
    brb_t(strpos(brb_chat_reserved_sentence(), 'First time renting') === false, 'old flow: chat hold sentence does not mention first-time renters');

    $res = brb_rest_availability($req)->get_data();
    $prices = array_filter(array_map(function ($x) { return $x['price']; }, $res['days']));
    brb_t((bool) $prices, 'old flow: calendar prices are back');
    brb_t(!in_array('review', brb_blocking_statuses(), true), 'old flow: a request does not hold the date');
    $_GET = ['date' => $ymd, 'type' => 'day', 'guests' => '20'];
    ob_start(); brb_view_quote(); $q = ob_get_clean();
    $_GET = [];
    brb_t(strpos($q, 'Rental Price') !== false && strpos($q, 'walk_days') === false, 'old flow: Rental Price back, no walk-through fields');
    brb_t(strpos($q, 'guest_says_returning') === false && strpos($q, 'I\'ve rented with The Boardroom before') === false, 'old flow: no I\'ve rented with The Boardroom before checkbox');
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
