<?php
if (!defined('ABSPATH')) exit;

function brb_default_rate_ranges() {
    return [
        ['start' => '2026-12-01', 'end' => '2026-12-31', 'sun' => '', 'mon_thu' => '1200', 'fri_sat' => '1400'],
        ['start' => '2026-11-24', 'end' => '2026-11-26', 'sun' => '1500', 'mon_thu' => '1500', 'fri_sat' => '1500'],
        ['start' => '2026-12-24', 'end' => '2026-12-24', 'sun' => '1500', 'mon_thu' => '1500', 'fri_sat' => '1500'],
    ];
}

function brb_rate_amount($value) {
    $value = trim((string) $value);
    if ($value === '' || !is_numeric($value)) return '';
    $n = round((float) $value, 2);
    if ($n <= 0) return '';
    if (abs($n - round($n)) < 0.001) return (string) (int) round($n);
    return number_format($n, 2, '.', '');
}

function brb_rate_date($value) {
    $value = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
    $p = explode('-', $value);
    if (!checkdate((int) $p[1], (int) $p[2], (int) $p[0])) return '';
    return $value;
}

function brb_rate_row($row) {
    if (!is_array($row)) return null;
    $start = brb_rate_date($row['start'] ?? '');
    $end = brb_rate_date($row['end'] ?? '');
    if ($start === '' || $end === '' || $end < $start) return null;
    return [
        'start' => $start,
        'end' => $end,
        'sun' => brb_rate_amount($row['sun'] ?? ''),
        'mon_thu' => brb_rate_amount($row['mon_thu'] ?? ''),
        'fri_sat' => brb_rate_amount($row['fri_sat'] ?? ''),
    ];
}

function brb_rate_ranges_from_json($json) {
    $rows = json_decode((string) $json, true);
    if (!is_array($rows)) return brb_default_rate_ranges();
    $out = [];
    foreach ($rows as $row) {
        $clean = brb_rate_row($row);
        if ($clean) $out[] = $clean;
    }
    return $out;
}

function brb_rate_ranges_from_post($post) {
    $starts = isset($post['range_start']) && is_array($post['range_start']) ? $post['range_start'] : [];
    $ends = isset($post['range_end']) && is_array($post['range_end']) ? $post['range_end'] : [];
    $suns = isset($post['range_sun']) && is_array($post['range_sun']) ? $post['range_sun'] : [];
    $weeks = isset($post['range_mon_thu']) && is_array($post['range_mon_thu']) ? $post['range_mon_thu'] : [];
    $weekends = isset($post['range_fri_sat']) && is_array($post['range_fri_sat']) ? $post['range_fri_sat'] : [];
    $out = [];
    $n = max(count($starts), count($ends));
    for ($i = 0; $i < $n; $i++) {
        $clean = brb_rate_row([
            'start' => $starts[$i] ?? '',
            'end' => $ends[$i] ?? '',
            'sun' => $suns[$i] ?? '',
            'mon_thu' => $weeks[$i] ?? '',
            'fri_sat' => $weekends[$i] ?? '',
        ]);
        if ($clean) $out[] = $clean;
    }
    return $out;
}

function brb_rate_ranges() {
    return brb_rate_ranges_from_json(brb_opt('rate_ranges'));
}

function brb_chat_apply_rate_settings($new, $post) {
    if (!is_array($new)) $new = [];
    if (!is_array($post)) $post = [];
    $new['quote_prices'] = (!empty($post['quote_prices']) && (string) $post['quote_prices'] !== '0') ? '1' : '0';
    $sun = brb_rate_amount($post['rate_sun_thu'] ?? '');
    $fri = brb_rate_amount($post['rate_fri_sat'] ?? '');
    $new['rate_sun_thu'] = $sun !== '' ? $sun : '850';
    $new['rate_fri_sat'] = $fri !== '' ? $fri : '1400';
    if (isset($post['range_start']) && is_array($post['range_start'])) {
        $rows = brb_rate_ranges_from_post($post);
    } else {
        $rows = brb_rate_ranges_from_json($new['rate_ranges'] ?? '');
    }
    $new['rate_ranges'] = wp_json_encode($rows);
    return $new;
}

function brb_quote_prices_enabled() {
    return (string) brb_opt('quote_prices') !== '0';
}

