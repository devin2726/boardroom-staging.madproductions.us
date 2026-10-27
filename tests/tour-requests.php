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
    brb_t(get_option('brb_version') === '2.2.5', 'plugin storage version is 2.2.5');
    brb_t($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', brb_tour_table())) === brb_tour_table(), 'tour requests table exists');
    brb_t((string) brb_opt('tour_email') === 'boardroomqc@gmail.com', 'tour email defaults to boardroomqc@gmail.com');

    $user = get_user_by('login', 'admin');
    if ($user) wp_set_current_user($user->ID);

    $stored = brb_opt();
    $stored['tour_email'] = 'not-an-email';
    update_option('brb_settings', $stored);
    brb_migrate_settings();
    brb_t((string) brb_opt('tour_email') === 'boardroomqc@gmail.com', 'an invalid tour email is replaced on upgrade');
    $stored = brb_opt();
    $stored['tour_email'] = 'tours@example.com';
    $stored['admin_email'] = 'staff@example.com, desk@example.com';
    update_option('brb_settings', $stored);
    brb_migrate_settings();
    brb_t((string) brb_opt('tour_email') === 'tours@example.com', 'a real tour email is kept');
    $recipients = brb_tour_recipients();
    sort($recipients);
    brb_t($recipients === ['desk@example.com', 'staff@example.com', 'tours@example.com'], 'tour mail goes to the admin list and the tour address');
    $stored['admin_email'] = 'boardroomqc@gmail.com';
    $stored['tour_email'] = 'boardroomqc@gmail.com';
    update_option('brb_settings', $stored);
    brb_t(brb_tour_recipients() === ['boardroomqc@gmail.com'], 'the same address is emailed once');

    delete_transient(brb_tour_rate_key());
    $missing = brb_tour_submit(['name' => '', 'email' => 'a@example.com']);
    brb_t(is_wp_error($missing) && $missing->get_error_code() === 'missing', 'a tour request needs a name');
    $neither = brb_tour_submit(['name' => 'Ada', 'email' => '', 'phone' => '']);
    brb_t(is_wp_error($neither) && $neither->get_error_code() === 'missing', 'a tour request needs an email or a phone');
    $bad_email = brb_tour_submit(['name' => 'Ada', 'email' => 'not-email', 'phone' => '']);
    brb_t(is_wp_error($bad_email) && $bad_email->get_error_code() === 'email', 'a bad email is rejected when no phone is given');
    $short_phone = brb_tour_submit(['name' => 'Ada', 'phone' => '123']);
    brb_t(is_wp_error($short_phone) && $short_phone->get_error_code() === 'phone', 'a phone-only request needs a usable number');
    $bad_date = brb_tour_submit(['name' => 'Ada', 'email' => 'ada@example.com', 'preferred_date' => '2026-13-40']);
    brb_t(is_wp_error($bad_date) && $bad_date->get_error_code() === 'date', 'an impossible date is rejected');
    $bad_window = brb_tour_submit(['name' => 'Ada', 'email' => 'ada@example.com', 'time_window' => 'midnight']);
    brb_t(is_wp_error($bad_window) && $bad_window->get_error_code() === 'window', 'an unknown time window is rejected');
    $spam = brb_tour_submit(['name' => 'Ada', 'email' => 'ada@example.com', 'company' => 'http://spam.test']);
    brb_t(is_wp_error($spam) && $spam->get_error_code() === 'spam', 'a filled honeypot is not stored');
    brb_t((int) $wpdb->get_var("SELECT COUNT(*) FROM " . brb_tour_table() . " WHERE email='ada@example.com'") === 0, 'rejected tour requests are not stored');

    $GLOBALS['brb_mails'] = [];
    delete_transient(brb_tour_rate_key());
    $phone_only = brb_tour_submit([
        'name' => 'Phone Guest',
        'phone' => '(309) 555-0100',
        'time_window' => 'morning',
        'notes' => 'Birthday',
    ]);
    brb_t(!is_wp_error($phone_only) && $phone_only->status === 'new' && $phone_only->email === '', 'a phone number alone can request a tour');
    if (!is_wp_error($phone_only)) $created[] = (int) $phone_only->id;

    delete_transient(brb_tour_rate_key());
    $GLOBALS['brb_mails'] = [];
    $row = brb_tour_submit([
        'name' => 'Tour Guest',
        'email' => 'tour-guest@example.com',
        'phone' => '',
        'preferred_date' => '2026-11-02',
        'time_window' => 'evening',
        'party_size' => '12',
        'notes' => 'Rehearsal dinner',
    ]);
    brb_t(!is_wp_error($row) && $row && $row->status === 'new', 'an email alone stores a new tour request');
    if (!is_wp_error($row) && $row) {
        $created[] = (int) $row->id;
        brb_t($row->preferred_date === '2026-11-02' && $row->time_window === 'evening' && (int) $row->party_size === 12, 'date, time window, and party size are stored');
        brb_t($row->notes === 'Rehearsal dinner' && $row->staff_notes === '', 'guest notes are stored and staff notes start empty');
        $tos = [];
        foreach ($GLOBALS['brb_mails'] as $mail) $tos[] = $mail['to'];
        sort($tos);
        brb_t($tos === ['boardroomqc@gmail.com'], 'the notice is emailed to the tour address');
        $body = (string) ($GLOBALS['brb_mails'][0]['message'] ?? '');
        brb_t(strpos($body, 'No time is confirmed.') !== false && strpos($body, 'Tour Guest') !== false && strpos($body, 'Rehearsal dinner') !== false, 'the notice describes the request and does not confirm a time');
        foreach ($banned as $w) brb_t(stripos($body, $w) === false, 'tour email has no ' . $w);
    }

    delete_transient(brb_tour_rate_key());
    $blank_people = brb_tour_submit(['name' => 'Optional Guest', 'email' => 'optional-tour@example.com', 'party_size' => '', 'preferred_date' => '']);
    brb_t(!is_wp_error($blank_people) && $blank_people->party_size === null && $blank_people->preferred_date === null, 'people and date can be left blank');
    if (!is_wp_error($blank_people) && $blank_people) $created[] = (int) $blank_people->id;

    $soon = brb_tour_submit(['name' => 'Again', 'email' => 'again-tour@example.com']);
    brb_t(is_wp_error($soon) && $soon->get_error_code() === 'soon', 'a second request right away is rate limited');
    brb_t((int) $wpdb->get_var("SELECT COUNT(*) FROM " . brb_tour_table() . " WHERE email='again-tour@example.com'") === 0, 'a rate-limited request is not stored');
    set_transient(brb_tour_rate_key(), ['n' => 5, 'last' => time() - 30], HOUR_IN_SECONDS);
    $hour = brb_tour_submit(['name' => 'Later', 'email' => 'later-tour@example.com']);
    brb_t(is_wp_error($hour) && $hour->get_error_code() === 'hour', 'five requests in an hour stop the next one');

    $updated = brb_tour_update((int) $row->id, 'contacted', 'Called and left a message.');
    brb_t(!is_wp_error($updated) && $updated->status === 'contacted' && $updated->staff_notes === 'Called and left a message.', 'staff can save a status and notes');
    brb_t(brb_tour((int) $row->id), 'saving a status does not delete the request');
    $done = brb_tour_update((int) $row->id, 'done', 'Called and left a message.');
    brb_t(!is_wp_error($done) && $done->status === 'done', 'a request can be marked done');
    $cancelled = brb_tour_update((int) $phone_only->id, 'cancelled', '');
    brb_t(!is_wp_error($cancelled) && $cancelled && brb_tour((int) $phone_only->id), 'cancelled keeps the record');
    brb_t(brb_tour_delete((int) $blank_people->id) && !brb_tour((int) $blank_people->id), 'a request is removed only by delete');
    $created = array_values(array_filter($created, function ($id) use ($blank_people) {
        return (int) $id !== (int) $blank_people->id;
    }));

    $html = brb_tour_form();
    brb_t(strpos($html, 'id="tour"') !== false && strpos($html, 'Schedule a tour') !== false, 'the form is a tour section');
    brb_t(strpos($html, 'name="company"') !== false && strpos($html, 'name="_brb_tour"') !== false, 'the form has a honeypot and a nonce');
    brb_t(strpos($html, 'Request a tour') !== false && stripos($html, 'Book Now') === false, 'the form does not add another Book Now button');
    brb_t(strpos($html, 'does not confirm a time') !== false, 'the form does not promise a confirmed time');
    foreach ($banned as $w) brb_t(stripos($html, $w) === false && stripos($html, 'online payment') === false, 'tour form has no ' . $w);
    $_GET['tour'] = 'ok';
    $thanks = brb_tour_form();
    unset($_GET['tour']);
    brb_t(strpos($thanks, "Thanks, we'll contact you to confirm a time.") !== false, 'the guest sees the confirmation sentence');
    brb_t(stripos($thanks, 'your tour is confirmed') === false && stripos($thanks, 'time is confirmed') === false, 'the confirmation does not say a time is already set');

    $contact = get_page_by_path('contact');
    $contact_html = $contact ? (string) $contact->post_content : '';
    brb_t(strpos($contact_html, '[boardroom_tour]') !== false, 'the contact page includes the tour form');
    $header = file_get_contents(get_stylesheet_directory() . '/header.php');
    brb_t(is_string($header) && strpos($header, 'Book Now') !== false && stripos($header, 'tour') === false, 'the header keeps Book Now and has no tour button');
    $tour_nav = false;
    $locs = get_nav_menu_locations();
    if (!empty($locs['primary'])) {
        foreach ((array) wp_get_nav_menu_items($locs['primary']) as $item) {
            if (stripos((string) $item->title, 'tour') !== false || strpos((string) $item->url, '#tour') !== false) $tour_nav = true;
        }
    }
    brb_t(!$tour_nav, 'the primary menu has no tour link');

    $privacy = get_page_by_path('privacy');
    $privacy_html = $privacy ? (string) $privacy->post_content : '';
    brb_t(strpos($privacy_html, 'The tour request form asks for your name') !== false, 'privacy lists what a tour request collects');
    brb_t(strpos($privacy_html, 'tour request records') !== false && strpos($privacy_html, 'only when a staff member deletes it') !== false, 'privacy says tour requests stay until staff delete them');
    brb_t(strpos($privacy_html, 'tour request form does not set a cookie') !== false, 'privacy says the tour form sets no cookie');

    if ($user) {
        $_GET = ['page' => 'brb-tours'];
        ob_start();
        brb_admin_tours();
        $admin_html = ob_get_clean();
        brb_t(strpos($admin_html, 'Tour Guest') !== false && strpos($admin_html, 'Mark done') !== false, 'the admin list shows the request and can mark it done');
        brb_t(strpos($admin_html, 'Delete this request') === false && strpos($admin_html, 'brb_tour_delete') === false, 'the list does not delete a request from the row');
        $_GET = ['page' => 'brb-tours', 'id' => (int) $row->id];
        ob_start();
        brb_admin_tours();
        $detail = ob_get_clean();
        brb_t(strpos($detail, 'Delete this request') !== false && strpos($detail, 'name="staff_notes"') !== false, 'the detail page has notes and a delete button');
        $_GET = [];
    }
} finally {
    delete_transient(brb_tour_rate_key());
    if ($created) {
        $in = implode(',', array_map('intval', $created));
        $wpdb->query("DELETE FROM " . brb_tour_table() . " WHERE id IN ($in)");
    }
    $wpdb->query("DELETE FROM " . brb_tour_table() . " WHERE email IN ('tour-guest@example.com','optional-tour@example.com','again-tour@example.com','later-tour@example.com','ada@example.com') OR name='Phone Guest'");
    if ($snapshot !== false && $snapshot !== null) update_option('brb_settings', $snapshot);
    else delete_option('brb_settings');
}

echo "\n{$GLOBALS['brb_ok']} passed, {$GLOBALS['brb_fail']} failed\n";
exit($GLOBALS['brb_fail'] ? 1 : 0);
