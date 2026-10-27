<?php
if (!defined('ABSPATH')) exit;

function brb_stripe_secret() {
    return brb_env('BRB_STRIPE_SECRET_KEY');
}
function brb_stripe_webhook_secret() {
    return brb_env('BRB_STRIPE_WEBHOOK_SECRET');
}
function brb_stripe_enabled() {
    return brb_stripe_secret() !== '';
}
function brb_stripe_mode() {
    $key = brb_stripe_secret();
    if ($key === '') return '';
    if (strpos($key, 'sk_test_') === 0) return 'test';
    if (strpos($key, 'sk_live_') === 0) return 'live';
    return 'unknown';
}
function brb_stripe_redact($text) {
    $text = (string) $text;
    foreach (['BRB_STRIPE_SECRET_KEY', 'BRB_STRIPE_WEBHOOK_SECRET', 'BRB_STRIPE_PUBLISHABLE_KEY'] as $name) {
        $value = brb_env($name);
        if ($value !== '') $text = str_replace($value, '[redacted]', $text);
    }
    return $text;
}
function brb_card_fee_percent() {
    $n = (float) brb_opt('card_fee_percent');
    if ($n < 0) $n = 0;
    if ($n > 100) $n = 100;
    return $n;
}
function brb_fee_percent_label($percent = null) {
    if ($percent === null) $percent = brb_card_fee_percent();
    $s = rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.');
    if ($s === '') $s = '0';
    return $s . '%';
}
function brb_cents_from_amount($amount) {
    return (int) round(((float) $amount) * 100);
}
function brb_card_fee_cents($base_cents, $percent = null) {
    $base_cents = (int) $base_cents;
    if ($base_cents < 0) $base_cents = 0;
    if ($percent === null) $percent = brb_card_fee_percent();
    return (int) round($base_cents * ((float) $percent) / 100);
}
function brb_amount_due_now($b) {
    if (!$b || !brb_price_is_set($b)) return 0.0;
    if (!in_array($b->status, ['held', 'deposit_paid'], true)) return 0.0;
    $due = round((float) brb_amount_to_bring($b), 2);
    return $due > 0 ? $due : 0.0;
}
function brb_pay_signature($token) {
    return hash_hmac('sha256', 'brb-pay|' . (string) $token, wp_salt('auth'));
}
function brb_pay_token_valid($token, $sig) {
    $token = (string) $token;
    $sig = strtolower((string) $sig);
    if ($token === '' || $sig === '' || !preg_match('/^[A-Za-z0-9]+$/', $token)) return false;
    $expected = brb_pay_signature($token);
    if (strlen($sig) !== strlen($expected)) return false;
    return hash_equals($expected, $sig);
}
function brb_pay_link($b) {
    if (!$b || empty($b->token) || !brb_stripe_enabled()) return '';
    if (brb_amount_due_now($b) <= 0) return '';
    return add_query_arg([
        'br_pay' => $b->token,
        'br_sig' => brb_pay_signature($b->token),
    ], home_url('/book/'));
}
function brb_pay_online_line($b) {
    $url = brb_pay_link($b);
    if ($url === '') return '';
    $due = brb_amount_due_now($b);
    $fee = brb_card_fee_cents(brb_cents_from_amount($due)) / 100;
    return 'Pay online: ' . $url . ' The card charge is ' . brb_money($due) . ' plus a ' . brb_fee_percent_label() . ' processing fee of ' . brb_money($fee) . '. That fee is not part of the booking balance. You can still pay by check or in person. Cancellation and refunds follow the Rental Agreement. The card processing fee is non-refundable [VERIFY with attorney].';
}

