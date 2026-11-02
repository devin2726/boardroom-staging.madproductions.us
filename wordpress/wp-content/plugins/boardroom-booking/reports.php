<?php
if (!defined('ABSPATH')) exit;

function brb_views_table() { global $wpdb; return $wpdb->prefix . 'br_views'; }
function brb_events_table() { global $wpdb; return $wpdb->prefix . 'br_events'; }
function brb_chatlog_table() { global $wpdb; return $wpdb->prefix . 'br_chat_log'; }

function brb_lead_statuses() {
    return [
        'new' => 'New',
        'quoted' => 'Quoted',
        'approved' => 'Approved',
        'paid' => 'Paid',
        'lost' => 'Lost',
    ];
}
function brb_report_events() {
    return [
        'booking_step_date',
        'booking_step_review',
        'booking_step_submit',
        'booking_request_submitted',
        'chat_open',
        'chat_lead',
        'tour_request',
        'deposit_paid',
    ];
}

function brb_install_report_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $views = brb_views_table();
    dbDelta("CREATE TABLE $views (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      view_day date NOT NULL,
      path varchar(120) NOT NULL DEFAULT '',
      referrer_host varchar(120) NOT NULL DEFAULT '',
      views int(11) unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY  (id),
      UNIQUE KEY day_path (view_day, path, referrer_host)
    ) $c;");
    $events = brb_events_table();
    dbDelta("CREATE TABLE $events (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      event_day date NOT NULL,
      event_name varchar(40) NOT NULL DEFAULT '',
      hits int(11) unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY  (id),
      UNIQUE KEY day_event (event_day, event_name)
    ) $c;");
    $log = brb_chatlog_table();
    dbDelta("CREATE TABLE $log (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      question varchar(500) NOT NULL DEFAULT '',
      intent varchar(40) NOT NULL DEFAULT '',
      unanswered tinyint(1) NOT NULL DEFAULT 0,
      created datetime NOT NULL,
      PRIMARY KEY  (id),
      KEY created (created)
    ) $c;");
    $inquiry = brb_tour_table();
    foreach ([
        'lead_status' => "varchar(20) NOT NULL DEFAULT 'new'",
        'lost_reason' => "varchar(190) NOT NULL DEFAULT ''",
        'event_type' => "varchar(40) NOT NULL DEFAULT ''",
        'utm_source' => "varchar(80) NOT NULL DEFAULT ''",
        'utm_medium' => "varchar(80) NOT NULL DEFAULT ''",
        'utm_campaign' => "varchar(80) NOT NULL DEFAULT ''",
        'referrer_host' => "varchar(190) NOT NULL DEFAULT ''",
        'landing_path' => "varchar(190) NOT NULL DEFAULT ''",
    ] as $col => $def) {
        brb_ensure_column($inquiry, $col, $def);
    }
    foreach ([
        'utm_source' => "varchar(80) NOT NULL DEFAULT ''",
        'referrer_host' => "varchar(190) NOT NULL DEFAULT ''",
        'landing_path' => "varchar(190) NOT NULL DEFAULT ''",
    ] as $col => $def) {
        brb_ensure_column(brb_table(), $col, $def);
    }
    return true;
}

function brb_report_rate($num, $den) {
    $num = (float) $num;
    $den = (float) $den;
    if ($den <= 0) return null;
    return round(100 * $num / $den, 1);
}

function brb_report_range($get = null) {
    if (!is_array($get)) $get = [];
    $today = brb_now()->setTime(0, 0);
    $preset = sanitize_key((string) ($get['range'] ?? '30'));
    if (!in_array($preset, ['month', '30', '90', 'ytd', 'custom'], true)) $preset = '30';
    $end = $today;
    if ($preset === 'month') {
        $start = $today->modify('first day of this month');
    } elseif ($preset === '90') {
        $start = $today->modify('-89 days');
    } elseif ($preset === 'ytd') {
        $start = $today->setDate((int) $today->format('Y'), 1, 1);
    } elseif ($preset === 'custom') {
        $from = (string) ($get['from'] ?? '');
        $to = (string) ($get['to'] ?? '');
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? new DateTimeImmutable($from . ' 00:00:00', brb_tz()) : $today->modify('-29 days');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $end = new DateTimeImmutable($to . ' 00:00:00', brb_tz());
        if ($end < $start) $end = $start;
    } else {
        $preset = '30';
        $start = $today->modify('-29 days');
    }
    return [
        'preset' => $preset,
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
    ];
}

function brb_is_bot_request() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    if ($ua === '') return true;
    return (bool) preg_match('/bot|crawl|spider|slurp|preview|wget|curl|headless|facebookexternalhit|embedly/i', $ua);
}

function brb_attr_cookie_name() { return 'brb_attr'; }
function brb_attr_from_request() {
    $landing = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
    if ($landing === '') $landing = '/';
    $landing = substr(sanitize_text_field($landing), 0, 190);
    $ref = '';
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $host = wp_parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_HOST);
        if (is_string($host)) $ref = substr(sanitize_text_field($host), 0, 190);
    }
    $pick = function ($key) {
        $value = isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
        return substr($value, 0, 80);
    };
    return [
        'landing' => $landing,
        'referrer' => $ref,
        'utm_source' => $pick('utm_source'),
        'utm_medium' => $pick('utm_medium'),
        'utm_campaign' => $pick('utm_campaign'),
    ];
}
function brb_attr_read() {
    $raw = isset($_COOKIE[brb_attr_cookie_name()]) ? wp_unslash($_COOKIE[brb_attr_cookie_name()]) : '';
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) return brb_attr_from_request();
    $clean = brb_attr_from_request();
    foreach (['landing', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign'] as $key) {
        if (!empty($data[$key]) && is_string($data[$key])) $clean[$key] = substr(sanitize_text_field($data[$key]), 0, $key === 'landing' || $key === 'referrer' ? 190 : 80);
    }
    return $clean;
}
function brb_attr_maybe_set() {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
    if (brb_is_bot_request()) return;
    if (is_user_logged_in() && current_user_can('manage_options')) return;
    if (!empty($_COOKIE[brb_attr_cookie_name()])) return;
    $payload = wp_json_encode(brb_attr_from_request());
    if (!is_string($payload)) return;
    setcookie(brb_attr_cookie_name(), $payload, [
        'expires' => time() + 90 * DAY_IN_SECONDS,
        'path' => COOKIEPATH ? COOKIEPATH : '/',
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[brb_attr_cookie_name()] = $payload;
}
function brb_track_page_view() {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
    if (brb_is_bot_request()) return;
    if (is_user_logged_in() && current_user_can('manage_options')) return;
    if (!is_front_page() && !is_singular()) return;
    brb_install_report_tables();
    $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
    if ($path === '') $path = '/';
    $path = substr(sanitize_text_field($path), 0, 120);
    $ref = '';
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $host = wp_parse_url((string) $_SERVER['HTTP_REFERER'], PHP_URL_HOST);
        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        if (is_string($host) && $host !== '' && strcasecmp((string) $host, (string) $home) !== 0) {
            $ref = substr(sanitize_text_field($host), 0, 120);
        }
    }
    global $wpdb;
    $day = brb_now()->format('Y-m-d');
    $table = brb_views_table();
    $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (view_day, path, referrer_host, views) VALUES (%s, %s, %s, 1)
         ON DUPLICATE KEY UPDATE views = views + 1",
        $day,
        $path,
        $ref
    ));
}
add_action('init', function () {
    if (get_option('brb_reports_schema') === '1') return;
    brb_install_report_tables();
    update_option('brb_reports_schema', '1', false);
}, 5);
add_action('template_redirect', function () {
    brb_attr_maybe_set();
    brb_track_page_view();
}, 20);

