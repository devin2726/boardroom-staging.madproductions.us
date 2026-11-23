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
function brb_amount_due_now($b, $choice = '') {
    if (!$b || !brb_price_is_set($b)) return 0.0;
    if (!in_array($b->status, ['approved', 'held', 'deposit_paid'], true)) return 0.0;
    if ($choice === 'full') {
        // 2.5.84 guest Pay in full: everything still owed on the rental plus any unpaid damage deposit.
        $all = round(brb_rental_left($b) + brb_damage_left($b), 2);
        return $all > 0.009 ? $all : 0.0;
    }
    $money = brb_booking_money($b);
    if ((float) $money['deposit_left'] > 0.009) {
        // Reserve stage: the admin Money box "Due now to reserve" figure.
        $due = (float) $money['due_now'];
    } else {
        $due = round((float) brb_amount_to_bring($b) + brb_damage_left($b), 2);
    }
    return $due > 0.009 ? $due : 0.0;
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
    if (empty($b->walkthrough_done)) return '';
    if (brb_amount_due_now($b) <= 0) return '';
    return add_query_arg([
        'br_pay' => $b->token,
        'br_sig' => brb_pay_signature($b->token),
    ], brb_book_url());
}
/**
 * 2.5.84: the guest may choose Pay in full on the pay page while the booking is still at
 * the deposit stage and paying in full would charge more than the deposit.
 */
function brb_guest_pay_full_available($b) {
    if (!$b || !brb_price_is_set($b)) return false;
    if (!in_array((string) $b->status, ['approved', 'held'], true)) return false;
    $deposit_left = max(0, round((float) $b->deposit_due - (float) $b->amount_paid, 2));
    if ($deposit_left <= 0.009) return false;
    return brb_amount_due_now($b, 'full') - brb_amount_due_now($b) > 0.009;
}
function brb_normalize_pay_choice($b, $choice) {
    $choice = sanitize_key((string) $choice);
    return ($choice === 'full' && brb_guest_pay_full_available($b)) ? 'full' : '';
}
/** Base, fee, and total cents for one choice, the same math as Stripe Checkout. */
function brb_pay_choice_totals($b, $choice = '') {
    $charge = brb_checkout_lines($b, $choice);
    $base = (int) $charge['base_cents'];
    $fee = brb_card_fee_cents($base);
    return ['charge' => $charge, 'base_cents' => $base, 'fee_cents' => $fee, 'total_cents' => $base + $fee];
}
function brb_pay_link_checklist() {
    return [
        'stripe' => 'Stripe connected',
        'walkthrough' => 'Walk-through completed',
        'price' => 'Agreed price set',
        'approved' => 'Request approved',
        'balance' => 'Balance due greater than $0',
    ];
}
function brb_pay_link_blockers($b) {
    $all = brb_pay_link_checklist();
    if (!$b) return $all;
    $missing = [];
    if (!brb_stripe_enabled()) $missing['stripe'] = $all['stripe'];
    if (empty($b->walkthrough_done)) $missing['walkthrough'] = $all['walkthrough'];
    $priced = brb_price_is_set($b);
    if (!$priced) $missing['price'] = $all['price'];
    $status = (string) ($b->status ?? '');
    if (in_array($status, ['review', 'pending'], true)) $missing['approved'] = $all['approved'];
    $balance_due = $priced && in_array($status, ['approved', 'held', 'deposit_paid'], true) && brb_amount_due_now($b) <= 0;
    if ($status === 'paid' || $balance_due) $missing['balance'] = $all['balance'];
    return $missing;
}
function brb_pay_online_line($b) {
    $url = brb_pay_link($b);
    if ($url === '') return '';
    $due = brb_amount_due_now($b);
    $fee = brb_card_fee_cents(brb_cents_from_amount($due)) / 100;
    $line = 'Pay online: ' . $url . ' The card charge is ' . brb_money($due) . ' plus a ' . brb_fee_percent_label() . ' processing fee of ' . brb_money($fee) . '.';
    if (brb_guest_pay_full_available($b)) {
        $full = brb_pay_choice_totals($b, 'full');
        $line .= ' You can also choose to pay in full on that page: ' . brb_money($full['base_cents'] / 100) . ' plus ' . brb_money($full['fee_cents'] / 100) . ', ' . brb_money($full['total_cents'] / 100) . ' in all.';
    }
    return $line . ' That fee is not part of the booking balance. You can still pay by check or in person. ' . brb_booking_deposit_line($b) . ' ' . brb_fee_public_line();
}