function brb_stripe_create_session($b) {
    if (!brb_stripe_enabled()) return new WP_Error('stripe', 'Online card payment is not available.');
    $due = brb_amount_due_now($b);
    if ($due <= 0) return new WP_Error('amount', 'There is no balance to pay online.');
    $base_cents = brb_cents_from_amount($due);
    $percent = brb_card_fee_percent();
    $fee_cents = brb_card_fee_cents($base_cents, $percent);
    $success = add_query_arg(['br_token' => $b->token, 'card' => 'pending'], home_url('/book/'));
    $success .= '&session_id={CHECKOUT_SESSION_ID}';
    $body = [
        'mode' => 'payment',
        'success_url' => $success,
        'cancel_url' => brb_pay_link($b),
        'client_reference_id' => (string) (int) $b->id,
        'customer_email' => $b->email,
        'metadata[booking_id]' => (string) (int) $b->id,
        'metadata[token]' => (string) $b->token,
        'metadata[base_cents]' => (string) $base_cents,
        'metadata[fee_cents]' => (string) $fee_cents,
        'line_items[0][quantity]' => '1',
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][unit_amount]' => (string) $base_cents,
        'line_items[0][price_data][product_data][name]' => 'Boardroom booking ' . brb_ref($b),
        'line_items[1][quantity]' => '1',
        'line_items[1][price_data][currency]' => 'usd',
        'line_items[1][price_data][unit_amount]' => (string) $fee_cents,
        'line_items[1][price_data][product_data][name]' => 'Card processing fee (' . brb_fee_percent_label($percent) . ')',
    ];
    if ($fee_cents <= 0) {
        unset($body['line_items[1][quantity]'], $body['line_items[1][price_data][currency]'], $body['line_items[1][price_data][unit_amount]'], $body['line_items[1][price_data][product_data][name]']);
    }
    $encoded = http_build_query($body, '', '&');
    $encoded = str_replace(['%7BCHECKOUT_SESSION_ID%7D', '%7bCHECKOUT_SESSION_ID%7d'], '{CHECKOUT_SESSION_ID}', $encoded);
    $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', [
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Bearer ' . brb_stripe_secret(),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'body' => $encoded,
    ]);
    if (is_wp_error($response)) return new WP_Error('stripe', 'Online payment could not be started. Please try again or pay by check.');
    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    $url = is_array($data) ? (string) ($data['url'] ?? '') : '';
    $host = $url !== '' ? (string) wp_parse_url($url, PHP_URL_HOST) : '';
    if ($code < 200 || $code >= 300 || $host === '' || !preg_match('/(^|\.)stripe\.com$/i', $host)) {
        return new WP_Error('stripe', 'Online payment could not be started. Please try again or pay by check.');
    }
    return $url;
}
function brb_stripe_begin_checkout($token, $sig, $agreed) {
    if (!brb_pay_token_valid($token, $sig)) return new WP_Error('token', 'This payment link is not valid.');
    if (!$agreed) return new WP_Error('agree', 'Please confirm the card processing fee.');
    $b = brb_booking_by_token($token);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    return brb_stripe_create_session($b);
}
function brb_handle_stripe_checkout() {
    $token = sanitize_text_field(wp_unslash($_POST['br_token'] ?? ''));
    $sig = sanitize_text_field(wp_unslash($_POST['br_sig'] ?? ''));
    $agreed = !empty($_POST['card_fee_agree']);
    $result = brb_stripe_begin_checkout($token, $sig, $agreed);
    $back = add_query_arg(['br_pay' => $token, 'br_sig' => $sig], home_url('/book/'));
    if (is_wp_error($result)) {
        wp_safe_redirect(add_query_arg('br_err', rawurlencode($result->get_error_message()), $back));
        exit;
    }
    wp_redirect($result);
    exit;
}
add_action('admin_post_nopriv_brb_stripe_checkout', 'brb_handle_stripe_checkout');
add_action('admin_post_brb_stripe_checkout', 'brb_handle_stripe_checkout');

