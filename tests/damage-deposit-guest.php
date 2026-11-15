<?php
/**
 * Every guest-facing amount matches the admin Money box (brb_booking_money),
 * including the $250 refundable damage deposit. BR-10 style: $850 rental,
 * 50% deposit, $250 damage deposit, 3% card fee. Stripe is never called:
 * the Checkout Session request is intercepted.
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

$GLOBALS['brb_dd_mail'] = [];
$GLOBALS['brb_dd_stripe'] = [];
add_filter('pre_wp_mail', function ($short, $atts) {
    $GLOBALS['brb_dd_mail'][] = $atts;
    return true;
}, 1, 2);
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos((string) $url, 'api.stripe.com') === false) return $pre;
    $GLOBALS['brb_dd_stripe'][] = ['url' => $url, 'body' => (string) ($args['body'] ?? '')];
    return ['headers' => [], 'body' => wp_json_encode(['id' => 'cs_test_harness', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_harness']), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 1, 3);

function brb_dd_mail_to($email, $from = 0) {
    $all = array_slice($GLOBALS['brb_dd_mail'], $from);
    foreach (array_reverse($all) as $m) {
        $to = is_array($m['to']) ? implode(',', $m['to']) : (string) $m['to'];
        if (stripos($to, $email) !== false) return $m;
    }
    return null;
}
function brb_dd_text($html) {
    $html = preg_replace('#<(script|style)\b.*?</\1>#s', ' ', (string) $html);
    $html = preg_replace('#</(p|li|div|h\d|tr)>#', "\n", $html);
    return html_entity_decode(preg_replace('/[ \t]+/', ' ', strip_tags($html)), ENT_QUOTES, 'UTF-8');
}

global $wpdb;
$kept_show = $wpdb->hide_errors();
$saved_env = getenv('BRB_STRIPE_SECRET_KEY');
$saved_opt = get_option('brb_settings');
$kept_get = $_GET;
$created = [];
try {
    brb_t(get_option('brb_version') === '2.5.79', 'plugin storage version is 2.5.79');
    putenv('BRB_STRIPE_SECRET_KEY=sk_test_harness_not_real');
    $admin = get_users(['role' => 'administrator', 'number' => 1]);
    if ($admin) wp_set_current_user($admin[0]->ID);
    $o = brb_opt();
    $o['deposit_type'] = 'percent';
    $o['deposit_pct'] = '50';
    $o['damage_deposit_amount'] = '250';
    $o['card_fee_percent'] = '3';
    update_option('brb_settings', $o);

    $wpdb->insert(brb_table(), ['token' => wp_generate_password(32, false), 'mode' => 'day', 'start_dt' => '2027-05-23 10:00:00', 'end_dt' => '2027-05-23 23:30:00', 'guests' => 50, 'name' => 'Damage Test Guest', 'email' => 'damage-guest@example.com', 'phone' => '', 'notes' => '', 'status' => 'review', 'source' => 'online', 'created' => current_time('mysql', true)]);
    $id = (int) $wpdb->insert_id;
    $created[] = $id;
    $priced = brb_set_agreed_price($id, 850, false);
    $b = brb_booking($id);
    brb_t(!is_wp_error($priced) && $b && abs((float) $b->total - 850) < 0.001 && abs((float) $b->deposit_due - 425) < 0.001, 'a BR-10 style booking is priced at $850 with a $425 deposit');

    // The admin Money box is the reference.
    $m = brb_booking_money($b);
    brb_t($m['rows']['Rental'] === '$850.00' && $m['rows']['Refundable damage deposit'] === '$250.00 unpaid' && $m['rows']['Due now to reserve'] === '$425.00 + $250.00 = $675.00' && $m['rows']['Balance'] === '$425.00' && abs($m['due_now'] - 675) < 0.001, 'the Money box shows Rental $850, damage $250 unpaid, due now $675, balance $425');
    $expect_text = "Rental: $850.00\nRefundable damage deposit: $250.00 unpaid\nDue now to reserve: $425.00 + $250.00 = $675.00\nBalance: $425.00";
    brb_t(brb_guest_money_text($b) === $expect_text, 'the guest money text is built from the Money box rows');

    // 1. "Email guest the total" price email, pending review.
    $price_body = brb_price_guest_body($b);
    brb_t(strpos($price_body, $expect_text) !== false, 'the price email (pending review) lists rental, damage deposit, due now $675, and balance');
    brb_t(strpos($price_body, 'Agreed price: $850.00' . "\nDeposit: $425.00\n\n") === false, 'the price email no longer stops at agreed price and deposit');
    $mail_from = count($GLOBALS['brb_dd_mail']);
    brb_send_price_email($b);
    $sent = brb_dd_mail_to('damage-guest@example.com', $mail_from);
    brb_t($sent && strpos((string) $sent['message'], 'Refundable damage deposit: $250.00 unpaid') !== false && strpos((string) $sent['message'], 'Due now to reserve: $425.00 + $250.00 = $675.00') !== false, 'the sent price email carries the damage deposit and $675 due now');

    // 2. Guest booking view page, pending review.
    $_GET = ['br_token' => $b->token];
    $view = do_shortcode('[boardroom_booking]');
    $view_text = brb_dd_text($view);
    brb_t(strpos($view, '<strong>Rental:</strong> $850.00') !== false && strpos($view, '<strong>Refundable damage deposit:</strong> $250.00 unpaid') !== false && strpos($view, '<strong>Due now to reserve:</strong> $425.00 + $250.00 = $675.00') !== false && strpos($view, '<strong>Balance:</strong> $425.00') !== false, 'the booking view page shows the same four Money box rows');
    brb_t(strpos($view_text, 'Total $850.00 · Paid $0.00 · Balance $850.00') === false, 'the view page no longer shows a $850 balance that ignores the deposit split');

    // 3. Approval email, check instructions, payment link, pay page, Stripe.
    $wpdb->update(brb_table(), ['walkthrough_done' => 1, 'status' => 'approved', 'balance_due_on' => '2027-05-16'], ['id' => $id]);
    $b = brb_booking($id);
    $m = brb_booking_money($b);
    $approve = brb_approve_guest_body($b);
    brb_t(strpos($approve, 'Refundable damage deposit: $250.00 unpaid') !== false && strpos($approve, 'Due now to reserve: $425.00 + $250.00 = $675.00') !== false && strpos($approve, 'Balance due: Sunday, May 16, 2027') !== false, 'the approval email lists the damage deposit, $675 due now, and the balance due date');
    brb_t(strpos($approve, 'a check for $675.00') !== false && strpos($approve, 'That amount is the $425.00 deposit plus the $250.00 refundable damage deposit.') !== false, 'the check instructions ask for $675 and explain the split');
    $price_approved = brb_price_guest_body($b);
    brb_t(strpos($price_approved, 'Due now to reserve: $425.00 + $250.00 = $675.00') !== false && strpos($price_approved, 'a check for $675.00') !== false, 'the price email after approval agrees with the check instructions');
    brb_t(abs(brb_amount_due_now($b) - $m['due_now']) < 0.001, 'the online amount due equals the Money box due now ($675)');
    $charge = brb_checkout_lines($b);
    $fee = brb_card_fee_cents($charge['base_cents']);
    brb_t($charge['base_cents'] === 67500 && $charge['damage_cents'] === 25000 && $fee === 2025, 'checkout lines are $425 + $250 = $675 and the 3% fee is $20.25');
    brb_t(brb_card_fee_cents(25000) === 750 && brb_card_fee_cents(42500) === 1275, 'the 3% fee covers the damage deposit too ($7.50) and the deposit ($12.75)');
    $link_body = brb_payment_link_body($b);
    brb_t(strpos($link_body, 'Deposit (50%): $425.00') !== false && strpos($link_body, 'Refundable damage deposit: $250.00') !== false && strpos($link_body, 'Card processing fee (3%): $20.25') !== false && strpos($link_body, 'Total charged to your card: $695.25') !== false, 'the payment-link email itemizes $425 + $250 + $20.25 = $695.25');
    ob_start();
    brb_view_pay($b->token, brb_pay_signature($b->token));
    $pay_page = brb_dd_text(ob_get_clean());
    brb_t(strpos($pay_page, 'Refundable damage deposit: $250.00') !== false && strpos($pay_page, 'Total charged to your card: $695.25') !== false, 'the pay page shows the damage deposit and $695.25');
    $GLOBALS['brb_dd_stripe'] = [];
    $url = brb_stripe_create_session($b);
    $req = $GLOBALS['brb_dd_stripe'][0] ?? null;
    $sb = [];
    if ($req) parse_str($req['body'], $sb);
    $items = [];
    foreach ((array) ($sb['line_items'] ?? []) as $li) $items[$li['price_data']['product_data']['name']] = (int) $li['price_data']['unit_amount'];
    brb_t(is_string($url) && $req && strpos($req['url'], '/v1/checkout/sessions') !== false && ($sb['mode'] ?? '') === 'payment', 'the pay link starts a Stripe Checkout Session (intercepted, not sent)');
    brb_t($items === ['Deposit (50%)' => 42500, 'Refundable damage deposit' => 25000, 'Card processing fee (3%)' => 2025] && array_sum($items) === 69525, 'Stripe line items are $425, $250, and $20.25, $695.25 in all');
    brb_t(($sb['metadata']['base_cents'] ?? '') === '67500' && ($sb['metadata']['damage_cents'] ?? '') === '25000' && ($sb['metadata']['fee_cents'] ?? '') === '2025', 'Stripe metadata records base $675, damage $250, and fee $20.25');
    $_GET = ['br_token' => $b->token];
    $view2 = do_shortcode('[boardroom_booking]');
    brb_t(strpos($view2, '<strong>Due now to reserve:</strong> $425.00 + $250.00 = $675.00') !== false && strpos($view2, '<strong>Balance due:</strong> Sunday, May 16, 2027') !== false && strpos(brb_dd_text($view2), 'a check for $675.00') !== false, 'the approved view page shows $675 due now, the due date, and the $675 check');

    // 4. Request email guest copy uses the same rows once priced.
    $mail_from = count($GLOBALS['brb_dd_mail']);
    brb_send_request_emails($b);
    $req_mail = brb_dd_mail_to('damage-guest@example.com', $mail_from);
    brb_t($req_mail && strpos((string) $req_mail['message'], 'Refundable damage deposit: $250.00 unpaid') !== false && strpos((string) $req_mail['message'], 'Price: $850.00') === false, 'the request email guest copy shows the damage deposit instead of only the price');

    // 5. Email customer (Gmail compose) is a blank personal note, no amounts.
    $compose = rawurldecode((string) parse_url(brb_gmail_compose_url($b), PHP_URL_QUERY));
    brb_t(strpos($compose, '$') === false, 'the Email customer Gmail prefill has no amounts to get wrong');

    // 6. After the reserve payment, nothing changes about later amounts.
    $wpdb->update(brb_table(), ['status' => 'deposit_paid', 'amount_paid' => 425, 'damage_paid' => 250], ['id' => $id]);
    $b = brb_booking($id);
    $m = brb_booking_money($b);
    brb_t($m['due_text'] === 'Deposit received' && abs(brb_amount_due_now($b) - 425) < 0.001 && brb_checkout_lines($b)['base_cents'] === 42500 && brb_checkout_lines($b)['damage_cents'] === 0, 'after the $675 is paid, the next card amount is the $425 balance with no second damage deposit');
    $receipt = brb_receipt_block($b, 675, 'check', '2026-11-13');
    brb_t(strpos($receipt, 'Balance remaining: $425.00') !== false, 'the receipt shows $425 remaining');
    $_GET = ['br_token' => $b->token];
    $view3 = do_shortcode('[boardroom_booking]');
    brb_t(strpos($view3, '<strong>Refundable damage deposit:</strong> $250.00 paid') !== false && strpos($view3, '<strong>Paid:</strong> $425.00') !== false && strpos($view3, '<strong>Balance:</strong> $425.00') !== false, 'the view page after the deposit shows damage paid, $425 paid, and $425 balance');
    brb_t((string) brb_booking($id)->status === 'deposit_paid' && abs((float) brb_booking($id)->total - 850) < 0.001, 'no price or status was changed by rendering any of this');
} finally {
    $_GET = $kept_get;
    foreach ($created as $cid) {
        $wpdb->delete(brb_line_table(), ['booking_id' => (int) $cid]);
        $wpdb->delete(brb_table(), ['id' => (int) $cid]);
    }
    update_option('brb_settings', $saved_opt);
    if ($saved_env === false || $saved_env === '') putenv('BRB_STRIPE_SECRET_KEY'); else putenv('BRB_STRIPE_SECRET_KEY=' . $saved_env);
    $wpdb->show_errors($kept_show);
    echo 'ok ' . (int) $GLOBALS['brb_ok'] . ' fail ' . (int) $GLOBALS['brb_fail'] . "\n";
}
