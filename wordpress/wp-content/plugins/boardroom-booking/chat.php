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

function brb_chat_weekdays() {
    return ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
}
function brb_chat_extra_dates($text) {
    $found = [];
    $now = brb_now()->setTime(0, 0);
    if (preg_match('/\bnew year\'?s eve\b|\bnew years eve\b|\bnye\b|\bdecember 31\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
        $year = (int) $now->format('Y');
        $ymd = sprintf('%04d-12-31', $year);
        if ($ymd < $now->format('Y-m-d')) $ymd = sprintf('%04d-12-31', $year + 1);
        $found[] = ['date' => $ymd, 'pos' => $m[0][1], 'end' => $m[0][1] + strlen($m[0][0])];
    }
    if (preg_match('/\btomorrow\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
        $ymd = $now->modify('+1 day')->format('Y-m-d');
        $found[] = ['date' => $ymd, 'pos' => $m[0][1], 'end' => $m[0][1] + strlen($m[0][0])];
    }
    if (preg_match_all('/\b(?:the\s+)?(\d{1,2})(?:st|nd|rd|th)?\s+of\s+next\s+month\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
        $next = $now->modify('first day of next month');
        foreach ($m[0] as $i => $full) {
            $ymd = brb_chat_make_date((int) $next->format('Y'), (int) $next->format('n'), (int) $m[1][$i][0]);
            if ($ymd !== '') $found[] = ['date' => $ymd, 'pos' => $full[1], 'end' => $full[1] + strlen($full[0])];
        }
    }
    $months = brb_chat_months();
    if (preg_match_all('/\b(?:the\s+)?(\d{1,2})(?:st|nd|rd|th)?\s+of\s+(january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sept|sep|oct|nov|dec)\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => $full) {
            $month = $months[strtolower($m[2][$i][0])] ?? 0;
            $ymd = brb_chat_infer_year($month, (int) $m[1][$i][0]);
            if ($ymd !== '') $found[] = ['date' => $ymd, 'pos' => $full[1], 'end' => $full[1] + strlen($full[0])];
        }
    }
    if (preg_match_all('/\b(next|this)\s+(sunday|monday|tuesday|wednesday|thursday|friday|saturday)\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
        $days = brb_chat_weekdays();
        $today_dow = (int) $now->format('w');
        foreach ($m[0] as $i => $full) {
            $which = strtolower($m[1][$i][0]);
            $target = $days[strtolower($m[2][$i][0])];
            $delta = ($target - $today_dow + 7) % 7;
            if ($which === 'next' && $delta === 0) $delta = 7;
            $found[] = ['date' => $now->modify('+' . $delta . ' days')->format('Y-m-d'), 'pos' => $full[1], 'end' => $full[1] + strlen($full[0])];
        }
    }
    if (preg_match_all('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $text, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $i => $full) {
            $year = $m[3][$i][0];
            $ymd = ($year !== '') ? brb_chat_make_date($year, (int) $m[1][$i][0], (int) $m[2][$i][0]) : brb_chat_infer_year((int) $m[1][$i][0], (int) $m[2][$i][0]);
            if ($ymd !== '') $found[] = ['date' => $ymd, 'pos' => $full[1], 'end' => $full[1] + strlen($full[0])];
        }
    }
    return $found;
}
function brb_chat_find_dates($text) {
    $found = brb_chat_extra_dates($text);
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
    $invalid = brb_chat_invalid_date($text);
    if (!$found) return ['dates' => [], 'too_long' => false, 'invalid' => $invalid];
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
                if (count($dates) > 14) return ['dates' => [], 'too_long' => true, 'invalid' => $invalid];
            }
            return ['dates' => $dates, 'too_long' => false, 'invalid' => $invalid];
        }
    }
    $dates = [];
    foreach ($found as $row) {
        if (!in_array($row['date'], $dates, true)) $dates[] = $row['date'];
        if (count($dates) >= 7) break;
    }
    return ['dates' => $dates, 'too_long' => false, 'invalid' => $invalid];
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
    if (preg_match('/\b(legal advice|lawsuit|attorney|lawyer|legal)\b/', $t) || preg_match('/\bsue\b/', $t)) return 'legal';
    if (preg_match('/ignore (your|the|all|my|these|previous)\b.{0,48}\b(rules|instructions|prompt|policy|terms)/', $t)) return 'refuse';
    if (preg_match('/\b(jailbreak|developer mode|you are now|act as if|pretend)\b/', $t)) return 'refuse';
    return '';
}

function brb_chat_slang_map() {
    return [
        'bday' => 'birthday', 'booze' => 'alcohol', 'beer' => 'alcohol', 'wine' => 'alcohol',
        'ppl' => 'people', 'peeps' => 'people', 'cuz' => 'because', 'bc' => 'because',
        'pls' => 'please', 'plz' => 'please', 'u' => 'you', 'ur' => 'your', 'r' => 'are',
        'thru' => 'through', 'tmrw' => 'tomorrow', 'tmr' => 'tomorrow', 'gonna' => 'going to',
        'wanna' => 'want to', 'gotta' => 'have to', 'ya' => 'you', 'yall' => 'you all',
        'thx' => 'thanks', 'ty' => 'thanks', 'pics' => 'photos', 'pic' => 'photo', 'vid' => 'video',
        'avail' => 'available', 'hrs' => 'hours', 'hr' => 'hour', 'wknd' => 'weekend',
        'nxt' => 'next', 'sat' => 'saturday', 'fri' => 'friday', 'thu' => 'thursday', 'thur' => 'thursday',
        'wed' => 'wednesday', 'mon' => 'monday', 'tue' => 'tuesday', 'tix' => 'tickets',
        'merch' => 'merchandise', 'liq' => 'liquor', 'alc' => 'alcohol', 'ac' => 'air conditioning',
        'info' => 'information', 'im' => 'i am', 'dont' => 'do not', 'cant' => 'cannot',
        'wont' => 'will not', 'whats' => 'what is', 'hows' => 'how is', 'wheres' => 'where is',
        'kiddos' => 'kids', 'kiddo' => 'kid', 'loo' => 'restroom', 'n' => 'and',
    ];
}
function brb_chat_lexicon() {
    return [
        'parking', 'available', 'availability', 'birthday', 'alcohol', 'people', 'deposit', 'kitchen',
        'overnight', 'cooking', 'capacity', 'wheelchair', 'accessible', 'decorations', 'cancellation',
        'refund', 'receipt', 'invoice', 'insurance', 'agreement', 'projector', 'shuffleboard', 'pinball',
        'restroom', 'bathroom', 'refrigerator', 'garbage', 'smoking', 'vaping', 'balloon', 'balloons',
        'glitter', 'confetti', 'catering', 'warmer', 'warmers', 'stovetop', 'weekday', 'discount',
        'military', 'nonprofit', 'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday',
        'friday', 'december', 'january', 'february', 'november', 'october', 'september', 'tomorrow',
        'tonight', 'liquor', 'license', 'permit', 'wedding', 'graduation', 'funeral', 'tickets',
        'admission', 'concert', 'fundraiser', 'merchandise', 'kitchen', 'oven', 'stove', 'caterer',
        'television', 'wifi', 'bluetooth', 'accessible', 'entrance', 'address', 'location', 'hours',
        'notice', 'reschedule', 'balance', 'receipt', 'invoice', 'cleaning', 'security', 'damage',
        'walkthrough', 'capacity', 'children', 'birthday', 'corporate', 'meeting', 'band', 'music',
        'tables', 'chairs', 'setup', 'decorations', 'tape', 'photos', 'gallery', 'video', 'contact',
        'phone', 'email', 'person', 'human', 'rules', 'cleanup', 'trash', 'pets', 'cameras', 'noise',
        'contract', 'signed', 'payment', 'check', 'card', 'price', 'estimate', 'quote', 'reserve',
        'booking', 'available', 'holiday', 'half', 'june', 'deposit', 'refundable', 'weather',
        'snowstorm', 'outage', 'insurance', 'liability', 'utensils', 'plates', 'cups', 'heater',
        'heating', 'conditioning', 'restrooms', 'bathrooms', 'accessibility', 'directions', 'located',
        'premises', 'truck', 'garbage', 'smoking', 'vaping', 'service', 'animals', 'overnight',
        'cooking', 'stovetop', 'bartender', 'alcohol', 'byob', 'host', 'teenagers', 'shower',
        'reception', 'celebration', 'admission', 'cover', 'charge', 'speakers', 'sonos', 'projector',
        'television', 'pinball', 'shuffleboard', 'pool', 'kitchen', 'refrigerator', 'freezer',
        'microwave', 'dishwasher', 'wheelchair', 'handicap', 'private', 'entrance', 'surprise',
        'cancellation', 'reschedule', 'invoice', 'receipt', 'tax', 'included', 'methods', 'cash',
        'payable', 'processing', 'nonrefundable', 'emergency', 'hurricane', 'flat', 'tire',
    ];
}
function brb_chat_word_ok($word) {
    static $ok = null;
    if ($ok === null) {
        $ok = array_fill_keys(brb_chat_lexicon(), true);
        foreach (brb_chat_slang_map() as $from => $to) $ok[$from] = true;
        $common = 'a an the and or but if to for of in on at by with from into over after before about than then this that these those you your our we they them it its is are was were be been being do does did can could should would will just not no yes ok okay please thanks thank hello hi hey help have has had get got need want like more most some any all out up so too very also here there when where what which who how why me my mine us our ours your yours their theirs i am let make made take taken come came look see know known think say said tell told give given use used using cheaper cheapest lower lowest day days time times date dates week weeks month months year years next last this today tomorrow tonight early late same other another such than into onto per via off own our you can we us just really maybe around still already again here there yes no not but and or if so too very much many few more most less least only even also back down off out up over under again before after during while because since until though although plus vs per each every both either neither into onto from with without within about across against among around between during except toward towards upon within hello thanks please sorry hi hey help question questions room space event events guest guests party parties bring bringing allowed allow allows fine okay sure free full half open closed start starting starts stop end ending ends leave left stay staying here there site staff team member members form page link links check checks mail mailing mailed drop card cards cash pay paying paid payment payments price prices cost costs rate rates quote quotes estimate estimates date dates book books booking bookings hold holds holding tour tours visit visits photo photos video videos rule rules food foods drink drinks cake cakes dog dogs cat cats kid kids child children host hosts age ages old older young teen teens band bands music dj djs sell selling sold ticket tickets show shows concert concerts market markets item items wall walls tape tapes glitter balloon balloons confetti table tables chair chairs set setup setting hour hours minute minutes am pm june july dec jan feb saturday sunday monday tuesday wednesday thursday friday week weekend weekday weekdays people person persons human humans phone phones email emails call calls text texts number numbers address addresses located location directions map maps parking park parks lot lots free street premise premises wifi internet tv tvs television sound speaker speakers system systems heat heating air conditioning ac cool cooler cold hot warm warmer warmers ice fridge refrigerator machine machines bar bars kitchen kitchens cook cooking stove oven ovens stovetop tap taps permit permits license licenses alcohol liquor beer wine booze byob cater caterer catering plate plates cup cups utensil utensils bathroom bathrooms restroom restrooms accessible access wheelchair ada entrance door doors main back private surprise guest guests capacity max maximum minimum notice advance same row rows night nights overnight stay stays hotel checkin checkout smoke smoking vape vaping cigarette cigarettes pet pets animal animals service camera cameras noise quiet insurance policy policies agreement contract contracts sign signed damage damaged deposit deposits refund refunds cancel cancellation canceling reschedule balance receipt invoice tax taxes included include sales fee fees method methods cash card processing stripe person real talk speak contact reach touch cleaning clean cleanup garbage trash tidy security walk through walkthrough christmas decoration decorations decorate decorated provided provide provides included includes crash';
        foreach (preg_split('/\s+/', $common) as $w) {
            if ($w !== '') $ok[$w] = true;
        }
    }
    return isset($ok[$word]);
}
function brb_chat_fuzzy_word($word) {
    if (brb_chat_word_ok($word)) return $word;
    $len = strlen($word);
    if ($len < 4) return $word;
    $max = ($len >= 6) ? 2 : 1;
    $best = '';
    $bestD = 99;
    $ties = 0;
    foreach (brb_chat_lexicon() as $target) {
        if (abs(strlen($target) - $len) > $max) continue;
        $d = levenshtein($word, $target);
        if ($d < 1 || $d > $max) continue;
        if ($d < $bestD) {
            $bestD = $d;
            $best = $target;
            $ties = 1;
        } elseif ($d === $bestD && $target !== $best) {
            $ties++;
        }
    }
    if ($best === '' || $ties !== 1) return $word;
    similar_text($word, $best, $pct);
    if ($pct < 72) return $word;
    return $best;
}
function brb_chat_normalize($text) {
    $t = strtolower((string) $text);
    $t = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $t);
    $phrases = [
        "new year's eve" => 'december 31',
        'new years eve' => 'december 31',
        'wi fi' => 'wifi',
        'wi-fi' => 'wifi',
        'sleep over' => 'overnight',
        'spend the night' => 'overnight',
        'stay the night' => 'overnight',
        'food truck' => 'food truck',
    ];
    foreach ($phrases as $from => $to) {
        $t = preg_replace('/\b' . preg_quote($from, '/') . '\b/u', $to, $t);
    }
    $t = preg_replace('/\b1130\b/', '11:30', $t);
    $t = preg_replace('/\bnye\b/', 'december 31', $t);
    $t = preg_replace('/\bcheck in\b(?!\s+to)/', 'check-in', $t);
    $t = preg_replace('/\bcheck out\b(?!\s+to)/', 'check-out', $t);
    $slang = brb_chat_slang_map();
    $parts = preg_split('/([^a-z0-9\/:.]+)/', $t, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    foreach ($parts as $part) {
        if ($part === '') continue;
        if (!preg_match('/^[a-z0-9\/:.]+$/', $part) || preg_match('/\d/', $part)) {
            $out .= $part;
            continue;
        }
        if (isset($slang[$part])) {
            $out .= $slang[$part];
            continue;
        }
        $out .= brb_chat_fuzzy_word($part);
    }
    $out = preg_replace('/\s+/', ' ', $out);
    return trim($out);
}
function brb_chat_keyword_hit($text, $word) {
    $stem = false;
    if (substr($word, -1) === '*') {
        $stem = true;
        $word = substr($word, 0, -1);
    }
    if (strpos($word, ' ') !== false) return strpos($text, $word) !== false;
    $q = preg_quote($word, '/');
    if ($stem) return (bool) preg_match('/\b' . $q . '/i', $text);
    return (bool) preg_match('/\b' . $q . '\b/i', $text);
}
function brb_chat_intents($text) {
    $t = strtolower($text);
    if (preg_match('/\bcheck-in\b|\bcheck-out\b|\bcheck in\b(?!\s+to)|\bcheck out\b(?!\s+to)/', $t)) {
        $t = preg_replace('/\bcheck(?:-|\s)?(?:in|out)\b(?!\s+to)/', ' ', $t);
    }
    $map = [
        'availability' => ['available', 'availability', 'blackout', 'is the date', 'open on', 'free on', 'reserved'],
        'amenities' => ['amenit*', 'wifi', 'wi-fi', 'parking', 'pool table', 'kitchen', 'fridge', 'sound system', 'air conditioning', 'what is included', 'what do you have', 'television', 'restroom', 'bathroom'],
        'rules' => ['rule*', 'overnight', 'cook*', 'stove', 'stovetop', 'tap', 'byob', 'liquor', 'alcohol', 'insurance', 'decorat*', 'garbage', 'trash', 'occupan*', '75', 'not set up', 'clean*', 'allowed'],
        'pricing' => ['price*', 'cost*', 'how much', 'rate', 'estimate*', 'quote*'],
        'payment' => ['deposit', 'check', 'payment', 'pay', 'card', 'fee', 'non-refundable', 'stripe'],
        'book' => ['book*', 'reserve a', 'request a date', 'hold a date', 'how do i reserve', 'how to reserve'],
        'tour' => ['tour', 'visit', 'see the space', 'walk through', 'walk-through'],
        'contact' => ['contact', 'email', 'reach', 'get in touch', 'phone number', 'call'],
        'place' => ['where', 'address', 'located', 'directions', 'location'],
        'cancel' => ['cancel*', 'cancellation', 'refund'],
    ];
    $hits = [];
    foreach ($map as $intent => $words) {
        foreach ($words as $word) {
            if (brb_chat_keyword_hit($t, $word)) {
                $hits[] = $intent;
                break;
            }
        }
    }
    return $hits;
}
function brb_chat_chips($intent) {
    $catalog = [
        ['Check dates', 'Is a date open?'],
        ['How much', 'How much does it cost?'],
        ['Deposit', 'How much is the deposit?'],
        ['BYOB', 'Can we bring alcohol?'],
        ['Hours', 'What are your hours?'],
        ['Parking', 'Is there parking?'],
        ['Wi-Fi', 'Is there Wi-Fi?'],
        ['Capacity', 'How many people does it hold?'],
        ['Cancellation', 'What is the cancellation policy?'],
        ['Book', 'How do I book?'],
    ];
    $pick = [
        'pricing' => [0, 2, 3],
        'availability' => [1, 2, 4],
        'payment' => [0, 8, 3],
        'rules' => [4, 3, 7],
        'amenities' => [5, 6, 7],
        'book' => [0, 2, 4],
        'cancel' => [2, 0, 9],
        'contact' => [9, 4, 5],
        'tour' => [0, 9, 4],
        'place' => [5, 9, 4],
        'greeting' => [0, 1, 2],
        'unknown' => [0, 4, 9],
        'refuse' => [0, 4, 9],
        'legal' => [8, 9, 0],
        'thanks' => [0, 9, 2],
        'topic' => [0, 2, 3],
    ];
    $idx = $pick[$intent] ?? [0, 2, 4];
    $out = [];
    foreach ($idx as $i) {
        $out[] = ['label' => $catalog[$i][0], 'question' => $catalog[$i][1]];
    }
    return $out;
}
function brb_chat_dedupe($text) {
    $text = trim((string) $text);
    $parts = preg_split('/(?<=[.!?])\s+/', $text);
    if (!$parts) return $text;
    $seen = [];
    $out = [];
    foreach ($parts as $part) {
        $key = strtolower(preg_replace('/\s+/', ' ', trim($part)));
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = trim($part);
    }
    return implode(' ', $out);
}
function brb_chat_finish($reply, $lead, $intent) {
    return [
        'reply' => brb_chat_dedupe(brb_chat_clean_reply($reply)),
        'lead' => (bool) $lead,
        'intent' => $intent,
        'suggestions' => brb_chat_chips($intent),
    ];
}
function brb_chat_extra_lead($message) {
    return (bool) preg_match('/\bdiscount\b|haggle|best price|cheaper|% off|percent off|secret\s+\w+\s+rate|reschedul|change my date|move my date|hear back|when will you reply|get back to me|nonprofit|military/i', (string) $message);
}
function brb_chat_invalid_date($text) {
    $t = strtolower((string) $text);
    if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)\b/', $t, $m) && (int) $m[1] > 31) return true;
    $months = brb_chat_months();
    if (preg_match_all('/\b(january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sept|sep|oct|nov|dec)\.?\s+(\d{1,2})\b/i', $t, $all, PREG_SET_ORDER)) {
        foreach ($all as $row) {
            $month = $months[strtolower($row[1])] ?? 0;
            if ($month && !checkdate($month, (int) $row[2], 2024)) return true;
        }
    }
    if (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $t, $m)) {
        $year = (($m[3] ?? '') !== '') ? (int) $m[3] : 2024;
        if ($year < 100) $year += 2000;
        if (!checkdate((int) $m[1], (int) $m[2], $year)) return true;
    }
    if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+of\s+(?:next\s+)?month\b/', $t, $m) && ((int) $m[1] > 31 || (int) $m[1] < 1)) return true;
    return false;
}