function brb_estimate_amount($ymd) {
    $ymd = brb_rate_date($ymd);
    if ($ymd === '') return 0.0;
    $dt = new DateTimeImmutable($ymd . ' 12:00:00', brb_tz());
    $dow = (int) $dt->format('w');
    $key = $dow === 0 ? 'sun' : (($dow === 5 || $dow === 6) ? 'fri_sat' : 'mon_thu');
    $ranges = brb_rate_ranges();
    usort($ranges, function ($a, $b) {
        $sa = (new DateTimeImmutable($a['start']))->diff(new DateTimeImmutable($a['end']))->days;
        $sb = (new DateTimeImmutable($b['start']))->diff(new DateTimeImmutable($b['end']))->days;
        if ($sa === $sb) return strcmp($a['start'], $b['start']);
        return $sa <=> $sb;
    });
    foreach ($ranges as $row) {
        if ($ymd < $row['start'] || $ymd > $row['end']) continue;
        if ($row[$key] !== '') return (float) $row[$key];
    }
    $base = ($key === 'fri_sat') ? brb_opt('rate_fri_sat') : brb_opt('rate_sun_thu');
    $n = (float) $base;
    if ($n <= 0) $n = ($key === 'fri_sat') ? 1400 : 850;
    return $n;
}

function brb_admin_quote_rates_fields() {
    $o = brb_opt();
    $rows = brb_rate_ranges();
    $rows[] = ['start' => '', 'end' => '', 'sun' => '', 'mon_thu' => '', 'fri_sat' => ''];
    $rows[] = ['start' => '', 'end' => '', 'sun' => '', 'mon_thu' => '', 'fri_sat' => ''];
    echo '<h2>Assistant price estimates</h2>';
    echo '<p class="description">These daily rates are estimates for the chat assistant only. They are not shown as a price list on the website. The Boardroom does not offer overnight stays.</p>';
    echo '<p><label><input type="checkbox" name="quote_prices" value="1"' . checked((string) $o['quote_prices'] !== '0', true, false) . '> Let the assistant quote estimated prices</label></p>';
    echo '<table class="form-table"><tr><th><label for="rate_sun_thu">Sunday to Thursday</label></th><td><input type="number" min="0" step="1" name="rate_sun_thu" id="rate_sun_thu" value="' . esc_attr($o['rate_sun_thu']) . '"></td></tr>';
    echo '<tr><th><label for="rate_fri_sat">Friday and Saturday</label></th><td><input type="number" min="0" step="1" name="rate_fri_sat" id="rate_fri_sat" value="' . esc_attr($o['rate_fri_sat']) . '"></td></tr></table>';
    echo '<table class="widefat" style="max-width:920px"><thead><tr><th>Start</th><th>End</th><th>Sunday</th><th>Monday to Thursday</th><th>Friday and Saturday</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td><input type="date" name="range_start[]" value="' . esc_attr($row['start']) . '"></td>';
        echo '<td><input type="date" name="range_end[]" value="' . esc_attr($row['end']) . '"></td>';
        echo '<td><input type="number" min="0" step="1" name="range_sun[]" value="' . esc_attr($row['sun']) . '"></td>';
        echo '<td><input type="number" min="0" step="1" name="range_mon_thu[]" value="' . esc_attr($row['mon_thu']) . '"></td>';
        echo '<td><input type="number" min="0" step="1" name="range_fri_sat[]" value="' . esc_attr($row['fri_sat']) . '"></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p class="description">A blank cell in a date range uses the next matching range, then the Sunday to Thursday or Friday and Saturday rate. A shorter range wins over a longer one. Leave a row blank to ignore it.</p>';
}

function brb_chat_months() {
    return [
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8, 'september' => 9, 'sept' => 9, 'sep' => 9, 'october' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12,
    ];
}

function brb_chat_make_date($year, $month, $day) {
    $year = (int) $year;
    $month = (int) $month;
    $day = (int) $day;
    if ($year < 100) $year += 2000;
    if (!checkdate($month, $day, $year)) return '';
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function brb_chat_infer_year($month, $day) {
    $now = brb_now()->setTime(0, 0);
    $year = (int) $now->format('Y');
    $made = brb_chat_make_date($year, $month, $day);
    if ($made === '') return '';
    $dt = new DateTimeImmutable($made . ' 00:00:00', brb_tz());
    if ($dt < $now) return brb_chat_make_date($year + 1, $month, $day);
    return $made;
}

function brb_chat_find_dates($text) {
    $found = [];
    $months = brb_chat_months();
    if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => $full) {
            $ymd = brb_chat_make_date($m[1][$i][0], $m[2][$i][0], $m[3][$i][0]);
            if ($ymd !== '') $found[] = ['date' => $ymd, 'pos' => $full[1], 'end' => $full[1] + strlen($full[0])];
        }
    }
    if (preg_match_all('/\b(january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sept|sep|oct|nov|dec)\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s*(\d{4}))?\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => $full) {
            $name = strtolower($m[1][$i][0]);
            $month = $months[$name] ?? 0;
            $day = (int) $m[2][$i][0];
            $year = $m[3][$i][0] !== '' ? (int) $m[3][$i][0] : 0;
            $ymd = $year ? brb_chat_make_date($year, $month, $day) : brb_chat_infer_year($month, $day);
            if ($ymd !== '') $found[] = ['date' => $ymd, 'pos' => $full[1], 'end' => $full[1] + strlen($full[0])];
        }
    }
    if (preg_match_all('/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => $full) {
            $ymd = brb_chat_make_date($m[3][$i][0], $m[1][$i][0], $m[2][$i][0]);
            if ($ymd !== '') $found[] = ['date' => $ymd, 'pos' => $full[1], 'end' => $full[1] + strlen($full[0])];
        }
    }
    usort($found, function ($a, $b) { return $a['pos'] <=> $b['pos']; });
    $out = [];
    $last = -1;
    foreach ($found as $row) {
        if ($row['pos'] < $last) continue;
        $out[] = $row;
        $last = $row['end'];
    }
    return $out;
}

