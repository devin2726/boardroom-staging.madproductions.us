<?php
/**
 * Staff follow-up log for bookings and inquiries.
 *
 * A record of who contacted the guest, how, and when. It is separate from the
 * booking status: saving an entry never changes status, payments, holds,
 * availability, or the Edit history, and it never emails anyone.
 *
 * Entries live in the bookings table column follow_up_log as a JSON list,
 * oldest first. Saving always appends; nothing is overwritten.
 */
if (!defined('ABSPATH')) exit;

function brb_followup_statuses() {
    return [
        'not_contacted' => 'Not contacted',
        'contacted' => 'Contacted',
        'tour_offered' => 'Tour offered',
        'tour_scheduled' => 'Tour scheduled',
        'waiting_guest' => 'Waiting on guest',
        'follow_up' => 'Follow up needed',
        'closed' => 'Closed',
    ];
}
function brb_followup_status_label($key) {
    $all = brb_followup_statuses();
    return $all[(string) $key] ?? '';
}
function brb_followup_ensure_column() {
    brb_ensure_column(brb_table(), 'follow_up_log', 'longtext NULL');
}
function brb_followup_note_max() {
    return 200;
}
function brb_followup_by_max() {
    return 60;
}
function brb_followup_clean_text($text, $max) {
    $text = sanitize_text_field((string) $text);
    if (function_exists('mb_substr')) return trim(mb_substr($text, 0, $max));
    return trim(substr($text, 0, $max));
}
function brb_followup_current_name() {
    if (!function_exists('wp_get_current_user')) return '';
    $user = wp_get_current_user();
    if (!$user || empty($user->ID)) return '';
    $name = trim((string) $user->display_name);
    if ($name === '') $name = (string) $user->user_login;
    return brb_followup_clean_text($name, brb_followup_by_max());
}
function brb_followup_today() {
    return brb_now()->format('Y-m-d');
}
function brb_followup_valid_date($ymd) {
    $ymd = (string) $ymd;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}
// Entries as stored (oldest first). Bad JSON or bad rows are skipped.
function brb_followup_raw_entries($b) {
    $raw = is_object($b) ? (string) ($b->follow_up_log ?? '') : (string) $b;
    if (trim($raw) === '') return [];
    $list = json_decode($raw, true);
    if (!is_array($list)) return [];
    $out = [];
    foreach ($list as $row) {
        if (!is_array($row)) continue;
        $status = (string) ($row['status'] ?? '');
        if (brb_followup_status_label($status) === '') continue;
        $out[] = [
            'status' => $status,
            'date' => brb_followup_valid_date($row['date'] ?? '') ? (string) $row['date'] : '',
            'by' => (string) ($row['by'] ?? ''),
            'note' => (string) ($row['note'] ?? ''),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'saved' => (string) ($row['saved'] ?? ''),
        ];
    }
    return $out;
}
// Newest first, for display.
function brb_followup_entries($b) {
    return array_reverse(brb_followup_raw_entries($b));
}
function brb_followup_latest($b) {
    $all = brb_followup_raw_entries($b);
    return $all ? $all[count($all) - 1] : null;
}
function brb_followup_short_date($ymd) {
    if (!brb_followup_valid_date($ymd)) return '';
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, brb_tz());
    if (!$d) return '';
    return $d->format('Y') === brb_now()->format('Y') ? $d->format('M j') : $d->format('M j, Y');
}
// Plain text, e.g. "Contacted · Nov 13 · Max". Escape when printing.
function brb_followup_summary($entry) {
    if (!is_array($entry)) return '';
    $parts = [brb_followup_status_label($entry['status'] ?? '')];
    $date = brb_followup_short_date($entry['date'] ?? '');
    if ($date !== '') $parts[] = $date;
    if (($entry['by'] ?? '') !== '') $parts[] = (string) $entry['by'];
    return implode(' · ', array_filter($parts, 'strlen'));
}
/**
 * Append one follow-up entry. Returns the saved entry or WP_Error.
 * Touches only follow_up_log.
 */
