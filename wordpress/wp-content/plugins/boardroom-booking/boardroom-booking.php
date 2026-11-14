<?php
/**
 * Plugin Name: Boardroom Booking
 * Description: Self-hosted direct booking for The Boardroom — full-day blocks, availability calendar, agreed prices, approval before payment, blackout dates, and emails.
 * Version: 2.5.79
 * Author: Boardroom QC LLC
 */
if (!defined('ABSPATH')) exit;
define('BRB_VER', '2.5.79');
function brb_reserve_explainer_steps() {
    return [
        'Pick a date. Prices are on the calendar.',
        'Send a request. No charge yet.',
        'We approve it and email a payment link.',
        'Within 1 day of signing, pay 50% of the invoice. That deposit holds the date. The $250 refundable damage deposit is separate.',
        'Pay the rest a week before your event.',
    ];
}
define('BRB_BOOK_SLUG', 'reserve-the-boardroom-quad-cities');
function brb_book_url() {
    return home_url('/' . BRB_BOOK_SLUG . '/');
}
function brb_request_path() {
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    $home = trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($home !== '' && ($path === $home || strpos($path, $home . '/') === 0)) {
        $path = trim(substr($path, strlen($home)), '/');
    }
    return $path;
}
function brb_legacy_book_target() {
    if (brb_request_path() !== 'book') return '';
    $target = brb_book_url();
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($query !== '') $target .= '?' . $query;
    return $target;
}
function brb_legacy_book_redirect() {
    if (is_admin()) return;
    $target = brb_legacy_book_target();
    if ($target === '') return;
    wp_safe_redirect($target, 301);
    exit;
}
function brb_migrate_book_slug() {
    $page = get_page_by_path('book');
    if (!$page || $page->post_name === BRB_BOOK_SLUG) return;
    wp_update_post([
        'ID' => (int) $page->ID,
        'post_name' => BRB_BOOK_SLUG,
    ]);
    clean_post_cache((int) $page->ID);
}
define('BRB_VENUE_ADDRESS', '2220 37th Street, Moline, IL 61265');
define('BRB_CHECK_ADDRESS', '2212 37th Street, Moline, IL 61265');
define('BRB_MAPS_URL', 'https://maps.app.goo.gl/nVqUsGTcy48nPzc18');
define('BRB_MAPS_LAT', '41.4913116');
define('BRB_MAPS_LNG', '-90.4820481');
require_once __DIR__ . '/legal-content.php';
require_once __DIR__ . '/tour.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/lines.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/llms.php';
require_once __DIR__ . '/followup.php';
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
function brb_format_local_mysql($mysql) {
    if (!$mysql) return '';
    try {
        $dt = new DateTimeImmutable($mysql, brb_tz());
        return $dt->format('D, M j, Y g:i A');
    } catch (Exception $e) {
        return '';
    }
}
function brb_date_taken_message() {
    return 'That date is already reserved or booked.';
}
function brb_blocking_statuses() {
    // A request in review does not reserve the date. Approving it, or a paid
    // deposit, does. Legacy held/confirmed rows are the same as approved/paid.
    return ['approved', 'held', 'deposit_paid', 'paid', 'confirmed'];
}
function brb_dec($n) { return number_format(round((float) $n, 2), 2, '.', ''); }
function brb_money($n) { return '$' . number_format((float) $n, 2); }
function brb_whole_money($n) {
    $n = round((float) $n, 2);
    if (abs($n - round($n)) < 0.001) return '$' . number_format((float) round($n), 0);
    return brb_money($n);
}
function brb_ref($b) {
    $id = is_object($b) ? (int) ($b->id ?? 0) : (int) $b;
    return (brb_is_production() ? 'BR-' : 'BR-S') . $id;
}
function brb_ref_id($query) {
    $query = trim((string) $query);
    if (preg_match('/^BR-S(\d+)$/i', $query, $m)) return (int) $m[1];
    if (preg_match('/^BR-(\d+)$/i', $query, $m)) return (int) $m[1];
    return 0;
}
function brb_price_sentence() {
    $start = brb_whole_money(brb_opt('starting_price'));
    return 'Pricing from ' . $start . ' per day, final price confirmed based on date, guest count and features.';
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
        'mode' => 'day',
        'day_rate' => '500',
        'hourly_rate' => '75',
        'starting_price' => '850',
        'typical_price' => '',
        'min_hours' => '2',
        'open_hour' => '10',
        'close_hour' => '23',
        'cleaning_fee' => '100',
        'tax_rate' => '7',
        'deposit_type' => 'percent',
        'deposit_pct' => '50',
        'deposit_fixed' => '0',
        'balance_due_days' => '7',
        'addon_price' => '500',
        'damage_deposit_amount' => '250',
        'max_guests' => '200',
        'calendar_rates' => '',
        'date_notes' => '',
        'max_days' => '7',
        'min_notice_hours' => '48',
        'hold_hours' => '48',
        'currency' => 'usd',
        'admin_email' => $admin,
        'tour_email' => 'boardroomqc@gmail.com',
        'payee' => 'Boardroom QC LLC',
        'venue_address' => BRB_VENUE_ADDRESS,
        'pay_location' => BRB_VENUE_ADDRESS,
        'pay_hours' => '',
        'pay_instructions' => '',
        'card_fee_percent' => '3',
        'blackouts' => '',
        'policy' => brb_cancellation_summary(),
        'checklist' => '',
        'quote_prices' => '1',
        'rate_sun_thu' => '850',
        'rate_fri_sat' => '1400',
        'rate_ranges' => wp_json_encode(brb_default_rate_ranges()),
        'mail_from' => 'boardroomqc@gmail.com',
        'mail_from_name' => 'The Boardroom',
        'mail_reply_to' => 'boardroomqc@gmail.com',
        'inquiry_cc' => '',
        'ga_measurement_id' => '',
        'ga_property_id' => '',
        'weekly_summary' => '0',
        'weekly_summary_emails' => '',
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
    $o['mode'] = 'day';
    if ($k === null) return $o;
    return $o[$k] ?? '';
}
function brb_venue_address() {
    $v = trim((string) brb_opt('venue_address'));
    return $v !== '' ? $v : BRB_VENUE_ADDRESS;
}
function brb_check_address() {
    $v = trim((string) brb_opt('pay_location'));
    return $v !== '' ? $v : BRB_VENUE_ADDRESS;
}
function brb_guest_payee() {
    $payee = trim((string) brb_opt('payee'));
    if ($payee === '' || $payee === '[VERIFY] Name on the check') return 'Boardroom QC LLC';
    return $payee;
}
function brb_check_sentence($item = 'checks') {
    $place = brb_check_address();
    if ($place === brb_venue_address()) $place = 'The Boardroom, ' . $place;
    return 'Mail or drop off ' . $item . ' payable to ' . brb_guest_payee() . ' at ' . $place . '.';
}
function brb_appointment_line() {
    return 'We don\'t keep set hours. We meet on demand and set a time together, by appointment.';
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
    return '';
}
function brb_public_guest_cap() {
    return 80;
}
function brb_parking_car_span() {
    return '40 to 50';
}
function brb_faq_source_html() {
    $page = get_page_by_path('faq');
    if ($page && trim((string) $page->post_content) !== '') return (string) $page->post_content;
    return brb_faq_markup();
}
function brb_faq_plain_text($html) {
    $text = html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xc2\xa0", ' ', $text);
    $collapsed = preg_replace('/\s+/u', ' ', $text);
    if (!is_string($collapsed)) $collapsed = preg_replace('/\s+/', ' ', $text);
    return trim((string) $collapsed);
}
function brb_faq_qa_pairs($html) {
    $pairs = [];
    if (!preg_match_all('/<details\b[^>]*>\s*<summary\b[^>]*>(.*?)<\/summary>(.*?)<\/details>/is', (string) $html, $matches, PREG_SET_ORDER)) return $pairs;
    foreach ($matches as $match) {
        $question = brb_faq_plain_text($match[1]);
        $answer = brb_faq_plain_text($match[2]);
        if ($question === '' || $answer === '') continue;
        $pairs[] = ['question' => $question, 'answer' => $answer];
    }
    return $pairs;
}
function brb_faq_answer_has_dollar_amount($answer) {
    return (bool) preg_match('/\$\s*\d/', (string) $answer);
}
function brb_faq_rendered_html() {
    return apply_filters('the_content', brb_faq_source_html());
}
function brb_faq_states_event_window($html = null) {
    static $cached = null;
    if ($html === null && $cached !== null) return $cached;
    if ($html === null) $html = brb_faq_rendered_html();
    $states = (bool) preg_match('/10(?::00)?\s*a\.?m\.?\s+to\s+11:30\s*p\.?m\.?/i', brb_faq_plain_text($html));
    if ($cached === null && func_num_args() === 0) $cached = $states;
    return $states;
}
function brb_faq_schema_entities($html = null) {
    if ($html === null) $html = brb_faq_rendered_html();
    $entities = [];
    foreach (brb_faq_qa_pairs($html) as $pair) {
        if (brb_faq_answer_has_dollar_amount($pair['answer'])) continue;
        $entities[] = [
            '@type' => 'Question',
            'name' => $pair['question'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $pair['answer'],
            ],
        ];
    }
    return $entities;
}
function brb_place_schema() {
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'EventVenue',
        'name' => 'The Boardroom',
        'legalName' => 'Boardroom QC LLC',
        'email' => 'boardroomqc@gmail.com',
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
        'url' => home_url('/'),
        'maximumAttendeeCapacity' => brb_public_guest_cap(),
        'sameAs' => [BRB_MAPS_URL],
    ];
    if (brb_faq_states_event_window()) {
        $data['openingHoursSpecification'] = [[
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
            'opens' => '10:00',
            'closes' => '23:30',
        ]];
    }
    echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_SLASHES) . "</script>\n";
}
add_action('wp_head', 'brb_place_schema', 20);
function brb_faq_schema() {
    if (!is_page('faq')) return;
    $html = brb_faq_rendered_html();
    $entities = brb_faq_schema_entities($html);
    if (!$entities) return;
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $entities,
    ];
    echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_SLASHES) . "</script>\n";
}
add_action('wp_head', 'brb_faq_schema', 21);
function brb_blackouts() {
    $out = [];
    foreach (preg_split('/[\s,]+/', (string) brb_opt('blackouts')) as $d) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $out[] = $d;
    }
    return $out;
}
function brb_hold_table() {
    global $wpdb;
    return $wpdb->prefix . 'br_holds';
}
function brb_hold_types() {
    return [
        'reserved' => 'Reserved, paid elsewhere/cash',
        'hold' => 'Hold, unpaid',
        'blocked' => 'Blocked',
    ];
}
function brb_install_hold_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $t = brb_hold_table();
    dbDelta("CREATE TABLE $t (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      name varchar(190) NOT NULL DEFAULT '',
      hold_type varchar(20) NOT NULL DEFAULT 'blocked',
      start_dt datetime NOT NULL,
      end_dt datetime NOT NULL,
      notes text,
      created datetime NOT NULL,
      PRIMARY KEY  (id),
      KEY range_idx (start_dt, end_dt)
    ) $c;");
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
}
function brb_hold($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . brb_hold_table() . ' WHERE id=%d', (int) $id));
}
function brb_holds_overlapping($start, $end) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        'SELECT id, name, hold_type, start_dt, end_dt, notes FROM ' . brb_hold_table() . ' WHERE start_dt < %s AND end_dt > %s ORDER BY start_dt ASC, id ASC',
        $end,
        $start
    ));
}
function brb_save_hold($input) {
    global $wpdb;
    $types = brb_hold_types();
    $name = sanitize_text_field((string) ($input['name'] ?? ''));
    $type = sanitize_key((string) ($input['hold_type'] ?? 'blocked'));
    if (!isset($types[$type])) $type = 'blocked';
    if ($name === '') $name = $types[$type];
    $start = brb_hold_moment($input['start_date'] ?? '', $input['start_time'] ?? '00:00');
    $end = brb_hold_moment($input['end_date'] ?? '', $input['end_time'] ?? '23:59');
    if (!$start || !$end) return new WP_Error('when', 'Enter a start and end.');
    if ($end <= $start) return new WP_Error('when', 'The end must be after the start.');
    $row = [
        'name' => mb_substr($name, 0, 190),
        'hold_type' => $type,
        'start_dt' => $start->format('Y-m-d H:i:s'),
        'end_dt' => $end->format('Y-m-d H:i:s'),
        'notes' => sanitize_textarea_field((string) ($input['notes'] ?? '')),
    ];
    $id = (int) ($input['id'] ?? 0);
    $exclude = ($id && brb_hold($id)) ? $id : 0;
    if (brb_range_has_booking($start, $end, 0) || brb_range_hits_hold($start, $end, $exclude)) {
        return new WP_Error('busy', brb_date_taken_message());
    }
    if ($id && brb_hold($id)) {
        $wpdb->update(brb_hold_table(), $row, ['id' => $id]);
    } else {
        $row['created'] = current_time('mysql', true);
        $wpdb->insert(brb_hold_table(), $row);
        $id = (int) $wpdb->insert_id;
    }
    $saved = brb_hold($id);
    return $saved ?: new WP_Error('db', 'Could not save that hold.');
}
function brb_hold_moment($date, $time) {
    $date = trim((string) $date);
    $time = trim((string) $time);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time = '00:00';
    try {
        return new DateTimeImmutable($date . ' ' . $time . ':00', brb_tz());
    } catch (Exception $e) {
        return null;
    }
}
function brb_delete_hold($id) {
    global $wpdb;
    $hold = brb_hold($id);
    if (!$hold) return new WP_Error('missing', 'That hold was not found.');
    $wpdb->delete(brb_hold_table(), ['id' => (int) $id], ['%d']);
    return true;
}
function brb_drop_blackout_date($ymd) {
    $left = array_values(array_filter(brb_blackouts(), function ($d) use ($ymd) {
        return $d !== $ymd;
    }));
    $o = brb_opt();
    $o['blackouts'] = implode("\n", $left);
    update_option('brb_settings', $o);
}
function brb_range_hits_hold($s, $e, $exclude_id = 0) {
    $exclude_id = (int) $exclude_id;
    foreach (brb_holds_overlapping($s->format('Y-m-d H:i:s'), $e->format('Y-m-d H:i:s')) as $row) {
        if ($exclude_id && (int) $row->id === $exclude_id) continue;
        return true;
    }
    return false;
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
        'spam' => 'Flagged as spam/joke',
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
        'graduation' => 'Graduation',
        'shower' => 'Shower',
        'reunion' => 'Reunion',
        'holiday' => 'Holiday party',
        'corporate' => 'Corporate',
        'fundraiser' => 'Fundraiser (private, no ticket sales)',
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
        'under_25' => 'Under 25',
        'age_25' => '25 or older',
    ];
}
function brb_host_age_label($value) {
    $legacy = [
        'under_21' => 'Under 21',
        'age_21_24' => '21 to 24',
    ];
    $ages = brb_host_ages() + $legacy;
    return $ages[$value] ?? '';
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
function brb_out_by_line() {
    return 'Everyone must be out of The Boardroom by 11:30 PM. This is firm, with no exceptions. Please finish in time to wrap up.';
}
function brb_guest_hello_line() {
    return 'Thank you for contacting The Boardroom!';
}
function brb_guest_review_line() {
    return 'We review your request. Once it\'s approved, we\'ll email you a payment link. Your date is reserved once your deposit is received and approved by the Boardroom.';
}
function brb_terms_review_line() {
    return 'All bookings are subject to review and approval by the Boardroom. Approval is based on the event details provided, availability, and our house rules.';
}
function brb_price_range_sentence() {
    return 'The price for a date is on the calendar. Your exact price is confirmed by email.';
}
function brb_damage_card_refund_line() {
    return 'If you paid the damage deposit by card, it is refunded through Stripe, less the card processing fee, after the event once the space is checked.';
}
function brb_damage_public_line() {
    return 'The refundable damage deposit is a flat $250. It is charged with the booking deposit and mainly covers cleaning. A dumpster is on site. It is refunded through Stripe, less the card processing fee, after the event once the space is checked.';
}
function brb_first_payment_parts($rental = 1400.0) {
    $rental = round((float) $rental, 2);
    $deposit = brb_deposit_due_amount($rental);
    $damage = round((float) brb_opt('damage_deposit_amount'), 2);
    if ($damage < 0) $damage = 0;
    $base = round($deposit + $damage, 2);
    $base_cents = brb_cents_from_amount($base);
    $fee_cents = brb_card_fee_cents($base_cents);
    $card = ($base_cents + $fee_cents) / 100;
    return [
        'rental' => $rental,
        'deposit' => $deposit,
        'damage' => $damage,
        'base' => $base,
        'fee' => $fee_cents / 100,
        'card' => $card,
    ];
}
function brb_first_payment_sentence() {
    $ex = brb_first_payment_parts(1400);
    return 'Your first payment is 50% of the rental plus the ' . brb_whole_money($ex['damage']) . ' refundable damage deposit; card payments add a 3% fee. For example, on a ' . brb_whole_money($ex['rental']) . ' rental the first card payment is ' . brb_whole_money($ex['deposit']) . ' + ' . brb_whole_money($ex['damage']) . ' = ' . brb_whole_money($ex['base']) . ', plus a 3% fee of ' . brb_money($ex['fee']) . ', for ' . brb_money($ex['card']) . '.';
}
function brb_deposit_bullets() {
    return [
        'The deposit is 50% of the agreed rental. It holds the date once it is received.',
        'It is refundable if written cancellation is received 60 or more days before the event, less the non-refundable 3% card fee and costs already incurred.',
        'It is not refundable if cancellation is received less than 60 days before the event.',
    ];
}
function brb_deposit_bullets_html() {
    $html = '<ul class="brb-deposit-bullets">';
    foreach (brb_deposit_bullets() as $line) $html .= '<li>' . esc_html($line) . '</li>';
    $html .= '</ul><p><a href="' . esc_url(home_url('/terms/')) . '">Read the Rental Terms</a></p>';
    return $html;
}
function brb_how_booking_works_steps() {
    return [
        'Pick your date. The price shows right on the calendar.',
        'Send your request. Nothing is charged yet.',
        'We review it. Once it\'s approved, we\'ll email you a payment link.',
        'Lock in your date. Pay 50% of the rental plus the $250 refundable damage deposit by card (3% fee) or check, and agree to the Terms and Rental Agreement.',
        'You\'re booked! Pay the balance by check or card no later than one week before your event. Your status link stays with your booking, and we\'ll send a checklist reminder about a week before your event.',
    ];
}
function brb_how_booking_works_html() {
    $facts = function_exists('br_facts_strip') ? br_facts_strip([
        'Prices on the Calendar',
        'No Charge to Request',
        '50% Holds Your Date',
        '$250 Refundable Damage Deposit',
        'Balance Due 1 Week Before',
    ], 0) : '';
    return '<section class="brb-how"><h2>Pick a Date</h2>' . $facts . '</section>';
}
function brb_book_policy_html() {
    $sections = [
        ['Pricing', 'Full days start at $850. The price for a date is on the calendar, and we confirm the final price by email.'],
        ['Payment', 'To hold your date, pay 50% of the rental plus a $250 refundable damage deposit. Card payments add a 3% fee. The damage deposit is refunded after the event, once the space is checked.'],
        ['Cancellation', 'If you cancel in writing 60 or more days before the event, your 50% payment is refunded, less the 3% card fee and any costs already incurred. Cancellations made less than 60 days out are not refunded.'],
    ];
    $html = '<section class="brb-book-policy">';
    foreach ($sections as $section) {
        $html .= '<h3>' . esc_html($section[0]) . '</h3><p>' . esc_html($section[1]) . '</p>';
    }
    $html .= '</section>';
    return $html;
}
function brb_latest_start_hour() {
    $min = max(1, (int) brb_opt('min_hours'));
    $latest = (int) floor(((23 * 60 + 30) - ($min * 60)) / 60);
    $open = (int) brb_opt('open_hour');
    if ($latest < $open) $latest = $open;
    return $latest;
}
function brb_named_blackout_map() {
    return [
        '2027-04-17' => 'Karyn Miller (paid elsewhere)',
        '2026-12-06' => 'Kevin Iams',
        '2027-02-07' => 'Brody',
    ];
}
function brb_hold_covers_date($ymd) {
    $start = $ymd . ' 00:00:00';
    try {
        $next = (new DateTimeImmutable($ymd . ' 00:00:00', brb_tz()))->modify('+1 day')->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return false;
    }
    return !empty(brb_holds_overlapping($start, $next));
}
function brb_import_named_blackouts() {
    $made = 0;
    foreach (brb_named_blackout_map() as $ymd => $name) {
        if (!in_array($ymd, brb_blackouts(), true) && !brb_hold_covers_date($ymd)) continue;
        if (!brb_hold_covers_date($ymd)) {
            $saved = brb_save_hold([
                'name' => $name,
                'hold_type' => 'reserved',
                'start_date' => $ymd,
                'start_time' => '00:00',
                'end_date' => $ymd,
                'end_time' => '23:59',
                'notes' => 'Converted from a blackout date.',
            ]);
            if (!is_wp_error($saved)) $made++;
        }
        if (in_array($ymd, brb_blackouts(), true)) brb_drop_blackout_date($ymd);
    }
    return $made;
}
function brb_addon_price() {
    $n = (float) brb_opt('addon_price');
    if ($n <= 0) $n = 500;
    return $n;
}
function brb_guest_out_line() {
    return 'Please plan to be out by 11:30 PM.';
}
function brb_guest_free_line() {
    return 'Events are free to attend: no tickets, no cover charge, no paid admission, no door fees, and no ticket or merchandise sales. A band or live music is welcome when guests are not charged for the event.';
}
function brb_byob_line() {
    return 'BYOB is allowed.';
}
function brb_checklist_default() {
    return implode("\n", [
        'Sign the rental agreement.',
        'The deposit is received and approved.',
        'The balance is due no later than one week before the event.',
        'The refundable damage deposit is a flat $250. It is charged with the booking deposit and returned if the room is left as you found it.',
        'The damage deposit is refunded after the event, once the space is checked, by your original payment method: through Stripe for card payments (less the card processing fee) or by check.',
        'Setup is the morning of the event. Setup can begin at 8:00 AM.',
        'Everyone is out by 11:30 PM.',
        'All events are free to attend: no tickets, no cover charge, and no sales.',
        brb_byob_line(),
        'Bring your own plates, cups, and utensils. Buffet pans and serving spoons are available. The microwave, blender and food warmers are fine. The stovetops and oven can\'t be used.',
        'Not allowed: Confetti, confetti cannons, rice, birdseed, or loose sequins.',
        'Not allowed: Wall tape.',
        'Not allowed: Fireworks, sparklers, smoke bombs, pyrotechnics, or explosive or combustible props.',
        'Not allowed: Open flame, unless we approve it in writing.',
        'Not allowed: Hazardous materials or illegal substances.',
        'Not allowed: Weapons, except as the law allows.',
        'Not allowed: Anything that creates a big mess, a fire or safety risk, a nuisance, or a disturbance.',
        'Tickets, cover charges, paid admission, door fees, and ticket or merchandise sales aren\'t allowed either.',
        'Vaping leaves a residue. All smoking is outside.',
        'Balloons are fine, no glitter.',
        'Please clean up and take the garbage to the dumpster.',
        'Renters insurance covering your event is required. We may ask for proof before giving you access.',
        'Vendors (caterer, DJ, bartender) send proof of insurance at least 7 days before.',
        'The minimum host age is 25.',
    ]);
}
function brb_checklist_policy_text($text) {
    $lines = [];
    $saw_vendor = false;
    foreach (preg_split("/\r\n|\n|\r/", (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, 'Optional setup or takedown day add-ons') === 0) continue;
        if ($line === 'The space is quiet after 11:30 PM, and nothing is outdoors.') continue;
        if ($line === 'Consider your own renters or event insurance.') {
            $line = 'Renters insurance covering your event is required. We may ask for proof before giving you access.';
        }
        if ($line === 'If you paid the damage deposit by card, it is refunded to the same card after the event once the space is checked.') {
            $line = 'The damage deposit is refunded after the event, once the space is checked, by your original payment method: through Stripe for card payments (less the card processing fee) or by check.';
        }
        if (strpos($line, 'The stovetop cannot be used.') !== false) {
            $line = str_replace('The stovetop cannot be used.', 'The microwave, blender and food warmers are fine. The stovetops and oven can\'t be used.', $line);
        }
        if (strpos($line, 'Vendors (caterer, DJ, bartender) send proof of insurance') === 0) $saw_vendor = true;
        $lines[] = $line;
    }
    if (!$saw_vendor) {
        $next = [];
        $inserted = false;
        foreach ($lines as $line) {
            $next[] = $line;
            if (!$inserted && strpos($line, 'Renters insurance covering your event is required') === 0) {
                $next[] = 'Vendors (caterer, DJ, bartender) send proof of insurance at least 7 days before.';
                $inserted = true;
            }
        }
        if (!$inserted) $next[] = 'Vendors (caterer, DJ, bartender) send proof of insurance at least 7 days before.';
        $lines = $next;
    }
    return implode("\n", $lines);
}
function brb_checklist_text() {
    $text = trim((string) brb_opt('checklist'));
    if ($text === '') $text = brb_checklist_default();
    return brb_checklist_policy_text($text);
}
function brb_checklist_lines() {
    $lines = [];
    $saw_balloons = false;
    foreach (preg_split("/\r\n|\n|\r/", brb_checklist_text()) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if ($line === 'Balloons are fine.' || $line === 'Balloons are fine, no glitter.') {
            if ($saw_balloons) continue;
            $line = 'Balloons are fine, no glitter.';
            $saw_balloons = true;
        }
        if ($line === 'Not allowed: Glitter, confetti, confetti cannons, rice, birdseed, or loose sequins.' || $line === 'Not allowed: Glitter and confetti.') {
            $line = 'Not allowed: Confetti, confetti cannons, rice, birdseed, or loose sequins.';
        }
        $lines[] = $line;
    }
    if (!$saw_balloons) {
        $next = [];
        $inserted = false;
        foreach ($lines as $line) {
            $next[] = $line;
            if (!$inserted && strpos($line, 'confetti cannons') !== false) {
                $next[] = 'Balloons are fine, no glitter.';
                $inserted = true;
            }
        }
        if (!$inserted) $next[] = 'Balloons are fine, no glitter.';
        $lines = $next;
    }
    return $lines;
}
function brb_checklist_plain() {
    $out = "Rental checklist\n";
    foreach (brb_checklist_lines() as $line) $out .= '- ' . $line . "\n";
    $out .= "\nThe checklist is also at " . home_url('/rental-checklist/') . "\n";
    return $out;
}
function brb_checklist_html() {
    $url = 'https://moline.il.us/244/Liquor-Licensing';
    $items = '';
    foreach (brb_checklist_lines() as $line) {
        $safe = esc_html($line);
        $safe = str_replace(esc_html($url), '<a href="' . esc_url($url) . '">' . esc_html($url) . '</a>', $safe);
        $items .= '<li>' . $safe . '</li>';
    }
    return '<ul class="brb-checklist">' . $items . '</ul>';
}
function brb_checklist_stored_markup() {
    return '<p>Here is a friendly checklist for your event at The Boardroom. We also email it when a request is approved, and again about 7 days before the event.</p>[brb_checklist]';
}
function brb_replace_liquor_fee($text) {
    $text = str_replace('BYOB is allowed, and the renter must obtain a one-day liquor license from the City of Moline, fee $50.', brb_byob_line(), (string) $text);
    $text = str_replace('The renter must obtain a one-day liquor license from the City of Moline, fee $50.', brb_byob_line(), $text);
    $text = str_replace('Guests must get the one-day City of Moline liquor license, fee $50.', brb_byob_line(), $text);
    return $text;
}
function brb_hours_line() {
    return 'A full day runs from 10:00 AM to 11:30 PM.';
}
function brb_half_day_month($day) {
    return (int) $day->format('n') === 6;
}
function brb_free_admission_rule() {
    return 'All events must be free to attend: no tickets, no cover charge, no paid admission, no door fees, and no ticket or merchandise sales. Guests may have a band or live music, as long as they are not charging for the event. We do not host concerts or ticketed shows.';
}
function brb_end_time_error($end, $start = null) {
    $end = (string) $end;
    if (!preg_match('/^(\d{2}):(\d{2})$/', $end, $m)) return 'Please enter what time your event will end.';
    $h = (int) $m[1];
    $min = (int) $m[2];
    if ($h > 23 || $min > 59) return 'Please enter what time your event will end.';
    $mins = $h * 60 + $min;
    $latest = 23 * 60 + 30;
    if ($mins > $latest || $mins < 5 * 60) {
        return 'Everyone must be out of The Boardroom by 11:30 PM. Please choose an end time no later than 11:30 PM so there is time to wrap up. An early-morning time is not available.';
    }
    if ($start instanceof DateTimeImmutable) {
        $start_mins = (int) $start->format('H') * 60 + (int) $start->format('i');
        if ($start_mins > 0 && $mins <= $start_mins) {
            return 'That end time is before the booking starts. Please choose a time after the start and no later than 11:30 PM. Everyone must be out by 11:30 PM.';
        }
    }
    return '';
}
function brb_deposit_refund_rule() {
    return 'It is refundable if written cancellation is received 60 or more days before the event, less any non-refundable processing charges (including the ' . brb_fee_percent_label() . ' card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 60 days before the event. Refunds are issued manually.';
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
function brb_screening_lines($b) {
    $lines = [];
    if (($b->free_admission ?? '') === '1') $lines[] = 'Free admission acknowledged: Yes';
    if (($b->public_event ?? '') === 'no') $lines[] = 'Private, invitation-only: Yes';
    elseif (($b->public_event ?? '') === 'yes') $lines[] = 'Private, invitation-only: No, it is open to the public. Staff review required.';
    $alcohol = brb_yes_no_label($b->alcohol ?? '');
    if ($alcohol !== '') $lines[] = 'Alcohol served: ' . $alcohol;
    $end = trim((string) ($b->end_time ?? ''));
    if ($end !== '') $lines[] = 'Planned end time: ' . brb_end_time_label($end);
    $age = brb_host_age_label($b->host_age ?? '');
    if ($age !== '') $lines[] = 'Host age bracket: ' . $age;
    $about = trim((string) ($b->event_about ?? ''));
    if ($about !== '') $lines[] = 'About the event: ' . $about;
    return $lines;
}
function brb_collect_screening($post, $required = true, $start = null) {
    unset($start);
    $free = sanitize_key($post['free_admission'] ?? '');
    $about = sanitize_textarea_field($post['event_about'] ?? '');
    if ($required) {
        if ($free !== '1') return new WP_Error('free_admission', 'Please confirm that the event is free to attend.');
        if ($about === '') return new WP_Error('event_about', 'Please tell us about your event.');
    } else {
        if ($free !== '1') $free = '';
    }
    return [
        'public_event' => '',
        'free_admission' => $free === '1' ? '1' : '',
        'alcohol' => '',
        'end_time' => '',
        'host_age' => '',
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
      free_admission varchar(3) NOT NULL DEFAULT '',
      waiver_ack varchar(3) NOT NULL DEFAULT '',
      walkthrough_done tinyint(1) NOT NULL DEFAULT 0,
      damage_deposit decimal(10,2) NOT NULL DEFAULT 0.00,
      setup_addon tinyint(1) NOT NULL DEFAULT 0,
      takedown_addon tinyint(1) NOT NULL DEFAULT 0,
      addon_prompted tinyint(1) NOT NULL DEFAULT 0,
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
    brb_ensure_column($t, 'audit_log', 'longtext NULL');
    brb_ensure_column($t, 'free_admission', "varchar(3) NOT NULL DEFAULT ''");
    brb_ensure_column($t, 'waiver_ack', "varchar(3) NOT NULL DEFAULT ''");
    brb_ensure_column($t, 'walkthrough_done', 'tinyint(1) NOT NULL DEFAULT 0');
    brb_ensure_column($t, 'damage_deposit', 'decimal(10,2) NOT NULL DEFAULT 0.00');
    brb_ensure_column($t, 'setup_addon', 'tinyint(1) NOT NULL DEFAULT 0');
    brb_ensure_column($t, 'takedown_addon', 'tinyint(1) NOT NULL DEFAULT 0');
    brb_ensure_column($t, 'addon_prompted', 'tinyint(1) NOT NULL DEFAULT 0');
    brb_ensure_column($t, 'checklist_reminded', 'datetime NULL DEFAULT NULL');
    brb_ensure_column($t, 'damage_paid', 'decimal(10,2) NOT NULL DEFAULT 0.00');
    brb_ensure_column($t, 'damage_refunded', 'tinyint(1) NOT NULL DEFAULT 0');
    // Staff follow-up log (followup.php). Separate from status and audit_log.
    brb_ensure_column($t, 'follow_up_log', 'longtext NULL');
    brb_install_line_table();
    brb_install_hold_table();
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
    if ((string) ($stored['open_hour'] ?? '') === '9') $merged['open_hour'] = '10';
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
    if (stripos((string) ($merged['pay_hours'] ?? ''), '[VERIFY]') !== false) $merged['pay_hours'] = '';
    $payee = trim((string) ($merged['payee'] ?? ''));
    // 2.5.65: the check payee is Boardroom QC LLC. Only the old default (or a blank) is moved; a custom payee is kept.
    if ($payee === '' || $payee === '[VERIFY] Name on the check' || $payee === 'Mad Productions') $merged['payee'] = 'Boardroom QC LLC';
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
    if ($location === '' || stripos($location, '[VERIFY]') !== false || stripos($location, '2212 47') !== false || $location === BRB_CHECK_ADDRESS) {
        $merged['pay_location'] = BRB_VENUE_ADDRESS;
    }
    $venue = trim((string) ($stored['venue_address'] ?? ''));
    if ($venue === '' || stripos($venue, '[VERIFY]') !== false || stripos($venue, '2212 47') !== false || $venue === BRB_CHECK_ADDRESS) {
        $merged['venue_address'] = BRB_VENUE_ADDRESS;
    }
    $old_policy = 'A date saved on the website is held for 48 hours while you bring a check in person.';
    if (strpos((string) $merged['policy'], $old_policy) !== false) {
        $merged['policy'] = str_replace($old_policy, brb_cancellation_summary(), $merged['policy']);
    }
    $old_balance = 'The invoice notes require full payment 30 days before the event.';
    $week_balance = 'The remaining balance is due the week of the event, no later than 48 hours before the event.';
    $one_week = 'The remaining balance is due no later than one week before the event.';
    $policy = (string) ($merged['policy'] ?? '');
    if (strpos($policy, $old_balance) !== false) $policy = str_replace($old_balance, $one_week, $policy);
    if (strpos($policy, $week_balance) !== false) $policy = str_replace($week_balance, $one_week, $policy);
    $policy = str_replace(
        'Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265.',
        'Mail or drop off checks payable to Boardroom QC LLC at The Boardroom, 2220 37th Street, Moline, IL 61265.',
        $policy
    );
    $policy = str_replace('checks payable to Mad Productions at The Boardroom, ', 'checks payable to Boardroom QC LLC at The Boardroom, ', $policy);
    $merged['policy'] = $policy;
    if (!isset($stored['policy']) || brb_policy_needs_refresh($stored['policy'])) {
        $merged['policy'] = brb_cancellation_summary();
    }
    if (!isset($stored['quote_prices'])) $merged['quote_prices'] = '1';
    if (!isset($stored['calendar_rates']) || brb_calendar_rates_are_empty($stored['calendar_rates'])) {
        $merged['calendar_rates'] = wp_json_encode(brb_default_calendar_rates());
    }
    $start = trim((string) ($stored['starting_price'] ?? ''));
    if ($start === '' || $start === '600' || $start === '600.00') $merged['starting_price'] = '850';
    if (trim((string) ($merged['typical_price'] ?? '')) === 'most events around $1,200') $merged['typical_price'] = '';
    if (!isset($stored['rate_sun_thu']) || !is_numeric($stored['rate_sun_thu']) || (float) $stored['rate_sun_thu'] <= 0) $merged['rate_sun_thu'] = '850';
    if (!isset($stored['rate_fri_sat']) || !is_numeric($stored['rate_fri_sat']) || (float) $stored['rate_fri_sat'] <= 0) $merged['rate_fri_sat'] = '1400';
    $ranges = json_decode((string) ($stored['rate_ranges'] ?? ''), true);
    if (!isset($stored['rate_ranges']) || !is_array($ranges)) $merged['rate_ranges'] = wp_json_encode(brb_default_rate_ranges());
    $merged['mode'] = 'day';
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
// [CONFIRM with Chris] Guests out by 11:30 PM. No smoking or vaping. Service animals only.
function brb_faq_markup() {
    $html = <<<'HTML'
<h2>Booking and dates</h2>
<details><summary>How do I book?</summary><p>We'd love to host you. Pick your date on the <a href="/reserve-the-boardroom-quad-cities/">Book</a> page. The price shows right on the calendar. Send a request. Nothing is charged yet. Once it's approved, we'll email you a payment link. Your date is reserved once your deposit is received and approved by the Boardroom. A request does not hold the date. Bookings need at least 48 hours' notice.</p></details>
<details><summary>How booking works</summary><p>HOW_STEPS</p></details>
<details><summary>Are overnight stays allowed?</summary><p>No overnight stays. The Boardroom is an event space only.</p></details>
<details><summary>What dates are open?</summary><p>Use the calendar on the <a href="/reserve-the-boardroom-quad-cities/">Book</a> page, or ask us about a date. We'll tell you if it looks open.</p></details>
<details><summary>Can I rent for a few hours?</summary><p>Each booking is one full day, 10:00 AM to 11:30 PM, with setup from 8:00 AM. Half-day and hourly rentals aren't offered.</p></details>

<h2>Pricing and payment</h2>
<details><summary>How do I pay?</summary><p>After we approve the request, pay by check or in person, or online by card with a 3% processing fee. Mail or drop off checks payable to Boardroom QC LLC at The Boardroom, 2220 37th Street, Moline, IL 61265. We don't keep set hours. We meet on demand and set a time together, by appointment. The deposit (50% of the agreed total) holds the date. The balance is due no later than one week before the event. Sales tax is included in the price. The card processing fee is non-refundable.</p></details>
<details><summary>Can I get an invoice or a receipt?</summary><p>Yes. Card payments get an emailed receipt, and we can provide an invoice or receipt for any payment on request.</p></details>
<details><summary>Is sales tax included?</summary><p>Sales tax is included in the price.</p></details>

<h2>Setup and hours</h2>
<details><summary>What are the hours?</summary><p>A full day runs from 10:00 AM to 11:30 PM. We don't keep set hours for meetings or check drop-off. We meet on demand and set a time together, by appointment.</p></details>
<details><summary>When does everyone have to be out?</summary><p>Out by 11:30 PM. This one's firm, no exceptions. Everyone must be out of The Boardroom by 11:30 PM so there's time to wrap up.</p></details>
<details><summary>How is the space set up?</summary><p>The space arrives not set up. You set up and tear down. Staff cannot set the room up. Setup can begin at 8:00 AM.</p></details>
<details><summary>Do you provide decorations?</summary><p>The room doesn't come decorated, though Christmas decorations are up in December. You may bring and put up your own decorations. Glitter, confetti, confetti cannons, rice, birdseed, and loose sequins aren't allowed.</p></details>

<h2>Food and drink</h2>
<details><summary>Can I bring my own food?</summary><p>Yes. We provide the space; you bring the food. You can use the microwave, blender, food warmers and the bar area. The stovetops and oven can't be used. You can also have it catered by any caterer. The Boardroom does not provide catering.</p></details>
<details><summary>Do I need to bring plates and utensils?</summary><p>Yes. Bring your own plates, cups and utensils. Buffet pans and serving spoons are available.</p></details>
<details><summary>Can we bring our own drinks?</summary><p>Yes. There's a bar area and an ice machine, and there are no taps. BYOB is allowed.</p></details>
<details><summary>Is there a refrigerator or ice?</summary><p>Yes. There is a refrigerator and an ice machine.</p></details>

<h2>Rules</h2>
<details><summary>How many guests can The Boardroom hold?</summary><p>80 guests. Graduation parties or open houses can go over, since guests come and go.</p></details>
<details><summary>Is there an age limit for hosts?</summary><p>The minimum host age is 25 for every booking.</p></details>
<details><summary>Can we sell tickets or charge admission?</summary><p>No. All events must be free to attend: no tickets, no cover charge, no paid admission, no door fees, and no ticket or merchandise sales. We do not host concerts or ticketed shows.</p></details>
<details><summary>Can we have a band?</summary><p>Yes. Guests may have a band or live music, as long as they are not charging for the event.</p></details>
<details><summary>Can I hold a vendor market or sell items?</summary><p>No. All events are free to attend. There are no merchandise sales and no ticket sales.</p></details>
<details><summary>What decorations are allowed?</summary><p>You may bring and put up your own decorations. Balloons are fine. No wall tape, glitter or confetti. Confetti cannons, rice, birdseed, and loose sequins aren't allowed. The space doesn't come decorated, though Christmas decorations are up in December.</p></details>
<details><summary>Do I need insurance?</summary><p>Yes. You must carry renters insurance that covers your event, and we may ask for proof before giving you access. We also recommend event liability insurance, plus liquor liability if alcohol is served. Vendors (caterer, DJ, bartender) must send proof of insurance at least 7 days before.</p></details>
<details><summary>Is smoking allowed?</summary><p>All smoking, including cigars and cigarettes, is outside only. Vaping leaves a residue and can affect the damage deposit.</p></details>
<details><summary>Can I bring a pet?</summary><p>Service animals only. No pets.</p></details>

<h2>Policies and cancellation</h2>
<details><summary>What is the cancellation policy?</summary><p>If we receive written cancellation 60 or more days before the event, the deposit is refundable, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 60 days before the event. See the <a href="/terms/">Rental Terms</a>. If a summary and the signed agreement differ, the signed agreement controls.</p></details>
<details><summary>What if there is bad weather?</summary><p>If we have to cancel because of severe weather or another emergency outside our control, such as a severe storm, a snowstorm, or a power outage, the payment is refundable. A personal issue, such as a flat tire, is not refundable.</p></details>
<details><summary>What happens if something gets damaged?</summary><p>The damage deposit covers cleaning and damage. Anything beyond that deposit may be charged. See the <a href="/terms/">Terms</a>.</p></details>
<details><summary>Is there a damage deposit?</summary><p>The refundable damage deposit is a flat $250. It is charged with the booking deposit and mainly covers cleaning. A dumpster is on site. After the event, once the space is checked, it's refunded by your original payment method: through Stripe for card payments (less the card processing fee) or by check.</p></details>
<details><summary>What are our responsibilities before we leave?</summary><p>Please leave it like you got it. Clean and tidy the space, and take the garbage out. Another event is often booked the following day, and a ready room lets the next hosts begin on time.</p></details>

<h2>Facilities</h2>
<details><summary>Is parking available?</summary><p>Yes. Free parking on premises, with space for 40 to 50 cars.</p></details>
<details><summary>What is there to do?</summary><p>There's an 80-inch TV, pinball, pool, and shuffleboard.</p></details>
<details><summary>Who is on site during an event?</summary><p>No one is on site during events. Our connected offices are available on demand if something is needed. Cell numbers are given when the contract is signed, and they are not listed on this site.</p></details>
<details><summary>Is there a private entrance?</summary><p>There is a private main entrance. A back entrance can be used for a surprise guest when it is arranged ahead of time with our team.</p></details>
<details><summary>Is the space accessible?</summary><p>Yes. The space is handicapped accessible.</p></details>
<details><summary>If I pay the damage deposit by card, how is it returned?</summary><p>After the event, once the space is checked, it's refunded by your original payment method: through Stripe for card payments (less the card processing fee) or by check.</p></details>
HTML;
    return str_replace('HOW_STEPS', implode(' ', brb_how_booking_works_steps()), $html);
}

function brb_patch_faq_drinks() {
    $page = get_page_by_path('faq');
    if (!$page) return;
    $c = (string) $page->post_content;
    $replacements = [
        'The Boardroom has a BYOB bar and ice machine. <span class="placeholder">PLACEHOLDER: alcohol rules / age policy — owner to confirm.</span>' => 'The Boardroom has a bar area and an ice machine. No cooking is allowed in the venue. The stove top may not be used. Food warmers (for keeping prepared food warm) and the bar area may be used. There are no taps. Draft taps are not available. BYOB is allowed. Guests are responsible for obtaining any permit or license the City of Moline requires for alcohol at their event. Please check with the City of Moline: <a href="https://moline.il.us/244/Liquor-Licensing">https://moline.il.us/244/Liquor-Licensing</a> (City Clerk, 309-524-2004). See the <a href="/terms/">Rental Terms</a>.',
        'The Boardroom has a bar area and an ice machine. Do not use the stove top. Food warmers and the bar area may be used. Draft taps are not available. BYOB is allowed, and the renter must obtain a one-day liquor license. See the' => 'The Boardroom has a bar area and an ice machine. No cooking is allowed in the venue. The stove top may not be used. Food warmers (for keeping prepared food warm) and the bar area may be used. There are no taps. Draft taps are not available. BYOB is allowed. Guests are responsible for obtaining any permit or license the City of Moline requires for alcohol at their event. Please check with the City of Moline: <a href="https://moline.il.us/244/Liquor-Licensing">https://moline.il.us/244/Liquor-Licensing</a> (City Clerk, 309-524-2004). See the',
        'The Boardroom has a bar area and an ice machine. Do not use the stove top. Food warmers and the bar area may be used. Draft taps are not available. BYOB is allowed. Guests are responsible for obtaining any permit or license the City of Moline requires for alcohol at their event. Please check with the City of Moline: <a href="https://moline.il.us/244/Liquor-Licensing">https://moline.il.us/244/Liquor-Licensing</a> (City Clerk, 309-524-2004). See the' => 'The Boardroom has a bar area and an ice machine. No cooking is allowed in the venue. The stove top may not be used. Food warmers (for keeping prepared food warm) and the bar area may be used. There are no taps. Draft taps are not available. BYOB is allowed. Guests are responsible for obtaining any permit or license the City of Moline requires for alcohol at their event. Please check with the City of Moline: <a href="https://moline.il.us/244/Liquor-Licensing">https://moline.il.us/244/Liquor-Licensing</a> (City Clerk, 309-524-2004). See the',
        'BYOB is allowed, and the renter must obtain a one-day liquor license. See the' => 'BYOB is allowed. Guests are responsible for obtaining any permit or license the City of Moline requires for alcohol at their event. Please check with the City of Moline: <a href="https://moline.il.us/244/Liquor-Licensing">https://moline.il.us/244/Liquor-Licensing</a> (City Clerk, 309-524-2004). See the',
        'See our <a href="/terms-and-conditions/">Terms and Conditions</a>. <span class="placeholder">PLACEHOLDER — owner to supply.</span>' => 'See the <a href="/terms/">Rental Terms</a>. If a summary and the signed agreement differ, the signed agreement controls.',
    ];
    $next = $c;
    foreach ($replacements as $old => $new) $next = str_replace($old, $new, $next);
    $next = brb_replace_liquor_fee($next);
    $next = str_replace('Guests are responsible for obtaining any permit or license the City of Moline requires for alcohol at their event. Please check with the City of Moline: <a href="https://moline.il.us/244/Liquor-Licensing">https://moline.il.us/244/Liquor-Licensing</a> (City Clerk, 309-524-2004). ', '', $next);
    $next = str_replace('If you serve alcohol, the City of Moline requires a one-day liquor license ($50). Please check with the City of Moline: <a href="https://moline.il.us/244/Liquor-Licensing">https://moline.il.us/244/Liquor-Licensing</a> (City Clerk, 309-524-2004).', '', $next);
    $next = str_replace('If you serve alcohol, the City of Moline requires a one-day liquor license ($50).', '', $next);
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
function brb_refresh_checklist_copy() {
    $o = get_option('brb_settings', []);
    if (!is_array($o)) return;
    $text = (string) ($o['checklist'] ?? '');
    if ($text === '') return;
    $next = str_replace('Complete the in-person walk-through.', '', $text);
    $next = str_replace("\n\n\n", "\n\n", $next);
    $next = str_replace(
        'BYOB is allowed. Guests are responsible for obtaining any permit or license the City of Moline requires for alcohol at their event. Please check with the City of Moline: https://moline.il.us/244/Liquor-Licensing (City Clerk, 309-524-2004).',
        brb_byob_line(),
        $next
    );
    $next = str_replace('The balance is due the week of the event, no later than 48 hours before.', 'The balance is due no later than one week before the event.', $next);
    $next = str_replace('It is returned within 72 hours when Venmo or PayPal is on file. A check takes 1 to 2 weeks.', 'It is refunded through Stripe, less the card processing fee, after the event once the space is checked.', $next);
    $next = str_replace('Not allowed: Glitter, confetti, confetti cannons, rice, birdseed, or loose sequins.', 'Not allowed: Confetti, confetti cannons, rice, birdseed, or loose sequins.', $next);
    $next = str_replace('Not allowed: Glitter and confetti.', 'Not allowed: Confetti, confetti cannons, rice, birdseed, or loose sequins.', $next);
    if (strpos($next, 'confetti cannons') === false) {
        $next = rtrim($next) . "\n" . implode("\n", [
            'Not allowed: Confetti, confetti cannons, rice, birdseed, or loose sequins.',
            'Not allowed: Wall tape.',
            'Not allowed: Fireworks, sparklers, smoke bombs, pyrotechnics, or explosive or combustible props.',
            'Not allowed: Open flame, unless we approve it in writing.',
            'Not allowed: Hazardous materials or illegal substances.',
            'Not allowed: Weapons, except as the law allows.',
            'Not allowed: Anything that creates a big mess, a fire or safety risk, a nuisance, or a disturbance.',
            'Tickets, cover charges, paid admission, door fees, and ticket or merchandise sales aren\'t allowed either.',
            'If you paid the damage deposit by card, it is refunded to the same card after the event once the space is checked.',
        ]);
    }
    $next = str_replace('Balloons are fine.', 'Balloons are fine, no glitter.', $next);
    $next = str_replace('Balloons are fine, no glitter, no glitter.', 'Balloons are fine, no glitter.', $next);
    $next = brb_checklist_policy_text($next);
    $next = str_replace('Setup is the morning of the event. On a full day, setup can begin at 8:00 AM. On a half day, setup can begin one hour before the start.', 'Setup is the morning of the event. Setup can begin at 8:00 AM.', $next);
    $next = str_replace('If you serve alcohol, the City of Moline requires a one-day liquor license ($50). Please check with the City of Moline: https://moline.il.us/244/Liquor-Licensing (City Clerk, 309-524-2004).', brb_byob_line(), $next);
    $next = str_replace(brb_byob_line() . ' ' . brb_byob_line(), brb_byob_line(), $next);
    if ($next !== $text) {
        $o['checklist'] = $next;
        update_option('brb_settings', $o);
    }
}
function brb_faq_without_cost_question($html) {
    $asks = [
        'How much does it ' . 'cost',
        'What does it ' . 'cost',
        'How much is the ' . 'rental',
        'How much to ' . 'rent',
        'What is the ' . 'price',
        'What is the rental ' . 'price',
        'What are your ' . 'prices',
        'What are your ' . 'rates',
    ];
    $pattern = '/<details>\s*<summary>\s*(?:' . implode('|', array_map(function ($q) {
        return preg_quote($q, '/');
    }, $asks)) . ')\??\s*<\/summary>.*?<\/details>/is';
    $next = preg_replace($pattern, '', (string) $html);
    return is_string($next) ? $next : (string) $html;
}
function brb_refresh_faq() {
    $page = get_page_by_path('faq');
    if (!$page) return;
    $c = (string) $page->post_content;
    $stripped = brb_faq_without_cost_question($c);
    if ($stripped !== $c) {
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => $stripped], ['ID' => (int) $page->ID]);
        clean_post_cache((int) $page->ID);
        $c = $stripped;
    }
    $payee_fixed = str_replace('payable to Mad Productions at The Boardroom, ', 'payable to Boardroom QC LLC at The Boardroom, ', $c);
    if ($payee_fixed !== $c) {
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => $payee_fixed], ['ID' => (int) $page->ID]);
        clean_post_cache((int) $page->ID);
        $c = $payee_fixed;
    }
    if (stripos($c, 'How many guests can The Boardroom hold') === false) return;
    if ($c !== brb_faq_markup()) {
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => brb_faq_markup()], ['ID' => (int) $page->ID]);
        clean_post_cache((int) $page->ID);
        return;
    }
    return;
    if (stripos($c, 'Our staff can\'t set the room for you') === false) {
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => brb_faq_markup()], ['ID' => (int) $page->ID]);
        clean_post_cache((int) $page->ID);
        return;
    }
    $old_pay = 'Bring a check in person within those 48 hours.';
    $new_pay = 'Mail or drop off checks payable to Boardroom QC LLC at The Boardroom, ' . BRB_VENUE_ADDRESS . '.';
    $next = str_replace('online processing fee', 'card processing fee', str_replace($old_pay, $new_pay, $c));
    $book = '<details><summary>How do I book and pay?</summary><p>Request a date on the <a href="/reserve-the-boardroom-quad-cities/">Book</a> page. Bookings are subject to approval, and the owner may decline any request. No payment is due until the request is approved. A request does not hold the date. Your date is reserved once your deposit is received. The deposit (50% of the agreed total) holds the date. It is refundable if the Owner receives written cancellation 60 or more days before the event, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 60 days before the event. Pay by check or in person, or online by card with a 3% processing fee. Mail or drop off checks payable to Boardroom QC LLC at The Boardroom, ' . BRB_VENUE_ADDRESS . '. Bookings need at least 48 hours\' notice. Until we confirm your price, your confirmation says price to be confirmed. The card processing fee is non-refundable.</p></details>';
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
    if (stripos($c, 'Christmas decorations are up in December') === false) {
        $next = brb_faq_markup();
    }
    $next = str_replace('<details><summary>When do events have to end?</summary><p>Events must end by midnight, per the City of Moline permit.</p></details>', '', $next);
    $next = str_replace('90 or more days before the event', '60 or more days before the event', $next);
    $next = str_replace('less than 90 days before the event', 'less than 60 days before the event', $next);
    $next = str_replace('Yes, on-site parking.', 'Yes. Free parking on premises.', $next);
    $next = str_replace('Please clean and tidy the space, and take the garbage out.', 'Please leave it like you got it. Clean and tidy the space, and take the garbage out.', $next);
    $next = str_replace('If you would like a hand, our staff can help with setup for an extra fee.', 'Renters set up and tear down. Staff cannot set the room up. Setup can begin at 8:00 AM.', $next);
    $next = str_replace('Sales tax is added.', 'Sales tax is included in the price.', $next);
    $next = str_replace('Sales tax is added', 'Sales tax is included in the price.', $next);
    $next = brb_replace_liquor_fee($next);
    $hours_plain = 'A full day runs from 10:00 AM to 11:30 PM.';
    $hours_meet = $hours_plain . ' We don\'t keep set hours for meetings or check drop-off. We meet on demand and set a time together, by appointment.';
    if (strpos($next, $hours_meet) === false) $next = str_replace($hours_plain, $hours_meet, $next);
    $next = str_replace('Our team will reach out to schedule it.', 'We don\'t keep set hours. We meet on demand and set a time together, by appointment.', $next);
    $next = str_replace('Mail or drop off checks payable to Mad Productions at 2212 37th Street, Moline, IL 61265. The deposit (50% of the agreed total) holds the date.', 'Mail or drop off checks payable to Boardroom QC LLC at The Boardroom, ' . BRB_VENUE_ADDRESS . '. We don\'t keep set hours. We meet on demand and set a time together, by appointment. The deposit (50% of the agreed total) holds the date.', $next);
    $house = [
        'When does everyone have to be out?' => 'Everyone must be out of The Boardroom by 11:30 PM. This is firm, with no exceptions. Please finish in time to wrap up.',
        'What are the hours?' => 'A full day runs from 10:00 AM to 11:30 PM. We don\'t keep set hours for meetings or check drop-off. We meet on demand and set a time together, by appointment.',
        'Do I have to meet you?' => 'Yes. We meet every renter before approving a booking. It is a short, friendly visit to talk through your plans and the house rules. We don\'t keep set hours. We meet on demand and set a time together, by appointment.',
        'What if there is bad weather?' => 'Bad weather or an emergency out of our control, such as a hurricane, a snowstorm, or a power outage, is refundable. A personal issue, such as a flat tire, is not refundable.',
        'Is there a damage deposit?' => 'The refundable damage deposit is a flat $250. It is charged with the booking deposit and mainly covers cleaning. It may be applied to excessive cleaning or garbage. A dumpster is on site. It is returned within 72 hours when Venmo or PayPal is on file. A check takes 1 to 2 weeks. No glitter. Vaping leaves a residue and can affect the deposit. All smoking, including cigars and cigarettes, is outside only.',
        'Can we sell tickets or charge admission?' => 'No. All events must be free to attend: no tickets, no cover charge, no paid admission, no door fees, and no ticket or merchandise sales. We do not host concerts or ticketed shows.',
        'Can we have a band?' => 'Yes. Guests may have a band or live music, as long as they are not charging for the event. We do not host concerts or ticketed shows.',
        'Is smoking allowed?' => 'No smoking or vaping.',
        'Can I bring a pet?' => 'Service animals only. No pets.',
        'Can I bring my own food?' => 'Yes. You can bring your own food. We have warmers and buffet-style warmers, but the stovetop can\'t be used. You can also have it catered by any caterer. The Boardroom does not provide catering.',
        'When is the balance due?' => 'The remaining balance is due no later than one week before the event.',
        'Is there a refrigerator or ice?' => 'Yes. There is a refrigerator and an ice machine.',
        'Who is on site during an event?' => 'No one is on site during events. Our connected offices are available on demand if something is needed. Cell numbers are given when the contract is signed, and they are not listed on this site.',
        'Is there a private entrance?' => 'There is one main entrance. A back entrance can be used for a surprise guest when it is arranged ahead of time with our team.',
        'Are balloons allowed?' => 'Yes. Balloons are fine. Wall tape is not allowed. Glitter is not allowed.',
        'Is there an age limit for hosts?' => 'The minimum host age is 25 for every booking.',
        'What happens if something gets damaged?' => 'The damage deposit covers cleaning and damage. Anything beyond that deposit may be charged. See the Terms. Our team will go over it at the walk-through.',
        'Can I hold a vendor market or sell items?' => 'No. All events are free to attend. There are no merchandise sales and no ticket sales.',
        'Can I get an invoice or a receipt?' => 'Yes. Card payments get an emailed receipt, and we can provide an invoice or receipt for any payment on request.',
        'Is sales tax included?' => 'Sales tax is included in the price.',
    ];
    foreach ($house as $question => $answer) {
        if (strpos($next, $question) !== false) continue;
        $next .= '<details><summary>' . $question . '</summary><p>' . $answer . '</p></details>';
    }
    if ($next === $c) return;
    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_content' => $next], ['ID' => (int) $page->ID]);
    clean_post_cache((int) $page->ID);
}
function brb_scrub_copy_string($c) {
    $card = 'Pay by check or in person, or online by card with a 3% processing fee. Mail or drop off checks payable to Boardroom QC LLC at The Boardroom, ' . BRB_VENUE_ADDRESS . '. We never see or store card numbers. The deposit (50% of the agreed total) holds the date. It is refundable if the Owner receives written cancellation 60 or more days before the event, less any non-refundable processing charges (including the 3% card processing fee) and costs already incurred. It is not refundable if cancellation is received less than 60 days before the event. The card processing fee is non-refundable.';
    $c = preg_replace('/Payments are processed by \w+;?\s*we do not store card numbers\./i', $card, $c);
    $c = preg_replace('/This site does not process payments online and does not collect card data\./i', 'Stripe processes card payments. We never see or store card numbers.', $c);
    $c = preg_replace('/pay securely online/i', 'pay by check or in person, or online by card', $c);
    $c = preg_replace('/Bookings are confirmed once payment \(or the required deposit\) is received\./i', 'A request does not hold the date. Your date is reserved once your deposit is received.', $c);
    $c = preg_replace('/Saving a date holds it for a limited time\. The booking is confirmed once we receive your check, your card payment, or the required deposit\./i', 'A request does not hold the date. Your date is reserved once your deposit is received.', $c);
    $c = str_replace('online processing fee', 'card processing fee', $c);
    $c = str_replace('Sales tax is added.', 'Sales tax is included in the price.', $c);
    $c = str_replace('Sales tax is added', 'Sales tax is included in the price.', $c);
    $c = str_replace('The damage deposit is $100 to $500 depending on event size, mainly for cleaning. It may be applied to excessive cleaning or garbage.', 'The refundable damage deposit is a flat $250. It is charged with the booking deposit and mainly covers cleaning. It may be applied to excessive cleaning or garbage.', $c);
    $c = str_replace('The damage deposit is $100 to $500 depending on event size, mainly for cleaning.', 'The refundable damage deposit is a flat $250. It is charged with the booking deposit and mainly covers cleaning.', $c);
    $c = str_replace('The damage deposit is $100 to $500, depending on event size, and mostly covers cleaning and garbage.', 'The refundable damage deposit is a flat $250. It is charged with the booking deposit and mostly covers cleaning and garbage.', $c);
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
// 2.5.68: Boardroom QC LLC is the only entity named in public copy. The other
// entities appear only in the Released Parties definitions in Rental Agreement section 5
// and (2.5.70) the Terms section 5 summary. Both pages are rebuilt from code by
// brb_install_legal_pages() before this scrub runs, so the scrub skips them.
function brb_other_entity_pattern() {
    return '/(?<![\w.\/@-])mad\s+(productions|rentals)\b/i';
}
function brb_scrub_other_entities($c) {
    $c = (string) $c;
    if (!preg_match(brb_other_entity_pattern(), $c)) return $c;
    $q = '(?:"|&quot;|&#8220;|&#8221;|\x{201C}|\x{201D})';
    $list = '/(?:Boardroom QC LLC,\s*)?Mad\s+Rentals(?:,?\s*LLC)?,?\s*and\s+Mad\s+Productions(?:,?\s*LLC)?,?\s*and each of their members and managers(?:\s*\(collectively, the ' . $q . 'Released Parties' . $q . '\))?/iu';
    $next = preg_replace($list, 'the Released Parties (as defined in Section 5 of the Rental Agreement)', $c);
    if (is_string($next)) $c = $next;
    $next = preg_replace('/(?<![\w.\/@-])mad\s+(?:productions|rentals),?\s*(?:LLC|L\.L\.C\.)/i', 'Boardroom QC LLC', $c);
    if (is_string($next)) $c = $next;
    $next = preg_replace(brb_other_entity_pattern(), 'Boardroom QC LLC', $c);
    if (is_string($next)) $c = $next;
    $next = preg_replace('/Boardroom QC LLC(?:,?\s*(?:and\s+)?Boardroom QC LLC)+/', 'Boardroom QC LLC', $c);
    if (is_string($next)) $c = $next;
    return $c;
}
function brb_scrub_other_entities_deep($v) {
    if (is_string($v)) return brb_scrub_other_entities($v);
    if (is_array($v)) {
        foreach ($v as $k => $item) $v[$k] = brb_scrub_other_entities_deep($item);
    }
    return $v;
}
function brb_scrub_other_entity_copy() {
    global $wpdb;
    $like = ['%mad productions%', '%mad rentals%'];
    $skip = [];
    foreach (['rental-agreement', 'terms'] as $legal_slug) {
        $legal_page = get_page_by_path($legal_slug);
        if ($legal_page) $skip[] = (int) $legal_page->ID;
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_content, post_excerpt FROM {$wpdb->posts} WHERE post_type IN ('page','post') AND post_status IN ('publish','draft','pending','private','future') AND (LOWER(post_content) LIKE %s OR LOWER(post_content) LIKE %s OR LOWER(post_title) LIKE %s OR LOWER(post_title) LIKE %s OR LOWER(post_excerpt) LIKE %s OR LOWER(post_excerpt) LIKE %s)",
        $like[0], $like[1], $like[0], $like[1], $like[0], $like[1]
    ));
    foreach ((array) $rows as $p) {
        if (in_array((int) $p->ID, $skip, true)) continue;
        $fields = [];
        foreach (['post_title', 'post_content', 'post_excerpt'] as $f) {
            $next = brb_scrub_other_entities((string) $p->$f);
            if ($next !== (string) $p->$f) $fields[$f] = $next;
        }
        if ($fields) {
            $wpdb->update($wpdb->posts, $fields, ['ID' => (int) $p->ID]);
            clean_post_cache((int) $p->ID);
        }
    }
    $metas = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND (LOWER(meta_value) LIKE %s OR LOWER(meta_value) LIKE %s)",
        $wpdb->esc_like('_yoast_wpseo_') . '%', $like[0], $like[1]
    ));
    foreach ((array) $metas as $m) {
        $next = brb_scrub_other_entities((string) $m->meta_value);
        if ($next !== (string) $m->meta_value) {
            $wpdb->update($wpdb->postmeta, ['meta_value' => $next], ['meta_id' => (int) $m->meta_id]);
            wp_cache_delete((int) $m->post_id, 'post_meta');
        }
    }
    foreach (['brb_settings', 'blogname', 'blogdescription', 'wpseo_titles', 'wpseo_social'] as $opt) {
        $v = get_option($opt, null);
        if ($v === null) continue;
        $next = brb_scrub_other_entities_deep($v);
        if ($next !== $v) update_option($opt, $next);
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
    brb_backfill_line_items();
    brb_install_checklist_page();
    brb_scrub_other_entity_copy();
    brb_install_report_tables();
    brb_ensure_tour_on_contact();
    brb_import_named_blackouts();
    brb_refresh_checklist_copy();
    brb_migrate_book_slug();
    brb_drop_blocked_inquiry_cc();
    wp_clear_scheduled_hook('brb_cron_expire');
    update_option('brb_version', BRB_VER);
}
// init, not plugins_loaded: legal pages call wp_insert_post, which needs rewrite
// and WP_POST_REVISIONS. Those are not ready when plugins_loaded fires.
add_action('init', 'brb_maybe_upgrade', 1);
add_action('template_redirect', 'brb_legacy_book_redirect', 0);
add_action('template_redirect', 'brb_legacy_legal_redirect');
register_activation_hook(__FILE__, 'brb_maybe_upgrade');
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('brb_cron_expire');
    wp_clear_scheduled_hook('brb_cron_checklist');
});
add_action('init', function () {
    if (get_option('brb_version') !== BRB_VER) return;
    wp_clear_scheduled_hook('brb_cron_expire');
    if (!wp_next_scheduled('brb_cron_checklist')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'brb_cron_checklist');
    }
    brb_migrate_book_slug();
}, 20);
add_action('brb_cron_expire', 'brb_expire_holds');

