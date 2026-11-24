<?php
/**
 * 2.5.80: Nov 16, 2026 final Rental Terms and Rental Agreement across the site.
 * Guards: no $250 / "damage deposit" / BYOB / "renters insurance" / "one week before"
 * in rendered public strings, and the upgrade never rewrites the 2212 check
 * address back to 2220. Also self-checks the LLC name, price parts, 80 guests,
 * the 7-day balance, the ADA rule, and the version order.
 * Run with wp eval-file.
 */
if (!defined('ABSPATH')) exit;
// 2.5.91: this suite covers the old pricing flow (Settings > Booking flow = old flow). The walk-through flow is in no-pricing-walkthrough.php.
add_filter('brb_pricing_mode', function () { return 'calendar'; }, 5);

if (!function_exists('brb_t')) {
    $GLOBALS['brb_fail'] = 0;
    $GLOBALS['brb_ok'] = 0;
    function brb_t($cond, $msg) {
        if ($cond) {
            $GLOBALS['brb_ok']++;
            echo "ok  $msg\n";
        } else {
            $GLOBALS['brb_fail']++;
            echo "FAIL $msg\n";
        }
    }
}
function brb_tf_plain($html) {
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}
function brb_tf_reply($q, $more = false) {
    $history = $more ? [['role' => 'user', 'text' => $q]] : [];
    $r = brb_chat_answer($more ? 'tell me more' : $q, $history);
    return (string) ($r['reply'] ?? '');
}

