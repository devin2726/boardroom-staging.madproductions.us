<?php
/**
 * Run inside WordPress: wp eval-file tests/chat-assistant.php
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "Load this with wp eval-file\n");
    exit(1);
}

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
$other = 'air' . 'bnb';

global $wpdb;
$snapshot = get_option('brb_settings');
$created_bookings = [];
$created_inquiries = [];
add_filter('pre_wp_mail', function ($short, $atts) {
    $GLOBALS['brb_mails'][] = $atts;
    return true;
}, 10, 2);
$GLOBALS['brb_mails'] = [];

function brb_chat_reply($text, $history = []) {
    $result = brb_chat_answer($text, $history);
    return $result['reply'];
}

try {
    brb_t(get_option('brb_version') === '2.5.5', 'plugin storage version is 2.5.5');
    $cols = $wpdb->get_col('SHOW COLUMNS FROM ' . brb_tour_table(), 0);
    brb_t(in_array('source', $cols, true), 'inquiries can store a source');
    brb_t((string) brb_opt('quote_prices') === '1', 'price estimates are on by default');
    brb_t((string) brb_opt('rate_sun_thu') === '850' && (string) brb_opt('rate_fri_sat') === '1400', 'weekday and weekend base rates are prefilled');
    brb_t(brb_estimate_amount('2026-12-24') === 1500.0, 'December 24 2026 estimates at 1500');
    brb_t(brb_estimate_amount('2026-12-04') === 1400.0, 'a Friday in December 2026 estimates at 1400');
    brb_t(brb_estimate_amount('2026-12-02') === 1200.0, 'a Wednesday in December 2026 estimates at 1200');
    brb_t(brb_estimate_amount('2026-12-06') === 850.0, 'a Sunday in December 2026 keeps the Sunday to Thursday base rate');
    brb_t(brb_estimate_amount('2026-11-25') === 1500.0, 'November 25 2026 estimates at 1500');
    brb_t(brb_estimate_amount('2027-01-08') === 1400.0, 'a Friday outside the holiday ranges uses the weekend rate');
    brb_t(brb_estimate_amount('2027-01-10') === 850.0, 'a Sunday outside the holiday ranges uses the Sunday to Thursday rate');

    $rules = brb_chat_reply('What are the house rules?');
    foreach (['No overnight stays', 'No cooking', 'stovetop', 'no taps', 'BYOB', 'City of Moline', '$50', 'not set up', 'garbage', '75', 'Christmas decorations are up in December', 'insurance'] as $needle) {
        brb_t(stripos($rules, $needle) !== false, 'rules answer includes ' . $needle);
    }
    brb_t(stripos($rules, 'owner') === false && stripos($rules, $other) === false && strpos($rules, '11:30 PM') !== false && stripos($rules, 'midnight') === false && strpos($rules, 'no tickets') !== false, 'rules answer stays on the venue and states the departure and admission rules');
    $food = brb_chat_reply('Can I bring my own food?');
    brb_t(stripos($food, 'own food') !== false && stripos($food, 'warmers') !== false && stripos($food, 'stovetop') !== false && stripos($food, 'any caterer') !== false && stripos($food, 'does not provide catering') !== false, 'own food is allowed with warmers and without catering from us');
    $byob = brb_chat_reply('Is BYOB allowed?');
    brb_t(strpos($byob, 'BYOB') !== false && strpos($byob, 'City of Moline') !== false && strpos($byob, 'leave it like you got it') !== false && strpos($byob, 'garbage') !== false, 'BYOB includes the license and cleanup');
    $out = brb_chat_reply('How late can we stay?');
    brb_t(strpos($out, '11:30 PM') !== false && stripos($out, 'midnight') === false && stripos($out, 'permit') === false, 'end time questions say 11:30 PM');
    $smoke = brb_chat_reply('Can we smoke or vape?');
    brb_t(strpos($smoke, 'No smoking or vaping.') !== false, 'smoking and vaping are refused');
    $pets = brb_chat_reply('Can I bring a pet?');
    brb_t(strpos($pets, 'Service animals only') !== false && strpos($pets, 'No pets') !== false, 'only service animals are allowed');
    $park = brb_chat_reply('Is there parking?');
    brb_t(strpos($park, 'Free parking on premises') !== false, 'parking stays free on premises');
    $tickets = brb_chat_reply('Can we sell tickets for a concert?');
    brb_t(strpos($tickets, 'no tickets') !== false && strpos($tickets, 'no cover charge') !== false && strpos($tickets, 'no paid admission') !== false && strpos($tickets, 'do not host concerts') !== false, 'ticketed events are refused');
    $band = brb_chat_reply('Can we have a band?');
    brb_t(strpos($band, 'band or live music') !== false && strpos($band, 'not charging') !== false, 'a band is allowed when the event is free');
    $fund = brb_chat_reply('Can our fundraiser sell tickets?');
    brb_t(strpos($fund, 'no tickets') !== false && strpos($fund, 'no door fees') !== false, 'a fundraiser cannot sell tickets');

    $amenities = brb_chat_reply('What amenities are included?');
    brb_t(strpos($amenities, 'Wifi') !== false && strpos($amenities, 'Kitchen area is for serving and storage. Cooking and stovetop use are not permitted.') !== false && strpos($amenities, "Here's what the room includes:") === 0 && strpos($amenities, 'Great question') === false, 'amenities answer uses the checklist and leads with the list');
    brb_t(strpos($amenities, 'Laundromat') === false && stripos($amenities, $other) === false && stripos($amenities, 'owner') === false, 'amenities answer does not add excluded items');

    $off = brb_chat_reply('Who is the ' . 'owner' . ' and what is their personal life?');
    brb_t(strpos($off, 'Contact form') !== false && stripos($off, 'owner') === false && stripos($off, $other) === false, 'questions about people are refused');
    $competitor = brb_chat_reply('How do you compare with ' . $other . '?');
    brb_t(strpos($competitor, 'Contact form') !== false && stripos($competitor, $other) === false, 'questions about other businesses are refused without repeating them');
    $politics = brb_chat_reply('What do you think about the election?');
    brb_t(strpos($politics, 'I\'m not sure about that one, but our team would be glad to help') !== false && strpos($politics, 'I can only help with The Boardroom') !== false, 'politics is out of scope');
    $legal = brb_chat_reply('Can you give legal advice about suing?');
    brb_t(strpos($legal, 'legal advice') !== false && strpos($legal, 'Contact form') !== false && stripos($legal, 'owner') === false, 'legal advice is refused');

    $settings = brb_opt();
    $settings['blackouts'] = trim((string) $settings['blackouts'] . "\n2030-06-15");
    update_option('brb_settings', $settings);
    $blackout = brb_chat_reply('Is June 15, 2030 available?');
    brb_t(strpos($blackout, 'unavailable') !== false && strpos($blackout, '48 hours') === false && strpos($blackout, '$') === false && strpos($blackout, 'nearby dates look open') !== false, 'a blackout date is unavailable, with nearby dates and no price or notice rule');

    $open = brb_chat_reply('Is September 10, 2030 available?');
    brb_t(strpos($open, 'looks open') !== false && strpos($open, 'reserved only once a deposit is paid') !== false && substr_count($open, 'not a binding commitment') === 1, 'an open date includes the estimate once and one binding sentence');

    $soon = brb_now()->modify('+1 day')->format('Y-m-d');
    $soon_reply = brb_chat_reply('Is ' . $soon . ' available?');
    brb_t(strpos($soon_reply, '48 hours') !== false && (strpos($soon_reply, 'notice') !== false || strpos($soon_reply, 'too soon') !== false), 'a date inside the notice window is not offered as a full day');

    $review_id = $wpdb->insert(brb_table(), [
        'token' => wp_generate_password(32, false),
        'mode' => 'day',
        'start_dt' => '2030-08-01 00:00:00',
        'end_dt' => '2030-08-02 00:00:00',
        'guests' => 10,
        'name' => 'Chat Review',
        'email' => 'chat-review@example.com',
        'status' => 'review',
        'total' => '0.00',
        'amount_paid' => '0.00',
        'created' => current_time('mysql', true),
    ]);
    brb_t((bool) $review_id, 'a pending review row can be inserted for the calendar test');
    if ($review_id) $created_bookings[] = (int) $wpdb->insert_id;
    $pending = brb_chat_reply('Is August 1, 2030 available?');
    brb_t(strpos($pending, 'looks open') !== false && strpos($pending, 'already reserved') === false, 'a pending review does not block the date');

    $paid_id = $wpdb->insert(brb_table(), [
        'token' => wp_generate_password(32, false),
        'mode' => 'day',
        'start_dt' => '2030-07-01 00:00:00',
        'end_dt' => '2030-07-02 00:00:00',
        'guests' => 12,
        'name' => 'Chat Reserved',
        'email' => 'chat-reserved@example.com',
        'status' => 'deposit_paid',
        'total' => '200.00',
        'deposit_due' => '100.00',
        'amount_paid' => '100.00',
        'price_set' => 1,
        'created' => current_time('mysql', true),
    ]);
    brb_t((bool) $paid_id, 'a paid deposit row can be inserted for the calendar test');
    if ($paid_id) $created_bookings[] = (int) $wpdb->insert_id;
    $reserved = brb_chat_reply('Is July 1, 2030 available?');
    brb_t(strpos($reserved, 'already reserved') !== false && strpos($reserved, 'once a deposit is paid') !== false, 'a paid deposit reserves the date');

    $price = brb_chat_reply('How much is December 24, 2026?');
    brb_t(strpos($price, '$1,500') !== false && strpos($price, '$750') !== false && strpos($price, 'an estimate for that date; final price is confirmed in writing by our team and depends on date, headcount and features needed') !== false, 'a priced date includes the estimate and the 50 percent deposit');
    brb_t(substr_count($price, 'not a binding commitment') === 1 && strpos($price, '2212') === false, 'a price answer has one binding sentence and no check address');
    $list = brb_chat_reply('What are your prices?');
    brb_t(strpos($list, 'Tell me a date') !== false && strpos($list, '$850') === false && strpos($list, '$1,400') === false && strpos($list, '$1,500') === false && strpos($list, '$1,200') === false, 'a general price question does not publish the rate table');

    $saved = brb_opt();
    $saved['quote_prices'] = '0';
    update_option('brb_settings', $saved);
    $hidden = brb_chat_reply('How much is December 24, 2026?');
    brb_t(strpos($hidden, 'will confirm pricing') !== false && strpos($hidden, '$1,500') === false && strpos($hidden, '1500') === false, 'turning estimates off hides the dollar amount');
    $saved['quote_prices'] = '1';
    update_option('brb_settings', $saved);

    $pay = brb_chat_reply('How do I pay the deposit?');
    brb_t(strpos($pay, '50% of the agreed total') !== false && strpos($pay, '90 or more days before the event') !== false && strpos($pay, 'less than 90 days before the event') !== false && strpos($pay, 'Want the full cancellation terms?') !== false && strpos($pay, '2212') === false && strpos($pay, 'not a binding commitment') === false && stripos($pay, 'Stripe') === false, 'the deposit answer is short and skips the check address');
    $checks = brb_chat_reply('Where do I mail a check?');
    brb_t(strpos($checks, '2212 37th Street, Moline, IL 61265') !== false && strpos($checks, 'Mad Productions') !== false && strpos($checks, 'check address') !== false, 'a check question names the check address');

    putenv('BRB_STRIPE_SECRET_KEY=sk_test_ZZZexamplekey');
    $_ENV['BRB_STRIPE_SECRET_KEY'] = 'sk_test_ZZZexamplekey';
    $_SERVER['BRB_STRIPE_SECRET_KEY'] = 'sk_test_ZZZexamplekey';
    $card = brb_chat_reply('How do I pay the deposit?');
    brb_t(strpos($card, 'Stripe') !== false && strpos($card, '3%') !== false && strpos($card, 'sk_test_ZZZexamplekey') === false, 'card payment is mentioned only when Stripe is enabled, without the key');
    putenv('BRB_STRIPE_SECRET_KEY');
    unset($_ENV['BRB_STRIPE_SECRET_KEY'], $_SERVER['BRB_STRIPE_SECRET_KEY']);

    $book = brb_chat_reply('I want to book a date');
    brb_t(strpos($book, home_url('/book/')) !== false && strpos($book, 'subject to approval') !== false, 'booking help points at the book page');
    $booked = brb_chat_answer('I want to book a date');
    brb_t(!empty($booked['lead']), 'a booking question asks for follow-up details');
    $tour = brb_chat_reply('Can I tour the space?');
    brb_t(strpos($tour, home_url('/contact/')) !== false && strpos($tour, 'boardroomqc@gmail.com') !== false && strpos($tour, 'does not hold') !== false, 'a tour question points at the contact form');
    $contact = brb_chat_reply('How do I contact you?');
    brb_t(strpos($contact, 'boardroomqc@gmail.com') !== false && strpos($contact, 'no phone number') !== false && strpos($contact, '2220 37th Street, Moline, IL 61265') !== false && strpos($contact, '2212 37th Street') === false && strpos($contact, '309') === false, 'contact help gives the email, the venue, and no phone number');
    $cancel = brb_chat_reply('What is the cancellation policy?');
    brb_t(strpos($cancel, 'non-refundable') !== false && strpos($cancel, 'not legal advice') !== false && strpos($cancel, home_url('/terms/')) !== false && stripos($cancel, 'owner') === false, 'cancellation follows the terms without giving legal advice');

    $once = brb_chat_answer('What amenities are included?');
    brb_t(empty($once['lead']), 'one amenities question does not require contact details');
    $later = brb_chat_answer('What amenities are included?', [
        ['role' => 'user', 'text' => 'Hello'],
        ['role' => 'user', 'text' => 'Parking?'],
        ['role' => 'user', 'text' => 'Kitchen?'],
    ]);
    brb_t(!empty($later['lead']), 'a longer conversation offers follow-up');

    $hello = brb_chat_answer('Hi');
    brb_t(strpos($hello['reply'], 'Hi there! I\'m the Boardroom\'s assistant.') !== false && strpos($hello['reply'], 'whether a date is open') !== false && strpos($hello['reply'], 'what\'s in the room') !== false && strpos($hello['reply'], 'how the deposit works') !== false, 'a hello suggests three things to ask');
    $hey = brb_chat_answer('Hey');
    brb_t(strpos($hey['reply'], 'Hey!') === 0 && strpos($hey['reply'], 'whether a date is open') !== false, 'hey gets its own greeting');
    $next_month = brb_now()->setTime(0, 0)->modify('first day of next month');
    $fifteenth = sprintf('%04d-%02d-15', (int) $next_month->format('Y'), (int) $next_month->format('n'));
    $rel = brb_chat_reply('Is the 15th of next month available?');
    brb_t(strpos($rel, brb_chat_date_label($fifteenth)) !== false && strpos($rel, 'looks open') !== false, 'the 15th of next month is parsed');
    $saturday = brb_now()->setTime(0, 0);
    $delta = (6 - (int) $saturday->format('w') + 7) % 7;
    if ($delta === 0) $delta = 7;
    $saturday = $saturday->modify('+' . $delta . ' days')->format('Y-m-d');
    $sat_reply = brb_chat_reply('Is next Saturday free?');
    brb_t(strpos($sat_reply, brb_chat_date_label($saturday)) !== false, 'next Saturday is parsed');
    $dec12 = brb_chat_infer_year(12, 12);
    $dec_reply = brb_chat_reply('Is Dec 12 open?');
    $slash_reply = brb_chat_reply('What about 12/12?');
    brb_t($dec12 !== '' && strpos($dec_reply, brb_chat_date_label($dec12)) !== false && strpos($slash_reply, brb_chat_date_label($dec12)) !== false, 'Dec 12 and 12/12 are the same date');
    brb_t(strpos($dec_reply, 'an estimate for that date') !== false, 'a parsed date includes the estimate when quoting is on');
    $rules_reply = brb_chat_answer('What are the house rules?');
    brb_t(strpos($rules_reply['reply'], '✨') === false && strpos($rules_reply['reply'], '90 or more days') === false, 'rules replies stay plain');
    $refund_reply = brb_chat_answer('What is the cancellation policy?');
    brb_t(strpos($refund_reply['reply'], '90 or more days before the event') !== false && strpos($refund_reply['reply'], 'less than 90 days before the event') !== false && strpos($refund_reply['reply'], '✨') === false, 'the assistant explains the 90-day deposit rule without an emoji');
    $long = brb_chat_answer(str_repeat('a', 501));
    brb_t($long['intent'] === 'unknown' && strpos($long['reply'], 'shorten') !== false, 'a very long message is refused');

    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    $referer = $_SERVER['HTTP_REFERER'] ?? null;
    unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);
    $denied = new WP_REST_Request('POST', '/brb/v1/chat');
    $denied_result = brb_chat_permission($denied);
    brb_t(is_wp_error($denied_result) && $denied_result->get_error_data()['status'] === 403, 'chat rejects a request with no nonce or origin');
    $_SERVER['HTTP_ORIGIN'] = home_url();
    brb_t(brb_chat_access_ok(new WP_REST_Request('POST', '/brb/v1/chat')) === true, 'chat accepts the site origin');
    unset($_SERVER['HTTP_ORIGIN']);
    $nonce_req = new WP_REST_Request('POST', '/brb/v1/chat');
    $nonce_req->set_header('X-BRB-Chat', wp_create_nonce('brb_chat'));
    brb_t(brb_chat_access_ok($nonce_req) === true, 'chat accepts the nonce');
    delete_transient(brb_chat_rate_key('chat'));
    $allowed = true;
    for ($i = 0; $i < 30; $i++) {
        if (!brb_chat_rate_allow('chat')) $allowed = false;
    }
    $_SERVER['HTTP_ORIGIN'] = home_url();
    $limited = brb_chat_permission(new WP_REST_Request('POST', '/brb/v1/chat'));
    brb_t($allowed && is_wp_error($limited) && $limited->get_error_data()['status'] === 429, 'chat answers are rate limited');
    if ($origin === null) unset($_SERVER['HTTP_ORIGIN']); else $_SERVER['HTTP_ORIGIN'] = $origin;
    if ($referer === null) unset($_SERVER['HTTP_REFERER']); else $_SERVER['HTTP_REFERER'] = $referer;

    $GLOBALS['brb_mails'] = [];
    $lead = brb_chat_save_lead([
        'name' => 'Chat Guest',
        'email' => 'chat-guest@example.com',
        'phone' => '309-555-0144',
        'event_date' => '2026-12-24',
        'event_type' => 'birthday',
        'note' => 'Looking at a birthday.',
        'consent' => '1',
        'transcript' => 'Guest asked about December 24.',
        'company' => '',
    ]);
    brb_t(is_array($lead) && !empty($lead['stored']) && $lead['message'] === 'Thanks so much, someone from our team will get right back to you!', 'a chat lead is saved and thanks the guest');
    $row = !empty($lead['id']) ? brb_tour((int) $lead['id']) : null;
    if ($row) $created_inquiries[] = (int) $row->id;
    brb_t($row && $row->source === 'chat assistant' && $row->email === 'chat-guest@example.com' && $row->phone === '309-555-0144' && strpos($row->notes, 'Guest asked about December 24.') !== false && strpos($row->notes, 'Birthday') !== false, 'the inquiry stores the source, the note, and the transcript summary');
    $mail = $GLOBALS['brb_mails'][0] ?? null;
    brb_t(is_array($mail) && $mail['subject'] === 'New contact inquiry' && strpos($mail['message'], 'Source: chat assistant') !== false && strpos($mail['message'], 'No date is held.') !== false, 'staff are emailed about the chat inquiry');

    $before = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_tour_table() . ' WHERE email=%s', 'chat-spam@example.com'));
    $spam = brb_chat_save_lead([
        'name' => 'Spam',
        'email' => 'chat-spam@example.com',
        'note' => 'Buy now',
        'consent' => '1',
        'company' => 'Spam Co',
    ]);
    $after = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . brb_tour_table() . ' WHERE email=%s', 'chat-spam@example.com'));
    brb_t(is_array($spam) && empty($spam['stored']) && $after === $before, 'a honeypot submission is not stored');
    $missing = brb_chat_save_lead([
        'name' => 'No Consent',
        'email' => 'chat-noconsent@example.com',
        'note' => 'Hello',
        'consent' => '',
    ]);
    brb_t(is_wp_error($missing) && $missing->get_error_code() === 'consent', 'a lead requires the consent line');
    $ordered = brb_chat_save_lead([
        'name' => 'Ordered Guest',
        'email' => 'chat-order@example.com',
        'note' => 'Please call me back.',
        'consent' => '1',
        'turns' => [
            ['role' => 'user', 'text' => 'First question about Saturday'],
            ['role' => 'assistant', 'text' => 'First answer about Saturday'],
            ['role' => 'user', 'text' => 'Second question about the deposit'],
        ],
    ]);
    $ordered_row = (is_array($ordered) && !empty($ordered['id'])) ? brb_tour((int) $ordered['id']) : null;
    if ($ordered_row) $created_inquiries[] = (int) $ordered_row->id;
    $notes = $ordered_row ? (string) $ordered_row->notes : '';
    $first_q = strpos($notes, 'First question about Saturday');
    $first_a = strpos($notes, 'First answer about Saturday');
    $second_q = strpos($notes, 'Second question about the deposit');
    brb_t($first_q !== false && $first_a !== false && $second_q !== false && $first_q < $first_a && $first_a < $second_q, 'the chat transcript stays in chronological order');

    $user = get_user_by('login', 'admin');
    if ($user) wp_set_current_user($user->ID);
    unset($_POST['brb_save']);
    ob_start();
    brb_admin_settings();
    $settings_html = ob_get_clean();
    brb_t(strpos($settings_html, 'Let the assistant quote estimated prices') !== false && strpos($settings_html, 'value="850"') !== false && strpos($settings_html, 'value="1400"') !== false && strpos($settings_html, '2026-12-24') !== false, 'settings show the estimate table and the checkbox');
    if ($row) {
        $_GET = ['page' => 'brb-tours', 'id' => (int) $row->id];
        ob_start();
        brb_admin_tours();
        $detail = ob_get_clean();
        $_GET = [];
        brb_t(strpos($detail, 'chat assistant') !== false && strpos($detail, 'Guest asked about December 24.') !== false, 'the inquiry detail shows the chat source and summary');
    } else {
        brb_t(false, 'the inquiry detail shows the chat source and summary');
    }

    $widget = brb_chat_markup();
    brb_t(strpos($widget, 'brb-chat-launch') !== false && strpos($widget, 'Ask our Chat Bot') !== false && strpos($widget, '>Chat<') === false && strpos($widget, 'role="dialog"') !== false && strpos($widget, 'aria-expanded') !== false && strpos($widget, 'A team member will reply using this info') !== false && strpos($widget, 'Hi there! I\'m the Boardroom\'s assistant. I\'d love to help you plan your event.') !== false, 'the chat button and panel are on the page');
    brb_t(strpos($widget, '850') === false && strpos($widget, '1400') === false && strpos($widget, '1500') === false && stripos($widget, $other) === false && stripos($widget, 'owner') === false, 'the chat panel does not publish rates or private topics');
    $plugin_dir = dirname((new ReflectionFunction('brb_chat_markup'))->getFileName());
    $css = file_get_contents($plugin_dir . '/assets/chat.css');
    $js = file_get_contents($plugin_dir . '/assets/chat.js');
    brb_t(is_string($css) && strpos($css, '.brb-chat-msg,.brb-chat-msg.user,.brb-chat-msg.bot{') !== false && strpos($css, 'color:#161616') !== false, 'guest and bot messages share one text color');
    brb_t(is_string($css) && strpos($css, 'EB Garamond') !== false && strpos($css, '#c4912e') !== false && strpos($css, 'position:fixed') !== false && strpos($css, 'brb-chat-scroll') !== false && strpos($css, 'min-height:0') !== false && strpos($css, '100dvh') !== false, 'the chat panel scrolls inside the viewport');
    brb_t(is_string($js) && strpos($js, 'credentials: \'omit\'') !== false && stripos($js, 'openai') === false && stripos($js, 'googleapis') === false && stripos($js, $other) === false, 'the chat script stays on this site and does not call an outside model');
    $public_price = brb_price_sentence();
    brb_t(strpos($public_price, '$850') === false && strpos($public_price, '$1,400') === false && strpos($public_price, '$1,500') === false, 'the public price sentence does not list the assistant rates');

    $privacy = get_page_by_path('privacy');
    $privacy_html = $privacy ? (string) $privacy->post_content : '';
    brb_t(strpos($privacy_html, 'Messages submitted through the chat assistant are stored with the inquiry.') !== false && stripos($privacy_html, 'phone') === false, 'privacy says chat messages are stored with the inquiry');
    brb_t(strpos($privacy_html, 'The chat assistant does not set a cookie.') !== false, 'privacy says the assistant does not set a cookie');

    $routes = rest_get_server()->get_routes();
    brb_t(isset($routes['/brb/v1/chat']) && isset($routes['/brb/v1/chat-lead']), 'the chat routes are registered');
} finally {
    if ($created_bookings) {
        $in = implode(',', array_map('intval', $created_bookings));
        $wpdb->query("DELETE FROM " . brb_pay_table() . " WHERE booking_id IN ($in)");
        $wpdb->query("DELETE FROM " . brb_table() . " WHERE id IN ($in)");
    }
    $wpdb->query("DELETE FROM " . brb_table() . " WHERE email IN ('chat-review@example.com','chat-reserved@example.com')");
    if ($created_inquiries) {
        $in = implode(',', array_map('intval', $created_inquiries));
        $wpdb->query("DELETE FROM " . brb_tour_table() . " WHERE id IN ($in)");
    }
    $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_tour_table() . ' WHERE email=%s', 'chat-guest@example.com'));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_tour_table() . ' WHERE email=%s', 'chat-spam@example.com'));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . brb_tour_table() . ' WHERE email=%s', 'chat-noconsent@example.com'));
    if ($snapshot !== false && $snapshot !== null) update_option('brb_settings', $snapshot);
    delete_transient(brb_chat_rate_key('chat'));
    putenv('BRB_STRIPE_SECRET_KEY');
    unset($_ENV['BRB_STRIPE_SECRET_KEY'], $_SERVER['BRB_STRIPE_SECRET_KEY']);
}

echo "\n{$GLOBALS['brb_ok']} passed, {$GLOBALS['brb_fail']} failed\n";
exit($GLOBALS['brb_fail'] ? 1 : 0);
