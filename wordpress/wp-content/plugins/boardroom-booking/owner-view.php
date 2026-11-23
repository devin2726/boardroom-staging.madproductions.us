<?php
/**
 * [brb_owner_view]: a read-only bookings calendar for the owner.
 *
 * Meant for one password-protected page (planned slug: /admin-calendar/).
 * It shows a month calendar (booked, held, blocked or free per day), that
 * day's bookings and calendar holds, and a short upcoming list.
 *
 * Read-only by design:
 * - It runs SELECT queries only. It links to nothing in wp-admin, has no
 *   forms, and never calls the approve, decline, cancel, payment or hold code.
 * - The only links are month navigation (?brb_ov_m=YYYY-MM), in-page day
 *   anchors, tel: and mailto:.
 * - Mail is blocked while it renders.
 *
 * Privacy:
 * - It renders nothing unless the page has a post password and the visitor
 *   has entered it, and never in REST, feeds, search, embeds or the admin.
 * - Any page containing the shortcode is noindex/nofollow, Cache-Control
 *   no-store/private, kept out of the core and Yoast sitemaps, site search,
 *   feeds, oEmbed and automatic page lists. Menus: never add the page to a
 *   menu (see docs/owner-view.md).
 * - It shows what the wp-admin Bookings screens show (booking details, money,
 *   charges, payments, follow-up, edit history; hold names and notes), except
 *   door, lock and other entry codes, which are always redacted.
 * - On the page: no "Protected: " title prefix and no Ask Walter chat.
 */
if (!defined('ABSPATH')) exit;

define('BRB_OV_TAG', 'brb_owner_view');

function brb_ov_closed_statuses() {
    return ['cancelled', 'declined', 'expired', 'spam'];
}
/** IDs of posts/pages whose content contains the shortcode. */
function brb_ov_post_ids($refresh = false) {
    static $ids = null;
    if ($ids !== null && !$refresh) return $ids;
    global $wpdb;
    $like = '%' . $wpdb->esc_like('[' . BRB_OV_TAG) . '%';
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type NOT IN ('revision','nav_menu_item') AND post_status NOT IN ('trash','auto-draft')",
        $like
    ));
    $ids = array_values(array_filter(array_map('intval', (array) $rows)));
    return $ids;
}
function brb_ov_is_owner_post($post) {
    $post = get_post($post);
    if (!$post) return false;
    return has_shortcode((string) $post->post_content, BRB_OV_TAG) || in_array((int) $post->ID, brb_ov_post_ids(), true);
}
function brb_ov_context_blocked() {
    if (is_admin() && !wp_doing_ajax()) return true;
    if (defined('REST_REQUEST') && REST_REQUEST) return true;
    if (function_exists('wp_is_json_request') && wp_is_json_request()) return true;
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return true;
    if (is_feed() || is_search() || is_embed()) return true;
    return false;
}

/* ---------- data (SELECT only) ---------- */

