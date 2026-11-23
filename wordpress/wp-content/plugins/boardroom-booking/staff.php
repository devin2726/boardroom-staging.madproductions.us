<?php
/**
 * 2.5.89 staff dashboard at /staff/ for Boardroom staff (Charlie and Chris).
 *
 * - Front-end screen, not wp-admin. Logged-out visitors go to the WordPress login and come back here.
 * - Role brb_staff ("Boardroom staff") has read + brb_manage_bookings. Administrators get brb_manage_bookings too.
 * - Every read and every action is REST under brb/v1/staff/*: logged in, brb_manage_bookings, and for writes
 *   also the X-BRB-Staff-Nonce header (wp_create_nonce('brb_staff')) on top of the core X-WP-Nonce cookie check.
 * - noindex/no-store headers, no WordPress page (so nothing for sitemaps, menus or search to list).
 * - All writes go through the existing plugin code (brb_insert_booking, brb_approve_booking,
 *   brb_decline_booking, brb_record_payment, brb_save_hold, brb_pay_link) under GET_LOCK('brb_book').
 * - No double booking: Add booking and Add hold are refused on any day that is booked, held, closed,
 *   a setup/takedown day, or has a request waiting. Approve re-checks the date under the lock.
 * - Actions (approve, decline, payment, add) never email the guest themselves; a mail guard blocks the guest
 *   address while they run. Max's decision (Nov 23, 2026, 10:00 AM CT): staff may send a guest email only
 *   from a confirm screen that shows the full message and recipient; the server refuses a send without a
 *   valid confirm token tied to that exact previewed message. Nothing sends automatically. Bots never send.
 * - Dashboard text (calendar, lists, details, hold names/notes) is scrubbed of phone numbers, the 2212 check
 *   address, entry codes and other-platform names. The send-confirm screen shows the guest email exactly as the
 *   plugin writes it (Max asked for the full message), which for some email types includes the check address.
 */
if (!defined('ABSPATH')) exit;

define('BRB_SD_CAP', 'brb_manage_bookings');
define('BRB_SD_ROLE', 'brb_staff');
define('BRB_SD_SLUG', 'staff');
define('BRB_SD_NS', 'brb/v1');

function brb_sd_max_guests() { return (int) apply_filters('brb_sd_max_guests', 80); }
function brb_sd_closed_statuses() { return ['cancelled', 'declined', 'expired', 'spam']; }
function brb_sd_request_statuses() { return ['review', 'pending']; }

/* ---------- role, capability, route ---------- */

function brb_sd_install_role() {
    if (!get_role(BRB_SD_ROLE)) add_role(BRB_SD_ROLE, 'Boardroom staff', ['read' => true, BRB_SD_CAP => true]);
    $role = get_role(BRB_SD_ROLE);
    if ($role && !$role->has_cap(BRB_SD_CAP)) $role->add_cap(BRB_SD_CAP);
    if ($role && !$role->has_cap('read')) $role->add_cap('read');
    $admin = get_role('administrator');
    if ($admin && !$admin->has_cap(BRB_SD_CAP)) $admin->add_cap(BRB_SD_CAP);
}
function brb_sd_user_is_staff_only($user = null) {
    $user = $user ?: wp_get_current_user();
    if (!$user || !$user->exists()) return false;
    return in_array(BRB_SD_ROLE, (array) $user->roles, true) && !user_can($user, 'manage_options');
}
function brb_sd_url() { return home_url('/' . BRB_SD_SLUG . '/'); }

add_action('init', function () {
    add_rewrite_rule('^' . BRB_SD_SLUG . '/?$', 'index.php?brb_staff=1', 'top');
    if (get_option('brb_sd_version') !== BRB_VER) {
        brb_sd_install_role();
        flush_rewrite_rules(false);
        update_option('brb_sd_version', BRB_VER, false);
    }
}, 30);
add_filter('query_vars', function ($vars) { $vars[] = 'brb_staff'; return $vars; });

/** Is this request the /staff/ screen (rewrite var, or the plain path when rules are not flushed yet)? */
function brb_sd_is_staff_request() {
    if ((string) get_query_var('brb_staff') === '1') return true;
    return function_exists('brb_request_path') && brb_request_path() === BRB_SD_SLUG;
}
/** login | forbidden | ok */
function brb_sd_page_state() {
    if (!is_user_logged_in()) return 'login';
    if (!current_user_can(BRB_SD_CAP)) return 'forbidden';
    return 'ok';
}
function brb_sd_private_headers() {
    if (headers_sent()) return;
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: SAMEORIGIN');
}
add_action('template_redirect', function () {
    if (!brb_sd_is_staff_request()) return;
    brb_sd_private_headers();
    $state = brb_sd_page_state();
    if ($state === 'login') {
        wp_safe_redirect(wp_login_url(brb_sd_url()));
        exit;
    }
    status_header($state === 'ok' ? 200 : 403);
    echo $state === 'ok' ? brb_sd_page_html() : brb_sd_forbidden_html();
    exit;
}, 0);

// Staff-only accounts land on /staff/ after login, never in wp-admin, and see no admin bar.
add_filter('login_redirect', function ($to, $requested, $user) {
    if ($user instanceof WP_User && brb_sd_user_is_staff_only($user)) return brb_sd_url();
    return $to;
}, 20, 3);
add_action('admin_init', function () {
    if (wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) return;
    if (!brb_sd_user_is_staff_only()) return;
    $page = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    if (in_array($page, ['admin-ajax.php', 'admin-post.php'], true)) return;
    wp_safe_redirect(brb_sd_url());
    exit;
}, 1);
add_filter('show_admin_bar', function ($show) { return brb_sd_user_is_staff_only() ? false : $show; }, 99);
add_filter('logout_redirect', function ($to, $requested, $user) {
    if ($user instanceof WP_User && user_can($user, BRB_SD_CAP) && !user_can($user, 'manage_options')) return brb_sd_url();
    return $to;
}, 20, 3);
// Keep the screen out of Yoast/core sitemaps even if someone makes a page with this slug.
add_filter('wp_sitemaps_posts_query_args', function ($args) {
    $page = get_page_by_path(BRB_SD_SLUG);
    if ($page) $args['post__not_in'] = array_values(array_unique(array_merge((array) ($args['post__not_in'] ?? []), [(int) $page->ID])));
    return $args;
});

/* ---------- text safety ---------- */

/** No phone numbers, no 2212 check address, no entry codes, no other-platform names. */
function brb_sd_scrub($text) {
    $text = (string) $text;
    if ($text === '') return '';
    if (function_exists('brb_ov_redact_codes')) $text = brb_ov_redact_codes($text);
    $text = preg_replace('/2212\s+37th\s+(St(reet)?\.?)?[^\n]*/i', '[address hidden]', $text);
    $text = preg_replace('/(?<![\d$.,])(\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]\d{3}[\s.\-]\d{4}(?!\d)/', '[number hidden]', $text);
    $text = preg_replace('/(?<![\d$.,])\d{10}(?!\d)/', '[number hidden]', $text);
    $text = preg_replace('/\bair\s*bnb\b/i', 'outside stay', $text);
    return $text;
}

/* ---------- day conflicts (the double-booking guard) ---------- */

function brb_sd_valid_ymd($ymd) {
    $ymd = (string) $ymd;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, brb_tz());
    return $d && $d->format('Y-m-d') === $ymd;
}
function brb_sd_day_bookings($ymd, $exclude_id = 0) {
    global $wpdb;
    $day = new DateTimeImmutable($ymd . ' 00:00:00', brb_tz());
    $closed = "'" . implode("','", brb_sd_closed_statuses()) . "'";
    return (array) $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . brb_table() . " WHERE id<>%d AND start_dt < %s AND end_dt > %s AND status NOT IN ($closed) ORDER BY start_dt ASC, id ASC",
        (int) $exclude_id,
        $day->modify('+1 day')->format('Y-m-d H:i:s'),
        $day->format('Y-m-d H:i:s')
    ));
}
/**
 * Why this day cannot take a new booking or hold, or null when it is open.
 * $args['exclude'] = booking id to ignore (Approve); $args['ignore_requests'] = true lets Approve pick
 * one of two waiting requests. Returns ['code' => ..., 'message' => ...].
 */