function brb_event_hit($name, $day = null) {
    $name = sanitize_key((string) $name);
    if (!in_array($name, brb_report_events(), true)) return false;
    brb_install_report_tables();
    if ($day === null) $day = brb_now()->format('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $day)) return false;
    global $wpdb;
    $table = brb_events_table();
    $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (event_day, event_name, hits) VALUES (%s, %s, 1)
         ON DUPLICATE KEY UPDATE hits = hits + 1",
        $day,
        $name
    ));
    return true;
}
function brb_event_rest($req) {
    $name = sanitize_key((string) $req->get_param('event'));
    brb_event_hit($name);
    return ['ok' => in_array($name, brb_report_events(), true)];
}
function brb_event_permission($req) {
    if (brb_chat_origin_ok()) return true;
    $nonce = (string) $req->get_header('x-brb-chat');
    if ($nonce !== '' && wp_verify_nonce($nonce, 'brb_chat')) return true;
    return new WP_Error('brb_event_forbidden', 'This event is only recorded on The Boardroom website.', ['status' => 403]);
}

function brb_chat_log_question($message, $intent) {
    $message = trim(wp_strip_all_tags((string) $message));
    if ($message === '') return;
    brb_install_report_tables();
    $unanswered = in_array((string) $intent, ['unknown', 'refuse', 'legal'], true) ? 1 : 0;
    global $wpdb;
    $wpdb->insert(brb_chatlog_table(), [
        'question' => substr($message, 0, 500),
        'intent' => substr(sanitize_key((string) $intent), 0, 40),
        'unanswered' => $unanswered,
        'created' => current_time('mysql', true),
    ]);
}

function brb_origin_label($utm, $referrer, $channel) {
    $utm = strtolower(trim((string) $utm));
    $referrer = strtolower(trim((string) $referrer));
    if (strpos($utm, 'google') !== false || strpos($referrer, 'google.') !== false) return 'Google';
    if (strpos($utm, 'vrbo') !== false || strpos($referrer, 'vrbo.') !== false) return 'VRBO';
    if ($channel === 'chat lead' || $utm === 'chat') return 'Chat';
    if ($utm === '' && $referrer === '') return 'Direct';
    if ($utm !== '') return ucfirst($utm);
    return $referrer !== '' ? $referrer : 'Direct';
}
function brb_inquiry_channel($row) {
    $source = strtolower(trim((string) ($row->source ?? '')));
    $topic = strtolower(trim((string) ($row->topic ?? '')));
    if ($source === 'chat assistant' || $source === 'chat lead') return 'chat lead';
    if ($topic === 'tour' || $source === 'tour request') return 'tour request';
    if ($source === 'booking form') return 'booking form';
    return 'contact form';
}
function brb_report_stamp_inquiry($id, $extra = []) {
    $id = (int) $id;
    if ($id <= 0) return;
    brb_install_report_tables();
    $row = brb_tour($id);
    if (!$row) return;
    $attr = brb_attr_read();
    $fields = [
        'lead_status' => 'new',
        'utm_source' => substr((string) ($extra['utm_source'] ?? $attr['utm_source']), 0, 80),
        'utm_medium' => substr((string) ($extra['utm_medium'] ?? $attr['utm_medium']), 0, 80),
        'utm_campaign' => substr((string) ($extra['utm_campaign'] ?? $attr['utm_campaign']), 0, 80),
        'referrer_host' => substr((string) ($extra['referrer_host'] ?? $attr['referrer']), 0, 190),
        'landing_path' => substr((string) ($extra['landing_path'] ?? $attr['landing']), 0, 190),
    ];
    if (trim((string) ($row->source ?? '')) === '' && !empty($extra['source'])) {
        $fields['source'] = substr(sanitize_text_field($extra['source']), 0, 40);
    }
    if (!empty($extra['event_type'])) $fields['event_type'] = substr(sanitize_key($extra['event_type']), 0, 40);
    if (isset($extra['party_size']) && $extra['party_size'] !== '') $fields['party_size'] = max(0, (int) $extra['party_size']);
    if (!empty($extra['phone']) && trim((string) $row->phone) === '') $fields['phone'] = substr(sanitize_text_field($extra['phone']), 0, 50);
    global $wpdb;
    $wpdb->update(brb_tour_table(), $fields, ['id' => $id]);
}
function brb_report_stamp_booking($id) {
    $id = (int) $id;
    if ($id <= 0) return;
    brb_install_report_tables();
    $attr = brb_attr_read();
    global $wpdb;
    $wpdb->update(brb_table(), [
        'utm_source' => $attr['utm_source'],
        'referrer_host' => $attr['referrer'],
        'landing_path' => $attr['landing'],
    ], ['id' => $id]);
}