function brb_chat_dates($text) {
    $found = brb_chat_find_dates($text);
    if (!$found) return ['dates' => [], 'too_long' => false];
    if (count($found) >= 2) {
        $between = substr($text, $found[0]['end'], $found[1]['pos'] - $found[0]['end']);
        if (preg_match('/\b(to|through|until|thru)\b|–|—|\s-\s/u', $between)) {
            $start = new DateTimeImmutable($found[0]['date'] . ' 00:00:00', brb_tz());
            $end = new DateTimeImmutable($found[1]['date'] . ' 00:00:00', brb_tz());
            if ($end < $start) {
                $swap = $start;
                $start = $end;
                $end = $swap;
            }
            $dates = [];
            for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
                $dates[] = $d->format('Y-m-d');
                if (count($dates) > 14) return ['dates' => [], 'too_long' => true];
            }
            return ['dates' => $dates, 'too_long' => false];
        }
    }
    $dates = [];
    foreach ($found as $row) {
        if (!in_array($row['date'], $dates, true)) $dates[] = $row['date'];
        if (count($dates) >= 7) break;
    }
    return ['dates' => $dates, 'too_long' => false];
}

function brb_chat_date_label($ymd) {
    $dt = new DateTimeImmutable($ymd . ' 12:00:00', brb_tz());
    return $dt->format('l, F j, Y');
}

function brb_chat_sensitive($text) {
    $t = strtolower($text);
    $other = 'air' . 'bnb';
    if (preg_match('/\b' . $other . '\b/', $t)) return 'refuse';
    if (preg_match('/\b(owner|owners)\b/', $t)) return 'refuse';
    if (preg_match('/\b(politic|election|democrat|republican|personal life|biograph|founded|history of|competitor|vrbo|who works|staff names)\b/', $t)) return 'refuse';
    if (preg_match('/\b(legal advice|lawsuit|attorney|lawyer)\b/', $t) || preg_match('/\bsue\b/', $t)) return 'legal';
    return '';
}

function brb_chat_intents($text) {
    $t = strtolower($text);
    $map = [
        'availability' => ['available', 'availability', 'blackout', 'is the date', 'open on', 'free on', 'reserved'],
        'amenities' => ['amenit', 'wifi', 'wi-fi', 'parking', 'pool table', 'kitchen', 'fridge', 'sound system', 'air conditioning', 'what is included', 'what do you have'],
        'rules' => ['rule', 'overnight', 'cook', 'stove', 'stovetop', 'tap', 'byob', 'liquor', 'alcohol', 'insurance', 'decorat', 'garbage', 'trash', 'occupan', '75', 'not set up', 'clean', 'allowed'],
        'pricing' => ['price', 'pricing', 'cost', 'how much', 'rate', 'estimate', 'quote'],
        'payment' => ['deposit', 'check', 'payment', 'pay ', 'card', 'fee', 'non-refundable', 'stripe'],
        'book' => ['book', 'reserve a', 'request a date', 'hold a date'],
        'tour' => ['tour', 'visit', 'see the space', 'walk through'],
        'contact' => ['contact', 'email', 'reach', 'get in touch', 'phone number', 'call'],
        'cancel' => ['cancel', 'cancellation'],
    ];
    $hits = [];
    foreach ($map as $intent => $words) {
        foreach ($words as $word) {
            if (strpos($t, $word) !== false) {
                $hits[] = $intent;
                break;
            }
        }
    }
    return $hits;
}