function brb_sd_day_conflict($ymd, $args = []) {
    if (!brb_sd_valid_ymd($ymd)) return ['code' => 'date', 'message' => 'Choose a date.'];
    $exclude = (int) ($args['exclude'] ?? 0);
    if (in_array($ymd, brb_blackouts(), true)) return ['code' => 'closed', 'message' => 'This day is closed.'];
    foreach (brb_holds_touching_day($ymd) as $h) {
        $type = (string) ($h->hold_type ?? '');
        if ($type === 'hold') return ['code' => 'held', 'message' => 'This date is held.'];
        if ($type === 'reserved') return ['code' => 'booked', 'message' => 'This date is reserved.'];
        return ['code' => 'closed', 'message' => 'This day is closed.'];
    }
    $waiting = false;
    foreach (brb_sd_day_bookings($ymd, $exclude) as $b) {
        if (in_array((string) $b->status, brb_blocking_statuses(), true)) return ['code' => 'booked', 'message' => 'This date is already booked.'];
        if (in_array((string) $b->status, brb_sd_request_statuses(), true)) $waiting = true;
    }
    if ($waiting && empty($args['ignore_requests'])) {
        return ['code' => 'waiting', 'message' => 'A request is waiting for this date. Approve or decline it first.'];
    }
    $day = new DateTimeImmutable($ymd . ' 00:00:00', brb_tz());
    if (brb_range_hits_addon($day->setTime(10, 0), $day->setTime(23, 30), $exclude)) {
        return ['code' => 'addon', 'message' => 'This date is a setup or takedown day for another booking.'];
    }
    // Last word: the same check the booking code uses.
    if (!brb_is_free($day->setTime(10, 0), $day->setTime(23, 30), $exclude)) return ['code' => 'busy', 'message' => brb_date_taken_message()];
    return null;
}
function brb_sd_lock() {
    global $wpdb;
    return (string) $wpdb->get_var("SELECT GET_LOCK('brb_book', 10)") === '1';
}
function brb_sd_unlock() {
    global $wpdb;
    $wpdb->query("SELECT RELEASE_LOCK('brb_book')");
}

/* ---------- guest-mail guard ---------- */

/** While a staff action runs, any email to the booking's guest is blocked. Staff alerts still go. */
function brb_sd_guard_guest_mail($emails) {
    $GLOBALS['brb_sd_guarded'] = array_values(array_filter(array_map('strtolower', array_map('trim', (array) $emails))));
}
function brb_sd_release_guard() { $GLOBALS['brb_sd_guarded'] = []; }
add_filter('pre_wp_mail', function ($pre, $atts) {
    $guarded = (array) ($GLOBALS['brb_sd_guarded'] ?? []);
    if (!$guarded || $pre !== null) return $pre;
    $to = $atts['to'] ?? [];
    if (!is_array($to)) $to = explode(',', (string) $to);
    $headers = $atts['headers'] ?? [];
    if (!is_array($headers)) $headers = explode("\n", str_replace("\r\n", "\n", (string) $headers));
    foreach ($headers as $h) {
        if (preg_match('/^\s*(cc|bcc)\s*:(.*)$/i', (string) $h, $hm)) $to = array_merge($to, explode(',', $hm[2]));
    }
    foreach ($to as $addr) {
        $addr = strtolower(trim((string) $addr));
        if (preg_match('/<([^>]+)>/', $addr, $m)) $addr = trim($m[1]);
        if ($addr !== '' && in_array($addr, $guarded, true)) {
            $GLOBALS['brb_sd_blocked_mail'] = (int) ($GLOBALS['brb_sd_blocked_mail'] ?? 0) + 1;
            return false;
        }
    }
    return $pre;
}, 1, 2);

/* ---------- guest email: preview, confirm, send (Max's decision, Nov 23 2026 10:00 AM CT) ---------- */

/** Message types staff can send. Text always comes from the plugin's own email builders. */
function brb_sd_mail_kinds() {
    return [
        'request' => 'Request received email',
        'approve' => 'Approval email',
        'decline' => 'Decline email',
        'receipt' => 'Payment receipt',
        'paylink' => 'Pay link email',
        'balance' => 'Balance reminder (draft wording)',
        'checklist' => 'Week-out checklist email',
    ];
}
/** Addresses the dashboard never emails. */
function brb_sd_never_email() {
    return array_map('strtolower', (array) apply_filters('brb_sd_never_email', ['contiveros8@gmail.com', 'stephens818@gmail.com']));
}
/** Render the request-received guest email exactly as brb_send_request_emails would, with delivery blocked. */
function brb_sd_capture_guest_mail($b, $fn) {
    $captured = null;
    $guest = strtolower(trim((string) $b->email));
    $cap = function ($pre, $atts) use (&$captured, $guest) {
        $to = is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'];
        if ($captured === null && strtolower(trim($to)) === $guest) $captured = ['subject' => (string) $atts['subject'], 'body' => (string) $atts['message']];
        return false; // never delivered: this is only a preview
    };
    add_filter('pre_wp_mail', $cap, -1000, 2);
    try { $fn(); } finally { remove_filter('pre_wp_mail', $cap, -1000); }
    return $captured;
}
/** The full message staff will see on the confirm screen. Nothing is sent here. */
function brb_sd_draft($booking_id, $kind, $note = '') {
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.', ['status' => 404]);
    $kinds = brb_sd_mail_kinds();
    if (!isset($kinds[$kind])) return new WP_Error('kind', 'Unknown email type.');
    $to = sanitize_email($b->email);
    if (!is_email($to)) return new WP_Error('to', 'This booking has no valid guest email.');
    if (in_array(strtolower($to), brb_sd_never_email(), true)) return new WP_Error('to', 'The dashboard does not email this address.');
    $subject = ''; $body = '';
    if ($kind === 'approve') {
        if (!in_array((string) $b->status, ['approved', 'held'], true)) return new WP_Error('status', 'Approve the request first.');
        $subject = brb_approve_guest_subject($b); $body = brb_approve_guest_body($b);
    } elseif ($kind === 'decline') {
        if ((string) $b->status !== 'declined') return new WP_Error('status', 'Decline the request first.');
        $subject = brb_decline_guest_subject($b); $body = brb_decline_guest_body($b, sanitize_textarea_field((string) $note), []);
    } elseif ($kind === 'receipt') {
        $pays = brb_payments($b->id);
        $last = $pays ? end($pays) : null;
        if (!$last) return new WP_Error('payment', 'No payment is recorded yet.');
        $m = brb_payment_guest_message($b, (float) $last->amount, (string) $last->check_number, (string) $last->method, $last);
        $subject = (string) $m['subject']; $body = (string) $m['body'];
    } elseif ($kind === 'paylink') {
        if (brb_pay_link($b) === '') return new WP_Error('link', 'There is no pay link yet. Still needed: ' . implode(', ', brb_sd_blockers($b)) . '.');
        $subject = 'Your Boardroom payment link (' . brb_ref($b) . ')';
        $body = brb_guest_hello_line() . "\n\n" . brb_payment_link_body($b);
    } elseif ($kind === 'balance') {
        if ((string) $b->status !== 'deposit_paid' || round((float) $b->total - (float) $b->amount_paid, 2) <= 0.009) return new WP_Error('status', 'A balance reminder is only for a Paid Deposit booking with a balance left.');
        if (brb_sd_reminder_sent($b->id)) return new WP_Error('sent', 'The balance reminder for this booking was already sent.');
        if (!brb_sd_reminder_is_open($b)) return new WP_Error('not_ready', 'The balance reminder is not open yet. It can be previewed from ' . brb_sd_reminder_opens_on($b)->format('D, M j, Y') . '.', ['status' => 409]);
        $m = brb_sd_balance_reminder($b);
        if (is_wp_error($m)) return $m;
        $subject = $m['subject']; $body = $m['body'];
    } elseif ($kind === 'checklist') {
        if (!brb_sd_checklist_eligible($b)) return new WP_Error('status', 'This booking is not due a week-out checklist email, or it was already sent.');
        $m = brb_checklist_reminder_message($b);
        $subject = $m['subject']; $body = $m['body'];
    } else {
        $got = brb_sd_capture_guest_mail($b, function () use ($b) { brb_send_request_emails($b, true); });
        if (!$got) return new WP_Error('draft', 'Could not build that email.');
        $subject = $got['subject']; $body = $got['body'];
    }
    $exp = time() + 15 * MINUTE_IN_SECONDS;
    return [
        'booking_id' => (int) $b->id, 'ref' => brb_ref($b), 'kind' => $kind, 'title' => $kinds[$kind],
        'to' => $to, 'name' => brb_sd_scrub($b->name), 'subject' => $subject, 'body' => $body,
        'confirm' => $exp . '.' . brb_sd_confirm_sig((int) $b->id, $kind, $to, $subject, $body, $exp),
        'can_send' => $kind !== 'balance' || brb_sd_reminder_approved(),
        'notice' => ($kind === 'balance' && !brb_sd_reminder_approved()) ? brb_sd_reminder_notice() . '. Sending is off until Max approves the wording in Settings.' : '',
    ];
}
/** Ties the confirm token to this user, booking, recipient and the exact previewed text. */
function brb_sd_confirm_sig($booking_id, $kind, $to, $subject, $body, $exp) {
    $msg = implode('|', [get_current_user_id(), (int) $booking_id, $kind, strtolower($to), hash('sha256', (string) $subject), hash('sha256', (string) $body), (int) $exp]);
    return hash_hmac('sha256', 'brb-staff-send|' . $msg, wp_salt('nonce'));
}
/**
 * Send one guest email that staff saw in full and confirmed. Refused without a valid, unused confirm
 * token for exactly this recipient, subject and body. Never called automatically.
 */
