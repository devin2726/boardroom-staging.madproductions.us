<?php
/**
 * Plugin Name: Boardroom Booking
 * Description: Self-hosted direct booking for The Boardroom — full-day and hourly blocks, availability calendar, quotes, Stripe Checkout, blackout dates, emails. No third-party channels.
 * Version: 1.0.0
 * Author: Mad Productions
 */
if (!defined('ABSPATH')) exit;
define('BRB_VER', '1.0.0');
define('BRB_URL', plugin_dir_url(__FILE__));

function brb_table() { global $wpdb; return $wpdb->prefix . 'br_bookings'; }

register_activation_hook(__FILE__, function () {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = brb_table(); $c = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $t (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      token varchar(40) NOT NULL,
      mode varchar(10) NOT NULL,
      start_dt datetime NOT NULL,
      end_dt datetime NOT NULL,
      guests int NOT NULL DEFAULT 1,
      name varchar(190) NOT NULL,
      email varchar(190) NOT NULL,
      phone varchar(50) DEFAULT '',
      notes text,
      subtotal decimal(10,2) NOT NULL DEFAULT 0,
      cleaning decimal(10,2) NOT NULL DEFAULT 0,
      tax decimal(10,2) NOT NULL DEFAULT 0,
      total decimal(10,2) NOT NULL DEFAULT 0,
      amount_due decimal(10,2) NOT NULL DEFAULT 0,
      status varchar(20) NOT NULL DEFAULT 'pending',
      stripe_session varchar(255) DEFAULT '',
      created datetime NOT NULL,
      PRIMARY KEY  (id),
      KEY token (token),
      KEY range_idx (start_dt,end_dt)
    ) $c;");
    if (!get_option('brb_settings')) update_option('brb_settings', brb_defaults());
});

function brb_defaults() {
    return [
        'mode' => 'both',            // day | hourly | both
        'day_rate' => '500',         // PLACEHOLDER
        'hourly_rate' => '75',       // PLACEHOLDER
        'min_hours' => '2',
        'open_hour' => '9', 'close_hour' => '23',
        'cleaning_fee' => '100',     // PLACEHOLDER
        'tax_rate' => '7',           // PLACEHOLDER %
        'deposit_pct' => '100',      // 100 = pay in full
        'max_guests' => '75',
        'max_days' => '7',
        'hold_minutes' => '30',
        'currency' => 'usd',
        'stripe_pk' => '', 'stripe_sk' => '', 'stripe_whsec' => '',
        'admin_email' => get_option('admin_email'),
        'blackouts' => '',
        'policy' => "PLACEHOLDER cancellation policy — owner to supply. Example: Full refund if cancelled 14+ days before the event; 50% refund 7–13 days before; no refund within 7 days.",
    ];
}
function brb_opt($k = null) {
    $o = wp_parse_args(get_option('brb_settings', []), brb_defaults());
    return $k ? $o[$k] : $o;
}
function brb_money($n) { return '$' . number_format((float)$n, 2); }
function brb_blackouts() {
    $out = [];
    foreach (preg_split('/[\s,]+/', brb_opt('blackouts')) as $d) if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $out[] = $d;
    return $out;
}