function brb_chat_refusal() {
    return 'I\'m not sure about that one, but our team would be glad to help. I can only help with The Boardroom. I can chat about availability, amenities, house rules, an estimated price for a date, the deposit, or how to book, tour, or contact us. Please use the Contact form for anything else.';
}

function brb_chat_legal_refusal() {
    return 'I\'m not able to give legal advice. I\'m happy to share what the Rental Terms say, and the signed Rental Agreement controls. For anything else, our team would be glad to help through the Contact form.';
}

function brb_chat_estimate_phrase() {
    return 'an estimate for that date; final price is confirmed in writing by our team and depends on date, headcount and features needed';
}

function brb_chat_blocked_kind(DateTimeImmutable $day, DateTimeImmutable $next) {
    global $wpdb;
    list($block, $args) = brb_blocking_sql();
    $sql = 'SELECT start_dt, end_dt FROM ' . brb_table() . " WHERE start_dt < %s AND end_dt > %s AND $block";
    $params = array_merge([$next->format('Y-m-d H:i:s'), $day->format('Y-m-d H:i:s')], $args);
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
    if (!$rows) return 'open';
    foreach ($rows as $row) {
        $s = new DateTimeImmutable($row->start_dt, brb_tz());
        $e = new DateTimeImmutable($row->end_dt, brb_tz());
        if ($s <= $day && $e >= $next) return 'reserved';
    }
    return 'partial';
}

function brb_chat_day_sentence($ymd) {
    $day = new DateTimeImmutable($ymd . ' 00:00:00', brb_tz());
    $next = $day->modify('+1 day');
    $label = brb_chat_date_label($ymd);
    $today = brb_now()->setTime(0, 0);
    if ($day < $today) return $label . ' has already passed. I\'d be glad to check another date.';
    if (in_array($ymd, brb_blackouts(), true)) return 'I\'m sorry, ' . $label . ' is unavailable.';
    $slot = brb_slot_info($day);
    if (!brb_is_free($day, $next)) {
        $kind = brb_chat_blocked_kind($day, $next);
        if ($kind === 'partial') return 'Part of ' . $label . ' is already reserved. A full day may not be open. This is not a binding commitment.';
        return $label . ' is already reserved. A date is reserved only once a deposit is paid.';
    }
    $notice = 'Bookings need at least ' . brb_notice_hours() . ' hours\' notice.';
    if (!$slot['any']) return $label . ' is inside the minimum notice window. ' . $notice;
    if (!$slot['day_ok']) return 'A full day on ' . $label . ' starts too soon. ' . $notice . ' A shorter block may be possible later that day. This is not a binding commitment.';
    return $label . ' looks open right now. A request does not hold the date. The date is reserved only once a deposit is paid. This is not a binding commitment.';
}

function brb_chat_availability_text($parsed) {
    if (!empty($parsed['too_long'])) return 'I can check up to 14 days at a time. Send me a shorter range and I\'ll take a look.';
    $notice = 'Bookings need at least ' . brb_notice_hours() . ' hours\' notice. Blackout dates are unavailable. A date is reserved only once a deposit is paid. Pending requests don\'t block the calendar. This is not a binding commitment.';
    if (empty($parsed['dates'])) return 'Tell me a date or a short range and I\'ll check it for you. ' . $notice;
    $lines = [];
    foreach ($parsed['dates'] as $ymd) $lines[] = brb_chat_day_sentence($ymd);
    $lines[] = $notice;
    return implode(' ', $lines);
}

function brb_chat_price_for_dates($parsed) {
    if (!brb_quote_prices_enabled()) {
        return 'Our team will confirm pricing. I can\'t share an estimate here, but they\'d be glad to help. Final price is confirmed in writing by our team and depends on date, headcount and features needed.';
    }
    if (!empty($parsed['too_long'])) return 'I can estimate up to 14 days at a time. Please give me a shorter range. ' . ucfirst(brb_chat_estimate_phrase()) . '.';
    if (empty($parsed['dates'])) {
        return 'Tell me a date and I\'ll share an estimate. Any figure is ' . brb_chat_estimate_phrase() . '. This is not a binding commitment.';
    }
    $lines = [];
    foreach ($parsed['dates'] as $ymd) {
        $day = new DateTimeImmutable($ymd . ' 00:00:00', brb_tz());
        if ($day < brb_now()->setTime(0, 0)) {
            $lines[] = brb_chat_date_label($ymd) . ' has already passed. Tell me a future date for an estimate.';
            continue;
        }
        $amount = brb_estimate_amount($ymd);
        $due = brb_deposit_due_amount($amount);
        $pct = (float) brb_opt('deposit_pct');
        if (($brb_type = (string) brb_opt('deposit_type')) !== 'percent') $pct = 0;
        unset($brb_type);
        $deposit = 'The deposit on that estimate is ' . brb_whole_money($due) . '.';
        if ($pct > 0) {
            $label = (abs($pct - round($pct)) < 0.001) ? (string) (int) round($pct) : rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
            $deposit = 'A ' . $label . '% deposit on that estimate is ' . brb_whole_money($due) . '.';
        }
        $lines[] = brb_chat_date_label($ymd) . ' is ' . brb_whole_money($amount) . ', ' . brb_chat_estimate_phrase() . '. ' . $deposit . ' ' . brb_deposit_line() . ' The date is reserved only when the deposit is paid. This is not a binding commitment.';
    }
    return implode(' ', $lines);
}