function brb_stripe_unavailable_guest_message() {
    return 'That date is no longer available. Please contact the venue.';
}
function brb_stripe_review_mark() {
    return 'needs staff review - payment received for unavailable date';
}
function brb_stripe_review_steps() {
    return 'Do not refund this from the website, and do not email the guest automatically. Open the payment in the Stripe dashboard and refund it there if this guest cannot have the date. After the refund, clear the booking flag.';
}
/** 2.5.84: a second Checkout payment that arrived after the booking was already paid. */
function brb_stripe_overpay_mark() {
    return 'Extra payment on an already-paid booking: review needed, not applied';
}
function brb_stripe_overpay_steps() {
    return 'Do not refund this from the website, and do not email the guest automatically. Open the payment in the Stripe dashboard and refund it there if this charge was not intended. After the refund, clear the booking flag.';
}
function brb_stripe_overpay_subject($ref) {
    return 'Extra payment on an already-paid booking: review needed, not applied (' . $ref . ')';
}
function brb_stripe_review_kinds() {
    return [brb_stripe_review_mark(), brb_stripe_overpay_mark()];
}
function brb_stripe_review_kind_steps($mark) {
    return ((string) $mark === brb_stripe_overpay_mark()) ? brb_stripe_overpay_steps() : brb_stripe_review_steps();
}
function brb_stripe_dashboard_url($stripe_id, $session_id = '') {
    $stripe_id = (string) $stripe_id;
    $session_id = (string) $session_id;
    $test = strpos($stripe_id, '_test_') !== false || strpos($session_id, '_test_') !== false || brb_stripe_mode() === 'test';
    $base = 'https://dashboard.stripe.com/' . ($test ? 'test/' : '');
    if ($stripe_id !== '' && strpos($stripe_id, 'pi_') === 0) return $base . 'payments/' . rawurlencode($stripe_id);
    if ($session_id !== '' && strpos($session_id, 'cs_') === 0) return $base . 'checkout/sessions/' . rawurlencode($session_id);
    if ($stripe_id !== '') return $base . 'payments/' . rawurlencode($stripe_id);
    return $base . 'payments';
}
function brb_stripe_append_audit($b, $line) {
    if (!$b || $line === '') return;
    $fresh = brb_booking($b->id);
    if (!$fresh) return;
    if (strpos((string) ($fresh->audit_log ?? ''), $line) !== false) return;
    brb_audit_append((int) $b->id, $line, 'system');
}
function brb_stripe_note_checkout_blocked($b) {
    global $wpdb;
    if (!$b) return;
    $note = 'Card checkout was blocked because the date is no longer available. The guest was told to contact the venue. No Stripe session was created.';
    $attention = (string) ($b->attention ?? '');
    if ($attention !== brb_stripe_review_mark() && strpos($attention, 'Card checkout was blocked') === false) {
        $wpdb->update(brb_table(), ['attention' => $note], ['id' => (int) $b->id]);
    }
    brb_stripe_append_audit($b, $note);
}
function brb_stripe_review_rows($booking_id = null) {
    global $wpdb;
    if ($booking_id === null) {
        return $wpdb->get_results("SELECT * FROM " . brb_pay_table() . " WHERE method='card_review' ORDER BY id DESC");
    }
    return $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . brb_pay_table() . " WHERE method='card_review' AND booking_id=%d ORDER BY id DESC",
        (int) $booking_id
    ));
}
function brb_stripe_log_review($ref, $sid, $pi, $reason, $mark = '') {
    if ($mark === '') $mark = brb_stripe_review_mark();
    error_log('Boardroom ' . $mark . ': ' . $ref . ' session ' . $sid . ' payment ' . $pi . '. ' . brb_stripe_redact($reason));
}
function brb_stripe_save_unapplied($booking_id, $session, $reason, $kind = '') {
    global $wpdb;
    if (!is_array($session)) return new WP_Error('payload', 'Invalid payment session.', ['status' => 400]);
    $meta = isset($session['metadata']) && is_array($session['metadata']) ? $session['metadata'] : [];
    $base_cents = (int) ($meta['base_cents'] ?? 0);
    $fee_cents = (int) ($meta['fee_cents'] ?? 0);
    $pi = brb_stripe_id_value($session['payment_intent'] ?? '');
    $sid = brb_stripe_id_value($session['id'] ?? '');
    if (brb_find_stripe_payment([$pi, $sid])) return true;
    $mark = ($kind === 'overpay') ? brb_stripe_overpay_mark() : brb_stripe_review_mark();
    $notes = $mark . ' ' . sanitize_text_field($reason);
    $ok = $wpdb->insert(brb_pay_table(), [
        'booking_id' => max(0, (int) $booking_id),
        'amount' => brb_dec(max(0, $base_cents) / 100),
        'check_number' => '',
        'received_on' => brb_now()->format('Y-m-d'),
        'notes' => $notes,
        'method' => 'card_review',
        'fee' => brb_dec(max(0, $fee_cents) / 100),
        'stripe_id' => $pi !== '' ? $pi : $sid,
        'stripe_session' => $sid,
        'created' => current_time('mysql', true),
    ]);
    if (!$ok) return new WP_Error('db', 'Could not save the card payment for staff review.', ['status' => 500]);
    $b = $booking_id > 0 ? brb_booking($booking_id) : null;
    $ref = $b ? brb_ref($b) : 'no booking on file';
    if ($b) {
        $wpdb->update(brb_table(), ['attention' => $mark], ['id' => (int) $b->id]);
        brb_stripe_append_audit($b, $mark . '. Session ' . $sid . '. ' . $reason);
    }
    $url = brb_stripe_dashboard_url($pi !== '' ? $pi : $sid, $sid);
    $body = "A card payment was received and was not applied.\n\n"
        . $mark . "\n"
        . 'Booking: ' . $ref . "\n"
        . 'Reason: ' . $reason . "\n"
        . 'Stripe payment: ' . $url . "\n"
        . 'Session: ' . $sid . "\n"
        . 'Payment: ' . ($pi !== '' ? $pi : $sid) . "\n\n"
        . brb_stripe_review_kind_steps($mark) . "\n";
    if ($b) $body .= "\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n";
    $subject = ($kind === 'overpay')
        ? brb_stripe_overpay_subject($ref)
        : 'Needs staff review - payment received for unavailable date (' . $ref . ')';
    brb_mail_staff($subject, $body);
    brb_stripe_log_review($ref, $sid, $pi !== '' ? $pi : $sid, $reason, $mark);
    return true;
}
function brb_stripe_review_notice() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $marks = brb_stripe_review_kinds();
    $bookings = $wpdb->get_results($wpdb->prepare(
        'SELECT id, attention FROM ' . brb_table() . ' WHERE attention IN (%s, %s) ORDER BY id DESC LIMIT 20',
        $marks[0],
        $marks[1]
    ));
    $orphans = brb_stripe_review_rows(0);
    if (!$bookings && !$orphans) return;
    $list = admin_url('admin.php?page=brb&status=card_review');
    echo '<div class="notice notice-error">';
    $seen = [];
    foreach ((array) $bookings as $row) {
        $mark = (string) ($row->attention ?? '');
        if ($mark === '' || isset($seen[$mark])) continue;
        $seen[$mark] = true;
        echo '<p><strong>Boardroom Booking:</strong> ' . esc_html($mark) . '.</p>';
        echo '<p>' . esc_html(brb_stripe_review_kind_steps($mark)) . '</p>';
    }
    if (!$seen && $orphans) {
        echo '<p><strong>Boardroom Booking:</strong> ' . esc_html(brb_stripe_review_mark()) . '.</p>';
        echo '<p>' . esc_html(brb_stripe_review_steps()) . '</p>';
    }
    echo '<ul>';
    foreach ((array) $bookings as $row) {
        $b = brb_booking($row->id);
        if (!$b) continue;
        $pay = brb_stripe_review_rows((int) $b->id);
        $pay = $pay ? $pay[0] : null;
        $url = $pay ? brb_stripe_dashboard_url($pay->stripe_id, $pay->stripe_session) : '';
        echo '<li><a href="' . esc_url(admin_url('admin.php?page=brb&booking=' . (int) $b->id)) . '">' . esc_html(brb_ref($b)) . '</a>';
        if ($url !== '') echo ' — <a href="' . esc_url($url) . '" target="_blank" rel="noopener">Open this payment in the Stripe dashboard</a>';
        echo '</li>';
    }
    foreach ((array) $orphans as $pay) {
        $url = brb_stripe_dashboard_url($pay->stripe_id, $pay->stripe_session);
        echo '<li>No booking on file';
        if ($url !== '') echo ' — <a href="' . esc_url($url) . '" target="_blank" rel="noopener">Open this payment in the Stripe dashboard</a>';
        echo '</li>';
    }
    echo '</ul><p><a href="' . esc_url($list) . '">Open the needs-staff-review list</a></p></div>';
}
add_action('admin_notices', 'brb_stripe_review_notice');