function brb_sd_send($booking_id, $p) {
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.', ['status' => 404]);
    $p = is_array($p) ? $p : [];
    $kind = sanitize_key((string) ($p['kind'] ?? ''));
    $to = sanitize_email((string) ($p['to'] ?? ''));
    $subject = (string) ($p['subject'] ?? '');
    $body = (string) ($p['body'] ?? '');
    $token = (string) ($p['confirm'] ?? '');
    if (empty($p['confirmed']) || $token === '' || strpos($token, '.') === false) {
        return new WP_Error('confirm', 'Open the message and press Send on the confirm screen. Nothing was sent.', ['status' => 409]);
    }
    list($exp, $sig) = explode('.', $token, 2);
    if (!ctype_digit($exp) || (int) $exp < time()) return new WP_Error('confirm', 'That preview expired. Open the message again. Nothing was sent.', ['status' => 409]);
    if (!isset(brb_sd_mail_kinds()[$kind]) || strcasecmp($to, (string) $b->email) !== 0) {
        return new WP_Error('confirm', 'The recipient does not match this booking. Nothing was sent.', ['status' => 409]);
    }
    if (!hash_equals(brb_sd_confirm_sig((int) $b->id, $kind, $to, $subject, $body, (int) $exp), $sig)) {
        return new WP_Error('confirm', 'The message changed after the preview. Open it again. Nothing was sent.', ['status' => 409]);
    }
    if (in_array(strtolower($to), brb_sd_never_email(), true)) return new WP_Error('to', 'The dashboard does not email this address.');
    if ($kind === 'balance' && (!brb_sd_reminder_approved() || stripos($subject . $body, 'DRAFT WORDING') !== false)) {
        return new WP_Error('template', 'The balance reminder wording is not approved yet (needs Max approval). Nothing was sent.', ['status' => 409]);
    }
    if ($kind === 'balance' && !brb_sd_reminder_is_open($b)) {
        return new WP_Error('not_ready', 'The balance reminder is not open yet. Nothing was sent.', ['status' => 409]);
    }
    if ($kind === 'checklist' && !brb_sd_checklist_eligible($b)) return new WP_Error('status', 'This checklist email was already sent, or the booking is not due one. Nothing was sent.', ['status' => 409]);
    $used = 'brb_sd_sent_' . md5($token);
    if (get_transient($used)) return new WP_Error('confirm', 'This message was already sent.', ['status' => 409]);
    // One balance reminder per booking, ever: claim the marker first, so a second confirm (even a new preview) is refused.
    if ($kind === 'balance' && !brb_sd_reminder_claim((int) $b->id)) {
        return new WP_Error('sent', 'The balance reminder for this booking was already sent. Nothing was sent.', ['status' => 409]);
    }
    set_transient($used, 1, HOUR_IN_SECONDS);
    $ok = brb_mail($to, $subject, $body, brb_guest_reply_to());
    $kinds = brb_sd_mail_kinds();
    if (!$ok) {
        delete_transient($used);
        if ($kind === 'balance') brb_sd_reminder_release((int) $b->id);
        brb_audit_append((int) $b->id, 'staff dashboard: ' . strtolower($kinds[$kind]) . ' was confirmed but did not send');
        return new WP_Error('mail', 'The email did not send. Try again, or tell Max.', ['status' => 502]);
    }
    brb_audit_append((int) $b->id, 'staff dashboard: ' . strtolower($kinds[$kind]) . ' sent to the guest after the confirm screen; subject: "' . brb_audit_clean($subject) . '"');
    $log = get_option('brb_sd_sent', []);
    if (!is_array($log)) $log = [];
    $log[] = ['booking_id' => (int) $b->id, 'kind' => $kind, 'who' => brb_audit_who(), 'at' => brb_now()->format('Y-m-d H:i:s'), 'subject' => $subject];
    update_option('brb_sd_sent', array_slice($log, -500), false);
    if ($kind === 'checklist') {
        global $wpdb;
        $wpdb->update(brb_table(), ['checklist_reminded' => current_time('mysql', true)], ['id' => (int) $b->id]);
    }
    $link = brb_pay_link($b);
    if ($link !== '' && strpos($body, $link) !== false) brb_note_pay_link_sent((int) $b->id); // the pay window starts or restarts here
    return ['sent' => true, 'to' => $to, 'kind' => $kind];
}
function brb_sd_sent_log() {
    $log = get_option('brb_sd_sent', []);
    return is_array($log) ? array_values($log) : [];
}

/* ---------- data shapes ---------- */

