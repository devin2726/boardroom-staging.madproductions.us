<?php
if (!defined('ABSPATH')) exit;
add_action('admin_menu', function () {
    add_menu_page('Bookings', 'Bookings', 'manage_options', 'brb', 'brb_admin_list', 'dashicons-calendar-alt', 25);
    add_submenu_page('brb', 'Bookings', 'All Bookings', 'manage_options', 'brb', 'brb_admin_list');
    add_submenu_page('brb', 'Calendar', 'Calendar', 'manage_options', 'brb-cal', 'brb_admin_cal');
    add_submenu_page('brb', 'Booking Settings', 'Settings', 'manage_options', 'brb-settings', 'brb_admin_settings');
});
add_action('admin_enqueue_scripts', function ($h) { if (strpos($h, 'brb') !== false) wp_enqueue_style('brb-admin', BRB_URL . 'assets/brb.css', [], BRB_VER); });
add_action('admin_notices', function () {
    if (current_user_can('manage_options') && !brb_opt('stripe_sk')) echo '<div class="notice notice-warning"><p><strong>Boardroom Booking:</strong> Stripe keys not set — bookings are saved as pending requests without online payment. <a href="' . esc_url(admin_url('admin.php?page=brb-settings')) . '">Add test keys</a>.</p></div>';
});
add_action('admin_post_brb_status', function () {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'brb_status')) wp_die('No');
    global $wpdb; $id = (int)$_GET['id']; $st = sanitize_key($_GET['st']);
    if ($st === 'confirmed') brb_confirm($wpdb->get_row($wpdb->prepare("SELECT * FROM " . brb_table() . " WHERE id=%d", $id)));
    elseif ($st === 'cancelled') $wpdb->update(brb_table(), ['status' => 'cancelled'], ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=brb')); exit;
});
function brb_admin_list() {
    global $wpdb;
    $f = sanitize_key($_GET['status'] ?? '');
    $rows = $wpdb->get_results($f ? $wpdb->prepare("SELECT * FROM " . brb_table() . " WHERE status=%s ORDER BY start_dt DESC", $f) : "SELECT * FROM " . brb_table() . " ORDER BY start_dt DESC LIMIT 500");
    echo '<div class="wrap"><h1>Bookings</h1><p>';
    foreach (['' => 'All', 'confirmed' => 'Confirmed', 'pending' => 'Pending', 'cancelled' => 'Cancelled'] as $k => $l) printf('<a class="button%s" href="%s">%s</a> ', $f === $k ? ' button-primary' : '', esc_url(admin_url('admin.php?page=brb&status=' . $k)), $l);
    echo '</p><table class="widefat striped"><thead><tr><th>#</th><th>When</th><th>Guest</th><th>Guests</th><th>Total</th><th>Paid/Due</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="8">No bookings yet.</td></tr>';
    foreach ($rows as $b) {
        $act = '';
        foreach (['confirmed' => 'Confirm', 'cancelled' => 'Cancel'] as $s => $l) if ($b->status !== $s)
            $act .= '<a class="button button-small" href="' . esc_url(wp_nonce_url(admin_url("admin-post.php?action=brb_status&id={$b->id}&st=$s"), 'brb_status')) . '">' . $l . '</a> ';
        printf('<tr><td>BR-%d</td><td>%s</td><td>%s<br><small>%s %s</small></td><td>%d</td><td>%s</td><td>%s</td><td><span class="brb-badge %s">%s</span></td><td>%s</td></tr>',
            $b->id, esc_html(brb_when($b)), esc_html($b->name), esc_html($b->email), esc_html($b->phone), $b->guests, brb_money($b->total), brb_money($b->amount_due), esc_attr($b->status), esc_html($b->status), $act);
    }
    echo '</tbody></table></div>';
}
function brb_admin_cal() {
    global $wpdb;
    $m = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : wp_date('Y-m');
    $tz = wp_timezone(); $first = new DateTimeImmutable("$m-01", $tz); $next = $first->modify('+1 month');
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . brb_table() . " WHERE status<>'cancelled' AND start_dt < %s AND end_dt > %s", $next->format('Y-m-d H:i:s'), $first->format('Y-m-d H:i:s')));
    $bo = brb_blackouts();
    echo '<div class="wrap"><h1>Calendar – ' . esc_html($first->format('F Y')) . '</h1><p>';
    printf('<a class="button" href="%s">← Prev</a> <a class="button" href="%s">Next →</a> <a class="button" href="%s">Edit blackout dates</a></p>',
        esc_url(admin_url('admin.php?page=brb-cal&m=' . $first->modify('-1 month')->format('Y-m'))), esc_url(admin_url('admin.php?page=brb-cal&m=' . $next->format('Y-m'))), esc_url(admin_url('admin.php?page=brb-settings')));
    echo '<div class="brb-admincal">';
    foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d) echo "<div class='h'>$d</div>";
    for ($i = 0; $i < (int)$first->format('w'); $i++) echo '<div class="e"></div>';
    for ($d = $first; $d < $next; $d = $d->modify('+1 day')) {
        $k = $d->format('Y-m-d'); $items = '';
        foreach ($rows as $b) { $s = new DateTimeImmutable($b->start_dt, $tz); $e = new DateTimeImmutable($b->end_dt, $tz);
            if ($s < $d->modify('+1 day') && $e > $d) $items .= '<span class="brb-badge ' . esc_attr($b->status) . '">BR-' . $b->id . ' ' . esc_html($b->mode === 'hourly' ? $s->format('ga') . '-' . $e->format('ga') : 'day') . ' ' . esc_html(wp_trim_words($b->name, 1, '')) . '</span>'; }
        if (in_array($k, $bo, true)) $items .= '<span class="brb-badge blackout">Blackout</span>';
        echo '<div><b>' . $d->format('j') . '</b>' . $items . '</div>';
    }
    echo '</div></div>';
}
function brb_admin_settings() {
    if (isset($_POST['brb_save']) && check_admin_referer('brb_settings')) {
        $new = [];
        foreach (brb_defaults() as $k => $v) $new[$k] = in_array($k, ['policy', 'blackouts']) ? sanitize_textarea_field(wp_unslash($_POST[$k] ?? '')) : sanitize_text_field(wp_unslash($_POST[$k] ?? ''));
        update_option('brb_settings', $new); echo '<div class="updated"><p>Saved.</p></div>';
    }
    $o = brb_opt();
    $f = [
        'mode' => ['Booking type offered', 'select', ['both' => 'Full day + hourly', 'day' => 'Full day only', 'hourly' => 'Hourly only']],
        'day_rate' => ['Full-day rate ($) — placeholder'], 'hourly_rate' => ['Hourly rate ($) — placeholder'], 'min_hours' => ['Minimum hours'],
        'open_hour' => ['Hourly bookings open (0–23)'], 'close_hour' => ['Hourly bookings close (1–24)'], 'max_days' => ['Max consecutive days'],
        'cleaning_fee' => ['Cleaning fee ($) — placeholder'], 'tax_rate' => ['Tax rate (%) — placeholder, confirm local rate'],
        'deposit_pct' => ['Charge now (% of total; 100 = full)'], 'max_guests' => ['Max guests'], 'hold_minutes' => ['Hold pending booking (minutes)'],
        'currency' => ['Currency'], 'admin_email' => ['Admin notification email'],
        'stripe_pk' => ['Stripe publishable key (pk_test_…)'], 'stripe_sk' => ['Stripe secret key (sk_test_…)', 'password'], 'stripe_whsec' => ['Stripe webhook signing secret (whsec_…)', 'password'],
        'blackouts' => ['Blackout dates (YYYY-MM-DD, one per line)', 'textarea'], 'policy' => ['Cancellation policy (shown at checkout + emails)', 'textarea'],
    ];
    echo '<div class="wrap"><h1>Booking Settings</h1><p>Stripe webhook URL: <code>' . esc_html(rest_url('brb/v1/stripe-webhook')) . '</code> (events: checkout.session.completed, checkout.session.expired)</p><form method="post">';
    wp_nonce_field('brb_settings'); echo '<table class="form-table">';
    foreach ($f as $k => $d) {
        $t = $d[1] ?? 'text'; echo '<tr><th><label for="' . $k . '">' . esc_html($d[0]) . '</label></th><td>';
        if ($t === 'select') { echo "<select name='$k' id='$k'>"; foreach ($d[2] as $v => $l) printf('<option value="%s"%s>%s</option>', $v, selected($o[$k], $v, false), $l); echo '</select>'; }
        elseif ($t === 'textarea') printf('<textarea name="%s" id="%s" rows="5" class="large-text">%s</textarea>', $k, $k, esc_textarea($o[$k]));
        else printf('<input type="%s" name="%s" id="%s" value="%s" class="regular-text" autocomplete="off">', $t, $k, $k, esc_attr($o[$k]));
        echo '</td></tr>';
    }
    echo '</table><p><button class="button button-primary" name="brb_save" value="1">Save settings</button></p></form></div>';
}
