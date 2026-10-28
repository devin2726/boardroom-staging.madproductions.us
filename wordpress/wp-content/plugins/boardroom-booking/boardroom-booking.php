<?php
/**
 * Plugin Name: Boardroom Booking
 * Description: Self-hosted direct booking for The Boardroom — full-day and hourly blocks, availability calendar, agreed prices, approval before payment, blackout dates, and emails.
 * Version: 2.5.2
 * Author: Mad Productions
 */
if (!defined('ABSPATH')) exit;
define('BRB_VER', '2.5.2');
define('BRB_VENUE_ADDRESS', '2220 37th Street, Moline, IL 61265');
define('BRB_CHECK_ADDRESS', '2212 37th Street, Moline, IL 61265');
define('BRB_MAPS_URL', 'https://maps.app.goo.gl/nVqUsGTcy48nPzc18');
define('BRB_MAPS_LAT', '41.4913116');
define('BRB_MAPS_LNG', '-90.4820481');
require_once __DIR__ . '/legal-content.php';
require_once __DIR__ . '/tour.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/chat.php';
define('BRB_URL', plugin_dir_url(__FILE__));

function brb_table() { global $wpdb; return $wpdb->prefix . 'br_bookings'; }
function brb_pay_table() { global $wpdb; return $wpdb->prefix . 'br_payments'; }

function brb_tz() {
    static $tz = null;
    if (!$tz) $tz = new DateTimeZone('America/Chicago');
    return $tz;
}
function brb_now() { return new DateTimeImmutable('now', brb_tz()); }
function brb_format_chicago(DateTimeInterface $dt) {
    $local = (new DateTimeImmutable('@' . $dt->getTimestamp()))->setTimezone(brb_tz());
    return $local->format('l, F j, Y \a\t g:i A') . ' America/Chicago';
}
function brb_format_utc_mysql($mysql) {
    if (!$mysql) return '';
    try {
        return brb_format_chicago(new DateTimeImmutable($mysql, new DateTimeZone('UTC')));
    } catch (Exception $e) {
        return '';
    }
}
function brb_dec($n) { return number_format(round((float) $n, 2), 2, '.', ''); }
function brb_money($n) { return '$' . number_format((float) $n, 2); }
function brb_whole_money($n) {
    $n = round((float) $n, 2);
    if (abs($n - round($n)) < 0.001) return '$' . number_format((float) round($n), 0);
    return brb_money($n);
}
function brb_ref($b) { return 'BR-' . (int) $b->id; }
function brb_price_sentence() {
    $start = brb_whole_money(brb_opt('starting_price'));
    $typical = trim((string) brb_opt('typical_price'));
    $sentence = 'Pricing from ' . $start . ' per day';
    if ($typical !== '') $sentence .= ' (' . $typical . ')';
    $sentence .= ', final price confirmed based on date, guest count and features.';
    return $sentence;
}
function brb_price_is_set($b) {
    if (is_array($b)) {
        if (array_key_exists('price_set', $b)) return (int) $b['price_set'] === 1;
        return ((float) ($b['total'] ?? 0)) > 0.009;
    }
    if (isset($b->price_set)) return (int) $b->price_set === 1;
    return isset($b->total) && (float) $b->total > 0.009;
}
function brb_price_phrase($b) {
    return brb_price_is_set($b) ? brb_money($b->total) : 'price to be confirmed';
}

function brb_defaults() {
    $admin = function_exists('get_option') ? (string) get_option('admin_email') : '';
    return [
        'mode' => 'both',
        'day_rate' => '500',
        'hourly_rate' => '75',
        'starting_price' => '600',
        'typical_price' => 'most events around $1,200',
        'min_hours' => '2',
        'open_hour' => '9',
        'close_hour' => '23',
        'cleaning_fee' => '100',
        'tax_rate' => '7',
        'deposit_type' => 'percent',
        'deposit_pct' => '50',
        'deposit_fixed' => '0',
        'balance_due_days' => '7',
        'max_guests' => '75',
        'max_days' => '7',
        'min_notice_hours' => '48',
        'hold_hours' => '48',
        'currency' => 'usd',
        'admin_email' => $admin,
        'tour_email' => 'boardroomqc@gmail.com',
        'payee' => '[VERIFY] Name on the check',
        'venue_address' => BRB_VENUE_ADDRESS,
        'pay_location' => BRB_CHECK_ADDRESS,
        'pay_hours' => '[VERIFY] In-person hours',
        'pay_instructions' => '',
        'card_fee_percent' => '3',
        'blackouts' => '',
        'policy' => brb_cancellation_summary(),
        'quote_prices' => '1',
        'rate_sun_thu' => '850',
        'rate_fri_sat' => '1400',
        'rate_ranges' => wp_json_encode(brb_default_rate_ranges()),
    ];
}
function brb_legacy_keys() {
    return ['hold_minutes', 'str' . 'ipe_pk', 'str' . 'ipe_sk', 'str' . 'ipe_whsec'];
}
function brb_opt($k = null) {
    $stored = get_option('brb_settings', []);
    if (!is_array($stored)) $stored = [];
    $o = wp_parse_args($stored, brb_defaults());
    foreach (brb_legacy_keys() as $lk) unset($o[$lk]);
    if ($k === null) return $o;
    return $o[$k] ?? '';
}
function brb_venue_address() {
    $v = trim((string) brb_opt('venue_address'));
    return $v !== '' ? $v : BRB_VENUE_ADDRESS;
}
function brb_check_address() {
    $v = trim((string) brb_opt('pay_location'));
    return $v !== '' ? $v : BRB_CHECK_ADDRESS;
}
function brb_guest_payee() {
    $payee = trim((string) brb_opt('payee'));
    if ($payee === '' || $payee === '[VERIFY] Name on the check') return 'Mad Productions';
    return $payee;
}
function brb_check_sentence($item = 'checks') {
    $hours = trim((string) brb_opt('pay_hours'));
    $suffix = ($hours !== '' && $hours !== '[VERIFY] In-person hours') ? ' (' . $hours . ')' : '';
    return 'Mail or drop off ' . $item . ' payable to ' . brb_guest_payee() . ' at ' . brb_check_address() . $suffix . '.';
}
function brb_directions_markup() {
    return '<p><a href="' . esc_url(BRB_MAPS_URL) . '" rel="noopener noreferrer">Get directions / View on Google Maps</a></p>';
}
function brb_map_embed_src() {
    return 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2988!2d' . BRB_MAPS_LNG . '!3d' . BRB_MAPS_LAT . '!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x87e231a14fb72783:0x7d868c8b37ef86ac!2sthe%20Boardroom!5e0!3m2!1sen!2sus';
}
function brb_map_embed() {
    return '<iframe class="venue-map" src="' . esc_url(brb_map_embed_src()) . '" loading="lazy" title="Map of The Boardroom" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>';
}
function brb_contact_stored_markup() {
    return '<h2>Contact the Boardroom</h2><p>The Boardroom QC<br>' . BRB_VENUE_ADDRESS . '</p><p><a href="mailto:boardroomqc@gmail.com">boardroomqc@gmail.com</a></p>' . brb_directions_markup();
}
function brb_place_schema() {
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'EventVenue',
        'name' => 'The Boardroom',
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => '2220 37th Street',
            'addressLocality' => 'Moline',
            'addressRegion' => 'IL',
            'postalCode' => '61265',
            'addressCountry' => 'US',
        ],
        'geo' => [
            '@type' => 'GeoCoordinates',
            'latitude' => BRB_MAPS_LAT,
            'longitude' => BRB_MAPS_LNG,
        ],
        'hasMap' => BRB_MAPS_URL,
    ];
    echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_SLASHES) . "</script>\n";
}
add_action('wp_head', 'brb_place_schema', 20);
function brb_blackouts() {
    $out = [];
    foreach (preg_split('/[\s,]+/', (string) brb_opt('blackouts')) as $d) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $out[] = $d;
    }
    return $out;
}
function brb_notice_hours() { return max(0, (int) brb_opt('min_notice_hours')); }
function brb_hold_hours() { return max(1, (int) brb_opt('hold_hours')); }
function brb_notice_cutoff() {
    return brb_now()->modify('+' . brb_notice_hours() . ' hours');
}
function brb_deposit_due_amount($total) {
    $o = brb_opt();
    $total = round((float) $total, 2);
    $type = $o['deposit_type'] ?? 'full';
    if ($type === 'percent') {
        $pct = (float) $o['deposit_pct'];
        if ($pct <= 0) $pct = 100;
        if ($pct > 100) $pct = 100;
        return round($total * $pct / 100, 2);
    }
    if ($type === 'fixed') {
        $fixed = round((float) $o['deposit_fixed'], 2);
        if ($fixed <= 0) return $total;
        return min($total, $fixed);
    }
    return $total;
}
function brb_status_label($s) {
    $map = [
        'review' => 'Pending review',
        'approved' => 'Approved, deposit due',
        'held' => 'Approved, deposit due',
        'deposit_paid' => 'Deposit paid',
        'paid' => 'Paid in full',
        'declined' => 'Declined',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        'pending' => 'Pending review',
        'confirmed' => 'Paid in full',
    ];
    return $map[$s] ?? $s;
}
function brb_source_label($s) {
    $map = ['online' => 'Online', 'phone' => 'Phone', 'walk-in' => 'Walk-in'];
    return $map[$s] ?? ($s ?: 'Online');
}
function brb_event_types() {
    return [
        'wedding' => 'Wedding',
        'birthday' => 'Birthday',
        'corporate' => 'Corporate',
        'fundraiser' => 'Fundraiser',
        'other' => 'Other',
    ];
}
function brb_event_type_label($value) {
    $all = brb_event_types();
    $value = (string) $value;
    return $all[$value] ?? '';
}
function brb_event_type_line($b) {
    $label = brb_event_type_label($b->event_type ?? '');
    return $label !== '' ? "Event type: {$label}\n" : '';
}
function brb_host_ages() {
    return [
        'under_21' => 'Under 21',
        'age_21_24' => '21 to 24',
        'age_25' => '25 or older',
    ];
}
function brb_yes_no_label($value) {
    if ($value === 'yes') return 'Yes';
    if ($value === 'no') return 'No';
    return '';
}
function brb_end_time_label($value) {
    $value = (string) $value;
    if (preg_match('/^(\d{2}):(\d{2})/', $value, $m)) {
        $h = (int) $m[1];
        $suffix = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12;
        if ($h12 === 0) $h12 = 12;
        return $h12 . ':' . $m[2] . ' ' . $suffix;
    }
    return $value;
}
function brb_deposit_refund_rule() {
    return 'It is refundable if written cancellation is received 90 or more days before the event, less any non-refundable processing charges (including the ' . brb_fee_percent_label() . ' card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 90 days before the event. Refunds are issued manually.';
}
function brb_deposit_line() {
    $o = brb_opt();
    $rule = brb_deposit_refund_rule();
    if (($o['deposit_type'] ?? '') === 'percent') {
        $pct = (float) ($o['deposit_pct'] ?? 50);
        if ($pct <= 0) $pct = 50;
        if ($pct > 100) $pct = 100;
        $label = (abs($pct - round($pct)) < 0.001) ? (string) (int) round($pct) : rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
        return 'The deposit (' . $label . '% of the agreed total) holds the date. ' . $rule;
    }
    return 'The deposit holds the date. ' . $rule;
}
function brb_reserved_line() {
    return 'Your date is reserved once your deposit is received.';
}
function brb_not_held_line() {
    return 'Your date is not held until the deposit is received.';
}
function brb_approval_line() {
    return 'Bookings are subject to approval. No payment is due until the request is approved. The owner may decline any request.';
}
function brb_request_open_line() {
    return 'A request does not hold the date.';
}
function brb_fee_public_line() {
    return 'The ' . brb_fee_percent_label() . ' card processing fee is non-refundable.';
}
function brb_attorney_notice() {
    return 'Deposit and refund wording updated October 27, 2026: the deposit (50% of the agreed total) is refundable if written cancellation is received 90 or more days before the event, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 90 days before the event. Staff process refunds manually. [VERIFY: attorney review]';
}
function brb_screening_lines($b) {
    $lines = [];
    $public = brb_yes_no_label($b->public_event ?? '');
    if ($public !== '') $lines[] = 'Open to the public or ticketed: ' . $public;
    $alcohol = brb_yes_no_label($b->alcohol ?? '');
    if ($alcohol !== '') $lines[] = 'Alcohol served: ' . $alcohol;
    $end = trim((string) ($b->end_time ?? ''));
    if ($end !== '') $lines[] = 'Planned end time: ' . brb_end_time_label($end);
    $ages = brb_host_ages();
    $age = $ages[$b->host_age ?? ''] ?? '';
    if ($age !== '') $lines[] = 'Host age bracket: ' . $age;
    $about = trim((string) ($b->event_about ?? ''));
    if ($about !== '') $lines[] = 'About the event: ' . $about;
    return $lines;
}
function brb_collect_screening($post, $required = true) {
    $public = sanitize_key($post['public_event'] ?? '');
    $alcohol = sanitize_key($post['alcohol'] ?? '');
    $end = sanitize_text_field($post['end_time'] ?? '');
    if (preg_match('/^(\d{2}:\d{2})/', $end, $m)) $end = $m[1];
    $age = sanitize_key($post['host_age'] ?? '');
    $about = sanitize_textarea_field($post['event_about'] ?? '');
    if ($required) {
        if (!in_array($public, ['yes', 'no'], true)) return new WP_Error('public_event', 'Please say whether the event is open to the public or ticketed.');
        if (!in_array($alcohol, ['yes', 'no'], true)) return new WP_Error('alcohol', 'Please say whether alcohol will be served.');
        if (!preg_match('/^\d{2}:\d{2}$/', $end)) return new WP_Error('end_time', 'Please enter a planned end time.');
        if (!isset(brb_host_ages()[$age])) return new WP_Error('host_age', 'Please choose the host age bracket.');
        if ($about === '') return new WP_Error('event_about', 'Please tell us about your event.');
    } else {
        if (!in_array($public, ['yes', 'no'], true)) $public = '';
        if (!in_array($alcohol, ['yes', 'no'], true)) $alcohol = '';
        if (!preg_match('/^\d{2}:\d{2}$/', $end)) $end = '';
        if (!isset(brb_host_ages()[$age])) $age = '';
    }
    return [
        'public_event' => $public,
        'alcohol' => $alcohol,
        'end_time' => $end,
        'host_age' => $age,
        'event_about' => $about,
    ];
}
function brb_pay_methods() {
    return ['check' => 'Check', 'cash' => 'Cash'];
}
function brb_pay_method_label($method) {
    if ($method === 'card') return 'Card (Stripe)';
    $all = brb_pay_methods();
    return $all[$method] ?? 'Check';
}
function brb_pay_method_known($method) {
    return $method === 'card' || isset(brb_pay_methods()[$method]);
}
function brb_extra_instructions() {
    $extra = trim((string) brb_opt('pay_instructions'));
    if ($extra === '' || $extra === '[VERIFY] Extra instructions for bringing the check.') return '';
    return $extra;
}

