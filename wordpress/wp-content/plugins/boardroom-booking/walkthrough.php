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
/*
 * Public price (walk-through flow).
 * Max, Nov 24, 2026, 3:16 PM CT: "Starting at $850".
 * Max, Nov 25, 2026, 5:15 AM CT: "Ok but pricing has to say Starting at $850+. Instead of the range".
 * Reads the existing starting_price setting ("Starting price ($ per day)", default 850), one source.
 * The "+" is appended in this helper after the formatted amount, so $1,200 becomes $1,200+ (never $850++).
 */
function brb_public_price_from() {
    $n = (float) brb_opt('starting_price');
    if ($n <= 0) $n = 850;
    // Whole dollars (Les round 5): decimals are rounded. The "+" is added once after the formatted amount.
    $n = max(1, (int) round($n));
    return rtrim(brb_whole_money($n), '+') . '+';
}
/** "Starting at $850+" */
function brb_public_price_range() {
    return 'Starting at ' . brb_public_price_from();
}
function brb_walk_price_line() {
    return brb_public_price_range() . '. We quote your exact price at the walk-through for first-time renters, or when we reach out if you\'ve rented with us before.';
}
/*
 * First-time vs returning renters (Max, Nov 25, 2026, 7:07 AM CT, relayed by the Czar):
 * "first-time renters have to do the walkthrough. if you've rented before you, you can just place a hold on it."
 * Calm wording, no exclamation points, no auto-confirmation, no time limits. Tentative-hold language kept.
 */
function brb_walk_first_time_line() {
    return 'First time renting The Boardroom? We\'ll set up an in-person walk-through before your date is confirmed. If you\'ve rented with us before, you can simply place a tentative hold, and no walk-through is needed.';
}
function brb_walk_first_time_form_note() {
    return 'If you\'ve rented with us before, you can skip the walk-through. We still ask for a preferred weekday and time in case plans change.';
}
/** After a Hold a Date request (email, thanks screen, status). No auto-confirmation, no deadlines. */
function brb_walk_followup_line() {
    return 'If this is your first time renting The Boardroom, we\'ll reach out to set up an in-person walk-through. If you\'ve rented with us before, we\'ll reach out to confirm your hold.';
}
function brb_is_returning_renter($b) {
    if (!$b) return false;
    return !empty(is_object($b) ? ($b->returning_renter ?? 0) : ($b['returning_renter'] ?? 0));
}
/**
 * Whether staff must mark the walk-through complete before approval.
 * Old flow always requires it (81776f6). In the new flow, a staff-only
 * "Returning renter (walk-through not needed)" flag waives it.
 */