function brb_view_pay($token, $sig) {
    if (!brb_pay_token_valid($token, $sig)) {
        echo '<div class="brb-alert brb-err">This payment link is not valid.</div>';
        return;
    }
    $b = brb_booking_by_token($token);
    if (!$b) {
        echo '<div class="brb-alert brb-err">Booking not found.</div>';
        return;
    }
    if (!brb_stripe_enabled()) {
        echo '<div class="brb-alert brb-err">Online card payment is not available. Please pay by check or in person.</div>';
        echo '<p><a href="' . esc_url(brb_guest_link($b)) . '">Back to your booking</a></p>';
        return;
    }
    $due = brb_amount_due_now($b);
    if ($due <= 0) {
        echo '<div class="brb-alert">There is no balance to pay online.</div>';
        echo '<p><a href="' . esc_url(brb_guest_link($b)) . '">Back to your booking</a></p>';
        return;
    }
    $base_cents = brb_cents_from_amount($due);
    $fee_cents = brb_card_fee_cents($base_cents);
    $total_cents = $base_cents + $fee_cents;
    $label = brb_fee_percent_label();
    ?>
    <div class="brb-card">
      <h2>Pay online</h2>
      <p>Reference <strong><?php echo esc_html(brb_ref($b)); ?></strong></p>
      <p><?php echo esc_html(brb_when($b)); ?></p>
      <div class="brb-paybox">
        <p>Amount applied to your booking: <?php echo esc_html(brb_money($base_cents / 100)); ?></p>
        <p>Card processing fee (<?php echo esc_html($label); ?>): <?php echo esc_html(brb_money($fee_cents / 100)); ?></p>
        <p><strong>Total charged to your card: <?php echo esc_html(brb_money($total_cents / 100)); ?></strong></p>
        <p>The processing fee is not part of the booking balance. You can still pay by check or in person. Cancellation and refunds follow the Rental Agreement. The card processing fee is non-refundable [VERIFY with attorney].</p>
      </div>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="brb_stripe_checkout">
        <input type="hidden" name="br_token" value="<?php echo esc_attr($token); ?>">
        <input type="hidden" name="br_sig" value="<?php echo esc_attr($sig); ?>">
        <label class="brb-check"><input type="checkbox" name="card_fee_agree" value="1" required> I understand a <?php echo esc_html($label); ?> card processing fee applies and I agree to the <a href="<?php echo esc_url(home_url('/terms/')); ?>" target="_blank" rel="noopener">Terms</a> and <a href="<?php echo esc_url(home_url('/rental-agreement/')); ?>" target="_blank" rel="noopener">Rental Agreement</a>.</label>
        <p><button class="brb-btn" type="submit">Continue to card payment</button></p>
      </form>
      <p><a href="<?php echo esc_url(brb_guest_link($b)); ?>">Back to your booking</a></p>
    </div>
    <?php
}

