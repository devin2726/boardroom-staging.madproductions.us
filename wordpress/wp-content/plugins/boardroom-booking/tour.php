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
function brb_inquiry_topics() {
    return [
        '' => 'Choose one',
        'general' => 'General inquiry',
        'question' => 'Question',
        'tour' => 'Tour the venue',
        'event' => 'Planning an event',
    ];
}
function brb_inquiry_date($raw) {
    $raw = trim(sanitize_text_field((string) $raw));
    if ($raw === '') return '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return false;
    $dt = DateTime::createFromFormat('!Y-m-d', $raw);
    $errors = DateTime::getLastErrors();
    if (!$dt || ($errors && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) return false;
    if ($dt->format('Y-m-d') !== $raw) return false;
    return $raw;
}
function brb_inquiry_date_label($value) {
    $value = trim((string) $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $dt = DateTime::createFromFormat('!Y-m-d', $value);
        if ($dt && $dt->format('Y-m-d') === $value) return $dt->format('F j, Y');
    }
    return $value;
}
function brb_tour_status_label($status) {
    $all = brb_tour_statuses();
    return $all[$status] ?? $status;
}
function brb_tour_window_label($window) {
    $all = brb_tour_windows();
    return $all[$window] ?? ($window !== '' ? $window : 'No preference');
}
function brb_inquiry_topic_label($topic) {
    $all = brb_inquiry_topics();
    return $all[$topic] ?? ($topic !== '' ? $topic : '');
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
      topic varchar(40) NOT NULL DEFAULT '',
      preferred varchar(190) NOT NULL DEFAULT '',
      source varchar(40) NOT NULL DEFAULT '',
      guest_ack datetime DEFAULT NULL,
      PRIMARY KEY  (id),
      KEY status (status),
      KEY created (created)
    ) $c;");
    $cols = $wpdb->get_col("SHOW COLUMNS FROM $t", 0);
    if (is_array($cols) && !in_array('topic', $cols, true)) {
        $wpdb->query("ALTER TABLE $t ADD COLUMN topic varchar(40) NOT NULL DEFAULT ''");
    }
    if (is_array($cols) && !in_array('preferred', $cols, true)) {
        $wpdb->query("ALTER TABLE $t ADD COLUMN preferred varchar(190) NOT NULL DEFAULT ''");
    }
    if (is_array($cols) && !in_array('source', $cols, true)) {
        $wpdb->query("ALTER TABLE $t ADD COLUMN source varchar(40) NOT NULL DEFAULT ''");
    }
    if (is_array($cols) && !in_array('guest_ack', $cols, true)) {
        $wpdb->query("ALTER TABLE $t ADD COLUMN guest_ack datetime DEFAULT NULL");
    }
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
    foreach (brb_admin_emails() as $email) $out[strtolower($email)] = $email;
    $parsed = brb_parse_email_list(brb_opt('tour_email'));
    $tour = $parsed['valid'][0] ?? '';
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
    $raw_email = trim((string) ($input['email'] ?? ''));
    $email = sanitize_email($raw_email);
    $topic = 'general';
    $preferred = brb_inquiry_date($input['preferred'] ?? '');
    $notes = sanitize_textarea_field((string) ($input['message'] ?? $input['notes'] ?? ''));

    if ($name === '' || strlen($name) > 190) return new WP_Error('missing', 'Please enter your name and email.');
    if ($raw_email === '') return new WP_Error('missing', 'Please enter your name and email.');
    if (!is_email($email)) return new WP_Error('email', 'Please enter a valid email.');
    if ($preferred === false) return new WP_Error('preferred', 'Please choose a preferred date.');
    if (trim($notes) === '') return new WP_Error('message', 'Please enter a message.');
    if (strlen($notes) > 4000) $notes = substr($notes, 0, 4000);

    global $wpdb;
    $t = brb_tour_table();
    $ok = $wpdb->query($wpdb->prepare(
        "INSERT INTO $t (name, email, phone, preferred_date, time_window, party_size, notes, staff_notes, status, created, topic, preferred) VALUES (%s, %s, '', NULL, '', NULL, %s, '', 'new', %s, %s, %s)",
        $name,
        $email,
        $notes,
        current_time('mysql', true),
        $topic,
        $preferred
    ));
    if (!$ok) return new WP_Error('store', 'We could not save that message. Please try again.');
    $row = brb_tour((int) $wpdb->insert_id);
    if ($row) {
        brb_report_stamp_inquiry((int) $row->id, [
            'source' => $topic === 'tour' ? 'tour request' : 'contact form',
            'event_type' => '',
            'party_size' => $input['party_size'] ?? '',
        ]);
        if ($topic === 'tour') brb_event_hit('tour_request');
    }
    brb_tour_rate_bump();
    if ($row) brb_send_tour_notice($row);
    return $row;
}

