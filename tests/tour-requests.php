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
    brb_t(get_option('brb_version') === '2.5.10', 'plugin storage version is 2.5.10');
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
    $bad_topic = brb_tour_submit(['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello', 'topic' => 'midnight']);
    brb_t(is_wp_error($bad_topic) && $bad_topic->get_error_code() === 'topic', 'an unknown topic is rejected');
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
        'preferred' => 'Saturday afternoon',
        'message' => 'Can we see the room?',
    ]);
    brb_t(!is_wp_error($row) && $row && $row->status === 'new', 'a name and email store a new inquiry');
    if (!is_wp_error($row) && $row) {
        $created[] = (int) $row->id;
        brb_t($row->phone === '' && $row->preferred_date === null && $row->time_window === '' && $row->party_size === null, 'a new inquiry leaves the old tour columns empty');
        brb_t($row->topic === 'tour' && $row->preferred === 'Saturday afternoon' && $row->notes === 'Can we see the room?' && $row->staff_notes === '', 'topic, preferred time, and message are stored');
        brb_t(brb_tour($legacy_id) && brb_tour($legacy_id)->notes === 'Old tour note', 'saving an inquiry does not delete an older row');
        $tos = [];
        foreach ($GLOBALS['brb_mails'] as $mail) $tos[] = $mail['to'];
        sort($tos);
        brb_t($tos === ['boardroomqc@gmail.com'], 'the notice is emailed to the inquiry address');
        brb_t(($GLOBALS['brb_mails'][0]['subject'] ?? '') === 'New contact inquiry', 'the subject is New contact inquiry');
        $headers = $GLOBALS['brb_mails'][0]['headers'] ?? [];
        $header_text = is_array($headers) ? implode("\n", $headers) : (string) $headers;
        brb_t(strpos($header_text, 'From: The Boardroom <boardroomqc@gmail.com>') !== false, 'inquiry mail is from boardroomqc@gmail.com');
        brb_t(strpos($header_text, 'Reply-To: inquiry-guest@example.com') !== false, 'inquiry mail replies to the guest');
        $body = (string) ($GLOBALS['brb_mails'][0]['message'] ?? '');
        brb_t(strpos($body, 'A contact inquiry was received.') !== false && strpos($body, 'No date is held.') !== false && strpos($body, 'Inquiry Guest') !== false && strpos($body, 'Can we see the room?') !== false && strpos($body, 'Tour the venue') !== false && strpos($body, 'Saturday afternoon') !== false, 'the notice describes the inquiry and does not hold a date');
        brb_t(strpos($body, 'Phone:') === false && stripos($body, 'tour was requested') === false && strpos($body, '309-555-0100') === false, 'the inquiry email omits a phone number');
        foreach ($banned as $w) brb_t(stripos($body, $w) === false, 'inquiry email has no ' . $w);
    }

    delete_transient(brb_tour_rate_key());
    $blank = brb_tour_submit(['name' => 'Optional Guest', 'email' => 'optional-inquiry@example.com', 'message' => 'Just a question', 'topic' => '', 'preferred' => '']);
    brb_t(!is_wp_error($blank) && $blank->topic === '' && $blank->preferred === '' && $blank->preferred_date === null, 'topic and preferred date or time can be left blank');
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
    brb_t(strpos($html, 'Question') !== false && strpos($html, 'Tour the venue') !== false && strpos($html, 'Planning an event') !== false, 'the optional topic lists question, tour, and event');
    brb_t(strpos($html, 'name="preferred"') !== false && strpos($html, 'name="message"') !== false, 'the form has a message and a preferred date or time');
    brb_t(strpos($html, 'Ready to reserve?') !== false && strpos($html, home_url('/book/')) !== false && strpos($html, 'Book your date') !== false, 'the form points to booking above the fields');
    brb_t(stripos($html, 'Book Now') === false && stripos($html, 'Request a tour') === false, 'the form does not add another booking button');
    foreach ($banned as $w) brb_t(stripos($html, $w) === false, 'inquiry form has no ' . $w);
    $_GET['inquiry'] = 'ok';
    $thanks = brb_tour_form();
    unset($_GET['inquiry']);
    brb_t(brb_tour_message('ok') === "Thanks, we'll reply by email." && strpos(html_entity_decode($thanks, ENT_QUOTES), "Thanks, we'll reply by email.") !== false, 'the guest sees the confirmation sentence');
    brb_t(strpos($thanks, '<iframe') === false && strpos($thanks, '#tour') === false, 'the confirmation does not add another map or a tour anchor');

    $contact = get_page_by_path('contact');
    $contact_html = $contact ? (string) $contact->post_content : '';
    brb_t(strpos($contact_html, '[boardroom_tour]') === false && strpos($contact_html, '2220 37th Street, Moline, IL 61265') !== false && strpos($contact_html, 'mailto:boardroomqc@gmail.com') !== false, 'stored contact content has the venue and email and no tour shortcode');
    $template = file_get_contents(get_stylesheet_directory() . '/page-contact.php');
    $map = brb_map_embed();
    brb_t(is_string($template) && strpos($template, 'brb_map_embed') !== false && strpos($template, 'brb_tour_form') !== false, 'the contact template renders the map and the one form');
    brb_t(strpos($map, 'loading="lazy"') !== false && strpos($map, 'https://www.google.com/maps/embed') !== false && strpos($map, '41.4913116') !== false && strpos($map, '-90.4820481') !== false, 'the map iframe is lazy and uses the venue coordinates');
    brb_t(strpos($map, BRB_MAPS_URL) === false, 'the directions short link stays outside the iframe');
    $header = file_get_contents(get_stylesheet_directory() . '/header.php');
    brb_t(is_string($header) && strpos($header, 'Book Now') !== false && stripos($header, 'tour') === false && stripos($header, 'tel:') === false, 'the header keeps Book Now and has no tour or phone link');
    $home = file_get_contents(get_stylesheet_directory() . '/front-page.php');
    brb_t(is_string($home) && strpos($home, 'br_contact_form') === false && strpos($home, 'name="phone"') === false && strpos($home, "home_url('/contact/')") !== false, 'the homepage links to contact instead of a second form');
    $amenities = br_amenities_html();
    foreach (['Air conditioning', 'Heating', 'Hot water', 'Private entrance', 'Free parking on premises', 'Free street parking', 'Wifi', 'TV', 'Sound system (Sonos, Aux, Bluetooth)', 'Pool table', 'Board games', 'Dining table', 'Dedicated workspace', 'Refrigerator (large)', 'Freezer', 'Mini fridge', 'Microwave', 'Dishwasher', 'Coffee maker (drip and pour-over)', 'Hot water kettle', 'Toaster', 'Blender', 'Wine glasses', 'Smoke alarm', 'Carbon monoxide alarm', 'Fire extinguisher', 'First aid kit'] as $item) {
        brb_t(strpos($amenities, $item) !== false, 'amenities include ' . $item);
    }
    brb_t(strpos($amenities, 'Kitchen area is for serving and storage. Cooking and stovetop use are not permitted.') !== false, 'the kitchen note says serving and storage only');
    brb_t(strpos($amenities, 'The room comes not set up.') !== false && strpos($amenities, 'No overnight stays.') !== false, 'amenities say the room is not set up and there are no overnight stays');
    brb_t(strpos($home, 'br_amenities_html') !== false && strpos($home, 'id="amenities"') !== false, 'the homepage amenities section prints the checklist');
    foreach (['Laundromat', 'Airbnb', 'airbnb', 'Cooking basics', 'Baking sheet', 'Barbecue utensils', 'Books and reading material', '$'] as $banned_item) {
        brb_t(strpos($amenities, $banned_item) === false, 'amenities do not list ' . $banned_item);
    }
    brb_t(strpos($amenities, '<li>Stove</li>') === false && strpos($amenities, '<li>Oven</li>') === false, 'amenities do not list a stove or oven');
    $gallery_tpl = file_get_contents(get_stylesheet_directory() . '/page-gallery.php');
    $book_tpl = file_get_contents(get_stylesheet_directory() . '/page.php');
    brb_t(is_string($gallery_tpl) && strpos($gallery_tpl, '/#amenities') !== false, 'the gallery page links to the amenities');
    brb_t(is_string($book_tpl) && strpos($book_tpl, 'book-amenities') !== false && strpos($book_tpl, '/#amenities') !== false, 'the book page links to the amenities');
    $reviews = br_reviews_html();
    brb_t(strpos($home, 'br_reviews_html') !== false && strpos($home, 'br_reviews_html') < strpos($home, 'block dark cta'), 'guest reviews sit before the reservation call to action');
    brb_t(strpos($reviews, 'What our guests say') !== false && strpos($reviews, '5.0 ★ from 29 guest reviews') !== false && strpos($reviews, 'Read more reviews') === false && substr_count($reviews, 'class="t-set"') === 2 && strpos($reviews, 'aria-hidden="true"') !== false && substr_count($reviews, 'class="t-card"') === 24, 'the reviews section is a marquee of every review with a hidden copy');
    brb_t(strpos($reviews, 'Dixon N.') !== false && strpos($reviews, 'Nothing else like it in the QC.') !== false && strpos($reviews, 'Cresencia') !== false && strpos($reviews, 'grandma') !== false, 'featured and later reviews are all in the marquee');
    brb_t(stripos($reviews, 'airbnb') === false && strpos($reviews, 'Google') === false && strpos($reviews, 'tel:') === false && strpos($reviews, 'name="phone"') === false, 'guest reviews do not name a source or a phone number');
    $rotator = br_review_rotator_html();
    brb_t(is_string($book_tpl) && strpos($book_tpl, 'br_review_rotator_html') !== false && strpos($rotator, 'class="review-card on"') !== false && substr_count($rotator, '<blockquote') === 4, 'the book page rotates the featured quotes and shows the first without script');
    $summary = br_review_aggregate_schema();
    brb_t(is_array($summary) && $summary['ratingValue'] === '5.0' && $summary['reviewCount'] === 29, 'the review schema uses the 5.0 rating and the count of 29');
    ob_start();
    brb_place_schema();
    $plain_schema = ob_get_clean();
    brb_t(strpos($plain_schema, 'aggregateRating') === false, 'review stars are not added to the venue schema off the home page');
    $video = br_tour_video_html();
    $contact_tpl = file_get_contents(get_stylesheet_directory() . '/page-contact.php');
    brb_t(strpos($home, 'br_tour_video_html') !== false && strpos($home, 'br_tour_video_html') < strpos($home, 'id="amenities"'), 'the tour video sits after the hero and before amenities');
    brb_t(strpos($video, 'Take a tour') !== false && strpos($video, 'Take a look around The Boardroom') !== false && strpos($video, '<video') !== false && strpos($video, 'controls') !== false && strpos($video, 'preload="none"') !== false && strpos($video, 'playsinline') !== false && strpos($video, 'poster=') !== false && strpos($video, 'autoplay') === false && strpos($video, 'tour-play') !== false, 'the tour video is a native player with a poster and a play button');
    brb_t(strpos($video, home_url('/book/')) !== false && strpos($video, home_url('/contact/')) !== false && strpos($video, 'boardroom-tour-720.mp4') !== false, 'the tour video links to booking and the contact form');
    brb_t(is_string($contact_tpl) && strpos($contact_tpl, 'br_tour_video_html') !== false, 'the contact page includes the tour video');
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
            if ($title === 'book' && substr($path, -4) === 'book') $book_nav = true;
            if ($title === 'faq' && substr($path, -3) === 'faq') $faq_nav = true;
        }
    }
    brb_t(!$home_nav && $book_nav && $faq_nav, 'the primary menu drops Home and keeps working Book and FAQ links');
    $footer = file_get_contents(get_stylesheet_directory() . '/footer.php');
    $footer_map = function_exists('br_footer_map') ? br_footer_map() : '';
    brb_t(is_string($footer) && strpos($footer, 'br_footer_map') !== false && strpos($footer_map, 'https://www.google.com/maps?q=2220+37th+Street,+Moline,+IL+61265') !== false && strpos($footer_map, 'output=embed') !== false && strpos($footer_map, 'loading="lazy"') !== false && strpos($footer_map, 'referrerpolicy="no-referrer-when-downgrade"') !== false && strpos($footer, 'Get directions') !== false && strpos($footer, '2220 37th Street, Moline, IL 61265') !== false, 'the footer map uses the venue embed');
    brb_t(is_string($contact_tpl) && strpos($contact_tpl, 'brb_chat_markup') !== false && strpos($contact_tpl, 'Contact the Boardroom') !== false, 'the contact page includes the inline assistant');
    brb_t(is_string($home) && strpos($home, 'brb_chat_markup') !== false && strpos($home, 'Contact the Boardroom') !== false, 'the home contact section includes the inline assistant');
    $css = file_get_contents(get_stylesheet_directory() . '/style.css');
    $site_js = file_get_contents(get_stylesheet_directory() . '/assets/site.js');
    brb_t(is_string($css) && strpos($css, 'z-index:400') !== false && strpos($css, 'body.admin-bar .site-header{top:32px}') !== false && strpos($css, '.nav.open{display:block}') !== false && strpos($css, 't-scroll') !== false && strpos($css, 'translateX(-50%)') !== false, 'the header stays above the page and the review track scrolls in CSS');
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
        brb_t(strpos($detail, 'Delete this inquiry') !== false && strpos($detail, 'name="staff_notes"') !== false && strpos($detail, 'Tour the venue') !== false, 'the detail page has notes and a delete button');
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
    $wpdb->query("DELETE FROM " . brb_tour_table() . " WHERE email IN ('inquiry-guest@example.com','optional-inquiry@example.com','again-inquiry@example.com','later-inquiry@example.com','ada@example.com','legacy-tour@example.com') OR name IN ('Legacy Guest')");
    if ($snapshot !== false && $snapshot !== null) update_option('brb_settings', $snapshot);
    else delete_option('brb_settings');
}

echo "\n{$GLOBALS['brb_ok']} passed, {$GLOBALS['brb_fail']} failed\n";
exit($GLOBALS['brb_fail'] ? 1 : 0);