function brb_chat_rules_text() {
    return 'Here\'s the short version. No overnight stays. The Boardroom is an event space only. '
        . 'No cooking is allowed. The stovetop may not be used. The kitchen area is for serving and storage. '
        . 'There are no taps. BYOB is allowed. The renter must obtain a one-day liquor license from the City of Moline, fee $50. '
        . 'The room comes not set up. Guests clean the space and take the garbage out. '
        . 'No more than 75 people. Decorations are not provided, except that Christmas decorations are up in December. '
        . 'Event liability insurance is required. If alcohol is served, liquor liability coverage is also required. Proof can be requested before access.';
}

function brb_chat_amenities_text() {
    $groups = function_exists('br_amenity_groups') ? br_amenity_groups() : [];
    $parts = ['The room comes not set up. No overnight stays. The Boardroom is an event space only. I hope this helps you picture the day.'];
    foreach ($groups as $group) {
        $line = $group['title'] . ': ' . implode(', ', $group['items']) . '.';
        if (!empty($group['note'])) $line .= ' ' . $group['note'];
        $parts[] = $line;
    }
    if (count($parts) === 1) $parts[] = 'The amenities checklist is on the home page.';
    return implode(' ', $parts);
}

function brb_chat_payment_text() {
    $parts = [
        'Happy to walk you through payment. Bookings are subject to approval. No payment is due until the request is approved.',
        brb_request_open_line(),
        brb_reserved_line(),
        brb_deposit_line(),
        brb_check_sentence(),
        'The venue is at ' . brb_venue_address() . '.',
    ];
    if (brb_stripe_enabled()) {
        $parts[] = 'After approval you can pay online by card. Stripe adds a ' . brb_fee_percent_label() . ' processing fee, and that fee is non-refundable.';
    }
    $parts[] = 'This is not a binding commitment.';
    return implode(' ', $parts);
}

function brb_chat_book_text() {
    return 'I\'d love to help you plan your event. Request a date on the Book page: ' . home_url('/book/') . '. Bookings are subject to approval. No payment is due until the request is approved. '
        . brb_request_open_line() . ' ' . brb_reserved_line() . ' ' . brb_deposit_line()
        . ' Leave your name and email below if you would like a team member to follow up.';
}

function brb_chat_tour_text() {
    return 'A tour is a lovely way to see the room. Use the Contact form: ' . home_url('/contact/') . '. Email boardroomqc@gmail.com. A tour request does not hold a date. Leave your name and email below and a team member will follow up.';
}

function brb_chat_contact_text() {
    return 'We\'d love to hear from you. Use the Contact form: ' . home_url('/contact/') . '. Email boardroomqc@gmail.com. There is no phone number on this site. The venue is at ' . brb_venue_address() . '.';
}

function brb_chat_cancel_text() {
    return 'Bookings are subject to approval. No payment is due until a request is approved, and a request may be declined. '
        . brb_request_open_line() . ' ' . brb_reserved_line() . ' ' . brb_deposit_line()
        . ' The card processing fee is non-refundable. '
        . 'This is a summary of the Rental Terms, not legal advice. The signed Rental Agreement controls. Read the terms: ' . home_url('/terms/') . '.';
}

function brb_chat_clean_reply($text) {
    $other = 'air' . 'bnb';
    $text = preg_replace('/\b' . $other . '\b/i', '', (string) $text);
    $text = preg_replace('/[ \t]{2,}/', ' ', $text);
    return trim($text);
}

function brb_chat_lead_flag($intents, $history) {
    foreach ($intents as $intent) {
        if (in_array($intent, ['book', 'tour', 'contact', 'unknown', 'legal', 'refuse', 'thanks'], true)) return true;
    }
    $n = 1;
    foreach ((array) $history as $turn) {
        if (is_array($turn) && ($turn['role'] ?? '') === 'user') $n++;
    }
    return $n >= 4;
}

