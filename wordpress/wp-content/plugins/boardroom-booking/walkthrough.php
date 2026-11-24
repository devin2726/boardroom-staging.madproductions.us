<?php
/**
 * 2.5.91: No-pricing walk-through booking flow (Max, Nov 24, 2026, 1:44 PM CT).
 *
 * Bookings > Settings > "Booking flow shown to guests":
 *   walkthrough = Hold a Date request, tentative hold, in-person walk-through, price quoted in person.
 *                 The only price on the public side is the price range line.
 *   calendar    = the old flow (prices on the calendar, deposit facts, priced request email).
 *                 Pick it to reinstall the pricing flow exactly as it was in 2.5.90.
 *
 * Staff invoicing and Stripe pay links are not changed in either mode. Legal pages
 * (Terms, Rental Agreement, Privacy) are not changed here; they need Devin's sign-off.
 */
if (!defined('ABSPATH')) exit;

function brb_pricing_modes() {
    return [
        'walkthrough' => 'Hold a Date + walk-through (no prices; price quoted in person)',
        'calendar' => 'Old flow: prices on the calendar (reinstalls the 2.5.90 pricing)',
    ];
}
function brb_pricing_mode() {
    $mode = (string) brb_opt('pricing_mode');
    $mode = (string) apply_filters('brb_pricing_mode', $mode);
    return array_key_exists($mode, brb_pricing_modes()) ? $mode : 'walkthrough';
}
function brb_walkthrough_mode() {
    return brb_pricing_mode() === 'walkthrough';
}
function brb_public_price_range_default() {
    return "$850\u{2013}$1,500 depending on seasonal availability";
}
/** The single public price line, e.g. "$850–$1,500 depending on seasonal availability". */
function brb_public_price_range() {
    $line = trim((string) brb_opt('public_price_range'));
    return $line !== '' ? $line : brb_public_price_range_default();
}
function brb_walk_price_line() {
    return 'Rentals run ' . brb_public_price_range() . '. We quote your exact price in person at your walk-through.';
}
function brb_walk_days() {
    return ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday'];
}
function brb_walk_windows() {
    return ['morning' => 'Morning (8 AM–12 PM)', 'afternoon' => 'Afternoon (12–4 PM)'];
}
function brb_walk_not_confirmed_line() {
    return 'This is only your preference. The walk-through is not set until we reach out and confirm a time with you.';
}
/** Reads the walk-through preference from a posted form. Required in walk-through mode. */
function brb_collect_walk_pref($post, $required = true) {
    $days = [];
    $raw_days = $post['walk_days'] ?? [];
    if (!is_array($raw_days)) $raw_days = [$raw_days];
    foreach ($raw_days as $d) {
        $d = sanitize_key((string) $d);
        if (isset(brb_walk_days()[$d]) && !in_array($d, $days, true)) $days[] = $d;
    }
    $windows = [];
    $raw_win = $post['walk_window'] ?? [];
    if (!is_array($raw_win)) $raw_win = [$raw_win];
    foreach ($raw_win as $w) {
        $w = sanitize_key((string) $w);
        if (isset(brb_walk_windows()[$w]) && !in_array($w, $windows, true)) $windows[] = $w;
    }
    $order = array_keys(brb_walk_days());
    usort($days, function ($a, $b) use ($order) { return array_search($a, $order, true) - array_search($b, $order, true); });
    $worder = array_keys(brb_walk_windows());
    usort($windows, function ($a, $b) use ($worder) { return array_search($a, $worder, true) - array_search($b, $worder, true); });
    if ($required) {
        if (!$days) return new WP_Error('walk_days', 'Please pick at least one weekday (Monday to Friday) for your walk-through.');
        if (!$windows) return new WP_Error('walk_window', 'Please pick a walk-through time: morning (8 AM–12 PM) or afternoon (12–4 PM).');
    }
    return ['walk_days' => implode(',', $days), 'walk_window' => implode(',', $windows)];
}
/** "Monday, Wednesday · Morning (8 AM–12 PM)" or '' when nothing was given. */
function brb_walk_pref_label($b) {
    $days = array_filter(explode(',', (string) (is_array($b) ? ($b['walk_days'] ?? '') : ($b->walk_days ?? ''))));
    $wins = array_filter(explode(',', (string) (is_array($b) ? ($b['walk_window'] ?? '') : ($b->walk_window ?? ''))));
    $dl = [];
    foreach ($days as $d) if (isset(brb_walk_days()[$d])) $dl[] = brb_walk_days()[$d];
    $wl = [];
    foreach ($wins as $w) if (isset(brb_walk_windows()[$w])) $wl[] = brb_walk_windows()[$w];
    if (!$dl && !$wl) return '';
    $parts = [];
    if ($dl) $parts[] = implode(', ', $dl);
    if ($wl) $parts[] = implode(' or ', $wl);
    return implode(' · ', $parts);
}
function brb_walk_fields_html() {
    $h = '<fieldset class="brb-field brb-walk"><legend>Walk-through: preferred weekday<span class="brb-req">*</span></legend>';
    $h .= '<p class="brb-small">Before we confirm a booking, we meet you at The Boardroom for a short walk-through and quote the price in person. Pick the weekdays that work for you.</p>';
    $h .= '<div class="brb-walk-opts">';
    foreach (brb_walk_days() as $k => $label) {
        $h .= '<label class="brb-check brb-walk-opt"><input type="checkbox" name="walk_days[]" value="' . esc_attr($k) . '"> ' . esc_html($label) . '</label>';
    }
    $h .= '</div></fieldset>';
    $h .= '<fieldset class="brb-field brb-walk"><legend>Walk-through: preferred time<span class="brb-req">*</span></legend><div class="brb-walk-opts">';
    foreach (brb_walk_windows() as $k => $label) {
        $h .= '<label class="brb-check brb-walk-opt"><input type="checkbox" name="walk_window[]" value="' . esc_attr($k) . '"> ' . esc_html($label) . '</label>';
    }
    $h .= '</div><p class="brb-small brb-walk-note">' . esc_html(brb_walk_not_confirmed_line()) . '</p></fieldset>';
    return $h;
}
function brb_walk_steps() {
    return [
        'Hold a Date. Pick an open date and send a request. Nothing is charged.',
        'We email you a confirmation and place a tentative hold on your date.',
        'We reach out to set up an in-person walk-through on a weekday, morning (8 AM–12 PM) or afternoon (12–4 PM).',
        'At the walk-through we quote your price. Rentals run ' . brb_public_price_range() . '.',
        'Once you\'re ready, we send your invoice to lock in the date.',
    ];
}
function brb_walk_review_line() {
    return 'Your date is tentatively held. We\'ll reach out to set up an in-person walk-through, where we quote your price. Please don\'t count on the walk-through time until we confirm it with you.';
}
function brb_walk_hold_line() {
    return 'A tentative hold is not a confirmed booking. Your date is booked once you approve the quote and we confirm it.';
}
/** Guest "We received your request" email body in walk-through mode. No price, deposit or fee. */
function brb_walk_request_guest_body($b) {
    $pref = brb_walk_pref_label($b);
    $detail = 'When: ' . brb_when($b) . "\n" . brb_event_type_line($b) . 'Estimated Guests: ' . (int) $b->guests . "\n";
    $about = trim((string) ($b->event_about ?? ''));
    if ($about !== '') $detail .= 'About the event: ' . $about . "\n";
    if ($pref !== '') $detail .= 'Your walk-through preference: ' . $pref . "\n";
    return brb_guest_hello_line() . "\n\n"
        . "We received your request, and your date is tentatively held.\n\n"
        . "Next, we'll reach out to set up an in-person walk-through of The Boardroom. We'll quote your price at the walk-through. Please don't count on the walk-through time until we confirm it with you.\n\n"
        . $detail . "\n"
        . brb_walk_hold_line() . "\n\n"
        . brb_no_overnight_line() . "\n" . brb_guest_out_line() . "\n" . brb_guest_free_line() . "\n" . brb_hours_line() . "\n\n"
        . 'View your request: ' . brb_guest_link($b) . "\n";
}
/** preg_replace without "$1"-style expansion, so "$850" in the new text stays literal. */
function brb_walk_replace_details($pattern, $replacement, $html) {
    $out = preg_replace_callback($pattern, function () use ($replacement) { return $replacement; }, (string) $html, 1);
    return is_string($out) ? $out : (string) $html;
}
/** Public FAQ in walk-through mode (no dollar amounts besides the range line). */
function brb_walk_faq_swap($html) {
    $how = '<details><summary>How do I book?</summary><p>We\'d love to host you. Pick an open date on the <a href="/reserve-the-boardroom-quad-cities/">Book</a> page and send a Hold a Date request. Nothing is charged. We email you a confirmation and place a tentative hold on your date. Then we reach out to set up an in-person walk-through on a weekday, morning (8 AM–12 PM) or afternoon (12–4 PM), and we quote your price at the walk-through. Bookings need at least 48 hours\' notice.</p></details>';
    $html = brb_walk_replace_details('/<details><summary>How do I book\?<\/summary>.*?<\/details>/s', $how, $html);
    $steps = '<details><summary>How booking works</summary><p>' . esc_html(implode(' ', brb_walk_steps())) . '</p></details>';
    $html = brb_walk_replace_details('/<details><summary>How booking works<\/summary>.*?<\/details>/s', $steps, $html);
    $price = '<details><summary>How is pricing set?</summary><p>' . esc_html(brb_walk_price_line()) . '</p></details>';
    $pay = '<details><summary>How do I pay?</summary><p>After your walk-through, once you approve your quote, we send an invoice. Pay by check or in person, or online by card (a card processing fee applies). Mail or drop off checks payable to Boardroom QC LLC at the office, ' . BRB_CHECK_ADDRESS . '. We don\'t keep set hours. We meet on demand and set a time together, by appointment. Payment terms are in the <a href="/terms/">Rental Terms</a>. Sales tax is included in the price.</p></details>';
    $html = brb_walk_replace_details('/<details><summary>How do I pay\?<\/summary>.*?<\/details>/s', $price . $pay, $html);
    $walk = '<details><summary>What is the walk-through?</summary><p>A short, in-person visit at The Boardroom before your booking is confirmed. We show you the space, go over your plans and the house rules, and quote your price. Walk-throughs are on weekdays, morning (8 AM–12 PM) or afternoon (12–4 PM). You pick your preference on the request form, and we reach out to confirm a time.</p></details>';
    $html = str_replace('<details><summary>What dates are open?</summary>', $walk . '<details><summary>What dates are open?</summary>', $html);
    $html = str_replace('(including the 3% card processing fee)', '(including the card processing fee)', $html);
    return $html;
}
/** Public blog/page copy that described the old pricing flow, swapped at render time (database untouched). */
function brb_walk_copy_map() {
    return [
        'Keep in mind a request does not hold the date until the deposit is received and approved.' => 'Your request places a tentative hold on the date, and we reach out to set up a walk-through where we quote your price.',
        'See open dates and pricing on our calendar.' => 'See open dates on our calendar.',
    ];
}
add_filter('the_content', function ($content) {
    if (!brb_walkthrough_mode() || is_admin()) return $content;
    $content = (string) $content;
    if (function_exists('is_page') && is_page('faq') && stripos($content, 'How many guests can The Boardroom hold') !== false) {
        $content = brb_walk_faq_swap($content);
    }
    return strtr($content, brb_walk_copy_map());
}, 9);