function brb_stripe_create_session($b, $choice = '') {
    if (!brb_stripe_enabled()) return new WP_Error('stripe', 'Online card payment is not available.');
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (brb_date_taken($b)) {
        brb_stripe_note_checkout_blocked($b);
        return new WP_Error('busy', brb_stripe_unavailable_guest_message());
    }
    // 2.5.84: never open a session for a paid booking or a $0 balance (double-charge guard).
    if ((string) $b->status === 'paid') return new WP_Error('amount', 'This booking is paid in full. There is no balance to pay online.');
    $choice = brb_normalize_pay_choice($b, $choice);
    $due = brb_amount_due_now($b, $choice);
    if ($due <= 0) return new WP_Error('amount', 'There is no balance to pay online.');
    $charge = brb_checkout_lines($b, $choice);
    $base_cents = (int) $charge['base_cents'];
    if ($base_cents <= 0) return new WP_Error('amount', 'There is no balance to pay online.');
    $percent = brb_card_fee_percent();
    $fee_cents = brb_card_fee_cents($base_cents, $percent);
    $success = add_query_arg(['br_token' => $b->token, 'card' => 'pending'], brb_book_url());
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
        'metadata[damage_cents]' => (string) (int) $charge['damage_cents'],
        'metadata[pay_choice]' => $choice === 'full' ? 'full' : 'due_now',
    ];
    $i = 0;
    foreach ($charge['lines'] as $line) {
        if ((int) $line['cents'] <= 0) continue;
        $body['line_items[' . $i . '][quantity]'] = '1';
        $body['line_items[' . $i . '][price_data][currency]'] = 'usd';
        $body['line_items[' . $i . '][price_data][unit_amount]'] = (string) (int) $line['cents'];
        $body['line_items[' . $i . '][price_data][product_data][name]'] = (string) $line['name'];
        $i++;
    }
    if ($fee_cents > 0) {
        $body['line_items[' . $i . '][quantity]'] = '1';
        $body['line_items[' . $i . '][price_data][currency]'] = 'usd';
        $body['line_items[' . $i . '][price_data][unit_amount]'] = (string) $fee_cents;
        $body['line_items[' . $i . '][price_data][product_data][name]'] = 'Card processing fee (' . brb_fee_percent_label($percent) . ')';
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
function brb_stripe_begin_checkout($token, $sig, $agreed, $choice = '') {
    if (!brb_pay_token_valid($token, $sig)) return new WP_Error('token', 'This payment link is not valid.');
    if (!$agreed) return new WP_Error('agree', 'Please confirm the card processing fee.');
    $b = brb_booking_by_token($token);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    return brb_stripe_create_session($b, $choice);
}
function brb_handle_stripe_checkout() {
    $token = sanitize_text_field(wp_unslash($_POST['br_token'] ?? ''));
    $sig = sanitize_text_field(wp_unslash($_POST['br_sig'] ?? ''));
    $agreed = !empty($_POST['card_fee_agree']);
    $choice = sanitize_key(wp_unslash($_POST['pay_choice'] ?? ''));
    $result = brb_stripe_begin_checkout($token, $sig, $agreed, $choice);
    $back = add_query_arg(['br_pay' => $token, 'br_sig' => $sig], brb_book_url());
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
    if (brb_date_taken($b)) {
        brb_stripe_note_checkout_blocked($b);
        echo '<div class="brb-alert brb-err">' . esc_html(brb_stripe_unavailable_guest_message()) . '</div>';
        echo '<p><a href="' . esc_url(home_url('/contact/')) . '">Contact the venue</a></p>';
        echo '<p><a href="' . esc_url(brb_guest_link($b)) . '">Back to your booking</a></p>';
        return;
    }
    if ($due <= 0) {
        echo '<div class="brb-alert">There is no balance to pay online.</div>';
        echo '<p><a href="' . esc_url(brb_guest_link($b)) . '">Back to your booking</a></p>';
        return;
    }
    $charge = brb_checkout_lines($b);
    $base_cents = (int) $charge['base_cents'];
    $fee_cents = brb_card_fee_cents($base_cents);
    $total_cents = $base_cents + $fee_cents;
    $label = brb_fee_percent_label();
    $can_full = brb_guest_pay_full_available($b);
    $full = $can_full ? brb_pay_choice_totals($b, 'full') : null;
    $deposit_line = brb_booking_deposit_line($b);
    ?>
    <div class="brb-pay-page">
    <div class="brb-card">
      <h2>Pay online</h2>
      <p>Reference <strong><?php echo esc_html(brb_ref($b)); ?></strong></p>
      <p><?php echo esc_html(brb_when($b)); ?></p>
      <div class="brb-paybox">
        <?php foreach ($charge['lines'] as $line) { ?>
        <p><?php echo esc_html($line['name']); ?>: <?php echo esc_html(brb_money($line['cents'] / 100)); ?></p>
        <?php } ?>
        <p>Card processing fee (<?php echo esc_html($label); ?>): <?php echo esc_html(brb_money($fee_cents / 100)); ?></p>
        <p><strong>Total charged to your card: <?php echo esc_html(brb_money($total_cents / 100)); ?></strong></p>
        <p>The processing fee is not part of the booking balance. You can still pay by check or in person. <?php echo esc_html($deposit_line); ?> <?php echo esc_html(brb_fee_public_line()); ?></p>
      </div>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="brb_stripe_checkout">
        <input type="hidden" name="br_token" value="<?php echo esc_attr($token); ?>">
        <input type="hidden" name="br_sig" value="<?php echo esc_attr($sig); ?>">
        <?php if ($can_full) { ?>
        <fieldset class="brb-pay-choice">
          <legend><strong>How much would you like to pay now?</strong></legend>
          <label class="brb-check"><input type="radio" name="pay_choice" value="due_now" checked> Pay the deposit: <?php echo esc_html(brb_money($base_cents / 100)); ?> + <?php echo esc_html(brb_money($fee_cents / 100)); ?> card fee = <strong><?php echo esc_html(brb_money($total_cents / 100)); ?></strong>. The balance of <?php echo esc_html(brb_money(max(0, round(($full['base_cents'] - $base_cents) / 100, 2)))); ?> is due later.</label>
          <label class="brb-check"><input type="radio" name="pay_choice" value="full"> Pay in full: <?php echo esc_html(brb_money($full['base_cents'] / 100)); ?> + <?php echo esc_html(brb_money($full['fee_cents'] / 100)); ?> card fee = <strong><?php echo esc_html(brb_money($full['total_cents'] / 100)); ?></strong>. Nothing more is due.</label>
        </fieldset>
        <?php } ?>
        <label class="brb-check"><input type="checkbox" name="card_fee_agree" value="1" required> I understand a <?php echo esc_html($label); ?> card processing fee applies, <?php echo esc_html(lcfirst($deposit_line)); ?> and I agree to the <a href="<?php echo esc_url(home_url('/terms/')); ?>" target="_blank" rel="noopener">Terms</a> and <a href="<?php echo esc_url(home_url('/rental-agreement/')); ?>" target="_blank" rel="noopener">Rental Agreement</a>.</label>
        <p><button class="brb-btn" type="submit">Continue to card payment</button></p>
      </form>
      <p><a href="<?php echo esc_url(brb_guest_link($b)); ?>">Back to your booking</a></p>
    </div>
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
    $damage_cents = (int) ($meta['damage_cents'] ?? 0);
    $amount_total = (int) ($session['amount_total'] ?? 0);
    if ($base_cents <= 0 || $damage_cents < 0 || $damage_cents > $base_cents || $amount_total !== $base_cents + $fee_cents) {
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
        if (!$b) return brb_stripe_save_unapplied(0, $session, 'No booking matches this payment.');
        $token = (string) ($meta['token'] ?? '');
        $expected_token = (string) $b->token;
        if ($token === '' || strlen($token) !== strlen($expected_token) || !hash_equals($expected_token, $token)) {
            return new WP_Error('token', 'Booking token does not match.', ['status' => 400]);
        }
        $blocked_status = in_array((string) $b->status, ['cancelled', 'expired', 'declined'], true);
        $taken = brb_date_taken($b);
        if ($blocked_status || $taken) {
            $reason = $taken
                ? 'The date is no longer available.'
                : 'The booking status is ' . (string) $b->status . ', so the payment cannot be applied.';
            return brb_stripe_save_unapplied((int) $b->id, $session, $reason);
        }
        $res = brb_record_payment($b->id, $base_cents / 100, '', brb_now()->format('Y-m-d'), 'Card payment', false, 'card', [
            'fee' => $fee_cents / 100,
            'stripe_id' => $pi !== '' ? $pi : $sid,
            'stripe_session' => $sid,
            'damage' => $damage_cents / 100,
        ]);
        if (is_wp_error($res)) {
            // 2.5.84: 'amount' after the booking is already covered = extra payment, with its own staff alert wording.
            if (in_array($res->get_error_code(), ['busy', 'status', 'amount'], true)) {
                $fresh = brb_booking($b->id);
                $covered = $fresh && ((string) $fresh->status === 'paid' || ((float) $fresh->amount_paid + 0.009 >= (float) $fresh->total && (float) $fresh->total > 0.009));
                $kind = ($res->get_error_code() === 'amount' && $covered) ? 'overpay' : '';
                return brb_stripe_save_unapplied((int) $b->id, $session, $res->get_error_message(), $kind);
            }
            return $res;
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