function brb_ga_measurement_id() {
    $id = strtoupper(trim((string) brb_opt('ga_measurement_id')));
    return preg_match('/^G-[A-Z0-9]+$/', $id) ? $id : '';
}
function brb_ga_property_id() {
    $id = preg_replace('/\D/', '', (string) brb_opt('ga_property_id'));
    return $id !== '' ? $id : '';
}
function brb_ga_tag_allowed() {
    return brb_ga_measurement_id() !== '' && function_exists('brb_search_indexing_allowed') && brb_search_indexing_allowed();
}
function brb_ga_credentials() {
    $raw = get_option('brb_ga_credentials', '');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) return null;
    if (empty($data['client_email']) || empty($data['private_key'])) return null;
    return $data;
}
function brb_ga_store_credentials($json) {
    $json = trim((string) $json);
    if ($json === '') return new WP_Error('empty', 'Paste the service account JSON, or leave the box blank to keep the saved key.');
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
        return new WP_Error('json', 'That file needs a client_email and a private_key.');
    }
    update_option('brb_ga_credentials', wp_json_encode($data), false);
    return true;
}
function brb_b64url($raw) {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}
function brb_ga_access_token($creds) {
    $cached = get_transient('brb_ga_token');
    if (is_string($cached) && $cached !== '') return $cached;
    $now = time();
    $header = brb_b64url(wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = brb_b64url(wp_json_encode([
        'iss' => $creds['client_email'],
        'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ]));
    $input = $header . '.' . $claim;
    $sig = '';
    if (!openssl_sign($input, $sig, $creds['private_key'], OPENSSL_ALGO_SHA256)) return '';
    $jwt = $input . '.' . brb_b64url($sig);
    $response = wp_remote_post('https://oauth2.googleapis.com/token', [
        'timeout' => 15,
        'body' => [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ],
    ]);
    if (is_wp_error($response)) return '';
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    $token = is_array($data) ? (string) ($data['access_token'] ?? '') : '';
    if ($token === '') return '';
    set_transient('brb_ga_token', $token, 50 * MINUTE_IN_SECONDS);
    return $token;
}
function brb_ga_run_report($property, $token, $body) {
    $response = wp_remote_post('https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($property) . ':runReport', [
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode($body),
    ]);
    if (is_wp_error($response)) return null;
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    return is_array($data) ? $data : null;
}
function brb_ga_rows($report, $dim) {
    $out = [];
    foreach ((array) ($report['rows'] ?? []) as $row) {
        $name = (string) ($row['dimensionValues'][0]['value'] ?? '');
        $n = (int) ($row['metricValues'][0]['value'] ?? 0);
        if ($name === '' || $name === '(not set)') continue;
        $out[] = [$dim => $name, 'sessions' => $n];
    }
    return $out;
}
function brb_ga_report($start, $end) {
    $property = brb_ga_property_id();
    $creds = brb_ga_credentials();
    if ($property === '' || !$creds) return ['ok' => false, 'reason' => 'Add a GA4 property id and a service account JSON in Booking Settings.'];
    $cache_key = 'brb_ga_rep_' . md5($property . '|' . $start . '|' . $end);
    $cached = get_transient($cache_key);
    if (is_array($cached)) return $cached;
    $token = brb_ga_access_token($creds);
    if ($token === '') return ['ok' => false, 'reason' => 'Google did not accept the service account. Check that it can view the property.'];
    $range = [['startDate' => $start, 'endDate' => $end]];
    $totals = brb_ga_run_report($property, $token, [
        'dateRanges' => $range,
        'metrics' => [['name' => 'sessions'], ['name' => 'totalUsers']],
    ]);
    $pages = brb_ga_run_report($property, $token, [
        'dateRanges' => $range,
        'dimensions' => [['name' => 'pagePath']],
        'metrics' => [['name' => 'sessions']],
        'limit' => 8,
        'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
    ]);
    $sources = brb_ga_run_report($property, $token, [
        'dateRanges' => $range,
        'dimensions' => [['name' => 'sessionSource']],
        'metrics' => [['name' => 'sessions']],
        'limit' => 8,
        'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
    ]);
    $devices = brb_ga_run_report($property, $token, [
        'dateRanges' => $range,
        'dimensions' => [['name' => 'deviceCategory']],
        'metrics' => [['name' => 'sessions']],
    ]);
    if (!is_array($totals)) return ['ok' => false, 'reason' => 'Google Analytics did not return a report for that range.'];
    $result = [
        'ok' => true,
        'sessions' => (int) ($totals['rows'][0]['metricValues'][0]['value'] ?? 0),
        'users' => (int) ($totals['rows'][0]['metricValues'][1]['value'] ?? 0),
        'pages' => brb_ga_rows($pages, 'page'),
        'sources' => brb_ga_rows($sources, 'source'),
        'devices' => brb_ga_rows($devices, 'device'),
    ];
    set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
    return $result;
}

function brb_stripe_reconcile($start, $end) {
    if (!brb_stripe_enabled()) return ['ok' => false, 'reason' => 'Stripe is not connected. Figures below are from payment records on this site.'];
    $cache_key = 'brb_stripe_rep_' . md5($start . '|' . $end);
    $cached = get_transient($cache_key);
    if (is_array($cached)) return $cached;
    $gte = strtotime($start . ' 00:00:00 UTC');
    $lte = strtotime($end . ' 23:59:59 UTC');
    $response = wp_remote_get('https://api.stripe.com/v1/charges?limit=100&created[gte]=' . (int) $gte . '&created[lte]=' . (int) $lte, [
        'timeout' => 20,
        'headers' => ['Authorization' => 'Bearer ' . brb_stripe_secret()],
    ]);
    if (is_wp_error($response)) {
        return ['ok' => false, 'reason' => brb_stripe_redact($response->get_error_message())];
    }
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
        $message = is_array($data) ? (string) ($data['error']['message'] ?? 'Stripe did not return charges.') : 'Stripe did not return charges.';
        return ['ok' => false, 'reason' => brb_stripe_redact($message)];
    }
    $gross = 0;
    $refunded = 0;
    $count = 0;
    foreach ($data['data'] as $charge) {
        if (!is_array($charge) || empty($charge['paid'])) continue;
        $count++;
        $gross += ((int) ($charge['amount'] ?? 0)) / 100;
        $refunded += ((int) ($charge['amount_refunded'] ?? 0)) / 100;
    }
    $result = [
        'ok' => true,
        'count' => $count,
        'gross' => round($gross, 2),
        'refunded' => round($refunded, 2),
        'more' => !empty($data['has_more']),
    ];
    set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
    return $result;
}

function brb_report_money_add(&$bucket, $key, $amount) {
    if (!isset($bucket[$key])) $bucket[$key] = 0.0;
    $bucket[$key] = round($bucket[$key] + (float) $amount, 2);
}

function brb_report_build($start, $end) {
    global $wpdb;
    brb_install_report_tables();
    $start_dt = $start . ' 00:00:00';
    $end_dt = $end . ' 23:59:59';
    $book = brb_table();
    $pay = brb_pay_table();
    $inq = brb_tour_table();

    $payments = $wpdb->get_results($wpdb->prepare(
        "SELECT p.*, b.event_type, b.email, b.status AS booking_status, b.total, b.deposit_due, b.amount_paid, b.damage_deposit, b.damage_paid, b.damage_refunded, b.guests, b.start_dt, b.created AS booked_at
         FROM $pay p LEFT JOIN $book b ON b.id = p.booking_id
         WHERE p.received_on >= %s AND p.received_on <= %s",
        $start,
        $end
    ));
    $gross = 0.0;
    $deposits = 0.0;
    $balances = 0.0;
    $fees = 0.0;
    $by_type = [];
    $by_month = [];
    $grouped = [];
    foreach ((array) $payments as $p) {
        $grouped[(int) $p->booking_id][] = $p;
        $amount = (float) $p->amount;
        $gross += $amount;
        $fees += (float) $p->fee;
        $type = brb_event_type_label($p->event_type ?? '') ?: 'Not set';
        brb_report_money_add($by_type, $type, $amount);
        brb_report_money_add($by_month, substr((string) $p->received_on, 0, 7), $amount);
    }
    foreach ($grouped as $rows) {
        usort($rows, function ($a, $b) { return (int) $a->id <=> (int) $b->id; });
        $sum = 0.0;
        foreach ($rows as $p) $sum += (float) $p->amount;
        $before = max(0, (float) $rows[0]->amount_paid - $sum);
        $deposit_due = (float) $rows[0]->deposit_due;
        foreach ($rows as $p) {
            $amount = (float) $p->amount;
            $deposit_part = min($amount, max(0, $deposit_due - $before));
            $deposits += $deposit_part;
            $balances += max(0, round($amount - $deposit_part, 2));
            $before += $amount;
        }
    }

    $bookings = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $book WHERE created >= %s AND created <= %s",
        $start_dt,
        $end_dt
    ));
    $by_status = [];
    $party = [];
    $values = [];
    $lead_days = [];
    $weekdays = array_fill(0, 7, 0);
    $seasons = [];
    $emails = [];
    $cancelled = 0;
    foreach ((array) $bookings as $b) {
        $status = (string) $b->status;
        if (!isset($by_status[$status])) $by_status[$status] = 0;
        $by_status[$status]++;
        if ($status === 'cancelled') $cancelled++;
        if (!in_array($status, ['cancelled', 'declined', 'expired'], true)) {
            $party[] = (int) $b->guests;
            if (brb_price_is_set($b)) $values[] = (float) $b->total;
            $start_day = substr((string) $b->start_dt, 0, 10);
            $created_day = substr((string) $b->created, 0, 10);
            if ($start_day !== '' && $created_day !== '') {
                $lead_days[] = (strtotime($start_day) - strtotime($created_day)) / DAY_IN_SECONDS;
            }
            $ts = strtotime((string) $b->start_dt);
            if ($ts) {
                $weekdays[(int) wp_date('w', $ts, new DateTimeZone('America/Chicago'))]++;
                $season = wp_date('F', $ts, new DateTimeZone('America/Chicago'));
                if (!isset($seasons[$season])) $seasons[$season] = 0;
                $seasons[$season]++;
            }
        }
        $email = strtolower(trim((string) $b->email));
        if ($email !== '') $emails[$email] = true;
    }
    $repeat = 0;
    if ($emails) {
        $all = $wpdb->get_results("SELECT email, COUNT(*) AS n FROM $book WHERE status NOT IN ('cancelled','declined','expired') GROUP BY email");
        foreach ((array) $all as $row) {
            $email = strtolower(trim((string) $row->email));
            if ($email !== '' && isset($emails[$email]) && (int) $row->n > 1) $repeat++;
        }
    }

    $open_rows = $wpdb->get_results(
        "SELECT * FROM $book WHERE status IN ('approved','held','deposit_paid') AND price_set = 1 AND total > amount_paid + 0.009"
    );
    $outstanding = 0.0;
    $outstanding_n = 0;
    $upcoming = 0.0;
    $now = brb_now()->format('Y-m-d H:i:s');
    foreach ((array) $open_rows as $b) {
        $left = round(max(0, (float) $b->total - (float) $b->amount_paid) + max(0, (float) $b->damage_deposit - (float) $b->damage_paid), 2);
        $outstanding += $left;
        $outstanding_n++;
        if ((string) $b->start_dt >= $now) $upcoming += $left;
    }

    $damage_held = (float) $wpdb->get_var("SELECT COALESCE(SUM(damage_paid),0) FROM $book WHERE damage_paid > 0.009 AND damage_refunded = 0");
    $damage_back = (float) $wpdb->get_var("SELECT COALESCE(SUM(damage_paid),0) FROM $book WHERE damage_refunded = 1");
    $damage_held_n = (int) $wpdb->get_var("SELECT COUNT(*) FROM $book WHERE damage_paid > 0.009 AND damage_refunded = 0");
    $damage_back_n = (int) $wpdb->get_var("SELECT COUNT(*) FROM $book WHERE damage_refunded = 1");

    $inquiries = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $inq WHERE created >= %s AND created <= %s ORDER BY created DESC",
        $start_dt,
        $end_dt
    ));
    $channels = [];
    $origins = [];
    $inquiry_to_book = [];
    foreach ((array) $inquiries as $row) {
        $channel = brb_inquiry_channel($row);
        if (!isset($channels[$channel])) $channels[$channel] = ['inquiries' => 0, 'approved' => 0, 'deposit' => 0, 'paid' => 0];
        $channels[$channel]['inquiries']++;
        $origin = brb_origin_label($row->utm_source ?? '', $row->referrer_host ?? '', $channel);
        if (!isset($origins[$origin])) $origins[$origin] = 0;
        $origins[$origin]++;
        $email = strtolower(trim((string) $row->email));
        if ($email === '') continue;
        $match = $wpdb->get_row($wpdb->prepare(
            "SELECT created, status FROM $book WHERE email = %s AND created >= %s ORDER BY created ASC LIMIT 1",
            $email,
            $row->created
        ));
        if (!$match) continue;
        $inquiry_to_book[] = max(0, (strtotime((string) $match->created) - strtotime((string) $row->created)) / DAY_IN_SECONDS);
        $st = (string) $match->status;
        if (in_array($st, ['approved', 'held', 'deposit_paid', 'paid', 'confirmed'], true)) $channels[$channel]['approved']++;
        if (in_array($st, ['deposit_paid', 'paid', 'confirmed'], true)) $channels[$channel]['deposit']++;
        if (in_array($st, ['paid', 'confirmed'], true)) $channels[$channel]['paid']++;
    }
    $booking_inquiries = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $book WHERE source = 'online' AND created >= %s AND created <= %s",
        $start_dt,
        $end_dt
    ));
    if (!isset($channels['booking form'])) $channels['booking form'] = ['inquiries' => 0, 'approved' => 0, 'deposit' => 0, 'paid' => 0];
    $channels['booking form']['inquiries'] += $booking_inquiries;
    $online_later = $wpdb->get_results($wpdb->prepare(
        "SELECT status FROM $book WHERE source = 'online' AND created >= %s AND created <= %s",
        $start_dt,
        $end_dt
    ));
    foreach ((array) $online_later as $b) {
        $st = (string) $b->status;
        if (in_array($st, ['approved', 'held', 'deposit_paid', 'paid', 'confirmed'], true)) $channels['booking form']['approved']++;
        if (in_array($st, ['deposit_paid', 'paid', 'confirmed'], true)) $channels['booking form']['deposit']++;
        if (in_array($st, ['paid', 'confirmed'], true)) $channels['booking form']['paid']++;
    }

    $visits = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(views),0) FROM " . brb_views_table() . " WHERE view_day >= %s AND view_day <= %s",
        $start,
        $end
    ));
    $inquiry_total = 0;
    foreach ($channels as $row) $inquiry_total += (int) $row['inquiries'];
    $approved_n = 0;
    $deposit_n = 0;
    $paid_n = 0;
    foreach ($channels as $row) {
        $approved_n += (int) $row['approved'];
        $deposit_n += (int) $row['deposit'];
        $paid_n += (int) $row['paid'];
    }
    $funnel = [
        ['label' => 'Page views', 'n' => $visits, 'rate' => null],
        ['label' => 'Inquiries', 'n' => $inquiry_total, 'rate' => brb_report_rate($inquiry_total, $visits)],
        ['label' => 'Approved', 'n' => $approved_n, 'rate' => brb_report_rate($approved_n, $inquiry_total)],
        ['label' => 'Deposit paid', 'n' => $deposit_n, 'rate' => brb_report_rate($deposit_n, $approved_n)],
        ['label' => 'Paid in full', 'n' => $paid_n, 'rate' => brb_report_rate($paid_n, $deposit_n)],
    ];

    $steps = [];
    $event_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT event_name, SUM(hits) AS hits FROM " . brb_events_table() . " WHERE event_day >= %s AND event_day <= %s GROUP BY event_name",
        $start,
        $end
    ));
    $hits = [];
    foreach ((array) $event_rows as $row) $hits[$row->event_name] = (int) $row->hits;
    $step_pairs = [
        ['Date step', 'booking_step_date', 'booking_step_review'],
        ['Review step', 'booking_step_review', 'booking_step_submit'],
        ['Submit', 'booking_step_submit', 'booking_request_submitted'],
    ];
    foreach ($step_pairs as $pair) {
        $from = $hits[$pair[1]] ?? 0;
        $to = $hits[$pair[2]] ?? 0;
        $steps[] = ['label' => $pair[0], 'from' => $from, 'to' => $to, 'rate' => brb_report_rate($to, $from)];
    }

    $daily = $wpdb->get_results($wpdb->prepare(
        "SELECT view_day, SUM(views) AS views FROM " . brb_views_table() . " WHERE view_day >= %s AND view_day <= %s GROUP BY view_day ORDER BY view_day",
        $start,
        $end
    ));
    $top_pages = $wpdb->get_results($wpdb->prepare(
        "SELECT path, SUM(views) AS views FROM " . brb_views_table() . " WHERE view_day >= %s AND view_day <= %s GROUP BY path ORDER BY views DESC LIMIT 8",
        $start,
        $end
    ));
    $top_refs = $wpdb->get_results($wpdb->prepare(
        "SELECT referrer_host, SUM(views) AS views FROM " . brb_views_table() . " WHERE view_day >= %s AND view_day <= %s AND referrer_host <> '' GROUP BY referrer_host ORDER BY views DESC LIMIT 8",
        $start,
        $end
    ));

    $questions = $wpdb->get_results($wpdb->prepare(
        "SELECT question, COUNT(*) AS n FROM " . brb_chatlog_table() . " WHERE created >= %s AND created <= %s GROUP BY question ORDER BY n DESC LIMIT 8",
        $start_dt,
        $end_dt
    ));
    $unanswered = $wpdb->get_results($wpdb->prepare(
        "SELECT question, COUNT(*) AS n FROM " . brb_chatlog_table() . " WHERE unanswered = 1 AND created >= %s AND created <= %s GROUP BY question ORDER BY n DESC LIMIT 8",
        $start_dt,
        $end_dt
    ));

    $span_days = max(1, (int) ((strtotime($end) - strtotime($start)) / DAY_IN_SECONDS) + 1);
    $busy_days = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT DATE(start_dt)) FROM $book WHERE status IN ('approved','held','deposit_paid','paid','confirmed') AND start_dt < %s AND end_dt > %s",
        $end_dt,
        $start_dt
    ));
    $hold_days = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . brb_hold_table() . " WHERE start_dt < %s AND end_dt > %s",
        $end_dt,
        $start_dt
    ));
    $blackout_n = 0;
    foreach (brb_blackouts() as $ymd) {
        if ($ymd >= $start && $ymd <= $end) $blackout_n++;
    }

    $soon = brb_now()->modify('+7 days')->format('Y-m-d H:i:s');
    $attention = [
        'review' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $book WHERE status IN ('review','pending')"),
        'deposit_overdue' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $book WHERE status IN ('approved','held') AND amount_paid < 0.01 AND created < %s",
            brb_now()->modify('-1 day')->format('Y-m-d H:i:s')
        )),
        'week_unpaid' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $book WHERE status IN ('approved','held','deposit_paid') AND price_set = 1 AND total > amount_paid + 0.009 AND start_dt >= %s AND start_dt < %s",
            $now,
            $soon
        )),
        'damage_return' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $book WHERE damage_paid > 0.009 AND damage_refunded = 0 AND end_dt < %s AND status IN ('deposit_paid','paid','confirmed')",
            $now
        )),
    ];

    $upcoming_list = $wpdb->get_results($wpdb->prepare(
        "SELECT id, name, email, status, start_dt, guests, total, amount_paid, event_type FROM $book
         WHERE end_dt >= %s AND status NOT IN ('cancelled','declined','expired') ORDER BY start_dt ASC LIMIT 12",
        $now
    ));

    $created_n = count((array) $bookings);
    return [
        'start' => $start,
        'end' => $end,
        'financials' => [
            'gross' => round($gross, 2),
            'deposits' => round($deposits, 2),
            'balances' => round($balances, 2),
            'fees' => round($fees, 2),
            'damage_held' => round($damage_held, 2),
            'damage_held_n' => $damage_held_n,
            'damage_refunded' => round($damage_back, 2),
            'damage_refunded_n' => $damage_back_n,
            'outstanding' => round($outstanding, 2),
            'outstanding_n' => $outstanding_n,
            'upcoming' => round($upcoming, 2),
            'by_type' => $by_type,
            'by_month' => $by_month,
        ],
        'bookings' => [
            'by_status' => $by_status,
            'count' => $created_n,
            'avg_value' => $values ? round(array_sum($values) / count($values), 2) : null,
            'avg_party' => $party ? round(array_sum($party) / count($party), 1) : null,
            'lead_days' => $lead_days ? round(array_sum($lead_days) / count($lead_days), 1) : null,
            'inquiry_to_book_days' => $inquiry_to_book ? round(array_sum($inquiry_to_book) / count($inquiry_to_book), 1) : null,
            'weekdays' => $weekdays,
            'seasons' => $seasons,
            'occupancy' => brb_report_rate($busy_days, $span_days),
            'busy_days' => $busy_days,
            'span_days' => $span_days,
            'holds' => $hold_days,
            'blackouts' => $blackout_n,
            'cancel_rate' => brb_report_rate($cancelled, $created_n),
            'cancelled' => $cancelled,
            'repeat' => $repeat,
            'upcoming' => $upcoming_list,
        ],
        'leads' => [
            'rows' => $inquiries,
            'channels' => $channels,
            'origins' => $origins,
            'funnel' => $funnel,
            'steps' => $steps,
        ],
        'traffic' => [
            'visits' => $visits,
            'daily' => $daily,
            'pages' => $top_pages,
            'referrers' => $top_refs,
        ],
        'chat' => [
            'top' => $questions,
            'unanswered' => $unanswered,
        ],
        'attention' => $attention,
    ];
}