function brb_ov_month_param() {
    $now = brb_now();
    $raw = isset($_GET['brb_ov_m']) ? sanitize_text_field(wp_unslash((string) $_GET['brb_ov_m'])) : '';
    $min = $now->modify('first day of this month')->modify('-' . brb_ov_months_back() . ' months')->format('Y-m');
    $max = $now->modify('first day of this month')->modify('+' . brb_ov_months_ahead() . ' months')->format('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) return $now->format('Y-m');
    if ($raw < $min) return $min;
    if ($raw > $max) return $max;
    return $raw;
}
function brb_ov_months_back() { return (int) apply_filters('brb_ov_months_back', 3); }
function brb_ov_months_ahead() { return (int) apply_filters('brb_ov_months_ahead', 24); }

function brb_ov_bookings_between($from, $to) {
    global $wpdb;
    $closed = "'" . implode("','", brb_ov_closed_statuses()) . "'";
    return (array) $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . brb_table() . " WHERE start_dt < %s AND end_dt > %s AND status NOT IN ($closed) ORDER BY start_dt ASC, id ASC LIMIT 500",
        $to,
        $from
    ));
}
function brb_ov_booking_on_day($b, $ymd) {
    $day = $ymd . ' 00:00:00';
    $next = (new DateTimeImmutable($day, brb_tz()))->modify('+1 day')->format('Y-m-d H:i:s');
    return $b->start_dt < $next && $b->end_dt > $day;
}
function brb_ov_is_blocking($status) {
    return in_array((string) $status, brb_blocking_statuses(), true);
}
/** Day status for the calendar: booked, held, blocked or free. */
function brb_ov_day_status($ymd, $day_bookings) {
    foreach ($day_bookings as $b) if (brb_ov_is_blocking($b->status)) return 'booked';
    $hold = brb_date_status($ymd); // plugin block data: blackouts + calendar holds
    if ($hold === 'blocked') return 'blocked';
    if ($hold === 'reserved') return 'booked';
    if ($hold === 'hold') return 'held';
    return 'free';
}
function brb_ov_day_label($status) {
    $map = ['booked' => 'Booked', 'held' => 'Held', 'blocked' => 'Blocked', 'free' => 'Free'];
    return $map[$status] ?? 'Free';
}
function brb_ov_badge_class($status) {
    if (in_array($status, ['paid', 'confirmed'], true)) return 'paid';
    if ($status === 'deposit_paid') return 'deposit';
    if (in_array($status, ['approved', 'held'], true)) return 'approved';
    return 'review';
}
/** Calendar hold label, the same wording as the admin calendar. */
function brb_ov_hold_label($type) {
    $types = brb_hold_types();
    return $types[$type] ?? $types['blocked'];
}
function brb_ov_hold_type($raw) {
    return isset(brb_hold_types()[(string) $raw]) ? (string) $raw : 'blocked';
}
function brb_ov_hold_item($h) {
    $type = brb_ov_hold_type($h->hold_type ?? '');
    return [
        'id' => (int) ($h->id ?? 0),
        'label' => brb_ov_hold_label($type),
        'type' => $type,
        'name' => (string) ($h->name ?? ''),
        'notes' => (string) ($h->notes ?? ''),
        'start_dt' => (string) $h->start_dt,
        'end_dt' => (string) $h->end_dt,
        'created' => (string) ($h->created ?? ''),
    ];
}
function brb_ov_blackout_item($ymd) {
    $named = function_exists('brb_named_blackout_map') ? brb_named_blackout_map() : [];
    return [
        'id' => 0,
        'label' => 'Blocked (blackout date)',
        'type' => 'blocked',
        'name' => (string) ($named[$ymd] ?? ''),
        'notes' => '',
        'start_dt' => $ymd . ' 00:00:00',
        'end_dt' => $ymd . ' 23:59:00',
        'created' => '',
    ];
}
/** Calendar holds/blocks for one day: everything the admin hold editor shows. */
function brb_ov_day_holds($ymd) {
    $out = [];
    if (in_array($ymd, brb_blackouts(), true)) $out[] = brb_ov_blackout_item($ymd);
    foreach (brb_holds_touching_day($ymd) as $h) {
        $full = brb_hold((int) $h->id);
        $out[] = brb_ov_hold_item($full ?: $h);
    }
    return $out;
}
/** Upcoming bookings and holds from today on, sorted by start. */
function brb_ov_upcoming_items($limit = 300) {
    global $wpdb;
    $today = brb_now()->setTime(0, 0)->format('Y-m-d H:i:s');
    $far = '9999-12-31 00:00:00';
    $items = [];
    foreach (brb_ov_bookings_between($today, $far) as $b) {
        $items[] = ['kind' => 'booking', 'start_dt' => $b->start_dt, 'name' => (string) $b->name, 'status' => (string) $b->status, 'label' => brb_status_label($b->status), 'row' => $b];
    }
    $holds = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . brb_hold_table() . ' WHERE end_dt > %s' . brb_hold_live_sql() . ' ORDER BY start_dt ASC, id ASC LIMIT 500',
        $today
    ));
    foreach ($holds as $h) {
        $it = brb_ov_hold_item($h);
        $items[] = ['kind' => 'hold', 'start_dt' => $it['start_dt'], 'name' => $it['name'], 'status' => $it['type'], 'label' => $it['label'], 'row' => $it];
    }
    $todayYmd = substr($today, 0, 10);
    foreach (brb_blackouts() as $ymd) {
        if ($ymd < $todayYmd) continue;
        $it = brb_ov_blackout_item($ymd);
        $items[] = ['kind' => 'hold', 'start_dt' => $it['start_dt'], 'name' => $it['name'], 'status' => 'blocked', 'label' => $it['label'], 'row' => $it];
    }
    usort($items, function ($a, $b) { return strcmp($a['start_dt'], $b['start_dt']); });
    return array_slice($items, 0, $limit);
}

/* ---------- formatting ---------- */

function brb_ov_tel_href($phone) {
    $digits = preg_replace('/[^0-9+]/', '', (string) $phone);
    return $digits === '' ? '' : 'tel:' . $digits;
}
function brb_ov_time_span($start_dt, $end_dt) {
    try {
        $s = new DateTimeImmutable($start_dt, brb_tz());
        $e = new DateTimeImmutable($end_dt, brb_tz());
    } catch (Exception $ex) {
        return '';
    }
    if ($s->format('H:i') === '00:00' && in_array($e->format('H:i'), ['00:00', '23:59'], true)) return 'All day';
    $span = $s->format('g:i A') . ' – ' . $e->format('g:i A');
    if ($e->format('Y-m-d') !== $s->format('Y-m-d') && !($e->format('H:i') === '00:00' && $e->modify('-1 day')->format('Y-m-d') === $s->format('Y-m-d'))) {
        $span = $s->format('M j, g:i A') . ' – ' . $e->format('M j, g:i A');
    }
    return $span;
}
/**
 * Door, lock, gate, keypad, password and similar entry codes are never shown,
 * even when someone typed one into a notes field. Works on the whole field, so
 * a code on the line after its keyword ("Door code:\n4821") is caught too.
 *  - Any code word, then separators, spaces, line breaks or a few filler words
 *    ("is", "with", "to"), then a token with 2+ digits: the token is hidden.
 *  - A strong code word (code, passcode, password, pin, combo) followed by
 *    ":", "=" or "is", then a letter-only word: that word is hidden.
 *  - On any line that mentions a code word, every other run of 3+ digits (or a
 *    digit-bearing token) is hidden too.
 * Dates, times and dollar amounts are never treated as codes, and the original
 * line breaks are kept exactly. Underscored keys (door_code, entry_code=) and
 * JSON ("door_code":"4821") count as code words.
 */