/* ---------- quotes, notice, availability ---------- */
function brb_slot_info(DateTimeImmutable $day) {
    $cutoff = brb_notice_cutoff();
    $dayOk = ($day->setTime(10, 0) >= $cutoff);
    return ['day_ok' => $dayOk, 'first_hour' => null, 'any' => $dayOk];
}
function brb_build_range($in, $args = []) {
    $o = brb_opt();
    $date = sanitize_text_field($in['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return new WP_Error('date', 'Please choose a date.');
    $asked = (string) ($in['type'] ?? 'day');
    if ($asked !== '' && $asked !== 'day') {
        return new WP_Error('type', 'Bookings are for a full day, from 10:00 AM to 11:30 PM.');
    }
    $type = 'day';
    $tz = brb_tz();
    try {
        $d0 = new DateTimeImmutable($date . ' 00:00:00', $tz);
    } catch (Exception $e) {
        return new WP_Error('date', 'Please choose a date.');
    }
    if ($d0 < brb_now()->setTime(0, 0)) return new WP_Error('past', 'That date is in the past.');
    $days_raw = $in['days'] ?? 1;
    $days = (int) $days_raw;
    if ($days > 1 && empty($args['allow_multi'])) {
        return new WP_Error('days', 'A booking is one full day, from 10:00 AM to 11:30 PM.');
    }
    if (!empty($args['allow_multi'])) $days = max(1, min((int) $o['max_days'], $days));
    else $days = 1;
    $s = $d0->setTime(10, 0);
    $e = $d0->modify('+' . ($days - 1) . ' day')->setTime(23, 30);
    $units = $days;
    $guests = max(1, (int) ($in['guests'] ?? 1));
    $guest_cap = max(200, (int) $o['max_guests']);
    if ($guests > $guest_cap) return new WP_Error('guests', '80 guests is the usual capacity. Graduation parties or open houses can go over. For a much larger guest count, please contact us.');
    if (empty($args['override_notice']) && $s < brb_notice_cutoff()) {
        return new WP_Error('notice', sprintf(
            'Bookings must start at least %d hours from now. The earliest start is %s.',
            brb_notice_hours(),
            brb_format_chicago(brb_notice_cutoff())
        ));
    }
    return ['type' => $type, 's' => $s, 'e' => $e, 'units' => $units, 'guests' => $guests];
}
function brb_calendar_rates() {
    $data = json_decode((string) brb_opt('calendar_rates'), true);
    if (!is_array($data)) $data = [];
    $rules = [];
    foreach ((array) ($data['rules'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $rules[] = $row;
    }
    $overrides = [];
    foreach ((array) ($data['overrides'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $overrides[] = $row;
    }
    return ['rules' => $rules, 'overrides' => $overrides];
}
function brb_rate_number($raw) {
    $raw = trim((string) $raw);
    if ($raw === '' || !is_numeric($raw)) return null;
    $n = round((float) $raw, 2);
    return $n > 0 ? $n : null;
}
function brb_rate_months($raw) {
    $months = [];
    $names = ['january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12];
    foreach (preg_split('/[^a-z0-9]+/i', strtolower((string) $raw)) as $part) {
        if ($part === '') continue;
        if (isset($names[$part])) $months[] = $names[$part];
        elseif (ctype_digit($part)) {
            $n = (int) $part;
            if ($n >= 1 && $n <= 12) $months[] = $n;
        }
    }
    return array_values(array_unique($months));
}
function brb_date_is_weekend($ymd) {
    try {
        $n = (int) (new DateTimeImmutable($ymd, brb_tz()))->format('N');
    } catch (Exception $e) {
        return false;
    }
    return $n === 5 || $n === 6;
}
function brb_rate_rule_matches($rule, $ymd) {
    $start = trim((string) ($rule['start'] ?? ''));
    $end = trim((string) ($rule['end'] ?? ''));
    if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) return false;
    if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) return false;
    if ($start !== '' && $ymd < $start) return false;
    if ($end !== '' && $ymd > $end) return false;
    $months = brb_rate_months($rule['months'] ?? '');
    if ($months) {
        $month = (int) substr($ymd, 5, 2);
        if (!in_array($month, $months, true)) return false;
    }
    return true;
}
function brb_default_calendar_rates() {
    $blank_short = ['sun_shorter' => '', 'mon_thu_shorter' => '', 'fri_sat_shorter' => ''];
    return [
        'rules' => [
            array_merge([
                'start' => '2026-12-01',
                'end' => '2026-12-31',
                'months' => '',
                'sun_full' => '',
                'mon_thu_full' => '1200',
                'fri_sat_full' => '1400',
            ], $blank_short),
            array_merge([
                'start' => '2026-11-03',
                'end' => '2027-12-31',
                'months' => '',
                'sun_full' => '850',
                'mon_thu_full' => '850',
                'fri_sat_full' => '1400',
            ], $blank_short),
        ],
        'overrides' => [
            ['date' => '2026-11-24', 'full' => '1500', 'shorter' => ''],
            ['date' => '2026-11-25', 'full' => '1500', 'shorter' => ''],
            ['date' => '2026-11-26', 'full' => '1500', 'shorter' => ''],
            ['date' => '2026-11-28', 'full' => '1050', 'shorter' => ''],
            ['date' => '2026-12-24', 'full' => '1500', 'shorter' => ''],
        ],
    ];
}
function brb_calendar_rates_are_empty($json) {
    $data = json_decode((string) $json, true);
    if (!is_array($data)) return true;
    foreach ((array) ($data['rules'] ?? []) as $row) {
        if (is_array($row)) return false;
    }
    foreach ((array) ($data['overrides'] ?? []) as $row) {
        if (is_array($row)) return false;
    }
    return true;
}
function brb_rate_day_keys($ymd, $block) {
    $suffix = $block === 'shorter' ? 'shorter' : 'full';
    try {
        $n = (int) (new DateTimeImmutable($ymd, brb_tz()))->format('N');
    } catch (Exception $e) {
        $n = 1;
    }
    if ($n === 7) return ['sun_' . $suffix, 'weekday_' . $suffix];
    if ($n >= 1 && $n <= 4) return ['mon_thu_' . $suffix, 'weekday_' . $suffix];
    return ['fri_sat_' . $suffix, 'weekend_' . $suffix];
}
function brb_rule_span_days($rule) {
    $start = trim((string) ($rule['start'] ?? ''));
    $end = trim((string) ($rule['end'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) return 100000;
    try {
        return (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days;
    } catch (Exception $e) {
        return 100000;
    }
}
function brb_price_floor() {
    return 850.0;
}
function brb_rate_amount_string($amount) {
    $amount = round((float) $amount, 2);
    if (abs($amount - round($amount)) < 0.001) return (string) (int) round($amount);
    return (string) $amount;
}
function brb_date_notes_map() {
    $raw = json_decode((string) brb_opt('date_notes'), true);
    return is_array($raw) ? $raw : [];
}
function brb_date_note($ymd) {
    $map = brb_date_notes_map();
    return isset($map[$ymd]) ? (string) $map[$ymd] : '';
}
function brb_save_date_note($ymd, $note) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $ymd)) return new WP_Error('date', 'Choose a date.');
    $map = brb_date_notes_map();
    $note = sanitize_textarea_field((string) $note);
    if ($note === '') unset($map[$ymd]);
    else $map[$ymd] = $note;
    $o = brb_opt();
    $o['date_notes'] = wp_json_encode($map);
    update_option('brb_settings', $o);
    return $note;
}
function brb_date_override_row($ymd) {
    foreach (brb_calendar_rates()['overrides'] as $row) {
        if (!is_array($row)) continue;
        if (trim((string) ($row['date'] ?? '')) === $ymd) return $row;
    }
    return null;
}
function brb_date_override_amount($ymd) {
    $row = brb_date_override_row($ymd);
    if (!$row) return null;
    return brb_rate_number($row['full'] ?? '');
}
function brb_save_date_override($ymd, $amount) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $ymd)) return new WP_Error('date', 'Choose a date.');
    if ($amount !== null && trim((string) $amount) !== '') {
        $amount = brb_rate_number($amount);
        if ($amount === null) return new WP_Error('price', 'Enter a price above zero, or leave it blank to use the seasonal rate.');
    } else {
        $amount = null;
    }
    $rates = brb_calendar_rates();
    $overrides = [];
    $seen = false;
    foreach ($rates['overrides'] as $row) {
        if (!is_array($row)) continue;
        $date = trim((string) ($row['date'] ?? ''));
        if ($date === '') continue;
        if ($date === $ymd) {
            $seen = true;
            if ($amount === null) continue;
            $row['date'] = $ymd;
            $row['full'] = brb_rate_amount_string($amount);
            if (!array_key_exists('shorter', $row)) $row['shorter'] = '';
            $overrides[] = $row;
            continue;
        }
        $overrides[] = $row;
    }
    if (!$seen && $amount !== null) {
        $overrides[] = ['date' => $ymd, 'full' => brb_rate_amount_string($amount), 'shorter' => ''];
    }
    $o = brb_opt();
    $o['calendar_rates'] = wp_json_encode(['rules' => $rates['rules'], 'overrides' => $overrides]);
    update_option('brb_settings', $o);
    return $amount;
}
function brb_seasonal_rate_for_date($ymd, $block = 'full') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $ymd)) return null;
    $block = ($block === 'shorter') ? 'shorter' : 'full';
    $matched = [];
    foreach (brb_calendar_rates()['rules'] as $rule) {
        if (brb_rate_rule_matches($rule, $ymd)) $matched[] = $rule;
    }
    usort($matched, function ($a, $b) {
        return brb_rule_span_days($a) <=> brb_rule_span_days($b);
    });
    foreach ($matched as $rule) {
        foreach (brb_rate_day_keys($ymd, $block) as $key) {
            $rate = brb_rate_number($rule[$key] ?? '');
            if ($rate !== null) return $rate;
        }
    }
    return null;
}
function brb_rate_for_date($ymd, $block) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $ymd)) return null;
    $block = ($block === 'shorter') ? 'shorter' : 'full';
    $rates = brb_calendar_rates();
    foreach ($rates['overrides'] as $row) {
        if (trim((string) ($row['date'] ?? '')) !== $ymd) continue;
        $key = $block === 'shorter' ? 'shorter' : 'full';
        $amount = brb_rate_number($row[$key] ?? '');
        if ($amount !== null) return $amount;
    }
    $matched = [];
    foreach ($rates['rules'] as $rule) {
        if (brb_rate_rule_matches($rule, $ymd)) $matched[] = $rule;
    }
    usort($matched, function ($a, $b) {
        return brb_rule_span_days($a) <=> brb_rule_span_days($b);
    });
    foreach ($matched as $rule) {
        foreach (brb_rate_day_keys($ymd, $block) as $key) {
            $amount = brb_rate_number($rule[$key] ?? '');
            if ($amount !== null) return $amount;
        }
    }
    return null;
}
function brb_quote_amount($r) {
    $block = (($r['type'] ?? '') === 'day') ? 'full' : 'shorter';
    if ($block === 'shorter') return brb_rate_for_date($r['s']->format('Y-m-d'), 'shorter');
    $cursor = $r['s']->setTime(0, 0);
    $last = $r['e']->setTime(0, 0);
    $total = 0.0;
    $days = 0;
    for (; $cursor <= $last && $days < 31; $cursor = $cursor->modify('+1 day')) {
        $amount = brb_rate_for_date($cursor->format('Y-m-d'), 'full');
        if ($amount === null) return null;
        $total += $amount;
        $days++;
    }
    return $days > 0 ? round($total, 2) : null;
}
function brb_quote($r) {
    $amount = brb_quote_amount($r);
    if ($amount === null) {
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
    $due = brb_deposit_due_amount($amount);
    return [
        'rate' => $amount,
        'units' => (int) $r['units'],
        'subtotal' => $amount,
        'cleaning' => 0,
        'tax' => 0,
        'total' => $amount,
        'due' => $due,
        'price_set' => 1,
    ];
}
function brb_seasonal_price_html() {
    $rates = brb_calendar_rates();
    $items = '';
    $labels = [
        'weekday_full' => 'Sunday to Thursday, full day',
        'weekend_full' => 'Friday and Saturday, full day',
    ];
    foreach ($rates['rules'] as $rule) {
        $when = [];
        $months = trim((string) ($rule['months'] ?? ''));
        $start = trim((string) ($rule['start'] ?? ''));
        $end = trim((string) ($rule['end'] ?? ''));
        if ($months !== '') $when[] = $months;
        if ($start !== '' && $end !== '') $when[] = $start . ' to ' . $end;
        elseif ($start !== '') $when[] = 'from ' . $start;
        elseif ($end !== '') $when[] = 'through ' . $end;
        $span = $when ? implode(', ', $when) : 'Any date';
        foreach ($labels as $key => $label) {
            $amount = brb_rate_number($rule[$key] ?? '');
            if ($amount === null) continue;
            $items .= '<li>' . esc_html($span . ': ' . $label . ' ' . brb_whole_money($amount)) . '</li>';
        }
    }
    foreach ($rates['overrides'] as $row) {
        $date = trim((string) ($row['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        foreach (['full' => 'full day'] as $key => $label) {
            $amount = brb_rate_number($row[$key] ?? '');
            if ($amount === null) continue;
            $items .= '<li>' . esc_html($date . ': ' . $label . ' ' . brb_whole_money($amount)) . '</li>';
        }
    }
    if ($items === '') return '';
    return '<ul class="brb-season-prices">' . $items . '</ul>';
}
function brb_calendar_widest_rule() {
    $best = null;
    $span = -1;
    foreach (brb_calendar_rates()['rules'] as $rule) {
        $days = brb_rule_span_days($rule);
        if ($days > $span) {
            $span = $days;
            $best = $rule;
        }
    }
    return $best;
}
function brb_calendar_has_higher_rate($floor) {
    $keys = ['sun_full', 'mon_thu_full', 'fri_sat_full', 'weekday_full', 'weekend_full'];
    foreach (brb_calendar_rates()['rules'] as $rule) {
        foreach ($keys as $key) {
            $amount = brb_rate_number($rule[$key] ?? '');
            if ($amount !== null && $amount > $floor + 0.001) return true;
        }
    }
    foreach (brb_calendar_rates()['overrides'] as $row) {
        $amount = brb_rate_number($row['full'] ?? '');
        if ($amount !== null && $amount > $floor + 0.001) return true;
    }
    return false;
}
function brb_faq_price_html() {
    $base = brb_calendar_widest_rule();
    $sun = $base ? (brb_rate_number($base['sun_full'] ?? '') ?? brb_rate_number($base['weekday_full'] ?? '')) : null;
    $mon = $base ? (brb_rate_number($base['mon_thu_full'] ?? '') ?? brb_rate_number($base['weekday_full'] ?? '')) : null;
    $fri = $base ? (brb_rate_number($base['fri_sat_full'] ?? '') ?? brb_rate_number($base['weekend_full'] ?? '')) : null;
    $link = '<a href="' . esc_url(brb_book_url()) . '">booking calendar</a>';
    if ($sun === null || $mon === null || $fri === null) {
        return 'The price for your date shows on the ' . $link . '. If a date has no rate yet, the page says price to be confirmed.';
    }
    // Published day rates match the calendar rate table. The table itself is not edited here.
    $text = 'Sunday to Thursday from $850, Friday and Saturday ' . brb_whole_money($fri);
    if (brb_calendar_has_higher_rate(max($sun, $mon, $fri))) $text .= ', with a few holiday dates priced higher';
    $text .= '. The price for your date is on the ' . $link . '.';
    return $text;
}
function brb_calendar_rates_from_post($post) {
    $saved = brb_calendar_rates();
    $saved_rules = $saved['rules'];
    $saved_overrides = $saved['overrides'];
    $shorter_keys = ['sun_shorter', 'mon_thu_shorter', 'fri_sat_shorter', 'weekday_shorter', 'weekend_shorter'];
    $rules = [];
    $raw_rules = $post['brb_rate_rules'] ?? [];
    if (is_array($raw_rules)) {
        $i = 0;
        foreach ($raw_rules as $row) {
            if (!is_array($row)) continue;
            $amount_keys = ['sun_full', 'mon_thu_full', 'fri_sat_full', 'weekday_full', 'weekend_full'];
            $clean = [
                'start' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['start'] ?? '')) ? $row['start'] : '',
                'end' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['end'] ?? '')) ? $row['end'] : '',
                'months' => sanitize_text_field($row['months'] ?? ''),
            ];
            $has_amount = false;
            foreach ($amount_keys as $key) {
                $amount = brb_rate_number($row[$key] ?? '');
                $clean[$key] = $amount === null ? '' : (string) $amount;
                if ($clean[$key] !== '') $has_amount = true;
            }
            $match = null;
            foreach ($saved_rules as $prev) {
                if ((string) ($prev['start'] ?? '') === $clean['start'] && (string) ($prev['end'] ?? '') === $clean['end'] && (string) ($prev['months'] ?? '') === $clean['months']) {
                    $match = $prev;
                    break;
                }
            }
            if (!$match && isset($saved_rules[$i]) && is_array($saved_rules[$i])) $match = $saved_rules[$i];
            foreach ($shorter_keys as $key) {
                if (array_key_exists($key, $row)) {
                    $amount = brb_rate_number($row[$key] ?? '');
                    $clean[$key] = $amount === null ? '' : (string) $amount;
                } else {
                    $amount = is_array($match) ? brb_rate_number($match[$key] ?? '') : null;
                    $clean[$key] = $amount === null ? '' : (string) $amount;
                }
                if ($clean[$key] !== '') $has_amount = true;
            }
            $has = $has_amount || $clean['start'] !== '' || $clean['end'] !== '' || $clean['months'] !== '';
            if ($has) $rules[] = $clean;
            $i++;
        }
    }
    $overrides = [];
    $raw_over = $post['brb_rate_overrides'] ?? [];
    if (is_array($raw_over)) {
        foreach ($raw_over as $row) {
            if (!is_array($row)) continue;
            $date = (string) ($row['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            $full = brb_rate_number($row['full'] ?? '');
            if (array_key_exists('shorter', $row)) {
                $shorter = brb_rate_number($row['shorter'] ?? '');
            } else {
                $shorter = null;
                foreach ($saved_overrides as $prev) {
                    if ((string) ($prev['date'] ?? '') === $date) {
                        $shorter = brb_rate_number($prev['shorter'] ?? '');
                        break;
                    }
                }
            }
            if ($full === null && $shorter === null) continue;
            $overrides[] = [
                'date' => $date,
                'full' => $full === null ? '' : (string) $full,
                'shorter' => $shorter === null ? '' : (string) $shorter,
            ];
        }
    }
    return wp_json_encode(['rules' => $rules, 'overrides' => $overrides]);
}
function brb_admin_calendar_rate_fields() {
    $saved = brb_calendar_rates();
    $rules = $saved['rules'];
    $overrides = $saved['overrides'];
    $blank_rule = ['start' => '', 'end' => '', 'months' => '', 'sun_full' => '', 'mon_thu_full' => '', 'fri_sat_full' => '', 'sun_shorter' => '', 'mon_thu_shorter' => '', 'fri_sat_shorter' => ''];
    while (count($rules) < 4) $rules[] = $blank_rule;
    while (count($overrides) < 8) $overrides[] = ['date' => '', 'full' => '', 'shorter' => ''];
    echo '<h2>Calendar date prices</h2>';
    echo '<p class="description">Full-day prices shown on the booking calendar. Sunday, Monday through Thursday, and Friday and Saturday can differ. Leave an amount blank when that date should say price to be confirmed. A narrower date range wins. A single date override wins over a range. Booked and blocked dates stay unavailable.</p>';
    echo '<table class="widefat"><thead><tr><th>Start</th><th>End</th><th>Months</th><th>Sunday</th><th>Mon–Thu</th><th>Fri–Sat</th></tr></thead><tbody>';
    foreach ($rules as $i => $row) {
        echo '<tr>';
        foreach (['start' => 'date', 'end' => 'date', 'months' => 'text', 'sun_full' => 'text', 'mon_thu_full' => 'text', 'fri_sat_full' => 'text'] as $key => $type) {
            $value = (string) ($row[$key] ?? '');
            echo '<td><input type="' . esc_attr($type) . '" name="brb_rate_rules[' . (int) $i . '][' . esc_attr($key) . ']" value="' . esc_attr($value) . '" class="regular-text"></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p class="description">Months can be numbers or names, such as 6, 7 or June, July. Start and end are optional.</p>';
    echo '<table class="widefat"><thead><tr><th>Date override</th><th>Full day</th></tr></thead><tbody>';
    foreach ($overrides as $i => $row) {
        echo '<tr>';
        echo '<td><input type="date" name="brb_rate_overrides[' . (int) $i . '][date]" value="' . esc_attr((string) ($row['date'] ?? '')) . '"></td>';
        echo '<td><input type="text" name="brb_rate_overrides[' . (int) $i . '][full]" value="' . esc_attr((string) ($row['full'] ?? '')) . '" class="regular-text"></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
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
    return ["status IN ('" . implode("','", brb_blocking_statuses()) . "')", []];
}
function brb_date_taken($b) {
    $s = new DateTimeImmutable($b->start_dt, brb_tz());
    $e = new DateTimeImmutable($b->end_dt, brb_tz());
    return !brb_is_free($s, $e, (int) $b->id);
}
function brb_blocking_booking_for_range($s, $e) {
    global $wpdb;
    list($block, $args) = brb_blocking_sql();
    $sql = 'SELECT * FROM ' . brb_table() . " WHERE start_dt < %s AND end_dt > %s AND $block ORDER BY id ASC LIMIT 1";
    $params = array_merge([$e->format('Y-m-d H:i:s'), $s->format('Y-m-d H:i:s')], $args);
    return $wpdb->get_row($wpdb->prepare($sql, $params));
}
function brb_range_has_booking($s, $e, $exclude_id = 0) {
    global $wpdb;
    list($block, $args) = brb_blocking_sql();
    $sql = "SELECT COUNT(*) FROM " . brb_table() . " WHERE id<>%d AND start_dt < %s AND end_dt > %s AND $block";
    $params = array_merge([(int) $exclude_id, $e->format('Y-m-d H:i:s'), $s->format('Y-m-d H:i:s')], $args);
    return (int) $wpdb->get_var($wpdb->prepare($sql, $params)) !== 0;
}
function brb_is_free($s, $e, $exclude_id = 0) {
    global $wpdb;
    if (brb_range_hits_blackout($s, $e) || brb_range_hits_hold($s, $e)) return false;
    if (brb_range_has_booking($s, $e, $exclude_id)) return false;
    return !brb_range_hits_addon($s, $e, $exclude_id);
}
function brb_range_hits_addon($s, $e, $exclude_id = 0) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT id, start_dt, setup_addon, takedown_addon FROM ' . brb_table() . ' WHERE id<>%d AND (setup_addon=1 OR takedown_addon=1)', (int) $exclude_id));
    if (!$rows) return false;
    foreach ($rows as $row) {
        $start = new DateTimeImmutable($row->start_dt, brb_tz());
        $windows = [];
        if (!empty($row->setup_addon)) $windows[] = $start->modify('-1 day');
        if (!empty($row->takedown_addon)) $windows[] = $start->modify('+1 day');
        foreach ($windows as $day) {
            $a = $day->setTime(10, 0);
            $b = $day->setTime(23, 30);
            if ($a < $e && $b > $s) return true;
        }
    }
    return false;
}
function brb_addon_day_open($b, $which) {
    $start = new DateTimeImmutable($b->start_dt, brb_tz());
    $day = $start->modify($which === 'setup' ? '-1 day' : '+1 day')->setTime(0, 0);
    $a = $day->setTime(10, 0);
    $c = $day->setTime(23, 30);
    return brb_is_free($a, $c, (int) $b->id);
}
function brb_apply_addon($booking_id, $which) {
    global $wpdb;
    $which = $which === 'takedown' ? 'takedown' : 'setup';
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    $col = $which . '_addon';
    if (!empty($b->$col)) return $b;
    if (!brb_addon_day_open($b, $which)) return new WP_Error('busy', 'That day is not open.');
    $wpdb->update(brb_table(), [$col => 1], ['id' => (int) $b->id]);
    brb_ensure_addon_line($b->id, $which);
    if (brb_price_is_set($b) || (float) $b->total > 0.009) {
        $wpdb->update(brb_table(), ['price_set' => 1], ['id' => (int) $b->id]);
        return brb_recalc_booking($b->id);
    }
    return brb_booking($b->id);
}
function brb_send_addon_prompts() {
    // Setup and takedown day add-ons are no longer offered.
}
add_action('init', 'brb_send_addon_prompts', 20);
function brb_balance_due_on(DateTimeImmutable $start) {
    return $start->setTimezone(brb_tz())->modify('-7 days')->format('Y-m-d');
}
function brb_balance_due_label($on) {
    if (!$on) return '';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $on, brb_tz());
    return $d ? $d->format('l, F j, Y') : $on;
}
function brb_balance_due_display($b) {
    if (!brb_price_is_set($b) || (float) ($b->deposit_due ?? 0) <= 0.009) return 'Price to be confirmed';
    $balance = round((float) ($b->total ?? 0) - (float) ($b->amount_paid ?? 0), 2);
    if ($balance <= 0.009) return 'Paid';
    $on = trim((string) ($b->balance_due_on ?? ''));
    if ($on === '') return 'Price to be confirmed';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $on, brb_tz());
    $today = brb_now()->setTime(0, 0);
    if (!$d || $d < $today) return 'Due now';
    return brb_balance_due_label($on);
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
        if ($s->format('Y-m-d') === $e->format('Y-m-d')) return $s->format('D M j, Y') . ' (full day)';
        $last = ($e->format('H:i') === '00:00') ? $e->modify('-1 day') : $e;
        if ($last->format('Y-m-d') === $s->format('Y-m-d')) return $s->format('D M j, Y') . ' (full day)';
        return $s->format('D M j, Y') . ' – ' . $last->format('D M j, Y') . ' (full day)';
    }
    if ($b->mode === 'half') {
        return $s->format('D M j, Y g:i A') . ' – ' . $e->format('g:i A');
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
        $deposit_left = max(0, round((float) $b->deposit_due - (float) $b->amount_paid, 2));
        $damage_left = brb_damage_left($b);
        $due_now = round(brb_amount_to_bring($b) + $damage_left, 2);
        if ($deposit_left > 0.009) {
            // Reserve stage: same figures as the admin Money box.
            $money = brb_booking_money($b);
            $deposit_left = (float) $money['deposit_left'];
            $damage_left = (float) $money['damage_left'];
            $due_now = (float) $money['due_now'];
        }
        $lines[] = brb_check_sentence('a check for ' . brb_money($due_now));
        $lines[] = brb_appointment_line();
        $lines[] = 'Write booking reference ' . $ref . ' on the memo line.';
        $rental_after = max(0, round((float) $b->total - (float) $b->deposit_due, 2));
        if ($deposit_left > 0.009) {
            if ($damage_left > 0.009) {
                $lines[] = 'That amount is the ' . brb_money($deposit_left) . ' deposit plus the ' . brb_money($damage_left) . ' refundable damage deposit.';
            }
            if ($rental_after > 0.009) {
                $when = brb_balance_due_display($b);
                if ($when === 'Due now') $lines[] = 'After that payment, the remaining balance of ' . brb_money($rental_after) . ' is due now.';
                elseif ($when !== '' && $when !== 'Price to be confirmed' && $when !== 'Paid') $lines[] = 'After that payment, the remaining balance of ' . brb_money($rental_after) . ' is due no later than ' . $when . ' (one week before the event).';
            }
        } elseif ($damage_left > 0.009) {
            $lines[] = 'The amount due now includes the unpaid refundable damage deposit of ' . brb_money($damage_left) . ' and the remaining rental balance of ' . brb_money(brb_rental_left($b)) . '.';
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
function brb_booking_damage_figure($b) {
    $line = ($b && function_exists('brb_find_line_kind')) ? brb_find_line_kind((int) $b->id, 'damage_deposit') : null;
    if ($line) return round((float) $line->amount, 2);
    return round((float) ($b->damage_deposit ?? 0), 2);
}
function brb_money_equation($parts) {
    $clean = [];
    foreach ((array) $parts as $n) {
        $n = round((float) $n, 2);
        if ($n > 0.009) $clean[] = $n;
    }
    if (!$clean) return brb_money(0);
    if (count($clean) === 1) return brb_money($clean[0]);
    $sum = 0.0;
    $bits = [];
    foreach ($clean as $n) {
        $sum += $n;
        $bits[] = brb_money($n);
    }
    return implode(' + ', $bits) . ' = ' . brb_money(round($sum, 2));
}
function brb_booking_money($b) {
    $set = brb_price_is_set($b);
    $rental = $set ? round((float) $b->total, 2) : null;
    $damage = brb_booking_damage_figure($b);
    $damage_paid = round((float) ($b->damage_paid ?? 0), 2);
    $damage_left = !empty($b->damage_refunded) ? 0.0 : max(0, round($damage - $damage_paid, 2));
    $deposit = $set ? round((float) $b->deposit_due, 2) : null;
    $paid = round((float) $b->amount_paid, 2);
    $deposit_left = $deposit === null ? null : max(0, round($deposit - $paid, 2));
    $balance = null;
    if ($rental !== null && $deposit !== null) {
        $scheduled = max(0, round($rental - $deposit, 2));
        $paid_after = max(0, round($paid - $deposit, 2));
        $balance = max(0, round($scheduled - $paid_after, 2));
    }
    $due_parts = [];
    $due_now = null;
    if ($set && $deposit_left > 0.009) {
        $due_parts[] = $deposit_left;
        if ($damage_left > 0.009) $due_parts[] = $damage_left;
        $due_now = round(array_sum($due_parts), 2);
        $due_text = brb_money_equation($due_parts);
    } elseif ($set) {
        $due_now = 0.0;
        $due_text = 'Deposit received';
    } else {
        $due_text = 'Price to be confirmed';
    }
    if ($damage <= 0.009 && $damage_paid <= 0.009) {
        $damage_text = brb_money(0);
    } elseif (!empty($b->damage_refunded)) {
        $damage_text = brb_money($damage) . ' (marked refunded)';
    } elseif ($damage_left <= 0.009 && $damage_paid > 0.009) {
        $damage_text = brb_money($damage) . ' paid';
    } elseif ($damage_paid > 0.009) {
        $damage_text = brb_money($damage) . ', ' . brb_money($damage_paid) . ' paid, ' . brb_money($damage_left) . ' unpaid';
    } else {
        $damage_text = brb_money($damage) . ' unpaid';
    }
    $card_fee = null;
    if (!empty($b->id)) {
        $fee_sum = 0.0;
        $card = false;
        foreach (brb_payments($b->id) as $p) {
            if ((string) ($p->method ?? '') === 'card') {
                $card = true;
                $fee_sum += (float) ($p->fee ?? 0);
            }
        }
        if ($card) $card_fee = round($fee_sum, 2);
    }
    $rows = [
        'Rental' => $set ? brb_money($rental) : 'Price to be confirmed',
        'Refundable damage deposit' => $damage_text,
        'Due now to reserve' => $due_text,
        'Balance' => $set ? brb_money((float) $balance) : 'Price to be confirmed',
        'Balance due' => brb_balance_due_display($b),
    ];
    if ($card_fee !== null) {
        $rows['Card processing fee (' . brb_fee_percent_label() . ')'] = brb_money($card_fee) . ' (not part of the rental or the damage deposit)';
    }
    return [
        'priced' => $set,
        'rental' => $rental,
        'damage' => $damage,
        'damage_paid' => $damage_paid,
        'damage_left' => $damage_left,
        'deposit' => $deposit,
        'deposit_left' => $deposit_left,
        'balance' => $balance,
        'due_parts' => $due_parts,
        'due_now' => $due_now,
        'due_text' => $due_text,
        'card_fee' => $card_fee,
        'rows' => $rows,
    ];
}
/**
 * Guest-facing money rows. Every value comes from brb_booking_money(), the
 * same helper behind the admin Money box, so guests see the same Rental,
 * Refundable damage deposit, Due now to reserve, and Balance figures.
 * Pass ['paid' => true] to add what has been paid toward the rental.
 */
function brb_guest_money_rows($b, $opts = []) {
    $m = brb_booking_money($b);
    if (!$m['priced']) return ['Price' => 'Price to be confirmed'];
    $rows = ['Rental' => $m['rows']['Rental']];
    if (!empty($opts['paid'])) $rows['Paid'] = brb_money(round((float) $b->amount_paid, 2));
    $rows['Refundable damage deposit'] = $m['rows']['Refundable damage deposit'];
    $rows['Due now to reserve'] = $m['rows']['Due now to reserve'];
    $rows['Balance'] = $m['rows']['Balance'];
    $due = (string) $m['rows']['Balance due'];
    if ($due !== '' && $due !== 'Price to be confirmed') $rows['Balance due'] = $due;
    return $rows;
}
function brb_guest_money_text($b, $opts = []) {
    $out = [];
    foreach (brb_guest_money_rows($b, $opts) as $label => $value) $out[] = $label . ': ' . $value;
    return implode("\n", $out);
}
function brb_guest_money_html($b, $opts = []) {
    $html = '<ul class="brb-money">';
    foreach (brb_guest_money_rows($b, $opts) as $label => $value) {
        $html .= '<li><strong>' . esc_html($label) . ':</strong> ' . esc_html($value) . '</li>';
    }
    return $html . '</ul>';
}
function brb_date_hold_state($b) {
    $status = (string) ($b->status ?? '');
    if (in_array($status, ['deposit_paid', 'paid', 'confirmed'], true)) {
        return 'Reserved. The deposit has been received.';
    }
    if (in_array($status, ['approved', 'held'], true)) {
        return 'Held after approval; reserved when the deposit is received.';
    }
    if (in_array($status, ['declined', 'cancelled', 'expired', 'spam'], true)) {
        return 'Not held. This request is closed.';
    }
    return 'Not held yet. Held after approval; reserved when the deposit is received.';
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

function brb_host_name($host) {
    $host = strtolower(trim((string) $host));
    $host = rtrim($host, '.');
    $host = (string) preg_replace('/:\d+$/', '', $host);
    return rtrim($host, '.');
}
function brb_is_live_host($host) {
    $host = brb_host_name($host);
    return $host === 'boardroomqc.com' || $host === 'www.boardroomqc.com';
}
function brb_is_staging_host($host) {
    return brb_host_name($host) === 'boardroom-staging.madproductions.us';
}
function brb_request_host() {
    $raw = '';
    if (!empty($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) $raw = $_SERVER['HTTP_HOST'];
    elseif (!empty($_SERVER['SERVER_NAME']) && is_string($_SERVER['SERVER_NAME'])) $raw = $_SERVER['SERVER_NAME'];
    return brb_host_name($raw);
}
function brb_home_host() {
    if (!function_exists('home_url')) return '';
    return brb_host_name((string) wp_parse_url(home_url(), PHP_URL_HOST));
}
function brb_is_nonproduction_env() {
    return in_array(strtolower(brb_env('BRB_ENV')), ['staging', 'dev', 'test'], true);
}
function brb_is_production() {
    static $checking = false;
    if ($checking) return !brb_is_nonproduction_env();
    $checking = true;
    $request = brb_request_host();
    $home = brb_home_host();
    $checking = false;
    // The public production host wins over any BRB_ENV value.
    if (brb_is_live_host($request) || brb_is_live_host($home)) return true;
    // The known staging host wins too, including over BRB_ENV=production.
    // That hostname is publicly reachable, so an explicit production flag there
    // would mint real-looking refs and send unmarked mail again.
    if (brb_is_staging_host($request) || brb_is_staging_host($home)) return false;
    return !brb_is_nonproduction_env();
}
function brb_live_book_url() {
    return 'https://boardroomqc.com/book/';
}
function brb_staging_guest_message() {
    return 'This is a staging site, not the real Boardroom. Please book at https://boardroomqc.com/book/.';
}
function brb_public_writes_open() {
    if (brb_is_production()) return true;
    return is_user_logged_in() && current_user_can('manage_options');
}
function brb_staging_guest_notice_html() {
    $url = brb_live_book_url();
    return '<div class="brb-staging-notice" role="status"><p>This is a staging site, not the real Boardroom. Please book at <a href="' . esc_url($url) . '">https://boardroomqc.com/book/</a>.</p></div>';
}
function brb_staging_banner() {
    if (brb_is_production()) return;
    echo '<div class="brb-staging-banner" role="status">STAGING - not the real site</div>';
}
function brb_staging_banner_css() {
    if (brb_is_production()) return;
    echo '<style>.brb-staging-banner{position:sticky;top:0;z-index:100000;background:#8f1d1d;color:#fff;text-align:center;font:700 16px/1.4 system-ui,sans-serif;padding:12px 16px;letter-spacing:.03em}.brb-staging-notice{background:#fdecec;border-left:4px solid #8f1d1d;padding:14px 16px;margin:0 0 16px}.brb-staging-notice p{margin:0}.brb-staging-notice a{font-weight:700}</style>';
}
add_action('wp_head', 'brb_staging_banner_css', 1);
add_action('admin_head', 'brb_staging_banner_css', 1);
add_action('wp_body_open', 'brb_staging_banner', 1);
add_action('in_admin_header', 'brb_staging_banner', 1);
// Staging and other non-production hosts serve one fixed robots.txt that blocks
// the whole site. It runs after Yoast's filter_robots (priority 99999), which
// otherwise appends its own block with an empty "Disallow:" and a Sitemap line.
function brb_staging_robots_body() {
    return "User-agent: *\nDisallow: /\n";
}
function brb_staging_robots_priority() {
    return 100000;
}
function brb_staging_robots_txt($output, $public = null) {
    unset($public);
    if (brb_is_production()) return $output;
    return brb_staging_robots_body();
}
add_filter('robots_txt', 'brb_staging_robots_txt', brb_staging_robots_priority(), 2);
function brb_is_stripe_webhook_request() {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = (string) parse_url($uri, PHP_URL_PATH);
    if (preg_match('#/wp-json/brb/v1/stripe-webhook/?$#', $path)) return true;
    $route = '';
    if (isset($_GET['rest_route']) && is_string($_GET['rest_route'])) $route = $_GET['rest_route'];
    if ($route === '') {
        $query = (string) parse_url($uri, PHP_URL_QUERY);
        if ($query !== '') {
            $qs = [];
            parse_str($query, $qs);
            if (isset($qs['rest_route']) && is_string($qs['rest_route'])) $route = $qs['rest_route'];
        }
    }
    return '/' . trim($route, '/') === '/brb/v1/stripe-webhook';
}
function brb_staging_basic_exempt() {
    if (brb_is_production()) return true;
    if ((defined('DOING_CRON') && DOING_CRON) || (function_exists('wp_doing_cron') && wp_doing_cron())) return true;
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (strpos($uri, 'wp-cron.php') !== false) return true;
    if (brb_is_stripe_webhook_request()) return true;
    if (is_user_logged_in() && current_user_can('manage_options')) return true;
    return false;
}
function brb_staging_basic_configured() {
    return brb_env('BRB_STAGING_USER') !== '' && brb_env('BRB_STAGING_PASS') !== '';
}
function brb_staging_basic_presented() {
    $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
    if ($user === '' && $pass === '') {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (stripos($header, 'basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if (is_string($decoded) && strpos($decoded, ':') !== false) {
                [$user, $pass] = explode(':', $decoded, 2);
            }
        }
    }
    return [(string) $user, (string) $pass];
}
function brb_staging_basic_credentials_match() {
    [$given_user, $given_pass] = brb_staging_basic_presented();
    $user = brb_env('BRB_STAGING_USER');
    $pass = brb_env('BRB_STAGING_PASS');
    return hash_equals($user, $given_user) && hash_equals($pass, $given_pass);
}
function brb_staging_basic_auth() {
    if (defined('WP_CLI') && WP_CLI) return;
    if (PHP_SAPI === 'cli') return;
    if (brb_staging_basic_exempt()) return;
    if (!brb_staging_basic_configured()) return;
    if (brb_staging_basic_credentials_match()) return;
    if (!headers_sent()) {
        header('WWW-Authenticate: Basic realm="Staging"');
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "This staging site is private.\n";
    exit;
}
add_action('init', 'brb_staging_basic_auth', 1);
function brb_search_indexing_allowed() {
    if (brb_is_production()) return true;
    return brb_env('DISCOURAGE_SEARCH') === '0';
}
function brb_apply_search_visibility() {
    $public = brb_search_indexing_allowed() ? '1' : '0';
    if ((string) get_option('blog_public') !== $public) update_option('blog_public', $public);
}
add_action('init', 'brb_apply_search_visibility', 0);
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
function brb_mail_from_address() {
    $env = sanitize_email(brb_env('BRB_SMTP_FROM'));
    if (is_email($env)) return $env;
    $set = sanitize_email((string) brb_opt('mail_from'));
    return is_email($set) ? $set : 'boardroomqc@gmail.com';
}
function brb_mail_from_name() {
    $env = trim(brb_env('BRB_SMTP_FROM_NAME'));
    if ($env !== '') return $env;
    $name = trim((string) brb_opt('mail_from_name'));
    return $name !== '' ? $name : 'The Boardroom';
}
function brb_guest_reply_to() {
    $set = sanitize_email((string) brb_opt('mail_reply_to'));
    return is_email($set) ? $set : brb_mail_from_address();
}
function brb_gmail_authuser() {
    $email = function_exists('brb_guest_reply_to') ? sanitize_email((string) brb_guest_reply_to()) : '';
    return is_email($email) ? $email : 'boardroomqc@gmail.com';
}
function brb_guest_email_subject($booking) {
    $date = '';
    if (!empty($booking->start_dt)) {
        try {
            $date = (new DateTimeImmutable($booking->start_dt, brb_tz()))->format('D M j, Y');
        } catch (Exception $e) {
            $date = '';
        }
    }
    return 'The Boardroom – ' . brb_ref($booking) . ' – ' . $date;
}
function brb_guest_email_body($booking) {
    $name = trim((string) ($booking->name ?? ''));
    $parts = $name === '' ? [] : preg_split('/\s+/', $name);
    $first = $parts[0] ?? '';
    return "Hi {$first},\n\n\n\n- Devin\nThe Boardroom\n";
}
function brb_gmail_compose_url($booking) {
    return 'https://mail.google.com/mail/?authuser=' . rawurlencode(brb_gmail_authuser())
        . '&view=cm&fs=1'
        . '&to=' . rawurlencode((string) ($booking->email ?? ''))
        . '&su=' . rawurlencode(brb_guest_email_subject($booking))
        . '&body=' . rawurlencode(brb_guest_email_body($booking));
}
function brb_blocked_inquiry_cc() {
    return 'contiveros8@gmail.com';
}
function brb_is_blocked_inquiry_cc($email) {
    return strcasecmp(sanitize_email((string) $email), brb_blocked_inquiry_cc()) === 0;
}
function brb_inquiry_cc_list() {
    $out = [];
    foreach (brb_parse_email_list(brb_opt('inquiry_cc'))['valid'] as $one) {
        if (brb_is_blocked_inquiry_cc($one)) continue;
        $out[] = $one;
    }
    return $out;
}
function brb_drop_blocked_inquiry_cc() {
    $stored = get_option('brb_settings', []);
    if (!is_array($stored) || !array_key_exists('inquiry_cc', $stored)) return;
    $current = (string) $stored['inquiry_cc'];
    $kept = [];
    $found = false;
    foreach (brb_parse_email_list($current)['valid'] as $one) {
        if (brb_is_blocked_inquiry_cc($one)) {
            $found = true;
            continue;
        }
        $kept[] = $one;
    }
    if (!$found && stripos($current, brb_blocked_inquiry_cc()) === false) return;
    $stored['inquiry_cc'] = implode("\n", $kept);
    update_option('brb_settings', $stored);
    if (get_option('brb_inquiry_cc_seeded', null) !== null) delete_option('brb_inquiry_cc_seeded');
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
    $phpmailer->From = brb_mail_from_address();
    $phpmailer->FromName = brb_mail_from_name();
    $phpmailer->Sender = brb_mail_from_address();
}
add_action('phpmailer_init', 'brb_configure_phpmailer');
function brb_redact_secret($text) {
    $text = (string) $text;
    $pass = brb_env('BRB_SMTP_PASS');
    if ($pass !== '') $text = str_replace($pass, '[redacted]', $text);
    return $text;
}
function brb_parse_email_list($raw) {
    $valid = [];
    $invalid = [];
    $parts = preg_split('/[,;\s]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) $parts = [];
    foreach ($parts as $part) {
        $part = trim($part, " \t\"'<>");
        if ($part === '') continue;
        if (is_email($part)) $valid[strtolower($part)] = $part;
        else $invalid[] = $part;
    }
    return ['valid' => array_values($valid), 'invalid' => $invalid];
}
function brb_admin_emails() {
    $parsed = brb_parse_email_list(brb_opt('admin_email'));
    if ($parsed['invalid']) {
        static $logged = false;
        if (!$logged) {
            $logged = true;
            error_log('Boardroom booking skipped invalid admin notification email(s): ' . implode(', ', $parsed['invalid']));
        }
    }
    $out = [];
    foreach ($parsed['valid'] as $email) $out[strtolower($email)] = $email;
    if (!$out) {
        $fallback = sanitize_email((string) get_option('admin_email'));
        if (is_email($fallback)) $out[strtolower($fallback)] = $fallback;
    }
    return array_values($out);
}
function brb_staff_reply() {
    $list = brb_admin_emails();
    return $list ? $list[0] : '';
}
function brb_mail_staff($subject, $body, $reply = '') {
    $sent = false;
    foreach (brb_admin_emails() as $to) {
        if (brb_mail($to, $subject, $body, $reply)) $sent = true;
    }
    return $sent;
}
function brb_admin_email_notice_html() {
    $parsed = brb_parse_email_list(brb_opt('admin_email'));
    if (!$parsed['invalid']) return '';
    return '<div class="notice notice-error"><p>The admin notification email list in Bookings → Settings has an entry that is not a valid email address: <strong>' . esc_html(implode(', ', $parsed['invalid'])) . '</strong>. Separate each address with a comma, semicolon, or a new line. Invalid entries are not emailed.</p></div>';
}
function brb_admin_email_notice() {
    if (!is_admin() || !current_user_can('manage_options')) return;
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page === 'brb-settings') return;
    $html = brb_admin_email_notice_html();
    if ($html !== '') echo $html;
}
add_action('admin_notices', 'brb_admin_email_notice');
function brb_mail_subject($subject) {
    $subject = str_replace(["\r", "\n"], '', (string) $subject);
    if (brb_is_production()) return $subject;
    if (preg_match('/^\[STAGING\](?:\s|$)/', $subject)) return $subject;
    return '[STAGING] ' . $subject;
}
add_filter('wp_mail', function ($atts) {
    if (is_array($atts) && array_key_exists('subject', $atts)) $atts['subject'] = brb_mail_subject($atts['subject']);
    return $atts;
});
function brb_mail($to, $subject, $body, $reply = '', $from = '', $cc = []) {
    $to = sanitize_email($to);
    if (!$to || !is_email($to) || brb_is_blocked_inquiry_cc($to)) return false;
    if (!$from || !is_email($from)) $from = brb_mail_from_address();
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    $headers[] = 'From: ' . brb_mail_from_name() . ' <' . $from . '>';
    if ($reply && is_email($reply)) $headers[] = 'Reply-To: ' . $reply;
    foreach ((array) $cc as $copy) {
        $copy = sanitize_email($copy);
        if (is_email($copy) && strcasecmp($copy, $to) !== 0 && !brb_is_blocked_inquiry_cc($copy)) $headers[] = 'Cc: ' . $copy;
    }
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
    return add_query_arg('br_token', $b->token, brb_book_url());
}
function brb_send_request_emails($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $price = brb_price_phrase($b);
    $screen = implode("\n", brb_screening_lines($b));
    $facts = brb_approval_line() . "\n\n" . brb_request_open_line() . "\n\n" . brb_reserved_line() . "\n\n" . brb_deposit_line() . "\n\n" . brb_not_held_line();
    $detail = "When: " . brb_when($b) . "\n" . brb_event_type_line($b) . "Estimated Guests: {$b->guests}\n" . ($screen !== '' ? $screen . "\n" : '') . "Price: {$price}\n";
    $guest_detail = brb_price_is_set($b) ? str_replace("Price: {$price}\n", brb_guest_money_text($b) . "\n", $detail) : $detail;
    $body = brb_guest_hello_line() . "\n\n" . brb_guest_review_line() . "\n\n{$guest_detail}\n" . brb_no_overnight_line() . "\n" . brb_guest_out_line() . "\n" . brb_guest_free_line() . "\n" . brb_hours_line() . "\n\nCancellation policy:\n{$o['policy']}\n\nView your request: " . brb_guest_link($b) . "\n";
    brb_mail($b->email, 'We received your Boardroom request (' . $ref . ')', $body, brb_guest_reply_to());
    $admin = "A new request is pending review. The date is not held.\n\nReference: {$ref}\nWhen: " . brb_when($b) . "\nGuest: {$b->name}\nEmail: {$b->email}\nPhone: {$b->phone}\n" . brb_event_type_line($b) . "Guests: {$b->guests}\n" . ($screen !== '' ? $screen . "\n" : '') . "Price: {$price}\nSource: " . brb_source_label($b->source) . "\nNotes: {$b->notes}\n\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n";
    brb_mail_staff('Request pending review ' . $ref . ' – ' . $b->name, $admin, $b->email);
}
function brb_send_hold_emails($b) {
    brb_send_request_emails($b);
}
function brb_audit_who() {
    if (!function_exists('wp_get_current_user')) return 'system';
    $user = wp_get_current_user();
    if ($user && !empty($user->user_login)) return (string) $user->user_login;
    return 'system';
}
function brb_audit_clean($text) {
    $text = trim(preg_replace("/[\r\n]+/", ' ', (string) $text));
    return $text;
}
function brb_audit_append($booking_id, $text, $who = null) {
    global $wpdb;
    $text = brb_audit_clean($text);
    if ($text === '') return;
    $b = brb_booking($booking_id);
    if (!$b) return;
    if ($who === null || $who === '') $who = brb_audit_who();
    $who = brb_audit_clean($who);
    if ($who === '') $who = 'system';
    brb_ensure_column(brb_table(), 'audit_log', 'longtext NULL');
    $line = brb_now()->format('Y-m-d g:i A') . ' America/Chicago — ' . $who . ': ' . $text;
    $fresh = brb_booking($b->id);
    $log = trim((string) ($fresh->audit_log ?? ''));
    $log = $log === '' ? $line : $log . "\n" . $line;
    $wpdb->update(brb_table(), ['audit_log' => $log], ['id' => (int) $b->id]);
}
function brb_audit_entries($log) {
    $log = trim((string) $log);
    if ($log === '') return [];
    $lines = preg_split("/\r\n|\n|\r/", $log);
    if (!is_array($lines)) return [];
    $lines = array_values(array_filter(array_map('trim', $lines), function ($line) {
        return $line !== '';
    }));
    return array_reverse($lines);
}
function brb_delete_log_append($text) {
    $text = brb_audit_clean($text);
    if ($text === '') return;
    $line = brb_now()->format('Y-m-d g:i A') . ' America/Chicago — ' . brb_audit_who() . ': ' . $text;
    $log = get_option('brb_delete_log', '');
    if (!is_string($log)) $log = '';
    $log = trim($log);
    $log = $log === '' ? $line : $log . "\n" . $line;
    update_option('brb_delete_log', $log, false);
}
function brb_booking_has_recorded_payment($b) {
    if (!$b) return false;
    if ((float) ($b->amount_paid ?? 0) > 0.009) return true;
    if ((float) ($b->damage_paid ?? 0) > 0.009) return true;
    return brb_payments($b->id) !== [];
}
function brb_reserve_date_url($ymd) {
    return add_query_arg('date', $ymd, brb_book_url());
}
function brb_decline_date_line($ymd) {
    try {
        $label = (new DateTimeImmutable($ymd . ' 12:00:00', brb_tz()))->format('l, F j, Y');
    } catch (Exception $e) {
        $label = $ymd;
    }
    return $label . ': ' . brb_reserve_date_url($ymd);
}
function brb_decline_guest_body($b, $message = '', $dates = []) {
    $ref = brb_ref($b);
    $message = trim((string) $message);
    $dates = array_values(array_filter((array) $dates, function ($ymd) {
        return $ymd !== '';
    }));
    $head = brb_guest_hello_line() . "\n\n" . brb_guest_review_line() . "\n\n";
    $tail = "\n\n" . brb_deposit_line() . "\n\nIf a payment was recorded, our team will resolve it manually.\n";
    if ($message === '' && !$dates) {
        $core = 'This request (' . $ref . ', ' . brb_when($b) . ') is not approved because it does not meet our event rules or the date is not available. The date is not reserved.';
    } else {
        $core = 'This request (' . $ref . ', ' . brb_when($b) . ') is not approved. The date is not reserved.';
        if ($message !== '') $core .= "\n\n" . $message;
        if ($dates) {
            $lines = [];
            foreach ($dates as $ymd) $lines[] = brb_decline_date_line($ymd);
            $core .= "\n\nThese dates are open if you would like to request one of them:\n" . implode("\n", $lines);
        }
    }
    return $head . $core . $tail;
}
function brb_collect_decline_dates($input, $b) {
    $raw = [];
    if (isset($input['alt_dates']) && is_array($input['alt_dates'])) $raw = $input['alt_dates'];
    for ($i = 1; $i <= 3; $i++) {
        if (isset($input['alt_date_' . $i])) $raw[] = $input['alt_date_' . $i];
    }
    $own = substr((string) ($b->start_dt ?? ''), 0, 10);
    $out = [];
    foreach ($raw as $ymd) {
        $ymd = sanitize_text_field((string) $ymd);
        if ($ymd === '') continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return new WP_Error('date', 'Choose a free date from the calendar.');
        if ($ymd === $own) return new WP_Error('date', 'Suggested dates have to be different from this booking.');
        if (!brb_public_date_is_open($ymd)) return new WP_Error('date', 'That date is not free on the calendar.');
        if (!in_array($ymd, $out, true)) $out[] = $ymd;
        if (count($out) > 3) return new WP_Error('date', 'Suggest up to 3 dates.');
    }
    return $out;
}
function brb_free_full_days($from_ymd, $to_ymd, $exclude_id = 0) {
    global $wpdb;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from_ymd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $to_ymd)) return [];
    $tz = brb_tz();
    try {
        $start = new DateTimeImmutable($from_ymd . ' 00:00:00', $tz);
        $end_day = new DateTimeImmutable($to_ymd . ' 00:00:00', $tz);
    } catch (Exception $e) {
        return [];
    }
    if ($end_day < $start) return [];
    $range_end = $end_day->modify('+1 day');
    list($block, $args) = brb_blocking_sql();
    $sql = 'SELECT start_dt, end_dt FROM ' . brb_table() . " WHERE id<>%d AND start_dt < %s AND end_dt > %s AND $block";
    $params = array_merge([(int) $exclude_id, $range_end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')], $args);
    $bookings = $wpdb->get_results($wpdb->prepare($sql, $params));
    $holds = brb_holds_overlapping($start->format('Y-m-d H:i:s'), $range_end->format('Y-m-d H:i:s'));
    $addons = $wpdb->get_results($wpdb->prepare(
        'SELECT id, start_dt, setup_addon, takedown_addon FROM ' . brb_table() . ' WHERE id<>%d AND (setup_addon=1 OR takedown_addon=1)',
        (int) $exclude_id
    ));
    $blackouts = array_fill_keys(brb_blackouts(), true);
    $notice = brb_notice_cutoff();
    $today = brb_now()->setTime(0, 0);
    $free = [];
    for ($d = $start; $d <= $end_day; $d = $d->modify('+1 day')) {
        if ($d < $today) continue;
        $s = $d->setTime(10, 0);
        $e = $d->setTime(23, 30);
        if ($s < $notice) continue;
        $ymd = $d->format('Y-m-d');
        if (isset($blackouts[$ymd])) continue;
        $hit = false;
        foreach ((array) $bookings as $row) {
            $bs = new DateTimeImmutable($row->start_dt, $tz);
            $be = new DateTimeImmutable($row->end_dt, $tz);
            if ($bs < $e && $be > $s) { $hit = true; break; }
        }
        if ($hit) continue;
        foreach ((array) $holds as $row) {
            $bs = new DateTimeImmutable($row->start_dt, $tz);
            $be = new DateTimeImmutable($row->end_dt, $tz);
            if ($bs < $e && $be > $s) { $hit = true; break; }
        }
        if ($hit) continue;
        foreach ((array) $addons as $row) {
            $origin = new DateTimeImmutable($row->start_dt, $tz);
            $windows = [];
            if (!empty($row->setup_addon)) $windows[] = $origin->modify('-1 day');
            if (!empty($row->takedown_addon)) $windows[] = $origin->modify('+1 day');
            foreach ($windows as $day) {
                $a = $day->setTime(10, 0);
                $c = $day->setTime(23, 30);
                if ($a < $e && $c > $s) { $hit = true; break 2; }
            }
        }
        if (!$hit) $free[] = $ymd;
    }
    return $free;
}
function brb_price_guest_subject($b) {
    return 'Your Boardroom price is confirmed (' . brb_ref($b) . ')';
}
function brb_approve_guest_subject($b) {
    return 'Your Boardroom request is approved (' . brb_ref($b) . ')';
}
function brb_decline_guest_subject($b) {
    return 'Your Boardroom request was declined (' . brb_ref($b) . ')';
}
function brb_guest_mail_len($text) {
    $text = (string) $text;
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}
function brb_sanitize_guest_subject($subject) {
    $subject = sanitize_text_field((string) $subject);
    if (brb_guest_mail_len($subject) > 200) {
        return new WP_Error('mail', 'The email subject must be 200 characters or fewer.');
    }
    return $subject;
}
function brb_sanitize_guest_body($body) {
    $body = sanitize_textarea_field((string) $body);
    if (brb_guest_mail_len($body) > 10000) {
        return new WP_Error('mail', 'The email must be 10,000 characters or fewer.');
    }
    return $body;
}
function brb_custom_guest_mail($args, $default_subject, $default_body) {
    if (!is_array($args)) $args = [];
    if (array_key_exists('subject', $args)) {
        $subject = brb_sanitize_guest_subject($args['subject']);
        if (is_wp_error($subject)) return $subject;
    } else {
        $subject = $default_subject;
    }
    if (array_key_exists('body', $args)) {
        $body = brb_sanitize_guest_body($args['body']);
        if (is_wp_error($body)) return $body;
    } else {
        $body = $default_body;
    }
    if ($subject === '') $subject = $default_subject;
    if ($body === '') $body = $default_body;
    return [$subject, $body];
}
function brb_approve_guest_body($b) {
    $ref = brb_ref($b);
    $guest_facts = implode("\n\n", array_values(array_filter(brb_payment_lines($b), function ($line) {
        return !in_array($line, [brb_not_held_line(), brb_reserved_line(), brb_approval_line(), brb_request_open_line()], true);
    })));
    return brb_guest_hello_line() . "\n\nYour request is approved.\n\n" . brb_guest_review_line() . "\n\n" . brb_guest_money_text($b) . "\n\n" . $guest_facts . "\n\n" . brb_guest_out_line() . "\n" . brb_guest_free_line() . "\n\nWhen: " . brb_when($b) . "\n" . brb_event_type_line($b) . "Estimated Guests: {$b->guests}\nReference: {$ref}\n\nView your booking: " . brb_guest_link($b) . "\n\n" . brb_checklist_plain();
}
function brb_approve_preview_body($b) {
    $preview = clone $b;
    if (in_array((string) $preview->status, ['review', 'pending'], true)) $preview->status = 'approved';
    return brb_approve_guest_body($preview);
}
function brb_send_approve_email($b, $subject = '', $body = '') {
    $ref = brb_ref($b);
    $facts = implode("\n\n", brb_payment_lines($b));
    $mail = brb_custom_guest_mail(['subject' => $subject, 'body' => $body], brb_approve_guest_subject($b), brb_approve_guest_body($b));
    if (is_wp_error($mail)) return $mail;
    list($subject, $body) = $mail;
    brb_mail($b->email, $subject, $body, brb_guest_reply_to());
    $staff = "Request approved.\n\n{$facts}\n\nWhen: " . brb_when($b) . "\n" . brb_event_type_line($b) . "Estimated Guests: {$b->guests}\nReference: {$ref}\n\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n";
    brb_mail_staff('Request approved ' . $ref . ' – ' . $b->name, $staff, $b->email);
}
function brb_send_decline_email($b, $message = '', $dates = [], $subject = '', $body = '') {
    $ref = brb_ref($b);
    $mail = brb_custom_guest_mail(['subject' => $subject, 'body' => $body], brb_decline_guest_subject($b), brb_decline_guest_body($b, $message, $dates));
    if (is_wp_error($mail)) return $mail;
    list($subject, $body) = $mail;
    brb_mail($b->email, $subject, $body, brb_guest_reply_to());
    $staff = "Request declined.\n\n{$ref}, " . brb_when($b) . "\nThe date is not reserved.\n\n" . brb_deposit_line() . "\n";
    $message = trim((string) $message);
    if ($message !== '') $staff .= "\nMessage to the guest:\n" . $message . "\n";
    if ($dates) {
        $staff .= "\nSuggested dates:\n";
        foreach ($dates as $ymd) $staff .= brb_decline_date_line($ymd) . "\n";
    }
    $staff .= "\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n";
    brb_mail_staff('Request declined ' . $ref . ' – ' . $b->name, $staff, $b->email);
}
function brb_send_checklist_reminder($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $body = brb_guest_hello_line() . "\n\nYour event is about a week away.\n\nWhen: " . brb_when($b) . "\nReference: {$ref}\n\nView your booking: " . brb_guest_link($b) . "\n\n" . brb_checklist_plain();
    return brb_mail($b->email, 'Your Boardroom event is about a week away (' . $ref . ')', $body, brb_guest_reply_to());
}
function brb_checklist_reminders() {
    global $wpdb;
    $days = [
        brb_now()->modify('+7 days')->format('Y-m-d'),
        brb_now()->modify('+6 days')->format('Y-m-d'),
    ];
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT id FROM ' . brb_table() . " WHERE checklist_reminded IS NULL AND status IN ('approved','deposit_paid','paid') AND DATE(start_dt) IN (%s,%s)",
        $days[0],
        $days[1]
    ));
    if (!$rows) return;
    foreach ($rows as $row) {
        $b = brb_booking((int) $row->id);
        if (!$b || !is_email($b->email)) continue;
        if (brb_send_checklist_reminder($b)) {
            $wpdb->update(brb_table(), ['checklist_reminded' => current_time('mysql', true)], ['id' => (int) $b->id]);
        }
    }
}
add_action('brb_cron_checklist', 'brb_checklist_reminders');
function brb_payment_date_label($ymd) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $ymd)) return brb_now()->format('F j, Y');
    try {
        return (new DateTimeImmutable($ymd . ' 12:00:00', brb_tz()))->format('F j, Y');
    } catch (Exception $e) {
        return brb_now()->format('F j, Y');
    }
}
function brb_receipt_block($b, $amount, $method, $received_on) {
        $balance = brb_money(max(0, round(brb_rental_left($b) + brb_damage_left($b), 2)));
    return "Amount: " . brb_money($amount) . "\n"
        . "Date: " . brb_payment_date_label($received_on) . "\n"
        . "Method: " . brb_pay_method_label($method) . "\n"
        . "Booking reference: " . brb_ref($b) . "\n"
        . "Event date: " . brb_when($b) . "\n"
        . "Balance remaining: " . $balance;
}
function brb_send_receipt_copies($b, $subject, $admin_subject, $body) {
    $admins = brb_admin_emails();
    brb_mail($b->email, $subject, $body, brb_guest_reply_to());
    $staff = $body . "\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n";
    foreach ($admins as $admin) {
        brb_mail($admin, $admin_subject, $staff, $b->email);
    }
}
function brb_payment_guest_message($b, $amount, $check_number, $method = 'check', $payment = null) {
    $ref = brb_ref($b);
    $method = brb_pay_method_known($method) ? $method : 'check';
    if ($payment) {
        $last_pay = $payment;
    } else {
        $pays = brb_payments($b->id);
        $last_pay = $pays ? end($pays) : null;
    }
    $fee = ($method === 'card' && $last_pay) ? (float) ($last_pay->fee ?? 0) : 0;
    $received_on = ($last_pay && !empty($last_pay->received_on)) ? (string) $last_pay->received_on : brb_now()->format('Y-m-d');
    if ((float) $amount > 0) {
        if ($method === 'cash') {
            $check = 'We recorded cash for ' . brb_money($amount) . '.';
        } elseif ($method === 'card') {
            $check = 'We recorded a card payment for ' . brb_money($amount) . '.';
            if ($fee > 0.009) $check .= ' A card processing fee of ' . brb_money($fee) . ' was charged on top and is not part of the booking balance. ' . brb_fee_public_line();
        } else {
            $check = 'We recorded a check for ' . brb_money($amount);
            if ($check_number !== '') $check .= ' (check ' . $check_number . ')';
            $check .= '.';
        }
    } else {
        $check = 'This booking is marked paid in full.';
    }
    if ($last_pay && preg_match('/Refundable damage deposit \$[\d,.]+/', (string) $last_pay->notes, $damage_note)) {
        $check .= ' ' . $damage_note[0] . '.';
    }
    $follow = '';
    if ($b->status !== 'paid') {
        $due = brb_balance_due_display($b);
        if ($due === 'Due now') $follow = 'The balance remaining is due now.';
        elseif ($due && $due !== 'Price to be confirmed' && $due !== 'Paid') $follow = 'The balance remaining is due the week of the event, no later than ' . $due . '.';
        $still = round(brb_rental_left($b) + brb_damage_left($b), 2);
        if ($still > 0.009 && brb_damage_left($b) > 0.009) {
            $follow .= ' The amount still due is ' . brb_money($still) . ', including the unpaid refundable damage deposit of ' . brb_money(brb_damage_left($b)) . '.';
        }
        $follow .= "\n\n" . brb_check_sentence('the remaining balance as a check') . "\n" . brb_appointment_line() . "\nWrite booking reference {$ref} on the memo line.";
        if ($method === 'card') $follow .= "\nYou can also pay the rest online from your booking page.";
    } else {
        $follow = 'Your booking is paid in full.';
    }
    if ($b->status === 'paid') {
        $subject = 'Your Boardroom receipt, booking confirmed (' . $ref . ')';
        $admin_subject = 'Payment recorded for ' . $ref . ' – ' . $b->name;
    } elseif ($method === 'cash') {
        $subject = 'Your Boardroom receipt for ' . $ref;
        $admin_subject = 'Cash recorded for ' . $ref . ' – ' . $b->name;
    } elseif ($method === 'card') {
        $subject = 'Your Boardroom receipt for ' . $ref;
        $admin_subject = 'Card payment recorded for ' . $ref . ' – ' . $b->name;
    } else {
        $subject = 'Your Boardroom receipt for ' . $ref;
        $admin_subject = 'Check recorded for ' . $ref . ' – ' . $b->name;
    }
    $body = brb_guest_hello_line() . "\n\nHere is your receipt.\n\n" . brb_receipt_block($b, $amount, $method, $received_on) . "\n\n{$check}\n\n{$follow}\n\n" . brb_event_type_line($b) . "Estimated Guests: {$b->guests}\n\nView your booking: " . brb_guest_link($b) . "\n";
    return ['subject' => $subject, 'admin_subject' => $admin_subject, 'body' => $body];
}
function brb_send_payment_email($b, $amount, $check_number, $method = 'check', $payment = null, $mail = []) {
    $message = brb_payment_guest_message($b, $amount, $check_number, $method, $payment);
    $custom = brb_custom_guest_mail($mail, $message['subject'], $message['body']);
    if (is_wp_error($custom)) return $custom;
    list($subject, $body) = $custom;
    $message['subject'] = $subject;
    $message['body'] = $body;
    brb_send_receipt_copies($b, $message['subject'], $message['admin_subject'], $message['body']);
    return $message;
}
function brb_preview_payment_message($b, $amount, $check_number, $received_on, $notes, $method) {
    $amount = round((float) $amount, 2);
    $split = brb_split_payment($b, $amount, null);
    if (is_wp_error($split)) return $split;
    $clone = clone $b;
    $clone->amount_paid = round((float) $b->amount_paid + $split['rental'], 2);
    $clone->damage_paid = round((float) ($b->damage_paid ?? 0) + $split['damage'], 2);
    $clone->status = ($clone->amount_paid + 0.009 >= (float) $b->total) ? 'paid' : 'deposit_paid';
    $notes = sanitize_textarea_field((string) $notes);
    if ($split['damage'] > 0.009) $notes = trim($notes . ' Refundable damage deposit ' . brb_money($split['damage']) . '.');
    $method = sanitize_key((string) $method);
    if (!brb_pay_method_known($method)) $method = 'check';
    if ($method === 'cash' || $method === 'card') $check_number = '';
    else $check_number = sanitize_text_field((string) $check_number);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $received_on)) $received_on = brb_now()->format('Y-m-d');
    $payment = (object) [
        'method' => $method,
        'fee' => 0,
        'received_on' => $received_on,
        'notes' => $notes,
    ];
    return brb_payment_guest_message($clone, $amount, $check_number, $method, $payment);
}
function brb_send_expired_emails($b) {
    unset($b);
}
function brb_send_cancel_email($b) {
    $o = brb_opt();
    $ref = brb_ref($b);
    $body = brb_guest_hello_line() . "\n\nBooking {$ref} (" . brb_when($b) . ") has been cancelled. The date is no longer reserved.\n\nCancellation policy:\n{$o['policy']}\n";
    brb_mail($b->email, 'Boardroom booking cancelled (' . $ref . ')', $body, brb_guest_reply_to());
    brb_mail_staff('Booking cancelled ' . $ref, "Booking {$ref} was cancelled.\n\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n", $b->email);
}