function brb_chat_answer($message, $history = []) {
    $message = trim((string) $message);
    if (mb_strlen($message) > 500) {
        return [
            'reply' => 'Could you shorten that to a few sentences? I\'m happy to help.',
            'lead' => true,
            'intent' => 'unknown',
        ];
    }
    if ($message === '' || preg_match('/^\s*(hi|hello|hey|help|good morning|good afternoon|good evening)\s*[!.]*\s*$/i', $message)) {
        return [
            'reply' => 'Hi there! I\'m the Boardroom\'s assistant. I\'d love to help you plan your event.',
            'lead' => false,
            'intent' => 'greeting',
        ];
    }
    $sensitive = brb_chat_sensitive($message);
    if ($sensitive === 'legal') {
        return ['reply' => brb_chat_clean_reply(brb_chat_legal_refusal()), 'lead' => true, 'intent' => 'legal'];
    }
    if ($sensitive === 'refuse') {
        return ['reply' => brb_chat_clean_reply(brb_chat_refusal()), 'lead' => true, 'intent' => 'refuse'];
    }
    $parsed = brb_chat_dates($message);
    $intents = brb_chat_intents($message);
    if (!$intents && !empty($parsed['dates'])) $intents[] = 'availability';
    if (!$intents && !empty($parsed['too_long'])) $intents[] = 'availability';
    if (preg_match('/^\s*(thanks|thank you)\s*[!.]*\s*$/i', $message)) {
        return [
            'reply' => 'You\'re so welcome! If you\'d like a team member to follow up, leave your name and email below. A team member will reply using this info.',
            'lead' => true,
            'intent' => 'thanks',
        ];
    }
    if (!$intents) {
        return ['reply' => brb_chat_clean_reply(brb_chat_refusal()), 'lead' => true, 'intent' => 'unknown'];
    }
    $parts = [];
    $prefix = '';
    $emoji = '';
    $quiet = array_intersect($intents, ['rules', 'cancel', 'payment']);
    if (!$quiet && in_array('pricing', $intents, true)) $prefix = 'Great question! ';
    elseif (!$quiet && in_array('amenities', $intents, true)) {
        $prefix = 'Great question! ';
        $emoji = ' ✨';
    } elseif (!$quiet && in_array('availability', $intents, true)) $prefix = 'Happy to help with that! ';
    elseif (!$quiet) $prefix = 'I\'d love to help with that. ';
    if (in_array('availability', $intents, true)) $parts[] = brb_chat_availability_text($parsed);
    if (in_array('pricing', $intents, true)) $parts[] = brb_chat_price_for_dates($parsed);
    if (in_array('payment', $intents, true)) $parts[] = brb_chat_payment_text();
    if (in_array('rules', $intents, true)) $parts[] = brb_chat_rules_text();
    if (in_array('amenities', $intents, true)) $parts[] = brb_chat_amenities_text();
    if (in_array('cancel', $intents, true)) $parts[] = brb_chat_cancel_text();
    if (in_array('book', $intents, true)) $parts[] = brb_chat_book_text();
    if (in_array('tour', $intents, true)) $parts[] = brb_chat_tour_text();
    if (in_array('contact', $intents, true)) $parts[] = brb_chat_contact_text();
    return [
        'reply' => brb_chat_clean_reply($prefix . implode("\n\n", $parts) . $emoji),
        'lead' => brb_chat_lead_flag($intents, $history),
        'intent' => $intents[0],
    ];
}

function brb_chat_rate_key($bucket) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'cli';
    return 'brb_ch_' . preg_replace('/[^a-z]/', '', (string) $bucket) . '_' . substr(md5($ip . '|' . wp_salt('auth')), 0, 16);
}

function brb_chat_rate_allow($bucket) {
    $max = ($bucket === 'lead') ? 5 : 30;
    $key = brb_chat_rate_key($bucket);
    $n = (int) get_transient($key);
    if ($n >= $max) return false;
    set_transient($key, $n + 1, HOUR_IN_SECONDS);
    return true;
}

function brb_chat_origin_ok() {
    $home = wp_parse_url(home_url(), PHP_URL_HOST);
    if (!$home) return false;
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
        if (empty($_SERVER[$key]) || !is_string($_SERVER[$key])) continue;
        $host = wp_parse_url($_SERVER[$key], PHP_URL_HOST);
        if ($host && strcasecmp($host, $home) === 0) return true;
    }
    return false;
}