function brb_sd_fmt_date($ymd, $format = 'D, M j') {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $ymd, 0, 10), brb_tz());
    return $d ? $d->format($format) : '';
}
function brb_sd_time($dt) {
    try { return (new DateTimeImmutable($dt, brb_tz()))->format('g:i A'); } catch (Exception $e) { return ''; }
}
/** Booking money chip. While a balance is open after the deposit: "Deposit paid, $X due by [date]". */
function brb_sd_chip($b) {
    $status = (string) $b->status;
    if (in_array($status, brb_sd_request_statuses(), true)) return ['class' => 'review', 'text' => 'Request waiting'];
    if (in_array($status, ['paid', 'confirmed'], true)) return ['class' => 'paid', 'text' => 'Paid in full'];
    if (in_array($status, brb_sd_closed_statuses(), true)) return ['class' => 'block', 'text' => brb_status_label($status)];
    if ($status === 'reserved') return ['class' => 'paid', 'text' => 'Reserved (paid elsewhere)'];
    if (!brb_price_is_set($b)) return ['class' => 'due', 'text' => 'Price to be confirmed'];
    $left = max(0, round((float) $b->total - (float) $b->amount_paid, 2));
    if ($status === 'deposit_paid') {
        if ($left <= 0.009) return ['class' => 'paid', 'text' => 'Paid in full'];
        $on = trim((string) ($b->balance_due_on ?? ''));
        $when = $on !== '' ? brb_sd_fmt_date($on, 'D, M j, Y') : '';
        return ['class' => 'deposit', 'text' => 'Deposit paid, ' . brb_money($left) . ' due' . ($when !== '' ? ' by ' . $when : '')];
    }
    $due = max(0, round((float) $b->deposit_due - (float) $b->amount_paid, 2));
    $hi = brb_sd_hold_info($b);
    if ($hi && !empty($hi['lapsed'])) return ['class' => 'due', 'text' => 'Pay window passed, ' . brb_money($due) . ' still due'];
    if ($hi && !empty($hi['waiting_for_link'])) return ['class' => 'due', 'text' => 'Pay link not sent, ' . brb_money($due) . ' due'];
    if ($hi && $hi['short'] !== '') return ['class' => 'due', 'text' => 'On hold, ' . brb_money($due) . ' due, ' . $hi['short']];
    return ['class' => 'due', 'text' => 'Approved, ' . brb_money($due) . ' due to hold the date'];
}
function brb_sd_booking_brief($b) {
    $chip = brb_sd_chip($b);
    return [
        'type' => 'booking',
        'id' => (int) $b->id,
        'ref' => brb_ref($b),
        'name' => brb_sd_scrub($b->name),
        'date' => substr((string) $b->start_dt, 0, 10),
        'status' => (string) $b->status,
        'status_label' => brb_status_label($b->status),
        'chip' => $chip,
        'guests' => (int) $b->guests,
        'event' => brb_sd_scrub(brb_event_type_label($b->event_type ?? '')),
        'waiting' => in_array((string) $b->status, brb_sd_request_statuses(), true),
        'hold' => brb_sd_hold_info($b),
    ];
}
/** Staff-only gate for the migration routes: an administrator, not a staff-role user. */
function brb_sd_admin_only() {
    return current_user_can('manage_options') ? null : new WP_Error('brb_admin_only', 'Only an administrator can move calendar holds into bookings.', ['status' => 403]);
}
function brb_sd_hold_brief($h) {
    $types = brb_hold_types();
    $type = isset($types[(string) $h->hold_type]) ? (string) $h->hold_type : 'blocked';
    $label = ['hold' => 'Date held', 'reserved' => 'Reserved', 'blocked' => 'Closed'][$type];
    $span = function_exists('brb_ov_time_span') ? brb_ov_time_span($h->start_dt, $h->end_dt) : '';
    return [
        'type' => 'hold',
        'id' => (int) $h->id,
        'hold_type' => $type,
        'can_remove' => (int) $h->id > 0 && empty($h->migrated_to),
        'label' => $label,
        'name' => brb_sd_scrub($h->name),
        'notes' => brb_sd_scrub($h->notes ?? ''),
        'time' => $span,
    ];
}
/** booked | held | waiting | closed | open. Closed beats booked beats held beats waiting. */
function brb_sd_day_kind($ymd, $bookings) {
    if (in_array($ymd, brb_blackouts(), true)) return 'closed';
    $hold = brb_date_status($ymd);
    if ($hold === 'blocked') return 'closed';
    // One record: an approved or held booking with no payment yet is a HOLD, so its day reads Date held.
    $held = false;
    foreach ($bookings as $b) {
        if (!in_array((string) $b->status, brb_blocking_statuses(), true)) continue;
        if (in_array((string) $b->status, ['approved', 'held'], true) && (float) $b->amount_paid < 0.01) $held = true;
        else return 'booked';
    }
    if ($hold === 'reserved') return 'booked';
    if ($held) return 'held';
    if ($hold === 'hold') return 'held';
    foreach ($bookings as $b) if (in_array((string) $b->status, brb_sd_request_statuses(), true)) return 'waiting';
    return 'open';
}
function brb_sd_kind_label($kind) {
    return ['booked' => 'Booked', 'held' => 'Date held', 'waiting' => 'Request waiting', 'closed' => 'Closed', 'open' => 'Open'][$kind] ?? 'Open';
}
function brb_sd_month($ym) {
    $now = brb_now();
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $ym)) $ym = $now->format('Y-m');
    $first = new DateTimeImmutable($ym . '-01 00:00:00', brb_tz());
    $next = $first->modify('first day of next month');
    $all = brb_ov_bookings_between($first->format('Y-m-d H:i:s'), $next->format('Y-m-d H:i:s'));
    $days = [];
    for ($d = $first; $d < $next; $d = $d->modify('+1 day')) {
        $ymd = $d->format('Y-m-d');
        $on = array_values(array_filter($all, function ($b) use ($ymd) { return brb_ov_booking_on_day($b, $ymd); }));
        $kind = brb_sd_day_kind($ymd, $on);
        $tag = '';
        $clock = ''; $lapsed = false;
        foreach ($on as $b) { $tag = brb_sd_scrub($b->name); break; }
        foreach ($on as $b) {
            $hi = brb_sd_hold_info($b);
            if ($hi && $hi['short'] !== '') { $clock = $hi['short']; $lapsed = $hi['lapsed']; break; }
        }
        if ($tag === '' && $kind !== 'open') $tag = brb_sd_kind_label($kind);
        $days[] = ['date' => $ymd, 'day' => (int) $d->format('j'), 'dow' => (int) $d->format('w'), 'kind' => $kind, 'label' => brb_sd_kind_label($kind), 'tag' => mb_substr($tag, 0, 24), 'clock' => $clock, 'lapsed' => $lapsed];
    }
    return [
        'month' => $first->format('Y-m'),
        'title' => $first->format('F Y'),
        'prev' => $first->modify('-1 month')->format('Y-m'),
        'next' => $next->format('Y-m'),
        'today' => $now->format('Y-m-d'),
        'days' => $days,
    ];
}
function brb_sd_money_rows($b) {
    $priced = brb_price_is_set($b);
    $total = $priced ? round((float) $b->total, 2) : 0.0;
    $paid = round((float) $b->amount_paid, 2);
    $fees = 0.0;
    $payments = [];
    foreach (brb_payments($b->id) as $p) {
        $fees += (float) ($p->fee ?? 0);
        $payments[] = [
            'amount' => brb_money($p->amount),
            'fee' => (float) ($p->fee ?? 0) > 0.009 ? brb_money($p->fee) : '',
            'method' => brb_pay_method_label((string) $p->method),
            'on' => brb_sd_fmt_date($p->received_on, 'M j, Y'),
        ];
    }
    return [
        'priced' => $priced,
        'total' => $total,
        'total_text' => $priced ? brb_money($total) : 'Price to be confirmed',
        'paid' => $paid,
        'paid_text' => brb_money($paid),
        'left' => $priced ? max(0, round($total - $paid, 2)) : 0.0,
        'left_text' => $priced ? brb_money(max(0, round($total - $paid, 2))) : '',
        'deposit_text' => $priced ? brb_money((float) $b->deposit_due) : '',
        'charge_now' => brb_charge_now_label($b),
        'balance_due' => brb_balance_due_display($b),
        'card_fees_text' => $fees > 0.009 ? brb_money($fees) . ' (card fees, not part of the rental)' : '',
        'due_now' => brb_amount_due_now($b),
        'payments' => $payments,
    ];
}
/** Dashboard wording for what a pay link still needs (Max, Nov 23 10:10 AM CT: no walk-through wording here). */
function brb_sd_blockers($b) {
    $map = ['walkthrough' => 'Details checked'];
    $out = [];
    foreach (brb_pay_link_blockers($b) as $key => $label) $out[] = $map[$key] ?? $label;
    return $out;
}
function brb_sd_booking_detail($b) {
    $out = brb_sd_booking_brief($b);
    $start = (string) $b->start_dt;
    $out += [
        'when' => brb_sd_fmt_date($start, 'l, F j, Y'),
        'hours' => brb_sd_time($b->start_dt) . ' – ' . brb_sd_time($b->end_dt),
        'end_time' => brb_sd_scrub($b->end_time ?? ''),
        'email' => sanitize_email($b->email),
        'source' => brb_source_label($b->source),
        'notes' => brb_sd_scrub($b->event_about ?: $b->notes),
        'details_checked' => !empty($b->walkthrough_done),
        'money' => brb_sd_money_rows($b),
        'can_approve' => in_array((string) $b->status, brb_sd_request_statuses(), true),
        'can_decline' => in_array((string) $b->status, ['review', 'pending', 'approved', 'held'], true) && (float) $b->amount_paid <= 0.009,
        'can_pay' => in_array((string) $b->status, ['approved', 'held', 'reserved', 'deposit_paid'], true) && brb_price_is_set($b),
        'pay_link_blockers' => brb_sd_blockers($b),
        'sent' => array_values(array_map(function ($r) { return $r['kind']; }, array_filter(brb_sd_sent_log(), function ($r) use ($b) { return (int) $r['booking_id'] === (int) $b->id; }))),
        'can_edit' => brb_sd_can_edit($b),
        'edit' => brb_sd_edit_fields($b),
        'needs_fill' => !is_email((string) $b->email) || !brb_price_is_set($b) || (int) $b->guests < 1,
    ];
    return $out;
}
/**
 * One record view for a calendar hold. Until the planned migration makes a hold a booking in HOLD status,
 * a wp_br_holds row has no guest, money or email, so it cannot send an invoice or pay link yet.
 */