function brb_ov_redact_codes($text) {
    $text = (string) $text;
    if ($text === '') return '';
    $weak = 'doors?|locks?|lock\\s*box(?:es)?|keypads?|key\\s*pad|keybox|key\\s*box|keys?|keycode|smart\\s*lock|deadbolt|gates?|garage|alarm|access|entry|entrance|unlock(?:s|ed|ing)?';
    $strong = 'codes?|passcodes?|pass\\s*code|passwords?|pass\\s*word|passwd|passkey|pins?|combo|combination';
    $kw = '(?<![A-Za-z])(?:' . $strong . '|' . $weak . ')(?![A-Za-z])';
    $hide = "\x02";
    // Keep dates, times and money out of reach.
    $kept = [];
    $keep = function ($m) use (&$kept) {
        $kept[] = $m[0];
        return "\x01" . (count($kept) - 1) . "\x01";
    };
    $text = preg_replace_callback('/\\$\\s?\\d[\\d,]*(?:\\.\\d+)?/', $keep, $text);
    $text = preg_replace_callback('/(?<![A-Za-z0-9])(?:\\d{4}-\\d{1,2}-\\d{1,2}(?:[T ]\\d{1,2}:\\d{2}(?::\\d{2})?)?|\\d{1,2}\\/\\d{1,2}\\/\\d{2,4})(?![A-Za-z0-9])/', $keep, $text);
    $text = preg_replace_callback('/(?<![A-Za-z0-9:])\\d{1,2}:\\d{2}(?::\\d{2})?(?:\\s?[AaPp]\\.?[Mm]\\.?(?![A-Za-z]))?(?![0-9:])/', $keep, $text);
    $text = preg_replace_callback('/(?<![A-Za-z])(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\\.?\\s+\\d{1,2}(?:st|nd|rd|th)?(?:,?\\s+\\d{4})?(?![0-9])/', $keep, $text);
    // Placeholders hide their digits as \x01N\x01; shield them from the digit rules.
    $text = preg_replace_callback("/\x01(\\d+)\x01/", function ($m) {
        return "\x01" . strtr($m[1], '0123456789', 'abcdefghij') . "\x01";
    }, $text);
    // 1) code word ... next token with 2+ digits, across line breaks.
    $gap = '(?:[\\s:=#"\'_.,;()\\[\\]{}<>*\\-]|(?<![A-Za-z])(?:is|are|was|be|set|to|with|number|num|no)(?![A-Za-z]))*';
    $text = preg_replace_callback('/' . $kw . $gap . '\\K[#*]?[A-Za-z]{0,4}\\d(?:[A-Za-z0-9#*]|-(?=[A-Za-z0-9])|[ ](?=\\d))*/i', function ($m) use ($hide) {
        return preg_match_all('/\\d/', $m[0]) >= 2 ? $hide : $m[0];
    }, $text);
    // 2) strong code word ":" / "=" / "is" then a letter-only code.
    $stop = '(?:the|a|an|on|in|at|by|for|from|and|or|not|no|none|tbd|same|below|above|attached|sent|given|inside|outside|yes|hidden)(?![A-Za-z])';
    $text = preg_replace('/(?<![A-Za-z])(?:' . $strong . ')(?![A-Za-z])["\'\\]\\s_]*(?:[:=]|(?<![A-Za-z])is(?![A-Za-z]))[\\s"\']*\\K(?!' . $stop . ')(?<![A-Za-z0-9\\x01])[A-Za-z]{2,}(?![A-Za-z0-9\\x01])/i', $hide, $text);
    // 3) any other digit run on a line that mentions a code word.
    $text = preg_replace_callback('/[^\\r\\n]+/', function ($m) use ($kw, $hide) {
        $line = $m[0];
        if (!preg_match('/' . $kw . '/i', $line)) return $line;
        $line = preg_replace('/(?<![A-Za-z0-9])[#*]?[A-Za-z]{0,3}\\d[\\dA-Za-z#*\\-]{2,}[#*]?(?![A-Za-z0-9])/', $hide, $line);
        return preg_replace('/(?<![A-Za-z0-9])\\d(?:[\\s\\-]?\\d){2,}(?![A-Za-z0-9])/', $hide, $line);
    }, $text);
    $text = preg_replace_callback("/\x01([a-j]+)\x01/", function ($m) use ($kept) {
        return $kept[(int) strtr($m[1], 'abcdefghij', '0123456789')];
    }, $text);
    return str_replace($hide, '[code hidden]', $text);
}
function brb_ov_text_block($text) {
    $text = trim(brb_ov_redact_codes((string) $text));
    return $text === '' ? '' : nl2br(esc_html($text));
}
function brb_ov_inline($text) {
    return esc_html(brb_ov_redact_codes((string) $text));
}
function brb_ov_month_url($ym, $anchor = '') {
    $base = get_permalink();
    if (!$base) $base = home_url('/');
    $url = add_query_arg('brb_ov_m', $ym, remove_query_arg('brb_ov_m', $base));
    return $url . ($anchor !== '' ? '#' . $anchor : '');
}

/* ---------- rendering ---------- */

