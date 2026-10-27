<?php
if (!defined('ABSPATH')) exit;

function brb_tour_table() { global $wpdb; return $wpdb->prefix . 'br_tours'; }

function brb_tour_statuses() {
    return [
        'new' => 'New',
        'contacted' => 'Contacted',
        'scheduled' => 'Scheduled',
        'done' => 'Done',
        'cancelled' => 'Cancelled',
    ];
}
function brb_tour_windows() {
    return [
        '' => 'No preference',
        'morning' => 'Morning',
        'afternoon' => 'Afternoon',
        'evening' => 'Evening',
    ];
}
function brb_tour_status_label($status) {
    $all = brb_tour_statuses();
    return $all[$status] ?? $status;
}
function brb_tour_window_label($window) {
    $all = brb_tour_windows();
    return $all[$window] ?? ($window !== '' ? $window : 'No preference');
}

function brb_install_tour_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $t = brb_tour_table();
    dbDelta("CREATE TABLE $t (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      name varchar(190) NOT NULL DEFAULT '',
      email varchar(190) NOT NULL DEFAULT '',
      phone varchar(50) NOT NULL DEFAULT '',
      preferred_date date DEFAULT NULL,
      time_window varchar(20) NOT NULL DEFAULT '',
      party_size int(11) DEFAULT NULL,
      notes text,
      staff_notes text,
      status varchar(20) NOT NULL DEFAULT 'new',
      created datetime NOT NULL,
      PRIMARY KEY  (id),
      KEY status (status),
      KEY created (created)
    ) $c;");
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
}

function brb_tour($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . brb_tour_table() . ' WHERE id=%d', (int) $id));
}

function brb_tour_rate_key() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'cli';
    return 'brb_tr_' . substr(md5($ip . '|' . wp_salt('auth')), 0, 20);
}
function brb_tour_rate_block() {
    $data = get_transient(brb_tour_rate_key());
    if (!is_array($data)) return '';
    $now = time();
    if (!empty($data['last']) && ($now - (int) $data['last']) < 20) return 'soon';
    if ((int) ($data['n'] ?? 0) >= 5) return 'hour';
    return '';
}
function brb_tour_rate_bump() {
    $key = brb_tour_rate_key();
    $data = get_transient($key);
    if (!is_array($data)) $data = ['n' => 0, 'last' => 0];
    $data['n'] = (int) $data['n'] + 1;
    $data['last'] = time();
    set_transient($key, $data, HOUR_IN_SECONDS);
}

function brb_tour_recipients() {
    $out = [];
    foreach (preg_split('/[,;\s]+/', (string) brb_opt('admin_email')) as $part) {
        $part = sanitize_email(trim($part));
        if (is_email($part)) $out[strtolower($part)] = $part;
    }
    $tour = sanitize_email((string) brb_opt('tour_email'));
    if (!is_email($tour)) $tour = 'boardroomqc@gmail.com';
    $out[strtolower($tour)] = $tour;
    return array_values($out);
}

function brb_tour_submit($input) {
    if (!is_array($input)) $input = [];
    if (trim((string) ($input['company'] ?? '')) !== '') return new WP_Error('spam', 'Ignored.');
    $block = brb_tour_rate_block();
    if ($block !== '') return new WP_Error($block, $block === 'soon' ? 'Please wait a moment and try the form again.' : 'Please wait a little while and try again.');

    $name = sanitize_text_field((string) ($input['name'] ?? ''));
    $email = sanitize_email((string) ($input['email'] ?? ''));
    $phone = sanitize_text_field((string) ($input['phone'] ?? ''));
    $date = trim((string) ($input['preferred_date'] ?? ''));
    $window = sanitize_key((string) ($input['time_window'] ?? ''));
    $people_raw = trim((string) ($input['party_size'] ?? ''));
    $notes = sanitize_textarea_field((string) ($input['notes'] ?? ''));

    if ($name === '' || strlen($name) > 190) return new WP_Error('missing', 'Please enter your name, and an email or a phone number.');
    $digits = preg_replace('/\D+/', '', $phone);
    if ($email === '' && $phone === '') return new WP_Error('missing', 'Please enter your name, and an email or a phone number.');
    if ($email !== '' && !is_email($email)) return new WP_Error('email', 'Please enter a valid email, or leave it blank and give a phone number.');
    if ($phone !== '' && strlen($phone) > 50) return new WP_Error('phone', 'Please enter a shorter phone number.');
    if ($email === '' && strlen($digits) < 7) return new WP_Error('phone', 'Please enter a phone number we can call, or an email.');
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return new WP_Error('date', 'Please choose a real date, or leave the date blank.');
    if ($date !== '') {
        $parts = array_map('intval', explode('-', $date));
        if (!checkdate($parts[1], $parts[2], $parts[0])) return new WP_Error('date', 'Please choose a real date, or leave the date blank.');
    }
    if (!array_key_exists($window, brb_tour_windows())) return new WP_Error('window', 'Please choose morning, afternoon, evening, or no preference.');
    $people = null;
    if ($people_raw !== '') {
        if (!preg_match('/^\d+$/', $people_raw)) return new WP_Error('people', 'Please enter a whole number of people, or leave it blank.');
        $people = (int) $people_raw;
        if ($people < 1 || $people > 500) return new WP_Error('people', 'Please enter a whole number of people, or leave it blank.');
    }
    if (strlen($notes) > 4000) $notes = substr($notes, 0, 4000);

    global $wpdb;
    $t = brb_tour_table();
    $date_sql = $date !== '' ? '%s' : 'NULL';
    $size_sql = $people === null ? 'NULL' : '%d';
    $args = [$name, $email, $phone];
    if ($date !== '') $args[] = $date;
    $args[] = $window;
    if ($people !== null) $args[] = $people;
    $args[] = $notes;
    $args[] = current_time('mysql', true);
    $sql = "INSERT INTO $t (name, email, phone, preferred_date, time_window, party_size, notes, staff_notes, status, created) VALUES (%s, %s, %s, $date_sql, %s, $size_sql, %s, '', 'new', %s)";
    $ok = $wpdb->query($wpdb->prepare($sql, $args));
    if (!$ok) return new WP_Error('store', 'We could not save that request. Please try again.');
    $row = brb_tour((int) $wpdb->insert_id);
    brb_tour_rate_bump();
    if ($row) brb_send_tour_notice($row);
    return $row;
}