function brb_report_csv($section, $report) {
    $lines = [];
    $add = function ($cells) use (&$lines) {
        $out = [];
        foreach ((array) $cells as $cell) {
            $cell = str_replace('"', '""', (string) $cell);
            $out[] = '"' . $cell . '"';
        }
        $lines[] = implode(',', $out);
    };
    if ($section === 'financials') {
        $f = $report['financials'];
        $add(['Metric', 'Amount']);
        foreach ([
            'Gross collected' => $f['gross'],
            'Deposits' => $f['deposits'],
            'Balances' => $f['balances'],
            'Card fees' => $f['fees'],
            'Damage deposits held' => $f['damage_held'],
            'Damage deposits refunded' => $f['damage_refunded'],
            'Outstanding' => $f['outstanding'],
            'Upcoming expected' => $f['upcoming'],
        ] as $label => $amount) {
            $add([$label, number_format((float) $amount, 2, '.', '')]);
        }
        $add([]);
        $add(['Event type', 'Revenue']);
        foreach ($f['by_type'] as $type => $amount) $add([$type, number_format((float) $amount, 2, '.', '')]);
    } elseif ($section === 'bookings') {
        $b = $report['bookings'];
        $add(['Status', 'Count']);
        foreach ($b['by_status'] as $status => $n) $add([brb_status_label($status), $n]);
        $add([]);
        $add(['Upcoming', 'When', 'Status', 'Guests']);
        foreach ($b['upcoming'] as $row) $add([$row->name, $row->start_dt, brb_status_label($row->status), $row->guests]);
    } elseif ($section === 'leads') {
        $add(['Name', 'Email', 'Phone', 'Created', 'Channel', 'Origin', 'Lead status', 'Event type', 'Event date', 'Guests', 'Lost reason']);
        foreach ($report['leads']['rows'] as $row) {
            $channel = brb_inquiry_channel($row);
            $add([
                $row->name,
                $row->email,
                $row->phone,
                $row->created,
                $channel,
                brb_origin_label($row->utm_source ?? '', $row->referrer_host ?? '', $channel),
                brb_lead_statuses()[$row->lead_status ?? 'new'] ?? ($row->lead_status ?? 'new'),
                brb_event_type_label($row->event_type ?? ''),
                $row->preferred ?? '',
                $row->party_size ?? '',
                $row->lost_reason ?? '',
            ]);
        }
    } elseif ($section === 'traffic') {
        $add(['Day', 'Page views']);
        foreach ($report['traffic']['daily'] as $row) $add([$row->view_day, $row->views]);
        $add([]);
        $add(['Page', 'Views']);
        foreach ($report['traffic']['pages'] as $row) $add([$row->path, $row->views]);
    } else {
        $a = $report['attention'];
        $add(['Item', 'Count']);
        $add(['Pending review', $a['review']]);
        $add(['Deposits overdue', $a['deposit_overdue']]);
        $add(['Events in 7 days not paid in full', $a['week_unpaid']]);
        $add(['Damage deposits to return', $a['damage_return']]);
    }
    return implode("\n", $lines) . "\n";
}