function brb_ov_rows_html($rows) {
    $h = '<dl class="brb-ov-dl">';
    foreach ($rows as $label => $val) {
        if ($val === null || $val === '') continue;
        $h .= '<dt>' . esc_html($label) . '</dt><dd>' . $val . '</dd>';
    }
    return $h . '</dl>';
}
function brb_ov_render_booking_card($b) {
    $badge = brb_ov_badge_class($b->status);
    $h = '<article class="brb-ov-card">';
    $h .= '<div class="brb-ov-card-top"><span class="brb-ov-time">' . esc_html(brb_ov_time_span($b->start_dt, $b->end_dt)) . '</span>';
    $h .= '<span class="brb-ov-badge brb-ov-badge-' . esc_attr($badge) . '">' . esc_html(brb_status_label($b->status)) . '</span></div>';
    $h .= '<h4 class="brb-ov-name">' . brb_ov_inline($b->name) . '</h4>';

    // Contact and event: the admin booking screen's detail rows, in its order.
    $rows = [];
    $rows['Ref'] = esc_html(brb_ref($b));
    $rows['When'] = esc_html(brb_when($b));
    $tel = brb_ov_tel_href($b->phone ?? '');
    $phone = trim((string) ($b->phone ?? ''));
    $rows['Phone'] = $phone === '' ? '' : ($tel !== '' ? '<a href="' . esc_url($tel, ['tel']) . '">' . esc_html($phone) . '</a>' : esc_html($phone));
    $email = trim((string) ($b->email ?? ''));
    if ($email !== '' && is_email($email) && sanitize_email($email) === $email) {
        $rows['Email'] = '<a href="' . esc_url('mailto:' . $email, ['mailto']) . '">' . esc_html($email) . '</a>';
    } else {
        $rows['Email'] = esc_html($email);
    }
    $rows['Estimated guests'] = esc_html((string) (int) $b->guests);
    $type = brb_event_type_label($b->event_type ?? '');
    $rows['Event type'] = esc_html($type);
    $rows['Free admission'] = (($b->free_admission ?? '') === '1') ? 'Acknowledged' : '—';
    $alcohol = brb_yes_no_label($b->alcohol ?? '');
    $rows['Alcohol served'] = esc_html($alcohol);
    if (($b->public_event ?? '') === 'no') $rows['Private, invitation-only'] = 'Yes';
    elseif (($b->public_event ?? '') === 'yes') $rows['Private, invitation-only'] = 'No, it is open to the public';
    if (trim((string) ($b->end_time ?? '')) !== '') $rows['Planned end time'] = esc_html(brb_end_time_label($b->end_time));
    $rows['Host age bracket'] = esc_html(brb_host_age_label($b->host_age ?? ''));
    $rows['Source'] = esc_html(brb_source_label($b->source ?? ''));
    $rows['Walk-through'] = !empty($b->walkthrough_done) ? 'Completed' : 'Not yet';
    $rows['Setup day'] = !empty($b->setup_addon) ? 'Added' : 'No';
    $rows['Takedown day'] = !empty($b->takedown_addon) ? 'Added' : 'No';
    $rows['Date hold'] = esc_html(brb_date_hold_state($b));
    $created = brb_format_utc_mysql($b->created ?? '');
    $rows['Requested'] = esc_html($created);
    $h .= brb_ov_rows_html($rows);
    if (!empty($b->attention)) $h .= '<p class="brb-ov-attn">' . brb_ov_inline($b->attention) . '</p>';
    $about = brb_ov_text_block($b->event_about ?? '');
    if ($about !== '') $h .= '<div class="brb-ov-note"><strong>About the event</strong><p>' . $about . '</p></div>';
    $notes = brb_ov_text_block($b->notes ?? '');
    if ($notes !== '') $h .= '<div class="brb-ov-note"><strong>Notes</strong><p>' . $notes . '</p></div>';

    // Money: the admin Money box, charges, and payments recorded.
    $money = brb_booking_money($b);
    $m = [];
    foreach ($money['rows'] as $label => $val) $m[$label] = esc_html((string) $val);
    $m['Paid toward rental'] = esc_html(brb_money(round((float) $b->amount_paid, 2)));
    if (brb_price_is_set($b) && ((float) $b->cleaning > 0.009 || (float) $b->tax > 0.009)) {
        $m['Subtotal'] = esc_html(brb_money($b->subtotal));
        $m['Cleaning'] = esc_html(brb_money($b->cleaning));
        $m['Tax'] = esc_html(brb_money($b->tax));
    }
    $h .= '<div class="brb-ov-sec"><strong>Money</strong>' . brb_ov_rows_html($m);
    $lines = brb_lines($b->id);
    if ($lines) {
        $cov = brb_line_coverage($b);
        $h .= '<p class="brb-ov-mini">Charges</p><ul class="brb-ov-lines">';
        foreach ($lines as $line) {
            $state = brb_line_state_text($cov[(int) $line->id]['paid'] ?? 0, $cov[(int) $line->id]['amount'] ?? $line->amount);
            $h .= '<li><span>' . brb_ov_inline($line->label) . ($state !== '' ? ' <em>(' . esc_html($state) . ')</em>' : '') . '</span><span>' . esc_html(brb_money($line->amount)) . '</span></li>';
        }
        $h .= '</ul><p class="brb-ov-mini">Guest total ' . esc_html(brb_money(brb_nonrefundable_total($b->id))) . ' · Refundable damage deposit ' . esc_html(brb_money(brb_booking_damage_figure($b))) . '</p>';
    }
    $pays = brb_payments($b->id);
    $h .= '<p class="brb-ov-mini">Payments recorded</p>';
    if (!$pays) {
        $h .= '<p class="brb-ov-none">No payments recorded yet.</p>';
    } else {
        $h .= '<ul class="brb-ov-lines">';
        foreach ($pays as $p) {
            $bits = [brb_pay_method_label($p->method ?? 'check')];
            if ((float) ($p->fee ?? 0) > 0.009) $bits[] = 'fee ' . brb_money($p->fee);
            if (trim((string) $p->check_number) !== '') $bits[] = 'check #' . $p->check_number;
            if (trim((string) ($p->stripe_id ?? '')) !== '') $bits[] = 'Stripe ' . $p->stripe_id;
            $h .= '<li><span>' . esc_html((string) $p->received_on) . ' · ' . esc_html(implode(' · ', $bits));
            if (trim((string) $p->notes) !== '') $h .= '<br><small>' . brb_ov_text_block($p->notes) . '</small>';
            $h .= '</span><span>' . esc_html(brb_money($p->amount)) . '</span></li>';
        }
        $h .= '</ul>';
    }
    $h .= '</div>';

    // Staff follow-up log and edit history (read-only).
    $fu = function_exists('brb_followup_entries') ? brb_followup_entries($b) : [];
    if ($fu) {
        $h .= '<div class="brb-ov-sec"><strong>Follow-up</strong><ul class="brb-ov-hist">';
        foreach ($fu as $e) {
            $h .= '<li>' . esc_html(brb_followup_summary($e));
            if (trim((string) ($e['note'] ?? '')) !== '') $h .= '<br><small>' . brb_ov_text_block($e['note']) . '</small>';
            $h .= '</li>';
        }
        $h .= '</ul></div>';
    }
    $hist = brb_audit_entries($b->audit_log ?? '');
    if ($hist) {
        $h .= '<details class="brb-ov-sec"><summary>Edit history (' . (int) count($hist) . ')</summary><ol class="brb-ov-hist">';
        foreach ($hist as $line) $h .= '<li>' . brb_ov_inline($line) . '</li>';
        $h .= '</ol></details>';
    }
    $h .= '</article>';
    return $h;
}
function brb_ov_render_hold_card($hold) {
    $h = '<article class="brb-ov-card brb-ov-card-hold brb-ov-hold-' . esc_attr($hold['type']) . '">';
    $h .= '<div class="brb-ov-card-top"><span class="brb-ov-time">' . esc_html(brb_ov_time_span($hold['start_dt'], $hold['end_dt'])) . '</span>';
    $h .= '<span class="brb-ov-badge brb-ov-badge-' . esc_attr($hold['type']) . '">' . esc_html($hold['label']) . '</span></div>';
    if ($hold['name'] !== '') $h .= '<h4 class="brb-ov-name">' . brb_ov_inline($hold['name']) . '</h4>';
    $rows = [
        'Calendar entry' => esc_html($hold['label']),
        'From' => esc_html(brb_format_local_mysql($hold['start_dt'])),
        'To' => esc_html(brb_format_local_mysql($hold['end_dt'])),
        'Added' => esc_html(brb_format_utc_mysql($hold['created'] ?? '')),
    ];
    $h .= brb_ov_rows_html($rows);
    $notes = brb_ov_text_block($hold['notes'] ?? '');
    if ($notes !== '') $h .= '<div class="brb-ov-note"><strong>Notes</strong><p>' . $notes . '</p></div>';
    $h .= '</article>';
    return $h;
}
function brb_ov_css() {
    return '<style id="brb-ov-css">'
        . '.brb-ov{max-width:720px;margin:0 auto;font-size:16px;line-height:1.4;color:#1d1d1f;overflow-wrap:anywhere}'
        . '.brb-ov *{box-sizing:border-box}'
        . '.brb-ov h2{font-size:1.25rem;margin:0 0 .25rem}.brb-ov h3{font-size:1.05rem;margin:1.25rem 0 .5rem}'
        . '.brb-ov-sub{margin:0 0 .75rem;color:#555;font-size:.9rem}'
        . '.brb-ov-nav{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin:.5rem 0}'
        . '.brb-ov-nav a{display:inline-block;padding:.6rem .9rem;border:1px solid #ccc;border-radius:8px;text-decoration:none;min-width:44px;text-align:center}'
        . '.brb-ov-nav .brb-ov-off{visibility:hidden}.brb-ov-month{font-weight:700;font-size:1.1rem}'
        . '.brb-ov-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:3px;touch-action:pan-y}'
        . '.brb-ov-dow{font-size:.7rem;text-align:center;color:#666;padding:2px 0}'
        . '.brb-ov-day{display:flex;flex-direction:column;align-items:center;justify-content:flex-start;min-height:48px;padding:4px 1px;border-radius:6px;text-decoration:none;color:inherit;background:#f1f5f1;font-size:.85rem}'
        . '.brb-ov-day b{font-weight:600}.brb-ov-day small{font-size:.6rem;line-height:1.1;margin-top:2px}'
        . '.brb-ov-free{background:#eef6ee}.brb-ov-booked{background:#c62828;color:#fff}.brb-ov-held{background:#f9a825;color:#1d1d1f}.brb-ov-blocked{background:#555;color:#fff}'
        . '.brb-ov-past{opacity:.55}.brb-ov-today{outline:2px solid #1565c0;outline-offset:-2px}.brb-ov-empty{background:transparent}'
        . '.brb-ov-req{display:block;width:6px;height:6px;border-radius:50%;background:#1565c0;margin-top:2px}'
        . '.brb-ov-legend{display:flex;flex-wrap:wrap;gap:.5rem .9rem;font-size:.8rem;margin:.5rem 0 0;padding:0;list-style:none}'
        . '.brb-ov-legend i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:4px;vertical-align:-1px}'
        . '.brb-ov-dayblock{scroll-margin-top:80px;margin:1rem 0;padding-top:.25rem;border-top:1px solid #ddd}'
        . '.brb-ov-dayblock:target{background:#fffbe6}'
        . '.brb-ov-dayhead{display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap}'
        . '.brb-ov-card{border:1px solid #ddd;border-radius:10px;padding:.75rem;margin:.5rem 0;background:#fff}'
        . '.brb-ov-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem;flex-wrap:wrap;font-size:.9rem}'
        . '.brb-ov-name{margin:.35rem 0;font-size:1.05rem}'
        . '.brb-ov-dl{display:grid;grid-template-columns:auto 1fr;gap:.2rem .75rem;margin:0}.brb-ov-dl dt{color:#666}.brb-ov-dl dd{margin:0}'
        . '.brb-ov-dl a{display:inline-block;padding:.15rem 0}'
        . '.brb-ov-note{margin-top:.5rem;font-size:.92rem}.brb-ov-note p{margin:.2rem 0 0}'
        . '.brb-ov-badge{display:inline-block;padding:.1rem .5rem;border-radius:999px;font-size:.75rem;font-weight:600;background:#e3f2fd;color:#0d47a1;white-space:nowrap}'
        . '.brb-ov-badge-paid{background:#e8f5e9;color:#1b5e20}.brb-ov-badge-deposit{background:#e0f2f1;color:#004d40}'
        . '.brb-ov-badge-approved{background:#fff3e0;color:#8a4b00}.brb-ov-badge-review{background:#e3f2fd;color:#0d47a1}'
        . '.brb-ov-badge-blocked{background:#eee;color:#333}.brb-ov-badge-hold{background:#fff8e1;color:#6d4c00}.brb-ov-badge-reserved{background:#fde0dc;color:#8e1b10}'
        . '.brb-ov-badge-booked{background:#fde0dc;color:#8e1b10}.brb-ov-badge-held{background:#fff8e1;color:#6d4c00}.brb-ov-badge-free{background:#eef6ee;color:#1b5e20}'
        . '.brb-ov-list{list-style:none;margin:0;padding:0}.brb-ov-list li{border-bottom:1px solid #eee}'
        . '.brb-ov-list a{display:flex;justify-content:space-between;gap:.5rem;padding:.55rem 0;text-decoration:none;color:inherit;flex-wrap:wrap}'
        . '.brb-ov-none{color:#666}'
        . '.brb-ov-list summary{display:flex;justify-content:space-between;gap:.5rem;padding:.6rem 0;cursor:pointer;flex-wrap:wrap;list-style:none}.brb-ov-list summary::-webkit-details-marker{display:none}'
        . '.brb-ov-sec{margin-top:.6rem;padding-top:.5rem;border-top:1px dashed #ddd;font-size:.92rem}.brb-ov-sec summary{cursor:pointer;font-weight:600}'
        . '.brb-ov-mini{margin:.5rem 0 .2rem;font-weight:600;font-size:.85rem;color:#444}'
        . '.brb-ov-lines{list-style:none;margin:0;padding:0}.brb-ov-lines li{display:flex;justify-content:space-between;gap:.5rem;padding:.2rem 0;border-bottom:1px solid #f0f0f0}'
        . '.brb-ov-hist{margin:.3rem 0 0;padding-left:1.1rem;font-size:.85rem}.brb-ov-attn{background:#fdecea;color:#8e1b10;padding:.4rem .6rem;border-radius:6px;font-weight:600}'
        . '</style>';
}
function brb_ov_swipe_js() {
    // Navigation only: a horizontal swipe on the calendar follows the prev/next link.
    return '<script>(function(){var g=document.getElementById("brb-ov-grid");if(!g)return;var x=null,y=null;'
        . 'g.addEventListener("touchstart",function(e){var t=e.changedTouches[0];x=t.clientX;y=t.clientY;},{passive:true});'
        . 'g.addEventListener("touchend",function(e){if(x===null)return;var t=e.changedTouches[0],dx=t.clientX-x,dy=t.clientY-y;x=null;'
        . 'if(Math.abs(dx)<60||Math.abs(dx)<Math.abs(dy)*1.5)return;var a=document.querySelector(dx<0?".brb-ov-next":".brb-ov-prev");'
        . 'if(a&&a.href)window.location.href=a.href;},{passive:true});})();</script>';
}
function brb_ov_render() {
    $tz = brb_tz();
    $ym = brb_ov_month_param();
    $first = new DateTimeImmutable($ym . '-01 00:00:00', $tz);
    $next = $first->modify('+1 month');
    $today = brb_now()->format('Y-m-d');
    $nowMonth = brb_now()->modify('first day of this month');
    $minYm = $nowMonth->modify('-' . brb_ov_months_back() . ' months')->format('Y-m');
    $maxYm = $nowMonth->modify('+' . brb_ov_months_ahead() . ' months')->format('Y-m');
    $prevYm = $first->modify('-1 month')->format('Y-m');
    $nextYm = $next->format('Y-m');

    $bookings = brb_ov_bookings_between($first->format('Y-m-d H:i:s'), $next->format('Y-m-d H:i:s'));
    $days = [];
    for ($d = $first; $d < $next; $d = $d->modify('+1 day')) {
        $ymd = $d->format('Y-m-d');
        $dayB = array_values(array_filter($bookings, function ($b) use ($ymd) { return brb_ov_booking_on_day($b, $ymd); }));
        $holds = brb_ov_day_holds($ymd);
        $status = brb_ov_day_status($ymd, $dayB);
        $req = false;
        foreach ($dayB as $b) if (!brb_ov_is_blocking($b->status)) $req = true;
        $days[$ymd] = ['date' => $d, 'bookings' => $dayB, 'holds' => $holds, 'status' => $status, 'request' => $req];
    }

    $h = brb_ov_css();
    $h .= '<div class="brb-ov" id="brb-ov">';
    $h .= '<h2>Bookings calendar</h2><p class="brb-ov-sub">View only. Tap a day to see its bookings. Times are Central.</p>';
    $h .= '<div class="brb-ov-nav">';
    $h .= $prevYm >= $minYm ? '<a class="brb-ov-prev" href="' . esc_url(brb_ov_month_url($prevYm)) . '" aria-label="Previous month">&larr;</a>' : '<span class="brb-ov-off">&larr;</span>';
    $h .= '<span class="brb-ov-month">' . esc_html($first->format('F Y')) . '</span>';
    $h .= $nextYm <= $maxYm ? '<a class="brb-ov-next" href="' . esc_url(brb_ov_month_url($nextYm)) . '" aria-label="Next month">&rarr;</a>' : '<span class="brb-ov-off">&rarr;</span>';
    $h .= '</div>';
    $h .= '<div class="brb-ov-grid" id="brb-ov-grid" role="grid" aria-label="' . esc_attr($first->format('F Y')) . '">';
    foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow) $h .= '<div class="brb-ov-dow">' . esc_html($dow) . '</div>';
    for ($i = 0, $pad = (int) $first->format('w'); $i < $pad; $i++) $h .= '<div class="brb-ov-day brb-ov-empty" aria-hidden="true"></div>';
    foreach ($days as $ymd => $day) {
        $cls = 'brb-ov-day brb-ov-' . $day['status'];
        if ($ymd < $today) $cls .= ' brb-ov-past';
        if ($ymd === $today) $cls .= ' brb-ov-today';
        $has = $day['bookings'] || $day['holds'];
        $inner = '<b>' . esc_html($day['date']->format('j')) . '</b><small>' . esc_html(brb_ov_day_label($day['status'])) . '</small>';
        if ($day['request']) $inner .= '<span class="brb-ov-req" title="Request in review"></span>';
        $label = $day['date']->format('l, F j') . ': ' . brb_ov_day_label($day['status']) . ($day['request'] ? ', request in review' : '');
        if ($has) $h .= '<a class="' . esc_attr($cls) . '" href="#brb-ov-d-' . esc_attr($ymd) . '" aria-label="' . esc_attr($label) . '">' . $inner . '</a>';
        else $h .= '<div class="' . esc_attr($cls) . '" aria-label="' . esc_attr($label) . '">' . $inner . '</div>';
    }
    $h .= '</div>';
    $h .= '<ul class="brb-ov-legend"><li><i class="brb-ov-booked"></i>Booked</li><li><i class="brb-ov-held"></i>Held</li><li><i class="brb-ov-blocked"></i>Blocked</li><li><i class="brb-ov-free"></i>Free</li><li><span class="brb-ov-req" style="display:inline-block;margin-right:4px"></span>Request in review</li></ul>';

    $h .= '<h3>' . esc_html($first->format('F Y')) . ' by day</h3>';
    $any = false;
    foreach ($days as $ymd => $day) {
        if (!$day['bookings'] && !$day['holds']) continue;
        $any = true;
        $h .= '<section class="brb-ov-dayblock" id="brb-ov-d-' . esc_attr($ymd) . '">';
        $h .= '<div class="brb-ov-dayhead"><h3>' . esc_html($day['date']->format('D, M j, Y')) . '</h3><span class="brb-ov-badge brb-ov-badge-' . esc_attr($day['status']) . '">' . esc_html(brb_ov_day_label($day['status'])) . '</span></div>';
        foreach ($day['bookings'] as $b) $h .= brb_ov_render_booking_card($b);
        foreach ($day['holds'] as $hold) $h .= brb_ov_render_hold_card($hold);
        $h .= '</section>';
    }
    if (!$any) $h .= '<p class="brb-ov-none">Nothing on the calendar this month.</p>';

    $h .= '<h3>Upcoming</h3><p class="brb-ov-sub">Tap a line to see everything.</p>';
    $items = brb_ov_upcoming_items();
    if (!$items) {
        $h .= '<p class="brb-ov-none">No upcoming bookings or holds.</p>';
    } else {
        $h .= '<ul class="brb-ov-list">';
        foreach ($items as $it) {
            $s = new DateTimeImmutable($it['start_dt'], $tz);
            $badge = $it['kind'] === 'booking' ? brb_ov_badge_class($it['status']) : $it['status'];
            $h .= '<li><details><summary><span>' . esc_html($s->format('D, M j, Y')) . ($it['name'] !== '' ? ' · ' . brb_ov_inline($it['name']) : '') . '</span><span class="brb-ov-badge brb-ov-badge-' . esc_attr($badge) . '">' . esc_html($it['label']) . '</span></summary>';
            $h .= $it['kind'] === 'booking' ? brb_ov_render_booking_card($it['row']) : brb_ov_render_hold_card($it['row']);
            $h .= '</details></li>';
        }
        $h .= '</ul>';
    }
    $h .= '</div>';
    $h .= brb_ov_swipe_js();
    return $h;
}
function brb_ov_block_mail($pre) { return false; }
function brb_ov_shortcode($atts = [], $content = null) {
    $post = get_post();
    if (!$post) return '';
    if (brb_ov_context_blocked()) return '';
    if (post_password_required($post)) return '';
    if ((string) $post->post_password === '') {
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return '<p class="brb-ov-admin-note">This bookings view only shows on a password-protected page. Set a page password to use it.</p>';
        }
        return '';
    }
    add_filter('pre_wp_mail', 'brb_ov_block_mail', PHP_INT_MAX);
    try {
        $html = brb_ov_render();
    } finally {
        remove_filter('pre_wp_mail', 'brb_ov_block_mail', PHP_INT_MAX);
    }
    return $html;
}
add_shortcode(BRB_OV_TAG, 'brb_ov_shortcode');