function brb_sd_hold_record($id) {
    $h = brb_hold((int) $id);
    if (!$h) return new WP_Error('missing', 'Hold not found.', ['status' => 404]);
    $out = brb_sd_hold_brief($h);
    $out['date'] = substr((string) $h->start_dt, 0, 10);
    $out['when'] = brb_sd_fmt_date($out['date'], 'l, F j, Y');
    $out['ref'] = 'Hold ' . (int) $h->id;
    $out['pay_note'] = 'This calendar hold has no guest or price on it yet, so there is no invoice or pay link to send.';
    return $out;
}
function brb_sd_day($ymd) {
    if (!brb_sd_valid_ymd($ymd)) return new WP_Error('date', 'Choose a date.', ['status' => 400]);
    $bookings = brb_sd_day_bookings($ymd);
    $kind = brb_sd_day_kind($ymd, $bookings);
    $items = [];
    foreach ($bookings as $b) $items[] = brb_sd_booking_detail($b);
    if (in_array($ymd, brb_blackouts(), true)) $items[] = ['type' => 'hold', 'id' => 0, 'hold_type' => 'blocked', 'label' => 'Closed', 'name' => 'Closed (blackout date)', 'notes' => '', 'time' => 'All day'];
    foreach (brb_holds_touching_day($ymd) as $h) $items[] = brb_sd_hold_brief(brb_hold((int) $h->id) ?: $h);
    $conflict = brb_sd_day_conflict($ymd);
    $rate = brb_rate_for_date($ymd, 'full');
    $past = $ymd < brb_now()->format('Y-m-d');
    return [
        'date' => $ymd,
        'title' => brb_sd_fmt_date($ymd, 'l, F j, Y'),
        'kind' => $kind,
        'label' => brb_sd_kind_label($kind),
        'rate' => $rate,
        'rate_text' => $rate !== null ? brb_whole_money($rate) . ' for the day' : '',
        'items' => $items,
        'can_add' => !$conflict && !$past,
        'add_blocked' => $past ? 'That date is in the past.' : ($conflict['message'] ?? ''),
    ];
}
function brb_sd_requests() {
    global $wpdb;
    $rows = (array) $wpdb->get_results("SELECT * FROM " . brb_table() . " WHERE status IN ('review','pending') ORDER BY start_dt ASC, id ASC LIMIT 200");
    return array_map('brb_sd_booking_detail', $rows);
}
/** Bookings with money still owed (deposit or balance), soonest first. */
function brb_sd_payments() {
    global $wpdb;
    $today = brb_now()->setTime(0, 0)->format('Y-m-d H:i:s');
    $rows = (array) $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . brb_table() . " WHERE status IN ('approved','held','deposit_paid') AND end_dt > %s ORDER BY start_dt ASC, id ASC LIMIT 200",
        $today
    ));
    $out = [];
    foreach ($rows as $b) {
        if (!brb_price_is_set($b)) { $out[] = brb_sd_booking_detail($b); continue; }
        if ((float) $b->total - (float) $b->amount_paid > 0.009) $out[] = brb_sd_booking_detail($b);
    }
    return $out;
}
function brb_sd_upcoming($limit = 40) {
    $out = [];
    foreach (brb_ov_upcoming_items(300) as $it) {
        if ($it['kind'] === 'booking') {
            $b = $it['row'];
            $row = brb_sd_booking_brief($b);
            $row['hours'] = brb_sd_time($b->start_dt) . ' – ' . brb_sd_time($b->end_dt);
            $row['end_time'] = brb_sd_scrub($b->end_time ?? '');
        } else {
            $src = (array) $it['row'];
            $h = (object) ['id' => $src['id'] ?? 0, 'hold_type' => $src['type'] ?? 'blocked', 'name' => $src['name'] ?? '', 'notes' => $src['notes'] ?? '', 'start_dt' => $src['start_dt'] ?? '', 'end_dt' => $src['end_dt'] ?? ''];
            $row = brb_sd_hold_brief($h);
            $row['date'] = substr((string) $h->start_dt, 0, 10);
        }
        $row['when'] = brb_sd_fmt_date($row['date'], 'D, M j, Y');
        $out[] = $row;
        if (count($out) >= $limit) break;
    }
    return $out;
}
function brb_sd_summary() {
    $req = brb_sd_requests();
    $pay = brb_sd_payments();
    $next = null;
    foreach (brb_sd_upcoming(60) as $row) {
        if ($row['type'] === 'booking' && in_array($row['status'], brb_blocking_statuses(), true)) { $next = $row; break; }
    }
    $soon = 0;
    $limit = brb_now()->modify('+14 days')->format('Y-m-d');
    foreach ($pay as $p) if ($p['date'] <= $limit) $soon++;
    return [
        'today' => brb_now()->format('l, F j, Y'),
        'requests' => count($req),
        'requests_text' => count($req) ? 'Oldest date: ' . $req[0]['when'] : 'Nothing waiting',
        'payments' => count($pay),
        'payments_text' => count($pay) ? $soon . ' in the next 14 days' : 'Nothing owed',
        'lapsed' => count(brb_sd_lapsed()),
        'awaiting_link' => count(brb_sd_awaiting_link()),
        'next' => $next ? $next['name'] . ', ' . $next['when'] : 'No events booked',
    ];
}
/** Messages: emails staff may want to send (each opens the confirm screen), and what was sent. Nothing sends here. */
function brb_sd_messages() {
    $kinds = brb_sd_mail_kinds();
    $sent = [];
    foreach (array_reverse(brb_sd_sent_log()) as $r) {
        $b = brb_booking($r['booking_id']);
        if (!$b) continue;
        $sent[] = ['title' => $kinds[$r['kind']] . ' to ' . brb_sd_scrub($b->name), 'ref' => brb_ref($b), 'who' => brb_sd_scrub($r['who']), 'at' => $r['at']];
        if (count($sent) >= 20) break;
    }
    $suggest = [];
    foreach (brb_sd_payments() as $p) {
        $b = brb_booking($p['id']);
        $what = $b->status === 'deposit_paid'
            ? 'Balance of ' . $p['money']['left_text'] . ' due ' . $p['money']['balance_due'] . '.'
            : 'Amount due now ' . brb_money($p['money']['due_now']) . ' to hold ' . $p['when'] . '.';
        $suggest[] = ['title' => $kinds['paylink'] . ' for ' . $p['name'], 'ref' => $p['ref'], 'booking_id' => $p['id'], 'kind' => 'paylink', 'preview' => $what, 'blocked' => $p['pay_link_blockers']];
    }
    return ['suggested' => $suggest, 'sent' => $sent, 'reminders' => brb_sd_reminder_queue(), 'approved_template' => brb_sd_reminder_approved()];
}

/* ---------- add booking / hold: one quote used by both preview and save ---------- */