function brb_send_tour_notice($row) {
    $about = brb_inquiry_topic_label((string) ($row->topic ?? ''));
    if ($about === '' || $about === 'Choose one') $about = 'General inquiry';
    $when = brb_inquiry_date_label((string) ($row->preferred ?? ''));
    if ($when === '') $when = 'not given';
    $body = "A contact inquiry was received. No date is held.\n\n"
        . "Name: {$row->name}\n"
        . "Email: {$row->email}\n"
        . "What's this about: {$about}\n"
        . "Preferred dates: {$when}\n"
        . "Message: {$row->notes}\n\n"
        . admin_url('admin.php?page=brb-tours&id=' . (int) $row->id) . "\n";
    $subject = 'New contact inquiry';
    $reply = is_email($row->email) ? $row->email : '';
    $from = brb_smtp_from_address();
    $sent = false;
    foreach (brb_tour_recipients() as $to) {
        if (brb_mail($to, $subject, $body, $reply, $from, brb_inquiry_cc_list())) $sent = true;
    }
    brb_send_inquiry_guest_ack($row);
    return $sent;
}

function brb_inquiry_mail_text($value, $multiline = false) {
    $value = wp_strip_all_tags((string) $value);
    $value = str_replace("\0", '', $value);
    if ($multiline) {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace("/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/", '', $value);
        return trim((string) $value);
    }
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/\s+/', ' ', (string) $value);
    return trim((string) $value);
}
function brb_inquiry_first_name($name) {
    $name = brb_inquiry_mail_text($name);
    if ($name === '') return 'there';
    $parts = preg_split('/\s+/', $name);
    $first = trim((string) ($parts[0] ?? ''), " \t.,;:<>\"'\\/");
    return $first !== '' ? $first : 'there';
}
function brb_inquiry_guest_ack_body($row) {
    $first = brb_inquiry_first_name($row->name ?? '');
    $lines = [];
    $type = brb_inquiry_mail_text(brb_event_type_label($row->event_type ?? ''));
    if ($type !== '') $lines[] = 'Event type: ' . $type;
    $when = brb_inquiry_mail_text(brb_inquiry_date_label((string) ($row->preferred ?? '')));
    if ($when !== '') $lines[] = 'Preferred dates: ' . $when;
    $guests = $row->party_size ?? null;
    if ($guests !== null && $guests !== '' && (int) $guests > 0) $lines[] = 'Guests: ' . (int) $guests;
    $message = brb_inquiry_mail_text($row->notes ?? '', true);
    if ($message !== '') $lines[] = 'Message: ' . $message;
    $body = "Hi {$first},\n\n"
        . "Thanks for reaching out to The Boardroom! We got your message and will call or email you shortly.\n";
    if ($lines) $body .= "\nHere's what you sent us:\n" . implode("\n", $lines) . "\n";
    $body .= "\nWant to check open dates or book? Visit " . brb_book_url() . "\n\n"
        . "See you soon,\n"
        . "The Boardroom\n"
        . BRB_VENUE_ADDRESS . "\n";
    return $body;
}
function brb_inquiry_guest_ack_clear($id, $stamp) {
    global $wpdb;
    $wpdb->query($wpdb->prepare(
        'UPDATE ' . brb_tour_table() . ' SET guest_ack=NULL WHERE id=%d AND guest_ack=%s',
        (int) $id,
        $stamp
    ));
}
function brb_send_inquiry_guest_ack($row) {
    $id = 0;
    $stamp = '';
    try {
        $id = is_object($row) ? (int) ($row->id ?? 0) : (int) $row;
        if ($id <= 0) return false;
        if (function_exists('brb_ensure_column')) {
            brb_ensure_column(brb_tour_table(), 'guest_ack', 'datetime NULL DEFAULT NULL');
        }
        $row = brb_tour($id);
        if (!$row) return false;
        $already = trim((string) ($row->guest_ack ?? ''));
        if ($already !== '' && $already !== '0000-00-00 00:00:00') return false;
        $email = sanitize_email((string) ($row->email ?? ''));
        if ($email === '' || !is_email($email)) return false;
        global $wpdb;
        $stamp = current_time('mysql', true);
        $claimed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . brb_tour_table() . " SET guest_ack=%s WHERE id=%d AND (guest_ack IS NULL OR guest_ack='' OR guest_ack='0000-00-00 00:00:00')",
            $stamp,
            $id
        ));
        if (!$claimed) return false;
        $subject = str_replace(["\r", "\n"], '', 'We got your message, The Boardroom');
        $ok = brb_mail($email, $subject, brb_inquiry_guest_ack_body($row), brb_guest_reply_to());
        if (!$ok) {
            brb_inquiry_guest_ack_clear($id, $stamp);
            return false;
        }
        return true;
    } catch (Throwable $e) {
        if ($id > 0 && $stamp !== '') brb_inquiry_guest_ack_clear($id, $stamp);
        error_log('Boardroom inquiry confirmation could not be sent for inquiry ' . (int) $id);
        return false;
    }
}