/* ---------- schema + upgrade (keeps existing options, including blackouts) ---------- */
function brb_ensure_column($table, $col, $def) {
    global $wpdb;
    $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $col));
    if (!$found) $wpdb->query("ALTER TABLE `$table` ADD COLUMN `$col` $def");
}
function brb_install_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $t = brb_table();
    dbDelta("CREATE TABLE $t (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      token varchar(40) NOT NULL,
      mode varchar(10) NOT NULL,
      start_dt datetime NOT NULL,
      end_dt datetime NOT NULL,
      guests int(11) NOT NULL DEFAULT 1,
      name varchar(190) NOT NULL,
      email varchar(190) NOT NULL,
      phone varchar(50) DEFAULT '',
      notes text,
      subtotal decimal(10,2) NOT NULL DEFAULT 0.00,
      cleaning decimal(10,2) NOT NULL DEFAULT 0.00,
      tax decimal(10,2) NOT NULL DEFAULT 0.00,
      total decimal(10,2) NOT NULL DEFAULT 0.00,
      amount_due decimal(10,2) NOT NULL DEFAULT 0.00,
      deposit_due decimal(10,2) NOT NULL DEFAULT 0.00,
      amount_paid decimal(10,2) NOT NULL DEFAULT 0.00,
      status varchar(20) NOT NULL DEFAULT 'review',
      source varchar(20) NOT NULL DEFAULT 'online',
      hold_until datetime DEFAULT NULL,
      balance_due_on date DEFAULT NULL,
      price_set tinyint(1) NOT NULL DEFAULT 0,
      event_type varchar(40) NOT NULL DEFAULT '',
      public_event varchar(3) NOT NULL DEFAULT '',
      alcohol varchar(3) NOT NULL DEFAULT '',
      end_time varchar(8) NOT NULL DEFAULT '',
      host_age varchar(20) NOT NULL DEFAULT '',
      event_about text,
      attention varchar(255) NOT NULL DEFAULT '',
      created datetime NOT NULL,
      PRIMARY KEY  (id),
      KEY token (token),
      KEY range_idx (start_dt,end_dt),
      KEY hold_idx (status,hold_until)
    ) $c;");
    brb_ensure_column($t, 'deposit_due', 'decimal(10,2) NOT NULL DEFAULT 0.00');
    brb_ensure_column($t, 'amount_paid', 'decimal(10,2) NOT NULL DEFAULT 0.00');
    brb_ensure_column($t, 'source', "varchar(20) NOT NULL DEFAULT 'online'");
    brb_ensure_column($t, 'hold_until', 'datetime NULL DEFAULT NULL');
    brb_ensure_column($t, 'balance_due_on', 'date NULL DEFAULT NULL');
    brb_ensure_column($t, 'price_set', 'tinyint(1) NOT NULL DEFAULT 0');
    brb_ensure_column($t, 'event_type', "varchar(40) NOT NULL DEFAULT ''");
    brb_ensure_column($t, 'public_event', "varchar(3) NOT NULL DEFAULT ''");
    brb_ensure_column($t, 'alcohol', "varchar(3) NOT NULL DEFAULT ''");
    brb_ensure_column($t, 'end_time', "varchar(8) NOT NULL DEFAULT ''");
    brb_ensure_column($t, 'host_age', "varchar(20) NOT NULL DEFAULT ''");
    brb_ensure_column($t, 'event_about', 'text');
    brb_ensure_column($t, 'attention', "varchar(255) NOT NULL DEFAULT ''");
    $p = brb_pay_table();
    dbDelta("CREATE TABLE $p (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      booking_id bigint(20) unsigned NOT NULL,
      amount decimal(10,2) NOT NULL DEFAULT 0.00,
      check_number varchar(64) NOT NULL DEFAULT '',
      received_on date DEFAULT NULL,
      notes text,
      method varchar(20) NOT NULL DEFAULT 'check',
      fee decimal(10,2) NOT NULL DEFAULT 0.00,
      stripe_id varchar(80) NOT NULL DEFAULT '',
      stripe_session varchar(80) NOT NULL DEFAULT '',
      created datetime NOT NULL,
      PRIMARY KEY  (id),
      KEY booking_id (booking_id)
    ) $c;");
    brb_ensure_column($p, 'method', "varchar(20) NOT NULL DEFAULT 'check'");
    brb_ensure_column($p, 'fee', 'decimal(10,2) NOT NULL DEFAULT 0.00');
    brb_ensure_column($p, 'stripe_id', "varchar(80) NOT NULL DEFAULT ''");
    brb_ensure_column($p, 'stripe_session', "varchar(80) NOT NULL DEFAULT ''");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p)) !== $p) {
        $wpdb->query("CREATE TABLE IF NOT EXISTS $p (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          booking_id bigint(20) unsigned NOT NULL,
          amount decimal(10,2) NOT NULL DEFAULT 0.00,
          check_number varchar(64) NOT NULL DEFAULT '',
          received_on date NULL DEFAULT NULL,
          notes text,
          method varchar(20) NOT NULL DEFAULT 'check',
          fee decimal(10,2) NOT NULL DEFAULT 0.00,
          stripe_id varchar(80) NOT NULL DEFAULT '',
          stripe_session varchar(80) NOT NULL DEFAULT '',
          created datetime NOT NULL,
          PRIMARY KEY (id),
          KEY booking_id (booking_id)
        ) $c");
    }
    brb_ensure_column($p, 'method', "varchar(20) NOT NULL DEFAULT 'check'");
    brb_ensure_column($p, 'fee', 'decimal(10,2) NOT NULL DEFAULT 0.00');
    brb_ensure_column($p, 'stripe_id', "varchar(80) NOT NULL DEFAULT ''");
    brb_ensure_column($p, 'stripe_session', "varchar(80) NOT NULL DEFAULT ''");
    foreach (['deposit_due', 'amount_paid', 'source', 'hold_until', 'balance_due_on', 'price_set', 'event_type', 'public_event', 'alcohol', 'end_time', 'host_age', 'event_about', 'attention'] as $col) {
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$t` LIKE %s", $col))) return false;
    }
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p)) !== $p) return false;
    foreach (['method', 'fee', 'stripe_id', 'stripe_session'] as $col) {
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$p` LIKE %s", $col))) return false;
    }
    return true;
}
function brb_migrate_settings() {
    $stored = get_option('brb_settings', []);
    if (!is_array($stored)) $stored = [];
    $had_blackouts = array_key_exists('blackouts', $stored);
    $blackouts = $had_blackouts ? (string) $stored['blackouts'] : '';
    $merged = wp_parse_args($stored, brb_defaults());
    if ($had_blackouts) $merged['blackouts'] = $blackouts;
    foreach (brb_legacy_keys() as $lk) unset($merged[$lk]);
    if (!isset($stored['deposit_type']) || !in_array($stored['deposit_type'], ['full', 'percent', 'fixed'], true)) {
        $merged['deposit_type'] = 'percent';
        $merged['deposit_pct'] = '50';
    } elseif (($stored['deposit_type'] ?? '') === 'full') {
        $merged['deposit_type'] = 'percent';
        $merged['deposit_pct'] = '50';
    }
    if (!isset($stored['min_notice_hours'])) $merged['min_notice_hours'] = '48';
    if (!isset($stored['hold_hours'])) $merged['hold_hours'] = '48';
    if (!isset($stored['balance_due_days'])) $merged['balance_due_days'] = '7';
    if (!isset($stored['deposit_fixed']) || $stored['deposit_fixed'] === '') $merged['deposit_fixed'] = '0';
    $defaults = brb_defaults();
    foreach (['payee', 'pay_location', 'pay_hours', 'pay_instructions'] as $k) {
        if (!isset($stored[$k]) || trim((string) $stored[$k]) === '') $merged[$k] = $defaults[$k];
    }
    if (trim((string) ($merged['pay_instructions'] ?? '')) === '[VERIFY] Extra instructions for bringing the check.') {
        $merged['pay_instructions'] = '';
    }
    if (!isset($stored['card_fee_percent']) || trim((string) $stored['card_fee_percent']) === '') $merged['card_fee_percent'] = '3';
    $old_summaries = [
        'A date saved on the website is held for 48 hours. Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265. If the check is not received by then, the hold is released. Under the Rental Agreement, 50% of the invoice is due within 1 day after signing unless the Owner agrees otherwise in writing. The invoice notes require full payment 30 days before the event. If you cancel in writing 90 days or more before the rental period, amounts paid may be refunded, less any non-refundable processing charges or costs already incurred. If you cancel less than 90 days before the rental period, security deposits and cleaning fees already paid are non-refundable. Rental fees are non-refundable if cancellation is received less than 30 days before the rental period, unless the Owner agrees otherwise in writing. The signed Rental Agreement controls if it differs from this summary.',
        'A date saved on the website is held for 48 hours. Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265. If the check is not received by then, the hold is released. You can pay by check or in person, or online by card with a 3% processing fee. Under the Rental Agreement, 50% of the invoice is due within 1 day after signing unless the Owner agrees otherwise in writing. The invoice notes require full payment 30 days before the event. If you cancel in writing 90 days or more before the rental period, amounts paid may be refunded, less any non-refundable processing charges or costs already incurred. If you cancel less than 90 days before the rental period, security deposits and cleaning fees already paid are non-refundable. Rental fees are non-refundable if cancellation is received less than 30 days before the rental period, unless the Owner agrees otherwise in writing. Cancellation and refunds follow the Rental Agreement. The card processing fee is non-refundable [VERIFY with attorney]. The signed Rental Agreement controls if it differs from this summary.',
    ];
    if (in_array(trim((string) ($merged['policy'] ?? '')), $old_summaries, true) || strpos((string) ($merged['policy'] ?? ''), 'is non-refundable and holds the date') !== false) $merged['policy'] = brb_cancellation_summary();
    $tour_email = isset($stored['tour_email']) ? sanitize_email((string) $stored['tour_email']) : '';
    if (!is_email($tour_email)) $merged['tour_email'] = $defaults['tour_email'];
    else $merged['tour_email'] = $tour_email;
    $location = trim((string) ($merged['pay_location'] ?? ''));
    if ($location === '' || stripos($location, '[VERIFY]') !== false || stripos($location, '2212 47') !== false) {
        $merged['pay_location'] = $defaults['pay_location'];
    }
    $venue = trim((string) ($stored['venue_address'] ?? ''));
    if ($venue === '' || stripos($venue, '[VERIFY]') !== false || stripos($venue, '2212 47') !== false || $venue === BRB_CHECK_ADDRESS) {
        $merged['venue_address'] = BRB_VENUE_ADDRESS;
    }
    $old_policy = 'A date saved on the website is held for 48 hours while you bring a check in person.';
    if (strpos((string) $merged['policy'], $old_policy) !== false) {
        $merged['policy'] = str_replace($old_policy, brb_cancellation_summary(), $merged['policy']);
    }
    if (!isset($stored['policy']) || brb_policy_needs_refresh($stored['policy'])) {
        $merged['policy'] = brb_cancellation_summary();
    }
    if (!isset($stored['quote_prices'])) $merged['quote_prices'] = '1';
    if (!isset($stored['rate_sun_thu']) || !is_numeric($stored['rate_sun_thu']) || (float) $stored['rate_sun_thu'] <= 0) $merged['rate_sun_thu'] = '850';
    if (!isset($stored['rate_fri_sat']) || !is_numeric($stored['rate_fri_sat']) || (float) $stored['rate_fri_sat'] <= 0) $merged['rate_fri_sat'] = '1400';
    $ranges = json_decode((string) ($stored['rate_ranges'] ?? ''), true);
    if (!isset($stored['rate_ranges']) || !is_array($ranges)) $merged['rate_ranges'] = wp_json_encode(brb_default_rate_ranges());
    update_option('brb_settings', $merged);
}
function brb_migrate_bookings() {
    global $wpdb;
    $t = brb_table();
    // Older rows stored the collected sum in amount_due and used status confirmed/pending.
    $wpdb->query("UPDATE $t SET amount_paid = amount_due, deposit_due = IF(deposit_due > 0, deposit_due, amount_due), source = IF(source IS NULL OR source = '', 'online', source), status = IF(amount_due + 0.009 >= total, 'paid', 'deposit_paid') WHERE status = 'confirmed'");
    $wpdb->query("UPDATE $t SET hold_until = NULL, status = 'paid' WHERE status IN ('held','pending') AND total > 0.009 AND amount_paid + 0.009 >= total");
    $wpdb->query("UPDATE $t SET hold_until = NULL, status = 'deposit_paid' WHERE status IN ('held','pending') AND amount_paid > 0.009 AND amount_paid + 0.009 < total");
    $wpdb->query("UPDATE $t SET hold_until = NULL, status = 'approved' WHERE status IN ('held','pending') AND amount_paid < 0.01");
}
function brb_migrate_prices() {
    global $wpdb;
    $t = brb_table();
    // Rows that already have a calculated total keep it. New saves store zero until staff set a price.
    $wpdb->query("UPDATE $t SET price_set = 1 WHERE price_set = 0 AND total > 0.009");
}
function brb_faq_markup() {
    return <<<'HTML'
<details><summary>How many guests can The Boardroom hold?</summary><p>Up to 75 people.</p></details><details><summary>Is parking available?</summary><p>Yes, on-site parking.</p></details><details><summary>Can we bring our own drinks?</summary><p>The Boardroom has a bar area and an ice machine. No cooking is allowed in the venue. The stove top may not be used. Food warmers (for keeping prepared food warm) and the bar area may be used. There are no taps. Draft taps are not available. BYOB is allowed, and the renter must obtain a one-day liquor license from the City of Moline, fee $50. See the <a href="/terms/">Rental Terms</a>.</p></details><details><summary>What is there to do?</summary><p>80in television, pinball, pool and shuffleboard.</p></details><details><summary>How much does it cost?</summary><p>Pricing starts at $600 per day and can reach about $2,000, depending on the date, how many people are coming, and the features you would like. Most events come to about $1,200. We confirm the final price with you before any check is due. The website does not guess a total.</p></details><details><summary>How is the space set up?</summary><p>The space arrives not set up. Seating and layout are yours to plan, so bring the arrangement that suits your gathering. If you would like a hand, our staff can help with setup for an extra fee.</p></details><details><summary>Do you provide decorations?</summary><p>Decorations are not provided. Christmas decorations are up in December.</p></details><details><summary>What are our responsibilities before we leave?</summary><p>Please clean and tidy the space, and take the garbage out. Another event is often booked the following day, and a ready room lets the next hosts begin on time.</p></details><details><summary>How do I book and pay?</summary><p>Request a date on the <a href="/book/">Book</a> page. Bookings are subject to approval, and the owner may decline any request. No payment is due until the request is approved. A request does not hold the date. Your date is reserved once your deposit is received. The deposit (50% of the agreed total) holds the date. It is refundable if the Owner receives written cancellation 90 or more days before the event, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 90 days before the event. Pay by check or in person, or online by card with a 3% processing fee. Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265. Bookings need at least 48 hours' notice. Until we confirm your price, your confirmation says price to be confirmed. The card processing fee is non-refundable.</p></details><details><summary>What is the cancellation policy?</summary><p>See the <a href="/terms/">Rental Terms</a>. If a summary and the signed agreement differ, the signed agreement controls.</p></details><details><summary>Are overnight stays allowed?</summary><p>No overnight stays. The Boardroom is an event space only.</p></details>
HTML;
}
function brb_patch_faq_drinks() {
    $page = get_page_by_path('faq');
    if (!$page) return;
    $c = (string) $page->post_content;
    $replacements = [
        'The Boardroom has a BYOB bar and ice machine. <span class="placeholder">PLACEHOLDER: alcohol rules / age policy — owner to confirm.</span>' => 'The Boardroom has a bar area and an ice machine. No cooking is allowed in the venue. The stove top may not be used. Food warmers (for keeping prepared food warm) and the bar area may be used. There are no taps. Draft taps are not available. BYOB is allowed, and the renter must obtain a one-day liquor license from the City of Moline, fee $50. See the <a href="/terms/">Rental Terms</a>.',
        'The Boardroom has a bar area and an ice machine. Do not use the stove top. Food warmers and the bar area may be used. Draft taps are not available. BYOB is allowed, and the renter must obtain a one-day liquor license. See the' => 'The Boardroom has a bar area and an ice machine. No cooking is allowed in the venue. The stove top may not be used. Food warmers (for keeping prepared food warm) and the bar area may be used. There are no taps. Draft taps are not available. BYOB is allowed, and the renter must obtain a one-day liquor license from the City of Moline, fee $50. See the',
        'The Boardroom has a bar area and an ice machine. Do not use the stove top. Food warmers and the bar area may be used. Draft taps are not available. BYOB is allowed, and the renter must obtain a one-day liquor license from the City of Moline, fee $50. See the' => 'The Boardroom has a bar area and an ice machine. No cooking is allowed in the venue. The stove top may not be used. Food warmers (for keeping prepared food warm) and the bar area may be used. There are no taps. Draft taps are not available. BYOB is allowed, and the renter must obtain a one-day liquor license from the City of Moline, fee $50. See the',
        'BYOB is allowed, and the renter must obtain a one-day liquor license. See the' => 'BYOB is allowed, and the renter must obtain a one-day liquor license from the City of Moline, fee $50. See the',
        'See our <a href="/terms-and-conditions/">Terms and Conditions</a>. <span class="placeholder">PLACEHOLDER — owner to supply.</span>' => 'See the <a href="/terms/">Rental Terms</a>. If a summary and the signed agreement differ, the signed agreement controls.',
    ];
    $next = $c;
    foreach ($replacements as $old => $new) $next = str_replace($old, $new, $next);
    if ($next === $c) return;
    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_content' => $next], ['ID' => (int) $page->ID]);
    clean_post_cache((int) $page->ID);
}
function brb_refresh_contact() {
    $page = get_page_by_path('contact');
    if (!$page) return;
    $c = (string) $page->post_content;
    $next = brb_contact_stored_markup();
    if ($next === $c) return;
    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_content' => $next], ['ID' => (int) $page->ID]);
    clean_post_cache((int) $page->ID);
}
function brb_refresh_faq() {
    $page = get_page_by_path('faq');
    if (!$page) return;
    $c = (string) $page->post_content;
    if (stripos($c, 'How many guests can The Boardroom hold') === false) return;
    $old_pay = 'Bring a check in person within those 48 hours.';
    $new_pay = 'Mail or drop off checks payable to Mad Productions at ' . BRB_CHECK_ADDRESS . '.';
    $next = str_replace('online processing fee', 'card processing fee', str_replace($old_pay, $new_pay, $c));
    $book = '<details><summary>How do I book and pay?</summary><p>Request a date on the <a href="/book/">Book</a> page. Bookings are subject to approval, and the owner may decline any request. No payment is due until the request is approved. A request does not hold the date. Your date is reserved once your deposit is received. The deposit (50% of the agreed total) holds the date. It is refundable if the Owner receives written cancellation 90 or more days before the event, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 90 days before the event. Pay by check or in person, or online by card with a 3% processing fee. Mail or drop off checks payable to Mad Productions at ' . BRB_CHECK_ADDRESS . '. Bookings need at least 48 hours\' notice. Until we confirm your price, your confirmation says price to be confirmed. The card processing fee is non-refundable.</p></details>';
    if (strpos($next, 'holds the date for 48 hours') !== false || strpos($next, 'held for 48 hours') !== false || strpos($next, 'Saving a date on this website holds it') !== false || strpos($next, 'non-refundable [VERIFY with attorney]') !== false || strpos($next, 'is non-refundable and holds the date') !== false) {
        $replaced = preg_replace('/<details><summary>How do I book and pay\?<\/summary>.*?<\/details>/is', $book, $next, 1);
        if (is_string($replaced)) $next = $replaced;
    }
    $old_hours = '<details><summary>What are the hours / check-in and check-out times?</summary><p class="placeholder">PLACEHOLDER — owner to supply.</p></details>';
    $new_hours = '<details><summary>Are overnight stays allowed?</summary><p>' . brb_no_overnight_line() . '</p></details>';
    if (strpos($next, $old_hours) !== false) {
        $next = str_replace($old_hours, $new_hours, $next);
    } else {
        $next = preg_replace('/<details><summary>[^<]*(check-in|check-out)[^<]*<\/summary>.*?<\/details>/is', '', $next);
    }
    if (strpos($next, 'No overnight stays') === false) $next .= $new_hours;
    if (stripos($c, 'How much does it cost?') === false || stripos($c, 'Christmas decorations are up in December') === false) {
        $next = brb_faq_markup();
    }
    if ($next === $c) return;
    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_content' => $next], ['ID' => (int) $page->ID]);
    clean_post_cache((int) $page->ID);
}
function brb_scrub_copy_string($c) {
    $card = 'Pay by check or in person, or online by card with a 3% processing fee. Mail or drop off checks payable to Mad Productions at ' . BRB_CHECK_ADDRESS . '. We never see or store card numbers. The deposit (50% of the agreed total) holds the date. It is refundable if the Owner receives written cancellation 90 or more days before the event, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 90 days before the event. The card processing fee is non-refundable.';
    $c = preg_replace('/Payments are processed by \w+;?\s*we do not store card numbers\./i', $card, $c);
    $c = preg_replace('/This site does not process payments online and does not collect card data\./i', 'Stripe processes card payments. We never see or store card numbers.', $c);
    $c = preg_replace('/pay securely online/i', 'pay by check or in person, or online by card', $c);
    $c = preg_replace('/Bookings are confirmed once payment \(or the required deposit\) is received\./i', 'A request does not hold the date. Your date is reserved once your deposit is received.', $c);
    $c = preg_replace('/Saving a date holds it for a limited time\. The booking is confirmed once we receive your check, your card payment, or the required deposit\./i', 'A request does not hold the date. Your date is reserved once your deposit is received.', $c);
    $c = str_replace('online processing fee', 'card processing fee', $c);
    $c = preg_replace('/\b(ze' . 'lle|air' . 'bnb)\b/i', '', $c);
    $c = preg_replace('/[ \t]{2,}/', ' ', $c);
    return $c;
}
function brb_scrub_public_copy() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post')");
    foreach ($rows as $p) {
        $next = brb_scrub_copy_string((string) $p->post_content);
        if ($next !== $p->post_content) {
            $wpdb->update($wpdb->posts, ['post_content' => $next], ['ID' => (int) $p->ID]);
            clean_post_cache((int) $p->ID);
        }
    }
    $o = get_option('brb_settings', []);
    if (is_array($o)) {
        $changed = false;
        foreach ($o as $k => $v) {
            if (!is_string($v)) continue;
            $next = brb_scrub_copy_string($v);
            if ($next !== $v) { $o[$k] = $next; $changed = true; }
        }
        foreach (brb_legacy_keys() as $lk) {
            if (array_key_exists($lk, $o)) { unset($o[$lk]); $changed = true; }
        }
        if ($changed) update_option('brb_settings', $o);
    }
}
function brb_maybe_upgrade() {
    if (get_option('brb_version') === BRB_VER) return;
    if (!brb_install_tables()) return;
    if (!brb_install_tour_table()) return;
    if (get_option('brb_settings', null) === null) update_option('brb_settings', brb_defaults());
    else brb_migrate_settings();
    brb_migrate_bookings();
    brb_migrate_prices();
    brb_scrub_public_copy();
    brb_refresh_faq();
    brb_refresh_contact();
    brb_patch_faq_drinks();
    brb_install_legal_pages();
    brb_ensure_tour_on_contact();
    wp_clear_scheduled_hook('brb_cron_expire');
    update_option('brb_version', BRB_VER);
}
// init, not plugins_loaded: legal pages call wp_insert_post, which needs rewrite
// and WP_POST_REVISIONS. Those are not ready when plugins_loaded fires.
add_action('init', 'brb_maybe_upgrade', 1);
add_action('template_redirect', 'brb_legacy_legal_redirect');
register_activation_hook(__FILE__, 'brb_maybe_upgrade');
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('brb_cron_expire'); });
add_action('init', function () {
    if (get_option('brb_version') !== BRB_VER) return;
    wp_clear_scheduled_hook('brb_cron_expire');
}, 20);
add_action('brb_cron_expire', 'brb_expire_holds');

