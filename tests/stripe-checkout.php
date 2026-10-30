<?php
/**
 * Run inside WordPress: wp eval-file tests/stripe-checkout.php
 * Signature checks, idempotent webhooks, fee cents, keys off, and pay-link tokens.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Load this with wp eval-file\n");
    exit(1);
}

$GLOBALS['brb_fail'] = 0;
$GLOBALS['brb_ok'] = 0;
if (!function_exists('brb_t')) {
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

function brb_test_env($name, $value) {
    if ($value === null) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
        return;
    }
    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
function brb_test_clear_stripe_env() {
    foreach (['BRB_STRIPE_SECRET_KEY', 'BRB_STRIPE_WEBHOOK_SECRET', 'BRB_STRIPE_PUBLISHABLE_KEY'] as $name) {
        brb_test_env($name, null);
    }
}
function brb_test_sign($payload, $secret, $t) {
    return hash_hmac('sha256', $t . '.' . $payload, $secret);
}
function brb_test_webhook($payload, $header) {
    $req = new WP_REST_Request('POST', '/brb/v1/stripe-webhook');
    $req->set_body($payload);
    if ($header !== null) $req->set_header('stripe-signature', $header);
    return rest_do_request($req);
}
function brb_test_post($payload, $secret) {
    $t = (string) time();
    return brb_test_webhook($payload, 't=' . $t . ',v1=' . brb_test_sign($payload, $secret, $t));
}

global $wpdb;
$snapshot = get_option('brb_settings');
$created_ids = [];
$secret_marker = 'ZZZexamplekey';
$whsec_marker = 'ZZZwebhooksecret';
add_filter('pre_wp_mail', function ($short, $atts) {
    $GLOBALS['brb_mails'][] = $atts;
    return true;
}, 10, 2);
$GLOBALS['brb_mails'] = [];
brb_test_clear_stripe_env();

try {
    brb_t(brb_card_fee_cents(10000, 3) === 300, 'a 3 percent fee on 10000 cents is 300 cents');
    brb_t(brb_card_fee_cents(333, 3) === 10, 'a 3 percent fee on 333 cents rounds 9.99 to 10 cents');
    brb_t(brb_card_fee_cents(1, 3) === 0, 'a 3 percent fee on 1 cent rounds down to 0');
    brb_t(brb_cents_from_amount(100) === 10000, '100 dollars is 10000 cents');
    brb_t(brb_cents_from_amount(1.005) === 101, 'cent conversion rounds half cents up');
    brb_t((string) brb_opt('card_fee_percent') === '3', 'the card fee setting defaults to 3');
    $fee_settings = brb_opt();
    $fee_settings['card_fee_percent'] = '10';
    update_option('brb_settings', $fee_settings);
    brb_t(brb_card_fee_cents(10000) === 1000 && brb_fee_percent_label() === '10%', 'the fee percent setting changes the cents and the label');
    $fee_settings['card_fee_percent'] = '3';
    update_option('brb_settings', $fee_settings);

    $payload = '{"id":"evt_sig","type":"checkout.session.expired"}';
    $now = 1_700_000_000;
    $whsec = 'whsec_' . $whsec_marker;
    $good = brb_test_sign($payload, $whsec, (string) $now);
    brb_t(brb_stripe_verify_signature($payload, 't=' . $now . ',v1=' . $good, $whsec, $now), 'a fresh signature is accepted');
    brb_t(brb_stripe_verify_signature($payload, 't=' . ($now - 300) . ',v1=' . brb_test_sign($payload, $whsec, (string) ($now - 300)), $whsec, $now), 'a signature 300 seconds old is accepted');
    brb_t(brb_stripe_verify_signature($payload, 't=' . ($now + 300) . ',v1=' . brb_test_sign($payload, $whsec, (string) ($now + 300)), $whsec, $now), 'a signature 300 seconds ahead is accepted');
    brb_t(!brb_stripe_verify_signature($payload, 't=' . ($now - 301) . ',v1=' . brb_test_sign($payload, $whsec, (string) ($now - 301)), $whsec, $now), 'a signature 301 seconds old is rejected');
    brb_t(!brb_stripe_verify_signature($payload, 't=' . ($now + 301) . ',v1=' . brb_test_sign($payload, $whsec, (string) ($now + 301)), $whsec, $now), 'a signature 301 seconds ahead is rejected');
    brb_t(!brb_stripe_verify_signature($payload . ' ', 't=' . $now . ',v1=' . $good, $whsec, $now), 'a tampered body is rejected');
    brb_t(!brb_stripe_verify_signature($payload, '', $whsec, $now), 'a missing signature header is rejected');
    brb_t(!brb_stripe_verify_signature($payload, 't=' . $now . ',v1=' . $good, '', $now), 'a missing webhook secret is rejected');
    $wrong = $good;
    $wrong[strlen($wrong) - 1] = $wrong[strlen($wrong) - 1] === '0' ? '1' : '0';
    brb_t(strlen($wrong) === strlen($good) && !brb_stripe_verify_signature($payload, 't=' . $now . ',v1=' . $wrong, $whsec, $now), 'an equal-length wrong signature is rejected');
    brb_t(!brb_stripe_verify_signature($payload, 't=' . $now . ',v1=abc', $whsec, $now), 'a short signature is rejected');
    $multi = 't=' . $now . ',v1=' . $wrong . ',v1=' . $good;
    brb_t(brb_stripe_verify_signature($payload, $multi, $whsec, $now), 'one matching v1 among several is accepted');

    brb_t(!brb_stripe_enabled() && brb_stripe_mode() === '', 'card payment is off when no secret key is set');
    $unsigned = brb_test_webhook($payload, null);
    brb_t($unsigned->get_status() === 400, 'an unsigned webhook is rejected with 400');
    brb_test_env('BRB_STRIPE_WEBHOOK_SECRET', $whsec);
    $signed_late = brb_test_webhook($payload, 't=' . ($now - 301) . ',v1=' . brb_test_sign($payload, $whsec, (string) ($now - 301)));
    brb_t($signed_late->get_status() === 400, 'the webhook route rejects a stale signature');
    $get_route = rest_do_request(new WP_REST_Request('GET', '/brb/v1/stripe-webhook'));
    brb_t($get_route->get_status() === 404, 'the webhook route does not accept GET');

    $admin = get_user_by('login', 'admin');
    brb_t((bool) $admin, 'an admin user exists for the settings screen');
    if ($admin) {
        wp_set_current_user($admin->ID);
        unset($_POST['brb_save']);
        ob_start();
        brb_admin_settings();
        $off_html = ob_get_clean();
        brb_t(strpos($off_html, 'Stripe connected: no') !== false && strpos($off_html, 'Mode: —') !== false, 'settings show Stripe disconnected and no mode');
    }

    $full_deposit = brb_opt();
    $full_deposit['deposit_type'] = 'full';
    $full_deposit['deposit_pct'] = '100';
    update_option('brb_settings', $full_deposit);
    $day = brb_now()->modify('+35 days')->format('Y-m-d');
    $held = brb_process_public_booking([
        'date' => $day,
        'type' => 'day',
        'days' => 1,
        'guests' => 12,
        'name' => 'Stripe Flow',
        'email' => 'stripe-flow@example.com',
        'policy' => '1', 'waiver' => '1',
        'event_type' => 'fundraiser',
        'public_event' => 'no',
        'free_admission' => '1',
        'alcohol' => 'no',
        'end_time' => '22:00',
        'host_age' => 'age_25',
        'event_about' => 'A fundraiser in the main room.',
    ]);
    brb_t(!is_wp_error($held) && $held && $held->status === 'review', 'a request can be created for card payment');
    if (!is_wp_error($held) && $held) {
        $wpdb->update(brb_table(), ['walkthrough_done' => 1], ['id' => (int) $held->id]);
        $held = brb_approve_booking($held->id);
    }
    brb_t(!is_wp_error($held) && $held && $held->status === 'approved', 'staff approve the request before card payment');
    if (is_wp_error($held) || !$held) {
        throw new RuntimeException('booking was not created');
    }
    $created_ids[] = (int) $held->id;
    brb_t(brb_pay_link($held) === '', 'an unpriced hold has no pay link');
    $off_session = brb_stripe_create_session($held);
    brb_t(is_wp_error($off_session) && $off_session->get_error_code() === 'stripe', 'checkout cannot start while the secret key is missing');

    $priced = brb_set_agreed_price($held->id, 100);
    brb_t(!is_wp_error($priced) && abs(brb_amount_due_now($priced) - 350) < 0.001, 'the amount due is the rental balance plus the refundable damage deposit');
    brb_t(brb_pay_link($priced) === '', 'the pay link stays empty until the secret key is set');
    $secret = 'sk_test_' . $secret_marker;
    $publishable = 'pk_test_' . $secret_marker;
    brb_test_env('BRB_STRIPE_SECRET_KEY', $secret);
    brb_test_env('BRB_STRIPE_PUBLISHABLE_KEY', $publishable);
    brb_t(brb_stripe_enabled() && brb_stripe_mode() === 'test', 'a test secret key enables test mode');
    brb_t(strpos(brb_stripe_redact('using ' . $secret . ' and ' . $whsec . ' and ' . $publishable), $secret_marker) === false, 'redaction removes key material from a message');
    $pay_url = brb_pay_link($priced);
    brb_t($pay_url !== '' && strpos($pay_url, 'br_pay=' . $priced->token) !== false && strpos($pay_url, 'br_sig=') !== false, 'a priced hold gets a signed pay link');
    $sig = brb_pay_signature($priced->token);
    brb_t(brb_pay_token_valid($priced->token, $sig), 'the pay link signature matches the booking token');
    brb_t(!brb_pay_token_valid($priced->token, $wrong), 'a signature from another payload is not a pay link');
    brb_t(!brb_pay_token_valid($priced->token . 'x', $sig), 'a swapped token is rejected');
    brb_t(!brb_pay_token_valid('', $sig) && !brb_pay_token_valid($priced->token, ''), 'empty pay-link parts are rejected');
    $other_sig = brb_pay_signature($priced->token . 'other');
    brb_t(!brb_pay_token_valid($priced->token, $other_sig), 'a signature for a different token is rejected');

    ob_start();
    brb_view_pay($priced->token, 'not-the-signature');
    $bad_page = ob_get_clean();
    brb_t(strpos($bad_page, 'This payment link is not valid.') !== false, 'a bad pay link tells the guest it is not valid');
    ob_start();
    brb_view_pay($priced->token, $sig);
    $pay_page = ob_get_clean();
    brb_t(strpos($pay_page, 'Deposit: $100.00') !== false && strpos($pay_page, 'Refundable damage deposit: $250.00') !== false, 'the pay page itemizes the deposit and the refundable damage deposit');
    brb_t(strpos($pay_page, 'Card processing fee (3%): $10.50') !== false, 'the pay page shows the 3 percent fee on the deposit and the damage deposit');
    brb_t(strpos($pay_page, 'Total charged to your card: $360.50') !== false, 'the pay page shows the card total before redirect');
    brb_t(strpos($pay_page, 'I understand a 3% card processing fee applies') !== false && strpos($pay_page, '60 or more days before the event') !== false && strpos($pay_page, 'less than 60 days before the event') !== false && strpos($pay_page, '>Terms<') !== false && strpos($pay_page, '>Rental Agreement<') !== false, 'the pay page requires the fee, the 60-day deposit rule, and the agreement checkbox');
    brb_t(strpos($pay_page, 'name="card_fee_agree"') !== false && strpos($pay_page, 'required') !== false, 'the fee checkbox is required');
    brb_t(strpos($pay_page, 'js.stripe.com') === false && stripos($pay_page, 'card number') === false && strpos($pay_page, 'cardnumber') === false, 'the pay page has no card fields');
    brb_t(strpos($pay_page, $secret_marker) === false && strpos($pay_page, $whsec_marker) === false, 'the pay page does not show key material');
    $no_agree = brb_stripe_begin_checkout($priced->token, $sig, false);
    brb_t(is_wp_error($no_agree) && $no_agree->get_error_code() === 'agree', 'checkout is refused until the fee checkbox is accepted');

    $captured = null;
    add_filter('pre_http_request', function ($pre, $args, $url) use (&$captured) {
        $captured = ['url' => $url, 'args' => $args];
        return [
            'headers' => [],
            'body' => wp_json_encode(['id' => 'cs_test_redirect', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_redirect']),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }, 10, 3);
    $started = brb_stripe_begin_checkout($priced->token, $sig, true);
    brb_t($started === 'https://checkout.stripe.com/c/pay/cs_test_redirect', 'an accepted checkbox returns the hosted checkout URL');
    brb_t(is_array($captured) && $captured['url'] === 'https://api.stripe.com/v1/checkout/sessions', 'checkout is created with the Stripe REST API');
    $raw_body = is_array($captured) ? (string) ($captured['args']['body'] ?? '') : '';
    $auth = is_array($captured) ? (string) ($captured['args']['headers']['Authorization'] ?? '') : '';
    brb_t($auth === 'Bearer ' . brb_stripe_secret(), 'checkout sends the secret as a bearer token');
    brb_t(strpos($raw_body, '{CHECKOUT_SESSION_ID}') !== false && strpos($raw_body, '%7BCHECKOUT_SESSION_ID%7D') === false, 'the success URL keeps the session placeholder unencoded');
    $parsed = [];
    parse_str($raw_body, $parsed);
    brb_t(($parsed['line_items'][0]['price_data']['product_data']['name'] ?? '') === 'Deposit' && ($parsed['line_items'][0]['price_data']['unit_amount'] ?? '') === '10000', 'the deposit line is the rental amount in cents');
    brb_t(($parsed['line_items'][1]['price_data']['product_data']['name'] ?? '') === 'Refundable damage deposit' && ($parsed['line_items'][1]['price_data']['unit_amount'] ?? '') === '25000', 'the damage deposit is its own checkout line');
    brb_t(($parsed['line_items'][2]['price_data']['product_data']['name'] ?? '') === 'Card processing fee (3%)', 'the fee is its own checkout line');
    brb_t(($parsed['line_items'][2]['price_data']['unit_amount'] ?? '') === '1050', 'the fee line includes 3 percent of the damage deposit');
    brb_t(($parsed['metadata']['booking_id'] ?? '') === (string) $priced->id && ($parsed['metadata']['token'] ?? '') === $priced->token && ($parsed['metadata']['damage_cents'] ?? '') === '25000', 'checkout metadata carries the booking, token, and damage deposit');
    $negative = false;
    foreach (($parsed['line_items'] ?? []) as $item) {
        if ((int) ($item['price_data']['unit_amount'] ?? 0) < 0) $negative = true;
    }
    brb_t(!$negative, 'checkout lines are not negative');
    brb_t(strpos($raw_body, $secret_marker) === false, 'the checkout body does not contain the secret key');

    remove_all_filters('pre_http_request');
    $captured = null;
    add_filter('pre_http_request', function ($pre) {
        return [
            'headers' => [],
            'body' => wp_json_encode(['url' => 'https://evil.example/pay']),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    });
    $evil = brb_stripe_create_session($priced);
    brb_t(is_wp_error($evil), 'a checkout URL on another host is refused');
    remove_all_filters('pre_http_request');

    if ($admin) {
        unset($_POST['brb_save']);
        ob_start();
        brb_admin_settings();
        $on_html = ob_get_clean();
        brb_t(strpos($on_html, 'Stripe connected: yes') !== false && strpos($on_html, 'Mode: test') !== false, 'settings show Stripe connected in test mode');
        brb_t(strpos($on_html, $secret_marker) === false && strpos($on_html, $whsec_marker) === false, 'settings do not show the keys');
    }
    brb_test_env('BRB_STRIPE_SECRET_KEY', 'sk_live_' . $secret_marker);
    brb_t(brb_stripe_mode() === 'live', 'a live secret key is reported as live mode');
    brb_test_env('BRB_STRIPE_SECRET_KEY', 'rk_' . $secret_marker);
    brb_t(brb_stripe_mode() === 'unknown', 'an unrecognized secret key is not called test or live');
    brb_test_env('BRB_STRIPE_SECRET_KEY', $secret);

    $GLOBALS['brb_mails'] = [];
    $again = brb_set_agreed_price($priced->id, 100);
    $price_mail = null;
    foreach ($GLOBALS['brb_mails'] as $m) {
        if (stripos($m['to'], 'stripe-flow@example.com') !== false) $price_mail = $m;
    }
    brb_t($price_mail && strpos($price_mail['message'], 'Pay online: ') !== false && strpos($price_mail['message'], 'Mail or drop off a check for') !== false && strpos($price_mail['message'], 'processing fee') !== false, 'the price email includes the pay link and the check instructions');
    $_GET = ['br_token' => $priced->token];
    $confirm = do_shortcode('[boardroom_booking]');
    brb_t(strpos($confirm, '>Pay online<') !== false && strpos($confirm, 'br_pay=' . $priced->token) !== false, 'the confirmation page shows Pay online once the price is set');
    $_GET = ['br_token' => $priced->token, 'card' => 'pending'];
    $waiting = do_shortcode('[boardroom_booking]');
    brb_t(strpos($waiting, 'the receipt is sent when the payment is confirmed') !== false && $priced->status === 'approved', 'returning from checkout does not mark the booking paid');
    brb_t(strpos($confirm, $secret_marker) === false, 'the confirmation page does not show key material');

    $base = 35000;
    $fee = 1050;
    $session = [
        'id' => 'cs_test_one',
        'payment_status' => 'paid',
        'amount_total' => $base + $fee,
        'payment_intent' => 'pi_test_one',
        'client_reference_id' => (string) $priced->id,
        'metadata' => [
            'booking_id' => (string) $priced->id,
            'token' => $priced->token,
            'base_cents' => (string) $base,
            'fee_cents' => (string) $fee,
            'damage_cents' => '25000',
        ],
    ];
    $bad_token = $session;
    $bad_token['metadata']['token'] = str_repeat('a', strlen($priced->token));
    $bad_body = wp_json_encode(['id' => 'evt_bad_token', 'type' => 'checkout.session.completed', 'data' => ['object' => $bad_token]]);
    $bad_res = brb_test_post($bad_body, $whsec);
    brb_t($bad_res->get_status() === 400, 'a webhook whose token does not match the booking is rejected');
    brb_t(count(brb_payments($priced->id)) === 0, 'a rejected webhook does not record a payment');

    $unpaid = $session;
    $unpaid['payment_status'] = 'unpaid';
    $unpaid_body = wp_json_encode(['id' => 'evt_unpaid', 'type' => 'checkout.session.completed', 'data' => ['object' => $unpaid]]);
    $unpaid_res = brb_test_post($unpaid_body, $whsec);
    brb_t($unpaid_res->get_status() === 200 && count(brb_payments($priced->id)) === 0, 'an unpaid checkout session does not record a payment');

    $GLOBALS['brb_mails'] = [];
    $body = wp_json_encode(['id' => 'evt_paid', 'type' => 'checkout.session.completed', 'data' => ['object' => $session]]);
    $first = brb_test_post($body, $whsec);
    $paid = brb_booking($priced->id);
    brb_t($first->get_status() === 200 && $paid && $paid->status === 'paid', 'a paid checkout session marks the booking paid in full');
    brb_t($paid && abs((float) $paid->amount_paid - 100) < 0.001 && abs((float) $paid->damage_paid - 250) < 0.001, 'the rental portion hits the balance and the damage deposit is recorded separately');
    $rows = brb_payments($priced->id);
    brb_t(count($rows) === 1 && $rows[0]->method === 'card' && abs((float) $rows[0]->fee - 10.5) < 0.001 && $rows[0]->stripe_id === 'pi_test_one' && $rows[0]->check_number === '', 'the payment stores the card method, fee, and payment intent');
    $guest_receipt = null;
    $admin_receipt = null;
    foreach ($GLOBALS['brb_mails'] as $m) {
        if (stripos($m['to'], 'stripe-flow@example.com') !== false) $guest_receipt = $m;
        if (stripos($m['subject'], 'Payment recorded') !== false) $admin_receipt = $m;
    }
    brb_t($guest_receipt && strpos($guest_receipt['message'], 'We recorded a card payment for $350.00') !== false && strpos($guest_receipt['message'], 'A card processing fee of $10.50') !== false && strpos($guest_receipt['message'], 'Refundable damage deposit') !== false && stripos($guest_receipt['subject'], 'confirmed') !== false, 'the guest receipt names the card amount, the damage deposit, and the fee');
    brb_t((bool) $admin_receipt, 'staff are notified when the card payment is recorded');
    $mail_count = count($GLOBALS['brb_mails']);
    $async = wp_json_encode(['id' => 'evt_async', 'type' => 'checkout.session.async_payment_succeeded', 'data' => ['object' => $session]]);
    $second = brb_test_post($async, $whsec);
    $still = brb_booking($priced->id);
    brb_t($second->get_status() === 200 && $still && abs((float) $still->amount_paid - 100) < 0.001 && count(brb_payments($priced->id)) === 1, 'a duplicate webhook does not record the payment twice');
    brb_t(count($GLOBALS['brb_mails']) === $mail_count, 'a duplicate webhook does not send a second receipt');

    $before_expired = (float) $still->amount_paid;
    $expired = wp_json_encode(['id' => 'evt_expired', 'type' => 'checkout.session.expired', 'data' => ['object' => ['id' => 'cs_test_old']]]);
    $expired_res = brb_test_post($expired, $whsec);
    $after_expired = brb_booking($priced->id);
    brb_t($expired_res->get_status() === 200 && $after_expired && abs((float) $after_expired->amount_paid - $before_expired) < 0.001 && ($after_expired->attention ?? '') === '', 'an expired checkout does not change the booking');

    $mismatch = $session;
    $mismatch['id'] = 'cs_test_mismatch';
    $mismatch['payment_intent'] = 'pi_test_mismatch';
    $mismatch['amount_total'] = $base + $fee + 1;
    $mismatch_body = wp_json_encode(['id' => 'evt_mismatch', 'type' => 'checkout.session.completed', 'data' => ['object' => $mismatch]]);
    $mismatch_res = brb_test_post($mismatch_body, $whsec);
    brb_t($mismatch_res->get_status() === 400 && count(brb_payments($priced->id)) === 1, 'a checkout total that is not base plus fee is rejected');

    $refund = wp_json_encode([
        'id' => 'evt_refund',
        'type' => 'charge.refunded',
        'data' => ['object' => ['id' => 'ch_test_one', 'payment_intent' => 'pi_test_one', 'amount_refunded' => 10300]],
    ]);
    $refund_res = brb_test_post($refund, $whsec);
    $flagged = brb_booking($priced->id);
    brb_t($refund_res->get_status() === 200 && $flagged && abs((float) $flagged->amount_paid - 100) < 0.001 && strpos((string) $flagged->attention, 'refund') !== false, 'a refund flags the booking and does not change the balance');
    $dispute = wp_json_encode([
        'id' => 'evt_dispute',
        'type' => 'charge.dispute.created',
        'data' => ['object' => ['id' => 'dp_test_one', 'payment_intent' => 'pi_test_one', 'charge' => 'ch_test_one']],
    ]);
    $dispute_res = brb_test_post($dispute, $whsec);
    $disputed = brb_booking($priced->id);
    brb_t($dispute_res->get_status() === 200 && $disputed && abs((float) $disputed->amount_paid - 100) < 0.001 && strpos((string) $disputed->attention, 'dispute') !== false, 'a dispute flags the booking and does not refund it');

    if ($admin) {
        ob_start();
        brb_admin_detail((int) $priced->id);
        $card_admin = ob_get_clean();
        brb_t(strpos($card_admin, 'Card (Stripe)') !== false && strpos($card_admin, '$10.50') !== false && strpos($card_admin, 'pi_test_one') !== false, 'the payments list shows the card method, fee, and payment id');
        brb_t(strpos($card_admin, 'dashboard.stripe.com') === false && strpos($card_admin, 'https://stripe.com') === false, 'the payment id is plain text');
        brb_t(strpos($card_admin, 'resolved manually in the Stripe dashboard') !== false && strpos($card_admin, '[VERIFY: attorney review]') !== false, 'the booking screen says card payments are resolved manually and marks the wording for attorney review');
        brb_t(strpos($card_admin, 'value="card"') === false, 'staff cannot record a card payment by hand');
        brb_t(strpos($card_admin, $secret_marker) === false, 'the booking screen does not show key material');
    }

    $half = brb_opt();
    $half['deposit_type'] = 'percent';
    $half['deposit_pct'] = '50';
    update_option('brb_settings', $half);
    $day2 = brb_now()->modify('+36 days')->format('Y-m-d');
    $second_booking = brb_process_public_booking([
        'date' => $day2,
        'type' => 'day',
        'days' => 1,
        'guests' => 8,
        'name' => 'Stripe Flow',
        'email' => 'stripe-flow@example.com',
        'policy' => '1', 'waiver' => '1',
        'event_type' => 'corporate',
        'public_event' => 'yes',
        'free_admission' => '1',
        'alcohol' => 'yes',
        'end_time' => '20:00',
        'host_age' => 'age_25',
        'event_about' => 'A corporate reception.',
    ]);
    brb_t(!is_wp_error($second_booking) && $second_booking && $second_booking->status === 'review', 'a second request can be created for a deposit card payment');
    if (!is_wp_error($second_booking) && $second_booking) {
        $created_ids[] = (int) $second_booking->id;
        $wpdb->update(brb_table(), ['walkthrough_done' => 1], ['id' => (int) $second_booking->id]);
        $second_booking = brb_approve_booking($second_booking->id);
        $second_priced = !is_wp_error($second_booking) ? brb_set_agreed_price($second_booking->id, 200) : $second_booking;
        brb_t(!is_wp_error($second_priced) && abs(brb_amount_due_now($second_priced) - 350) < 0.001, 'with a 50 percent deposit the card amount is the deposit plus the damage deposit');
        $deposit_session = [
            'id' => 'cs_test_deposit',
            'payment_status' => 'paid',
            'amount_total' => 36050,
            'payment_intent' => 'pi_test_deposit',
            'metadata' => [
                'booking_id' => (string) $second_booking->id,
                'token' => $second_booking->token,
                'base_cents' => '35000',
                'fee_cents' => '1050',
                'damage_cents' => '25000',
            ],
        ];
        $deposit_body = wp_json_encode(['id' => 'evt_deposit', 'type' => 'checkout.session.completed', 'data' => ['object' => $deposit_session]]);
        $deposit_res = brb_test_post($deposit_body, $whsec);
        $deposit_paid = brb_booking($second_booking->id);
        brb_t($deposit_res->get_status() === 200 && $deposit_paid && $deposit_paid->status === 'deposit_paid' && abs((float) $deposit_paid->amount_paid - 100) < 0.001 && abs((float) $deposit_paid->damage_paid - 250) < 0.001, 'a card deposit records the rental deposit and the damage deposit, and leaves the fee off the balance');
        brb_t(abs(brb_amount_due_now($deposit_paid) - 100) < 0.001, 'after the deposit the next card amount is the remaining balance');
        $card_balance = brb_checkout_lines($deposit_paid);
        brb_t((int) $card_balance['damage_cents'] === 0 && (int) $card_balance['base_cents'] === 10000, 'a card deposit already collected the damage deposit, so the balance checkout does not charge it again');
        $locked_line = brb_find_line_kind($second_booking->id, 'rental');
        brb_t($locked_line && !empty($locked_line->locked), 'paid rental lines lock');
        $edit_locked = brb_save_custom_line($second_booking->id, 'Rental', 10, (int) $locked_line->id);
        brb_t(is_wp_error($edit_locked), 'a locked line cannot be edited');
        $balance_session = [
            'id' => 'cs_test_balance',
            'payment_status' => 'paid',
            'amount_total' => 10300,
            'payment_intent' => 'pi_test_balance',
            'metadata' => [
                'booking_id' => (string) $second_booking->id,
                'token' => $second_booking->token,
                'base_cents' => '10000',
                'fee_cents' => '300',
            ],
        ];
        $balance_body = wp_json_encode(['id' => 'evt_balance', 'type' => 'checkout.session.async_payment_succeeded', 'data' => ['object' => $balance_session]]);
        $balance_res = brb_test_post($balance_body, $whsec);
        $balance_paid = brb_booking($second_booking->id);
        brb_t($balance_res->get_status() === 200 && $balance_paid && $balance_paid->status === 'paid' && abs((float) $balance_paid->amount_paid - 200) < 0.001, 'the balance card payment marks the booking paid without adding the fees');
        $_GET = ['br_token' => $second_booking->token, 'card' => 'pending'];
        $pending = do_shortcode('[boardroom_booking]');
        brb_t(strpos($pending, 'paid in full') !== false && strpos($pending, 'the receipt is sent when the payment is confirmed') === false, 'a paid booking confirmation does not wait on the return URL');
        $open_credit = brb_save_custom_line($second_booking->id, 'Courtesy credit', -20);
        brb_t(!is_wp_error($open_credit), 'a credit can be added after a payment');
    }

    $discount_day = brb_now()->modify('+37 days')->format('Y-m-d');
    $discount_booking = brb_process_public_booking([
        'date' => $discount_day,
        'type' => 'day',
        'days' => 1,
        'guests' => 6,
        'name' => 'Stripe Flow',
        'email' => 'stripe-flow@example.com',
        'policy' => '1', 'waiver' => '1',
        'event_type' => 'corporate',
        'public_event' => 'no',
        'free_admission' => '1',
        'alcohol' => 'no',
        'end_time' => '21:00',
        'host_age' => 'age_25',
        'event_about' => 'A small meeting.',
    ]);
    if (!is_wp_error($discount_booking) && $discount_booking) {
        $created_ids[] = (int) $discount_booking->id;
        $wpdb->update(brb_table(), ['walkthrough_done' => 1], ['id' => (int) $discount_booking->id]);
        brb_approve_booking($discount_booking->id);
        $discount_priced = brb_set_agreed_price($discount_booking->id, 200);
        $discounted = brb_save_custom_line($discount_booking->id, 'Weekday credit', -20);
        brb_t(!is_wp_error($discounted) && abs((float) $discounted->total - 180) < 0.01 && abs((float) $discounted->deposit_due - 90) < 0.01, 'a discount lowers the rental total and the 50 percent deposit');
        brb_t(abs(brb_amount_due_now($discounted) - 340) < 0.001, 'the card amount is the net deposit plus the damage deposit');
        $discount_captured = null;
        add_filter('pre_http_request', function ($pre, $args) use (&$discount_captured) {
            $discount_captured = $args;
            return [
                'headers' => [],
                'body' => wp_json_encode(['id' => 'cs_test_discount', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_discount']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies' => [],
                'filename' => null,
            ];
        }, 10, 2);
        brb_stripe_create_session(brb_booking($discount_booking->id));
        $discount_parsed = [];
        parse_str((string) ($discount_captured['body'] ?? ''), $discount_parsed);
        $saw_negative = false;
        foreach (($discount_parsed['line_items'] ?? []) as $item) {
            if ((int) ($item['price_data']['unit_amount'] ?? 0) < 0) $saw_negative = true;
        }
        brb_t(($discount_parsed['line_items'][0]['price_data']['unit_amount'] ?? '') === '9000' && ($discount_parsed['metadata']['damage_cents'] ?? '') === '25000' && !$saw_negative, 'a discounted deposit is netted into Checkout and the damage deposit is still charged');
        remove_all_filters('pre_http_request');
    }

    brb_test_env('BRB_STRIPE_SECRET_KEY', null);
    $disabled_again = brb_stripe_create_session($priced);
    brb_t(is_wp_error($disabled_again) && $disabled_again->get_error_code() === 'stripe' && brb_pay_link(brb_booking($priced->id)) === '', 'clearing the secret key disables online payment again');
} catch (Throwable $e) {
    brb_t(false, 'stripe tests stopped: ' . brb_stripe_redact($e->getMessage()));
} finally {
    brb_test_clear_stripe_env();
    remove_all_filters('pre_http_request');
    $_GET = [];
    unset($_POST['brb_save']);
    if ($created_ids) {
        $in = implode(',', array_map('intval', $created_ids));
        $wpdb->query("DELETE FROM " . brb_pay_table() . " WHERE booking_id IN ($in)");
        $wpdb->query("DELETE FROM " . brb_table() . " WHERE id IN ($in)");
    }
    $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_pay_table() . ' WHERE booking_id IN (SELECT id FROM ' . brb_table() . ' WHERE email=%s)', 'stripe-flow@example.com'));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_table() . ' WHERE email=%s', 'stripe-flow@example.com'));
    if ($snapshot !== false && $snapshot !== null) update_option('brb_settings', $snapshot);
    else delete_option('brb_settings');
}

echo "\n" . $GLOBALS['brb_ok'] . " passed, " . $GLOBALS['brb_fail'] . " failed\n";
exit($GLOBALS['brb_fail'] > 0 ? 1 : 0);