/* ---------- privacy filters ---------- */

function brb_ov_current_is_owner_page() {
    if (!is_singular()) return false;
    return brb_ov_is_owner_post(get_queried_object_id());
}
function brb_ov_send_headers() {
    if (!brb_ov_current_is_owner_page() || headers_sent()) return;
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Referrer-Policy: no-referrer');
}
add_action('template_redirect', 'brb_ov_send_headers', 0);

function brb_ov_wp_robots($robots) {
    if (!brb_ov_current_is_owner_page()) return $robots;
    unset($robots['index'], $robots['follow'], $robots['max-image-preview']);
    $robots['noindex'] = true;
    $robots['nofollow'] = true;
    $robots['noarchive'] = true;
    return $robots;
}
add_filter('wp_robots', 'brb_ov_wp_robots', 999);
add_filter('wpseo_robots', function ($robots) {
    return brb_ov_current_is_owner_page() ? 'noindex, nofollow' : $robots;
}, 999);
add_filter('wpseo_robots_array', function ($robots) {
    if (!brb_ov_current_is_owner_page()) return $robots;
    return array_merge((array) $robots, ['index' => 'noindex', 'follow' => 'nofollow']);
}, 999);
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $brb_ov_f) {
    add_filter($brb_ov_f, function ($v) { return brb_ov_current_is_owner_page() ? '' : $v; }, 999);
}
unset($brb_ov_f);
add_action('wp_head', function () {
    if (brb_ov_current_is_owner_page()) echo '<meta name="referrer" content="no-referrer">' . "\n";
}, 1);