function brb_chat_access_ok($req) {
    $nonce = '';
    if ($req instanceof WP_REST_Request) {
        $nonce = (string) $req->get_header('x-brb-chat');
        if ($nonce === '') $nonce = (string) $req->get_param('nonce');
    }
    $nonce_ok = ($nonce !== '' && wp_verify_nonce($nonce, 'brb_chat'));
    return $nonce_ok || brb_chat_origin_ok();
}

function brb_chat_permission($req) {
    if (!brb_chat_access_ok($req)) {
        return new WP_Error('brb_chat_forbidden', 'This chat is only available on The Boardroom website.', ['status' => 403]);
    }
    $route = ($req instanceof WP_REST_Request) ? (string) $req->get_route() : '';
    $bucket = (substr($route, -4) === 'lead') ? 'lead' : 'chat';
    if (!brb_chat_rate_allow($bucket)) {
        return new WP_Error('brb_chat_rate', 'Please wait a little while and try again.', ['status' => 429]);
    }
    return true;
}

function brb_chat_thanks() {
    return 'Thanks so much, someone from our team will get right back to you!';
}

function brb_chat_save_lead($input) {
    if (!is_array($input)) $input = [];
    if (trim((string) ($input['company'] ?? '')) !== '') {
        return ['ok' => true, 'stored' => false, 'message' => brb_chat_thanks()];
    }
    $name = sanitize_text_field((string) ($input['name'] ?? ''));
    $raw_email = trim((string) ($input['email'] ?? ''));
    $email = sanitize_email($raw_email);
    $phone = sanitize_text_field((string) ($input['phone'] ?? ''));
    $event_date = brb_rate_date($input['event_date'] ?? '');
    if (trim((string) ($input['event_date'] ?? '')) !== '' && $event_date === '') {
        return new WP_Error('date', 'Please use a real event date, or leave it blank.');
    }
    $event_type = sanitize_key((string) ($input['event_type'] ?? ''));
    if ($event_type !== '' && !isset(brb_event_types()[$event_type])) {
        return new WP_Error('event_type', 'Please choose an event type from the list, or leave it blank.');
    }
    $note = sanitize_textarea_field((string) ($input['note'] ?? ''));
    $transcript = sanitize_textarea_field((string) ($input['transcript'] ?? ''));
    if (strlen($note) > 1000) $note = substr($note, 0, 1000);
    if (strlen($transcript) > 2000) $transcript = substr($transcript, 0, 2000);
    $consent = (string) ($input['consent'] ?? '');
    if ($name === '' || strlen($name) > 190 || $raw_email === '') return new WP_Error('missing', 'Please enter your name and email.');
    if (!is_email($email)) return new WP_Error('email', 'Please enter a valid email.');
    if (strlen($phone) > 50) return new WP_Error('phone', 'Please shorten the phone number.');
    if ($consent !== '1') return new WP_Error('consent', 'Please confirm that a team member will reply using this info.');
    if ($note === '' && $transcript === '') return new WP_Error('message', 'Please enter a short note.');

    $parts = [];
    if ($note !== '') $parts[] = $note;
    if ($event_type !== '') $parts[] = 'Event type: ' . brb_event_type_label($event_type);
    if ($event_date !== '') $parts[] = 'Event date: ' . $event_date;
    if ($transcript !== '') $parts[] = "Chat summary:\n" . $transcript;
    $notes = implode("\n\n", $parts);
    if (strlen($notes) > 4000) $notes = substr($notes, 0, 4000);
    $topic = ($event_type !== '') ? 'event' : 'question';
    if (sanitize_key((string) ($input['topic'] ?? '')) === 'tour') $topic = 'tour';

    global $wpdb;
    $t = brb_tour_table();
    $ok = $wpdb->query($wpdb->prepare(
        "INSERT INTO $t (name, email, phone, preferred_date, time_window, party_size, notes, staff_notes, status, created, topic, preferred, source) VALUES (%s, %s, %s, NULL, '', NULL, %s, '', 'new', %s, %s, %s, %s)",
        $name,
        $email,
        $phone,
        $notes,
        current_time('mysql', true),
        $topic,
        $event_date,
        'chat assistant'
    ));
    if (!$ok) return new WP_Error('store', 'We could not save that message. Please try again.');
    $row = brb_tour((int) $wpdb->insert_id);
    if ($row) brb_chat_send_notice($row);
    return ['ok' => true, 'stored' => true, 'id' => $row ? (int) $row->id : 0, 'message' => brb_chat_thanks()];
}