function brb_chat_suggestions() {
    return 'You can ask me whether a date is open, what\'s in the room, or how the deposit works.';
}
function brb_chat_refusal() {
    return 'I\'m not sure about that one, but our team would be glad to help. I can only help with The Boardroom. ' . brb_chat_suggestions() . ' Please use the Contact form for anything else.';
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

function brb_chat_date_status($ymd) {
    $day = new DateTimeImmutable($ymd . ' 00:00:00', brb_tz());
    $start = $day->setTime(10, 0);
    $end = $day->setTime(23, 30);
    if ($day < brb_now()->setTime(0, 0)) return 'past';
    if (in_array($ymd, brb_blackouts(), true)) return 'blackout';
    if (!brb_is_free($start, $end)) return brb_chat_blocked_kind($start, $end);
    $slot = brb_slot_info($day);
    if (!$slot['any'] || !$slot['day_ok']) return 'notice';
    return 'open';
}
function brb_chat_nearby_open($ymd, $limit = 3) {
    $origin = new DateTimeImmutable($ymd . ' 00:00:00', brb_tz());
    $today = brb_now()->setTime(0, 0);
    $found = [];
    for ($i = 1; $i <= 45 && count($found) < $limit; $i++) {
        foreach ([-$i, $i] as $delta) {
            $cand = $origin->modify(($delta > 0 ? '+' : '') . $delta . ' day');
            if ($cand < $today) continue;
            $key = $cand->format('Y-m-d');
            if (brb_chat_date_status($key) === 'open' && !in_array($key, $found, true)) $found[] = $key;
            if (count($found) >= $limit) break;
        }
    }
    sort($found);
    return $found;
}
function brb_chat_list_dates($dates) {
    $labels = array_map('brb_chat_date_label', $dates);
    $n = count($labels);
    if ($n === 0) return '';
    if ($n === 1) return $labels[0];
    $last = array_pop($labels);
    return implode(', ', $labels) . ' and ' . $last;
}
function brb_chat_day_sentence($ymd) {
    $label = brb_chat_date_label($ymd);
    $status = brb_chat_date_status($ymd);
    if ($status === 'past') return $label . ' has already passed. I can check another date if you\'d like.';
    if ($status === 'blackout') return 'I\'m sorry, ' . $label . ' is unavailable.';
    if ($status === 'partial') return 'Part of ' . $label . ' is already reserved, so that day isn\'t fully open.';
    if ($status === 'reserved') return $label . ' is already reserved. A date is reserved only once a deposit is paid.';
    if ($status === 'notice') {
        $slot = brb_slot_info(new DateTimeImmutable($ymd . ' 00:00:00', brb_tz()));
        $notice = 'Bookings need at least ' . brb_notice_hours() . ' hours\' notice.';
        if (!empty($slot['any']) && empty($slot['day_ok'])) return $label . ' is a bit soon for a full day. ' . $notice . ' A shorter block may be possible later that day.';
        return $label . ' is a bit soon. ' . $notice;
    }
    return $label . ' looks open. ' . brb_guest_review_line();
}

function brb_chat_availability_text($parsed) {
    if (!empty($parsed['too_long'])) return 'I can check up to 14 days at a time. Try a shorter range?';
    if (empty($parsed['dates']) && !empty($parsed['invalid'])) return 'I couldn\'t find that date. Tell me a date, like next Saturday or Dec 12, and I\'ll check it.';
    if (empty($parsed['dates'])) return 'Tell me a date, like next Saturday or Dec 12, and I\'ll check it.';
    $lines = [];
    $blocked = [];
    foreach ($parsed['dates'] as $ymd) {
        $lines[] = brb_chat_day_sentence($ymd);
        if (in_array(brb_chat_date_status($ymd), ['blackout', 'reserved', 'partial'], true)) $blocked[] = $ymd;
    }
    if ($blocked) {
        $near = [];
        foreach ($blocked as $ymd) {
            foreach (brb_chat_nearby_open($ymd, 3) as $open) $near[$open] = true;
        }
        $near = array_keys($near);
        sort($near);
        $near = array_slice($near, 0, 3);
        if ($near) $lines[] = 'A few nearby dates look open: ' . brb_chat_list_dates($near) . '.';
    }
    return implode(' ', $lines);
}

function brb_chat_price_for_dates($parsed) {
    if (!brb_quote_prices_enabled()) {
        return 'Our team will confirm pricing. I can\'t share an estimate here, but they\'d be glad to help. Final price is confirmed in writing by our team and depends on date, headcount and features needed.';
    }
    if (!empty($parsed['too_long'])) return 'I can estimate up to 14 days at a time. Please give me a shorter range. ' . ucfirst(brb_chat_estimate_phrase()) . '.';
    if (empty($parsed['dates'])) {
        return 'Tell me a date and I\'ll share an estimate. Any figure is ' . brb_chat_estimate_phrase() . '.';
    }
    $lines = [];
    foreach ($parsed['dates'] as $ymd) {
        if (brb_chat_date_status($ymd) !== 'open') continue;
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
        $lines[] = brb_chat_date_label($ymd) . ' is ' . brb_whole_money($amount) . ', ' . brb_chat_estimate_phrase() . '. ' . $deposit;
    }
    if (!$lines) return '';
    $lines[] = 'That\'s an estimate, not a binding commitment.';
    return implode(' ', $lines);
}

// [CONFIRM with Chris] Guests out by 11:30 PM. No smoking or vaping. Service animals only.
function brb_chat_rules_text() {
    return 'We\'d love to host you. Here\'s the short version. No overnight stays. The Boardroom is an event space only. '
        . brb_hours_line() . ' Everyone is out by 11:30 PM. Smoking is outside only. Service animals only. No pets. '
        . 'All events must be free to attend: no tickets, no cover charge, no paid admission, no door fees, and no ticket or merchandise sales. '
        . 'No cooking is allowed. The stovetop may not be used. There are no taps. ' . brb_byob_line() . ' '
        . 'Please take the garbage out. The room comes not set up. We hold up to 75 people. Christmas decorations are up in December. Event liability insurance is required. '
        . 'Want me to check a date?';
}
function brb_chat_specific_text($text) {
    $t = strtolower((string) $text);
    if (preg_match('/\b(house rules|the rules|your rules|what are the rules)\b|\brules\s*\?/', $t)) return '';
    if (preg_match('/amenit|what is included|what do you have|what\'s included|whats included/', $t)) return '';
    $bits = [];
    if (preg_match('/\breceipt\b|\binvoice\b/', $t)) $bits[] = brb_chat_receipt_line();
    if (preg_match('/\bhours\b|what time are you open|half[- ]?day|10:00|10 am|open at|open and close|when do you open/', $t)) {
        $bits[] = brb_hours_line() . ' ' . brb_guest_out_line() . ' Want me to check a date?';
    }
    if (preg_match('/\b(monday|tuesday|wednesday|thursday|weekday)s?\b/', $t) && !preg_match('/\b(this|next)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/', $t) && !preg_match('/discount|cheaper|secret/', $t)) {
        $bits[] = brb_hours_line();
    }
    if (preg_match('/wedding|shower|graduation|\bgrad\b|birthday|funeral|celebration of life|\bteens?\b|\bteenagers?\b|corporate|business meeting|\bmeeting\b/', $t)) {
        $bits[] = 'We\'d love to host you. Weddings, showers, graduations, kids birthdays, funerals, business meetings, and teen events are welcome when the event is free to attend, within capacity, and finished by 11:30 PM. The minimum host age is 25 for every booking. Want me to check a date?';
    }
    if (preg_match('/\bage limit\b|\bhost age\b|\bminimum host\b|\bhosts\b|\bhow old\b|\bthe host\b/', $t)) {
        $bits[] = 'The host must be 25 or older. The minimum host age is 25 for every booking.';
    }
    if (preg_match('/balance due|when is the balance|the balance|remaining balance|\bthe rest\b/', $t)) {
        $bits[] = 'The balance is due the week of the event, no later than 48 hours before. We\'ll confirm the amount with you.';
    }
    if (preg_match('/minimum notice|how far in advance|how far ahead|same day|how soon|hours\'? notice|how much notice|\btonight\b/', $t)) {
        $bits[] = 'Bookings need at least ' . brb_notice_hours() . ' hours\' notice.';
    }
    if (preg_match('/hold (a|the) date|hold it|without paying|submit(?:ting)? the form|once i submit|reserved once/', $t)) {
        $bits[] = 'A request does not hold the date. Your date is reserved once the meeting is complete and your deposit is received and approved by the Boardroom.';
    }
    if (preg_match('/hear back|how long to hear|when will you reply|get back to me|response time/', $t)) {
        $bits[] = 'Please use the Contact form: ' . home_url('/contact/') . '. Email boardroomqc@gmail.com. A team member will follow up.';
    }
    if (preg_match('/more than one day|two days|in a row|back to back|consecutive|multi-?day/', $t)) {
        $bits[] = 'Tell me the dates and I\'ll check them. I can check up to 14 days at a time.';
    }
    if (preg_match('/reschedul|change my date|move my date|switch my date/', $t)) {
        $bits[] = brb_chat_cancel_text() . ' Please use the Contact form to ask about a different date.';
    }
    if (preg_match('/\bdiscount\b|haggle|best price|cheaper|% off|percent off|secret\s+\w+\s+rate/', $t)) {
        if (preg_match('/nonprofit|military/', $t)) $bits[] = 'Please contact us directly to discuss nonprofit or military discounts.';
        $bits[] = 'Final price is confirmed in writing by our team and depends on date, headcount and features needed. Please use the Contact form.';
    }
    if (preg_match('/why .{0,24}price|price change|one date more/', $t)) {
        $bits[] = 'Any figure is ' . brb_chat_estimate_phrase() . '.';
    }
    if (preg_match('/overnight|sleep over|crash there|\bhotel\b|check-in/', $t)) {
        $bits[] = 'No overnight stays. The Boardroom is an event space only.';
    }
    if (preg_match('/night before|day before/', $t) && preg_match('/decorat|set-?up|setup|\bcome\b/', $t)) {
        $bits[] = 'On a full day, setup can begin at 8:00 AM. On a half day, setup can begin one hour before the start. Optional setup or takedown day add-ons, when that day is open, can be added within 72 hours of the event for ' . brb_whole_money(brb_addon_price()) . '.';
    }
    if (preg_match('/cleaning fee|security deposit|damage deposit|extra for cleaning|pays for damage|who pays for damage/', $t)) {
        $bits[] = 'The refundable damage deposit is a flat $250. It is charged with the booking deposit and mainly covers cleaning.';
    }
    if (preg_match('/refrigerator|\bfridge\b|\bice\b/', $t)) {
        $bits[] = 'Yes. There is a refrigerator and an ice machine.';
    }
    if (preg_match('/who will be there|during my event|during the event|on site during|someone be there|staff on site|will someone be there/', $t)) {
        $bits[] = 'No one is on site during events. Our connected offices are available on demand if something is needed. Cell numbers are given when the contract is signed, and they are not listed on this site.';
    }
    if (preg_match('/entrance|surprise guest/', $t)) {
        $bits[] = 'There is one main entrance. A back entrance can be used for a surprise guest when it is arranged ahead of time with our team.';
    }
    if (preg_match('/talk to a person|speak to a person|speak with a person|real person|\bhuman\b/', $t)) {
        $bits[] = 'Use the Contact form or email boardroomqc@gmail.com. Cell numbers are given when the contract is signed, and they are not listed on this site.';
    }
    if (preg_match('/balloon/', $t)) {
        $bits[] = 'Yes. Balloons are fine. Wall tape is not allowed. Glitter is not allowed.';
    }
    if (preg_match('/wall tape|tape on the wall|\btape\b/', $t)) {
        $bits[] = 'No. Wall tape is not allowed. Balloons are fine. Glitter is not allowed.';
    }
    if (preg_match('/damaged|gets damaged|something gets|damage deposit|who pays for damage/', $t)) {
        $bits[] = 'The damage deposit covers cleaning and damage. Anything beyond that deposit may be charged. See the Terms. Our team will go over it at the walk-through.';
    }
    if (preg_match('/vendor market|sell items|\bsell\b|merchandise/', $t) && !preg_match('/alcohol|liquor|drink|booze|beer/', $t)) {
        $bits[] = 'No. All events are free to attend. There are no merchandise sales and no ticket sales.';
    }
    if (preg_match('/meet you|meet us|walk[- ]?through|in person/', $t)) {
        $bits[] = 'Yes. We meet every renter before approving a booking. It\'s a short, friendly visit to talk through your plans and the house rules. Our team will reach out to schedule it.';
    }
    if (preg_match('/nonprofit|military/', $t) && !preg_match('/\bdiscount\b/', $t)) {
        $bits[] = 'Please contact us directly to discuss nonprofit or military discounts.';
    }
    if (preg_match('/\btax\b|sales tax/', $t)) $bits[] = 'Sales tax is included in the price.';
    if (preg_match('/wheelchair|accessible|accessib|\bada\b|handicap/', $t)) $bits[] = 'Yes. The space is accessible.';
    if (preg_match('/\bnoise\b|quiet|outdoors/', $t)) $bits[] = 'Nothing is outdoors, and the space is quiet after 11:30 PM.';
    if (preg_match('/\bcameras?\b|no staff/', $t)) $bits[] = 'There are security cameras on site. No staff are in the area, and a representative can be reached if something arises.';
    if (preg_match('/cooler/', $t)) $bits[] = 'Yes. There is a cooler and an ice machine.';
    if (preg_match('/force majeure|hurricane|snowstorm|power outage|flat tire|bad weather/', $t)) {
        $bits[] = 'Bad weather or an emergency out of our control, such as a hurricane, a snowstorm, or a power outage, is refundable. A personal issue, such as a flat tire, is not refundable.';
    }
    if (preg_match('/\binsurance\b|liability|assumption of risk/', $t)) {
        $bits[] = 'We recommend that renters carry their own home or renters policy. If event insurance is needed, the renter carries that policy. The renter and guests assume the risk of the event. The Boardroom is not responsible for injury, loss, or equipment issues.';
    }
    if (preg_match('/dumpster|venmo|paypal/', $t)) {
        $bits[] = 'The refundable damage deposit is a flat $250. It is charged with the booking deposit and mainly covers cleaning. It may be applied to excessive cleaning or garbage. A dumpster is on site. It is returned within 72 hours when Venmo or PayPal is on file. A check takes 1 to 2 weeks. No glitter. Vaping leaves a residue and can affect the deposit. All smoking, including cigars and cigarettes, is outside only.';
    }
    if (preg_match('/\bdecember\b|\bdec\b/', $t) && preg_match('/open|available|availability|dates/', $t) && !preg_match('/\d/', $t)) {
        $bits[] = brb_chat_december_open();
    }
    if (preg_match('/\bphoto|\bpicture|\bgallery\b|tour video|\bvideo\b/', $t)) {
        $bits[] = 'Photos are in the Gallery: ' . home_url('/gallery/') . '. The tour video is on the home page and the Contact page.';
    }
    if (preg_match('/table|chair|already set|room set/', $t)) {
        $bits[] = 'Tables and chairs are on site. Guests set them up and put them away. The room comes not set up. Staff cannot set the room up.';
    }
    if (preg_match('/plate|cups|utensil|serving spoon|buffet/', $t)) {
        $bits[] = 'Please bring your own plates, cups, and utensils. Buffet units, pans, and some serving spoons are available when guests clean them before leaving.';
    }
    if (preg_match('/food truck/', $t)) $bits[] = 'Yes. There is a place for a food truck.';
    if (preg_match('/heat|air conditioning|\bac\b|wi-?fi|bluetooth/', $t)) {
        $bits[] = 'The room has heat and air conditioning, a TV, Wi-Fi, and Bluetooth sound throughout.';
    }
    if (preg_match('/\btv\b|television|pinball|shuffleboard|pool table|\bgames\b/', $t)) {
        $bits[] = 'Yes. The room has an 80in television, pinball, a pool table, and shuffleboard.';
    }
    if (preg_match('/restroom|bathroom/', $t)) {
        $bits[] = 'There are two restrooms, with stalls and urinals.';
    }
    if (preg_match('/\bbar\b/', $t) && !preg_match('/bartender/', $t)) {
        $bits[] = 'Yes. There is a bar area and an ice machine. ' . brb_byob_line();
    }
    if (preg_match('/rental agreement|sign an agreement|signed agreement|\bcontract\b/', $t)) {
        $bits[] = 'Yes. A signed rental agreement is part of booking. The Terms page is ' . home_url('/terms/') . ' and the agreement is ' . home_url('/rental-agreement/') . '.';
    }
    if (!preg_match('/food truck/', $t) && preg_match('/\bfood\b|cater|warmer|buffet|\bcook|\bstove|stovetop|\boven\b/', $t)) {
        $bits[] = 'Yes. You can bring your own food. We have warmers and buffet-style warmers, but the stovetop can\'t be used. You can also have it catered by any caterer. The Boardroom does not provide catering.';
    }
    if (preg_match('/sell or serve|do you sell|do u sell|\bserve alcohol\b|\bbartender\b/', $t)) {
        $bits[] = 'We don\'t supply food, drinks, catering, or bartending. ' . brb_byob_line();
    } elseif (preg_match('/\bbyob\b|liquor|alcohol|\bdrinks?\b/', $t)) {
        $bits[] = 'Yes. ' . brb_byob_line() . ' Please leave it like you got it, and take the garbage out.';
    }
    if (preg_match('/decorat/', $t)) {
        $bits[] = 'Decorations are not provided, except that Christmas decorations are up in December.';
    }
    if (preg_match('/confetti/', $t)) $bits[] = 'Confetti is not allowed.';
    if (preg_match('/\bglitter\b/', $t) && !preg_match('/balloon/', $t)) $bits[] = 'Glitter is not allowed.';
    if (preg_match('/get in|set it up|set the room|set up|set-?up|setup|tear ?down|put away/', $t)) {
        $bits[] = 'No. Guests set up and put everything away. Our staff can\'t set the room for you. Staff cannot set the room up. On a full day, setup can begin at 8:00 AM. On a half day, setup can begin one hour before the start.';
    }
    if (preg_match('/\bparking\b|\bpark\b/', $t)) {
        $bits[] = 'Yes. Free parking on premises, and there is free street parking too.';
    }
    if (preg_match('/music|sound system|sonos|\bvolume\b|speaker/', $t)) {
        $bits[] = 'The room has a sound system (Sonos, Aux, and Bluetooth).';
    }
    if (preg_match('/\bclean|\bgarbage\b|\btrash\b|\btidy\b/', $t)) {
        $bits[] = 'Please leave it like you got it, and take the garbage out.';
    }
    if (preg_match('/smok|vap|cigar|cigarette/', $t)) {
        $bits[] = 'All smoking, including cigars and cigarettes, is outside only. Vaping leaves a residue and can affect the damage deposit.';
    }
    if (preg_match('/\bpets?\b|service animal|\bdogs?\b|\bcats?\b/', $t)) {
        $bits[] = 'Service animals only. No pets.';
    }
    if (preg_match('/capacity|how many (people|guests)|75 people|how many can|\b75\b|how many fit/', $t)) {
        $bits[] = 'The Boardroom holds up to 75 people.';
    }
    $paid = preg_match('/ticket|cover charge|charge a cover|concert|paid admission|door fee|fundrais|merchandise|charge admission|paid event/', $t);
    $band = preg_match('/\bband\b|live music|\bdj\b/', $t);
    if ($paid) $bits[] = 'All events must be free to attend: no tickets, no cover charge, no paid admission, no door fees, and no ticket or merchandise sales. Guests may have a band or live music, as long as they are not charging for the event. We do not host concerts or ticketed shows.';
    elseif ($band) $bits[] = 'Yes. Guests may have a band or live music, as long as they are not charging for the event. We do not host concerts or ticketed shows.';
    if (preg_match('/how late|end time|have to leave|be out|what time do we|stay until|out by|11:30|wrap up|everyone out|3\s?am/', $t)) {
        $bits[] = 'Everyone must be out of The Boardroom by 11:30 PM. That is firm, with no exceptions. Please plan to wrap up before then.';
    }
    return implode(' ', $bits);
}

function brb_chat_amenities_text() {
    $groups = function_exists('br_amenity_groups') ? br_amenity_groups() : [];
    $lines = ["Here's what the room includes:"];
    foreach ($groups as $group) {
        $lines[] = $group['title'] . ': ' . implode(', ', $group['items']) . '.';
        if (!empty($group['note'])) $lines[] = $group['note'];
    }
    if (count($lines) === 1) $lines[] = 'The amenities checklist is on the home page.';
    $lines[] = 'The room comes not set up. No overnight stays. The Boardroom is an event space only.';
    return implode("\n", $lines);
}

function brb_chat_asks_checks($text) {
    $text = (string) $text;
    $lodging = preg_match('/\bcheck-in\b|\bcheck-out\b|\bcheck in\b(?!\s+to)|\bcheck out\b(?!\s+to)/i', $text);
    if ($lodging && !preg_match('/\b(mail|payable|pay by check|pay with a check|write a check|personal check)\b/i', $text)) {
        return false;
    }
    return (bool) preg_match('/\b(check|checks|mail|drop[- ]?off|where to pay|where do i pay|payable)\b/i', $text);
}
function brb_chat_payment_text($include_checks = false) {
    $fee = brb_fee_percent_label();
    $text = 'Happy to help. ' . brb_guest_review_line() . ' The deposit is 50% of the agreed total. It is due within 1 day of signing. Pay by check, in person, or by card once approved. It is refundable if written cancellation is received 60 or more days before the event, less the non-refundable ' . $fee . ' card processing fee and costs already incurred. It is not refundable if cancellation is received less than 60 days before the event. Want the full cancellation terms? See ' . home_url('/terms/') . '.';
    if ($include_checks) {
        $text .= ' Mail or drop off checks payable to ' . brb_guest_payee() . ' at ' . brb_check_address() . '. That is the check address, not the event space.';
    }
    if (brb_stripe_enabled()) {
        $text .= ' After approval you can also pay by card. Stripe adds a ' . $fee . ' card processing fee.';
    }
    return $text;
}

function brb_chat_book_text() {
    return 'I\'d love to help you plan your event. Submit a request on the Book page: ' . home_url('/book/') . '. ' . brb_guest_review_line() . ' Want me to check a date?';
}
function brb_chat_confirm_line() {
    return 'Our team will confirm that for you.';
}
function brb_chat_receipt_line() {
    return 'Yes. Card payments get an emailed receipt, and we can provide an invoice or receipt for any payment on request.';
}
function brb_chat_december_open() {
    $year = (int) brb_now()->format('Y');
    if ((int) brb_now()->format('n') > 12) $year++;
    $found = [];
    for ($d = 1; $d <= 31 && count($found) < 3; $d++) {
        $ymd = sprintf('%04d-12-%02d', $year, $d);
        if (brb_chat_date_status($ymd) === 'open') $found[] = $ymd;
    }
    if (!$found) return 'I do not see an open December date in that quick look. ' . brb_chat_confirm_line();
    return 'A few open dates in December: ' . brb_chat_list_dates($found) . '. ' . brb_guest_review_line();
}

function brb_chat_tour_text() {
    return 'A tour is a lovely way to see the room. Use the Contact form: ' . home_url('/contact/') . '. Email boardroomqc@gmail.com. A tour request does not hold a date. Leave your name and email below and a team member will follow up.';
}

function brb_chat_contact_text() {
    return 'We\'d love to hear from you. Email boardroomqc@gmail.com or use the Contact form: ' . home_url('/contact/') . '. There is no phone number on this site. The venue is at ' . brb_venue_address() . '.';
}
function brb_chat_place_text($with_checks) {
    if ($with_checks) {
        return 'The event is at ' . brb_venue_address() . '. Mail or drop off checks payable to ' . brb_guest_payee() . ' at ' . brb_check_address() . '. That is the check address, not the event space.';
    }
    return 'The venue is at ' . brb_venue_address() . '.';
}

function brb_chat_cancel_text() {
    return 'The deposit is 50% of the agreed total. It is refundable if written cancellation is received 60 or more days before the event, less the non-refundable 3% card processing fee and costs already incurred. It is not refundable if cancellation is received less than 60 days before the event. This is not legal advice. Want the full cancellation terms? See ' . home_url('/terms/') . '.';
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

function brb_chat_only_about($message, $pattern) {
    $parts = preg_split('/\?|\band\b|&/i', strtolower((string) $message));
    $n = 0;
    foreach ($parts as $part) {
        if (trim($part) === '') continue;
        $n++;
        if (!preg_match($pattern, $part)) return false;
    }
    return $n > 0;
}
function brb_chat_answer($message, $history = []) {
    $message = trim((string) $message);
    if (mb_strlen($message) > 500) {
        return brb_chat_finish('Could you shorten that to a few sentences? I\'m happy to help.', true, 'unknown');
    }
    if ($message === '' || preg_match('/^\s*(hi|hello|hey|help|good morning|good afternoon|good evening)\s*[!.]*\s*$/i', $message)) {
        $hello = 'Hi there! I\'m the Boardroom\'s assistant.';
        if (preg_match('/hello/i', $message)) $hello = 'Hello! I\'m the Boardroom\'s assistant.';
        elseif (preg_match('/hey/i', $message)) $hello = 'Hey! I\'m the Boardroom\'s assistant.';
        return brb_chat_finish($hello . ' I\'d love to help you plan your event. ' . brb_chat_suggestions(), false, 'greeting');
    }
    $norm = brb_chat_normalize($message);
    $sensitive = brb_chat_sensitive($message);
    if ($sensitive === '') $sensitive = brb_chat_sensitive($norm);
    if ($sensitive === 'legal') return brb_chat_finish(brb_chat_legal_refusal(), true, 'legal');
    if ($sensitive === 'refuse') return brb_chat_finish(brb_chat_refusal(), true, 'refuse');
    if (brb_chat_only_about($message, '/\breceipt\b|\binvoice\b/') || brb_chat_only_about($norm, '/\breceipt\b|\binvoice\b/')) {
        return brb_chat_finish(brb_chat_receipt_line(), false, 'topic');
    }
    if (brb_chat_only_about($message, '/\btax\b|sales tax/') || brb_chat_only_about($norm, '/\btax\b|sales tax/')) {
        return brb_chat_finish('Sales tax is included in the price.', false, 'topic');
    }
    $parsed = brb_chat_dates($message);
    $parsedNorm = brb_chat_dates($norm);
    foreach ($parsedNorm['dates'] as $ymd) {
        if (!in_array($ymd, $parsed['dates'], true)) $parsed['dates'][] = $ymd;
    }
    $parsed['invalid'] = !empty($parsed['invalid']) || !empty($parsedNorm['invalid']);
    $parsed['too_long'] = !empty($parsed['too_long']) || !empty($parsedNorm['too_long']);
    $intents = array_values(array_unique(array_merge(brb_chat_intents($message), brb_chat_intents($norm))));
    $specific = brb_chat_specific_text($norm);
    if ($specific === '') $specific = brb_chat_specific_text($message);
    if ($specific !== '') $intents = array_values(array_diff($intents, ['rules', 'amenities']));
    if (!$intents && !empty($parsed['dates'])) $intents[] = 'availability';
    if (!empty($parsed['dates']) && !in_array('availability', $intents, true) && preg_match('/\b(open|available|availability|free)\b/i', $norm . ' ' . $message)) $intents[] = 'availability';
    if (!$intents && !empty($parsed['too_long'])) $intents[] = 'availability';
    if (!empty($parsed['invalid']) && empty($parsed['dates']) && !in_array('availability', $intents, true)) $intents[] = 'availability';
    $quotable = [];
    foreach ($parsed['dates'] as $ymd) {
        if (brb_chat_date_status($ymd) === 'open') $quotable[] = $ymd;
    }
    if (!empty($parsed['dates']) && !$quotable && in_array('pricing', $intents, true)) {
        $intents = array_values(array_diff($intents, ['pricing']));
        if (!in_array('availability', $intents, true)) $intents[] = 'availability';
    }
    if ($quotable && brb_quote_prices_enabled() && in_array('availability', $intents, true) && !in_array('pricing', $intents, true) && !in_array('rules', $intents, true)) {
        $intents[] = 'pricing';
    }
    if (preg_match('/^\s*(thanks|thank you)\s*[!.]*\s*$/i', $message)) {
        return brb_chat_finish('You\'re so welcome! If you\'d like a team member to follow up, leave your name and email below. A team member will reply using this info.', true, 'thanks');
    }
    if (!$intents && $specific === '') {
        return brb_chat_finish(brb_chat_refusal(), true, 'unknown');
    }
    $parts = [];
    if ($specific !== '') $parts[] = $specific;
    if (in_array('availability', $intents, true)) $parts[] = brb_chat_availability_text($parsed);
    if (in_array('pricing', $intents, true)) $parts[] = brb_chat_price_for_dates($parsed);
    if (in_array('payment', $intents, true)) $parts[] = brb_chat_payment_text((brb_chat_asks_checks($message) || brb_chat_asks_checks($norm)) && !in_array('place', $intents, true));
    if (in_array('rules', $intents, true)) $parts[] = brb_chat_rules_text();
    if (in_array('amenities', $intents, true)) $parts[] = brb_chat_amenities_text();
    if (in_array('cancel', $intents, true)) $parts[] = brb_chat_cancel_text();
    if (in_array('book', $intents, true)) $parts[] = brb_chat_book_text();
    if (in_array('tour', $intents, true)) $parts[] = brb_chat_tour_text();
    if (in_array('contact', $intents, true)) $parts[] = brb_chat_contact_text();
    if (in_array('place', $intents, true)) $parts[] = brb_chat_place_text(in_array('payment', $intents, true));
    $lead = brb_chat_lead_flag($intents, $history) || brb_chat_extra_lead($message) || brb_chat_extra_lead($norm);
    return brb_chat_finish(implode("\n\n", $parts), $lead, $intents ? $intents[0] : 'topic');
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

function brb_chat_transcript_from_input($input) {
    $lines = [];
    if (!empty($input['turns']) && is_array($input['turns'])) {
        foreach ($input['turns'] as $turn) {
            if (!is_array($turn)) continue;
            $role = (($turn['role'] ?? '') === 'user') ? 'Guest' : 'Assistant';
            $text = trim(sanitize_text_field((string) ($turn['text'] ?? '')));
            if ($text === '') continue;
            $lines[] = $role . ': ' . $text;
            if (count($lines) >= 24) break;
        }
    }
    if (!$lines) {
        $raw = str_replace(["\r\n", "\r"], "\n", (string) ($input['transcript'] ?? ''));
        foreach (explode("\n", $raw) as $line) {
            $line = trim(sanitize_text_field($line));
            if ($line === '') continue;
            $lines[] = $line;
            if (count($lines) >= 24) break;
        }
    }
    $text = implode("\n", $lines);
    if (strlen($text) > 2000) $text = substr($text, 0, 2000);
    return $text;
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
    $transcript = brb_chat_transcript_from_input($input);
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
    try {
        $history = $req->get_param('history');
        return brb_chat_answer((string) $req->get_param('message'), is_array($history) ? $history : []);
    } catch (Throwable $e) {
        return new WP_Error('brb_chat_unavailable', 'Please try that once more in a moment.', ['status' => 503]);
    }
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

function brb_chat_markup($mode = 'float') {
    $inline = ($mode === 'inline');
    ob_start();
    echo '<div class="brb-chat' . ($inline ? ' brb-chat-inline' : '') . '">';
    if (!$inline) {
        echo '<button type="button" class="brb-chat-launch" aria-expanded="false" aria-controls="brb-chat-panel">Ask our Chat Bot</button>';
    }
    echo '<section id="brb-chat-panel" class="brb-chat-panel" role="' . ($inline ? 'region' : 'dialog') . '"' . ($inline ? '' : ' aria-modal="true"') . ' aria-labelledby="brb-chat-title"' . ($inline ? '' : ' hidden') . '>';
    echo '<header class="brb-chat-head"><h2 id="brb-chat-title">Ask our Chat Bot</h2>';
    if (!$inline) echo '<button type="button" class="brb-chat-close" aria-label="Close chat">Close</button>';
    echo '</header>';
    echo '<div class="brb-chat-scroll">';
    echo '<div class="brb-chat-log" aria-live="polite"><p class="brb-chat-msg bot"><span class="brb-chat-who">Boardroom</span>Hi there! I\'m the Boardroom\'s assistant. I\'d love to help you plan your event.</p></div>';
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
    echo '</div></section></div>';
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
    if (is_page('contact') || is_front_page()) return;
    echo brb_chat_markup();
});