// Sitemaps: core and Yoast.
add_filter('wp_sitemaps_posts_query_args', function ($args) {
    $ids = brb_ov_post_ids();
    if ($ids) $args['post__not_in'] = array_values(array_unique(array_merge((array) ($args['post__not_in'] ?? []), $ids)));
    return $args;
});
add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($ids) {
    return array_values(array_unique(array_merge(array_map('intval', (array) $ids), brb_ov_post_ids())));
});

// Search (front end and REST /wp/v2/search) and feeds.
add_action('pre_get_posts', function ($q) {
    if (is_admin() && !wp_doing_ajax()) return;
    if (!$q->is_search() && !$q->is_feed()) return;
    $ids = brb_ov_post_ids();
    if (!$ids) return;
    $q->set('post__not_in', array_values(array_unique(array_merge((array) $q->get('post__not_in'), $ids))));
});

// Automatic page lists (wp_list_pages, page-list block, get_pages on the front end).
add_filter('wp_list_pages_excludes', function ($ex) {
    return array_values(array_unique(array_merge(array_map('intval', (array) $ex), brb_ov_post_ids())));
});
add_filter('get_pages', function ($pages) {
    if (is_admin()) return $pages;
    $ids = brb_ov_post_ids();
    if (!$ids) return $pages;
    return array_values(array_filter((array) $pages, function ($p) use ($ids) { return !in_array((int) $p->ID, $ids, true); }));
});