function brb_tour_update($id, $status, $staff_notes) {
    $id = (int) $id;
    $row = brb_tour($id);
    if (!$row) return new WP_Error('missing', 'That inquiry was not found.');
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
        'ok' => "Thanks, we'll reply by email.",
        'missing' => 'Please enter your name and email.',
        'email' => 'Please enter a valid email.',
        'message' => 'Please enter a message.',
        'topic' => 'Please choose a topic from the list, or leave it blank.',
        'preferred' => 'Please choose a preferred date.',
        'soon' => 'Please wait a moment and try the form again.',
        'hour' => 'Please wait a little while and try again.',
    ];
    return $messages[$code] ?? '';
}

function brb_tour_form() {
    $code = '';
    if (isset($_GET['inquiry'])) $code = sanitize_key(wp_unslash($_GET['inquiry']));
    elseif (isset($_GET['tour'])) $code = sanitize_key(wp_unslash($_GET['tour']));
    $message = brb_tour_message($code);
    ob_start();
    echo '<section class="inquiry-block">';
    if ($message !== '') echo '<div class="notice">' . esc_html($message) . '</div>';
    echo '<form class="form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="brb_tour">';
    wp_nonce_field('brb_tour', '_brb_tour');
    echo '<label class="hp">Company<input name="company" tabindex="-1" autocomplete="off"></label>';
    echo '<label>Name*<input name="name" required maxlength="190" autocomplete="name"></label>';
    echo '<label>Email*<input type="email" name="email" required maxlength="190" autocomplete="email"></label>';
    echo '<label>Preferred Dates<input type="date" name="preferred"></label>';
    echo '<label>Guests<input type="number" name="party_size" min="1" max="200"></label>';
    echo '<label>Message*<textarea name="message" rows="5" maxlength="4000" required></textarea></label>';
    echo '<button class="btn" type="submit">Send</button>';
    echo '</form></section>';
    return ob_get_clean();
}
add_shortcode('boardroom_tour', 'brb_tour_form');