function brb_sd_add_input($p) {
    $p = is_array($p) ? $p : [];
    return [
        'kind' => (($p['kind'] ?? 'booking') === 'hold') ? 'hold' : 'booking',
        'hold_type' => (($p['hold_type'] ?? 'hold') === 'blocked') ? 'blocked' : 'hold',
        'date' => sanitize_text_field((string) ($p['date'] ?? '')),
        'name' => sanitize_text_field((string) ($p['name'] ?? '')),
        'email' => sanitize_email((string) ($p['email'] ?? '')),
        'guests' => (int) ($p['guests'] ?? 0),
        'event_type' => sanitize_key((string) ($p['event_type'] ?? '')),
        'notes' => sanitize_textarea_field((string) ($p['notes'] ?? '')),
        'charge_now' => (($p['charge_now'] ?? '') === 'full') ? 'full' : 'deposit',
        // Default ON: the guest is not emailed. Unticked, staff see the full request email and confirm before it sends.
        'no_guest_email' => !array_key_exists('no_guest_email', $p) || !empty($p['no_guest_email']),
    ];
}
/** The numbers Add shows and saves. Same function for both, so the preview cannot drift from the save. */
function brb_sd_add_quote($in) {
    if (!brb_sd_valid_ymd($in['date'])) return new WP_Error('date', 'Choose a date.');
    if ($in['kind'] === 'hold') return ['kind' => 'hold', 'date' => $in['date']];
    $max = brb_sd_max_guests();
    if ($in['guests'] < 1) return new WP_Error('guests', 'Enter how many guests.');
    if ($in['guests'] > $max) return new WP_Error('guests', 'Up to ' . $max . ' guests.');
    $r = brb_build_range(['date' => $in['date'], 'type' => 'day', 'guests' => $in['guests']], ['override_notice' => true]);
    if (is_wp_error($r)) return $r;
    $q = brb_quote($r);
    if (empty($q['price_set'])) return new WP_Error('price', 'There is no price for this date yet. Ask Max to set it.');
    $mode = $in['charge_now'] === 'full' ? 'full' : '';
    $due = brb_charge_now_due($mode, 0, $q['total']);
    $fee = brb_card_fee_cents(brb_cents_from_amount($due)) / 100;
    return [
        'kind' => 'booking',
        'date' => $in['date'],
        'r' => $r,
        'q' => $q,
        'charge_now_mode' => $mode,
        'total' => round((float) $q['total'], 2),
        'due_now' => round($due, 2),
        'card_fee' => round($fee, 2),
        'card_total' => round($due + $fee, 2),
        'balance' => round((float) $q['total'] - $due, 2),
        'balance_due_on' => brb_balance_due_on($r['s']),
        'charge_label' => $mode === 'full' ? 'Pay in full (100%)' : 'Deposit ' . brb_pct_label(brb_site_deposit_pct()) . '% (holds the date)',
    ];
}
function brb_sd_quote_public($quote) {
    if ($quote['kind'] === 'hold') return $quote;
    $out = $quote;
    unset($out['r'], $out['q']);
    $out['text'] = [
        'total' => brb_money($quote['total']),
        'due_now' => brb_money($quote['due_now']),
        'card_fee' => brb_money($quote['card_fee']),
        'card_total' => brb_money($quote['card_total']),
        'balance' => brb_money($quote['balance']),
        'balance_due_on' => brb_sd_fmt_date($quote['balance_due_on'], 'D, M j, Y'),
    ];
    return $out;
}
function brb_sd_add($p) {
    $in = brb_sd_add_input($p);
    $quote = brb_sd_add_quote($in);
    if (is_wp_error($quote)) return $quote;
    if ($in['name'] === '' && $in['kind'] === 'booking') return new WP_Error('name', 'Enter the guest name.');
    if ($in['kind'] === 'booking' && !is_email($in['email'])) return new WP_Error('email', 'Enter a valid guest email.');
    if ($in['date'] < brb_now()->format('Y-m-d')) return new WP_Error('past', 'That date is in the past.');
    if (!brb_sd_lock()) return new WP_Error('lock', 'Please try again in a moment.');
    try {
        $conflict = brb_sd_day_conflict($in['date']);
        if ($conflict) return new WP_Error('busy', $conflict['message'] . ' Nothing was saved.', ['code' => $conflict['code']]);
        if ($in['kind'] === 'hold') {
            $blocked = $in['hold_type'] === 'blocked';
            $saved = brb_save_hold([
                'name' => $in['name'] !== '' ? $in['name'] : ($blocked ? 'Closed' : 'Date held'),
                'hold_type' => $in['hold_type'],
                'start_date' => $in['date'],
                'start_time' => $blocked ? '00:00' : '10:00',
                'end_date' => $in['date'],
                'end_time' => $blocked ? '23:59' : '23:30',
                'notes' => trim($in['notes'] . "\nAdded on the staff dashboard by " . brb_audit_who() . '.'),
            ]);
            return is_wp_error($saved) ? $saved : ['hold' => brb_sd_hold_brief($saved)];
        }
        brb_sd_guard_guest_mail([$in['email']]);
        $b = brb_insert_booking($quote['r'], $quote['q'], [
            'name' => $in['name'],
            'email' => $in['email'],
            'phone' => '',
            'notes' => $in['notes'],
            'event_type' => isset(brb_event_types()[$in['event_type']]) ? $in['event_type'] : '',
            'event_about' => $in['notes'],
            'charge_now_mode' => $quote['charge_now_mode'],
            'charge_now_value' => 0,
        ], 'phone', 'staff');
    } finally {
        brb_sd_release_guard();
        brb_sd_unlock();
    }
    if (is_wp_error($b)) return $b;
    brb_audit_append((int) $b->id, 'added on the staff dashboard; charge now ' . $quote['charge_label'] . '; no guest email sent');
    return ['booking' => brb_sd_booking_detail(brb_booking($b->id)), 'quote' => brb_sd_quote_public($quote)];
}

/* ---------- approve / decline / payment / pay link ---------- */

function brb_sd_walkthrough_set($b, $on) {
    global $wpdb;
    $on = $on ? 1 : 0;
    if ((int) $on === (int) ($b->walkthrough_done ?? 0)) return;
    $wpdb->update(brb_table(), ['walkthrough_done' => $on], ['id' => (int) $b->id]);
    brb_audit_append((int) $b->id, 'saved walk-through from "' . ($on ? 'Not yet' : 'Completed') . '" to "' . ($on ? 'Completed' : 'Not yet') . '" (staff dashboard: "I checked the details"); no email sent');
}
function brb_sd_approve($id, $p) {
    $b = brb_booking($id);
    if (!$b) return new WP_Error('missing', 'Booking not found.', ['status' => 404]);
    if (!in_array((string) $b->status, brb_sd_request_statuses(), true)) return new WP_Error('status', 'Only a request that is waiting can be approved.');
    $walk = !empty($p['walkthrough']);
    // Stored in the existing walkthrough_done column; the dashboard label is "I checked the details".
    if (!$walk && empty($b->walkthrough_done)) return new WP_Error('walkthrough', 'Tick "I checked the details" first.');
    $charge = null;
    if (isset($p['charge_now']) && in_array($p['charge_now'], ['deposit', 'full'], true)) {
        $charge = ['mode' => $p['charge_now'] === 'full' ? 'full' : '', 'value' => 0];
    }
    if (!brb_sd_lock()) return new WP_Error('lock', 'Please try again in a moment.');
    $set_walk = false;
    try {
        $conflict = brb_sd_day_conflict(substr((string) $b->start_dt, 0, 10), ['exclude' => (int) $b->id, 'ignore_requests' => true]);
        if ($conflict) return new WP_Error('busy', $conflict['message'] . ' This request was not approved.', ['code' => $conflict['code']]);
        if ($walk && empty($b->walkthrough_done)) { brb_sd_walkthrough_set($b, true); $set_walk = true; }
        brb_sd_guard_guest_mail([$b->email]);
        $res = brb_approve_booking($b->id, false, [], $charge);
        if (is_wp_error($res) && $set_walk) brb_sd_walkthrough_set(brb_booking($b->id), false);
    } finally {
        brb_sd_release_guard();
        brb_sd_unlock();
    }
    if (is_wp_error($res)) return $res;
    return brb_sd_booking_detail(brb_booking($res->id));
}
function brb_sd_decline($id, $p) {
    $b = brb_booking($id);
    if (!$b) return new WP_Error('missing', 'Booking not found.', ['status' => 404]);
    if ((float) $b->amount_paid > 0.009) return new WP_Error('paid', 'A payment is recorded on this booking. Ask Max to handle it in Bookings.');
    brb_sd_guard_guest_mail([$b->email]);
    try {
        $res = brb_decline_booking($b->id, ['send' => false, 'message' => (string) ($p['message'] ?? '')]);
    } finally {
        brb_sd_release_guard();
    }
    if (is_wp_error($res)) return $res;
    return brb_sd_booking_detail(brb_booking($res->id));
}
/** Record payment preview + save share this. The 3% card fee is shown and stored separately. */
function brb_sd_payment_figures($b, $p) {
    $amount = round((float) ($p['amount'] ?? 0), 2);
    $method = in_array(($p['method'] ?? ''), ['card', 'check', 'cash'], true) ? $p['method'] : 'check';
    $fee = $method === 'card' ? brb_card_fee_cents(brb_cents_from_amount($amount)) / 100 : 0.0;
    $left = brb_price_is_set($b) ? max(0, round((float) $b->total - (float) $b->amount_paid, 2)) : 0;
    return [
        'amount' => $amount, 'method' => $method, 'fee' => round($fee, 2), 'charged' => round($amount + $fee, 2),
        'left_after' => max(0, round($left - $amount, 2)),
        'text' => [
            'amount' => brb_money($amount),
            'fee' => $method === 'card' ? brb_money($fee) . ' card fee (' . brb_fee_percent_label() . '), not part of the rental' : 'No card fee',
            'charged' => brb_money($amount + $fee),
            'left_after' => brb_money(max(0, round($left - $amount, 2))),
        ],
    ];
}
function brb_sd_payment($id, $p) {
    $b = brb_booking($id);
    if (!$b) return new WP_Error('missing', 'Booking not found.', ['status' => 404]);
    if (!in_array((string) $b->status, ['approved', 'held', 'deposit_paid'], true)) return new WP_Error('status', 'Approve the request before recording a payment.');
    $f = brb_sd_payment_figures($b, $p);
    if ($f['amount'] <= 0) return new WP_Error('amount', 'Enter an amount greater than zero.');
    $left = brb_price_is_set($b) ? round((float) $b->total - (float) $b->amount_paid + brb_damage_left($b), 2) : 0;
    if ($f['amount'] - $left > 0.009) return new WP_Error('amount', 'That is more than the ' . brb_money($left) . ' still owed.');
    brb_sd_guard_guest_mail([$b->email]);
    try {
        $res = brb_record_payment($b->id, $f['amount'], (string) ($p['check_number'] ?? ''), sanitize_text_field((string) ($p['received_on'] ?? '')), 'Recorded on the staff dashboard. ' . (string) ($p['notes'] ?? ''), $f['method'] === 'check', $f['method'], ['notify' => false, 'fee' => $f['fee']]);
    } finally {
        brb_sd_release_guard();
    }
    if (is_wp_error($res)) return $res;
    return brb_sd_booking_detail(brb_booking($res->id));
}
/** Returns the signed link for staff to copy. Never emails it. */
function brb_sd_pay_link($id) {
    $b = brb_booking($id);
    if (!$b) return new WP_Error('missing', 'Booking not found.', ['status' => 404]);
    $url = brb_pay_link($b);
    if ($url === '') return ['url' => '', 'blockers' => brb_sd_blockers($b)];
    $due = brb_amount_due_now($b);
    $fee = brb_card_fee_cents(brb_cents_from_amount($due)) / 100;
    brb_audit_append((int) $b->id, 'staff dashboard: pay link opened for copying; no email sent');
    return ['url' => $url, 'blockers' => [], 'due' => brb_money($due), 'fee' => brb_money($fee), 'charged' => brb_money($due + $fee)];
}