function brb_chat_send_notice($row) {
    $body = "A chat assistant inquiry was received. No date is held.\n\n"
        . "Source: chat assistant\n"
        . "Name: {$row->name}\n"
        . "Email: {$row->email}\n";
    if (trim((string) $row->phone) !== '') $body .= "Phone: {$row->phone}\n";
    $when = trim((string) ($row->preferred ?? ''));
    $body .= 'Event date: ' . ($when !== '' ? $when : 'not given') . "\n"
        . "Message: {$row->notes}\n\n"
        . admin_url('admin.php?page=brb-tours&id=' . (int) $row->id) . "\n";
    $reply = is_email($row->email) ? $row->email : '';
    $from = brb_smtp_from_address();
    $sent = false;
    foreach (brb_tour_recipients() as $to) {
        if (brb_mail($to, 'New contact inquiry', $body, $reply, $from)) $sent = true;
    }
    return $sent;
}

function brb_chat_rest($req) {
    $history = $req->get_param('history');
    return brb_chat_answer((string) $req->get_param('message'), is_array($history) ? $history : []);
}

function brb_chat_lead_rest($req) {
    $result = brb_chat_save_lead($req->get_params());
    if (is_wp_error($result)) {
        $status = ($result->get_error_code() === 'store') ? 500 : 400;
        return new WP_Error($result->get_error_code(), $result->get_error_message(), ['status' => $status]);
    }
    return $result;
}

add_action('rest_api_init', function () {
    register_rest_route('brb/v1', '/chat', [
        'methods' => 'POST',
        'permission_callback' => 'brb_chat_permission',
        'callback' => 'brb_chat_rest',
    ]);
    register_rest_route('brb/v1', '/chat-lead', [
        'methods' => 'POST',
        'permission_callback' => 'brb_chat_permission',
        'callback' => 'brb_chat_lead_rest',
    ]);
});

function brb_chat_markup() {
    ob_start();
    echo '<div class="brb-chat">';
    echo '<button type="button" class="brb-chat-launch" aria-expanded="false" aria-controls="brb-chat-panel">Chat</button>';
    echo '<section id="brb-chat-panel" class="brb-chat-panel" role="dialog" aria-modal="true" aria-labelledby="brb-chat-title" hidden>';
    echo '<header class="brb-chat-head"><h2 id="brb-chat-title">The Boardroom</h2><button type="button" class="brb-chat-close" aria-label="Close chat">Close</button></header>';
    echo '<div class="brb-chat-log" aria-live="polite"><p class="brb-chat-msg bot">Hi there! I\'m the Boardroom\'s assistant. I\'d love to help you plan your event.</p></div>';
    echo '<form class="brb-chat-ask"><label class="brb-chat-sr" for="brb-chat-input">Your question</label><input id="brb-chat-input" class="brb-chat-input" type="text" maxlength="500" autocomplete="off"><button type="submit" class="brb-chat-send">Send</button></form>';
    echo '<button type="button" class="brb-chat-lead-toggle">Leave your details</button>';
    echo '<form class="brb-chat-lead" hidden>';
    echo '<p class="brb-chat-hp" aria-hidden="true"><label>Company<input name="company" tabindex="-1" autocomplete="off"></label></p>';
    echo '<label>Name*<input name="name" maxlength="190" autocomplete="name" required></label>';
    echo '<label>Email*<input type="email" name="email" maxlength="190" autocomplete="email" required></label>';
    echo '<label>Phone (optional)<input name="phone" maxlength="50" autocomplete="tel"></label>';
    echo '<label>Event date<input type="date" name="event_date"></label>';
    echo '<label>Event type<select name="event_type"><option value="">Choose one</option>';
    foreach (brb_event_types() as $value => $label) {
        echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Short note<textarea name="note" rows="3" maxlength="1000"></textarea></label>';
    echo '<label class="brb-chat-consent"><input type="checkbox" name="consent" value="1" required> A team member will reply using this info</label>';
    echo '<button type="submit" class="brb-chat-send">Send to the team</button>';
    echo '</form>';
    echo '<noscript><p><a href="' . esc_url(home_url('/contact/')) . '">Contact form</a></p></noscript>';
    echo '</section></div>';
    return ob_get_clean();
}

add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;
    wp_enqueue_style('brb-chat', BRB_URL . 'assets/chat.css', [], BRB_VER);
    wp_enqueue_script('brb-chat', BRB_URL . 'assets/chat.js', [], BRB_VER, true);
    wp_localize_script('brb-chat', 'BRB_CHAT', [
        'endpoint' => esc_url_raw(rest_url('brb/v1/chat')),
        'leadEndpoint' => esc_url_raw(rest_url('brb/v1/chat-lead')),
        'nonce' => wp_create_nonce('brb_chat'),
    ]);
});

add_action('wp_footer', function () {
    if (is_admin()) return;
    echo brb_chat_markup();
});