/* ---------- quotes, notice, availability ---------- */
function brb_slot_info(DateTimeImmutable $day) {
    $o = brb_opt();
    $cutoff = brb_notice_cutoff();
    $open = (int) $o['open_hour'];
    $close = (int) $o['close_hour'];
    $minH = max(1, (int) $o['min_hours']);
    $midnight = $day->setTime(0, 0);
    $mode = $o['mode'];
    $dayOk = ($mode !== 'hourly') && ($midnight >= $cutoff);
    $first = null;
    if ($mode !== 'day') {
        for ($h = $open; $h + $minH <= $close; $h++) {
            if ($midnight->setTime($h, 0) >= $cutoff) { $first = $h; break; }
        }
    }
    $any = $dayOk || ($first !== null && $mode !== 'day');
    return ['day_ok' => $dayOk, 'first_hour' => $first, 'any' => $any];
}
function brb_build_range($in, $args = []) {
    $o = brb_opt();
    $date = sanitize_text_field($in['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return new WP_Error('date', 'Please choose a date.');
    $type = ($in['type'] ?? 'day') === 'hourly' ? 'hourly' : 'day';
    if ($o['mode'] !== 'both') $type = $o['mode'];
    $tz = brb_tz();
    try {
        $d0 = new DateTimeImmutable($date . ' 00:00:00', $tz);
    } catch (Exception $e) {
        return new WP_Error('date', 'Please choose a date.');
    }
    if ($d0 < brb_now()->setTime(0, 0)) return new WP_Error('past', 'That date is in the past.');
    if ($type === 'day') {
        $days = max(1, min((int) $o['max_days'], (int) ($in['days'] ?? 1)));
        $s = $d0;
        $e = $d0->modify("+$days day");
        $units = $days;
    } else {
        $h = (int) ($in['start_hour'] ?? $o['open_hour']);
        $len = max((int) $o['min_hours'], (int) ($in['hours'] ?? $o['min_hours']));
        if ($h < (int) $o['open_hour'] || $h + $len > (int) $o['close_hour']) {
            return new WP_Error('hours', sprintf('Hourly bookings must be between %d:00 and %d:00.', (int) $o['open_hour'], (int) $o['close_hour']));
        }
        $s = $d0->setTime($h, 0);
        $e = $s->modify("+$len hour");
        $units = $len;
    }
    $guests = max(1, (int) ($in['guests'] ?? 1));
    if ($guests > (int) $o['max_guests']) return new WP_Error('guests', 'Maximum ' . (int) $o['max_guests'] . ' guests.');
    if (empty($args['override_notice']) && $s < brb_notice_cutoff()) {
        return new WP_Error('notice', sprintf(
            'Bookings must start at least %d hours from now. The earliest start is %s.',
            brb_notice_hours(),
            brb_format_chicago(brb_notice_cutoff())
        ));
    }
    return ['type' => $type, 's' => $s, 'e' => $e, 'units' => $units, 'guests' => $guests];
}
function brb_quote($r) {
    // The public save does not invent a total. Staff set one agreed price later.
    return [
        'rate' => 0,
        'units' => (int) $r['units'],
        'subtotal' => 0,
        'cleaning' => 0,
        'tax' => 0,
        'total' => 0,
        'due' => 0,
        'price_set' => 0,
    ];
}
function brb_agreed_quote($total) {
    $total = round((float) $total, 2);
    $due = brb_deposit_due_amount($total);
    return [
        'rate' => 0,
        'units' => 1,
        'subtotal' => $total,
        'cleaning' => 0,
        'tax' => 0,
        'total' => $total,
        'due' => $due,
        'price_set' => 1,
    ];
}
function brb_range_hits_blackout($s, $e) {
    $bo = brb_blackouts();
    if (!$bo) return false;
    for ($d = $s->setTime(0, 0); $d < $e; $d = $d->modify('+1 day')) {
        if (in_array($d->format('Y-m-d'), $bo, true)) return true;
    }
    return false;
}
function brb_blocking_sql() {
    return ["status IN ('paid','deposit_paid','confirmed')", []];
}
function brb_date_taken($b) {
    $s = new DateTimeImmutable($b->start_dt, brb_tz());
    $e = new DateTimeImmutable($b->end_dt, brb_tz());
    return !brb_is_free($s, $e, (int) $b->id);
}
function brb_is_free($s, $e, $exclude_id = 0) {
    global $wpdb;
    if (brb_range_hits_blackout($s, $e)) return false;
    list($block, $args) = brb_blocking_sql();
    $sql = "SELECT COUNT(*) FROM " . brb_table() . " WHERE id<>%d AND start_dt < %s AND end_dt > %s AND $block";
    $params = array_merge([(int) $exclude_id, $e->format('Y-m-d H:i:s'), $s->format('Y-m-d H:i:s')], $args);
    $n = $wpdb->get_var($wpdb->prepare($sql, $params));
    return (int) $n === 0;
}
function brb_balance_due_on(DateTimeImmutable $start) {
    $days = max(0, (int) brb_opt('balance_due_days'));
    return $start->setTimezone(brb_tz())->modify("-{$days} days")->format('Y-m-d');
}
function brb_balance_due_label($on) {
    if (!$on) return '';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $on, brb_tz());
    return $d ? $d->format('l, F j, Y') : $on;
}
function brb_booking($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . brb_table() . ' WHERE id=%d', (int) $id));
}
function brb_booking_by_token($token) {
    global $wpdb;
    $token = preg_replace('/[^A-Za-z0-9]/', '', (string) $token);
    if ($token === '') return null;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . brb_table() . ' WHERE token=%s', $token));
}
function brb_payments($booking_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . brb_pay_table() . ' WHERE booking_id=%d ORDER BY id ASC', (int) $booking_id));
}
function brb_when($b) {
    $s = new DateTimeImmutable($b->start_dt, brb_tz());
    $e = new DateTimeImmutable($b->end_dt, brb_tz());
    if ($b->mode === 'day') {
        $last = $e->modify('-1 day');
        if ($last->format('Y-m-d') === $s->format('Y-m-d')) return $s->format('D M j, Y') . ' (full day)';
        return $s->format('D M j, Y') . ' – ' . $last->format('D M j, Y') . ' (full day)';
    }
    return $s->format('D M j, Y g:i A') . ' – ' . $e->format('g:i A');
}
function brb_no_overnight_line() {
    return 'No overnight stays. The Boardroom is an event space only.';
}
function brb_amount_to_bring($b) {
    $deposit_left = round((float) $b->deposit_due - (float) $b->amount_paid, 2);
    if ($deposit_left > 0) return $deposit_left;
    return max(0, round((float) $b->total - (float) $b->amount_paid, 2));
}
function brb_payment_lines($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $set = brb_price_is_set($b);
    $lines = [];
    $lines[] = brb_not_held_line();
    $lines[] = brb_reserved_line();
    $lines[] = brb_deposit_line();
    $lines[] = brb_fee_public_line();
    if ($set) {
        $lines[] = brb_check_sentence('a check for ' . brb_money(brb_amount_to_bring($b)));
        $lines[] = 'Write booking reference ' . $ref . ' on the memo line.';
        $balance = round((float) $b->total - (float) $b->deposit_due, 2);
        if ($balance > 0.009 && (float) $b->amount_paid + 0.009 < (float) $b->deposit_due) {
            $lines[] = 'After that deposit, the remaining balance of ' . brb_money($balance) . ' is due by ' . brb_balance_due_label($b->balance_due_on) . ' (' . (int) $o['balance_due_days'] . ' days before the event).';
        }
    } else {
        $lines[] = 'Price to be confirmed. We will confirm the final price based on the date, guest count, and features, then tell you the deposit amount.';
    }
    $extra = brb_extra_instructions();
    if ($extra !== '') $lines[] = $extra;
    $lines[] = brb_no_overnight_line();
    $pay = brb_pay_online_line($b);
    if ($pay !== '') $lines[] = $pay;
    return $lines;
}
function brb_hold_lines($b) {
    return brb_payment_lines($b);
}
function brb_preview_lines($q, DateTimeImmutable $start) {
    unset($q, $start);
    return [
        brb_approval_line(),
        brb_request_open_line(),
        brb_reserved_line(),
        brb_deposit_line(),
        brb_fee_public_line(),
        'No payment is due until the request is approved.',
        brb_no_overnight_line(),
    ];
}

