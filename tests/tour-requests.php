<?php
/**
 * Run inside WordPress: wp eval-file tests/tour-requests.php
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
$brand = 'str' . 'ipe';
$wire = 'ze' . 'lle';
$other = 'air' . 'bnb';
$banned = [$brand, $wire, $other];

global $wpdb;
add_filter('pre_wp_mail', function ($short, $atts) {
    $GLOBALS['brb_mails'][] = $atts;
    return true;
}, 10, 2);
$GLOBALS['brb_mails'] = [];
$created = [];
$snapshot = get_option('brb_settings');

try {
    brb_t(get_option('brb_version') === '2.5.45', 'plugin storage version is 2.5.45');
    brb_t($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', brb_tour_table())) === brb_tour_table(), 'inquiry table still exists');
    $cols = $wpdb->get_col('SHOW COLUMNS FROM ' . brb_tour_table(), 0);
    brb_t(in_array('phone', $cols, true) && in_array('topic', $cols, true) && in_array('preferred', $cols, true), 'topic and preferred were added and phone was kept');
    brb_t((string) brb_opt('tour_email') === 'boardroomqc@gmail.com', 'inquiry email defaults to boardroomqc@gmail.com');

    $user = get_user_by('login', 'admin');
    if ($user) wp_set_current_user($user->ID);

    $stored = brb_opt();
    $stored['tour_email'] = 'not-an-email';
    update_option('brb_settings', $stored);
    brb_migrate_settings();
    brb_t((string) brb_opt('tour_email') === 'boardroomqc@gmail.com', 'an invalid inquiry email is replaced on upgrade');
    $stored = brb_opt();
    $stored['tour_email'] = 'tours@example.com';
    $stored['admin_email'] = 'staff@example.com, desk@example.com';
    update_option('brb_settings', $stored);
    brb_migrate_settings();
    brb_t((string) brb_opt('tour_email') === 'tours@example.com', 'a real inquiry email is kept');
    $recipients = brb_tour_recipients();
    sort($recipients);
    brb_t($recipients === ['desk@example.com', 'staff@example.com', 'tours@example.com'], 'inquiry mail goes to the admin list and the inquiry address');
    $stored['admin_email'] = 'boardroomqc@gmail.com';
    $stored['tour_email'] = 'boardroomqc@gmail.com';
    update_option('brb_settings', $stored);
    brb_t(brb_tour_recipients() === ['boardroomqc@gmail.com'], 'the same address is emailed once');

    $wpdb->insert(brb_tour_table(), [
        'name' => 'Legacy Guest',
        'email' => 'legacy-tour@example.com',
        'phone' => '309-555-0199',
        'preferred_date' => '2026-12-01',
        'time_window' => 'morning',
        'party_size' => 8,
        'notes' => 'Old tour note',
        'staff_notes' => '',
        'status' => 'new',
        'created' => current_time('mysql', true),
    ]);
    $legacy_id = (int) $wpdb->insert_id;
    $created[] = $legacy_id;
    $legacy = brb_tour($legacy_id);
    brb_t($legacy && $legacy->phone === '309-555-0199' && $legacy->preferred_date === '2026-12-01' && (int) $legacy->party_size === 8, 'an existing tour request row is still stored');

    delete_transient(brb_tour_rate_key());
    $missing = brb_tour_submit(['name' => '', 'email' => 'a@example.com', 'message' => 'Hello']);
    brb_t(is_wp_error($missing) && $missing->get_error_code() === 'missing', 'an inquiry needs a name');
    $phone_only = brb_tour_submit(['name' => 'Ada', 'email' => '', 'phone' => '309-555-0100', 'message' => 'Hello']);
    brb_t(is_wp_error($phone_only) && $phone_only->get_error_code() === 'missing', 'a phone number alone cannot send an inquiry');
    $bad_email = brb_tour_submit(['name' => 'Ada', 'email' => 'not-email', 'message' => 'Hello']);
    brb_t(is_wp_error($bad_email) && $bad_email->get_error_code() === 'email', 'a bad email is rejected');
    $no_message = brb_tour_submit(['name' => 'Ada', 'email' => 'ada@example.com', 'message' => '']);
    brb_t(is_wp_error($no_message) && $no_message->get_error_code() === 'message', 'an inquiry needs a message');
    $timed = brb_tour_submit(['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello', 'preferred' => 'Saturday afternoon']);
    brb_t(is_wp_error($timed) && $timed->get_error_code() === 'preferred', 'a preferred time is rejected');
    $unknown = brb_tour_submit(['name' => 'Ada', 'email' => 'topic-inquiry@example.com', 'message' => 'Hello', 'topic' => 'midnight']);
    brb_t(!is_wp_error($unknown) && $unknown && $unknown->topic === 'general' && $unknown->preferred === '', 'a missing or unknown topic is stored as a general inquiry');
    if (!is_wp_error($unknown) && $unknown) $created[] = (int) $unknown->id;
    $spam = brb_tour_submit(['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello', 'company' => 'http://spam.test']);
    brb_t(is_wp_error($spam) && $spam->get_error_code() === 'spam', 'a filled honeypot is not stored');
    brb_t((int) $wpdb->get_var("SELECT COUNT(*) FROM " . brb_tour_table() . " WHERE email='ada@example.com'") === 0, 'rejected inquiries are not stored');

    $GLOBALS['brb_mails'] = [];
    delete_transient(brb_tour_rate_key());
    $row = brb_tour_submit([
        'name' => 'Inquiry Guest',
        'email' => 'inquiry-guest@example.com',
        'phone' => '309-555-0100',
        'topic' => 'tour',
        'preferred' => '2026-12-12',
        'message' => 'Can we see the room?',
    ]);
    brb_t(!is_wp_error($row) && $row && $row->status === 'new', 'a name and email store a new inquiry');
    if (!is_wp_error($row) && $row) {
        $created[] = (int) $row->id;
        brb_t($row->phone === '' && $row->preferred_date === null && $row->time_window === '' && $row->party_size === null, 'a new inquiry leaves the old tour columns empty');
        brb_t($row->topic === 'general' && $row->preferred === '2026-12-12' && $row->notes === 'Can we see the room?' && $row->staff_notes === '', 'a contact inquiry is a general inquiry and stores the preferred date');
        brb_t(brb_tour($legacy_id) && brb_tour($legacy_id)->notes === 'Old tour note', 'saving an inquiry does not delete an older row');
        $staff_mails = [];
        $guest_mails = [];
        foreach ($GLOBALS['brb_mails'] as $mail) {
            if (($mail['subject'] ?? '') === 'New contact inquiry') $staff_mails[] = $mail;
            if (($mail['subject'] ?? '') === 'We got your message, The Boardroom') $guest_mails[] = $mail;
        }
        $tos = [];
        foreach ($staff_mails as $mail) $tos[] = $mail['to'];
        sort($tos);
        brb_t($tos === ['boardroomqc@gmail.com'], 'the notice is emailed to the inquiry address');
        brb_t(count($staff_mails) === 1 && ($staff_mails[0]['subject'] ?? '') === 'New contact inquiry', 'the subject is New contact inquiry');
        $headers = $staff_mails[0]['headers'] ?? [];
        $header_text = is_array($headers) ? implode("\n", $headers) : (string) $headers;
        brb_t(strpos($header_text, 'From: The Boardroom <boardroomqc@gmail.com>') !== false, 'inquiry mail is from boardroomqc@gmail.com');
        brb_t(strpos($header_text, 'Reply-To: inquiry-guest@example.com') !== false, 'inquiry mail replies to the guest');
        $body = (string) ($staff_mails[0]['message'] ?? '');
        brb_t(strpos($body, 'A contact inquiry was received.') !== false && strpos($body, 'No date is held.') !== false && strpos($body, 'Inquiry Guest') !== false && strpos($body, 'Can we see the room?') !== false && strpos($body, 'General inquiry') !== false && strpos($body, 'Preferred dates: December 12, 2026') !== false && stripos($body, 'Saturday afternoon') === false && stripos($body, 'or time') === false, 'the notice describes the inquiry, the preferred date, and does not hold a date');
        brb_t(strpos($body, 'Phone:') === false && stripos($body, 'tour was requested') === false && strpos($body, '309-555-0100') === false, 'the inquiry email omits a phone number');
        foreach ($banned as $w) brb_t(stripos($body, $w) === false, 'inquiry email has no ' . $w);
        brb_t(count($guest_mails) === 1 && ($guest_mails[0]['to'] ?? '') === 'inquiry-guest@example.com', 'the guest confirmation goes only to the submitted email');
        $guest_headers = $guest_mails[0]['headers'] ?? [];
        $guest_header_text = is_array($guest_headers) ? implode("\n", $guest_headers) : (string) $guest_headers;
        brb_t(strpos($guest_header_text, 'From: The Boardroom <boardroomqc@gmail.com>') !== false && strpos($guest_header_text, 'Reply-To: boardroomqc@gmail.com') !== false && stripos($guest_header_text, 'Cc:') === false && stripos($guest_header_text, 'Bcc:') === false, 'the guest confirmation uses the guest From and Reply-To and has no copy list');
        $guest_body = (string) ($guest_mails[0]['message'] ?? '');
        brb_t(strpos($guest_body, "Hi Inquiry,\n") === 0 && strpos($guest_body, 'Thanks for reaching out to The Boardroom!') !== false && strpos($guest_body, "Preferred dates: December 12, 2026") !== false && strpos($guest_body, "Message: Can we see the room?") !== false && strpos($guest_body, 'Event type:') === false && strpos($guest_body, 'Guests:') === false && strpos($guest_body, 'Rental Checklist') === false && strpos($guest_body, '/rental-checklist/') === false && strpos($guest_body, 'Want to check open dates or book?') !== false && strpos($guest_body, brb_book_url()) !== false && strpos($guest_body, '2220 37th Street, Moline, IL 61265') !== false, 'the guest confirmation repeats only the details that were sent');
        brb_t(strpos($guest_body, '2212') === false && strpos($guest_body, '309-555-0100') === false && stripos($guest_body, 'airbnb') === false && strpos((string) ($guest_mails[0]['subject'] ?? ''), "\n") === false && strpos((string) ($guest_mails[0]['subject'] ?? ''), "\r") === false, 'the guest confirmation has no private address, phone, or injected subject');
        $acked = brb_tour((int) $row->id);
        brb_t($acked && trim((string) $acked->guest_ack) !== '', 'the guest confirmation is stamped on the inquiry');
        $before_retry = count($GLOBALS['brb_mails']);
        brb_t(brb_send_inquiry_guest_ack($row) === false && count($GLOBALS['brb_mails']) === $before_retry, 'the same inquiry does not send the guest confirmation again');
    }

    $injected = (object) [
        'name' => "Sam\r\nBcc: injected@example.com",
        'event_type' => 'birthday',
        'preferred' => "2026-11-20\r\nBcc: evil@example.com",
        'party_size' => 12,
        'notes' => "Hello\r\nthere",
    ];
    $injected_body = brb_inquiry_guest_ack_body($injected);
    brb_t(strpos($injected_body, "Hi Sam,\n") === 0 && strpos($injected_body, "\r") === false && strpos($injected_body, "\nBcc:") === false && strpos($injected_body, 'Event type: Birthday') !== false && strpos($injected_body, 'Guests: 12') !== false && strpos($injected_body, "Message: Hello\nthere") !== false && strpos($injected_body, 'Preferred dates: 2026-11-20 Bcc: evil@example.com') !== false, 'guest-supplied lines are flattened so they cannot add a header');
    $empty_named = (object) ['name' => '', 'event_type' => '', 'preferred' => '', 'party_size' => null, 'notes' => ''];
    $empty_body = brb_inquiry_guest_ack_body($empty_named);
    brb_t(strpos($empty_body, "Hi there,\n") === 0 && strpos($empty_body, "Here's what you sent us:") === false, 'a missing name says there and empty details are left out');

    $settings = get_option('brb_settings', []);
    if (!is_array($settings)) $settings = [];
    $settings['inquiry_cc'] = 'copy-inquiry@example.com';
    update_option('brb_settings', $settings);
    $GLOBALS['brb_mails'] = [];
    delete_transient(brb_tour_rate_key());
    $copied = brb_tour_submit([
        'name' => 'Copied Guest',
        'email' => 'copied-inquiry@example.com',
        'message' => 'Please copy staff only.',
        'event_type' => 'corporate',
        'party_size' => '18',
        'preferred' => '2026-11-03',
    ]);
    brb_t(!is_wp_error($copied) && $copied, 'an inquiry still saves when a staff copy list is set');
    if (!is_wp_error($copied) && $copied) {
        $created[] = (int) $copied->id;
        $staff_cc = '';
        $guest_only = null;
        foreach ($GLOBALS['brb_mails'] as $mail) {
            $text = is_array($mail['headers'] ?? null) ? implode("\n", $mail['headers']) : (string) ($mail['headers'] ?? '');
            if (($mail['subject'] ?? '') === 'New contact inquiry') $staff_cc = $text;
            if (($mail['subject'] ?? '') === 'We got your message, The Boardroom') $guest_only = $mail;
        }
        $guest_text = is_array($guest_only['headers'] ?? null) ? implode("\n", $guest_only['headers']) : (string) ($guest_only['headers'] ?? '');
        $guest_message = (string) ($guest_only['message'] ?? '');
        brb_t(strpos($staff_cc, 'Cc: copy-inquiry@example.com') !== false, 'staff still receive the inquiry copy list');
        brb_t($guest_only && ($guest_only['to'] ?? '') === 'copied-inquiry@example.com' && stripos($guest_text, 'Cc:') === false && stripos($guest_text, 'Bcc:') === false && stripos($guest_text, 'copy-inquiry@example.com') === false && strpos($guest_message, 'Event type:') === false && strpos($guest_message, 'Guests: 18') !== false && strpos($guest_message, 'Preferred dates: November 3, 2026') !== false, 'the guest confirmation is not copied to staff and includes the sent details');
    }
    if ($snapshot !== false && $snapshot !== null) update_option('brb_settings', $snapshot);

    global $wpdb;
    $wpdb->insert(brb_tour_table(), [
        'name' => 'Bad Email',
        'email' => 'not-an-email',
        'phone' => '',
        'notes' => 'No confirmation',
        'staff_notes' => '',
        'status' => 'new',
        'created' => current_time('mysql', true),
        'topic' => 'general',
        'preferred' => '',
    ]);
    $bad_id = (int) $wpdb->insert_id;
    $created[] = $bad_id;
    $GLOBALS['brb_mails'] = [];
        brb_t(brb_send_inquiry_guest_ack($bad_id) === false && count($GLOBALS['brb_mails']) === 0 && trim((string) (brb_tour($bad_id)->guest_ack ?? '')) === '', 'a bad email does not get a confirmation');

    $fail_filter = function ($short, $atts) {
        if (($atts['subject'] ?? '') === 'We got your message, The Boardroom') {
            throw new RuntimeException('guest mail failed');
        }
        return $short;
    };
    add_filter('pre_wp_mail', $fail_filter, 5, 2);
    $GLOBALS['brb_mails'] = [];
    delete_transient(brb_tour_rate_key());
    $failed_send = brb_tour_submit([
        'name' => 'Retry Guest',
        'email' => 'retry-inquiry@example.com',
        'message' => 'Staff mail should still go out.',
    ]);
    remove_filter('pre_wp_mail', $fail_filter, 5);
    brb_t(!is_wp_error($failed_send) && $failed_send && $failed_send->email === 'retry-inquiry@example.com', 'a failed guest confirmation still saves the inquiry');
    if (!is_wp_error($failed_send) && $failed_send) {
        $created[] = (int) $failed_send->id;
        $staff_sent = false;
        foreach ($GLOBALS['brb_mails'] as $mail) {
            if (($mail['subject'] ?? '') === 'New contact inquiry' && ($mail['to'] ?? '') === 'boardroomqc@gmail.com') $staff_sent = true;
        }
        brb_t($staff_sent && trim((string) (brb_tour((int) $failed_send->id)->guest_ack ?? '')) === '', 'a failed guest confirmation leaves the staff email and does not stamp the row');
        $GLOBALS['brb_mails'] = [];
        brb_t(brb_send_inquiry_guest_ack((int) $failed_send->id) === true, 'a later try sends the guest confirmation once');
        brb_t(count($GLOBALS['brb_mails']) === 1 && ($GLOBALS['brb_mails'][0]['to'] ?? '') === 'retry-inquiry@example.com' && ($GLOBALS['brb_mails'][0]['subject'] ?? '') === 'We got your message, The Boardroom', 'the retry goes to the guest and not to staff');
    }

    delete_transient(brb_tour_rate_key());
    $blank = brb_tour_submit(['name' => 'Optional Guest', 'email' => 'optional-inquiry@example.com', 'message' => 'Just a question', 'topic' => '', 'preferred' => '']);
    brb_t(!is_wp_error($blank) && $blank->topic === 'general' && $blank->preferred === '' && $blank->preferred_date === null, 'a blank preferred date is still saved as a general inquiry');
    if (!is_wp_error($blank) && $blank) $created[] = (int) $blank->id;

    $soon = brb_tour_submit(['name' => 'Again', 'email' => 'again-inquiry@example.com', 'message' => 'Again']);
    brb_t(is_wp_error($soon) && $soon->get_error_code() === 'soon', 'a second inquiry right away is rate limited');
    brb_t((int) $wpdb->get_var("SELECT COUNT(*) FROM " . brb_tour_table() . " WHERE email='again-inquiry@example.com'") === 0, 'a rate-limited inquiry is not stored');
    set_transient(brb_tour_rate_key(), ['n' => 5, 'last' => time() - 30], HOUR_IN_SECONDS);
    $hour = brb_tour_submit(['name' => 'Later', 'email' => 'later-inquiry@example.com', 'message' => 'Later']);
    brb_t(is_wp_error($hour) && $hour->get_error_code() === 'hour', 'five inquiries in an hour stop the next one');

    $updated = brb_tour_update((int) $row->id, 'contacted', 'Replied by email.');
    brb_t(!is_wp_error($updated) && $updated->status === 'contacted' && $updated->staff_notes === 'Replied by email.', 'staff can save a status and notes');
    brb_t(brb_tour((int) $row->id), 'saving a status does not delete the inquiry');
    $done = brb_tour_update((int) $row->id, 'done', 'Replied by email.');
    brb_t(!is_wp_error($done) && $done->status === 'done', 'an inquiry can be marked done');
    $cancelled = brb_tour_update($legacy_id, 'cancelled', '');
    brb_t(!is_wp_error($cancelled) && $cancelled && brb_tour($legacy_id), 'cancelled keeps the record');
    brb_t(brb_tour_delete((int) $blank->id) && !brb_tour((int) $blank->id), 'an inquiry is removed only by delete');
    $created = array_values(array_filter($created, function ($id) use ($blank) {
        return (int) $id !== (int) $blank->id;
    }));

    $html = brb_tour_form();
    brb_t(strpos($html, 'id="tour"') === false && strpos($html, '#tour') === false && stripos($html, 'Schedule a tour') === false, 'the form is not a schedule-a-tour section');
    brb_t(strpos($html, 'name="company"') !== false && strpos($html, 'name="_brb_tour"') !== false, 'the form has a honeypot and a nonce');
    brb_t(strpos($html, 'name="phone"') === false && stripos($html, 'tel:') === false, 'the inquiry form has no phone field');
    brb_t(strpos($html, "What's this about") === false && strpos($html, 'name="topic"') === false && strpos($html, 'Tour the venue') === false, 'the contact form has no topic menu');
    brb_t(strpos($html, 'Preferred Dates') !== false && strpos($html, 'type="date"') !== false && strpos($html, 'name="preferred"') !== false && strpos($html, 'name="message"') !== false && strpos($html, 'type="time"') === false && strpos($html, 'datetime-local') === false && stripos($html, 'or time') === false, 'the preferred field is a date and does not ask for a time');
    brb_t(strpos($html, 'Ready to reserve?') === false && strpos($html, 'name="name"') !== false && strpos($html, 'name="message"') !== false, 'the form is the fields without a booking link above them');
    brb_t(stripos($html, 'Book Now') === false && stripos($html, 'Request a tour') === false, 'the form does not add another booking button');
    foreach ($banned as $w) brb_t(stripos($html, $w) === false, 'inquiry form has no ' . $w);
    $_GET['inquiry'] = 'ok';
    $thanks = brb_tour_form();
    unset($_GET['inquiry']);
    brb_t(brb_tour_message('ok') === "Thanks, we'll reply by email." && strpos(html_entity_decode($thanks, ENT_QUOTES), "Thanks, we'll reply by email.") !== false, 'the guest sees the confirmation sentence');
    brb_t(strpos($thanks, '<iframe') === false && strpos($thanks, '#tour') === false, 'the confirmation does not add another map or a tour anchor');

    $contact = get_page_by_path('contact');
    $contact_html = $contact ? (string) $contact->post_content : '';
    brb_t(strpos($contact_html, '[boardroom_tour]') === false && strpos($contact_html, '2220 37th Street') === false && strpos($contact_html, 'mailto:') === false, 'stored contact content does not repeat the venue or email');
    $template = file_get_contents(get_stylesheet_directory() . '/page-contact.php');
    $map = brb_map_embed();
    brb_t(is_string($template) && strpos($template, 'brb_map_embed') === false && strpos($template, 'brb_tour_form') !== false && strpos($template, "br_ask_walter_card('#contact-inquiry')") !== false && strpos($template, "br_ask_walter_card('#contact-inquiry')") < strpos($template, 'brb_tour_form') && strpos($template, 'br_contact_ask_button(true)') === false && strpos($template, 'tel:') === false && strpos($template, '2212') === false, 'the contact page puts the Walter card above the form, without its own map');
    $ask = br_contact_ask_button();
    brb_t(strpos($ask, 'data-brb-open-chat') !== false && strpos($ask, '>Ask Walter<') !== false && strpos($ask, 'tel:') === false, 'Ask Walter on the contact page opens the floating chat');
    $_GET['inquiry'] = 'ok';
    brb_t(br_contact_is_thanks(), 'inquiry=ok is the thank-you state');
    $_GET['inquiry'] = 'missing';
    brb_t(!br_contact_is_thanks(), 'a form error still uses the contact form');
    unset($_GET['inquiry']);
    $hero_at = strpos($template, 'page-hero');
    $form_at = strpos($template, 'brb_tour_form');
    $thank_at = strpos($template, 'Thank You!');
    brb_t($hero_at !== false && $form_at !== false && $thank_at !== false && $hero_at < $form_at && $form_at < $thank_at && strpos($template, 'page-hero', $thank_at) === false && strpos($template, 'brb_tour_form', $thank_at) === false && strpos($template, 'We will call or email you shortly!') !== false && strpos($template, '/rental-checklist/') === false && strpos($template, 'if (!$thanks)') !== false, 'a successful inquiry replaces the page with a thank-you and Ask Walter');
    brb_t(strpos($map, 'loading="lazy"') !== false && strpos($map, 'https://www.google.com/maps/embed') !== false && strpos($map, '41.4913116') !== false && strpos($map, '-90.4820481') !== false, 'the map iframe is lazy and uses the venue coordinates');
    brb_t(strpos($map, BRB_MAPS_URL) === false, 'the directions short link stays outside the iframe');
    $header = file_get_contents(get_stylesheet_directory() . '/header.php');
    brb_t(is_string($header) && strpos($header, 'Weekends book up early. Check your date below.') !== false && strpos($header, "is_front_page() ? '#check-your-date' : home_url('/#check-your-date')") !== false && strpos($header, 'Book Now') === false && stripos($header, 'tour') === false && stripos($header, 'tel:') === false, 'the header banner points at the date calendar and has no tour or phone link');
    $home = file_get_contents(get_stylesheet_directory() . '/front-page.php');
    $hero = is_string($home) ? substr($home, 0, (int) strpos($home, 'hero-facts')) : '';
    brb_t(is_string($home) && strpos($home, 'br_contact_form') === false && strpos($home, 'name="phone"') === false && strpos($home, 'br_tour_video_html') !== false && strpos($home, "br_ask_walter_card(home_url('/contact/'))") !== false && strpos($home, 'Book Now') === false && strpos($hero, 'Check dates') === false && strpos($hero, 'class="btn"') !== false && strpos($hero, '>Reserve Now</a>') !== false && strpos($hero, 'esc_url($book)') !== false && strpos($home, "Moline's one-of-a-kind party venue") !== false && strpos($hero, '<br>') === false && strpos($hero, 'Moline,&nbsp;IL') === false && strpos($home, 'Check Your Date') !== false && strpos($home, "Pick a date to see your price. Nothing's charged until we approve.") === false && strpos($home, 'See full booking details') !== false && strpos($home, 'hero-facts') < strpos($home, 'id="check-your-date"'), 'the homepage hero links Reserve Now to the booking page');
    $facts_at = strpos($home, 'class="hero-facts"');
    $facts = $facts_at === false ? '' : substr($home, $facts_at, strpos($home, '</p>', $facts_at) - $facts_at);
    $facts_order = ['Serving&nbsp;Kitchen', 'Up&nbsp;to&nbsp;80&nbsp;Guests', 'Game&nbsp;Room', '80&#8209;Inch&nbsp;TV', 'Ample&nbsp;Parking'];
    $facts_ok = $facts !== '';
    $facts_cursor = 0;
    foreach ($facts_order as $facts_item) {
        $facts_pos = strpos($facts, $facts_item);
        $facts_ok = $facts_ok && $facts_pos !== false && $facts_pos >= $facts_cursor;
        if ($facts_pos !== false) $facts_cursor = $facts_pos;
    }
    brb_t($facts_ok && strpos($facts, 'BYOB') === false && strpos($facts, 'Free parking') === false && strpos($facts, '8 AM') === false && strpos($facts, 'From $850') === false, 'the homepage facts strip lists the venue features in order');
    $amenities = br_amenities_html();
    foreach (['Private main entrance', 'Free parking on premises', 'Space for 40 to 50 cars', 'Wifi', 'TV', 'Sound system (Sonos, Aux, Bluetooth)', 'Pool table', 'Board games', 'Refrigerator (large)', 'Freezer', 'Mini fridge', 'Microwave', 'Dishwasher', 'Coffee maker (drip and pour-over)', 'Hot water kettle', 'Blender', 'Wine glasses', 'Smoke alarm', 'Carbon monoxide alarm', 'Fire extinguisher', 'First aid kit'] as $item) {
        brb_t(strpos($amenities, $item) !== false, 'amenities include ' . $item);
    }
    foreach (['Air conditioning', 'Heating', 'Free street parking', 'Dining table', 'Comfort and utilities', 'Furnishings', 'The room comes not set up'] as $gone) {
        brb_t(strpos($amenities, $gone) === false, 'amenities leave out ' . $gone);
    }
    brb_t(substr_count($amenities, '<section class="amenity-group') === 3, 'amenities are three groups');
    brb_t(substr_count($amenities, 'Serving and storage only, no cooking or stovetop use.') === 1 && strpos($amenities, 'Kitchen area is for serving and storage. Cooking and stovetop use are not permitted.') === false, 'the kitchen card has one serving-and-storage line');
    $gallery_tpl = file_get_contents(get_stylesheet_directory() . '/page-gallery.php');
    $book_tpl = file_get_contents(get_stylesheet_directory() . '/page.php');
    brb_t(strpos($amenities, 'The room comes not set up.') === false && strpos($home, 'The room comes not set up.') === false && strpos($gallery_tpl, 'The room comes not set up.') === false && strpos($book_tpl, 'The room comes not set up.') === false, 'the not-set-up note is not a standalone block on the home, gallery, or book page');
    brb_t(strpos($home, 'br_amenities_html') !== false && strpos($home, 'id="amenities"') !== false, 'the homepage amenities section prints the checklist');
    foreach (['Laundromat', 'Airbnb', 'airbnb', 'Cooking basics', 'Baking sheet', 'Barbecue utensils', 'Books and reading material', '$'] as $banned_item) {
        brb_t(strpos($amenities, $banned_item) === false, 'amenities do not list ' . $banned_item);
    }
    brb_t(strpos($amenities, '<li>Stove</li>') === false && strpos($amenities, '<li>Oven</li>') === false, 'amenities do not list a stove or oven');
    brb_t(is_string($gallery_tpl) && strpos($gallery_tpl, 'br_amenities_html') !== false && strpos($gallery_tpl, 'id="amenities"') !== false && strpos($gallery_tpl, 'gallery-page') < strpos($gallery_tpl, "br_ask_walter_card(home_url('/contact/'))") && strpos($gallery_tpl, "br_ask_walter_card(home_url('/contact/'))") < strpos($gallery_tpl, 'id="amenities"') && strpos($gallery_tpl, 'br_good_to_know_html') === false && strpos($gallery_tpl, 'Good to know') === false && strpos($gallery_tpl, 'See the amenities') === false && strpos($gallery_tpl, 'href="#amenities"') === false && strpos($gallery_tpl, 'gallery-hero') !== false && strpos($gallery_tpl, 'boardroom-11.jpeg') !== false, 'the gallery page puts Walter under the photo grid and uses the darker hero');
    $book_card_at = strpos($book_tpl, "br_ask_walter_card(home_url('/contact/#contact-inquiry'))");
    $calendar_at = strpos($book_tpl, 'the_content');
    brb_t(is_string($book_tpl) && strpos($book_tpl, 'br_book_shortcuts_html') === false && strpos($book_tpl, 'br_good_to_know_html') === false && strpos($book_tpl, 'Good to know') === false && strpos($book_tpl, "br_ask_walter_card(home_url('/contact/'))") !== false && strpos($book_tpl, 'br_ask_walter_card') < $calendar_at && strpos($book_tpl, 'br_review_rotator_html') === false, 'FAQ keeps Walter above the page content, without Good to know or the checklist card');
    brb_t($calendar_at !== false && $book_card_at !== false && $calendar_at < $book_card_at && strpos($book_tpl, '$book_page') !== false && strpos($book_tpl, "'Book the Boardroom'") !== false && strpos($book_tpl, 'Book it!') === false, 'the book page heading is Book the Boardroom and Walter stays under the calendar');
    $book_card = br_ask_walter_card(home_url('/contact/#contact-inquiry'));
    brb_t(strpos($book_card, 'href="' . esc_url(home_url('/contact/#contact-inquiry')) . '"') !== false && strpos($book_card, 'Leave your details') !== false && strpos($book_card, 'Hi, How Can I Help You?') !== false && strpos($book_card, 'data-brb-scroll-form="#contact-inquiry"') === false, 'the book Walter card links Leave your details to the contact form');
    brb_t(is_string($book_tpl) && strpos($book_tpl, "br_ask_walter_card(home_url('/contact/'))") !== false && strpos($book_tpl, 'br_ask_walter_card') < strpos($book_tpl, 'the_content'), 'FAQ opens with the shared Walter card');
    $reviews = br_reviews_html();
    brb_t(strpos($home, 'br_reviews_html') !== false && strpos($home, 'br_reviews_html') < strpos($home, 'block dark cta'), 'guest reviews sit before the reservation call to action');
    brb_t(strpos($reviews, 'What our guests say') !== false && strpos($reviews, '5.0 ★ from 29 guest reviews') !== false && strpos($reviews, 'Read more reviews') === false && substr_count($reviews, 'class="t-set"') === 2 && strpos($reviews, 'aria-hidden="true"') !== false && substr_count($reviews, 'class="t-card"') === 24, 'the reviews section is a marquee of every review with a hidden copy');
    brb_t(strpos($reviews, 'Dixon N.') !== false && strpos($reviews, 'Nothing else like it in the QC.') !== false && strpos($reviews, 'Cresencia') !== false && strpos($reviews, 'grandma') !== false, 'featured and later reviews are all in the marquee');
    brb_t(stripos($reviews, 'airbnb') === false && strpos($reviews, 'Google') === false && strpos($reviews, 'tel:') === false && strpos($reviews, 'name="phone"') === false, 'guest reviews do not name a source or a phone number');
    $rotator = br_review_rotator_html();
    $shortcuts = br_book_shortcuts_html();
    brb_t(strpos($shortcuts, 'See the amenities') === false && strpos($shortcuts, 'Rental checklist') !== false && strpos($shortcuts, 'What to do before and after') !== false && strpos($shortcuts, '/#amenities') === false && strpos($shortcuts, '/rental-checklist/') !== false && strpos($book_tpl, 'br_book_shortcuts_html') === false, 'the standalone checklist helper remains, and the book page does not print that card');
    brb_t(strpos($rotator, 'class="review-card on"') !== false && substr_count($rotator, '<blockquote') === 4 && strpos($home, 'br_reviews_html') !== false, 'featured quotes still exist for the home page, which keeps the review marquee');
    $summary = br_review_aggregate_schema();
    brb_t(is_array($summary) && $summary['ratingValue'] === '5.0' && $summary['reviewCount'] === 29, 'the review schema uses the 5.0 rating and the count of 29');
    global $wp_query, $wp_the_query;
    $saved_query = $wp_query;
    $saved_the = $wp_the_query;
    foreach (br_seo_pages() as $slug => $seo) {
        if ($slug === 'home') {
            $front = (int) get_option('page_on_front');
            $q = $front ? new WP_Query(['page_id' => $front]) : new WP_Query(['post_type' => 'page', 'pagename' => 'home']);
        } else {
            $q = new WP_Query(['pagename' => $slug]);
        }
        $wp_query = $q;
        $wp_the_query = $q;
        $title = html_entity_decode(wp_get_document_title(), ENT_QUOTES, 'UTF-8');
        brb_t($q->have_posts() && $title === $seo['title'] && substr_count($title, ' – ') === 0, 'the ' . $slug . ' title does not add the site name again');
    }
    $wp_query = $saved_query;
    $wp_the_query = $saved_the;
    ob_start();
    brb_place_schema();
    $plain_schema = ob_get_clean();
    brb_t(strpos($plain_schema, 'aggregateRating') === false, 'review stars are not added to the venue schema off the home page');
    $video = br_tour_video_html(br_ask_walter_card(home_url('/contact/')));
    $contact_tpl = file_get_contents(get_stylesheet_directory() . '/page-contact.php');
    brb_t(strpos($home, 'br_tour_video_html') !== false && strpos($home, 'br_tour_video_html') < strpos($home, 'id="amenities"'), 'the tour video sits after the hero and before amenities');
    brb_t(strpos($video, 'Take a tour') !== false && strpos($video, 'Take a look around') === false && strpos($video, 'walter-tour') === false && strpos($video, 'A unique event destination in the Quad Cities') !== false && strpos($video, '<video') !== false && strpos($video, 'controls') !== false && strpos($video, 'preload="none"') !== false && strpos($video, 'playsinline') !== false && strpos($video, 'poster=') !== false && strpos($video, 'autoplay') === false && strpos($video, 'tour-play') !== false && strpos($video, 'Book Now') === false && strpos($video, 'Request a tour') === false && strpos($video, 'id="ask-walter"') !== false && strpos($video, 'tour-stage') < strpos($video, 'id="ask-walter"'), 'the tour video is a native player with Walter directly under it and no booking buttons');
    brb_t(strpos($video, 'href="' . esc_url(home_url('/contact/')) . '"') !== false && strpos($video, 'Leave your details') !== false && strpos($video, 'boardroom-tour-720.mp4') !== false && strpos($video, brb_book_url()) === false, 'the homepage Walter card links Leave your details to contact');
    brb_t(is_string($contact_tpl) && strpos($contact_tpl, 'br_tour_video_html') === false, 'the contact page does not include the tour video');
    brb_t(stripos($video, 'airbnb') === false && stripos($video, 'google') === false && strpos($video, 'tel:') === false, 'the tour video does not name an outside source or a phone number');
    $mp4 = get_stylesheet_directory() . '/assets/video/boardroom-tour-720.mp4';
    $poster = get_stylesheet_directory() . '/assets/video/boardroom-tour-poster.jpg';
    $head_bytes = is_file($mp4) ? file_get_contents($mp4, false, null, 0, 32) : '';
    brb_t(is_file($mp4) && filesize($mp4) > 1000000 && is_file($poster) && strpos($head_bytes, 'ftyp') !== false && strpos($head_bytes, 'git-lfs') === false, 'the tour video is a real mp4 in the theme, not a pointer');
    $video_url = 'http://127.0.0.1/wp-content/themes/boardroom/assets/video/boardroom-tour-720.mp4';
    $headers = shell_exec('curl -sI ' . escapeshellarg($video_url));
    $ranged = shell_exec('curl -sI -H ' . escapeshellarg('Range: bytes=0-1') . ' ' . escapeshellarg($video_url));
    brb_t(is_string($headers) && stripos($headers, 'Accept-Ranges: bytes') !== false, 'the mp4 advertises byte ranges');
    brb_t(is_string($ranged) && strpos($ranged, '206') !== false && stripos($ranged, 'Content-Range:') !== false, 'the mp4 answers a range request');
    $tour_nav = false;
    $locs = get_nav_menu_locations();
    if (!empty($locs['primary'])) {
        foreach ((array) wp_get_nav_menu_items($locs['primary']) as $item) {
            if (stripos((string) $item->title, 'tour') !== false || strpos((string) $item->url, '#tour') !== false) $tour_nav = true;
        }
    }
    brb_t(!$tour_nav, 'the primary menu has no tour link');
    $home_nav = false;
    $book_nav = false;
    $faq_nav = false;
    if (!empty($locs['primary'])) {
        foreach ((array) wp_get_nav_menu_items($locs['primary']) as $item) {
            $title = strtolower(trim(wp_strip_all_tags((string) $item->title)));
            $path = trim((string) parse_url((string) $item->url, PHP_URL_PATH), '/');
            if ($title === 'home') $home_nav = true;
            if ($title === 'reserve' && substr($path, -strlen('reserve-the-boardroom-quad-cities')) === 'reserve-the-boardroom-quad-cities') $book_nav = true;
            if ($title === 'faq' && substr($path, -3) === 'faq') $faq_nav = true;
        }
    }
    brb_t(!$home_nav && $book_nav && $faq_nav, 'the primary menu drops Home and keeps working Reserve and FAQ links');
    $footer = file_get_contents(get_stylesheet_directory() . '/footer.php');
    $footer_map = function_exists('br_footer_map') ? br_footer_map() : '';
    brb_t(is_string($footer) && strpos($footer, 'br_footer_map') !== false && strpos($footer_map, 'https://www.google.com/maps?q=2220+37th+Street,+Moline,+IL+61265') !== false && strpos($footer_map, 'output=embed') !== false && strpos($footer_map, 'loading="lazy"') !== false && strpos($footer_map, 'referrerpolicy="no-referrer-when-downgrade"') !== false && strpos($footer, 'Get directions') !== false && strpos($footer, '2220 37th Street, Moline, IL 61265') !== false, 'the footer map uses the venue embed');
    brb_t(is_string($contact_tpl) && strpos($contact_tpl, "br_ask_walter_card('#contact-inquiry')") !== false && strpos($contact_tpl, '<h2>Send a Message</h2>') !== false && strpos($contact_tpl, '<h2>Send a Message</h2>') < strpos($contact_tpl, 'brb_tour_form') && strpos($contact_tpl, 'brb_tour_form') !== false && strpos($contact_tpl, 'brb_map_embed') === false && strpos($contact_tpl, "br_ask_walter_card('#contact-inquiry')") < strpos($contact_tpl, 'brb_tour_form') && strpos($contact_tpl, 'Thank You!') !== false && strpos($contact_tpl, 'brb_tour_form') < strpos($contact_tpl, 'Thank You!') && strpos($contact_tpl, 'br_contact_ask_button()') !== false, 'the contact page is Walter, then Send a Message and the form, and a successful inquiry shows Ask Walter');
    $chat_src = file_get_contents(dirname((new ReflectionFunction('brb_chat_markup'))->getFileName()) . '/chat.php');
    brb_t(is_string($chat_src) && strpos($chat_src, 'function brb_chat_page_is_inline()') !== false && strpos($chat_src, "is_page('faq')") !== false && strpos($chat_src, "is_page('contact')") !== false && strpos($chat_src, 'if (brb_chat_page_is_inline()) return;') !== false && strpos($chat_src, "if (is_front_page()) return;") === false, 'the floating chat stays off pages that already show the inline card');
    brb_t(is_string($home) && strpos($home, 'br_tour_video_html') !== false && strpos($home, "br_ask_walter_card(home_url('/contact/'))") !== false && strpos($home, "brb_chat_markup('home')") === false && strpos($home, 'Contact the Boardroom') === false && strpos($home, 'id="contact"') === false, 'the home page puts the shared Walter card under the tour video');
    $site_js_home = file_get_contents(get_stylesheet_directory() . '/assets/site.js');
    brb_t(is_string($site_js_home) && strpos($site_js_home, ".brb-chat:not(.brb-chat-inline)") !== false && strpos($site_js_home, "getElementById('ask-walter')") !== false && strpos($site_js_home, 'brb-chat-launch') !== false, 'the homepage keeps one chat and the floating Ask Walter bubble');
    $home_chat = function_exists('brb_chat_markup') ? brb_chat_markup('home', '#contact-inquiry') : '';
    $faq_chat = function_exists('brb_chat_markup') ? brb_chat_markup('home', home_url('/contact/')) : '';
    brb_t(strpos($home_chat, 'brb-chat-hello') !== false && strpos($home_chat, 'Ask Walter') !== false && strpos($home_chat, "The Boardroom's butler") !== false && strpos($home_chat, 'Hi, How Can I Help You?') !== false && strpos($home_chat, 'data-brb-scroll-form="#contact-inquiry"') !== false && strpos($home_chat, 'Is my date available?') !== false && strpos($home_chat, 'How many guests fit?') !== false && strpos($home_chat, 'What does a full day include?') === false && strpos($faq_chat, 'href="' . esc_url(home_url('/contact/')) . '"') !== false && strpos($faq_chat, 'Leave your details') !== false && strpos($home_chat, '2212') === false, 'the shared Walter card greets, scrolls on contact, and links to contact from FAQ');
    $css = file_get_contents(get_stylesheet_directory() . '/style.css');
    $site_js = file_get_contents(get_stylesheet_directory() . '/assets/site.js');
    brb_t(is_string($css) && strpos($css, 'z-index:400') !== false && strpos($css, 'body.admin-bar .site-header{top:32px}') !== false && strpos($css, '.nav.open{display:block}') !== false && strpos($css, 't-scroll') !== false && strpos($css, 'translateX(-50%)') !== false, 'the header stays above the page and the review track scrolls in CSS');
    brb_t(is_string($css) && strpos($css, '.contact-ask{display:inline-flex') !== false && strpos($css, 'border:2px solid #c4912e') !== false && strpos($css, "font-family:'EB Garamond'") !== false && strpos($css, '.contact-thanks h2{text-transform:none') !== false, 'the contact Ask Walter button is a small gold outline, and the thank-you heading keeps its wording');
    brb_t(is_string($site_js) && strpos($site_js, ".nav a") === false && strpos($site_js, "querySelectorAll('.gallery a')") !== false, 'the gallery lightbox does not capture menu links');

    $privacy = get_page_by_path('privacy');
    $privacy_html = $privacy ? (string) $privacy->post_content : '';
    brb_t(strpos($privacy_html, 'The contact form asks for your name, email, and a message') !== false, 'privacy lists what a contact inquiry collects');
    brb_t(strpos($privacy_html, 'contact inquiry records') !== false && strpos($privacy_html, 'only when a staff member deletes it') !== false, 'privacy says inquiries stay until staff delete them');
    brb_t(strpos($privacy_html, 'tour request') === false && stripos($privacy_html, 'phone') === false, 'privacy no longer describes a tour request or a phone number');
    brb_t(strpos($privacy_html, 'sets no advertising, analytics, or tracking cookies of its own') !== false && strpos($privacy_html, 'may set cookies from Google') !== false && strpos($privacy_html, "Google's privacy policy applies") !== false, 'privacy says Google may set cookies on the embedded map');
    brb_t(strpos($privacy_html, 'does not embed a map') === false && strpos($privacy_html, 'cookie banner') === false, 'privacy does not claim the map is absent and does not add a banner');

    if ($user && $row && !is_wp_error($row)) {
        $_GET = ['page' => 'brb-tours'];
        ob_start();
        brb_admin_tours();
        $admin_html = ob_get_clean();
        brb_t(strpos($admin_html, '<h1>Inquiries</h1>') !== false && strpos($admin_html, 'Inquiry Guest') !== false && strpos($admin_html, 'Legacy Guest') !== false && strpos($admin_html, '309-555-0199') !== false && strpos($admin_html, 'Mark done') !== false, 'the admin list is Inquiries and still shows the older request');
        brb_t(strpos($admin_html, 'Delete this inquiry') === false && strpos($admin_html, 'brb_tour_delete') === false && strpos($admin_html, 'Tour requests') === false, 'the list does not delete an inquiry from the row');
        $_GET = ['page' => 'brb-tours', 'id' => (int) $row->id];
        ob_start();
        brb_admin_tours();
        $detail = ob_get_clean();
        brb_t(strpos($detail, 'Delete this inquiry') !== false && strpos($detail, 'name="staff_notes"') !== false && strpos($detail, 'General inquiry') !== false && strpos($detail, 'Preferred dates') !== false && strpos($detail, 'December 12, 2026') !== false, 'the detail page has notes, the general inquiry, and the preferred date');
        brb_t(strpos($detail, '>Phone<') === false, 'a new inquiry detail does not show an empty phone');
        $_GET = ['page' => 'brb-tours', 'id' => $legacy_id];
        ob_start();
        brb_admin_tours();
        $old_detail = ob_get_clean();
        brb_t(strpos($old_detail, '309-555-0199') !== false && strpos($old_detail, '2026-12-01') !== false && strpos($old_detail, 'Old tour note') !== false && strpos($old_detail, 'Morning') !== false, 'an older tour request still shows its phone, date, and notes');
        $_GET = [];
    }
} finally {
    delete_transient(brb_tour_rate_key());
    if ($created) {
        $in = implode(',', array_map('intval', $created));
        $wpdb->query("DELETE FROM " . brb_tour_table() . " WHERE id IN ($in)");
    }
    $wpdb->query("DELETE FROM " . brb_tour_table() . " WHERE email IN ('inquiry-guest@example.com','optional-inquiry@example.com','again-inquiry@example.com','later-inquiry@example.com','ada@example.com','legacy-tour@example.com','topic-inquiry@example.com','copied-inquiry@example.com','retry-inquiry@example.com','not-an-email') OR name IN ('Legacy Guest','Bad Email')");
    if ($snapshot !== false && $snapshot !== null) update_option('brb_settings', $snapshot);
    else delete_option('brb_settings');
}

echo "\n{$GLOBALS['brb_ok']} passed, {$GLOBALS['brb_fail']} failed\n";
exit($GLOBALS['brb_fail'] ? 1 : 0);