function brb_tour_redirect($code) {
    $url = home_url('/contact/');
    if ($code !== '') $url = add_query_arg('inquiry', $code, $url);
    wp_safe_redirect($url);
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
    $c = (string) $page->post_content;
    $next = str_replace(['[boardroom_tour]', '[boardroom_contact]'], '', $c);
    if ($next === $c) return;
    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_content' => $next], ['ID' => (int) $page->ID]);
    clean_post_cache((int) $page->ID);
}

function brb_inquiry_preferred_label($row) {
    $preferred = trim((string) ($row->preferred ?? ''));
    if ($preferred !== '') return brb_inquiry_date_label($preferred);
    $parts = [];
    if (!empty($row->preferred_date)) $parts[] = $row->preferred_date;
    if ((string) ($row->time_window ?? '') !== '') $parts[] = brb_tour_window_label((string) $row->time_window);
    return trim(implode(' ', $parts));
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
    echo '<div class="wrap"><h1>Inquiries</h1>';
    echo '<p>An inquiry stays in this list until you open it and delete it.</p>';
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
    echo '<a href="' . esc_url($base) . '">All inquiries</a>';
    foreach (brb_tour_statuses() as $key => $label) {
        echo ' | <a href="' . esc_url(add_query_arg('status', $key, $base)) . '">' . esc_html($label) . '</a>';
    }
    echo '</p>';
    if (!$rows) {
        echo '<p>No inquiries yet.</p>';
        return;
    }
    echo '<table class="widefat striped"><thead><tr><th>Received</th><th>Name</th><th>Email</th><th>About</th><th>Preferred</th><th>Source</th><th>Status</th><th></th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $email = $row->email !== '' ? $row->email : '';
        if ($row->phone !== '') $email = trim($email . ($email !== '' ? ' · ' : '') . $row->phone);
        $about = brb_inquiry_topic_label((string) ($row->topic ?? ''));
        if ($about === '' || $about === 'Choose one') $about = '—';
        $pref = brb_inquiry_preferred_label($row);
        if ($pref === '') $pref = '—';
        echo '<tr><td>' . esc_html(brb_format_utc_mysql($row->created)) . '</td>';
        echo '<td>' . esc_html($row->name) . '</td><td>' . esc_html($email) . '</td><td>' . esc_html($about) . '</td><td>' . esc_html($pref) . '</td>';
        $source = trim((string) ($row->source ?? ''));
        echo '<td>' . esc_html($source !== '' ? $source : '—') . '</td>';
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
        echo '<p>That inquiry was not found.</p>';
        return;
    }
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=brb-tours')) . '">All inquiries</a></p>';
    echo '<table class="widefat" style="max-width:720px"><tbody>';
    $lines = [
        'Received' => brb_format_utc_mysql($row->created),
        'Name' => $row->name,
        'Email' => $row->email !== '' ? $row->email : 'not given',
    ];
    $about = brb_inquiry_topic_label((string) ($row->topic ?? ''));
    if ($about !== '' && $about !== 'Choose one') $lines["What's this about"] = $about;
    $source = trim((string) ($row->source ?? ''));
    if ($source !== '') $lines['Source'] = $source;
    if (trim((string) ($row->preferred ?? '')) !== '') $lines['Preferred dates'] = brb_inquiry_date_label($row->preferred);
    if ($row->phone !== '') $lines['Phone'] = $row->phone;
    if (!empty($row->preferred_date)) $lines['Preferred date'] = $row->preferred_date;
    if ((string) $row->time_window !== '') $lines['Time of day'] = brb_tour_window_label((string) $row->time_window);
    if ($row->party_size !== null && $row->party_size !== '') $lines['People'] = (string) (int) $row->party_size;
    $lines['Message'] = $row->notes !== '' ? $row->notes : 'none';
    foreach ($lines as $label => $value) {
        echo '<tr><th style="width:180px">' . esc_html($label) . '</th><td>';
        if ($label === 'Message' && preg_match('/^(Guest|Assistant):/m', $value)) {
            echo '<ol class="brb-chat-transcript">';
            foreach (preg_split("/\n/", $value) as $line) {
                if (trim($line) === '') continue;
                echo '<li>' . esc_html($line) . '</li>';
            }
            echo '</ol>';
        } else {
            echo nl2br(esc_html($value));
        }
        echo '</td></tr>';
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
    echo '<p><label>Lead status<br><select name="lead_status">';
    $lead_status = (string) ($row->lead_status ?? 'new');
    foreach (brb_lead_statuses() as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"' . selected($lead_status, $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>Lost reason<br><input name="lost_reason" class="regular-text" value="' . esc_attr((string) ($row->lost_reason ?? '')) . '"></label></p>';
    echo '<p><label>Notes<br><textarea name="staff_notes" rows="5" class="large-text">' . esc_textarea((string) $row->staff_notes) . '</textarea></label></p>';
    echo '<p><button class="button button-primary">Save</button></p></form>';
    if ($row->status !== 'done') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px">';
        echo '<input type="hidden" name="action" value="brb_tour_done"><input type="hidden" name="id" value="' . (int) $row->id . '">';
        wp_nonce_field('brb_tour_done_' . (int) $row->id);
        echo '<button class="button">Mark done</button></form>';
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px" onsubmit="return confirm(\'Delete this inquiry? This cannot be undone.\')">';
    echo '<input type="hidden" name="action" value="brb_tour_delete"><input type="hidden" name="id" value="' . (int) $row->id . '">';
    wp_nonce_field('brb_tour_delete_' . (int) $row->id);
    echo '<button class="button">Delete this inquiry</button></form>';
}

add_action('admin_post_brb_tour_save', function () {
    global $wpdb;
    if (!current_user_can('manage_options')) wp_die('Sorry, you are not allowed to do that.');
    $id = (int) ($_POST['id'] ?? 0);
    check_admin_referer('brb_tour_save_' . $id);
    $res = brb_tour_update($id, sanitize_key(wp_unslash($_POST['status'] ?? '')), wp_unslash($_POST['staff_notes'] ?? ''));
    if (is_wp_error($res)) brb_tour_admin_back($id, $res->get_error_message());
    $lead_status = sanitize_key(wp_unslash($_POST['lead_status'] ?? 'new'));
    if (!isset(brb_lead_statuses()[$lead_status])) $lead_status = 'new';
    $wpdb->update(brb_tour_table(), [
        'lead_status' => $lead_status,
        'lost_reason' => substr(sanitize_text_field(wp_unslash($_POST['lost_reason'] ?? '')), 0, 190),
    ], ['id' => $id]);
    brb_tour_admin_back($id, 'Saved.');
});
add_action('admin_post_brb_tour_done', function () {
    if (!current_user_can('manage_options')) wp_die('Sorry, you are not allowed to do that.');
    $id = (int) ($_POST['id'] ?? 0);
    check_admin_referer('brb_tour_done_' . $id);
    $row = brb_tour($id);
    if (!$row) brb_tour_admin_back(0, 'That inquiry was not found.');
    brb_tour_update($id, 'done', (string) $row->staff_notes);
    brb_tour_admin_back($id, 'Marked done.');
});
add_action('admin_post_brb_tour_delete', function () {
    if (!current_user_can('manage_options')) wp_die('Sorry, you are not allowed to do that.');
    $id = (int) ($_POST['id'] ?? 0);
    check_admin_referer('brb_tour_delete_' . $id);
    if (!brb_tour_delete($id)) brb_tour_admin_back(0, 'That inquiry was not found.');
    brb_tour_admin_back(0, 'Inquiry deleted.');
});