/* ---------- core logic ---------- */
function brb_build_range($in) {
    $o = brb_opt();
    $date = sanitize_text_field($in['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return new WP_Error('date', 'Please choose a date.');
    $type = ($in['type'] ?? 'day') === 'hourly' ? 'hourly' : 'day';
    if ($o['mode'] !== 'both') $type = $o['mode'];
    $tz = wp_timezone();
    $d0 = new DateTimeImmutable($date . ' 00:00', $tz);
    if ($d0 < new DateTimeImmutable('today', $tz)) return new WP_Error('past', 'That date is in the past.');
    if ($type === 'day') {
        $days = max(1, min((int)$o['max_days'], (int)($in['days'] ?? 1)));
        $s = $d0; $e = $d0->modify("+$days day");
        $units = $days;
    } else {
        $h = (int)($in['start_hour'] ?? $o['open_hour']);
        $len = max((int)$o['min_hours'], (int)($in['hours'] ?? $o['min_hours']));
        if ($h < (int)$o['open_hour'] || $h + $len > (int)$o['close_hour'])
            return new WP_Error('hours', sprintf('Hourly bookings must be between %d:00 and %d:00.', $o['open_hour'], $o['close_hour']));
        $s = $d0->modify("+$h hour"); $e = $s->modify("+$len hour");
        $units = $len;
    }
    $guests = max(1, (int)($in['guests'] ?? 1));
    if ($guests > (int)$o['max_guests']) return new WP_Error('guests', 'Maximum ' . (int)$o['max_guests'] . ' guests.');
    return compact('type', 's', 'e', 'units', 'guests');
}
function brb_quote($r) {
    $o = brb_opt();
    $rate = $r['type'] === 'day' ? (float)$o['day_rate'] : (float)$o['hourly_rate'];
    $sub = round($rate * $r['units'], 2);
    $clean = round((float)$o['cleaning_fee'], 2);
    $tax = round(($sub + $clean) * (float)$o['tax_rate'] / 100, 2);
    $total = $sub + $clean + $tax;
    $due = round($total * max(1, min(100, (float)$o['deposit_pct'])) / 100, 2);
    return ['rate' => $rate, 'units' => $r['units'], 'subtotal' => $sub, 'cleaning' => $clean, 'tax' => $tax, 'total' => $total, 'due' => $due];
}
/** true if range is free (no blackout, no overlapping confirmed/fresh-pending booking) */
function brb_is_free($s, $e, $exclude_id = 0) {
    global $wpdb;
    $bo = brb_blackouts();
    for ($d = $s->setTime(0, 0); $d < $e; $d = $d->modify('+1 day')) if (in_array($d->format('Y-m-d'), $bo, true)) return false;
    $hold = gmdate('Y-m-d H:i:s', time() - 60 * (int)brb_opt('hold_minutes'));
    $n = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . brb_table() . " WHERE id<>%d AND start_dt < %s AND end_dt > %s
         AND (status='confirmed' OR (status='pending' AND (stripe_session='' OR created > %s)))",
        $exclude_id, $e->format('Y-m-d H:i:s'), $s->format('Y-m-d H:i:s'), $hold));
    return (int)$n === 0;
}
function brb_get($field, $val) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . brb_table() . " WHERE $field=%s", $val));
}
function brb_confirm($b) {
    global $wpdb;
    if (!$b || $b->status === 'confirmed') return;
    $wpdb->update(brb_table(), ['status' => 'confirmed'], ['id' => $b->id]);
    $b->status = 'confirmed';
    brb_send_emails($b);
}
function brb_when($b) {
    $s = new DateTimeImmutable($b->start_dt, wp_timezone()); $e = new DateTimeImmutable($b->end_dt, wp_timezone());
    if ($b->mode === 'day') return $s->format('D M j, Y') . ' – ' . $e->modify('-1 day')->format('D M j, Y') . ' (full day)';
    return $s->format('D M j, Y g:ia') . ' – ' . $e->format('g:ia');
}
function brb_send_emails($b) {
    $o = brb_opt();
    $lines = "Booking #BR-{$b->id}\nWhen: " . brb_when($b) . "\nGuests: {$b->guests}\nTotal: " . brb_money($b->total) . "\nPaid: " . brb_money($b->amount_due) . "\nBalance: " . brb_money($b->total - $b->amount_due) . "\n";
    wp_mail($b->email, 'Your Boardroom booking is confirmed (#BR-' . $b->id . ')',
        "Hi {$b->name},\n\nThanks for booking The Boardroom!\n\n$lines\nCancellation policy:\n{$o['policy']}\n\nView your booking: " . add_query_arg('br_token', $b->token, home_url('/book/')) . "\n");
    wp_mail($o['admin_email'], 'New booking #BR-' . $b->id . ' – ' . $b->name,
        $lines . "Name: {$b->name}\nEmail: {$b->email}\nPhone: {$b->phone}\nNotes: {$b->notes}\n\nAdmin: " . admin_url('admin.php?page=brb') . "\n");
}