function brb_report_weekday_name($i) {
    $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return $names[(int) $i] ?? '';
}
function brb_report_bars($pairs) {
    $max = 1;
    foreach ($pairs as $pair) $max = max($max, (float) $pair[1]);
    echo '<ul class="brb-bars">';
    foreach ($pairs as $pair) {
        $pct = (int) round(100 * ((float) $pair[1]) / $max);
        echo '<li><span>' . esc_html($pair[0]) . '</span><span class="brb-bar"><span style="width:' . $pct . '%"></span></span><em>' . esc_html((string) $pair[1]) . '</em></li>';
    }
    if (!$pairs) echo '<li>Nothing in this range.</li>';
    echo '</ul>';
}
function brb_report_export_link($section, $range) {
    $url = wp_nonce_url(admin_url('admin-post.php?action=brb_report_csv&section=' . rawurlencode($section) . '&range=' . rawurlencode($range['preset']) . '&from=' . rawurlencode($range['start']) . '&to=' . rawurlencode($range['end'])), 'brb_report_csv');
    return '<a class="button" href="' . esc_url($url) . '">CSV</a>';
}

function brb_admin_reports() {
    if (!current_user_can('manage_options')) return;
    $range = brb_report_range(wp_unslash($_GET));
    $report = brb_report_build($range['start'], $range['end']);
    $f = $report['financials'];
    $b = $report['bookings'];
    $stripe = brb_stripe_reconcile($range['start'], $range['end']);
    $ga = brb_ga_report($range['start'], $range['end']);
    echo '<div class="wrap brb-reports">';
    echo '<h1>Reports</h1>';
    echo '<form method="get" class="brb-report-range">';
    echo '<input type="hidden" name="page" value="brb-reports">';
    echo '<label>Range <select name="range">';
    foreach (['month' => 'This month', '30' => 'Last 30 days', '90' => 'Last 90 days', 'ytd' => 'Year to date', 'custom' => 'Custom'] as $key => $label) {
        echo '<option value="' . esc_attr($key) . '"' . selected($range['preset'], $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label> ';
    echo '<label>From <input type="date" name="from" value="' . esc_attr($range['start']) . '"></label> ';
    echo '<label>To <input type="date" name="to" value="' . esc_attr($range['end']) . '"></label> ';
    echo '<button class="button button-primary">Apply</button>';
    echo '</form>';
    echo '<p class="brb-report-note">' . esc_html($range['start'] . ' through ' . $range['end']) . '. Card fees are recorded on top of the booking and are not part of the rental balance.</p>';

    $a = $report['attention'];
    echo '<section class="brb-report-card brb-attention"><h2>Needs attention</h2>';
    echo '<ul>';
    echo '<li><a href="' . esc_url(admin_url('admin.php?page=brb&status=review')) . '">' . (int) $a['review'] . ' pending review</a></li>';
    echo '<li>' . (int) $a['deposit_overdue'] . ' approved bookings with the deposit still unpaid after a day</li>';
    echo '<li>' . (int) $a['week_unpaid'] . ' events in the next 7 days that are not paid in full</li>';
    echo '<li>' . (int) $a['damage_return'] . ' damage deposits ready to return</li>';
    echo '</ul>' . brb_report_export_link('attention', $range) . '</section>';

    echo '<section class="brb-report-card"><h2>Financials ' . brb_report_export_link('financials', $range) . '</h2>';
    echo '<div class="brb-stats">';
    foreach ([
        'Gross collected' => brb_money($f['gross']),
        'Deposits' => brb_money($f['deposits']),
        'Later balances' => brb_money($f['balances']),
        'Card fees (3%)' => brb_money($f['fees']),
        'Damage deposits held' => brb_money($f['damage_held']) . ' (' . (int) $f['damage_held_n'] . ')',
        'Damage deposits refunded' => brb_money($f['damage_refunded']) . ' (' . (int) $f['damage_refunded_n'] . ')',
        'Outstanding' => brb_money($f['outstanding']) . ' (' . (int) $f['outstanding_n'] . ')',
        'Upcoming expected' => brb_money($f['upcoming']),
    ] as $label => $value) {
        echo '<div><em>' . esc_html($label) . '</em><strong>' . esc_html($value) . '</strong></div>';
    }
    echo '</div>';
    echo '<h3>Revenue by month</h3>';
    $months = [];
    foreach ($f['by_month'] as $month => $amount) $months[] = [$month, brb_money($amount)];
    brb_report_bars($months);
    echo '<h3>Revenue by event type</h3>';
    $types = [];
    foreach ($f['by_type'] as $type => $amount) $types[] = [$type, brb_money($amount)];
    brb_report_bars($types);
    echo '<h3>Stripe</h3><p>';
    if (!empty($stripe['ok'])) {
        echo esc_html('Stripe charges in this range: ' . (int) $stripe['count'] . ', gross ' . brb_money($stripe['gross']) . ', refunded ' . brb_money($stripe['refunded']) . '.');
        if (!empty($stripe['more'])) echo ' Stripe returned a partial page of charges.';
        echo ' This is read-only and cached for 15 minutes. The secret key is not shown.';
    } else {
        echo esc_html((string) ($stripe['reason'] ?? 'Stripe figures are unavailable.'));
    }
    echo '</p></section>';

    echo '<section class="brb-report-card"><h2>Bookings ' . brb_report_export_link('bookings', $range) . '</h2>';
    echo '<div class="brb-stats">';
    echo '<div><em>Created in range</em><strong>' . (int) $b['count'] . '</strong></div>';
    echo '<div><em>Average value</em><strong>' . ($b['avg_value'] === null ? '—' : esc_html(brb_money($b['avg_value']))) . '</strong></div>';
    echo '<div><em>Average party</em><strong>' . ($b['avg_party'] === null ? '—' : esc_html((string) $b['avg_party'])) . '</strong></div>';
    echo '<div><em>Days from request to event</em><strong>' . ($b['lead_days'] === null ? '—' : esc_html((string) $b['lead_days'])) . '</strong></div>';
    echo '<div><em>Days from inquiry to booking</em><strong>' . ($b['inquiry_to_book_days'] === null ? '—' : esc_html((string) $b['inquiry_to_book_days'])) . '</strong></div>';
    echo '<div><em>Cancellation rate</em><strong>' . ($b['cancel_rate'] === null ? '—' : esc_html($b['cancel_rate'] . '%')) . '</strong></div>';
    echo '<div><em>Repeat guests</em><strong>' . (int) $b['repeat'] . '</strong></div>';
    echo '<div><em>Occupancy</em><strong>' . ($b['occupancy'] === null ? '—' : esc_html($b['occupancy'] . '%')) . '</strong></div>';
    echo '<div><em>Holds in range</em><strong>' . (int) $b['holds'] . '</strong></div>';
    echo '<div><em>Blackouts in range</em><strong>' . (int) $b['blackouts'] . '</strong></div>';
    echo '</div>';
    echo '<h3>By status</h3><ul class="brb-plain">';
    foreach ($b['by_status'] as $status => $n) echo '<li>' . esc_html(brb_status_label($status)) . ': ' . (int) $n . '</li>';
    if (!$b['by_status']) echo '<li>No bookings created in this range.</li>';
    echo '</ul>';
    echo '<h3>Busiest days</h3>';
    $days = [];
    foreach ($b['weekdays'] as $i => $n) $days[] = [brb_report_weekday_name($i), $n];
    brb_report_bars($days);
    echo '<h3>Busiest months</h3>';
    $season_rows = [];
    foreach ($b['seasons'] as $name => $n) $season_rows[] = [$name, $n];
    brb_report_bars($season_rows);
    echo '<h3>Upcoming</h3><table class="widefat"><thead><tr><th>When</th><th>Name</th><th>Status</th><th>Guests</th></tr></thead><tbody>';
    if (!$b['upcoming']) echo '<tr><td colspan="4">Nothing upcoming.</td></tr>';
    foreach ($b['upcoming'] as $row) {
        echo '<tr><td>' . esc_html(brb_format_utc_mysql($row->start_dt)) . '</td><td><a href="' . esc_url(admin_url('admin.php?page=brb&booking=' . (int) $row->id)) . '">' . esc_html($row->name) . '</a></td><td>' . esc_html(brb_status_label($row->status)) . '</td><td>' . (int) $row->guests . '</td></tr>';
    }
    echo '</tbody></table></section>';

    echo '<section class="brb-report-card"><h2>Leads and inquiries ' . brb_report_export_link('leads', $range) . '</h2>';
    echo '<h3>Funnel</h3><ol class="brb-funnel">';
    foreach ($report['leads']['funnel'] as $step) {
        $rate = $step['rate'] === null ? '' : ' <em>' . esc_html($step['rate'] . '% from the previous step') . '</em>';
        echo '<li><strong>' . esc_html($step['label']) . '</strong> ' . (int) $step['n'] . $rate . '</li>';
    }
    echo '</ol>';
    echo '<h3>By channel</h3><table class="widefat"><thead><tr><th>Channel</th><th>Inquiries</th><th>Approved</th><th>Deposit paid</th><th>Paid in full</th></tr></thead><tbody>';
    foreach ($report['leads']['channels'] as $name => $row) {
        echo '<tr><td>' . esc_html($name) . '</td><td>' . (int) $row['inquiries'] . '</td><td>' . (int) $row['approved'] . '</td><td>' . (int) $row['deposit'] . '</td><td>' . (int) $row['paid'] . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<h3>Where they came from</h3><ul class="brb-plain">';
    if (!$report['leads']['origins']) echo '<li>No inquiry origin in this range.</li>';
    foreach ($report['leads']['origins'] as $name => $n) echo '<li>' . esc_html($name) . ': ' . (int) $n . '</li>';
    echo '</ul>';
    echo '<h3>Booking steps</h3><ul class="brb-plain">';
    foreach ($report['leads']['steps'] as $step) {
        $rate = $step['rate'] === null ? 'no starts' : $step['rate'] . '% continued';
        echo '<li>' . esc_html($step['label']) . ': ' . (int) $step['from'] . ' to ' . (int) $step['to'] . ' (' . esc_html($rate) . ')</li>';
    }
    echo '</ul>';
    echo '<h3>People</h3><table class="widefat"><thead><tr><th>When</th><th>Name</th><th>Email</th><th>Phone</th><th>Channel</th><th>Origin</th><th>Status</th><th>Event</th><th>Guests</th></tr></thead><tbody>';
    if (!$report['leads']['rows']) echo '<tr><td colspan="9">No contact, tour, or chat inquiries in this range.</td></tr>';
    foreach (array_slice($report['leads']['rows'], 0, 40) as $row) {
        $channel = brb_inquiry_channel($row);
        $status = brb_lead_statuses()[$row->lead_status ?? 'new'] ?? 'New';
        if (($row->lead_status ?? '') === 'lost' && trim((string) ($row->lost_reason ?? '')) !== '') $status .= ': ' . $row->lost_reason;
        echo '<tr><td>' . esc_html(brb_format_utc_mysql($row->created)) . '</td><td>' . esc_html($row->name) . '</td><td>' . esc_html($row->email) . '</td><td>' . esc_html($row->phone) . '</td><td>' . esc_html($channel) . '</td><td>' . esc_html(brb_origin_label($row->utm_source ?? '', $row->referrer_host ?? '', $channel)) . '</td><td>' . esc_html($status) . '</td><td>' . esc_html(trim(brb_event_type_label($row->event_type ?? '') . ' ' . (string) ($row->preferred ?? ''))) . '</td><td>' . esc_html((string) ($row->party_size ?? '')) . '</td></tr>';
    }
    echo '</tbody></table></section>';

    echo '<section class="brb-report-card"><h2>Web traffic ' . brb_report_export_link('traffic', $range) . '</h2>';
    if (!empty($ga['ok'])) {
        echo '<p>Google Analytics: ' . (int) $ga['sessions'] . ' sessions, ' . (int) $ga['users'] . ' users. Cached for 15 minutes.</p>';
        echo '<h3>Top pages</h3><ul class="brb-plain">';
        foreach ($ga['pages'] as $row) echo '<li>' . esc_html($row['page']) . ': ' . (int) $row['sessions'] . '</li>';
        echo '</ul><h3>Sources</h3><ul class="brb-plain">';
        foreach ($ga['sources'] as $row) echo '<li>' . esc_html($row['source']) . ': ' . (int) $row['sessions'] . '</li>';
        echo '</ul><h3>Devices</h3><ul class="brb-plain">';
        foreach ($ga['devices'] as $row) echo '<li>' . esc_html($row['device']) . ': ' . (int) $row['sessions'] . '</li>';
        echo '</ul>';
    } else {
        echo '<p>' . esc_html((string) ($ga['reason'] ?? 'Google Analytics is not configured.')) . ' First-party page views are below. They skip bots and logged-in admins and do not store a name, email, or address.</p>';
        echo '<p><strong>' . (int) $report['traffic']['visits'] . '</strong> page views in this range.</p>';
        echo '<h3>Daily visits</h3>';
        $daily = [];
        foreach ($report['traffic']['daily'] as $row) $daily[] = [$row->view_day, (int) $row->views];
        brb_report_bars($daily);
        echo '<h3>Top pages</h3><ul class="brb-plain">';
        if (!$report['traffic']['pages']) echo '<li>No page views yet.</li>';
        foreach ($report['traffic']['pages'] as $row) echo '<li>' . esc_html($row->path) . ': ' . (int) $row->views . '</li>';
        echo '</ul><h3>Referrers</h3><ul class="brb-plain">';
        if (!$report['traffic']['referrers']) echo '<li>No outside referrers yet.</li>';
        foreach ($report['traffic']['referrers'] as $row) echo '<li>' . esc_html($row->referrer_host) . ': ' . (int) $row->views . '</li>';
        echo '</ul>';
    }
    echo '</section>';

    echo '<section class="brb-report-card"><h2>Chat</h2><h3>Top questions</h3><ul class="brb-plain">';
    if (!$report['chat']['top']) echo '<li>No chat questions in this range.</li>';
    foreach ($report['chat']['top'] as $row) echo '<li>' . esc_html($row->question) . ' (' . (int) $row->n . ')</li>';
    echo '</ul><h3>Unanswered</h3><ul class="brb-plain">';
    if (!$report['chat']['unanswered']) echo '<li>No unanswered questions in this range.</li>';
    foreach ($report['chat']['unanswered'] as $row) echo '<li>' . esc_html($row->question) . ' (' . (int) $row->n . ')</li>';
    echo '</ul></section>';
    echo '</div>';
}

function brb_report_headlines() {
    $range = brb_report_range(['range' => 'month']);
    $report = brb_report_build($range['start'], $range['end']);
    return [
        'gross' => $report['financials']['gross'],
        'outstanding' => $report['financials']['outstanding'],
        'review' => $report['attention']['review'],
        'visits' => $report['traffic']['visits'],
    ];
}
function brb_dashboard_widget() {
    if (!current_user_can('manage_options')) return;
    $n = brb_report_headlines();
    echo '<ul class="brb-dash">';
    echo '<li>Collected this month: <strong>' . esc_html(brb_money($n['gross'])) . '</strong></li>';
    echo '<li>Outstanding: <strong>' . esc_html(brb_money($n['outstanding'])) . '</strong></li>';
    echo '<li>Pending review: <strong>' . (int) $n['review'] . '</strong></li>';
    echo '<li>Page views this month: <strong>' . (int) $n['visits'] . '</strong></li>';
    echo '</ul><p><a href="' . esc_url(admin_url('admin.php?page=brb-reports')) . '">Open reports</a></p>';
}
add_action('wp_dashboard_setup', function () {
    if (!current_user_can('manage_options')) return;
    wp_add_dashboard_widget('brb_reports_widget', 'Boardroom reports', 'brb_dashboard_widget');
});

add_action('admin_post_brb_report_csv', function () {
    if (!current_user_can('manage_options')) wp_die('Sorry, you are not allowed to do that.');
    check_admin_referer('brb_report_csv');
    $section = sanitize_key(wp_unslash($_GET['section'] ?? 'financials'));
    if (!in_array($section, ['financials', 'bookings', 'leads', 'traffic', 'attention'], true)) $section = 'financials';
    $range = brb_report_range(wp_unslash($_GET));
    $csv = brb_report_csv($section, brb_report_build($range['start'], $range['end']));
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="boardroom-' . $section . '.csv"');
    echo $csv;
    exit;
});

function brb_weekly_summary_body() {
    $n = brb_report_headlines();
    $a = brb_report_build(brb_now()->modify('-6 days')->format('Y-m-d'), brb_now()->format('Y-m-d'));
    return "Boardroom weekly summary\n\n"
        . 'Collected this month: ' . brb_money($n['gross']) . "\n"
        . 'Outstanding: ' . brb_money($n['outstanding']) . "\n"
        . 'Pending review: ' . (int) $n['review'] . "\n"
        . 'Page views this month: ' . (int) $n['visits'] . "\n"
        . 'Events in the next 7 days not paid in full: ' . (int) $a['attention']['week_unpaid'] . "\n"
        . 'Damage deposits to return: ' . (int) $a['attention']['damage_return'] . "\n\n"
        . admin_url('admin.php?page=brb-reports') . "\n";
}
function brb_weekly_summary_send() {
    if ((string) brb_opt('weekly_summary') !== '1') return false;
    $list = brb_parse_email_list(brb_opt('weekly_summary_emails'));
    if (!$list['valid']) return false;
    $body = brb_weekly_summary_body();
    $sent = false;
    foreach ($list['valid'] as $to) {
        if (brb_mail($to, 'Boardroom weekly summary', $body, brb_staff_reply())) $sent = true;
    }
    return $sent;
}
add_action('init', function () {
    if (get_option('brb_version') !== BRB_VER) return;
    if (!wp_next_scheduled('brb_weekly_summary')) {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', 'brb_weekly_summary');
    }
}, 21);
add_action('brb_weekly_summary', 'brb_weekly_summary_send');

function brb_gtag_head() {
    if (is_admin() || !brb_ga_tag_allowed()) return;
    $id = brb_ga_measurement_id();
    echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr($id) . '"></script>' . "\n";
    echo '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","' . esc_js($id) . '");</script>' . "\n";
}
add_action('wp_head', 'brb_gtag_head', 5);

add_action('rest_api_init', function () {
    register_rest_route('brb/v1', '/event', [
        'methods' => 'POST',
        'permission_callback' => 'brb_event_permission',
        'callback' => 'brb_event_rest',
    ]);
});