function brb_followup_add($booking_id, $input) {
    global $wpdb;
    $booking_id = (int) $booking_id;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('brb_followup_missing', 'Booking not found.');
    $input = is_array($input) ? $input : [];
    $status = sanitize_key((string) ($input['followup_status'] ?? ''));
    if (brb_followup_status_label($status) === '') return new WP_Error('brb_followup_status', 'Choose a follow-up status.');
    $date = trim((string) ($input['followup_date'] ?? ''));
    if ($date === '') $date = brb_followup_today();
    if (!brb_followup_valid_date($date)) return new WP_Error('brb_followup_date', 'Enter the follow-up date as YYYY-MM-DD.');
    $by = brb_followup_clean_text($input['followup_by'] ?? '', brb_followup_by_max());
    if ($by === '') $by = brb_followup_current_name();
    if ($by === '') $by = 'Staff';
    $note = brb_followup_clean_text($input['followup_note'] ?? '', brb_followup_note_max());
    $entry = [
        'status' => $status,
        'date' => $date,
        'by' => $by,
        'note' => $note,
        'user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
        'saved' => brb_now()->format('c'),
    ];
    brb_followup_ensure_column();
    // Re-read right before writing so a save never drops an entry another
    // staff member just added.
    $fresh = brb_booking($booking_id);
    $list = brb_followup_raw_entries($fresh);
    $list[] = $entry;
    $ok = $wpdb->update(brb_table(), ['follow_up_log' => wp_json_encode($list)], ['id' => $booking_id]);
    if ($ok === false) return new WP_Error('brb_followup_db', 'The follow-up could not be saved.');
    return $entry;
}

function brb_followup_box_html($b) {
    $statuses = brb_followup_statuses();
    $latest = brb_followup_latest($b);
    $pick = $latest ? $latest['status'] : 'not_contacted';
    $id = (int) $b->id;
    $html = '<h2 id="brb-followup">Follow-up</h2>';
    $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="brb-followup-form">';
    $html .= '<input type="hidden" name="action" value="brb_followup"><input type="hidden" name="booking" value="' . $id . '">';
    $html .= wp_nonce_field('brb_followup_' . $id, '_wpnonce', true, false);
    $html .= '<table class="widefat brb-followup" style="max-width:760px"><tbody>';
    $html .= '<tr><th style="width:280px"><label for="brb-followup-status">Follow-up status</label></th><td><select id="brb-followup-status" name="followup_status">';
    foreach ($statuses as $key => $label) {
        $html .= '<option value="' . esc_attr($key) . '"' . selected($pick, $key, false) . '>' . esc_html($label) . '</option>';
    }
    $html .= '</select></td></tr>';
    $html .= '<tr><th><label for="brb-followup-date">Date</label></th><td><input type="date" id="brb-followup-date" name="followup_date" value="' . esc_attr(brb_followup_today()) . '" required></td></tr>';
    $html .= '<tr><th><label for="brb-followup-by">By</label></th><td><input type="text" id="brb-followup-by" name="followup_by" maxlength="' . (int) brb_followup_by_max() . '" value="' . esc_attr(brb_followup_current_name()) . '"></td></tr>';
    $html .= '<tr><th><label for="brb-followup-note">Note (optional)</label></th><td><input type="text" id="brb-followup-note" name="followup_note" class="regular-text" maxlength="' . (int) brb_followup_note_max() . '" placeholder="e.g. Emailed asking about a tour"></td></tr>';
    $html .= '<tr><th></th><td><button type="submit" class="button button-small">Save follow-up</button> <span class="description">A record only. Saving does not email the guest or change the booking status.</span></td></tr>';
    $html .= '</tbody></table></form>';
    $entries = brb_followup_entries($b);
    $html .= '<h3 class="brb-followup-history-title">Follow-up history</h3>';
    if (!$entries) {
        $html .= '<p class="brb-followup-empty">No follow-up yet.</p>';
    } else {
        $html .= '<ol class="brb-audit brb-followup-history">';
        foreach ($entries as $e) {
            $line = brb_followup_summary($e);
            if ($e['note'] !== '') $line .= ' — ' . $e['note'];
            $html .= '<li>' . esc_html($line) . '</li>';
        }
        $html .= '</ol>';
    }
    return $html;
}
function brb_followup_list_cell($b) {
    $summary = brb_followup_summary(brb_followup_latest($b));
    if ($summary === '') return '<span class="brb-followup-none">' . esc_html(brb_followup_status_label('not_contacted')) . '</span>';
    return '<span class="brb-followup-latest">' . esc_html($summary) . '</span>';
}
// Sort key for the list: latest follow-up date, then save time. Never
// followed up sorts as empty.
function brb_followup_sort_key($b) {
    $e = brb_followup_latest($b);
    if (!$e) return '';
    return $e['date'] . ' ' . $e['saved'];
}

function brb_followup_handle_post() {
    $id = (int) ($_POST['booking'] ?? 0);
    if (!current_user_can('manage_options')) wp_die('Sorry, you are not allowed to do that.');
    check_admin_referer('brb_followup_' . $id);
    $res = brb_followup_add($id, wp_unslash($_POST));
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    $ref = brb_ref(brb_booking($id));
    brb_admin_back($id, 'Follow-up saved for ' . $ref . ': ' . brb_followup_summary($res) . '. No email was sent.');
}
add_action('admin_post_brb_followup', 'brb_followup_handle_post');
