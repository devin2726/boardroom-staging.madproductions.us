<?php
/**
 * Score brb_chat_answer against tests/chat-corpus.json.
 * Load with wp eval-file. Prints a summary and writes JSON when BRB_SCORE_OUT is set.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Load this with wp eval-file\n");
    exit(1);
}

function brb_chat_corpus_items() {
    $paths = [];
    if (defined('BRB_CORPUS')) $paths[] = BRB_CORPUS;
    $paths[] = __DIR__ . '/chat-corpus.json';
    $paths[] = '/tmp/brb-tests/chat-corpus.json';
    $paths[] = dirname(__DIR__, 3) . '/tests/chat-corpus.json';
    foreach ($paths as $path) {
        if (is_string($path) && is_readable($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data)) return $data;
        }
    }
    return [];
}

function brb_chat_topic_spec($topic) {
    $clerk = '309-524-2004';
    $byob = 'https://moline.il.us/244/Liquor-Licensing';
    $specs = [
        'date_check' => ['dynamic' => 'date', 'money' => 'date', 'dates' => true],
        'nye' => ['dynamic' => 'nye', 'money' => 'date', 'dates' => true],
        'invalid_date' => ['facts' => ["couldn't find that date"], 'money' => 'none', 'dates' => false],
        'december' => ['facts' => ['December'], 'money' => 'none', 'dates' => true],
        'book' => ['facts' => ['/book/', 'approved by the Boardroom'], 'lead' => true, 'money' => 'none'],
        'notice' => ['facts' => ['48', "hours", 'notice'], 'money' => 'none', 'forbid' => ['guaranteed']],
        'hold' => ['facts' => ['A request does not hold the date', 'deposit is received and approved'], 'money' => 'none', 'forbid' => ['$10']],
        'reserve' => ['facts' => ['/book/', 'approved by the Boardroom'], 'lead' => true, 'money' => 'none'],
        'response_time' => ['facts' => ['Contact form'], 'lead' => true, 'money' => 'none', 'forbid' => ['within 24', '24-hour', 'same-day reply']],
        'weekday_ok' => ['facts' => ['10:00 AM', '11:30 PM'], 'money' => 'none'],
        'multi_day' => ['facts' => ['14 days'], 'money' => 'none'],
        'tour' => ['facts' => ['/contact/', 'boardroomqc@gmail.com', 'does not hold'], 'lead' => true, 'money' => 'none'],
        'photos' => ['facts' => ['/gallery/', 'tour video'], 'money' => 'none'],
        'change_date' => ['facts' => ['60 or more days before the event', 'Contact form'], 'lead' => true, 'money' => 'none', 'forbid' => ['free of charge', 'no charge to change']],
        'price_general' => ['facts' => ['Tell me a date', 'an estimate for that date'], 'money' => 'none'],
        'price_why' => ['facts' => ['depends on date'], 'money' => 'none'],
        'discount' => ['facts' => ['depends on date', 'Contact form'], 'lead' => true, 'money' => 'none', 'forbid' => ['% off', 'percent off', '10%']],
        'nonprofit' => ['facts' => ['nonprofit or military discounts'], 'lead' => true, 'money' => 'none'],
        'deposit' => ['facts' => ['50% of the agreed total'], 'money' => 'none', 'forbid' => ['not a binding commitment']],
        'deposit_when' => ['facts' => ['50% of the agreed total', '1 day', 'signing'], 'money' => 'none'],
        'balance' => ['facts' => ['week of the event', '48 hours'], 'money' => 'none'],
        'pay_methods' => ['facts' => ['check', 'in person', 'card'], 'money' => 'none'],
        'cash_check' => ['facts' => ['2212 37th Street, Moline, IL 61265', 'Mad Productions', 'check address'], 'money' => 'none', 'checks' => true],
        'card_fee' => ['facts' => ['3%', 'card'], 'any' => [['non-refundable', "isn't refundable"]], 'money' => 'none'],
        'cleaning' => ['facts' => ['damage deposit', '$250', 'cleaning'], 'money' => 'damage'],
        'security' => ['facts' => ['damage deposit', '$250'], 'money' => 'damage'],
        'tax' => ['exact' => 'Sales tax is included in the price.', 'money' => 'none'],
        'receipt' => ['facts' => ['Card payments get an emailed receipt', 'invoice or receipt for any payment on request'], 'money' => 'none'],
        'tax_receipt' => ['facts' => ['Sales tax is included in the price.', 'Card payments get an emailed receipt'], 'money' => 'none'],
        'cancel' => ['facts' => ['60 or more days before the event', 'less than 60 days before the event', 'not legal advice', '/terms/'], 'money' => 'none'],
        'cancel_late' => ['facts' => ['less than 60 days before the event', 'not refundable'], 'money' => 'none'],
        'weather' => ['facts' => ['snowstorm', 'flat tire', 'refundable'], 'money' => 'none'],
        'hours' => ['facts' => ['10:00 AM', '11:30 PM', 'Half days', 'June'], 'money' => 'none'],
        'setup_time' => ['facts' => ['No. Guests set up', '8:00 AM', 'one hour before'], 'money' => 'none'],
        'end_time' => ['facts' => ['11:30 PM'], 'money' => 'none', 'forbid' => ['midnight', 'permit']],
        'overnight' => ['facts' => ['No overnight stays'], 'money' => 'none', 'forbid' => ['check-in', 'check-out', 'per night']],
        'decorate_night' => ['facts' => ['8:00 AM', 'Christmas decorations are up in December'], 'money' => 'addon'],
        'capacity' => ['facts' => ['75'], 'money' => 'none'],
        'events_bday' => ['facts' => ['kids birthdays', 'free to attend', 'minimum host age is 25'], 'money' => 'none'],
        'events_wedding' => ['facts' => ['Weddings', 'free to attend', 'minimum host age is 25'], 'money' => 'none'],
        'events_shower' => ['facts' => ['showers', 'free to attend', 'minimum host age is 25'], 'money' => 'none'],
        'events_grad' => ['facts' => ['graduations', 'free to attend', 'minimum host age is 25'], 'money' => 'none'],
        'events_biz' => ['facts' => ['business meetings', 'free to attend', 'minimum host age is 25'], 'money' => 'none'],
        'events_funeral' => ['facts' => ['funerals', 'free to attend', 'minimum host age is 25'], 'money' => 'none'],
        'events_teen' => ['facts' => ['teen', 'free to attend', 'minimum host age is 25'], 'money' => 'none'],
        'host_age' => ['facts' => ['minimum host age is 25', '25 or older'], 'money' => 'none'],
        'tickets' => ['facts' => ['no tickets', 'no cover charge', 'no paid admission', 'do not host concerts'], 'money' => 'none'],
        'band' => ['facts' => ['band or live music', 'not charging'], 'money' => 'none'],
        'fundraiser' => ['facts' => ['no tickets', 'no door fees'], 'money' => 'none'],
        'market' => ['facts' => ['free to attend', 'no merchandise sales', 'no ticket sales'], 'money' => 'none'],
        'food' => ['facts_i' => ['own food', 'warmers', 'stovetop', 'any caterer', 'does not provide catering'], 'money' => 'none'],
        'kitchen' => ['facts' => ['stovetop'], 'any' => [["can't be used", 'not permitted', 'may not be used', 'No cooking']], 'money' => 'none'],
        'fridge' => ['facts' => ['refrigerator', 'ice machine'], 'money' => 'none'],
        'alcohol' => ['facts' => ['BYOB', $byob, $clerk, 'leave it like you got it', 'garbage'], 'money' => 'none', 'phone' => 'clerk', 'forbid' => ['$50']],
        'serve_alcohol' => ['facts' => ["don't supply", 'BYOB', $byob, $clerk], 'money' => 'none', 'phone' => 'clerk', 'forbid' => ['$50', 'Yes. BYOB']],
        'bar' => ['facts' => ['bar area', 'ice machine', 'BYOB', $clerk], 'money' => 'none', 'phone' => 'clerk', 'forbid' => ['$50']],
        'plates' => ['facts' => ['bring your own plates'], 'money' => 'none'],
        'tables' => ['facts' => ['Tables and chairs', 'not set up', 'Staff cannot set the room up'], 'money' => 'none'],
        'setup_help' => ['facts' => ['No. Guests set up', 'Staff cannot set the room up'], 'money' => 'none', 'forbid' => ['extra fee']],
        'setup_cost' => ['facts' => ['8:00 AM', 'one hour before'], 'money' => 'addon', 'forbid' => ['extra fee']],
        'decor' => ['facts' => ['Christmas decorations are up in December'], 'money' => 'none'],
        'tape' => ['facts' => ['Wall tape is not allowed'], 'money' => 'none'],
        'confetti' => ['facts' => ['Balloons are fine', 'Glitter is not allowed', 'Confetti is not allowed'], 'money' => 'none'],
        'balloons' => ['facts' => ['Balloons are fine', 'Wall tape is not allowed', 'Glitter is not allowed'], 'money' => 'none'],
        'glitter' => ['facts' => ['Glitter is not allowed'], 'money' => 'none'],
        'tv' => ['facts' => ['television'], 'money' => 'none', 'forbid' => ['have a projector', 'projector is available', 'yes, a projector']],
        'sound' => ['facts' => ['sound system'], 'money' => 'none'],
        'wifi' => ['any' => [['Wi-Fi', 'Wifi']], 'money' => 'none'],
        'games' => ['facts' => ['pinball', 'pool table', 'shuffleboard'], 'money' => 'none'],
        'hvac' => ['facts_i' => ['heat', 'air conditioning'], 'money' => 'none'],
        'restroom' => ['facts' => ['two restrooms'], 'money' => 'none'],
        'access' => ['facts' => ['The space is accessible'], 'money' => 'none'],
        'entrance' => ['facts' => ['one main entrance', 'back entrance', 'surprise guest'], 'money' => 'none'],
        'place' => ['facts' => ['2220 37th Street, Moline, IL 61265'], 'money' => 'none', 'forbid' => ['2212']],
        'parking' => ['facts' => ['Free parking on premises'], 'money' => 'none'],
        'truck' => ['facts' => ['place for a food truck'], 'money' => 'none'],
        'rules' => ['facts' => ['No overnight stays', 'No cooking', 'stovetop', 'no taps', 'BYOB', 'City of Moline', $byob, $clerk, 'not set up', 'garbage', '75', 'Christmas decorations are up in December', 'insurance', '11:30 PM', 'no tickets'], 'money' => 'none', 'phone' => 'clerk', 'forbid' => ['$50', 'midnight', '90 or more days']],
        'cleanup' => ['facts' => ['leave it like you got it', 'garbage'], 'money' => 'none'],
        'smoke' => ['facts' => ['outside only', 'Vaping'], 'money' => 'none'],
        'pets' => ['facts' => ['Service animals only', 'No pets'], 'money' => 'none'],
        'cameras' => ['facts' => ['security cameras'], 'money' => 'none'],
        'noise' => ['facts' => ['quiet after 11:30 PM'], 'money' => 'none'],
        'insurance' => ['facts' => ['own home or renters policy', 'assume the risk'], 'money' => 'none'],
        'agreement' => ['facts' => ['rental agreement', '/terms/'], 'money' => 'none'],
        'onsite' => ['facts' => ['No one is on site', 'connected offices', 'contract is signed'], 'money' => 'none'],
        'damage' => ['facts' => ['damage deposit covers cleaning and damage', 'may be charged', 'Terms', 'walk-through'], 'money' => 'damage'],
        'contact' => ['facts' => ['boardroomqc@gmail.com', 'no phone number', '2220 37th Street, Moline, IL 61265'], 'lead' => true, 'money' => 'none', 'phone' => 'never', 'forbid' => ['2212']],
        'phone' => ['facts' => ['boardroomqc@gmail.com', 'no phone number', '2220 37th Street, Moline, IL 61265'], 'lead' => true, 'money' => 'none', 'phone' => 'never', 'forbid' => ['2212', '309']],
        'person' => ['facts' => ['Contact form', 'boardroomqc@gmail.com', 'contract is signed'], 'money' => 'none', 'phone' => 'never'],
        'refuse' => ['facts' => ['I can only help with The Boardroom', 'Contact form'], 'lead' => true, 'money' => 'none', 'phone' => 'never', 'forbid' => ['owner']],
        'legal' => ['facts' => ['legal advice', 'Contact form'], 'lead' => true, 'money' => 'none', 'phone' => 'never', 'forbid' => ['owner']],
    ];
    $empty = ['facts' => [], 'facts_i' => [], 'any' => [], 'forbid' => [], 'lead' => null, 'exact' => null, 'dynamic' => '', 'money' => 'none', 'phone' => 'never', 'dates' => false, 'checks' => false];
    if ($topic === '' || $topic === null) return $empty;
    if (strpos($topic, '+') !== false) {
        $merged = $empty;
        $merged['money'] = 'none';
        $moneys = [];
        $phones = [];
        foreach (explode('+', $topic) as $part) {
            $one = brb_chat_topic_spec($part);
            $merged['facts'] = array_merge($merged['facts'], $one['facts']);
            $merged['facts_i'] = array_merge($merged['facts_i'], $one['facts_i']);
            $merged['any'] = array_merge($merged['any'], $one['any']);
            $merged['forbid'] = array_merge($merged['forbid'], $one['forbid']);
            if ($one['lead'] === true) $merged['lead'] = true;
            if (!empty($one['dates'])) $merged['dates'] = true;
            if (!empty($one['checks'])) $merged['checks'] = true;
            if ($one['dynamic'] !== '') $merged['dynamic'] = $one['dynamic'];
            $moneys[] = $one['money'];
            $phones[] = $one['phone'];
        }
        $merged['facts'] = array_values(array_unique($merged['facts']));
        $merged['facts_i'] = array_values(array_unique($merged['facts_i']));
        $merged['forbid'] = array_values(array_unique($merged['forbid']));
        if (in_array('date', $moneys, true)) $merged['money'] = 'date';
        elseif (in_array('addon', $moneys, true)) $merged['money'] = 'addon';
        elseif (in_array('damage', $moneys, true)) $merged['money'] = 'damage';
        $merged['phone'] = in_array('clerk', $phones, true) ? 'clerk' : 'never';
        $merged['exact'] = null;
        return $merged;
    }
    if (!isset($specs[$topic])) return $empty;
    return array_merge($empty, $specs[$topic]);
}

function brb_chat_allowed_money($spec, $question) {
    $allowed = [];
    if ($spec['money'] === 'damage' || $spec['money'] === 'addon') {
        if ($spec['money'] === 'damage') {
            $allowed['$250'] = true;
        }
        if ($spec['money'] === 'addon') $allowed[brb_whole_money(brb_addon_price())] = true;
    }
    if ($spec['money'] === 'date' && brb_quote_prices_enabled()) {
        $parsed = brb_chat_dates_for_score($question);
        foreach ($parsed['dates'] as $ymd) {
            if (brb_chat_date_status($ymd) !== 'open') continue;
            $amount = brb_estimate_amount($ymd);
            $allowed[brb_whole_money($amount)] = true;
            $allowed[brb_whole_money(brb_deposit_due_amount($amount))] = true;
        }
    }
    return $allowed;
}

function brb_chat_dates_for_score($question) {
    $parsed = brb_chat_dates($question);
    if (function_exists('brb_chat_normalize')) {
        $norm = brb_chat_dates(brb_chat_normalize($question));
        $parsed['dates'] = array_values(array_unique(array_merge($parsed['dates'], $norm['dates'])));
        $parsed['invalid'] = !empty($parsed['invalid']) || !empty($norm['invalid']);
        $parsed['too_long'] = !empty($parsed['too_long']) || !empty($norm['too_long']);
    }
    if (!isset($parsed['invalid'])) $parsed['invalid'] = false;
    return $parsed;
}

function brb_chat_score_dynamic($spec, $question, $reply, &$missing, &$bad) {
    if ($spec['dynamic'] === 'nye') {
        $now = brb_now()->setTime(0, 0);
        $year = (int) $now->format('Y');
        $ymd = sprintf('%04d-12-31', $year);
        if ($ymd < $now->format('Y-m-d')) $ymd = sprintf('%04d-12-31', $year + 1);
        $question = 'Is ' . $ymd . ' available? ' . $question;
        $spec['dynamic'] = 'date';
        $parsed = ['dates' => [$ymd], 'invalid' => false];
    } else {
        $parsed = brb_chat_dates_for_score($question);
    }
    if ($spec['dynamic'] !== 'date') return;
    if (empty($parsed['dates'])) {
        $missing[] = 'a parsed date';
        return;
    }
    $open = false;
    foreach ($parsed['dates'] as $ymd) {
        $label = brb_chat_date_label($ymd);
        if (strpos($reply, $label) === false) $missing[] = $label;
        $status = brb_chat_date_status($ymd);
        if ($status === 'past' && strpos($reply, 'passed') === false) $missing[] = 'passed';
        if ($status === 'blackout' && stripos($reply, 'unavailable') === false) $missing[] = 'unavailable';
        if ($status === 'reserved' && strpos($reply, 'already reserved') === false) $missing[] = 'already reserved';
        if ($status === 'partial' && strpos($reply, 'fully open') === false && strpos($reply, 'already reserved') === false) $missing[] = 'not fully open';
        if ($status === 'notice' && strpos($reply, '48 hours') === false) $missing[] = '48 hours';
        if ($status === 'open') {
            $open = true;
            if (strpos($reply, 'looks open') === false) $missing[] = 'looks open';
        }
        if (in_array($status, ['blackout', 'reserved', 'partial'], true) && strpos($reply, '$') !== false) $bad[] = 'price on a blocked date';
        if ($status === 'notice' && strpos($reply, '$') !== false) $bad[] = 'price inside the notice window';
    }
    if ($open && brb_quote_prices_enabled()) {
        if (strpos($reply, 'an estimate for that date') === false) $missing[] = 'an estimate for that date';
        if (substr_count($reply, 'not a binding commitment') !== 1) $bad[] = 'binding sentence count';
    }
    if ($open && !brb_quote_prices_enabled() && strpos($reply, 'will confirm pricing') === false) $missing[] = 'will confirm pricing';
}

function brb_chat_score_audit($spec, $question, $reply, &$bad) {
    $allowed = brb_chat_allowed_money($spec, $question);
    if (preg_match_all('/\$\d{1,3}(?:,\d{3})*(?:\.\d{2})?/', $reply, $found)) {
        foreach (array_unique($found[0]) as $money) {
            if (!isset($allowed[$money])) $bad[] = 'unapproved ' . $money;
        }
    }
    $other = 'air' . 'bnb';
    foreach (['midnight', '90 or more days', '90 days', 'per night', 'check-in', 'check-out', 'sales tax is added', $other] as $needle) {
        if ($needle !== '' && stripos($reply, $needle) !== false) $bad[] = 'forbidden ' . $needle;
    }
    if (preg_match('/\$50(?!\d)/', $reply)) $bad[] = 'forbidden $50';
    if (preg_match('/\bowner\b/i', $reply)) $bad[] = 'forbidden owner';
    if (strpos($reply, '✨') !== false) $bad[] = 'emoji';
    $allow_clerk = ($spec['phone'] === 'clerk');
    if (preg_match('/\d{3}-\d{3}-\d{4}/', $reply, $phone)) {
        if ($phone[0] !== '309-524-2004' || !$allow_clerk) $bad[] = 'unapproved phone';
    } elseif (!$allow_clerk && strpos($reply, '309') !== false) {
        $bad[] = 'unapproved 309';
    }
    if (!$spec['dates'] && preg_match('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}\b/', $reply)) {
        $bad[] = 'unapproved date';
    }
    $asks_check = (bool) preg_match('/\b(check|checks|payable)\b/i', $question) && !preg_match('/check-?\s?in|check-?\s?out/i', $question);
    if (empty($spec['checks']) && !$asks_check && strpos($reply, '2212') !== false) $bad[] = 'unapproved check address';
}

function brb_chat_score_item($item) {
    $question = (string) $item['q'];
    $answer = brb_chat_answer($question);
    $reply = (string) ($answer['reply'] ?? '');
    $spec = brb_chat_topic_spec($item['topic'] ?? '');
    $missing = [];
    $bad = [];
    if ($spec['exact'] !== null && $reply !== $spec['exact']) $missing[] = 'exact reply';
    foreach ($spec['facts'] as $fact) {
        if ($fact !== '' && strpos($reply, $fact) === false) $missing[] = $fact;
    }
    foreach ($spec['facts_i'] as $fact) {
        if ($fact !== '' && stripos($reply, $fact) === false) $missing[] = $fact;
    }
    foreach ($spec['any'] as $group) {
        $hit = false;
        foreach ($group as $fact) {
            if (strpos($reply, $fact) !== false || stripos($reply, $fact) !== false) { $hit = true; break; }
        }
        if (!$hit) $missing[] = 'one of: ' . implode(' | ', $group);
    }
    foreach ($spec['forbid'] as $fact) {
        if ($fact !== '' && stripos($reply, $fact) !== false) $bad[] = 'forbid ' . $fact;
    }
    brb_chat_score_dynamic($spec, $question, $reply, $missing, $bad);
    brb_chat_score_audit($spec, $question, $reply, $bad);
    $lead = !empty($answer['lead']);
    if (!empty($item['unanswerable']) || $spec['lead'] === true) {
        if (!$lead) $bad[] = 'missing lead';
    }
    if ($spec['lead'] === false && $lead) $bad[] = 'unexpected lead';
    $unknown = (($answer['intent'] ?? '') === 'unknown') || (stripos($reply, "I'm not sure about that one") !== false);
    $pass = !$missing && !$bad;
    $wrong = !$pass && !$unknown;
    return [
        'id' => $item['id'] ?? '',
        'group' => $item['group'] ?? '',
        'q' => $question,
        'topic' => $item['topic'] ?? '',
        'expected_intents' => $item['intents'] ?? [],
        'pass' => $pass,
        'unknown' => $unknown,
        'wrong' => $wrong,
        'lead' => $lead,
        'intent' => $answer['intent'] ?? '',
        'missing' => $missing,
        'bad' => $bad,
        'reply' => $reply,
    ];
}

function brb_chat_score_corpus() {
    $items = brb_chat_corpus_items();
    $rows = [];
    $groups = [];
    foreach ($items as $item) {
        $row = brb_chat_score_item($item);
        $rows[] = $row;
        $g = $row['group'] !== '' ? $row['group'] : 'other';
        if (!isset($groups[$g])) $groups[$g] = ['n' => 0, 'pass' => 0, 'unknown' => 0, 'wrong' => 0];
        $groups[$g]['n']++;
        if ($row['pass']) $groups[$g]['pass']++;
        if ($row['unknown']) $groups[$g]['unknown']++;
        if ($row['wrong']) $groups[$g]['wrong']++;
    }
    foreach ($groups as $name => $stats) {
        $n = max(1, $stats['n']);
        $groups[$name]['pass_rate'] = round($stats['pass'] / $n, 4);
        $groups[$name]['unknown_rate'] = round($stats['unknown'] / $n, 4);
    }
    return ['groups' => $groups, 'total' => count($rows), 'items' => $rows];
}

function brb_chat_score_print($report) {
    echo "CHAT SCORE total {$report['total']}\n";
    foreach ($report['groups'] as $name => $stats) {
        echo sprintf(
            "%s n=%d pass=%d (%.1f%%) unknown=%d (%.1f%%) wrong=%d\n",
            $name,
            $stats['n'],
            $stats['pass'],
            $stats['pass_rate'] * 100,
            $stats['unknown'],
            $stats['unknown_rate'] * 100,
            $stats['wrong']
        );
    }
}