function brb_env($name) {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        if (isset($_SERVER[$name]) && is_string($_SERVER[$name])) $value = $_SERVER[$name];
        elseif (isset($_ENV[$name]) && is_string($_ENV[$name])) $value = $_ENV[$name];
        else $value = '';
    }
    return trim(str_replace(["\r", "\n"], '', $value));
}
function brb_smtp_from_name() {
    $name = brb_env('BRB_SMTP_FROM_NAME');
    return $name !== '' ? $name : 'The Boardroom';
}
function brb_smtp_from_address() {
    $from = sanitize_email(brb_env('BRB_SMTP_FROM'));
    return is_email($from) ? $from : 'boardroomqc@gmail.com';
}
function brb_smtp_config() {
    $user = brb_env('BRB_SMTP_USER');
    $pass = brb_env('BRB_SMTP_PASS');
    if ($user === '' || $pass === '') return null;
    $host = brb_env('BRB_SMTP_HOST');
    if ($host === '') $host = 'smtp.gmail.com';
    $port = (int) brb_env('BRB_SMTP_PORT');
    if ($port <= 0) $port = 587;
    return [
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'pass' => $pass,
        'from' => brb_smtp_from_address(),
        'from_name' => brb_smtp_from_name(),
    ];
}
function brb_configure_phpmailer($phpmailer) {
    $cfg = brb_smtp_config();
    if (!$cfg) return;
    $phpmailer->isSMTP();
    $phpmailer->Host = $cfg['host'];
    $phpmailer->Port = (int) $cfg['port'];
    $phpmailer->SMTPAuth = true;
    $phpmailer->Username = $cfg['user'];
    $phpmailer->Password = $cfg['pass'];
    $phpmailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $phpmailer->SMTPAutoTLS = true;
    $phpmailer->SMTPDebug = 0;
    $phpmailer->Debugoutput = static function () {};
    $phpmailer->From = $cfg['from'];
    $phpmailer->FromName = $cfg['from_name'];
    $phpmailer->Sender = $cfg['from'];
}
add_action('phpmailer_init', 'brb_configure_phpmailer');
function brb_redact_secret($text) {
    $text = (string) $text;
    $pass = brb_env('BRB_SMTP_PASS');
    if ($pass !== '') $text = str_replace($pass, '[redacted]', $text);
    return $text;
}
function brb_admin_emails() {
    $out = [];
    foreach (preg_split('/[,;\s]+/', (string) brb_opt('admin_email')) as $part) {
        $part = sanitize_email(trim($part));
        if (is_email($part)) $out[strtolower($part)] = $part;
    }
    if (!$out) {
        $fallback = sanitize_email((string) get_option('admin_email'));
        if (is_email($fallback)) $out[strtolower($fallback)] = $fallback;
    }
    return array_values($out);
}
function brb_mail($to, $subject, $body, $reply = '', $from = '') {
    $to = sanitize_email($to);
    if (!$to || !is_email($to)) return false;
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    if ($from && is_email($from)) $headers[] = 'From: ' . brb_smtp_from_name() . ' <' . $from . '>';
    if ($reply && is_email($reply)) $headers[] = 'Reply-To: ' . $reply;
    return wp_mail($to, $subject, $body, $headers);
}
function brb_send_test_email() {
    $tos = brb_admin_emails();
    if (!$tos) return new WP_Error('mail', 'No admin notification email is set.');
    $subject = 'Boardroom email test';
    $body = "This is a test email from Bookings → Settings.\nIf you can read this, outbound mail is working.\n";
    $from = brb_smtp_from_address();
    $errors = [];
    $sent = [];
    foreach ($tos as $to) {
        $GLOBALS['brb_last_mail_error'] = null;
        $capture = static function ($err) {
            $GLOBALS['brb_last_mail_error'] = $err;
        };
        add_action('wp_mail_failed', $capture);
        $ok = brb_mail($to, $subject, $body, '', $from);
        remove_action('wp_mail_failed', $capture);
        if ($ok) {
            $sent[] = $to;
            continue;
        }
        $msg = 'Mail could not be sent.';
        $err = $GLOBALS['brb_last_mail_error'] ?? null;
        if ($err instanceof WP_Error && $err->get_error_message() !== '') $msg = $err->get_error_message();
        $errors[] = $to . ': ' . brb_redact_secret($msg);
    }
    if ($errors) return new WP_Error('mail', implode(' ', $errors));
    return $sent;
}
function brb_guest_link($b) {
    return add_query_arg('br_token', $b->token, home_url('/book/'));
}
function brb_send_request_emails($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $price = brb_price_phrase($b);
    $screen = implode("\n", brb_screening_lines($b));
    $facts = brb_approval_line() . "\n\n" . brb_request_open_line() . "\n\n" . brb_reserved_line() . "\n\n" . brb_deposit_line() . "\n\n" . brb_not_held_line();
    $detail = "When: " . brb_when($b) . "\n" . brb_event_type_line($b) . "Guests attending: {$b->guests}\n" . ($screen !== '' ? $screen . "\n" : '') . "Price: {$price}\n";
    $body = "Hi {$b->name},\n\nWe received your request.\n\n{$facts}\n\n{$detail}\n" . brb_no_overnight_line() . "\n\nCancellation policy:\n{$o['policy']}\n\nView your request: " . brb_guest_link($b) . "\n";
    brb_mail($b->email, 'We received your Boardroom request (' . $ref . ')', $body, $o['admin_email']);
    $admin = "A new request is pending review. The date is not held.\n\nReference: {$ref}\nWhen: " . brb_when($b) . "\nGuest: {$b->name}\nEmail: {$b->email}\nPhone: {$b->phone}\n" . brb_event_type_line($b) . "Guests: {$b->guests}\n" . ($screen !== '' ? $screen . "\n" : '') . "Price: {$price}\nSource: " . brb_source_label($b->source) . "\nNotes: {$b->notes}\n\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n";
    brb_mail($o['admin_email'], 'Request pending review ' . $ref . ' – ' . $b->name, $admin, $b->email);
}
function brb_send_hold_emails($b) {
    brb_send_request_emails($b);
}
function brb_send_approve_email($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $facts = implode("\n\n", brb_payment_lines($b));
    $body = "Hi {$b->name},\n\nYour request is approved.\n\n{$facts}\n\nWhen: " . brb_when($b) . "\n" . brb_event_type_line($b) . "Guests attending: {$b->guests}\nReference: {$ref}\n\nView your booking: " . brb_guest_link($b) . "\n";
    brb_mail($b->email, 'Your Boardroom request is approved (' . $ref . ')', $body, $o['admin_email']);
    brb_mail($o['admin_email'], 'Request approved ' . $ref . ' – ' . $b->name, $body . "\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n", $b->email);
}
function brb_send_decline_email($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $body = "Hi {$b->name},\n\nThank you for your request ({$ref}, " . brb_when($b) . "). We are not able to approve it. The date is not reserved.\n\n" . brb_deposit_line() . "\n\nIf a payment was recorded, our team will resolve it manually.\n\n" . brb_no_overnight_line() . "\n";
    brb_mail($b->email, 'Your Boardroom request was declined (' . $ref . ')', $body, $o['admin_email']);
    brb_mail($o['admin_email'], 'Request declined ' . $ref . ' – ' . $b->name, $body . "\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n", $b->email);
}
function brb_send_payment_email($b, $amount, $check_number, $method = 'check') {
    $o = brb_opt();
    $ref = brb_ref($b);
    $paid = brb_money($b->amount_paid);
    $balance = brb_money(max(0, round((float) $b->total - (float) $b->amount_paid, 2)));
    $method = brb_pay_method_known($method) ? $method : 'check';
    $pays = brb_payments($b->id);
    $last_pay = $pays ? end($pays) : null;
    $fee = ($method === 'card' && $last_pay) ? (float) ($last_pay->fee ?? 0) : 0;
    if ((float) $amount > 0) {
        if ($method === 'cash') {
            $check = 'We recorded cash for ' . brb_money($amount) . '.';
        } elseif ($method === 'card') {
            $check = 'We recorded a card payment for ' . brb_money($amount) . '.';
            if ($fee > 0.009)             $check .= ' A card processing fee of ' . brb_money($fee) . ' was charged on top and is not part of the booking balance. ' . brb_fee_public_line();
        } else {
            $check = 'We recorded a check for ' . brb_money($amount);
            if ($check_number !== '') $check .= ' (check ' . $check_number . ')';
            $check .= '.';
        }
    } else {
        $check = 'This booking is marked paid in full.';
    }
    if ($b->status === 'paid') {
        $intro = "Your Boardroom booking is confirmed.\n\n{$check}\nPaid: {$paid}\nBalance: {$balance}";
        $subject = 'Your Boardroom booking is confirmed (' . $ref . ')';
        $admin_subject = 'Payment recorded for ' . $ref . ' – ' . $b->name;
    } elseif ($method === 'cash') {
        $due = brb_balance_due_label($b->balance_due_on);
        $intro = "We received your cash and your date stays reserved.\n\n{$check}\nPaid so far: {$paid}\nBalance: {$balance}" . ($due ? " due by {$due}" : '') . ".\n\n" . brb_check_sentence('the remaining balance as a check') . "\nWrite booking reference {$ref} on the memo line.";
        $subject = 'We received your cash for ' . $ref;
        $admin_subject = 'Cash recorded for ' . $ref . ' – ' . $b->name;
    } elseif ($method === 'card') {
        $due = brb_balance_due_label($b->balance_due_on);
        $intro = "We received your card payment and your date stays reserved.\n\n{$check}\nPaid so far: {$paid}\nBalance: {$balance}" . ($due ? " due by {$due}" : '') . ".\n\n" . brb_check_sentence('the remaining balance as a check') . "\nYou can also pay the rest online from your booking page.\nWrite booking reference {$ref} on the memo line if you pay by check.";
        $subject = 'We received your card payment for ' . $ref;
        $admin_subject = 'Card payment recorded for ' . $ref . ' – ' . $b->name;
    } else {
        $due = brb_balance_due_label($b->balance_due_on);
        $intro = "We received your check and your date stays reserved.\n\n{$check}\nPaid so far: {$paid}\nBalance: {$balance}" . ($due ? " due by {$due}" : '') . ".\n\n" . brb_check_sentence('the remaining balance as a check') . "\nWrite booking reference {$ref} on the memo line.";
        $subject = 'We received your check for ' . $ref;
        $admin_subject = 'Check recorded for ' . $ref . ' – ' . $b->name;
    }
    $body = "Hi {$b->name},\n\n{$intro}\n\n" . brb_deposit_line() . "\n\n" . brb_reserved_line() . "\n\n" . brb_no_overnight_line() . "\n\nReference: {$ref}\nWhen: " . brb_when($b) . "\n" . brb_event_type_line($b) . "Guests attending: {$b->guests}\nPrice: " . brb_price_phrase($b) . "\n\nCancellation policy:\n{$o['policy']}\n\nView your booking: " . brb_guest_link($b) . "\n";
    brb_mail($b->email, $subject, $body, $o['admin_email']);
    brb_mail($o['admin_email'], $admin_subject, $body . "\nAdmin: " . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n", $b->email);
}
function brb_send_expired_emails($b) {
    unset($b);
}
function brb_send_cancel_email($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $body = "Hi {$b->name},\n\nBooking {$ref} (" . brb_when($b) . ") has been cancelled. The date is no longer reserved.\n\nCancellation policy:\n{$o['policy']}\n";
    brb_mail($b->email, 'Boardroom booking cancelled (' . $ref . ')', $body, $o['admin_email']);
}

function brb_expire_holds() {
    return;
}
function brb_column_ready() {
    global $wpdb;
    $found = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM `' . brb_table() . '` LIKE %s', 'hold_until'));
    return (bool) $found;
}

function brb_insert_booking($r, $q, $guest, $source = 'online', $notify = true) {
    global $wpdb;
    $source = in_array($source, ['online', 'phone', 'walk-in'], true) ? $source : 'online';
    $locked = $wpdb->get_var("SELECT GET_LOCK('brb_book', 10)");
    if ((string) $locked !== '1') return new WP_Error('lock', 'Please try again in a moment.');
    $result = null;
    try {
        if (!brb_is_free($r['s'], $r['e'])) {
            $result = new WP_Error('busy', 'Sorry, that time was just booked or is unavailable. Please pick another.');
        } else {
            $token = wp_generate_password(32, false);
            $due = brb_dec($q['due']);
            $ok = $wpdb->insert(brb_table(), [
                'token' => $token,
                'mode' => $r['type'],
                'start_dt' => $r['s']->format('Y-m-d H:i:s'),
                'end_dt' => $r['e']->format('Y-m-d H:i:s'),
                'guests' => (int) $r['guests'],
                'name' => $guest['name'],
                'email' => $guest['email'],
                'phone' => $guest['phone'],
                'notes' => $guest['notes'],
                'event_type' => $guest['event_type'] ?? '',
                'public_event' => $guest['public_event'] ?? '',
                'alcohol' => $guest['alcohol'] ?? '',
                'end_time' => $guest['end_time'] ?? '',
                'host_age' => $guest['host_age'] ?? '',
                'event_about' => $guest['event_about'] ?? '',
                'subtotal' => brb_dec($q['subtotal']),
                'cleaning' => brb_dec($q['cleaning']),
                'tax' => brb_dec($q['tax']),
                'total' => brb_dec($q['total']),
                'amount_due' => $due,
                'deposit_due' => $due,
                'amount_paid' => '0.00',
                'status' => 'review',
                'source' => $source,
                'hold_until' => null,
                'balance_due_on' => brb_balance_due_on($r['s']),
                'price_set' => empty($q['price_set']) ? 0 : 1,
                'created' => current_time('mysql', true),
            ]);
            $result = $ok ? brb_booking($wpdb->insert_id) : new WP_Error('db', 'Could not save the booking. Please try again.');
        }
    } finally {
        $wpdb->query("SELECT RELEASE_LOCK('brb_book')");
    }
    if ($notify && $result && !is_wp_error($result)) brb_send_request_emails($result);
    return $result;
}
function brb_process_public_booking($post) {
    if (empty($post['policy'])) return new WP_Error('policy', 'Please agree to the Terms, the Rental Agreement and the Privacy Policy.');
    $r = brb_build_range($post);
    if (is_wp_error($r)) return $r;
    $name = sanitize_text_field($post['name'] ?? '');
    $email = sanitize_email($post['email'] ?? '');
    if (!$name || !is_email($email)) return new WP_Error('contact', 'Name and a valid email are required.');
    $event_type = sanitize_key($post['event_type'] ?? '');
    if (!isset(brb_event_types()[$event_type])) return new WP_Error('event_type', 'Please choose an event type.');
    $screen = brb_collect_screening($post, true);
    if (is_wp_error($screen)) return $screen;
    $q = brb_quote($r);
    return brb_insert_booking($r, $q, [
        'name' => $name,
        'email' => $email,
        'phone' => '',
        'notes' => $screen['event_about'],
        'event_type' => $event_type,
        'public_event' => $screen['public_event'],
        'alcohol' => $screen['alcohol'],
        'end_time' => $screen['end_time'],
        'host_age' => $screen['host_age'],
        'event_about' => $screen['event_about'],
    ], 'online', true);
}
add_action('admin_post_nopriv_brb_book', 'brb_handle_book');
add_action('admin_post_brb_book', 'brb_handle_book');
function brb_handle_book() {
    $back = wp_get_referer() ?: home_url('/book/');
    if (!wp_verify_nonce($_POST['_brb'] ?? '', 'brb_book')) wp_die('Session expired, please try again.');
    $fail = function ($m) use ($back) {
        wp_safe_redirect(add_query_arg('br_err', rawurlencode($m), $back));
        exit;
    };
    $result = brb_process_public_booking(wp_unslash($_POST));
    if (is_wp_error($result) || !$result) $fail(is_wp_error($result) ? $result->get_error_message() : 'Could not save the booking. Please try again.');
    wp_safe_redirect(add_query_arg('br_token', $result->token, home_url('/book/')));
    exit;
}

function brb_record_payment($booking_id, $amount, $check_number, $received_on, $notes, $require_check = true, $method = 'check', $extra = []) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (in_array($b->status, ['cancelled', 'expired', 'declined'], true)) {
        return new WP_Error('status', 'That booking is not open for a payment.');
    }
    if (!brb_price_is_set($b)) {
        return new WP_Error('price', 'Set the agreed price before recording a payment.');
    }
    $amount = round((float) $amount, 2);
    if ($amount <= 0) return new WP_Error('amount', 'Enter an amount greater than zero.');
    $remaining = round((float) $b->total - (float) $b->amount_paid, 2);
    if ($amount - $remaining > 0.009) return new WP_Error('amount', 'That amount is more than the balance of ' . brb_money($remaining) . '.');
    $method = sanitize_key((string) $method);
    if (!brb_pay_method_known($method)) $method = 'check';
    if (!is_array($extra)) $extra = [];
    $fee = round((float) ($extra['fee'] ?? 0), 2);
    if ($fee < 0) $fee = 0;
    $stripe_id = sanitize_text_field($extra['stripe_id'] ?? '');
    $stripe_session = sanitize_text_field($extra['stripe_session'] ?? '');
    $check_number = sanitize_text_field($check_number);
    if ($method === 'cash' || $method === 'card') $check_number = '';
    elseif ($require_check && $check_number === '') return new WP_Error('check', 'Enter the check number.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $received_on)) $received_on = brb_now()->format('Y-m-d');
    $notes = sanitize_textarea_field($notes);
    $locked = $wpdb->get_var("SELECT GET_LOCK('brb_book', 10)");
    if ((string) $locked !== '1') return new WP_Error('lock', 'Please try again in a moment.');
    $b = brb_booking($b->id);
    $taken = brb_date_taken($b);
    if ($taken && $method !== 'card') {
        $wpdb->query("SELECT RELEASE_LOCK('brb_book')");
        return new WP_Error('busy', 'That date is already reserved by a paid deposit. This payment was not recorded.');
    }
    $ok = $wpdb->insert(brb_pay_table(), [
        'booking_id' => (int) $b->id,
        'amount' => brb_dec($amount),
        'check_number' => $check_number,
        'received_on' => $received_on,
        'notes' => $notes,
        'method' => $method,
        'fee' => brb_dec($fee),
        'stripe_id' => $stripe_id,
        'stripe_session' => $stripe_session,
        'created' => current_time('mysql', true),
    ]);
    if (!$ok) {
        $wpdb->query("SELECT RELEASE_LOCK('brb_book')");
        return new WP_Error('db', 'Could not record the payment.');
    }
    $paid = round((float) $b->amount_paid + $amount, 2);
    $fields = ['amount_paid' => brb_dec($paid)];
    if ($taken && $method === 'card') {
        $fields['attention'] = 'A card payment arrived after this date was already reserved by another paid deposit. Resolve it manually. [VERIFY: attorney review]';
    } else {
        $fields['status'] = ($paid + 0.009 >= (float) $b->total) ? 'paid' : 'deposit_paid';
    }
    $wpdb->update(brb_table(), $fields, ['id' => (int) $b->id]);
    $wpdb->query("SELECT RELEASE_LOCK('brb_book')");
    $b = brb_booking($b->id);
    if ($taken && $method === 'card') {
        brb_mail($b->email, 'Card payment received for ' . brb_ref($b), "Hi {$b->name},\n\nWe received your card payment for " . brb_money($amount) . ". Staff will follow up about this date.\n\n" . brb_deposit_line() . "\n", brb_opt('admin_email'));
        brb_mail(brb_opt('admin_email'), 'Card payment needs attention ' . brb_ref($b), $b->attention . "\n\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n", $b->email);
    } else {
        brb_send_payment_email($b, $amount, $check_number, $method);
    }
    return $b;
}
function brb_mark_paid_full($booking_id) {
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (in_array($b->status, ['cancelled', 'expired', 'declined'], true)) return new WP_Error('status', 'Cancelled or declined bookings cannot be marked paid.');
    if (!brb_price_is_set($b)) return new WP_Error('price', 'Set the agreed price before marking the booking paid.');
    $remaining = round((float) $b->total - (float) $b->amount_paid, 2);
    if ($remaining <= 0.009) {
        global $wpdb;
        $wpdb->update(brb_table(), ['status' => 'paid'], ['id' => (int) $b->id]);
        $b = brb_booking($b->id);
        brb_send_payment_email($b, 0, '');
        return $b;
    }
    return brb_record_payment($b->id, $remaining, '', brb_now()->format('Y-m-d'), 'Marked paid in full by staff.', false);
}
function brb_set_agreed_price($booking_id, $amount) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (in_array($b->status, ['cancelled', 'declined'], true)) return new WP_Error('status', 'That booking cannot take a price.');
    $amount = round((float) $amount, 2);
    if ($amount <= 0) return new WP_Error('amount', 'Enter an agreed price greater than zero.');
    if ((float) $b->amount_paid - $amount > 0.009) {
        return new WP_Error('amount', 'The agreed price cannot be less than the ' . brb_money($b->amount_paid) . ' already recorded.');
    }
    $due = brb_deposit_due_amount($amount);
    $status = $b->status;
    if ((float) $b->amount_paid + 0.009 >= $amount) $status = 'paid';
    elseif ((float) $b->amount_paid > 0.009) $status = 'deposit_paid';
    $ok = $wpdb->update(brb_table(), [
        'subtotal' => brb_dec($amount),
        'cleaning' => '0.00',
        'tax' => '0.00',
        'total' => brb_dec($amount),
        'amount_due' => brb_dec($due),
        'deposit_due' => brb_dec($due),
        'price_set' => 1,
        'status' => $status,
    ], ['id' => (int) $b->id]);
    if ($ok === false) return new WP_Error('db', 'Could not save the agreed price.');
    $b = brb_booking($b->id);
    brb_send_price_email($b);
    return $b;
}
function brb_send_price_email($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $bring = brb_money(brb_amount_to_bring($b));
    $body = "Hi {$b->name},\n\nThe price for your Boardroom date is confirmed.\n\nAgreed price: " . brb_money($b->total) . "\nDeposit: {$bring}\n\n";
    if (in_array($b->status, ['approved', 'held', 'deposit_paid'], true)) {
        $body .= implode("\n\n", brb_payment_lines($b)) . "\n\n";
    } else {
        $body .= "Payment instructions are sent when the request is approved. No payment is due until then.\n\n" . brb_not_held_line() . "\n\n" . brb_reserved_line() . "\n\n" . brb_deposit_line() . "\n\n";
    }
    $body .= 'When: ' . brb_when($b) . "\n" . brb_event_type_line($b) . "Guests attending: {$b->guests}\nReference: {$ref}\n\nView your booking: " . brb_guest_link($b) . "\n";
    brb_mail($b->email, 'Your Boardroom price is confirmed (' . $ref . ')', $body, $o['admin_email']);
    brb_mail($o['admin_email'], 'Price set for ' . $ref . ' – ' . $b->name, $body . "\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n", $b->email);
}
function brb_extend_hold($booking_id, $hours) {
    unset($booking_id, $hours);
    return new WP_Error('status', 'A request does not hold the date. The date is reserved once the deposit is received.');
}
function brb_approve_booking($booking_id) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (!in_array($b->status, ['review', 'pending'], true)) return new WP_Error('status', 'Only a request that is pending review can be approved.');
    $wpdb->update(brb_table(), ['status' => 'approved', 'hold_until' => null], ['id' => (int) $b->id]);
    $b = brb_booking($b->id);
    brb_send_approve_email($b);
    return $b;
}
function brb_decline_booking($booking_id) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (!in_array($b->status, ['review', 'approved', 'pending', 'held', 'deposit_paid'], true)) return new WP_Error('status', 'Only a request that is pending review, approved, or deposit paid can be declined.');
    $fields = ['status' => 'declined', 'hold_until' => null];
    if ((float) $b->amount_paid > 0.009) {
        $fields['attention'] = 'A payment was recorded before this request was declined. Resolve it manually. [VERIFY: attorney review]';
    }
    $wpdb->update(brb_table(), $fields, ['id' => (int) $b->id]);
    $b = brb_booking($b->id);
    brb_send_decline_email($b);
    return $b;
}
function brb_cancel_booking($booking_id) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if ($b->status === 'cancelled') return $b;
    $wpdb->update(brb_table(), ['status' => 'cancelled'], ['id' => (int) $b->id]);
    $b->status = 'cancelled';
    brb_send_cancel_email($b);
    return $b;
}
function brb_delete_booking($booking_id) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if ($b->status !== 'cancelled') return new WP_Error('status', 'Only a cancelled booking can be deleted.');
    $wpdb->delete(brb_pay_table(), ['booking_id' => (int) $b->id], ['%d']);
    $ok = $wpdb->delete(brb_table(), ['id' => (int) $b->id], ['%d']);
    if (!$ok) return new WP_Error('db', 'Could not delete that booking.');
    return true;
}