function brb_stripe_verify_signature($payload, $header, $secret, $now = null) {
    if (!is_string($payload) || !is_string($header) || $header === '' || !is_string($secret) || $secret === '') return false;
    $now = $now === null ? time() : (int) $now;
    $timestamp = '';
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        $part = trim($part);
        if (strpos($part, '=') === false) continue;
        list($key, $value) = explode('=', $part, 2);
        if ($key === 't') $timestamp = $value;
        elseif ($key === 'v1') $sigs[] = $value;
    }
    if ($timestamp === '' || !ctype_digit($timestamp) || !$sigs) return false;
    if (abs($now - (int) $timestamp) > 300) return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($sigs as $sig) {
        if (!is_string($sig) || strlen($sig) !== strlen($expected)) continue;
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}
function brb_stripe_id_value($value) {
    if (is_string($value)) return $value;
    if (is_array($value) && isset($value['id']) && is_string($value['id'])) return $value['id'];
    return '';
}
function brb_find_stripe_payment($ids) {
    global $wpdb;
    $ids = array_values(array_unique(array_filter(array_map('strval', (array) $ids), function ($id) {
        return $id !== '';
    })));
    if (!$ids) return null;
    $one = implode(',', array_fill(0, count($ids), '%s'));
    $sql = 'SELECT * FROM ' . brb_pay_table() . " WHERE stripe_id IN ($one) OR stripe_session IN ($one) LIMIT 1";
    return $wpdb->get_row($wpdb->prepare($sql, array_merge($ids, $ids)));
}
function brb_flag_booking($booking_id, $message) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return false;
    $message = sanitize_text_field(brb_stripe_redact($message));
    if ($message === '') return false;
    $wpdb->update(brb_table(), ['attention' => $message], ['id' => (int) $b->id]);
    return true;
}
function brb_stripe_apply_session($session) {
    if (!is_array($session)) return new WP_Error('payload', 'Invalid payment session.', ['status' => 400]);
    if ((string) ($session['payment_status'] ?? '') !== 'paid') return true;
    $meta = isset($session['metadata']) && is_array($session['metadata']) ? $session['metadata'] : [];
    $booking_id = (int) ($meta['booking_id'] ?? $session['client_reference_id'] ?? 0);
    $base_cents = (int) ($meta['base_cents'] ?? 0);
    $fee_cents = (int) ($meta['fee_cents'] ?? 0);
    $amount_total = (int) ($session['amount_total'] ?? 0);
    if ($booking_id <= 0 || $base_cents <= 0 || $amount_total !== $base_cents + $fee_cents) {
        return new WP_Error('amount', 'Payment total does not match the booking charge.', ['status' => 400]);
    }
    $pi = brb_stripe_id_value($session['payment_intent'] ?? '');
    $sid = brb_stripe_id_value($session['id'] ?? '');
    global $wpdb;
    $lock = 'brb_card_' . substr(md5($sid . '|' . $pi), 0, 16);
    $locked = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
    if ((string) $locked !== '1') return new WP_Error('lock', 'Please try the payment notice again.', ['status' => 400]);
    try {
        if (brb_find_stripe_payment([$pi, $sid])) return true;
        $b = brb_booking($booking_id);
        if (!$b) return new WP_Error('missing', 'Booking not found.', ['status' => 400]);
        $token = (string) ($meta['token'] ?? '');
        $expected_token = (string) $b->token;
        if ($token === '' || strlen($token) !== strlen($expected_token) || !hash_equals($expected_token, $token)) {
            return new WP_Error('token', 'Booking token does not match.', ['status' => 400]);
        }
        $res = brb_record_payment($b->id, $base_cents / 100, '', brb_now()->format('Y-m-d'), 'Card payment', false, 'card', [
            'fee' => $fee_cents / 100,
            'stripe_id' => $pi !== '' ? $pi : $sid,
            'stripe_session' => $sid,
        ]);
        if (is_wp_error($res)) {
            brb_flag_booking($b->id, 'A card payment could not be applied: ' . $res->get_error_message());
            return true;
        }
        return true;
    } finally {
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}
function brb_stripe_flag_from_charge($object, $message) {
    $pi = brb_stripe_id_value($object['payment_intent'] ?? '');
    if ($pi === '') $pi = brb_stripe_id_value($object['charge'] ?? '');
    $row = brb_find_stripe_payment([$pi]);
    if (!$row) return true;
    brb_flag_booking((int) $row->booking_id, $message);
    return true;
}
function brb_stripe_handle_event($event) {
    if (!is_array($event)) return new WP_Error('payload', 'Invalid event.', ['status' => 400]);
    $type = (string) ($event['type'] ?? '');
    $object = $event['data']['object'] ?? null;
    if ($type === 'checkout.session.expired') return true;
    if (!is_array($object)) return new WP_Error('payload', 'Invalid event.', ['status' => 400]);
    if ($type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded') {
        return brb_stripe_apply_session($object);
    }
    if ($type === 'charge.refunded') {
        return brb_stripe_flag_from_charge($object, 'Stripe reported a refund. Refunds follow the rental agreement and are issued manually in the Stripe dashboard.');
    }
    if (strpos($type, 'charge.dispute.') === 0) {
        return brb_stripe_flag_from_charge($object, 'Stripe reported a dispute. Review it in the Stripe dashboard. No automatic refund was made.');
    }
    return true;
}
function brb_rest_stripe_webhook(WP_REST_Request $request) {
    $payload = $request->get_body();
    $header = (string) $request->get_header('stripe-signature');
    $secret = brb_stripe_webhook_secret();
    if ($header === '' || $secret === '' || !brb_stripe_verify_signature($payload, $header, $secret)) {
        return new WP_REST_Response(['error' => 'Invalid signature'], 400);
    }
    $event = json_decode($payload, true);
    if (!is_array($event)) return new WP_REST_Response(['error' => 'Invalid payload'], 400);
    $result = brb_stripe_handle_event($event);
    if (is_wp_error($result)) {
        $status = 400;
        $data = $result->get_error_data();
        if (is_array($data) && !empty($data['status'])) $status = (int) $data['status'];
        return new WP_REST_Response(['error' => brb_stripe_redact($result->get_error_message())], $status);
    }
    return new WP_REST_Response(['received' => true], 200);
}
add_action('rest_api_init', function () {
    register_rest_route('brb/v1', '/stripe-webhook', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => 'brb_rest_stripe_webhook',
    ]);
});