/* ---------- Stripe (no SDK; plain HTTPS) ---------- */
function brb_stripe($method, $path, $body = []) {
    $res = wp_remote_request('https://api.stripe.com/v1/' . $path, [
        'method' => $method, 'timeout' => 30,
        'headers' => ['Authorization' => 'Bearer ' . brb_opt('stripe_sk')],
        'body' => $body ?: null]);
    if (is_wp_error($res)) return $res;
    $j = json_decode(wp_remote_retrieve_body($res), true);
    if (!empty($j['error'])) return new WP_Error('stripe', $j['error']['message']);
    return $j;
}

/* ---------- booking submit ---------- */
add_action('admin_post_nopriv_brb_book', 'brb_handle_book');
add_action('admin_post_brb_book', 'brb_handle_book');
function brb_handle_book() {
    global $wpdb;
    $back = wp_get_referer() ?: home_url('/book/');
    if (!wp_verify_nonce($_POST['_brb'] ?? '', 'brb_book')) wp_die('Session expired, please try again.');
    $fail = function ($m) use ($back) { wp_safe_redirect(add_query_arg('br_err', rawurlencode($m), $back)); exit; };
    $r = brb_build_range(wp_unslash($_POST));
    if (is_wp_error($r)) $fail($r->get_error_message());
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? '')); $email = sanitize_email($_POST['email'] ?? '');
    if (!$name || !is_email($email)) $fail('Name and a valid email are required.');
    if (empty($_POST['policy'])) $fail('Please accept the cancellation policy.');
    $q = brb_quote($r);
    // double-booking prevention: serialize booking writes with a DB lock, then re-check
    $wpdb->get_var("SELECT GET_LOCK('brb_book', 10)");
    if (!brb_is_free($r['s'], $r['e'])) { $wpdb->get_var("SELECT RELEASE_LOCK('brb_book')"); $fail('Sorry, that time was just booked or is unavailable. Please pick another.'); }
    $token = wp_generate_password(32, false);
    $wpdb->insert(brb_table(), [
        'token' => $token, 'mode' => $r['type'],
        'start_dt' => $r['s']->format('Y-m-d H:i:s'), 'end_dt' => $r['e']->format('Y-m-d H:i:s'),
        'guests' => $r['guests'], 'name' => $name, 'email' => $email,
        'phone' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')), 'notes' => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
        'subtotal' => $q['subtotal'], 'cleaning' => $q['cleaning'], 'tax' => $q['tax'], 'total' => $q['total'], 'amount_due' => $q['due'],
        'status' => 'pending', 'created' => current_time('mysql', true)]);
    $id = $wpdb->insert_id;
    $wpdb->get_var("SELECT RELEASE_LOCK('brb_book')");
    $conf = add_query_arg('br_token', $token, home_url('/book/'));
    if (!brb_opt('stripe_sk')) { wp_safe_redirect(add_query_arg('br_nopay', 1, $conf)); exit; }
    $sess = brb_stripe('POST', 'checkout/sessions', [
        'mode' => 'payment',
        'success_url' => $conf . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => add_query_arg('br_err', rawurlencode('Payment cancelled — your hold will expire.'), $back),
        'customer_email' => $email,
        'client_reference_id' => (string)$id,
        'metadata[booking_id]' => $id,
        'expires_at' => time() + max(1800, 60 * (int)brb_opt('hold_minutes')),
        'line_items[0][quantity]' => 1,
        'line_items[0][price_data][currency]' => brb_opt('currency'),
        'line_items[0][price_data][unit_amount]' => (int)round($q['due'] * 100),
        'line_items[0][price_data][product_data][name]' => 'The Boardroom – ' . ($q['due'] < $q['total'] ? 'Deposit' : 'Booking') . ' #BR-' . $id,
    ]);
    if (is_wp_error($sess)) { $wpdb->update(brb_table(), ['status' => 'cancelled'], ['id' => $id]); $fail('Payment error: ' . $sess->get_error_message()); }
    $wpdb->update(brb_table(), ['stripe_session' => $sess['id']], ['id' => $id]);
    wp_redirect($sess['url']); exit;
}