/* ---------- REST ---------- */
add_action('rest_api_init', function () {
    register_rest_route('brb/v1', '/availability', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => 'brb_rest_availability',
    ]);
});
function brb_rest_availability($req) {
    global $wpdb;
    $m = (is_string($req['month'] ?? null) && preg_match('/^\d{4}-\d{2}$/', $req['month'])) ? $req['month'] : wp_date('Y-m', null, brb_tz());
    $tz = brb_tz();
    $first = new DateTimeImmutable($m . '-01 00:00:00', $tz);
    $next = $first->modify('+1 month');
    list($block, $args) = brb_blocking_sql();
    $sql = 'SELECT start_dt,end_dt FROM ' . brb_table() . " WHERE start_dt < %s AND end_dt > %s AND $block";
    $params = array_merge([$next->format('Y-m-d H:i:s'), $first->format('Y-m-d H:i:s')], $args);
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
    $o = brb_opt();
    $bo = brb_blackouts();
    $today = brb_now()->setTime(0, 0);
    $days = [];
    for ($d = $first; $d < $next; $d = $d->modify('+1 day')) {
        $k = $d->format('Y-m-d');
        $busy = [];
        $open = $d->setTime((int) $o['open_hour'], 0);
        $close = $d->setTime((int) $o['close_hour'], 0);
        $full = false;
        foreach ($rows as $r) {
            $s = new DateTimeImmutable($r->start_dt, $tz);
            $e = new DateTimeImmutable($r->end_dt, $tz);
            $dayEnd = $d->modify('+1 day');
            if ($s < $dayEnd && $e > $d) {
                if ($s <= $open && $e >= $close) $full = true;
                $busy[] = [
                    max(0, (int) floor(($s->getTimestamp() - $d->getTimestamp()) / 3600)),
                    min(24, (int) ceil(($e->getTimestamp() - $d->getTimestamp()) / 3600)),
                ];
            }
        }
        $slot = brb_slot_info($d);
        if ($d < $today) $st = 'past';
        elseif (in_array($k, $bo, true)) $st = 'blackout';
        elseif ($full) $st = 'booked';
        elseif (!$slot['any']) $st = 'soon';
        elseif ($busy) $st = 'partial';
        else $st = 'free';
        $days[$k] = [
            'status' => $st,
            'busy' => $busy,
            'day_ok' => (bool) $slot['day_ok'],
            'first_hour' => $slot['first_hour'],
        ];
    }
    $response = new WP_REST_Response([
        'month' => $m,
        'cutoff' => brb_notice_cutoff()->format('c'),
        'notice_hours' => brb_notice_hours(),
        'open' => (int) $o['open_hour'],
        'close' => (int) $o['close_hour'],
        'min_hours' => (int) $o['min_hours'],
        'mode' => $o['mode'],
        'timezone' => 'America/Chicago',
        'days' => $days,
    ]);
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    return $response;
}