function brb_send_tour_notice($row) {
    $when = $row->preferred_date ? $row->preferred_date : 'not given';
    $people = ($row->party_size !== null && $row->party_size !== '') ? (string) (int) $row->party_size : 'not given';
    $body = "A tour was requested. No time is confirmed.\n\n"
        . "Name: {$row->name}\n"
        . "Email: " . ($row->email !== '' ? $row->email : 'not given') . "\n"
        . "Phone: " . ($row->phone !== '' ? $row->phone : 'not given') . "\n"
        . "Preferred date: {$when}\n"
        . "Time of day: " . brb_tour_window_label((string) $row->time_window) . "\n"
        . "People: {$people}\n"
        . "Event type / notes: {$row->notes}\n\n"
        . admin_url('admin.php?page=brb-tours&id=' . (int) $row->id) . "\n";
    $subject = 'Tour request from ' . $row->name;
    $reply = is_email($row->email) ? $row->email : '';
    $sent = false;
    foreach (brb_tour_recipients() as $to) {
        if (brb_mail($to, $subject, $body, $reply)) $sent = true;
    }
    return $sent;
}

function brb_tour_update($id, $status, $staff_notes) {
    $id = (int) $id;
    $row = brb_tour($id);
    if (!$row) return new WP_Error('missing', 'That tour request was not found.');
    if (!isset(brb_tour_statuses()[$status])) return new WP_Error('status', 'Unknown status.');
    global $wpdb;
    $wpdb->update(brb_tour_table(), [
        'status' => $status,
        'staff_notes' => sanitize_textarea_field((string) $staff_notes),
    ], ['id' => $id], ['%s', '%s'], ['%d']);
    return brb_tour($id);
}
function brb_tour_delete($id) {
    global $wpdb;
    $id = (int) $id;
    if (!brb_tour($id)) return false;
    return (bool) $wpdb->delete(brb_tour_table(), ['id' => $id], ['%d']);
}

function brb_tour_message($code) {
    $messages = [
        'ok' => "Thanks, we'll contact you to confirm a time.",
        'missing' => 'Please enter your name, and an email or a phone number.',
        'email' => 'Please enter a valid email, or leave it blank and give a phone number.',
        'phone' => 'Please enter a phone number we can call, or an email.',
        'date' => 'Please choose a real date, or leave the date blank.',
        'window' => 'Please choose morning, afternoon, evening, or no preference.',
        'people' => 'Please enter a whole number of people, or leave it blank.',
        'soon' => 'Please wait a moment and try the form again.',
        'hour' => 'Please wait a little while and try again.',
    ];
    return $messages[$code] ?? '';
}