function brb_expire_holds() {
    return;
}
function brb_column_ready() {
    global $wpdb;
    $found = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM `' . brb_table() . '` LIKE %s', 'hold_until'));
    return (bool) $found;
}

function brb_recent_same_day_booking($email, DateTimeInterface $start) {
    global $wpdb;
    $email = strtolower(sanitize_email((string) $email));
    if ($email === '' || !is_email($email)) return null;
    $day = $start->format('Y-m-d');
    try {
        $next = (new DateTimeImmutable($day . ' 00:00:00', brb_tz()))->modify('+1 day')->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
    $cutoff = gmdate('Y-m-d H:i:s', time() - 600);
    return $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . brb_table() . " WHERE LOWER(email)=%s AND start_dt >= %s AND start_dt < %s AND created >= %s AND status NOT IN ('cancelled','declined','spam') ORDER BY id DESC LIMIT 1",
        $email,
        $day . ' 00:00:00',
        $next,
        $cutoff
    ));
}
function brb_insert_booking($r, $q, $guest, $source = 'online', $notify = true, $track_submit = false) {
    global $wpdb;
    $source = in_array($source, ['online', 'phone', 'walk-in'], true) ? $source : 'online';
    $locked = $wpdb->get_var("SELECT GET_LOCK('brb_book', 10)");
    if ((string) $locked !== '1') return new WP_Error('lock', 'Please try again in a moment.');
    $result = null;
    $duplicate = false;
    try {
        $existing = brb_recent_same_day_booking($guest['email'] ?? '', $r['s']);
        if ($existing) {
            $result = $existing;
            $duplicate = true;
        } elseif (!brb_is_free($r['s'], $r['e'])) {
            $result = new WP_Error('busy', brb_date_taken_message());
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
                'free_admission' => (($guest['free_admission'] ?? '') === '1') ? '1' : '',
                'waiver_ack' => (($guest['waiver_ack'] ?? '') === '1') ? '1' : '',
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
    if ($duplicate) return $result;
    if ($result && !is_wp_error($result)) {
        brb_ensure_damage_line($result->id);
        if (!empty($q['price_set']) && (float) $q['total'] > 0.009) brb_ensure_rental_line($result->id, $q['total']);
    }
    if ($result && !is_wp_error($result)) {
        brb_report_stamp_booking($result->id);
        if ($track_submit) brb_event_hit('booking_request_submitted');
        if ($notify) {
            brb_send_request_emails($result);
        }
    }
    return $result;
}
function brb_process_public_booking($post) {
    if (!brb_public_writes_open()) return new WP_Error('staging', brb_staging_guest_message());
    if (empty($post['policy'])) return new WP_Error('policy', 'Please agree to the Terms, the Rental Agreement and the Privacy Policy.');
    $r = brb_build_range($post);
    if (is_wp_error($r)) return $r;
    $name = sanitize_text_field($post['name'] ?? '');
    $email = sanitize_email($post['email'] ?? '');
    if (!$name || !is_email($email)) return new WP_Error('contact', 'Name and a valid email are required.');
    $phone = sanitize_text_field($post['phone'] ?? '');
    if ($phone === '' || strlen($phone) > 50) return new WP_Error('phone', 'Please enter a phone number.');
    if ((string) ($post['waiver'] ?? '') !== '1') return new WP_Error('waiver', 'Please confirm the assumption of risk.');
    $screen = brb_collect_screening($post, true, is_wp_error($r) ? null : $r['s']);
    if (is_wp_error($screen)) return $screen;
    $about = $screen['event_about'] . "\n" . (string) ($post['notes'] ?? '');
    $blocked = brb_chat_safety_text_error($about);
    if ($blocked) return $blocked;
    $q = brb_quote($r);
    return brb_insert_booking($r, $q, [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'notes' => $screen['event_about'],
        'event_type' => '',
        'public_event' => $screen['public_event'],
        'free_admission' => $screen['free_admission'],
        'waiver_ack' => ((string) ($post['waiver'] ?? '') === '1') ? '1' : '',
        'alcohol' => $screen['alcohol'],
        'end_time' => $screen['end_time'],
        'host_age' => $screen['host_age'],
        'event_about' => $screen['event_about'],
    ], 'online', true, true);
}
add_action('admin_post_nopriv_brb_book', 'brb_handle_book');
add_action('admin_post_brb_book', 'brb_handle_book');
function brb_handle_book() {
    $back = wp_get_referer() ?: brb_book_url();
    if (!wp_verify_nonce($_POST['_brb'] ?? '', 'brb_book')) wp_die('Session expired, please try again.');
    $fail = function ($m) use ($back) {
        wp_safe_redirect(add_query_arg('br_err', rawurlencode($m), $back));
        exit;
    };
    $result = brb_process_public_booking(wp_unslash($_POST));
    if (is_wp_error($result) || !$result) $fail(is_wp_error($result) ? $result->get_error_message() : 'Could not save the booking. Please try again.');
    wp_safe_redirect(add_query_arg('br_token', $result->token, brb_book_url()));
    exit;
}

function brb_record_payment($booking_id, $amount, $check_number, $received_on, $notes, $require_check = true, $method = 'check', $extra = []) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    $paid_before = in_array($b->status, ['deposit_paid', 'paid', 'confirmed'], true);
    $before_status = brb_status_label($b->status);
    if (!is_array($extra)) $extra = [];
    $notify = !array_key_exists('notify', $extra) || !($extra['notify'] === false || $extra['notify'] === 0 || $extra['notify'] === '0');
    if (in_array($b->status, ['cancelled', 'expired', 'declined', 'spam'], true)) {
        return new WP_Error('status', 'That booking is not open for a payment.');
    }
    if (!brb_price_is_set($b)) {
        return new WP_Error('price', 'Set the agreed price before recording a payment.');
    }
    $amount = round((float) $amount, 2);
    if ($amount <= 0) return new WP_Error('amount', 'Enter an amount greater than zero.');
    brb_ensure_damage_line($b->id);
    $b = brb_booking($b->id);
    $damage_explicit = array_key_exists('damage', $extra) ? $extra['damage'] : null;
    $split = brb_split_payment($b, $amount, $damage_explicit);
    if (is_wp_error($split)) return $split;
    $method = sanitize_key((string) $method);
    if (!brb_pay_method_known($method)) $method = 'check';
    $fee = round((float) ($extra['fee'] ?? 0), 2);
    if ($fee < 0) $fee = 0;
    $stripe_id = sanitize_text_field($extra['stripe_id'] ?? '');
    $stripe_session = sanitize_text_field($extra['stripe_session'] ?? '');
    $check_number = sanitize_text_field($check_number);
    if ($method === 'cash' || $method === 'card') $check_number = '';
    elseif ($require_check && $check_number === '') return new WP_Error('check', 'Enter the check number.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $received_on)) $received_on = brb_now()->format('Y-m-d');
    $notes = sanitize_textarea_field($notes);
    $mail = [];
    if (array_key_exists('subject', $extra)) $mail['subject'] = $extra['subject'];
    if (array_key_exists('body', $extra)) $mail['body'] = $extra['body'];
    if ($notify && $mail) {
        foreach (['subject' => 'brb_sanitize_guest_subject', 'body' => 'brb_sanitize_guest_body'] as $key => $fn) {
            if (!array_key_exists($key, $mail)) continue;
            $checked = $fn($mail[$key]);
            if (is_wp_error($checked)) return $checked;
        }
    }
    $locked = $wpdb->get_var("SELECT GET_LOCK('brb_book', 10)");
    if ((string) $locked !== '1') return new WP_Error('lock', 'Please try again in a moment.');
    $b = brb_booking($b->id);
    $taken = brb_date_taken($b);
    if ($taken) {
        $wpdb->query("SELECT RELEASE_LOCK('brb_book')");
        return new WP_Error('busy', brb_date_taken_message() . ' This payment was not recorded.');
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
    $pay_id = (int) $wpdb->insert_id;
    $paid = round((float) $b->amount_paid + $split['rental'], 2);
    $damage_paid = round((float) ($b->damage_paid ?? 0) + $split['damage'], 2);
    $fields = ['amount_paid' => brb_dec($paid), 'damage_paid' => brb_dec($damage_paid)];
    $fields['status'] = ($paid + 0.009 >= (float) $b->total) ? 'paid' : 'deposit_paid';
    $wpdb->update(brb_table(), $fields, ['id' => (int) $b->id]);
    brb_lock_lines($b->id);
    $wpdb->query("SELECT RELEASE_LOCK('brb_book')");
    $b = brb_booking($b->id);
    if ($split['damage'] > 0.009) {
        $notes = trim($notes . ' Refundable damage deposit ' . brb_money($split['damage']) . '.');
        $wpdb->update(brb_pay_table(), ['notes' => $notes], ['id' => $pay_id]);
    }
    $mail = [];
    if (array_key_exists('subject', $extra)) $mail['subject'] = $extra['subject'];
    if (array_key_exists('body', $extra)) $mail['body'] = $extra['body'];
    if ($notify) {
        $sent = brb_send_payment_email($b, $amount, $check_number, $method, null, $mail);
        if (is_wp_error($sent)) {
            brb_audit_append((int) $b->id, 'recorded ' . brb_pay_method_label($method) . ' payment ' . brb_money($amount) . '; status from "' . $before_status . '" to "' . brb_status_label($b->status) . '"; guest email was not sent');
        } else {
            brb_audit_append((int) $b->id, 'recorded ' . brb_pay_method_label($method) . ' payment ' . brb_money($amount) . '; status from "' . $before_status . '" to "' . brb_status_label($b->status) . '"; guest email sent; subject: "' . $sent['subject'] . '"; body: "' . $sent['body'] . '"');
        }
    } else {
        brb_audit_append((int) $b->id, 'recorded ' . brb_pay_method_label($method) . ' payment ' . brb_money($amount) . '; status from "' . $before_status . '" to "' . brb_status_label($b->status) . '"; guest email skipped');
    }
    if (!$paid_before && in_array($b->status, ['deposit_paid', 'paid', 'confirmed'], true)) brb_event_hit('deposit_paid');
    return brb_booking($b->id);
}
function brb_mark_paid_full($booking_id) {
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (in_array($b->status, ['cancelled', 'expired', 'declined', 'spam'], true)) return new WP_Error('status', 'Cancelled or declined bookings cannot be marked paid.');
    if (!brb_price_is_set($b)) return new WP_Error('price', 'Set the agreed price before marking the booking paid.');
    $remaining = round(brb_rental_left($b) + brb_damage_left($b), 2);
    if (brb_rental_left($b) <= 0.009) {
        global $wpdb;
        $before_status = brb_status_label($b->status);
        $wpdb->update(brb_table(), ['status' => 'paid'], ['id' => (int) $b->id]);
        $b = brb_booking($b->id);
        brb_send_payment_email($b, 0, '');
        brb_audit_append((int) $b->id, 'marked paid in full; status from "' . $before_status . '" to "' . brb_status_label($b->status) . '"; guest email sent');
        return $b;
    }
    return brb_record_payment($b->id, $remaining, '', brb_now()->format('Y-m-d'), 'Marked paid in full by staff.', false);
}
function brb_set_agreed_price($booking_id, $amount, $notify = true) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (in_array($b->status, ['cancelled', 'declined', 'spam'], true)) return new WP_Error('status', 'That booking cannot take a price.');
    $before = brb_price_is_set($b) ? brb_money($b->total) : 'Price to be confirmed';
    $amount = round((float) $amount, 2);
    if ($amount <= 0) return new WP_Error('amount', 'Enter an agreed price greater than zero.');
    brb_ensure_damage_line($b->id);
    if (!empty($b->setup_addon)) brb_ensure_addon_line($b->id, 'setup');
    if (!empty($b->takedown_addon)) brb_ensure_addon_line($b->id, 'takedown');
    $rental = brb_ensure_rental_line($b->id, $amount);
    if (is_wp_error($rental)) return $rental;
    $wpdb->update(brb_table(), ['price_set' => 1, 'cleaning' => '0.00', 'tax' => '0.00'], ['id' => (int) $b->id]);
    $b = brb_recalc_booking($b->id);
    if (!$b) return new WP_Error('db', 'Could not save the agreed price.');
    if ((float) $b->amount_paid - (float) $b->total > 0.009) {
        return new WP_Error('amount', 'The agreed price cannot be less than the ' . brb_money($b->amount_paid) . ' already recorded.');
    }
    if ($notify) brb_send_price_email($b);
    brb_audit_append((int) $b->id, 'saved rental price from "' . $before . '" to "' . brb_money($b->total) . '"; guest email ' . ($notify ? 'sent' : 'skipped'));
    return brb_booking($b->id);
}
function brb_price_guest_body($b) {
    $ref = brb_ref($b);
    $body = brb_guest_hello_line() . "\n\nThe price for your Boardroom date is confirmed.\n\n" . brb_guest_money_text($b) . "\n\n" . brb_guest_review_line() . "\n\n";
    if (in_array($b->status, ['approved', 'held', 'deposit_paid'], true)) {
        $body .= implode("\n\n", brb_payment_lines($b)) . "\n\n";
    } else {
        $body .= "Payment instructions are sent when the request is approved.\n\n" . brb_deposit_line() . "\n\n";
    }
    $body .= 'When: ' . brb_when($b) . "\n" . brb_event_type_line($b) . "Estimated Guests: {$b->guests}\nReference: {$ref}\n\nView your booking: " . brb_guest_link($b) . "\n";
    return $body;
}
function brb_send_price_email($b, $subject = '', $body = '') {
    $ref = brb_ref($b);
    $mail = brb_custom_guest_mail(['subject' => $subject, 'body' => $body], brb_price_guest_subject($b), brb_price_guest_body($b));
    if (is_wp_error($mail)) return $mail;
    list($subject, $body) = $mail;
    brb_mail($b->email, $subject, $body, brb_guest_reply_to());
    brb_mail_staff('Price set for ' . $ref . ' – ' . $b->name, $body . "\n" . admin_url('admin.php?page=brb&booking=' . (int) $b->id) . "\n", $b->email);
    return ['subject' => $subject, 'body' => $body];
}
function brb_extend_hold($booking_id, $hours) {
    unset($booking_id, $hours);
    return new WP_Error('status', 'A request does not hold the date. The date is reserved once the deposit is received.');
}
function brb_save_booking_edit($booking_id, $input) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    $date = sanitize_text_field((string) ($input['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return new WP_Error('date', 'Choose a date.');
    $name = sanitize_text_field((string) ($input['name'] ?? ''));
    $email = sanitize_email((string) ($input['email'] ?? ''));
    $phone = sanitize_text_field((string) ($input['phone'] ?? ''));
    $notes = sanitize_textarea_field((string) ($input['notes'] ?? ''));
    if ($name === '' || !is_email($email)) return new WP_Error('contact', 'Name and a valid email are required.');
    try {
        $day = new DateTimeImmutable($date . ' 00:00:00', brb_tz());
    } catch (Exception $e) {
        return new WP_Error('date', 'Choose a date.');
    }
    $start = $day->setTime(10, 0);
    $end = $day->setTime(23, 30);
    $old_day = substr((string) $b->start_dt, 0, 10);
    if ($date !== $old_day && !brb_is_free($start, $end, (int) $b->id)) {
        return new WP_Error('busy', brb_date_taken_message());
    }
    $changes = [];
    if ($date !== $old_day) $changes[] = 'date from "' . $old_day . '" to "' . $date . '"';
    if ($name !== (string) $b->name) $changes[] = 'name from "' . (string) $b->name . '" to "' . $name . '"';
    if (strtolower($email) !== strtolower((string) $b->email)) $changes[] = 'email from "' . (string) $b->email . '" to "' . $email . '"';
    if ($phone !== (string) $b->phone) $changes[] = 'phone from "' . (string) $b->phone . '" to "' . $phone . '"';
    if ($notes !== (string) $b->notes) $changes[] = 'notes from "' . brb_audit_clean($b->notes) . '" to "' . brb_audit_clean($notes) . '"';
    if (!$changes) return $b;
    $fields = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'notes' => $notes,
    ];
    if ($date !== $old_day) {
        $fields['start_dt'] = $start->format('Y-m-d H:i:s');
        $fields['end_dt'] = $end->format('Y-m-d H:i:s');
        $fields['mode'] = 'day';
        $fields['balance_due_on'] = brb_balance_due_on($start);
    }
    $wpdb->update(brb_table(), $fields, ['id' => (int) $b->id]);
    brb_audit_append((int) $b->id, 'saved ' . implode('; ', $changes) . '; no email sent');
    return brb_booking($b->id);
}
function brb_save_booking_changes($booking_id, $input) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    $saved = brb_save_booking_edit($booking_id, $input);
    if (is_wp_error($saved)) return $saved;
    $b = brb_booking($booking_id);
    $walk = empty($input['walkthrough_done']) ? 0 : 1;
    $flag_changes = [];
    if ((int) $walk !== (int) ($b->walkthrough_done ?? 0)) {
        $flag_changes[] = 'walk-through from "' . (!empty($b->walkthrough_done) ? 'Completed' : 'Not yet') . '" to "' . ($walk ? 'Completed' : 'Not yet') . '"';
        $wpdb->update(brb_table(), ['walkthrough_done' => $walk], ['id' => (int) $b->id]);
    }
    foreach (['setup' => 'setup_addon', 'takedown' => 'takedown_addon'] as $which => $col) {
        if (empty($input[$col]) || !empty($b->$col)) continue;
        $before = 'No';
        $applied = brb_apply_addon($b->id, $which);
        if (is_wp_error($applied)) return $applied;
        $label = $which === 'setup' ? 'Setup day' : 'Takedown day';
        $flag_changes[] = $label . ' from "' . $before . '" to "Added"';
        $b = brb_booking($b->id);
    }
    if ($flag_changes) brb_audit_append((int) $b->id, 'saved ' . implode('; ', $flag_changes) . '; no email sent');
    if ((string) ($b->status ?? '') !== 'cancelled' && array_key_exists('agreed_price', $input) && trim((string) $input['agreed_price']) !== '') {
        $amount = round((float) $input['agreed_price'], 2);
        $line = brb_find_line_kind($b->id, 'rental');
        $current = $line ? round((float) $line->amount, 2) : ($b && brb_price_is_set($b) ? round((float) $b->total, 2) : 0);
        if ($amount > 0 && (abs($amount - $current) > 0.009 || !brb_price_is_set($b))) {
            $priced = brb_set_agreed_price($b->id, $amount, false);
            if (is_wp_error($priced)) return $priced;
        }
    }
    return brb_booking($booking_id);
}
function brb_approve_booking($booking_id, $notify = true, $mail = []) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (!in_array($b->status, ['review', 'pending'], true)) return new WP_Error('status', 'Only a request that is pending review can be approved.');
    $before_status = brb_status_label($b->status);
    if (empty($b->walkthrough_done)) return new WP_Error('walkthrough', 'Mark the in-person walk-through complete before approving. Approval follows the event details, availability, and house rules.');
    $checked_mail = null;
    if ($notify) {
        $mail = is_array($mail) ? $mail : [];
        $preview = clone $b;
        if (in_array((string) $preview->status, ['review', 'pending'], true)) $preview->status = 'approved';
        $checked_mail = brb_custom_guest_mail($mail, brb_approve_guest_subject($preview), brb_approve_guest_body($preview));
        if (is_wp_error($checked_mail)) return $checked_mail;
    }
    $start = new DateTimeImmutable($b->start_dt, brb_tz());
    $end = new DateTimeImmutable($b->end_dt, brb_tz());
    $locked = $wpdb->get_var("SELECT GET_LOCK('brb_book', 10)");
    if ((string) $locked !== '1') return new WP_Error('lock', 'Please try again in a moment.');
    if (!brb_is_free($start, $end, (int) $b->id)) {
        $wpdb->query("SELECT RELEASE_LOCK('brb_book')");
        return new WP_Error('busy', brb_date_taken_message() . ' This request was not approved.');
    }
    $wpdb->update(brb_table(), ['status' => 'approved', 'hold_until' => null], ['id' => (int) $b->id]);
    $wpdb->query("SELECT RELEASE_LOCK('brb_book')");
    $b = brb_booking($b->id);
    if ($notify) {
        list($subject, $body) = $checked_mail;
        brb_send_approve_email($b, $subject, $body);
        brb_audit_append((int) $b->id, 'status from "' . $before_status . '" to "' . brb_status_label('approved') . '"; guest email sent; subject: "' . $subject . '"; body: "' . $body . '"');
    } else {
        brb_audit_append((int) $b->id, 'status from "' . $before_status . '" to "' . brb_status_label('approved') . '"; guest email skipped');
    }
    return brb_booking($b->id);
}
function brb_decline_booking($booking_id, $args = []) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (!in_array($b->status, ['review', 'approved', 'pending', 'held', 'deposit_paid'], true)) return new WP_Error('status', 'Only a request that is pending review, approved, or deposit paid can be declined.');
    if (!is_array($args)) $args = [];
    $send = array_key_exists('send', $args) ? (bool) $args['send'] : true;
    $message = trim(sanitize_textarea_field((string) ($args['message'] ?? '')));
    if (strlen($message) > 2000) $message = substr($message, 0, 2000);
    $dates = $args['dates'] ?? [];
    if (!is_array($dates)) $dates = [];
    $checked = brb_collect_decline_dates(['alt_dates' => $dates], $b);
    if (is_wp_error($checked)) return $checked;
    $checked_mail = null;
    if ($send) {
        $checked_mail = brb_custom_guest_mail($args, brb_decline_guest_subject($b), brb_decline_guest_body($b, $message, $checked));
        if (is_wp_error($checked_mail)) return $checked_mail;
    }
    $before_status = brb_status_label($b->status);
    $fields = ['status' => 'declined', 'hold_until' => null];
    if ((float) $b->amount_paid > 0.009 || (float) ($b->damage_paid ?? 0) > 0.009) {
        $fields['attention'] = 'A payment was recorded before this request was declined. Resolve it manually.';
    }
    $wpdb->update(brb_table(), $fields, ['id' => (int) $b->id]);
    $b = brb_booking($b->id);
    $note = 'status from "' . $before_status . '" to "' . brb_status_label('declined') . '"';
    $note .= '; message: ' . ($message === '' ? '(none)' : '"' . brb_audit_clean($message) . '"');
    $note .= '; suggested dates: ' . ($checked ? implode(', ', $checked) : '(none)');
    if ($send) {
        list($subject, $body) = $checked_mail;
        brb_send_decline_email($b, $message, $checked, $subject, $body);
        $note .= '; guest email sent; subject: "' . $subject . '"; body: "' . $body . '"';
    } else {
        $note .= '; guest email skipped';
    }
    brb_audit_append((int) $b->id, $note);
    return brb_booking($b->id);
}
function brb_flag_spam_booking($booking_id) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if ((string) $b->status === 'spam') return $b;
    if (brb_booking_has_recorded_payment($b)) {
        brb_audit_append((int) $b->id, 'spam flag blocked because a payment is recorded. Cancel the booking instead.');
        return new WP_Error('paid', 'This booking has a recorded payment. Cancel it instead of flagging it.');
    }
    $before_status = brb_status_label($b->status);
    $wpdb->update(brb_table(), ['status' => 'spam', 'hold_until' => null], ['id' => (int) $b->id]);
    brb_audit_append((int) $b->id, 'flagged as spam/joke; status from "' . $before_status . '" to "' . brb_status_label('spam') . '"; no email sent');
    return brb_booking($b->id);
}
function brb_cancel_booking($booking_id) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if ($b->status === 'cancelled') return $b;
    $before_status = brb_status_label($b->status);
    $wpdb->update(brb_table(), ['status' => 'cancelled'], ['id' => (int) $b->id]);
    $b->status = 'cancelled';
    brb_send_cancel_email($b);
    brb_audit_append((int) $b->id, 'status from "' . $before_status . '" to "' . brb_status_label('cancelled') . '"; guest email sent');
    return brb_booking($b->id);
}
function brb_purge_booking($booking_id) {
    global $wpdb;
    $id = (int) $booking_id;
    if ($id <= 0 || !brb_booking($id)) return false;
    $wpdb->delete(brb_pay_table(), ['booking_id' => $id], ['%d']);
    $wpdb->delete(brb_line_table(), ['booking_id' => $id], ['%d']);
    $ok = $wpdb->delete(brb_table(), ['id' => $id], ['%d']);
    return $ok !== false && (int) $ok > 0;
}
function brb_delete_booking($booking_id, $confirm = null) {
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (brb_booking_has_recorded_payment($b)) {
        brb_audit_append((int) $b->id, 'delete blocked because a payment is recorded. Cancel the booking instead of deleting it.');
        return new WP_Error('paid', 'This booking has a recorded payment. Cancel it instead of deleting it.');
    }
    if ($confirm !== null && trim((string) $confirm) !== brb_ref($b)) {
        brb_audit_append((int) $b->id, 'delete blocked because the booking number did not match. Typed "' . brb_audit_clean($confirm) . '".');
        return new WP_Error('confirm', 'Type ' . brb_ref($b) . ' exactly to delete this booking.');
    }
    $ref = brb_ref($b);
    brb_audit_append((int) $b->id, 'deleted ' . $ref . ' (' . brb_audit_clean($b->name) . ', ' . brb_audit_clean($b->email) . ').');
    $fresh = brb_booking($b->id);
    $history = trim((string) ($fresh->audit_log ?? ''));
    brb_delete_log_append('deleted ' . $ref . ' (' . brb_audit_clean($b->name) . ', ' . brb_audit_clean($b->email) . '). History: ' . str_replace("\n", ' | ', $history));
    if (!brb_purge_booking($b->id)) return new WP_Error('db', 'Could not delete that booking.');
    return true;
}
function brb_bulk_delete_bookings($ids) {
    $seen = [];
    $n = 0;
    foreach ((array) $ids as $id) {
        $id = (int) $id;
        if ($id <= 0 || isset($seen[$id])) continue;
        $seen[$id] = true;
        if (brb_delete_booking($id) === true) $n++;
    }
    return $n;
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
    $hold_rows = $wpdb->get_results($wpdb->prepare(
        'SELECT start_dt, end_dt, hold_type FROM ' . brb_hold_table() . ' WHERE start_dt < %s AND end_dt > %s',
        $next->format('Y-m-d H:i:s'),
        $first->format('Y-m-d H:i:s')
    ));
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
        $blocked_hold = false;
        $booked_span = false;
        $mark = function ($r) use ($d, $tz, $open, $close, &$busy, &$full, &$booked_span, &$blocked_hold) {
            $s = new DateTimeImmutable($r->start_dt, $tz);
            $e = new DateTimeImmutable($r->end_dt, $tz);
            $dayEnd = $d->modify('+1 day');
            if (!($s < $dayEnd && $e > $d)) return;
            if (($r->hold_type ?? '') === 'blocked') {
                $blocked_hold = true;
                return;
            }
            $booked_span = true;
            if ($s <= $open && $e >= $close) $full = true;
            $busy[] = [
                max(0, (int) floor(($s->getTimestamp() - $d->getTimestamp()) / 3600)),
                min(24, (int) ceil(($e->getTimestamp() - $d->getTimestamp()) / 3600)),
            ];
        };
        foreach ($rows as $r) $mark($r);
        foreach ((array) $hold_rows as $r) $mark($r);
        $slot = brb_slot_info($d);
        if ($d < $today) $st = 'past';
        elseif (in_array($k, $bo, true) || $blocked_hold) $st = 'blocked';
        elseif ($full || $booked_span) $st = 'booked';
        elseif (!$slot['any']) $st = 'soon';
        else $st = 'free';
        $full_price = brb_rate_for_date($k, 'full');
        $days[$k] = [
            'status' => $st,
            'busy' => $busy,
            'day_ok' => (bool) $slot['day_ok'],
            'first_hour' => $slot['first_hour'],
            'price' => $full_price === null ? '' : brb_whole_money($full_price),
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
function brb_enqueue_public_calendar() {
    wp_enqueue_style('brb', BRB_URL . 'assets/brb.css', [], BRB_VER);
    wp_enqueue_script('brb', BRB_URL . 'assets/brb.js', [], BRB_VER, true);
    $o = brb_opt();
    wp_localize_script('brb', 'BRB', [
        'api' => esc_url_raw(rest_url('brb/v1/availability')),
        'eventEndpoint' => esc_url_raw(rest_url('brb/v1/event')),
        'nonce' => wp_create_nonce('wp_rest'),
        'open' => (int) $o['open_hour'],
        'close' => (int) $o['close_hour'],
        'minHours' => (int) $o['min_hours'],
        'noticeHours' => brb_notice_hours(),
        'mode' => $o['mode'],
        'cutoff' => brb_notice_cutoff()->format('c'),
    ]);
}
add_action('wp_enqueue_scripts', function () {
    if (function_exists('is_front_page') && is_front_page()) brb_enqueue_public_calendar();
    if (function_exists('is_singular') && is_singular('post')) brb_enqueue_public_calendar();
}, 20);
function brb_public_date_is_open($ymd) {
    $ymd = (string) $ymd;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
    $r = brb_build_range(['date' => $ymd, 'type' => 'day', 'days' => 1, 'guests' => 1]);
    if (is_wp_error($r)) return false;
    return brb_is_free($r['s'], $r['e']);
}
function brb_calendar_markup($link_to_book = false) {
    $o = brb_opt();
    $notice = brb_notice_hours();
    $extra = '';
    if ($link_to_book) {
        $extra = ' data-book="' . esc_url(brb_book_url()) . '" data-lazy="1"';
    }
    $html = '<div id="brb-cal" class="brb-cal" aria-live="polite" data-api="' . esc_url(rest_url('brb/v1/availability')) . '" data-nonce="' . esc_attr(wp_create_nonce('wp_rest')) . '" data-notice="' . (int) $notice . '" data-mode="' . esc_attr($o['mode']) . '"' . $extra . '>Loading calendar…</div>';
    $html .= '<p class="brb-legend"><span class="free"></span>Available <span class="booked"></span>Booked <span class="blocked"></span>Blocked <span class="soon"></span>Inside ' . (int) $notice . 'h notice <span class="past"></span>Past</p>';
    return $html;
}
function brb_home_calendar_html() {
    brb_enqueue_public_calendar();
    return '<div class="brb brb-home-cal">' . brb_calendar_markup(true) . '</div>';
}
add_shortcode('boardroom_booking', function () {
    brb_enqueue_public_calendar();
    ob_start();
    echo '<div class="brb' . (!empty($_GET['br_token']) ? ' brb-status' : '') . '">';
    if (empty($_GET['br_token'])) echo brb_how_booking_works_html();
    if (!empty($_GET['br_err'])) echo '<div class="brb-alert brb-err">' . esc_html(wp_unslash($_GET['br_err'])) . '</div>';
    if (!empty($_GET['br_addon'])) brb_view_addon(sanitize_text_field(wp_unslash($_GET['br_addon'])));
    elseif (!empty($_GET['br_pay'])) brb_view_pay(sanitize_text_field(wp_unslash($_GET['br_pay'])), sanitize_text_field(wp_unslash($_GET['br_sig'] ?? '')));
    elseif (!empty($_GET['br_token'])) brb_view_confirmation(sanitize_text_field(wp_unslash($_GET['br_token'])));
    elseif (!empty($_GET['date']) && brb_public_date_is_open(sanitize_text_field(wp_unslash($_GET['date'])))) brb_view_quote();
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
function brb_view_addon($token) {
    $b = brb_booking_by_token($token);
    if (!$b) {
        echo '<p>That link is not available.</p>';
        return;
    }
    echo '<h2>Setup</h2>';
    echo '<p>' . esc_html(brb_guest_hello_line()) . '</p>';
    echo '<p>Each booking is one full day. Setup starts at 8:00 AM that morning. Setup and takedown day add-ons are no longer offered.</p>';
}
add_action('admin_post_nopriv_brb_addon', 'brb_handle_addon');
add_action('admin_post_brb_addon', 'brb_handle_addon');
function brb_handle_addon() {
    wp_die('Each booking is one full day. Setup starts at 8:00 AM that morning. Setup and takedown day add-ons are no longer offered.');
}
function brb_view_select() {
    brb_steps(0);
    if (!brb_public_writes_open()) echo brb_staging_guest_notice_html();
    $notice = brb_notice_hours();
    ?>
    <div class="brb-grid">
      <div class="brb-card brb-cal-card">
        <?php echo brb_calendar_markup(false); ?>
      </div>
      <form class="brb-card" method="get" id="brb-form">
        <p class="brb-small">Please choose a date at least <?php echo (int) $notice; ?> hours from now (America/Chicago). Dates inside that window are unavailable.</p>
        <label>Date<input type="date" name="date" id="brb-date" required min="<?php echo esc_attr(brb_now()->format('Y-m-d')); ?>"></label>
        <input type="hidden" name="type" value="day">
        <p class="brb-small" id="brb-notice"></p>
        <label>Estimated Guests<input type="number" name="guests" id="brb-guests" value="10" min="1" max="200"></label>
        <p class="brb-small" id="brb-guest-note" hidden>Over 80 needs our written approval. Open houses and graduation parties can go over.</p>
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
    <div class="brb-grid" id="brb-your-date">
      <div class="brb-card">
        <h3>Your date</h3>
        <p><strong><?php echo esc_html($when); ?></strong><br>Estimated Guests: <?php echo (int) $r['guests']; ?></p>
        <?php if (!empty($q['price_set'])): ?>
        <p><strong>Rental Price: <?php echo esc_html(brb_whole_money($q['total'])); ?> (+deposit)</strong></p>
        <?php else: ?>
        <p><strong>price to be confirmed</strong></p>
        <?php endif; ?>
        <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'] ?? brb_book_url(), '?')); ?>">← Change date</a>
      </div>
      <?php if (!brb_public_writes_open()): ?>
      <div class="brb-card brb-guest"><?php echo brb_staging_guest_notice_html(); ?></div>
      <?php else: ?>
      <form class="brb-card brb-guest" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <h3>Guest details</h3>
        <input type="hidden" name="action" value="brb_book"><?php wp_nonce_field('brb_book', '_brb');
        foreach (['date', 'type', 'guests'] as $k) {
            if (isset($in[$k])) printf('<input type="hidden" name="%s" value="%s">', esc_attr($k), esc_attr($in[$k]));
        } ?>
        <div class="brb-field"><label>Full name<span class="brb-req">*</span><input name="name" required autocomplete="name"></label></div>
        <div class="brb-field"><label>Email<span class="brb-req">*</span><input type="email" name="email" required autocomplete="email"></label></div>
        <div class="brb-field"><label>Phone<span class="brb-req">*</span><input type="tel" name="phone" required autocomplete="tel" maxlength="50"></label></div>
        <div class="brb-field"><label class="brb-check"><input type="checkbox" name="free_admission" value="1" required><span class="brb-check-copy"><strong>Free to attend</strong><span class="brb-callout"><?php echo esc_html(brb_free_admission_rule()); ?></span></span></label></div>
        <div class="brb-field"><label>Tell us about your event<span class="brb-req">*</span><textarea name="event_about" rows="4" required></textarea></label></div>
        <p class="brb-callout"><?php echo esc_html(brb_approval_line()); ?> <?php echo esc_html(brb_request_open_line()); ?></p>
        <div class="brb-field"><label class="brb-check"><input type="checkbox" name="waiver" value="1" required><span class="brb-check-copy"><strong>Assumption of risk</strong><span class="brb-callout">I and my guests assume all risk. The Boardroom is not responsible for injury, loss, or equipment issues, such as the ice maker or wifi, that affect an event.</span></span></label></div>
        <div class="brb-field"><label class="brb-check"><input type="checkbox" name="policy" value="1" required><span class="brb-check-copy"><strong>I agree</strong><span class="brb-callout">I agree to the <a href="<?php echo esc_url(home_url('/terms/')); ?>" target="_blank" rel="noopener">Terms</a>, the <a href="<?php echo esc_url(home_url('/rental-agreement/')); ?>" target="_blank" rel="noopener">Rental Agreement</a> and the <a href="<?php echo esc_url(home_url('/privacy/')); ?>" target="_blank" rel="noopener">Privacy Policy</a>.</span></span></label></div>
        <button class="brb-btn" type="submit">Submit request</button>
      </form>
      <?php endif; ?>
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
    $first = trim((string) preg_replace('/\s+/', ' ', (string) $b->name));
    $first = $first === '' ? 'there' : strtok($first, ' ');
    $next = 'Nothing is charged yet. We review your request. Once it\'s approved, we\'ll email you a payment link.';
    $headline = 'Thank you, ' . $first . '! Your request is in.';
    if (in_array($b->status, ['approved', 'held'], true)) $next = 'Your request is approved. We\'ll email you a payment link so you can hold the date.';
    elseif ($b->status === 'deposit_paid') $next = 'Your deposit is recorded. The recap below shows what is still due.';
    elseif ($b->status === 'paid') $next = 'Your booking is confirmed. We\'ll see you at the Boardroom.';
    elseif ($b->status === 'declined') { $headline = $first . ', this request was not approved.'; $next = 'The date is not reserved.'; }
    elseif (in_array($b->status, ['cancelled', 'expired'], true)) { $headline = $first . ', this request is closed.'; $next = 'The date is not reserved.'; }
    ?>
    <div class="brb-thanks"><h2><?php echo esc_html($headline); ?></h2><p><?php echo esc_html($next); ?></p></div>
    <div class="brb-card brb-confirm">
      <h2><?php echo esc_html($title); ?></h2>
      <p>Reference <strong><?php echo esc_html(brb_ref($b)); ?></strong></p>
      <p><?php
        $summary = brb_when($b) . ' · Estimated Guests: ' . (int) $b->guests;
        $event_label = brb_event_type_label($b->event_type ?? '');
        if ($event_label !== '') $summary .= ' · ' . $event_label;
        echo esc_html($summary);
      ?></p>
      <?php if ($priced): ?>
      <?php echo brb_guest_money_html($b, ['paid' => true]); ?>
      <?php
        $card_fee = 0.0;
        foreach ($recorded as $pay) {
            if (($pay->method ?? '') === 'card') $card_fee += (float) ($pay->fee ?? 0);
        }
        if ($card_fee > 0.009) {
            echo '<p>The card processing fee of ' . esc_html(brb_money($card_fee)) . ' was charged separately and is not part of this balance.</p>';
        }
      ?>
      <?php else: ?>
      <p>price to be confirmed</p>
      <?php endif; ?>
      <?php
      if (!empty($_GET['card']) && $_GET['card'] === 'pending' && $b->status !== 'paid') {
          echo '<div class="brb-alert">If you just paid by card, the receipt is sent when the payment is confirmed. Your balance updates after that confirmation.</div>';
      }
      if (in_array($b->status, ['review', 'pending'], true)) {
          echo '<div class="brb-paybox">';
          echo '<p>' . esc_html(brb_guest_hello_line()) . '</p>';
          echo '<p>Nothing is charged yet. We review your request. Once it\'s approved, we\'ll email you a payment link.</p>';
          echo '<p>' . esc_html(brb_guest_review_line()) . '</p>';
          echo '<p>Pending review</p>';
          echo '</div>';
          echo '<p>A copy of this request was sent to ' . esc_html($b->email) . '.</p>';
      } elseif (in_array($b->status, ['approved', 'held'], true)) {
          brb_echo_lines(brb_payment_lines($b));
          echo '<p>Payment instructions were sent to ' . esc_html($b->email) . '.</p>';
      } elseif ($b->status === 'deposit_paid') {
          $due = brb_balance_due_display($b);
          if ($latest_method === 'cash') $received = 'We have recorded a cash payment toward this booking. Your date stays reserved.';
          elseif ($latest_method === 'card') $received = 'We have recorded a card payment toward this booking. Your date stays reserved.';
          else $received = 'We have recorded a check toward this booking. Your date stays reserved.';
          $due_bit = '';
          if ($due === 'Due now') $due_bit = ' is due now';
          elseif ($due && $due !== 'Price to be confirmed' && $due !== 'Paid') $due_bit = ' is due by ' . $due;
          $rental_left = max(0, $paid_off);
          $damage_open = brb_damage_left($b);
          $still = round($rental_left + $damage_open, 2);
          echo '<div class="brb-paybox"><p>' . esc_html($received) . '</p>';
          if ($damage_open > 0.009 && $rental_left > 0.009) {
              $now_line = 'The amount due now is ' . brb_money($still) . ', which is the remaining rental balance of ' . brb_money($rental_left) . ' plus the unpaid refundable damage deposit of ' . brb_money($damage_open) . '.';
              if ($due === 'Due now') $now_line .= ' It is due now.';
              elseif ($due && $due !== 'Price to be confirmed' && $due !== 'Paid') $now_line .= ' It is due by ' . $due . '.';
              echo '<p>' . esc_html($now_line) . '</p>';
          } else {
              echo '<p>Balance of ' . esc_html(brb_money($still)) . esc_html($due_bit) . '.</p>';
          }
          echo '<p>' . esc_html(brb_check_sentence('the remaining balance as a check')) . ' ' . esc_html(brb_appointment_line()) . ' You can also pay the rest online when card payment is offered. Write booking reference ' . esc_html(brb_ref($b)) . ' on the memo line.</p></div>';
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