/* ---------- REST ---------- */

function brb_sd_rest_can($request) {
    if (!is_user_logged_in()) return new WP_Error('brb_staff_login', 'Please log in.', ['status' => 401]);
    if (!current_user_can(BRB_SD_CAP)) return new WP_Error('brb_staff_forbidden', 'This is for Boardroom staff only.', ['status' => 403]);
    if ($request->get_method() !== 'GET') {
        $nonce = (string) $request->get_header('x_brb_staff_nonce');
        if (!wp_verify_nonce($nonce, 'brb_staff')) return new WP_Error('brb_staff_nonce', 'This page is out of date. Reload it and try again.', ['status' => 403]);
    }
    return true;
}
function brb_sd_respond($data) {
    if (is_wp_error($data)) {
        $st = $data->get_error_data();
        $status = is_array($st) && isset($st['status']) ? (int) $st['status'] : 409;
        $res = new WP_REST_Response(['ok' => false, 'code' => $data->get_error_code(), 'message' => brb_sd_scrub($data->get_error_message()), 'reason' => is_array($st) ? ($st['code'] ?? '') : ''], $status);
    } else {
        $res = new WP_REST_Response(['ok' => true, 'data' => $data], 200);
    }
    $res->header('Cache-Control', 'no-store, private');
    $res->header('X-Robots-Tag', 'noindex, nofollow');
    return $res;
}
function brb_sd_routes() {
    $id = '(?P<id>\d+)';
    return [
        ['summary', 'GET', function () { return brb_sd_summary(); }],
        ['calendar', 'GET', function ($r) { return brb_sd_month((string) $r->get_param('month')); }],
        ['day', 'GET', function ($r) { return brb_sd_day((string) $r->get_param('date')); }],
        ['requests', 'GET', function () { return brb_sd_requests(); }],
        ['payments', 'GET', function () { return brb_sd_payments(); }],
        ['upcoming', 'GET', function () { return brb_sd_upcoming(); }],
        ['messages', 'GET', function () { return brb_sd_messages(); }],
        ['booking/' . $id, 'GET', function ($r) { $b = brb_booking((int) $r['id']); return $b ? brb_sd_booking_detail($b) : new WP_Error('missing', 'Booking not found.', ['status' => 404]); }],
        ['hold/' . $id, 'GET', function ($r) { return brb_sd_hold_record((int) $r['id']); }],
        ['booking/' . $id . '/approve', 'POST', function ($r) { return brb_sd_approve((int) $r['id'], $r->get_json_params() ?: $r->get_body_params()); }],
        ['booking/' . $id . '/decline', 'POST', function ($r) { return brb_sd_decline((int) $r['id'], $r->get_json_params() ?: $r->get_body_params()); }],
        ['booking/' . $id . '/payment-preview', 'POST', function ($r) { $b = brb_booking((int) $r['id']); return $b ? brb_sd_payment_figures($b, $r->get_json_params() ?: []) : new WP_Error('missing', 'Booking not found.', ['status' => 404]); }],
        ['booking/' . $id . '/payment', 'POST', function ($r) { return brb_sd_payment((int) $r['id'], $r->get_json_params() ?: $r->get_body_params()); }],
        ['booking/' . $id . '/pay-link', 'POST', function ($r) { return brb_sd_pay_link((int) $r['id']); }],
        ['booking/' . $id . '/pay-link-copied', 'POST', function ($r) { $b = brb_booking((int) $r['id']); if (!$b || brb_pay_link($b) === '') return new WP_Error('link', 'There is no pay link for this booking yet.'); brb_note_pay_link_sent((int) $b->id); return brb_sd_booking_detail(brb_booking((int) $r['id'])); }],
        ['lapsed', 'GET', function () { return array_map('brb_sd_booking_detail', brb_sd_lapsed()); }],
        ['awaiting-link', 'GET', function () { return array_map('brb_sd_booking_detail', brb_sd_awaiting_link()); }],
        ['booking/' . $id . '/release', 'POST', function ($r) { return brb_sd_release((int) $r['id'], $r->get_json_params() ?: []); }],
        ['hold/' . $id . '/remove', 'POST', function ($r) { return brb_sd_remove_hold((int) $r['id'], $r->get_json_params() ?: []); }],
        ['migration', 'GET', function () { return brb_sd_admin_only() ?: brb_sd_mig_summary(); }],
        ['migration/run', 'POST', function ($r) { $p = $r->get_json_params() ?: []; return brb_sd_admin_only() ?: ((empty($p['confirmed'])) ? new WP_Error('confirm', 'Confirm first. Nothing was changed.', ['status' => 409]) : brb_sd_mig_run(false)); }],
        ['migration/undo', 'POST', function ($r) { $p = $r->get_json_params() ?: []; return brb_sd_admin_only() ?: ((empty($p['confirmed'])) ? new WP_Error('confirm', 'Confirm first. Nothing was changed.', ['status' => 409]) : brb_sd_mig_undo()); }],
        ['booking/' . $id . '/draft', 'POST', function ($r) { $p = $r->get_json_params() ?: []; return brb_sd_draft((int) $r['id'], sanitize_key((string) ($p['kind'] ?? '')), (string) ($p['note'] ?? '')); }],
        ['booking/' . $id . '/send', 'POST', function ($r) { return brb_sd_send((int) $r['id'], $r->get_json_params() ?: []); }],
        ['add-preview', 'POST', function ($r) {
            $in = brb_sd_add_input($r->get_json_params() ?: []);
            $q = brb_sd_add_quote($in);
            if (is_wp_error($q)) return $q;
            $c = brb_sd_day_conflict($in['date']);
            $out = brb_sd_quote_public($q);
            $out['blocked'] = $c ? $c['message'] : '';
            return $out;
        }],
        ['add', 'POST', function ($r) { return brb_sd_add($r->get_json_params() ?: $r->get_body_params()); }],
        ['booking/' . $id . '/edit-preview', 'POST', function ($r) { return brb_sd_edit_preview((int) $r['id'], $r->get_json_params() ?: $r->get_body_params()); }],
        ['booking/' . $id . '/edit', 'POST', function ($r) { return brb_sd_edit((int) $r['id'], $r->get_json_params() ?: $r->get_body_params()); }],
    ];
}
add_action('rest_api_init', function () {
    foreach (brb_sd_routes() as $route) {
        list($path, $method, $cb) = $route;
        register_rest_route(BRB_SD_NS, '/staff/' . $path, [
            'methods' => $method,
            'permission_callback' => 'brb_sd_rest_can',
            'callback' => function ($request) use ($cb) { return brb_sd_respond($cb($request)); },
        ]);
    }
});
// The staff routes are not listed in the public REST index.
add_filter('rest_index', function ($response) {
    $data = $response->get_data();
    if (isset($data['routes']) && is_array($data['routes'])) {
        foreach (array_keys($data['routes']) as $k) if (strpos($k, '/' . BRB_SD_NS . '/staff') === 0) unset($data['routes'][$k]);
        $response->set_data($data);
    }
    return $response;
});
add_filter('rest_namespace_index', function ($response) {
    $data = $response->get_data();
    if (isset($data['routes']) && is_array($data['routes'])) {
        foreach (array_keys($data['routes']) as $k) if (strpos($k, '/' . BRB_SD_NS . '/staff') === 0) unset($data['routes'][$k]);
        $response->set_data($data);
    }
    return $response;
});