function brb_tour_form() {
    $code = isset($_GET['tour']) ? sanitize_key(wp_unslash($_GET['tour'])) : '';
    $message = brb_tour_message($code);
    ob_start();
    echo '<section id="tour" class="tour-block">';
    echo '<h2>Schedule a tour</h2>';
    echo '<p>Tell us when you would like to see The Boardroom. Sending this form does not hold a date, and it does not confirm a time. We will contact you.</p>';
    if ($message !== '') echo '<div class="notice">' . esc_html($message) . '</div>';
    echo '<form class="form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="brb_tour">';
    wp_nonce_field('brb_tour', '_brb_tour');
    echo '<label class="hp">Company<input name="company" tabindex="-1" autocomplete="off"></label>';
    echo '<label>Name*<input name="name" required maxlength="190"></label>';
    echo '<div class="split"><label>Email<input type="email" name="email" maxlength="190"></label>';
    echo '<label>Phone<input name="phone" maxlength="50"></label></div>';
    echo '<p class="tour-hint">Email or phone: give at least one.</p>';
    echo '<div class="split"><label>Preferred date<input type="date" name="preferred_date"></label>';
    echo '<label>Preferred time<select name="time_window">';
    foreach (brb_tour_windows() as $value => $label) {
        echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
    }
    echo '</select></label></div>';
    echo '<label>Number of people<input type="number" name="party_size" min="1" max="500"></label>';
    echo '<label>Event type / notes<textarea name="notes" rows="5" maxlength="4000"></textarea></label>';
    echo '<button class="btn" type="submit">Request a tour</button>';
    echo '</form></section>';
    return ob_get_clean();
}
add_shortcode('boardroom_tour', 'brb_tour_form');

function brb_tour_redirect($code) {
    $url = home_url('/contact/');
    if ($code !== '') $url = add_query_arg('tour', $code, $url);
    wp_safe_redirect($url . '#tour');
    exit;
}
function brb_tour_post() {
    $nonce = isset($_POST['_brb_tour']) ? sanitize_text_field(wp_unslash($_POST['_brb_tour'])) : '';
    if (!wp_verify_nonce($nonce, 'brb_tour')) brb_tour_redirect('');
    $result = brb_tour_submit(wp_unslash($_POST));
    if (is_wp_error($result)) {
        $code = $result->get_error_code();
        if ($code === 'spam') brb_tour_redirect('');
        if (!brb_tour_message($code)) $code = 'missing';
        brb_tour_redirect($code);
    }
    brb_tour_redirect('ok');
}
add_action('admin_post_nopriv_brb_tour', 'brb_tour_post');
add_action('admin_post_brb_tour', 'brb_tour_post');

function brb_ensure_tour_on_contact() {
    $page = get_page_by_path('contact');
    if (!$page) return;
    if (strpos((string) $page->post_content, '[boardroom_tour]') !== false) return;
    global $wpdb;
    $next = rtrim((string) $page->post_content) . "\n[boardroom_tour]";
    $wpdb->update($wpdb->posts, ['post_content' => $next], ['ID' => (int) $page->ID]);
    clean_post_cache((int) $page->ID);
}