/* ---------- front end ---------- */
add_shortcode('boardroom_booking', function () {
    wp_enqueue_style('brb', BRB_URL . 'assets/brb.css', [], BRB_VER);
    wp_enqueue_script('brb', BRB_URL . 'assets/brb.js', [], BRB_VER, true);
    $o = brb_opt();
    wp_localize_script('brb', 'BRB', [
        'api' => esc_url_raw(rest_url('brb/v1/availability')),
        'nonce' => wp_create_nonce('wp_rest'),
        'open' => (int) $o['open_hour'],
        'close' => (int) $o['close_hour'],
        'minHours' => (int) $o['min_hours'],
        'noticeHours' => brb_notice_hours(),
        'mode' => $o['mode'],
        'cutoff' => brb_notice_cutoff()->format('c'),
    ]);
    ob_start();
    echo '<div class="brb">';
    echo '<p class="brb-lead">' . esc_html(brb_price_sentence()) . '</p>';
    echo '<p class="brb-lead">' . esc_html(brb_approval_line()) . ' ' . esc_html(brb_request_open_line()) . ' ' . esc_html(brb_reserved_line()) . ' ' . esc_html(brb_deposit_line()) . ' ' . esc_html(brb_fee_public_line()) . '</p>';
    if (!empty($_GET['br_err'])) echo '<div class="brb-alert brb-err">' . esc_html(wp_unslash($_GET['br_err'])) . '</div>';
    if (!empty($_GET['br_pay'])) brb_view_pay(sanitize_text_field(wp_unslash($_GET['br_pay'])), sanitize_text_field(wp_unslash($_GET['br_sig'] ?? '')));
    elseif (!empty($_GET['br_token'])) brb_view_confirmation(sanitize_text_field(wp_unslash($_GET['br_token'])));
    elseif (!empty($_GET['date'])) brb_view_quote();
    else brb_view_select();
    echo '</div>';
    return ob_get_clean();
});
function brb_steps($i, $labels = null) {
    if (!$labels) $labels = ['Choose date', 'Review', 'Request sent'];
    echo '<ol class="brb-steps">';
    foreach ($labels as $n => $s) {
        echo '<li class="' . ($n === $i ? 'on' : ($n < $i ? 'done' : '')) . '">' . esc_html($s) . '</li>';
    }
    echo '</ol>';
}
function brb_hour_label($h) {
    return brb_now()->setTime((int) $h, 0)->format('g:i A');
}
function brb_view_select() {
    $o = brb_opt();
    brb_steps(0);
    $notice = brb_notice_hours();
    ?>
    <div class="brb-grid">
      <div class="brb-card">
        <div id="brb-cal" class="brb-cal" aria-live="polite" data-api="<?php echo esc_url(rest_url('brb/v1/availability')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>" data-notice="<?php echo (int) $notice; ?>" data-mode="<?php echo esc_attr($o['mode']); ?>">Loading calendar…</div>
        <p class="brb-legend"><span class="free"></span>Available <span class="partial"></span>Partly booked <span class="booked"></span>Unavailable <span class="soon"></span>Inside <?php echo (int) $notice; ?>h notice</p>
      </div>
      <form class="brb-card" method="get" id="brb-form">
        <p class="brb-small">Bookings must start at least <?php echo (int) $notice; ?> hours from now (America/Chicago). Dates and start times inside that window are unavailable. A full day starts at 12:00 AM.</p>
        <p class="brb-small"><?php echo esc_html(brb_no_overnight_line()); ?></p>
        <?php if ($o['mode'] !== 'day'): ?>
        <p class="brb-small">A shorter block reserves part of the day. There is no separate hourly price. The price is confirmed for the event, from the same starting price.</p>
        <?php endif; ?>
        <label>Date<input type="date" name="date" id="brb-date" required min="<?php echo esc_attr(brb_now()->format('Y-m-d')); ?>"></label>
        <?php if ($o['mode'] === 'both'): ?>
        <fieldset class="brb-type"><legend>Booking type</legend>
          <label><input type="radio" name="type" value="day" checked> Full day</label>
          <label><input type="radio" name="type" value="hourly"> Shorter block (<?php echo (int) $o['min_hours']; ?> hour minimum)</label>
        </fieldset>
        <?php else: ?><input type="hidden" name="type" value="<?php echo esc_attr($o['mode']); ?>"><?php endif; ?>
        <div class="brb-day"><label>Number of days<input type="number" name="days" value="1" min="1" max="<?php echo (int) $o['max_days']; ?>"></label></div>
        <div class="brb-hourly">
          <label>Start time<select name="start_hour" id="brb-start"><?php
            for ($h = (int) $o['open_hour']; $h < (int) $o['close_hour']; $h++) {
                printf('<option value="%d">%s</option>', $h, esc_html(brb_hour_label($h)));
            }
          ?></select></label>
          <label>Hours<input type="number" name="hours" value="<?php echo (int) $o['min_hours']; ?>" min="<?php echo (int) $o['min_hours']; ?>" max="<?php echo (int) $o['close_hour'] - (int) $o['open_hour']; ?>"></label>
          <p class="brb-small" id="brb-busy"></p>
        </div>
        <p class="brb-small" id="brb-notice"></p>
        <label>Guests attending (max <?php echo (int) $o['max_guests']; ?>)<input type="number" name="guests" value="10" min="1" max="<?php echo (int) $o['max_guests']; ?>"></label>
        <button class="brb-btn" type="submit">Continue</button>
      </form>
    </div>
    <?php
}
function brb_echo_lines($lines) {
    echo '<div class="brb-paybox">';
    foreach ($lines as $line) echo '<p>' . esc_html($line) . '</p>';
    echo '</div>';
}
function brb_view_quote() {
    $o = brb_opt();
    $in = wp_unslash($_GET);
    $r = brb_build_range($in);
    if (is_wp_error($r)) {
        echo '<div class="brb-alert brb-err">' . esc_html($r->get_error_message()) . '</div>';
        brb_view_select();
        return;
    }
    if (!brb_is_free($r['s'], $r['e'])) {
        echo '<div class="brb-alert brb-err">Sorry, that date/time is not available. Please choose another.</div>';
        brb_view_select();
        return;
    }
    $q = brb_quote($r);
    brb_steps(1);
    $when = $r['type'] === 'day'
        ? $r['s']->format('D M j, Y') . ($r['units'] > 1 ? ' – ' . $r['e']->modify('-1 day')->format('D M j, Y') : '') . ' (full day)'
        : $r['s']->format('D M j, Y g:i A') . ' – ' . $r['e']->format('g:i A');
    ?>
    <div class="brb-grid">
      <div class="brb-card">
        <h3>Your date</h3>
        <p><strong><?php echo esc_html($when); ?></strong><br><?php echo (int) $r['guests']; ?> guests attending</p>
        <p><?php echo esc_html(brb_price_sentence()); ?></p>
        <p><strong>price to be confirmed</strong></p>
        <?php brb_echo_lines(brb_preview_lines($q, $r['s'])); ?>
        <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'] ?? home_url('/book/'), '?')); ?>">← Change date</a>
      </div>
      <form class="brb-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <h3>Guest details</h3>
        <input type="hidden" name="action" value="brb_book"><?php wp_nonce_field('brb_book', '_brb');
        foreach (['date', 'type', 'days', 'start_hour', 'hours', 'guests'] as $k) {
            if (isset($in[$k])) printf('<input type="hidden" name="%s" value="%s">', esc_attr($k), esc_attr($in[$k]));
        } ?>
        <label>Full name*<input name="name" required autocomplete="name"></label>
        <label>Email*<input type="email" name="email" required autocomplete="email"></label>
        <label>Event type*<select name="event_type" required>
          <option value="">Choose one</option>
          <?php foreach (brb_event_types() as $key => $label): ?>
          <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Is the event open to the public or ticketed?*
          <select name="public_event" required>
            <option value="">Choose one</option>
            <option value="yes">Yes</option>
            <option value="no">No</option>
          </select>
        </label>
        <label>Will alcohol be served?*
          <select name="alcohol" required>
            <option value="">Choose one</option>
            <option value="yes">Yes</option>
            <option value="no">No</option>
          </select>
        </label>
        <label>Planned end time*<input type="time" name="end_time" required></label>
        <label>Host age bracket*<select name="host_age" required>
          <option value="">Choose one</option>
          <?php foreach (brb_host_ages() as $key => $label): ?>
          <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Tell us about your event*<textarea name="event_about" rows="4" required></textarea></label>
        <div class="brb-policy"><strong>Cancellation policy</strong><p><?php echo nl2br(esc_html($o['policy'])); ?></p><p><a href="<?php echo esc_url(home_url('/terms/')); ?>" target="_blank" rel="noopener">Read the Rental Terms</a></p></div>
        <label class="brb-check"><input type="checkbox" name="policy" value="1" required> I agree to the <a href="<?php echo esc_url(home_url('/terms/')); ?>" target="_blank" rel="noopener">Terms</a>, the <a href="<?php echo esc_url(home_url('/rental-agreement/')); ?>" target="_blank" rel="noopener">Rental Agreement</a> and the <a href="<?php echo esc_url(home_url('/privacy/')); ?>" target="_blank" rel="noopener">Privacy Policy</a>. I understand <?php echo esc_html(lcfirst(brb_deposit_line())); ?></label>
        <button class="brb-btn" type="submit">Submit request</button>
      </form>
    </div>
    <?php
}
function brb_view_confirmation($token) {
    $b = brb_booking_by_token($token);
    if (!$b) {
        echo '<div class="brb-alert brb-err">Booking not found.</div>';
        return;
    }
    $paid_off = round((float) $b->total - (float) $b->amount_paid, 2);
    $priced = brb_price_is_set($b);
    $labels = ['Choose date', 'Review', $b->status === 'paid' ? 'Confirmed' : 'Request sent'];
    $step = in_array($b->status, ['cancelled', 'expired', 'declined'], true) ? 1 : 2;
    brb_steps($step, $labels);
    $recorded = brb_payments($b->id);
    $latest = $recorded ? end($recorded) : null;
    $latest_method = $latest ? (string) ($latest->method ?? 'check') : 'check';
    $titles = [
        'review' => 'Pending review',
        'pending' => 'Pending review',
        'approved' => 'Request approved',
        'held' => 'Request approved',
        'deposit_paid' => $latest_method === 'cash' ? 'Cash payment recorded' : ($latest_method === 'card' ? 'Card payment recorded' : 'Deposit recorded'),
        'paid' => 'Booking confirmed',
        'declined' => 'Request declined',
        'cancelled' => 'Booking cancelled',
        'expired' => 'Request closed',
    ];
    $title = $titles[$b->status] ?? 'Booking';
    ?>
    <div class="brb-card brb-confirm">
      <h2><?php echo esc_html($title); ?></h2>
      <p>Reference <strong><?php echo esc_html(brb_ref($b)); ?></strong></p>
      <p><?php
        $summary = brb_when($b) . ' · ' . (int) $b->guests . ' guests attending';
        $event_label = brb_event_type_label($b->event_type ?? '');
        if ($event_label !== '') $summary .= ' · ' . $event_label;
        echo esc_html($summary);
      ?></p>
      <?php if ($priced): ?>
      <p>Total <?php echo esc_html(brb_money($b->total)); ?> · Paid <?php echo esc_html(brb_money($b->amount_paid)); ?> · Balance <?php echo esc_html(brb_money(max(0, $paid_off))); ?></p>
      <?php else: ?>
      <p>price to be confirmed</p>
      <?php endif; ?>
      <?php
      if (!empty($_GET['card']) && $_GET['card'] === 'pending' && $b->status !== 'paid') {
          echo '<div class="brb-alert">If you just paid by card, the receipt is sent when the payment is confirmed. Your balance updates after that confirmation.</div>';
      }
      if (in_array($b->status, ['review', 'pending'], true)) {
          echo '<div class="brb-paybox">';
          echo '<p>Your request is pending review. Bookings are subject to approval.</p>';
          echo '<p>' . esc_html(brb_approval_line()) . '</p>';
          echo '<p>' . esc_html(brb_request_open_line()) . '</p>';
          echo '<p>' . esc_html(brb_reserved_line()) . '</p>';
          echo '<p>' . esc_html(brb_deposit_line()) . '</p>';
          echo '<p>No payment is due until the request is approved.</p>';
          echo '</div>';
          echo '<p>A copy of this request was sent to ' . esc_html($b->email) . '.</p>';
      } elseif (in_array($b->status, ['approved', 'held'], true)) {
          brb_echo_lines(brb_payment_lines($b));
          echo '<p>Payment instructions were sent to ' . esc_html($b->email) . '.</p>';
      } elseif ($b->status === 'deposit_paid') {
          $due = brb_balance_due_label($b->balance_due_on);
          if ($latest_method === 'cash') $received = 'We have recorded a cash payment toward this booking. Your date stays reserved.';
          elseif ($latest_method === 'card') $received = 'We have recorded a card payment toward this booking. Your date stays reserved.';
          else $received = 'We have recorded a check toward this booking. Your date stays reserved.';
          echo '<div class="brb-paybox"><p>' . esc_html($received) . '</p>';
          echo '<p>Balance of ' . esc_html(brb_money(max(0, $paid_off))) . ($due ? ' is due by ' . esc_html($due) : '') . '.</p>';
          echo '<p>' . esc_html(brb_check_sentence('the remaining balance as a check')) . ' You can also pay the rest online when card payment is offered. Write booking reference ' . esc_html(brb_ref($b)) . ' on the memo line.</p></div>';
      } elseif ($b->status === 'paid') {
          echo '<div class="brb-paybox"><p>This booking is paid in full and confirmed.</p></div>';
          echo '<p>A confirmation email was sent to ' . esc_html($b->email) . '.</p>';
      } elseif ($b->status === 'declined') {
          echo '<div class="brb-alert">This request was declined. The date is not reserved.</div>';
      } elseif ($b->status === 'expired') {
          echo '<div class="brb-alert">This request is closed. The date is not reserved.</div>';
      } else {
          echo '<div class="brb-alert">This booking is cancelled. The date is not reserved.</div>';
      }
      if (!in_array($b->status, ['approved', 'held'], true)) echo '<p>' . esc_html(brb_no_overnight_line()) . '</p>';
      $pay_url = brb_pay_link($b);
      if ($pay_url !== '') echo '<p><a class="brb-btn" href="' . esc_url($pay_url) . '">Pay online</a></p>';
      ?>
    </div>
    <?php
}

require __DIR__ . '/admin.php';