$saved_opt = get_option('brb_settings');
$saved_ver = get_option('brb_version');
try {
    brb_t(BRB_VER === '2.5.94' && version_compare(BRB_VER, '2.5.80', '>'), 'plugin version is 2.5.94');
    brb_t(function_exists('br_ver') && br_ver() === '1.2.52' && strpos((string) file_get_contents(get_template_directory() . '/style.css', false, null, 0, 400), 'Version: 1.2.52') !== false, 'theme version is 1.2.52 (after 1.2.51) in br_ver() and style.css');

    $seo_check = br_seo_pages();
    brb_t($seo_check['faq']['description'] === 'Answers about booking, hours, food and drink, capacity (up to 80 guests), and cancellation at The Boardroom, a party and event venue in Moline, IL.' && strlen($seo_check['faq']['description']) === 147, 'FAQ meta description is the approved 147-character text');
    brb_t($seo_check['contact']['description'] === 'Questions about The Boardroom, a party and event venue at 2220 37th Street in Moline, IL? Email boardroomqc@gmail.com or use the contact form.' && $seo_check['gallery']['description'] === 'See photos of The Boardroom, a party and event venue in Moline, IL (Quad Cities) for birthdays, showers, holiday parties, and meetings of up to 80 guests.', 'Contact and Gallery meta descriptions match PR #93');
    // ---- rendered public strings
    $checklist_saved = brb_opt();
    $old_checklist = $checklist_saved;
    $old_checklist['checklist'] = implode("\n", [
        'Sign the rental agreement.',
        'The balance is due no later than one week before the event.',
        'The refundable damage deposit is a flat $250. It is charged with the booking deposit and returned if the room is left as you found it.',
        'The damage deposit is refunded after the event, once the space is checked, by your original payment method: through Stripe for card payments (less the card processing fee) or by check.',
        'BYOB is allowed.',
        'Vaping leaves a residue. All smoking is outside.',
        'Renters insurance covering your event is required. We may ask for proof before giving you access.',
        'Vendors (caterer, DJ, bartender) send proof of insurance at least 7 days before.',
        'Service animals only. No pets.',
    ]);
    update_option('brb_settings', $old_checklist);
    $stored_checklist = brb_checklist_html() . "\n" . brb_checklist_plain();
    update_option('brb_settings', $checklist_saved);

    $seo = function_exists('br_seo_pages') ? br_seo_pages() : [];
    $seo_text = '';
    foreach ($seo as $slug => $row) $seo_text .= ' ' . ($row['title'] ?? '') . ' ' . ($row['description'] ?? '');
    $front = (string) file_get_contents(get_template_directory() . '/front-page.php');
    $chat_qs = [
        'Is there a damage deposit?', 'Is there a security deposit?', 'Is there a cleaning fee?', 'What happens if something gets damaged?',
        'Is BYOB allowed?', 'Can we bring alcohol?', 'Is there a bar?', 'Do you sell or serve alcohol?', 'Do I need insurance?',
        'When is the balance due?', 'Can I bring a pet?', 'Can I bring my dog?', 'What are the house rules?', 'Is smoking allowed?',
        'What does the room include?', 'Is there a dumpster?', 'How do I pay the deposit?', 'Where do I mail a check?',
    ];
    $chat_text = '';
    foreach ($chat_qs as $q) $chat_text .= ' ' . brb_tf_reply($q) . ' ' . brb_tf_reply($q, true);
    $surfaces = [
        'terms page' => brb_tf_plain(brb_terms_html()),
        'rental agreement' => brb_tf_plain(brb_agreement_html()),
        'privacy page' => brb_tf_plain(brb_privacy_html()),
        'faq page' => brb_tf_plain(brb_faq_markup()),
        'book page facts strip' => brb_tf_plain(brb_how_booking_works_html()),
        'book policy' => brb_tf_plain(brb_book_policy_html()),
        'reserve explainer steps' => implode(' ', brb_reserve_explainer_steps()),
        'how booking works steps' => implode(' ', brb_how_booking_works_steps()),
        'first payment example' => brb_first_payment_sentence(),
        'public damage line' => brb_damage_public_line(),
        'default checklist' => brb_tf_plain(brb_checklist_html()) . ' ' . brb_checklist_plain(),
        'stored old checklist after mapping' => brb_tf_plain($stored_checklist),
        'cancellation summary' => brb_cancellation_summary(),
        'preview lines' => implode(' ', brb_preview_lines([], brb_now()->modify('+10 days'))),
        'llms.txt' => function_exists('brb_llms_txt_body') ? brb_llms_txt_body() : '',
        'theme SEO titles and descriptions' => $seo_text,
        'home page cards' => brb_tf_plain(preg_replace('/<\?php.*?\?>/s', ' ', $front)),
        'Walter answers' => $chat_text,
    ];
    $banned = ['$250', 'damage deposit', 'BYOB', 'renters insurance', 'one week before', 'a week before', '1 week before', 'week of the event', 'Service animals only'];
    foreach ($surfaces as $label => $text) {
        brb_t(trim($text) !== '', $label . ' renders');
        $hits = [];
        foreach ($banned as $word) if (stripos($text, $word) !== false) $hits[] = $word;
        brb_t($hits === [], $label . ' has none of $250, damage deposit, BYOB, renters insurance, one week before' . ($hits ? ' (found: ' . implode(', ', $hits) . ')' : ''));
        brb_t(stripos($text, 'airbnb') === false, $label . ' never mentions Airbnb');
    }
    // Walter may still explain a refund for a booking that already has a damage deposit line, without a flat amount.
    $refund = brb_tf_reply('How do I get my damage deposit back if I paid by check?');
    brb_t(strpos($refund, 'If your booking included a refundable damage deposit') !== false && strpos($refund, '$250') === false && stripos($refund, 'BYOB') === false, 'a refund question for an existing damage deposit gets the conditional refund line with no amount');

    // overnight wording stays only where the legal drafts have it
    foreach (['faq page', 'book policy', 'llms.txt', 'theme SEO titles and descriptions', 'home page cards', 'default checklist'] as $label) {
        brb_t(stripos($surfaces[$label], 'overnight') === false, $label . ' does not add overnight-stay wording');
    }
    brb_t(strpos($surfaces['terms page'], 'No overnight stays.') !== false && strpos($surfaces['rental agreement'], 'Overnight stays are not permitted.') !== false, 'the legal pages keep the drafts\' overnight sentences as written');

    // ---- the drafts' rules are present
    $food = 'Guests are welcome to bring their own food and drink.';
    $ins = 'Every event needs liability insurance of at least $1,000,000 per occurrence and $2,000,000 in the aggregate. If alcohol is served, $1,000,000 in liquor liability is also required. Proof is due 7 days before the event.';
    $ada = 'Pets are not permitted. Service animals as defined by the ADA are welcome and must remain leashed or under the handler\'s control at all times. Emotional support animals and pets are not service animals. Renter is responsible for any damage or cleanup caused by an animal.';
    $clean = 'A cleaning fee may be applied if the space is left dirty or damaged.';
    foreach (['terms page', 'faq page', 'default checklist', 'stored old checklist after mapping', 'Walter answers'] as $label) {
        $t = $surfaces[$label];
        brb_t(strpos($t, $food) !== false, $label . ' says guests are welcome to bring their own food and drink');
        brb_t(strpos($t, '$1,000,000 per occurrence and $2,000,000 in the aggregate') !== false && strpos($t, '$1,000,000 in liquor liability') !== false && strpos($t, 'Proof is due 7 days before the event.') !== false, $label . ' states the $1M / $2M + $1M liquor insurance rule with proof due 7 days before');
        brb_t(strpos($t, $ada) !== false, $label . ' states the ADA service-animal rule word for word');
        brb_t(strpos($t, $clean) !== false, $label . ' says a cleaning fee may be applied if left dirty or damaged');
    }
    brb_t(strpos($surfaces['rental agreement'], 'Service animals as defined by the ADA') !== false && strpos($surfaces['rental agreement'], 'Guests are welcome to bring their own food and drink.') !== false && strpos($surfaces['rental agreement'], 'One Million Dollars ($1,000,000) per occurrence') !== false, 'the rental agreement has the ADA rule, the food-and-drink line, and the insurance limits');
    brb_t(substr_count($surfaces['terms page'], 'No smoking or vaping inside. Smoking is outside only.') >= 2 && preg_match('/8\. Strictly Prohibited Items and Activities\..*Smoking and vaping are not permitted inside the Venue\..*9\. /s', $surfaces['rental agreement']) === 1, 'no smoking or vaping inside is in the Terms quick version and section 8, and in Agreement section 8');
    brb_t(strpos($surfaces['rental agreement'], 'snowstorm, severe storm, or power outage') !== false && strpos($surfaces['terms page'], 'snowstorm, severe storm, or power outage') !== false, 'the weather and emergency refund is in both documents');

    // ---- self-check: LLC name, price parts, 80 guests, balance 7 days, 48-hour notice, 11:30 PM
    foreach (['terms page', 'rental agreement', 'privacy page', 'faq page', 'cancellation summary'] as $label) {
        brb_t(strpos($surfaces[$label], 'Boardroom QC LLC') !== false && strpos($surfaces[$label], 'The Boardroom QC LLC') === false, $label . ' names Boardroom QC LLC');
    }
    foreach (['terms page', 'book policy'] as $label) {
        brb_t(strpos($surfaces[$label], '$850 Sunday through Thursday') !== false && strpos($surfaces[$label], '$1,400 Friday and Saturday') !== false && strpos($surfaces[$label], '3% processing fee') !== false, $label . ' shows all price parts: $850 Sunday-Thursday, $1,400 Friday-Saturday, and the 3% card fee');
    }
    brb_t(strpos($surfaces['terms page'], 'Up to 80 guests') !== false && strpos($surfaces['rental agreement'], '80 guests') !== false && strpos($surfaces['faq page'], '80 guests') !== false, '80 guests on the Terms, the Agreement, and the FAQ');
    brb_t(strpos($surfaces['terms page'], 'no later than 7 days before your event') !== false && strpos($surfaces['rental agreement'], 'no later than seven (7) days before the event') !== false && strpos($surfaces['faq page'], 'no later than 7 days before the event') !== false && strpos($surfaces['book page facts strip'], 'Balance Due 7 Days Before') !== false && strpos($surfaces['cancellation summary'], 'no later than 7 days before the event') !== false, 'the balance is due 7 days before the event everywhere it is stated');
    brb_t(strpos($surfaces['terms page'], 'Book at least 48 hours ahead.') !== false && strpos($surfaces['book page facts strip'], 'Book 48 Hours Ahead') !== false && brb_notice_hours() === 48, 'the 48-hour booking notice is kept');
    brb_t(strpos($surfaces['terms page'], 'Out by 11:30 PM') !== false && strpos($surfaces['rental agreement'], '11:30 PM') !== false, 'the 11:30 PM cutoff is kept');
    brb_t(strpos($surfaces['terms page'], 'Last updated: November 16, 2026') !== false && strpos($surfaces['rental agreement'], 'Last updated: November 16, 2026') !== false && stripos($surfaces['terms page'] . $surfaces['rental agreement'], 'review draft') === false && stripos($surfaces['terms page'] . $surfaces['rental agreement'], 'not live') === false, 'both legal pages say Last updated: November 16, 2026 with no draft marker');
    brb_t(strpos($surfaces['terms page'], 'Within 1 day of signing: 50% of your invoice.') !== false && strpos($surfaces['book page facts strip'], '50% Holds Your Date') !== false, 'the 50% booking deposit stays');

    // ---- addresses: venue 2220, office 2212 for checks and notices only
    brb_t(strpos($surfaces['terms page'], 'The venue is at 2220 37th Street, Moline, IL 61265.') !== false && strpos($surfaces['terms page'], 'Mail or drop off checks at the office: 2212 37th Street, Moline, IL 61265.') !== false, 'terms: venue 2220, checks to the office at 2212');
    brb_t(strpos($surfaces['faq page'], 'payable to Boardroom QC LLC at the office, 2212 37th Street, Moline, IL 61265') !== false, 'faq: checks payable to Boardroom QC LLC at the office, 2212 37th Street');
    brb_t(brb_venue_address() === '2220 37th Street, Moline, IL 61265' && strpos($surfaces['llms.txt'], '2212') === false && strpos($front, '2212') === false, 'the venue stays 2220, and llms.txt and the home page do not list 2212');
    $footer = (string) file_get_contents(get_template_directory() . '/footer.php');
    brb_t(strpos($footer, '2220 37th Street') !== false && strpos($footer, '2212') === false, 'the footer keeps the venue at 2220 and does not list 2212');
    ob_start();
    brb_place_schema();
    $schema = (string) ob_get_clean();
    brb_t(strpos($schema, '2220 37th Street') !== false && strpos($schema, '2212') === false, 'schema keeps the venue at 2220');

    // ---- upgrade routine never rewrites 2212 back to 2220
    $base = $checklist_saved;
    $base['pay_location'] = '2212 37th Street, Moline, IL 61265';
    foreach (['2.5.79', '2.5.80', '2.5.81'] as $from) {
        update_option('brb_settings', $base);
        update_option('brb_version', $from);
        brb_migrate_settings();
        brb_t((string) brb_opt('pay_location') === '2212 37th Street, Moline, IL 61265', 'upgrade from ' . $from . ' keeps a stored 2212 37th Street check address');
    }
    $base['pay_location'] = '2220 37th Street, Moline, IL 61265';
    update_option('brb_settings', $base);
    update_option('brb_version', '2.5.79');
    brb_migrate_settings();
    brb_t((string) brb_opt('pay_location') === '2212 37th Street, Moline, IL 61265', 'upgrading from 2.5.79 moves the old venue check address to the office at 2212 once');
    $base['pay_location'] = '2220 37th Street, Moline, IL 61265';
    update_option('brb_settings', $base);
    update_option('brb_version', '2.5.80');
    brb_migrate_settings();
    brb_t((string) brb_opt('pay_location') === '2220 37th Street, Moline, IL 61265', 'after 2.5.80, a check address staff set to 2220 is left alone');
    $base['pay_location'] = '2212 37th Street, Moline, IL 61265';
    $base['venue_address'] = '2212 37th Street, Moline, IL 61265';
    update_option('brb_settings', $base);
    update_option('brb_version', '2.5.79');
    brb_migrate_settings();
    brb_t((string) brb_opt('venue_address') === '2220 37th Street, Moline, IL 61265' && (string) brb_opt('pay_location') === '2212 37th Street, Moline, IL 61265', 'the venue address stays 2220 while checks stay at 2212');
    $base['venue_address'] = '2220 37th Street, Moline, IL 61265';
    $base['policy'] = brb_cancellation_summary();
    update_option('brb_settings', $base);
    brb_migrate_settings();
    brb_t(strpos((string) brb_opt('policy'), '2212 37th Street') !== false && strpos((string) brb_opt('policy'), 'at The Boardroom, 2220') === false, 'the stored summary keeps the 2212 office after an upgrade');
    $src = (string) file_get_contents(WP_PLUGIN_DIR . '/boardroom-booking/boardroom-booking.php');
    brb_t(strpos($src, "\$location === BRB_CHECK_ADDRESS) {\n        \$merged['pay_location'] = BRB_VENUE_ADDRESS;") === false, 'the old 2212-to-2220 overwrite is gone from brb_migrate_settings');

    // ---- damage deposit default is $0 (Max, Nov 16; code default set in 2.5.84)
    brb_t(brb_defaults()['damage_deposit_amount'] === '0' && function_exists('brb_ensure_damage_line'), 'damage deposit default is $0 and the damage line helper still exists');
} finally {
    if ($saved_opt === false) delete_option('brb_settings'); else update_option('brb_settings', $saved_opt);
    if ($saved_ver === false) delete_option('brb_version'); else update_option('brb_version', $saved_ver);
    echo 'RESULT ok=' . (int) $GLOBALS['brb_ok'] . ' fail=' . (int) $GLOBALS['brb_fail'] . "\n";
}