/* ---------- REST: availability + webhook ---------- */
add_action('rest_api_init', function () {
    register_rest_route('brb/v1', '/availability', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function ($req) {
        global $wpdb;
        $m = preg_match('/^\d{4}-\d{2}$/', $req['month']) ? $req['month'] : wp_date('Y-m');
        $tz = wp_timezone(); $first = new DateTimeImmutable("$m-01", $tz); $next = $first->modify('+1 month');
        $hold = gmdate('Y-m-d H:i:s', time() - 60 * (int)brb_opt('hold_minutes'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT start_dt,end_dt FROM " . brb_table() . " WHERE start_dt < %s AND end_dt > %s AND (status='confirmed' OR (status='pending' AND (stripe_session='' OR created > %s)))",
            $next->format('Y-m-d H:i:s'), $first->format('Y-m-d H:i:s'), $hold));
        $o = brb_opt(); $days = []; $bo = brb_blackouts(); $today = new DateTimeImmutable('today', $tz);
        for ($d = $first; $d < $next; $d = $d->modify('+1 day')) {
            $k = $d->format('Y-m-d'); $busy = [];
            $open = $d->modify('+' . (int)$o['open_hour'] . ' hour'); $close = $d->modify('+' . (int)$o['close_hour'] . ' hour');
            $full = false;
            foreach ($rows as $r) {
                $s = new DateTimeImmutable($r->start_dt, $tz); $e = new DateTimeImmutable($r->end_dt, $tz);
                if ($s < $d->modify('+1 day') && $e > $d) {
                    if ($s <= $open && $e >= $close) $full = true;
                    $busy[] = [max(0, (int)(($s->getTimestamp() - $d->getTimestamp()) / 3600)), min(24, (int)ceil(($e->getTimestamp() - $d->getTimestamp()) / 3600))];
                }
            }
            $st = ($d < $today) ? 'past' : (in_array($k, $bo, true) ? 'blackout' : ($full ? 'booked' : ($busy ? 'partial' : 'free')));
            $days[$k] = ['status' => $st, 'busy' => $busy];
        }
        return ['month' => $m, 'days' => $days];
    }]);
    register_rest_route('brb/v1', '/stripe-webhook', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ($req) {
        $payload = $req->get_body(); $sig = $req->get_header('stripe_signature'); $secret = brb_opt('stripe_whsec');
        if (!$secret || !$sig) return new WP_REST_Response(['error' => 'not configured'], 400);
        $parts = []; foreach (explode(',', $sig) as $p) { [$k, $v] = array_pad(explode('=', $p, 2), 2, ''); $parts[$k][] = $v; }
        $t = $parts['t'][0] ?? ''; $ok = false;
        foreach ($parts['v1'] ?? [] as $v) if (hash_equals(hash_hmac('sha256', "$t.$payload", $secret), $v)) $ok = true;
        if (!$ok || abs(time() - (int)$t) > 300) return new WP_REST_Response(['error' => 'bad signature'], 400);
        $ev = json_decode($payload, true);
        if (($ev['type'] ?? '') === 'checkout.session.completed' && ($ev['data']['object']['payment_status'] ?? '') === 'paid') {
            brb_confirm(brb_get('stripe_session', $ev['data']['object']['id']));
        }
        if (($ev['type'] ?? '') === 'checkout.session.expired') {
            global $wpdb; $b = brb_get('stripe_session', $ev['data']['object']['id']);
            if ($b && $b->status === 'pending') $wpdb->update(brb_table(), ['status' => 'cancelled'], ['id' => $b->id]);
        }
        return ['received' => true];
    }]);
});