function brb_tour_admin_back($id, $note) {
    $url = admin_url('admin.php?page=brb-tours');
    if ($id) $url = add_query_arg('id', (int) $id, $url);
    if ($note) $url = add_query_arg('brb_note', $note, $url);
    wp_safe_redirect($url);
    exit;
}
function brb_admin_tours() {
    if (!current_user_can('manage_options')) return;
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    echo '<div class="wrap"><h1>Tour requests</h1>';
    echo '<p>A request stays in this list until you open it and delete it.</p>';
    if (!empty($_GET['brb_note'])) echo '<div class="notice notice-info"><p>' . esc_html(wp_unslash($_GET['brb_note'])) . '</p></div>';
    if ($id) brb_admin_tour_detail($id);
    else brb_admin_tour_list();
    echo '</div>';
}
function brb_admin_tour_list() {
    global $wpdb;
    $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
    if ($status !== '' && !isset(brb_tour_statuses()[$status])) $status = '';
    $t = brb_tour_table();
    if ($status !== '') {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE status=%s ORDER BY created DESC, id DESC", $status));
    } else {
        $rows = $wpdb->get_results("SELECT * FROM $t ORDER BY created DESC, id DESC");
    }
    $base = admin_url('admin.php?page=brb-tours');
    echo '<p>';
    echo '<a href="' . esc_url($base) . '">All</a>';
    foreach (brb_tour_statuses() as $key => $label) {
        echo ' | <a href="' . esc_url(add_query_arg('status', $key, $base)) . '">' . esc_html($label) . '</a>';
    }
    echo '</p>';
    if (!$rows) {
        echo '<p>No tour requests yet.</p>';
        return;
    }
    echo '<table class="widefat striped"><thead><tr><th>Received</th><th>Name</th><th>Contact</th><th>Preferred</th><th>People</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $contact = trim($row->email . ($row->email && $row->phone ? ' · ' : '') . $row->phone);
        $pref = trim(($row->preferred_date ? $row->preferred_date . ' ' : '') . brb_tour_window_label((string) $row->time_window));
        echo '<tr><td>' . esc_html(brb_format_utc_mysql($row->created)) . '</td>';
        echo '<td>' . esc_html($row->name) . '</td><td>' . esc_html($contact) . '</td><td>' . esc_html($pref) . '</td>';
        echo '<td>' . ($row->party_size !== null && $row->party_size !== '' ? esc_html((string) (int) $row->party_size) : '—') . '</td>';
        echo '<td>' . esc_html(brb_tour_status_label($row->status)) . '</td><td>';
        echo '<a href="' . esc_url(add_query_arg('id', (int) $row->id, $base)) . '">Open</a>';
        if ($row->status !== 'done') {
            echo ' <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
            echo '<input type="hidden" name="action" value="brb_tour_done"><input type="hidden" name="id" value="' . (int) $row->id . '">';
            wp_nonce_field('brb_tour_done_' . (int) $row->id);
            echo '<button class="button button-small">Mark done</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}
function brb_admin_tour_detail($id) {
    $row = brb_tour($id);
    if (!$row) {
        echo '<p>That tour request was not found.</p>';
        return;
    }
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=brb-tours')) . '">All tour requests</a></p>';
    echo '<table class="widefat" style="max-width:720px"><tbody>';
    $lines = [
        'Received' => brb_format_utc_mysql($row->created),
        'Name' => $row->name,
        'Email' => $row->email !== '' ? $row->email : 'not given',
        'Phone' => $row->phone !== '' ? $row->phone : 'not given',
        'Preferred date' => $row->preferred_date ? $row->preferred_date : 'not given',
        'Time of day' => brb_tour_window_label((string) $row->time_window),
        'People' => ($row->party_size !== null && $row->party_size !== '') ? (string) (int) $row->party_size : 'not given',
        'Event type / notes' => $row->notes !== '' ? $row->notes : 'none',
    ];
    foreach ($lines as $label => $value) {
        echo '<tr><th style="width:180px">' . esc_html($label) . '</th><td>' . nl2br(esc_html($value)) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:720px;margin-top:16px">';
    echo '<input type="hidden" name="action" value="brb_tour_save"><input type="hidden" name="id" value="' . (int) $row->id . '">';
    wp_nonce_field('brb_tour_save_' . (int) $row->id);
    echo '<p><label>Status<br><select name="status">';
    foreach (brb_tour_statuses() as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"' . selected($row->status, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>Notes<br><textarea name="staff_notes" rows="5" class="large-text">' . esc_textarea((string) $row->staff_notes) . '</textarea></label></p>';
    echo '<p><button class="button button-primary">Save</button></p></form>';
    if ($row->status !== 'done') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px">';
        echo '<input type="hidden" name="action" value="brb_tour_done"><input type="hidden" name="id" value="' . (int) $row->id . '">';
        wp_nonce_field('brb_tour_done_' . (int) $row->id);
        echo '<button class="button">Mark done</button></form>';
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px" onsubmit="return confirm(\'Delete this tour request? This cannot be undone.\')">';
    echo '<input type="hidden" name="action" value="brb_tour_delete"><input type="hidden" name="id" value="' . (int) $row->id . '">';
    wp_nonce_field('brb_tour_delete_' . (int) $row->id);
    echo '<button class="button">Delete this request</button></form>';
}

add_action('admin_post_brb_tour_save', function () {
    if (!current_user_can('manage_options')) wp_die('Sorry, you are not allowed to do that.');
    $id = (int) ($_POST['id'] ?? 0);
    check_admin_referer('brb_tour_save_' . $id);
    $res = brb_tour_update($id, sanitize_key(wp_unslash($_POST['status'] ?? '')), wp_unslash($_POST['staff_notes'] ?? ''));
    if (is_wp_error($res)) brb_tour_admin_back($id, $res->get_error_message());
    brb_tour_admin_back($id, 'Saved.');
});
add_action('admin_post_brb_tour_done', function () {
    if (!current_user_can('manage_options')) wp_die('Sorry, you are not allowed to do that.');
    $id = (int) ($_POST['id'] ?? 0);
    check_admin_referer('brb_tour_done_' . $id);
    $row = brb_tour($id);
    if (!$row) brb_tour_admin_back(0, 'That tour request was not found.');
    brb_tour_update($id, 'done', (string) $row->staff_notes);
    brb_tour_admin_back($id, 'Marked done.');
});
add_action('admin_post_brb_tour_delete', function () {
    if (!current_user_can('manage_options')) wp_die('Sorry, you are not allowed to do that.');
    $id = (int) ($_POST['id'] ?? 0);
    check_admin_referer('brb_tour_delete_' . $id);
    if (!brb_tour_delete($id)) brb_tour_admin_back(0, 'That tour request was not found.');
    brb_tour_admin_back(0, 'Tour request deleted.');
});
