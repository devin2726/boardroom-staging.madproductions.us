<?php
/**
 * Plugin Name: Boardroom Booking
 * Description: Self-hosted direct booking for The Boardroom — full-day and hourly blocks, availability calendar, agreed prices, in-person check holds, blackout dates, and emails.
 * Version: 2.2.0
 * Author: Mad Productions
 */
if (!defined('ABSPATH')) exit;
define('BRB_VER', '2.2.0');
require_once __DIR__ . '/legal-content.php';
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
        'deposit_type' => 'full',
        'deposit_pct' => '100',
        'deposit_fixed' => '0',
        'balance_due_days' => '7',
        'max_guests' => '75',
        'max_days' => '7',
        'min_notice_hours' => '48',
        'hold_hours' => '48',
        'currency' => 'usd',
        'admin_email' => $admin,
        'payee' => '[VERIFY] Name on the check',
        'pay_location' => '[VERIFY] In-person location',
        'pay_hours' => '[VERIFY] In-person hours',
        'pay_instructions' => '[VERIFY] Extra instructions for bringing the check.',
        'blackouts' => '',
        'policy' => brb_cancellation_summary(),
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
        'held' => 'Held (awaiting check)',
        'deposit_paid' => 'Deposit paid',
        'paid' => 'Paid in full',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        'pending' => 'Held (awaiting check)',
        'confirmed' => 'Paid in full',
    ];
    return $map[$s] ?? $s;
}
function brb_source_label($s) {
    $map = ['online' => 'Online', 'phone' => 'Phone', 'walk-in' => 'Walk-in'];
    return $map[$s] ?? ($s ?: 'Online');
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
      status varchar(20) NOT NULL DEFAULT 'held',
      source varchar(20) NOT NULL DEFAULT 'online',
      hold_until datetime DEFAULT NULL,
      balance_due_on date DEFAULT NULL,
      price_set tinyint(1) NOT NULL DEFAULT 0,
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
    $p = brb_pay_table();
    dbDelta("CREATE TABLE $p (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      booking_id bigint(20) unsigned NOT NULL,
      amount decimal(10,2) NOT NULL DEFAULT 0.00,
      check_number varchar(64) NOT NULL DEFAULT '',
      received_on date DEFAULT NULL,
      notes text,
      created datetime NOT NULL,
      PRIMARY KEY  (id),
      KEY booking_id (booking_id)
    ) $c;");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p)) !== $p) {
        $wpdb->query("CREATE TABLE IF NOT EXISTS $p (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          booking_id bigint(20) unsigned NOT NULL,
          amount decimal(10,2) NOT NULL DEFAULT 0.00,
          check_number varchar(64) NOT NULL DEFAULT '',
          received_on date NULL DEFAULT NULL,
          notes text,
          created datetime NOT NULL,
          PRIMARY KEY (id),
          KEY booking_id (booking_id)
        ) $c");
    }
    foreach (['deposit_due', 'amount_paid', 'source', 'hold_until', 'balance_due_on', 'price_set'] as $col) {
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$t` LIKE %s", $col))) return false;
    }
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p)) === $p;
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
        $pct = isset($stored['deposit_pct']) ? (float) $stored['deposit_pct'] : 100.0;
        $merged['deposit_type'] = ($pct >= 99.9) ? 'full' : 'percent';
    }
    if (!isset($stored['min_notice_hours'])) $merged['min_notice_hours'] = '48';
    if (!isset($stored['hold_hours'])) $merged['hold_hours'] = '48';
    if (!isset($stored['balance_due_days'])) $merged['balance_due_days'] = '7';
    if (!isset($stored['deposit_fixed']) || $stored['deposit_fixed'] === '') $merged['deposit_fixed'] = '0';
    $defaults = brb_defaults();
    foreach (['payee', 'pay_location', 'pay_hours', 'pay_instructions'] as $k) {
        if (!isset($stored[$k]) || trim((string) $stored[$k]) === '') $merged[$k] = $defaults[$k];
    }
    if (!isset($stored['policy']) || brb_policy_needs_refresh($stored['policy'])) {
        $merged['policy'] = brb_cancellation_summary();
    }
    update_option('brb_settings', $merged);
}
function brb_migrate_bookings() {
    global $wpdb;
    $t = brb_table();
    $hours = brb_hold_hours();
    // Older rows stored the collected sum in amount_due and used status confirmed/pending.
    $wpdb->query("UPDATE $t SET amount_paid = amount_due, deposit_due = IF(deposit_due > 0, deposit_due, amount_due), source = IF(source IS NULL OR source = '', 'online', source), status = IF(amount_due + 0.009 >= total, 'paid', 'deposit_paid') WHERE status = 'confirmed'");
    $wpdb->query($wpdb->prepare(
        "UPDATE $t SET deposit_due = IF(deposit_due > 0, deposit_due, amount_due), source = IF(source IS NULL OR source = '', 'online', source), hold_until = IF(hold_until IS NULL, DATE_ADD(created, INTERVAL %d HOUR), hold_until), status = 'held' WHERE status = 'pending'",
        $hours
    ));
}
function brb_migrate_prices() {
    global $wpdb;
    $t = brb_table();
    // Rows that already have a calculated total keep it. New saves store zero until staff set a price.
    $wpdb->query("UPDATE $t SET price_set = 1 WHERE price_set = 0 AND total > 0.009");
}
function brb_faq_markup() {
    return <<<'HTML'
<details><summary>How many guests can The Boardroom hold?</summary><p>Up to 75 people.</p></details><details><summary>Is parking available?</summary><p>Yes, on-site parking.</p></details><details><summary>Can we bring our own drinks?</summary><p>The Boardroom has a bar area and an ice machine. Do not use the stove top. Food warmers and the bar area may be used. Draft taps are not available. BYOB is allowed, and the renter must obtain a one-day liquor license. See the <a href="/terms/">Rental Terms</a>.</p></details><details><summary>What is there to do?</summary><p>80in television, pinball, pool and shuffleboard.</p></details><details><summary>How much does it cost?</summary><p>Pricing starts at $600 per day and can reach about $2,000, depending on the date, how many people are coming, and the features you would like. Most events come to about $1,200. We confirm the final price with you before any check is due. The website does not guess a total.</p></details><details><summary>How is the space set up?</summary><p>The space arrives not set up. Seating and layout are yours to plan, so bring the arrangement that suits your gathering. If you would like a hand, our staff can help with setup for an extra fee.</p></details><details><summary>Do you provide decorations?</summary><p>Decorations are not provided. Christmas decorations are up in December.</p></details><details><summary>What are our responsibilities before we leave?</summary><p>Please clean and tidy the space, and take the garbage out. Another event is often booked the following day, and a ready room lets the next hosts begin on time.</p></details><details><summary>How do I book and pay?</summary><p>Save your date on the <a href="/book/">Book</a> page. That holds the date for 48 hours. Bring a check in person within those 48 hours. Bookings need at least 48 hours' notice. Until we confirm your price, your confirmation says price to be confirmed.</p></details><details><summary>What is the cancellation policy?</summary><p>See the <a href="/terms/">Rental Terms</a>. If a summary and the signed agreement differ, the signed agreement controls.</p></details><details><summary>What are the hours / check-in and check-out times?</summary><p class="placeholder">PLACEHOLDER — owner to supply.</p></details>
HTML;
}
function brb_patch_faq_drinks() {
    $page = get_page_by_path('faq');
    if (!$page) return;
    $c = (string) $page->post_content;
    $replacements = [
        'The Boardroom has a BYOB bar and ice machine. <span class="placeholder">PLACEHOLDER: alcohol rules / age policy — owner to confirm.</span>' => 'The Boardroom has a bar area and an ice machine. Do not use the stove top. Food warmers and the bar area may be used. Draft taps are not available. BYOB is allowed, and the renter must obtain a one-day liquor license. See the <a href="/terms/">Rental Terms</a>.',
        'See our <a href="/terms-and-conditions/">Terms and Conditions</a>. <span class="placeholder">PLACEHOLDER — owner to supply.</span>' => 'See the <a href="/terms/">Rental Terms</a>. If a summary and the signed agreement differ, the signed agreement controls.',
    ];
    $next = $c;
    foreach ($replacements as $old => $new) $next = str_replace($old, $new, $next);
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
    if (stripos($c, 'How much does it cost?') !== false && stripos($c, 'Christmas decorations are up in December') !== false) return;
    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_content' => brb_faq_markup()], ['ID' => (int) $page->ID]);
    clean_post_cache((int) $page->ID);
}
function brb_scrub_copy_string($c) {
    $c = preg_replace('/Payments are processed by \w+;?\s*we do not store card numbers\./i', 'Payment is by check brought in person. We keep your booking details and the check number our staff records.', $c);
    $c = preg_replace('/pay securely online/i', 'save the date and bring a check in person', $c);
    $c = preg_replace('/Bookings are confirmed once payment \(or the required deposit\) is received\./i', 'Saving a date holds it for a limited time. The booking is confirmed once we receive your check, or the required deposit, in person.', $c);
    $c = preg_replace('/\b(str' . 'ipe|ze' . 'lle|air' . 'bnb)\b/i', '', $c);
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
    if (get_option('brb_settings', null) === null) update_option('brb_settings', brb_defaults());
    else brb_migrate_settings();
    brb_migrate_bookings();
    brb_migrate_prices();
    brb_scrub_public_copy();
    brb_refresh_faq();
    brb_patch_faq_drinks();
    brb_install_legal_pages();
    brb_expire_holds();
    if (!wp_next_scheduled('brb_cron_expire')) wp_schedule_event(time() + 60, 'hourly', 'brb_cron_expire');
    update_option('brb_version', BRB_VER);
}
add_action('plugins_loaded', 'brb_maybe_upgrade', 5);
add_action('template_redirect', 'brb_legacy_legal_redirect');
register_activation_hook(__FILE__, 'brb_maybe_upgrade');
register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('brb_cron_expire'); });
add_action('init', function () {
    if (get_option('brb_version') !== BRB_VER) return;
    if (!wp_next_scheduled('brb_cron_expire')) wp_schedule_event(time() + 60, 'hourly', 'brb_cron_expire');
    brb_expire_holds();
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
    $now = gmdate('Y-m-d H:i:s');
    $sql = "(status IN ('paid','deposit_paid','confirmed') OR (status IN ('held','pending') AND (hold_until IS NULL OR hold_until > %s)))";
    return [$sql, [$now]];
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
function brb_amount_to_bring($b) {
    $deposit_left = round((float) $b->deposit_due - (float) $b->amount_paid, 2);
    if ($deposit_left > 0) return $deposit_left;
    return max(0, round((float) $b->total - (float) $b->amount_paid, 2));
}
function brb_hold_lines($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $set = brb_price_is_set($b);
    $lines = [];
    $lines[] = 'Your date is held until ' . brb_format_utc_mysql($b->hold_until) . '.';
    if ($set) {
        $lines[] = 'Bring a check for ' . brb_money(brb_amount_to_bring($b)) . ' payable to ' . $o['payee'] . ', to ' . $o['pay_location'] . ' (' . $o['pay_hours'] . ').';
    } else {
        $lines[] = 'Price to be confirmed. We will confirm the final price based on the date, guest count, and features, then tell you the check amount.';
        $lines[] = 'When that amount is set, bring the check payable to ' . $o['payee'] . ', to ' . $o['pay_location'] . ' (' . $o['pay_hours'] . ').';
    }
    $lines[] = 'Write booking reference ' . $ref . ' on the memo line.';
    $lines[] = 'If the check is not received by then, the hold is released.';
    if ($set) {
        $balance = round((float) $b->total - (float) $b->deposit_due, 2);
        if ($balance > 0.009) {
            $lines[] = 'After that check, the remaining balance of ' . brb_money($balance) . ' is due by ' . brb_balance_due_label($b->balance_due_on) . ' (' . (int) $o['balance_due_days'] . ' days before the event).';
        }
    }
    $extra = trim((string) $o['pay_instructions']);
    if ($extra !== '') $lines[] = $extra;
    return $lines;
}
function brb_preview_lines($q, DateTimeImmutable $start) {
    $o = brb_opt();
    $hours = brb_hold_hours();
    $set = is_array($q) && !empty($q['price_set']);
    $lines = [];
    $lines[] = "Saving this date holds it for {$hours} hours. The next page shows the exact deadline in America/Chicago.";
    if ($set) {
        $lines[] = 'Bring a check for ' . brb_money($q['due']) . ' payable to ' . $o['payee'] . ', to ' . $o['pay_location'] . ' (' . $o['pay_hours'] . ').';
    } else {
        $lines[] = 'Price to be confirmed. We will confirm the final price based on the date, guest count, and features, then tell you the check amount.';
        $lines[] = 'When that amount is set, bring the check payable to ' . $o['payee'] . ', to ' . $o['pay_location'] . ' (' . $o['pay_hours'] . ').';
    }
    $lines[] = 'Write the booking reference (shown after you save) on the memo line.';
    $lines[] = 'If the check is not received by then, the hold is released.';
    if ($set) {
        $balance = round((float) $q['total'] - (float) $q['due'], 2);
        if ($balance > 0.009) {
            $on = brb_balance_due_on($start);
            $lines[] = 'After that check, the remaining balance of ' . brb_money($balance) . ' is due by ' . brb_balance_due_label($on) . ' (' . (int) $o['balance_due_days'] . ' days before the event).';
        }
    }
    $extra = trim((string) $o['pay_instructions']);
    if ($extra !== '') $lines[] = $extra;
    return $lines;
}

function brb_mail($to, $subject, $body, $reply = '') {
    $to = sanitize_email($to);
    if (!$to || !is_email($to)) return false;
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    if ($reply && is_email($reply)) $headers[] = 'Reply-To: ' . $reply;
    return wp_mail($to, $subject, $body, $headers);
}
function brb_guest_link($b) {
    return add_query_arg('br_token', $b->token, home_url('/book/'));
}
function brb_send_hold_emails($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $facts = implode("\n\n", brb_hold_lines($b));
    $price = brb_price_phrase($b);
    $check = brb_price_is_set($b) ? brb_money(brb_amount_to_bring($b)) : 'price to be confirmed';
    $detail = "When: " . brb_when($b) . "\nGuests: {$b->guests}\nPrice: {$price}\n";
    $body = "Hi {$b->name},\n\n{$facts}\n\n{$detail}\nCancellation policy:\n{$o['policy']}\n\nView your booking: " . brb_guest_link($b) . "\n";
    brb_mail($b->email, 'Your Boardroom date is held (' . $ref . ')', $body, $o['admin_email']);
    $admin = "A date was saved and is held.\n\nReference: {$ref}\nWhen: " . brb_when($b) . "\nGuest: {$b->name}\nEmail: {$b->email}\nPhone: {$b->phone}\nGuests: {$b->guests}\nPrice: {$price}\nCheck to bring: {$check}\nSource: " . brb_source_label($b->source) . "\nHeld until: " . brb_format_utc_mysql($b->hold_until) . "\nNotes: {$b->notes}\n\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n";
    brb_mail($o['admin_email'], 'Date held ' . $ref . ' – ' . $b->name, $admin, $b->email);
}
function brb_send_payment_email($b, $amount, $check_number) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $paid = brb_money($b->amount_paid);
    $balance = brb_money(max(0, round((float) $b->total - (float) $b->amount_paid, 2)));
    if ((float) $amount > 0) {
        $check = 'We recorded a check for ' . brb_money($amount);
        if ($check_number !== '') $check .= ' (check ' . $check_number . ')';
        $check .= '.';
    } else {
        $check = 'This booking is marked paid in full.';
    }
    if ($b->status === 'paid') {
        $intro = "Your Boardroom booking is confirmed.\n\n{$check}\nPaid: {$paid}\nBalance: {$balance}";
        $subject = 'Your Boardroom booking is confirmed (' . $ref . ')';
    } else {
        $due = brb_balance_due_label($b->balance_due_on);
        $intro = "We received your check and your date stays reserved.\n\n{$check}\nPaid so far: {$paid}\nBalance: {$balance}" . ($due ? " due by {$due}" : '') . ".\n\nBring the remaining balance as a check payable to {$o['payee']} to {$o['pay_location']} ({$o['pay_hours']}).\nWrite booking reference {$ref} on the memo line.";
        $subject = 'We received your check for ' . $ref;
    }
    $body = "Hi {$b->name},\n\n{$intro}\n\nReference: {$ref}\nWhen: " . brb_when($b) . "\nGuests: {$b->guests}\nPrice: " . brb_price_phrase($b) . "\n\nCancellation policy:\n{$o['policy']}\n\nView your booking: " . brb_guest_link($b) . "\n";
    brb_mail($b->email, $subject, $body, $o['admin_email']);
    brb_mail($o['admin_email'], 'Check recorded for ' . $ref . ' – ' . $b->name, $body . "\nAdmin: " . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n", $b->email);
}
function brb_send_expired_emails($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $when = brb_when($b);
    $guest = "Hi {$b->name},\n\nYour hold on The Boardroom ({$ref}, {$when}) expired. The hold is released and that date is no longer reserved.\n\nIf the date is still open you can save it again: " . home_url('/book/') . "\n";
    brb_mail($b->email, 'Your Boardroom hold expired (' . $ref . ')', $guest, $o['admin_email']);
    $admin = "Hold released.\n\nReference: {$ref}\nWhen: {$when}\nGuest: {$b->name}\nEmail: {$b->email}\nPhone: {$b->phone}\nThe date is available again.\n\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n";
    brb_mail($o['admin_email'], 'Hold expired ' . $ref . ' – ' . $b->name, $admin, $b->email);
}
function brb_send_cancel_email($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $body = "Hi {$b->name},\n\nBooking {$ref} (" . brb_when($b) . ") has been cancelled. The date is no longer reserved.\n\nCancellation policy:\n{$o['policy']}\n";
    brb_mail($b->email, 'Boardroom booking cancelled (' . $ref . ')', $body, $o['admin_email']);
}

function brb_expire_holds() {
    global $wpdb;
    if (get_option('brb_version') !== BRB_VER && !brb_column_ready()) return;
    $t = brb_table();
    $now = gmdate('Y-m-d H:i:s');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t WHERE status='held' AND amount_paid < 0.01 AND hold_until IS NOT NULL AND hold_until <= %s",
        $now
    ));
    foreach ($rows as $b) {
        $n = $wpdb->query($wpdb->prepare("UPDATE $t SET status='expired' WHERE id=%d AND status='held'", (int) $b->id));
        if ($n) {
            $b->status = 'expired';
            brb_send_expired_emails($b);
        }
    }
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
            $hold_until = gmdate('Y-m-d H:i:s', time() + brb_hold_hours() * HOUR_IN_SECONDS);
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
                'subtotal' => brb_dec($q['subtotal']),
                'cleaning' => brb_dec($q['cleaning']),
                'tax' => brb_dec($q['tax']),
                'total' => brb_dec($q['total']),
                'amount_due' => $due,
                'deposit_due' => $due,
                'amount_paid' => '0.00',
                'status' => 'held',
                'source' => $source,
                'hold_until' => $hold_until,
                'balance_due_on' => brb_balance_due_on($r['s']),
                'price_set' => empty($q['price_set']) ? 0 : 1,
                'created' => current_time('mysql', true),
            ]);
            $result = $ok ? brb_booking($wpdb->insert_id) : new WP_Error('db', 'Could not save the booking. Please try again.');
        }
    } finally {
        $wpdb->query("SELECT RELEASE_LOCK('brb_book')");
    }
    if ($notify && $result && !is_wp_error($result)) brb_send_hold_emails($result);
    return $result;
}
function brb_process_public_booking($post) {
    if (empty($post['policy'])) return new WP_Error('policy', 'Please agree to the Terms and Privacy Policy.');
    $r = brb_build_range($post);
    if (is_wp_error($r)) return $r;
    $name = sanitize_text_field($post['name'] ?? '');
    $email = sanitize_email($post['email'] ?? '');
    if (!$name || !is_email($email)) return new WP_Error('contact', 'Name and a valid email are required.');
    $q = brb_quote($r);
    return brb_insert_booking($r, $q, [
        'name' => $name,
        'email' => $email,
        'phone' => sanitize_text_field($post['phone'] ?? ''),
        'notes' => sanitize_textarea_field($post['notes'] ?? ''),
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

function brb_record_payment($booking_id, $amount, $check_number, $received_on, $notes, $require_check = true) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (in_array($b->status, ['cancelled', 'expired'], true)) {
        return new WP_Error('status', 'That booking is not open for a check. Extend the hold first if it expired and the date is still free.');
    }
    if (!brb_price_is_set($b)) {
        return new WP_Error('price', 'Set the agreed price before recording a check.');
    }
    $amount = round((float) $amount, 2);
    if ($amount <= 0) return new WP_Error('amount', 'Enter a check amount greater than zero.');
    $remaining = round((float) $b->total - (float) $b->amount_paid, 2);
    if ($amount - $remaining > 0.009) return new WP_Error('amount', 'That amount is more than the balance of ' . brb_money($remaining) . '.');
    $check_number = sanitize_text_field($check_number);
    if ($require_check && $check_number === '') return new WP_Error('check', 'Enter the check number.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $received_on)) $received_on = brb_now()->format('Y-m-d');
    $notes = sanitize_textarea_field($notes);
    $ok = $wpdb->insert(brb_pay_table(), [
        'booking_id' => (int) $b->id,
        'amount' => brb_dec($amount),
        'check_number' => $check_number,
        'received_on' => $received_on,
        'notes' => $notes,
        'created' => current_time('mysql', true),
    ]);
    if (!$ok) return new WP_Error('db', 'Could not record the check.');
    $paid = round((float) $b->amount_paid + $amount, 2);
    $status = ($paid + 0.009 >= (float) $b->total) ? 'paid' : 'deposit_paid';
    $wpdb->update(brb_table(), [
        'amount_paid' => brb_dec($paid),
        'status' => $status,
    ], ['id' => (int) $b->id]);
    $b = brb_booking($b->id);
    brb_send_payment_email($b, $amount, $check_number);
    return $b;
}
function brb_mark_paid_full($booking_id) {
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (in_array($b->status, ['cancelled', 'expired'], true)) return new WP_Error('status', 'Cancelled or expired bookings cannot be marked paid.');
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
    if ($b->status === 'cancelled') return new WP_Error('status', 'A cancelled booking cannot take a price.');
    $amount = round((float) $amount, 2);
    if ($amount <= 0) return new WP_Error('amount', 'Enter an agreed price greater than zero.');
    if ((float) $b->amount_paid - $amount > 0.009) {
        return new WP_Error('amount', 'The agreed price cannot be less than the ' . brb_money($b->amount_paid) . ' already recorded.');
    }
    $due = brb_deposit_due_amount($amount);
    $status = $b->status;
    if ((float) $b->amount_paid + 0.009 >= $amount) $status = 'paid';
    elseif ((float) $b->amount_paid > 0.009 && in_array($status, ['held', 'pending', 'deposit_paid'], true)) $status = 'deposit_paid';
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
    $body = "Hi {$b->name},\n\nThe price for your Boardroom date is confirmed.\n\nAgreed price: " . brb_money($b->total) . "\nCheck to bring: {$bring}\n\n";
    if ($b->status === 'held') {
        $body .= implode("\n\n", brb_hold_lines($b)) . "\n\n";
    } else {
        $balance = max(0, round((float) $b->total - (float) $b->amount_paid, 2));
        $body .= 'Paid so far: ' . brb_money($b->amount_paid) . "\nBalance: " . brb_money($balance) . "\n\n";
    }
    $body .= 'When: ' . brb_when($b) . "\nGuests: {$b->guests}\nReference: {$ref}\n\nView your booking: " . brb_guest_link($b) . "\n";
    brb_mail($b->email, 'Your Boardroom price is confirmed (' . $ref . ')', $body, $o['admin_email']);
    brb_mail($o['admin_email'], 'Price set for ' . $ref . ' – ' . $b->name, $body . "\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n", $b->email);
}
function brb_extend_hold($booking_id, $hours) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b || !in_array($b->status, ['held', 'expired'], true)) return new WP_Error('status', 'Only a held or expired booking can be extended.');
    $hours = max(1, min(24 * 30, (int) $hours));
    $s = new DateTimeImmutable($b->start_dt, brb_tz());
    $e = new DateTimeImmutable($b->end_dt, brb_tz());
    if ($b->status === 'expired' && !brb_is_free($s, $e, (int) $b->id)) {
        return new WP_Error('busy', 'That date has already been taken. It cannot be held again.');
    }
    $base = time();
    if (!empty($b->hold_until)) {
        $old = strtotime($b->hold_until . ' UTC');
        if ($old && $old > $base) $base = $old;
    }
    $until = gmdate('Y-m-d H:i:s', $base + $hours * HOUR_IN_SECONDS);
    $wpdb->update(brb_table(), ['status' => 'held', 'hold_until' => $until], ['id' => (int) $b->id]);
    return brb_booking($b->id);
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
    return [
        'month' => $m,
        'cutoff' => brb_notice_cutoff()->format('c'),
        'notice_hours' => brb_notice_hours(),
        'open' => (int) $o['open_hour'],
        'close' => (int) $o['close_hour'],
        'min_hours' => (int) $o['min_hours'],
        'mode' => $o['mode'],
        'timezone' => 'America/Chicago',
        'days' => $days,
    ];
}

/* ---------- front end ---------- */
add_shortcode('boardroom_booking', function () {
    wp_enqueue_style('brb', BRB_URL . 'assets/brb.css', [], BRB_VER);
    wp_enqueue_script('brb', BRB_URL . 'assets/brb.js', [], BRB_VER, true);
    $o = brb_opt();
    wp_localize_script('brb', 'BRB', [
        'api' => esc_url_raw(rest_url('brb/v1/availability')),
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
    echo '<p class="brb-lead">Save your date online and bring a check in person. A saved date is held so nobody else can take it while we wait for the check.</p>';
    if (!empty($_GET['br_err'])) echo '<div class="brb-alert brb-err">' . esc_html(wp_unslash($_GET['br_err'])) . '</div>';
    if (!empty($_GET['br_token'])) brb_view_confirmation(sanitize_text_field(wp_unslash($_GET['br_token'])));
    elseif (!empty($_GET['date'])) brb_view_quote();
    else brb_view_select();
    echo '</div>';
    return ob_get_clean();
});
function brb_steps($i, $labels = null) {
    if (!$labels) $labels = ['Choose date', 'Review', 'Date held'];
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
        <div id="brb-cal" class="brb-cal" aria-live="polite">Loading calendar…</div>
        <p class="brb-legend"><span class="free"></span>Available <span class="partial"></span>Partly booked <span class="booked"></span>Unavailable <span class="soon"></span>Inside <?php echo (int) $notice; ?>h notice</p>
      </div>
      <form class="brb-card" method="get" id="brb-form">
        <p class="brb-small">Bookings must start at least <?php echo (int) $notice; ?> hours from now (America/Chicago). Dates and start times inside that window are unavailable. A full day starts at 12:00 AM.</p>
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
        <label>Guests (max <?php echo (int) $o['max_guests']; ?>)<input type="number" name="guests" value="10" min="1" max="<?php echo (int) $o['max_guests']; ?>"></label>
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
        <p><strong><?php echo esc_html($when); ?></strong><br><?php echo (int) $r['guests']; ?> guests</p>
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
        <label>Phone<input name="phone" autocomplete="tel"></label>
        <label>Event details / notes<textarea name="notes" rows="3"></textarea></label>
        <div class="brb-policy"><strong>Cancellation policy</strong><p><?php echo nl2br(esc_html($o['policy'])); ?></p><p><a href="<?php echo esc_url(home_url('/terms/')); ?>" target="_blank" rel="noopener">Read the Rental Terms</a></p></div>
        <label class="brb-check"><input type="checkbox" name="policy" value="1" required> I agree to the <a href="<?php echo esc_url(home_url('/terms/')); ?>" target="_blank" rel="noopener">Terms</a> and <a href="<?php echo esc_url(home_url('/privacy/')); ?>" target="_blank" rel="noopener">Privacy Policy</a>.</label>
        <button class="brb-btn" type="submit">Save this date</button>
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
    $labels = ['Choose date', 'Review', $b->status === 'paid' ? 'Confirmed' : 'Date held'];
    $step = in_array($b->status, ['cancelled', 'expired'], true) ? 1 : 2;
    brb_steps($step, $labels);
    $titles = [
        'held' => 'Your date is held',
        'deposit_paid' => 'Deposit check recorded',
        'paid' => 'Booking confirmed',
        'cancelled' => 'Booking cancelled',
        'expired' => 'Hold released',
    ];
    $title = $titles[$b->status] ?? 'Booking';
    ?>
    <div class="brb-card brb-confirm">
      <h2><?php echo esc_html($title); ?></h2>
      <p>Reference <strong><?php echo esc_html(brb_ref($b)); ?></strong></p>
      <p><?php echo esc_html(brb_when($b)); ?> · <?php echo (int) $b->guests; ?> guests</p>
      <?php if ($priced): ?>
      <p>Total <?php echo esc_html(brb_money($b->total)); ?> · Paid <?php echo esc_html(brb_money($b->amount_paid)); ?> · Balance <?php echo esc_html(brb_money(max(0, $paid_off))); ?></p>
      <?php else: ?>
      <p>price to be confirmed</p>
      <?php endif; ?>
      <?php
      if ($b->status === 'held') {
          brb_echo_lines(brb_hold_lines($b));
          echo '<p>A copy of these instructions was sent to ' . esc_html($b->email) . '.</p>';
      } elseif ($b->status === 'deposit_paid') {
          $due = brb_balance_due_label($b->balance_due_on);
          echo '<div class="brb-paybox"><p>We have recorded a check toward this booking. Your date stays reserved.</p>';
          echo '<p>Balance of ' . esc_html(brb_money(max(0, $paid_off))) . ($due ? ' is due by ' . esc_html($due) : '') . '.</p>';
          echo '<p>Bring the balance as a check payable to ' . esc_html(brb_opt('payee')) . ' to ' . esc_html(brb_opt('pay_location')) . ' (' . esc_html(brb_opt('pay_hours')) . '). Write booking reference ' . esc_html(brb_ref($b)) . ' on the memo line.</p></div>';
      } elseif ($b->status === 'paid') {
          echo '<div class="brb-paybox"><p>This booking is paid in full and confirmed.</p></div>';
          echo '<p>A confirmation email was sent to ' . esc_html($b->email) . '.</p>';
      } elseif ($b->status === 'expired') {
          echo '<div class="brb-alert">This hold expired and the date was released. If it is still open you can save it again.</div>';
      } else {
          echo '<div class="brb-alert">This booking is cancelled. The date is not reserved.</div>';
      }
      ?>
    </div>
    <?php
}

require __DIR__ . '/admin.php';