function brb_walk_required_for_approval($b) {
    if (function_exists('brb_walkthrough_mode') && brb_walkthrough_mode() && brb_is_returning_renter($b)) return false;
    return true;
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
    $h .= '<p class="brb-small">First-time renters meet us at The Boardroom for a short walk-through, where we quote the price in person. Pick the weekdays that work for you.</p>';
    $h .= '<p class="brb-small brb-walk-return">' . esc_html(brb_walk_first_time_form_note()) . '</p>';
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
    // Checkbox groups cannot use "required"; stop the submit in the browser so typed details are not lost. The server checks again.
    $h .= '<script>(function(){var f=document.currentScript&&document.currentScript.closest("form");if(!f)return;f.addEventListener("submit",function(e){'
        . 'var d=f.querySelector("input[name=\'walk_days[]\']:checked"),w=f.querySelector("input[name=\'walk_window[]\']:checked");'
        . 'if(!d||!w){e.preventDefault();alert(!d?"Please pick at least one weekday (Monday to Friday) for your walk-through.":"Please pick a walk-through time: morning (8 AM\u201312 PM) or afternoon (12\u20134 PM).");'
        . 'var t=f.querySelector(".brb-walk");if(t)t.scrollIntoView({block:"center"});}});})();</script>';
    return $h;
}
function brb_walk_steps() {
    return [
        'Hold a Date. Pick an open date and send a request. Nothing is charged.',
        'We email you a confirmation and place a tentative hold on your date.',
        brb_walk_first_time_line(),
        'For a first-time rental, the walk-through is on a weekday, morning (8 AM–12 PM) or afternoon (12–4 PM). We quote your price there, starting at ' . brb_public_price_from() . '.',
        'Once you\'re ready, we send your invoice to lock in the date.',
    ];
}
function brb_walk_review_line() {
    return 'Your date is tentatively held. ' . brb_walk_followup_line() . ' Please don\'t count on a walk-through time until we confirm it with you.';
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
        . brb_walk_followup_line() . " Please don't count on a walk-through time until we confirm it with you.\n\n"
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
    $how = '<details><summary>How do I book?</summary><p>We\'d love to host you. Pick an open date on the <a href="/reserve-the-boardroom-quad-cities/">Book</a> page and send a Hold a Date request. Nothing is charged. We email you a confirmation and place a tentative hold on your date. ' . esc_html(brb_walk_first_time_line()) . ' For a first-time rental, the walk-through is on a weekday, morning (8 AM–12 PM) or afternoon (12–4 PM), and we quote your price there. Bookings need at least 48 hours\' notice.</p></details>';
    $html = brb_walk_replace_details('/<details><summary>How do I book\?<\/summary>.*?<\/details>/s', $how, $html);
    $steps = '<details><summary>How booking works</summary><p>' . esc_html(implode(' ', brb_walk_steps())) . '</p></details>';
    $html = brb_walk_replace_details('/<details><summary>How booking works<\/summary>.*?<\/details>/s', $steps, $html);
    $price = '<details><summary>How is pricing set?</summary><p>' . esc_html(brb_walk_price_line()) . '</p></details>';
    $pay = '<details><summary>How do I pay?</summary><p>Once you approve your quote, we send an invoice. First-time renters approve it after the walk-through. If you\'ve rented with us before, we\'ll be in touch about the quote when we confirm your hold. Pay by check or in person, or online by card (a card processing fee applies). Mail or drop off checks payable to Boardroom QC LLC at the office, ' . BRB_CHECK_ADDRESS . '. We don\'t keep set hours. We meet on demand and set a time together, by appointment. Payment terms are in the <a href="/terms/">Rental Terms</a>. Sales tax is included in the price.</p></details>';
    $html = brb_walk_replace_details('/<details><summary>How do I pay\?<\/summary>.*?<\/details>/s', $price . $pay, $html);
    $walk = '<details><summary>What is the walk-through?</summary><p>A short, in-person visit at The Boardroom before your booking is confirmed. We show you the space, go over your plans and the house rules, and quote your price. Walk-throughs are on weekdays, morning (8 AM–12 PM) or afternoon (12–4 PM). You pick your preference on the request form, and we reach out to confirm a time. ' . esc_html(brb_walk_first_time_line()) . '</p></details>';
    $html = str_replace('<details><summary>What dates are open?</summary>', $walk . '<details><summary>What dates are open?</summary>', $html);
    $html = str_replace('(including the 3% card processing fee)', '(including the card processing fee)', $html);
    // QA MINOR (Nov 24): neutral cancellation answer, no "deposit" wording. Same 60-day rule as the Rental Terms.
    $cancel = '<details><summary>What is the cancellation policy?</summary><p>Cancel in writing. If we receive your written cancellation 60 or more days before the event, what you have paid is refundable, less any non-refundable processing charges (including the card processing fee) and costs already incurred. Cancellations received less than 60 days before the event are not refundable. We go over this when we quote your price. See the <a href="/terms/">Rental Terms</a>. If a summary and the signed agreement differ, the signed agreement controls.</p></details>';
    $html = brb_walk_replace_details('/<details><summary>What is the cancellation policy\?<\/summary>.*?<\/details>/s', $cancel, $html);
    return $html;
}
/** Public blog/page copy that described the old pricing flow, swapped at render time (database untouched). */
function brb_walk_copy_map() {
    return [
        'Keep in mind a request does not hold the date until the deposit is received and approved.' => 'Your request places a tentative hold on the date. ' . brb_walk_first_time_line(),
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

/* ---------- /rental-checklist/ (QA SHOULD, Les, Nov 24, 2026) ----------
 * Display-time swap of the "deposit" line on the checklist page ([brb_checklist] shortcode).
 * Stored checklist text and the checklist emails are unchanged. Old flow shows the original.
 * Original text: pricing-backup-2026-11-24/checklist-original.txt
 */
function brb_walk_checklist_deposit_line() { return 'The deposit is received and approved.'; }
function brb_walk_checklist_hold_line() { return 'Your date is tentatively held once you send a Hold a Date request. It is confirmed once you approve the quote and we confirm it. First-time renters come in for a walk-through first. If you\'ve rented with us before, no walk-through is needed.'; }
function brb_walk_checklist_swap($html) {
    if (!brb_walkthrough_mode()) return $html;
    return str_replace('<li>' . esc_html(brb_walk_checklist_deposit_line()) . '</li>', '<li>' . esc_html(brb_walk_checklist_hold_line()) . '</li>', (string) $html);
}
add_action('init', function () {
    global $shortcode_tags;
    if (empty($shortcode_tags['brb_checklist'])) return;
    $orig = $shortcode_tags['brb_checklist'];
    add_shortcode('brb_checklist', function ($atts = [], $content = null, $tag = '') use ($orig) {
        return brb_walk_checklist_swap(call_user_func($orig, $atts, $content, $tag));
    });
}, 20);