/* ---------- page ---------- */

function brb_sd_forbidden_html() {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow, noarchive"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Staff only</title></head>'
        . '<body style="font-family:system-ui,sans-serif;background:#f3ebe1;color:#241c16;padding:32px"><h1>Staff only</h1><p>This page is for Boardroom staff. If you need access, ask Max.</p>'
        . '<p><a href="' . esc_url(wp_logout_url(brb_sd_url())) . '">Log out</a></p></body></html>';
}
function brb_sd_page_html() {
    $user = wp_get_current_user();
    $config = [
        'rest' => esc_url_raw(rest_url(BRB_SD_NS . '/staff/')),
        'wpNonce' => wp_create_nonce('wp_rest'),
        'staffNonce' => wp_create_nonce('brb_staff'),
        'user' => $user->display_name,
        'maxGuests' => brb_sd_max_guests(),
        'feeLabel' => brb_fee_percent_label(),
        'depositPct' => brb_pct_label(brb_site_deposit_pct()),
        'eventTypes' => brb_event_types(),
        'today' => brb_now()->format('Y-m-d'),
        'isAdmin' => current_user_can('manage_options'),
        'month' => (isset($_GET['m']) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $_GET['m'])) ? (string) $_GET['m'] : brb_now()->format('Y-m'),
    ];
    $css = esc_url(BRB_URL . 'assets/brb-staff.css?ver=' . BRB_VER);
    $js = esc_url(BRB_URL . 'assets/brb-staff.js?ver=' . BRB_VER);
    ob_start();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<title>Staff bookings · The Boardroom</title>
<link rel="stylesheet" href="<?php echo $css; ?>">
</head>
<body>
<div class="wrap">
  <header class="top">
    <div>
      <h1>The Boardroom</h1>
      <p class="sub">Staff bookings · <?php echo esc_html($user->display_name); ?> · <a href="<?php echo esc_url(wp_logout_url(brb_sd_url())); ?>">Log out</a></p>
    </div>
    <p class="today-line" id="today-line"></p>
  </header>
  <section class="summary" id="summary" aria-label="Today summary">
    <button type="button" class="summary-card" data-jump="requests" id="sum-requests"><span class="k">Requests waiting</span><span class="v">…</span></button>
    <button type="button" class="summary-card" data-jump="payments" id="sum-payments"><span class="k">Payments open</span><span class="v">…</span></button>
    <button type="button" class="summary-card" data-jump="upcoming" id="sum-next"><span class="k">Next event</span><span class="v">…</span></button>
  </section>

  <section id="panel-calendar" class="panel active" data-panel="calendar">
    <h2>Calendar</h2>
    <p class="lede">Tap a day, then tap any item to open its full record.</p>
    <div class="legend" aria-label="Calendar legend">
      <span><i class="swatch booked"></i> Booked</span><span><i class="swatch held"></i> Date held</span>
      <span><i class="swatch lapsed"></i> Pay window passed</span><span><i class="swatch waiting"></i> Request waiting</span><span><i class="swatch closed"></i> Closed</span><span><i class="swatch open"></i> Open</span>
    </div>
    <div class="card cal-card">
      <div class="cal-head">
        <button type="button" class="btn" id="cal-prev" aria-label="Previous month"><span class="wide">Previous month</span><span class="narrow">‹ Prev</span></button>
        <h3 id="cal-title" aria-live="polite"></h3>
        <button type="button" class="btn" id="cal-next" aria-label="Next month"><span class="wide">Next month</span><span class="narrow">Next ›</span></button>
      </div>
      <div class="dow" aria-hidden="true"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
      <div class="cal-grid" id="cal-grid"></div>
    </div>
    <div id="migration-card"></div>
  </section>
  <section id="panel-requests" class="panel" data-panel="requests">
    <h2>Requests waiting</h2>
    <p class="lede">Open a request to check the details, then approve or decline. If you choose to email the guest, you see the full message and confirm before it sends.</p>
    <div id="lapsed-list"></div>
    <div id="awaiting-list"></div>
    <div id="request-list"></div>
  </section>
  <section id="panel-payments" class="panel" data-panel="payments">
    <h2>Payments</h2>
    <p class="lede">Bookings with money still owed. Card payments add a <?php echo esc_html(brb_fee_percent_label()); ?> fee, shown separately.</p>
    <div id="payment-list"></div>
  </section>
  <section id="panel-add" class="panel" data-panel="add">
    <h2>Add a booking or hold</h2>
    <p class="lede">The price comes from the date. Up to <?php echo (int) brb_sd_max_guests(); ?> guests. Days that are booked, held, closed, or have a request waiting cannot be added.</p>
    <form class="card" id="add-form" novalidate>
      <p class="field-label">What are you adding?</p>
      <div class="choice">
        <label><input type="radio" name="kind" value="booking" checked> A booking</label>
        <label><input type="radio" name="kind" value="blocked"> Close the day</label>
      </div>
      <label class="field" for="add-date">Date</label>
      <input type="date" id="add-date" name="date" required>
      <p class="day-note" id="add-date-note" role="status"></p>
      <label class="field" for="add-name" id="add-name-label">Guest name</label>
      <input type="text" id="add-name" name="name" autocomplete="off" maxlength="120">
      <div id="booking-only">
        <label class="field" for="add-email">Guest email</label>
        <input type="email" id="add-email" name="email" autocomplete="off" maxlength="190">
        <label class="field" for="add-event">Event type</label>
        <select id="add-event" name="event_type"></select>
        <label class="field" for="add-guests">How many guests? (up to <?php echo (int) brb_sd_max_guests(); ?>)</label>
        <input type="number" id="add-guests" name="guests" min="1" max="<?php echo (int) brb_sd_max_guests(); ?>" inputmode="numeric">
        <p class="field-label">Charge now</p>
        <div class="choice">
          <label><input type="radio" name="charge_now" value="deposit" checked> Deposit <?php echo esc_html(brb_pct_label(brb_site_deposit_pct())); ?>% (holds the date)</label>
          <label><input type="radio" name="charge_now" value="full"> Pay in full (100%)</label>
        </div>
        <div class="price-box" id="add-price" aria-live="polite"></div>
        <label class="check"><input type="checkbox" id="add-no-email" name="no_guest_email" checked> Don't email the guest</label>
      </div>
      <label class="field" for="add-notes">Notes (optional)</label>
      <textarea id="add-notes" name="notes" maxlength="1000"></textarea>
      <div class="btn-row">
        <button type="submit" class="btn btn-primary" id="add-submit">Save</button>
        <button type="reset" class="btn btn-quiet">Clear form</button>
      </div>
    </form>
  </section>
  <section id="panel-upcoming" class="panel" data-panel="upcoming">
    <h2 class="big-heading">All Upcoming Reservations</h2>
    <p class="lede">Bookings, requests and holds from today on.</p>
    <div id="upcoming-list"></div>
  </section>
  <section id="panel-messages" class="panel" data-panel="messages">
    <h2>Messages</h2>
    <p class="lede">Every email opens a confirm screen with the full message and who it goes to. Nothing sends until you press Send there.</p>
    <div id="message-list"></div>
  </section>
</div>
<footer class="note">Staff only. Do not share this page.</footer>
<nav class="nav" aria-label="Main sections">
  <button type="button" data-jump="calendar" aria-current="page"><span class="ico" aria-hidden="true">▦</span>Calendar</button>
  <button type="button" data-jump="requests"><span class="ico" aria-hidden="true">◻</span>Requests</button>
  <button type="button" data-jump="payments"><span class="ico" aria-hidden="true">$</span>Payments</button>
  <button type="button" data-jump="add"><span class="ico" aria-hidden="true">+</span>Add</button>
  <button type="button" data-jump="upcoming"><span class="ico" aria-hidden="true">◷</span>Upcoming</button>
  <button type="button" data-jump="messages"><span class="ico" aria-hidden="true">✉</span>Messages</button>
</nav>
<div class="overlay" id="overlay" role="dialog" aria-modal="true" aria-labelledby="modal-title" hidden><div class="modal" id="modal"></div></div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script>window.BRB_STAFF = <?php echo wp_json_encode($config); ?>;</script>
<script src="<?php echo $js; ?>"></script>
</body>
</html>
<?php
    return (string) ob_get_clean();
}