/* ---------- front end shortcode ---------- */
add_shortcode('boardroom_booking', function () {
    wp_enqueue_style('brb', BRB_URL . 'assets/brb.css', [], BRB_VER);
    wp_enqueue_script('brb', BRB_URL . 'assets/brb.js', [], BRB_VER, true);
    $o = brb_opt();
    wp_localize_script('brb', 'BRB', ['api' => esc_url_raw(rest_url('brb/v1/availability')), 'open' => (int)$o['open_hour'], 'close' => (int)$o['close_hour'], 'minHours' => (int)$o['min_hours']]);
    ob_start();
    echo '<div class="brb">';
    if (!empty($_GET['br_err'])) echo '<div class="brb-alert brb-err">' . esc_html(wp_unslash($_GET['br_err'])) . '</div>';
    if (!empty($_GET['br_token'])) { brb_view_confirmation(sanitize_text_field($_GET['br_token'])); }
    elseif (!empty($_GET['date'])) { brb_view_quote(); }
    else { brb_view_select(); }
    echo '</div>';
    return ob_get_clean();
});
function brb_steps($i) {
    echo '<ol class="brb-steps">';
    foreach (['Choose date', 'Quote & details', 'Payment', 'Confirmed'] as $n => $s) echo '<li class="' . ($n == $i ? 'on' : ($n < $i ? 'done' : '')) . '">' . esc_html($s) . '</li>';
    echo '</ol>';
}
function brb_view_select() {
    $o = brb_opt(); brb_steps(0); ?>
    <div class="brb-grid">
      <div class="brb-card"><div id="brb-cal" class="brb-cal" aria-live="polite">Loading calendar…</div>
        <p class="brb-legend"><span class="free"></span>Available <span class="partial"></span>Partly booked <span class="booked"></span>Unavailable</p></div>
      <form class="brb-card" method="get" id="brb-form">
        <label>Date<input type="date" name="date" id="brb-date" required min="<?php echo esc_attr(wp_date('Y-m-d')); ?>"></label>
        <?php if ($o['mode'] === 'both'): ?>
        <fieldset class="brb-type"><legend>Booking type</legend>
          <label><input type="radio" name="type" value="day" checked> Full day (<?php echo brb_money($o['day_rate']); ?>/day)</label>
          <label><input type="radio" name="type" value="hourly"> Hourly (<?php echo brb_money($o['hourly_rate']); ?>/hr, <?php echo (int)$o['min_hours']; ?> hr min)</label></fieldset>
        <?php else: ?><input type="hidden" name="type" value="<?php echo esc_attr($o['mode']); ?>"><?php endif; ?>
        <div class="brb-day"><label>Number of days<input type="number" name="days" value="1" min="1" max="<?php echo (int)$o['max_days']; ?>"></label></div>
        <div class="brb-hourly"><label>Start time<select name="start_hour" id="brb-start"><?php for ($h = (int)$o['open_hour']; $h < (int)$o['close_hour']; $h++) printf('<option value="%d">%s</option>', $h, esc_html(date('g:00 a', mktime($h, 0)))); ?></select></label>
          <label>Hours<input type="number" name="hours" value="<?php echo (int)$o['min_hours']; ?>" min="<?php echo (int)$o['min_hours']; ?>" max="<?php echo (int)$o['close_hour'] - (int)$o['open_hour']; ?>"></label>
          <p class="brb-small" id="brb-busy"></p></div>
        <label>Guests (max <?php echo (int)$o['max_guests']; ?>)<input type="number" name="guests" value="10" min="1" max="<?php echo (int)$o['max_guests']; ?>"></label>
        <button class="brb-btn" type="submit">Get Quote</button>
      </form>
    </div><?php
}
function brb_view_quote() {
    $o = brb_opt(); $in = wp_unslash($_GET);
    $r = brb_build_range($in);
    if (is_wp_error($r)) { echo '<div class="brb-alert brb-err">' . esc_html($r->get_error_message()) . '</div>'; brb_view_select(); return; }
    if (!brb_is_free($r['s'], $r['e'])) { echo '<div class="brb-alert brb-err">Sorry, that date/time is not available. Please choose another.</div>'; brb_view_select(); return; }
    $q = brb_quote($r); brb_steps(1);
    $when = $r['type'] === 'day' ? $r['s']->format('D M j, Y') . ($r['units'] > 1 ? ' – ' . $r['e']->modify('-1 day')->format('D M j, Y') : '') . ' (full day)' : $r['s']->format('D M j, Y g:ia') . ' – ' . $r['e']->format('g:ia'); ?>
    <div class="brb-grid">
      <div class="brb-card"><h3>Your quote</h3><p><strong><?php echo esc_html($when); ?></strong><br><?php echo (int)$r['guests']; ?> guests</p>
        <table class="brb-quote">
          <tr><td><?php echo brb_money($q['rate']) . ' × ' . (int)$q['units'] . ($r['type'] === 'day' ? ' day(s)' : ' hour(s)'); ?></td><td><?php echo brb_money($q['subtotal']); ?></td></tr>
          <tr><td>Cleaning fee</td><td><?php echo brb_money($q['cleaning']); ?></td></tr>
          <tr><td>Tax (<?php echo esc_html($o['tax_rate']); ?>%)</td><td><?php echo brb_money($q['tax']); ?></td></tr>
          <tr class="tot"><td>Total</td><td><?php echo brb_money($q['total']); ?></td></tr>
          <?php if ($q['due'] < $q['total']): ?><tr><td>Deposit due now (<?php echo esc_html($o['deposit_pct']); ?>%)</td><td><?php echo brb_money($q['due']); ?></td></tr><?php endif; ?>
        </table><a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>">← Change date</a></div>
      <form class="brb-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <h3>Guest details</h3>
        <input type="hidden" name="action" value="brb_book"><?php wp_nonce_field('brb_book', '_brb');
        foreach (['date', 'type', 'days', 'start_hour', 'hours', 'guests'] as $k) if (isset($in[$k])) printf('<input type="hidden" name="%s" value="%s">', esc_attr($k), esc_attr($in[$k])); ?>
        <label>Full name*<input name="name" required></label>
        <label>Email*<input type="email" name="email" required></label>
        <label>Phone<input name="phone"></label>
        <label>Event details / notes<textarea name="notes" rows="3"></textarea></label>
        <div class="brb-policy"><strong>Cancellation policy</strong><p><?php echo nl2br(esc_html($o['policy'])); ?></p></div>
        <label class="brb-check"><input type="checkbox" name="policy" value="1" required> I agree to the cancellation policy and <a href="<?php echo esc_url(home_url('/terms-and-conditions/')); ?>" target="_blank">Terms and Conditions</a>.</label>
        <?php if (!$o['stripe_sk']) echo '<div class="brb-alert">Online payment is not configured yet (no Stripe keys). Your request will be held and we will contact you to arrange payment.</div>'; ?>
        <button class="brb-btn" type="submit"><?php echo $o['stripe_sk'] ? 'Continue to secure payment – ' . brb_money($q['due']) : 'Request booking'; ?></button>
      </form>
    </div><?php
}
function brb_view_confirmation($token) {
    $b = brb_get('token', $token);
    if (!$b) { echo '<div class="brb-alert brb-err">Booking not found.</div>'; return; }
    // fallback confirmation if webhook hasn't arrived yet
    if ($b->status === 'pending' && $b->stripe_session && !empty($_GET['session_id']) && $_GET['session_id'] === $b->stripe_session) {
        $s = brb_stripe('GET', 'checkout/sessions/' . rawurlencode($b->stripe_session));
        if (!is_wp_error($s) && ($s['payment_status'] ?? '') === 'paid') brb_confirm($b);
        $b = brb_get('token', $token);
    }
    brb_steps($b->status === 'confirmed' ? 3 : 2); ?>
    <div class="brb-card brb-confirm">
      <h2><?php echo $b->status === 'confirmed' ? 'Booking confirmed!' : ($b->status === 'cancelled' ? 'Booking cancelled' : 'Booking request received'); ?></h2>
      <?php if (!empty($_GET['br_nopay']) || ($b->status === 'pending' && !$b->stripe_session)) echo '<div class="brb-alert">Online payment is not set up yet. Your dates are held and we will contact you to arrange payment.</div>'; ?>
      <p>Reference <strong>#BR-<?php echo (int)$b->id; ?></strong></p>
      <p><?php echo esc_html(brb_when($b)); ?> · <?php echo (int)$b->guests; ?> guests</p>
      <p>Total <?php echo brb_money($b->total); ?> · <?php echo $b->status === 'confirmed' ? 'Paid ' . brb_money($b->amount_due) . ' · Balance ' . brb_money($b->total - $b->amount_due) : 'Amount due ' . brb_money($b->amount_due); ?></p>
      <?php if ($b->status === 'confirmed') echo '<p>A confirmation email has been sent to ' . esc_html($b->email) . '.</p>'; ?>
    </div><?php
}

require __DIR__ . '/admin.php';