// oEmbed: never embed the page.
add_filter('oembed_request_post_id', function ($post_id) {
    return ($post_id && in_array((int) $post_id, brb_ov_post_ids(), true)) ? 0 : $post_id;
});
add_filter('embed_oembed_discover', function ($discover) {
    return brb_ov_current_is_owner_page() ? false : $discover;
});
add_action('wp_head', function () {
    if (brb_ov_current_is_owner_page()) remove_action('wp_head', 'wp_oembed_add_discovery_links');
}, 0);

// REST: the rendered content and excerpt of these pages are always empty.
function brb_ov_rest_prepare($response, $post, $request) {
    if (!brb_ov_is_owner_post($post)) return $response;
    $data = $response->get_data();
    foreach (['content', 'excerpt'] as $k) {
        if (isset($data[$k]) && is_array($data[$k]) && array_key_exists('rendered', $data[$k])) $data[$k]['rendered'] = '';
    }
    $response->set_data($data);
    return $response;
}
add_filter('rest_prepare_page', 'brb_ov_rest_prepare', 999, 3);
add_filter('rest_prepare_post', 'brb_ov_rest_prepare', 999, 3);

// No "Protected: " prefix on the owner page's title.
add_filter('protected_title_format', function ($format, $post = null) {
    return ($post && brb_ov_is_owner_post($post)) ? '%s' : $format;
}, 10, 2);
// The theme footer's "Ask Walter" button opens the chat, which is not loaded here.
add_action('wp_head', function () {
    if (brb_ov_current_is_owner_page()) echo '<style id="brb-ov-no-walter">.footer-ask-wrap,.brb-chat,#ask-walter{display:none!important}</style>' . "\n";
}, 99);
