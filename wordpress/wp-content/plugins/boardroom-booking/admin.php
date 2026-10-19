<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page('Bookings', 'Bookings', 'manage_options', 'brb', 'brb_admin_router', 'dashicons-calendar-alt', 25);
    add_submenu_page('brb', 'Bookings', 'All Bookings', 'manage_options', 'brb', 'brb_admin_router');
    add_submenu_page('brb', 'Add booking', 'Add booking', 'manage_options', 'brb-new', 'brb_admin_new');
    add_submenu_page('brb', 'Calendar', 'Calendar', 'manage_options', 'brb-cal', 'brb_admin_cal');
    add_submenu_page('brb', 'Booking Settings', 'Settings', 'manage_options', 'brb-settings', 'brb_admin_settings');
});
add_action('admin_enqueue_scripts', function ($h) {
    if (strpos($h, 'brb') !== false) wp_enqueue_style('brb-admin', BRB_URL . 'assets/brb.css', [], BRB_VER);
});
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $o = brb_opt();
    $missing = [];
    foreach ([
        'payee' => 'check payable-to name',
        'pay_location' => 'in-person payment location',
        'pay_hours' => 'in-person hours',
        'pay_instructions' => 'payment instructions',
    ] as $k => $label) {
        if (strpos((string) $o[$k], '[VERIFY]') !== false) $missing[] = $label;
    }
    if (!$missing) return;
    echo '<div class="notice notice-warning"><p><strong>Boardroom Booking:</strong> Fill in ' . esc_html(implode(', ', $missing)) . ' under <a href="' . esc_url(admin_url('admin.php?page=brb-settings')) . '">Booking Settings</a>. Guests see the [VERIFY] placeholders until you replace them.</p></div>';
});

function brb_admin_gate($action) {
    if (!current_user_can('manage_options')) wp_die('Sorry, you are not allowed to do that.');
    check_admin_referer($action);
}
function brb_admin_back($id, $note, $page = 'brb') {
    $url = admin_url('admin.php?page=' . $page);
    if ($id) $url = add_query_arg('booking', (int) $id, $url);
    if ($note) $url = add_query_arg('brb_note', $note, $url);
    wp_safe_redirect($url);
    exit;
}
function brb_admin_note() {
    if (empty($_GET['brb_note'])) return;
    echo '<div class="notice notice-info"><p>' . esc_html(wp_unslash($_GET['brb_note'])) . '</p></div>';
}

add_action('admin_post_brb_payment', function () {
    brb_admin_gate('brb_payment');
    $id = (int) ($_POST['booking'] ?? 0);
    $res = brb_record_payment(
        $id,
        wp_unslash($_POST['amount'] ?? ''),
        wp_unslash($_POST['check_number'] ?? ''),
        sanitize_text_field(wp_unslash($_POST['received_on'] ?? '')),
        wp_unslash($_POST['pay_notes'] ?? ''),
        true
    );
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, brb_ref($res) . ' updated: ' . brb_status_label($res->status) . '. The guest was emailed.');
});
add_action('admin_post_brb_mark_paid', function () {
    brb_admin_gate('brb_mark_paid');
    $id = (int) ($_POST['booking'] ?? 0);
    $res = brb_mark_paid_full($id);
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, brb_ref($res) . ' marked paid in full. The guest was emailed.');
});
add_action('admin_post_brb_extend', function () {
    brb_admin_gate('brb_extend');
    $id = (int) ($_POST['booking'] ?? 0);
    $res = brb_extend_hold($id, (int) ($_POST['hours'] ?? 0));
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, brb_ref($res) . ' is held until ' . brb_format_utc_mysql($res->hold_until) . '.');
});
add_action('admin_post_brb_cancel', function () {
    brb_admin_gate('brb_cancel');
    $id = (int) ($_POST['booking'] ?? 0);
    $res = brb_cancel_booking($id);
    if (is_wp_error($res)) brb_admin_back($id, $res->get_error_message());
    brb_admin_back($id, brb_ref($res) . ' cancelled. The guest was emailed.');
});
add_action('admin_post_brb_manual', function () {
    brb_admin_gate('brb_manual');
    $post = wp_unslash($_POST);
    $override = !empty($post['override_notice']);
    $r = brb_build_range($post, ['override_notice' => $override]);
    if (is_wp_error($r)) brb_admin_back(0, $r->get_error_message(), 'brb-new');
    $name = sanitize_text_field($post['name'] ?? '');
    $email = sanitize_email($post['email'] ?? '');
    if (!$name || !is_email($email)) brb_admin_back(0, 'Name and a valid email are required.', 'brb-new');
    $source = sanitize_text_field($post['source'] ?? 'phone');
    $take = !empty($post['take_check']);
    $q = brb_quote($r);
    $b = brb_insert_booking($r, $q, [
        'name' => $name,
        'email' => $email,
        'phone' => sanitize_text_field($post['phone'] ?? ''),
        'notes' => sanitize_textarea_field($post['notes'] ?? ''),
    ], $source, !$take);
    if (is_wp_error($b)) brb_admin_back(0, $b->get_error_message(), 'brb-new');
    if ($take) {
        $pay = brb_record_payment(
            $b->id,
            $post['amount'] ?? '',
            $post['check_number'] ?? '',
            sanitize_text_field($post['received_on'] ?? ''),
            $post['pay_notes'] ?? '',
            true
        );
        if (is_wp_error($pay)) {
            brb_send_hold_emails(brb_booking($b->id));
            brb_admin_back($b->id, 'Booking saved as held, but the check was not recorded: ' . $pay->get_error_message());
        }
        brb_admin_back($pay->id, brb_ref($pay) . ' saved (' . brb_source_label($source) . '). ' . brb_status_label($pay->status) . '. The guest was emailed.');
    }
    brb_admin_back($b->id, brb_ref($b) . ' saved as held (' . brb_source_label($source) . '). The guest was emailed.');
});

function brb_admin_router() {
    if (!empty($_GET['booking'])) brb_admin_detail((int) $_GET['booking']);
    else brb_admin_list();
}
function brb_admin_list() {
    global $wpdb;
    $allowed = ['held', 'deposit_paid', 'paid', 'cancelled', 'expired'];
    $f = sanitize_key($_GET['status'] ?? '');
    if (!in_array($f, $allowed, true)) $f = '';
    if ($f) $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . brb_table() . ' WHERE status=%s ORDER BY start_dt DESC LIMIT 500', $f));
    else $rows = $wpdb->get_results('SELECT * FROM ' . brb_table() . ' ORDER BY start_dt DESC LIMIT 500');
    echo '<div class="wrap"><h1 class="wp-heading-inline">Bookings</h1> <a class="page-title-action" href="' . esc_url(admin_url('admin.php?page=brb-new')) . '">Add booking</a>';
    brb_admin_note();
    echo '<p style="margin-top:12px">';
    $filters = ['' => 'All', 'held' => 'Held (awaiting check)', 'deposit_paid' => 'Deposit paid', 'paid' => 'Paid in full', 'cancelled' => 'Cancelled', 'expired' => 'Expired'];
    foreach ($filters as $k => $l) {
        printf('<a class="button%s" href="%s">%s</a> ', $f === $k ? ' button-primary' : '', esc_url(admin_url('admin.php?page=brb&status=' . $k)), esc_html($l));
    }
    echo '</p><table class="widefat"><thead><tr><th>#</th><th>When</th><th>Guest</th><th>Source</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Held until</th><th></th></tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="10">No bookings yet.</td></tr>';
    foreach ($rows as $b) {
        $balance = max(0, round((float) $b->total - (float) $b->amount_paid, 2));
        $until = ($b->status === 'held' && $b->hold_until) ? brb_format_utc_mysql($b->hold_until) : '—';
        printf(
            '<tr><td>%s</td><td>%s</td><td>%s<br><small>%s %s</small></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><span class="brb-badge %s">%s</span></td><td>%s</td><td><a class="button button-small" href="%s">Open</a></td></tr>',
            esc_html(brb_ref($b)),
            esc_html(brb_when($b)),
            esc_html($b->name),
            esc_html($b->email),
            esc_html($b->phone),
            esc_html(brb_source_label($b->source)),
            esc_html(brb_money($b->total)),
            esc_html(brb_money($b->amount_paid)),
            esc_html(brb_money($balance)),
            esc_attr($b->status),
            esc_html(brb_status_label($b->status)),
            esc_html($until),
            esc_url(admin_url('admin.php?page=brb&booking=' . (int) $b->id))
        );
    }
    echo '</tbody></table></div>';
}
function brb_admin_detail($id) {
    $b = brb_booking($id);
    echo '<div class="wrap"><h1>Booking</h1>';
    brb_admin_note();
    if (!$b) {
        echo '<p>Booking not found.</p></div>';
        return;
    }
    $balance = max(0, round((float) $b->total - (float) $b->amount_paid, 2));
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=brb')) . '">← All bookings</a></p>';
    echo '<h2>' . esc_html(brb_ref($b)) . ' <span class="brb-badge ' . esc_attr($b->status) . '">' . esc_html(brb_status_label($b->status)) . '</span></h2>';
    echo '<table class="widefat" style="max-width:760px"><tbody>';
    $rows = [
        'When' => brb_when($b),
        'Guests' => (string) (int) $b->guests,
        'Source' => brb_source_label($b->source),
        'Name' => $b->name,
        'Email' => $b->email,
        'Phone' => $b->phone,
        'Notes' => $b->notes,
        'Subtotal' => brb_money($b->subtotal),
        'Cleaning' => brb_money($b->cleaning),
        'Tax' => brb_money($b->tax),
        'Total' => brb_money($b->total),
        'Check expected to secure the date' => brb_money($b->deposit_due),
        'Paid' => brb_money($b->amount_paid),
        'Balance' => brb_money($balance),
        'Balance due' => $b->balance_due_on ? brb_balance_due_label($b->balance_due_on) : '—',
        'Held until' => $b->hold_until ? brb_format_utc_mysql($b->hold_until) : '—',
    ];
    foreach ($rows as $label => $val) {
        echo '<tr><th style="width:280px">' . esc_html($label) . '</th><td>' . nl2br(esc_html((string) $val)) . '</td></tr>';
    }
    echo '</tbody></table>';

    if (!in_array($b->status, ['cancelled', 'expired', 'paid'], true) && $balance > 0.009) {
        echo '<h2>Record check payment</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:640px">';
        echo '<input type="hidden" name="action" value="brb_payment"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_payment');
        $toward = round((float) $b->deposit_due - (float) $b->amount_paid, 2);
        $suggest = ($toward > 0.009) ? min($balance, $toward) : $balance;
        echo '<table class="form-table"><tr><th><label for="amount">Amount</label></th><td><input name="amount" id="amount" type="number" step="0.01" min="0.01" max="' . esc_attr(brb_dec($balance)) . '" value="' . esc_attr(brb_dec($suggest)) . '" required> <span class="description">Balance is ' . esc_html(brb_money($balance)) . '. Record the deposit, then record the balance when the second check arrives.</span></td></tr>';
        echo '<tr><th><label for="check_number">Check number</label></th><td><input name="check_number" id="check_number" class="regular-text" required></td></tr>';
        echo '<tr><th><label for="received_on">Date received</label></th><td><input name="received_on" id="received_on" type="date" value="' . esc_attr(brb_now()->format('Y-m-d')) . '" required></td></tr>';
        echo '<tr><th><label for="pay_notes">Notes</label></th><td><textarea name="pay_notes" id="pay_notes" rows="3" class="large-text"></textarea></td></tr></table>';
        echo '<p><button class="button button-primary">Record check and email guest</button></p></form>';
    }

    echo '<h2>Actions</h2><div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end">';
    if (!in_array($b->status, ['paid', 'cancelled', 'expired'], true) && $balance > 0.009) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="brb_mark_paid"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_mark_paid');
        echo '<button class="button">Mark paid in full</button></form>';
    }
    if (in_array($b->status, ['held', 'expired'], true)) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="brb_extend"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_extend');
        echo '<label>Extend hold by <input type="number" name="hours" min="1" max="720" value="' . (int) brb_hold_hours() . '" style="width:80px"> hours</label> ';
        echo '<button class="button">Extend hold</button></form>';
    }
    if ($b->status !== 'cancelled') {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Cancel this booking and release the date?\');"><input type="hidden" name="action" value="brb_cancel"><input type="hidden" name="booking" value="' . (int) $b->id . '">';
        wp_nonce_field('brb_cancel');
        echo '<button class="button">Cancel booking</button></form>';
    }
    echo '</div>';

    $pays = brb_payments($b->id);
    echo '<h2>Checks recorded</h2>';
    if (!$pays) {
        echo '<p>No checks recorded yet.</p>';
    } else {
        echo '<table class="widefat" style="max-width:760px"><thead><tr><th>Date received</th><th>Amount</th><th>Check number</th><th>Notes</th></tr></thead><tbody>';
        foreach ($pays as $p) {
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html($p->received_on), esc_html(brb_money($p->amount)), esc_html($p->check_number), esc_html($p->notes));
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

function brb_admin_new() {
    if (!current_user_can('manage_options')) return;
    $o = brb_opt();
    echo '<div class="wrap"><h1>Add booking</h1>';
    brb_admin_note();
    echo '<p>Use this for a phone call or a walk-in. The date is held the same way as a date saved on the website, unless you record a check now.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:720px"><input type="hidden" name="action" value="brb_manual">';
    wp_nonce_field('brb_manual');
    echo '<table class="form-table">';
    echo '<tr><th>Source</th><td><select name="source"><option value="phone">Phone</option><option value="walk-in">Walk-in</option><option value="online">Online</option></select></td></tr>';
    echo '<tr><th><label for="date">Date</label></th><td><input type="date" name="date" id="date" required value="' . esc_attr(brb_now()->modify('+3 days')->format('Y-m-d')) . '"></td></tr>';
    echo '<tr><th>Type</th><td><label><input type="radio" name="type" value="day" checked> Full day</label> &nbsp; <label><input type="radio" name="type" value="hourly"> Hourly</label></td></tr>';
    echo '<tr><th><label for="days">Days (full day)</label></th><td><input type="number" name="days" id="days" min="1" max="' . (int) $o['max_days'] . '" value="1"></td></tr>';
    echo '<tr><th><label for="start_hour">Start hour (hourly)</label></th><td><input type="number" name="start_hour" id="start_hour" min="' . (int) $o['open_hour'] . '" max="' . ((int) $o['close_hour'] - 1) . '" value="' . (int) $o['open_hour'] . '"> <span class="description">0–23, venue hours ' . (int) $o['open_hour'] . ':00–' . (int) $o['close_hour'] . ':00</span></td></tr>';
    echo '<tr><th><label for="hours">Hours</label></th><td><input type="number" name="hours" id="hours" min="' . (int) $o['min_hours'] . '" value="' . (int) $o['min_hours'] . '"></td></tr>';
    echo '<tr><th><label for="guests">Guests</label></th><td><input type="number" name="guests" id="guests" min="1" max="' . (int) $o['max_guests'] . '" value="10"></td></tr>';
    echo '<tr><th><label for="name">Name</label></th><td><input name="name" id="name" class="regular-text" required></td></tr>';
    echo '<tr><th><label for="email">Email</label></th><td><input type="email" name="email" id="email" class="regular-text" required></td></tr>';
    echo '<tr><th><label for="phone">Phone</label></th><td><input name="phone" id="phone" class="regular-text"></td></tr>';
    echo '<tr><th><label for="notes">Notes</label></th><td><textarea name="notes" id="notes" rows="3" class="large-text"></textarea></td></tr>';
    echo '<tr><th>Minimum notice</th><td><label><input type="checkbox" name="override_notice" value="1"> Staff override: allow a start inside the ' . (int) brb_notice_hours() . '-hour notice. The public form cannot do this.</label></td></tr>';
    echo '<tr><th>Check now</th><td><label><input type="checkbox" name="take_check" value="1"> Record a check with this booking</label></td></tr>';
    echo '<tr><th><label for="amount">Check amount</label></th><td><input name="amount" id="amount" type="number" step="0.01" min="0"></td></tr>';
    echo '<tr><th><label for="check_number">Check number</label></th><td><input name="check_number" id="check_number" class="regular-text"></td></tr>';
    echo '<tr><th><label for="received_on">Date received</label></th><td><input type="date" name="received_on" id="received_on" value="' . esc_attr(brb_now()->format('Y-m-d')) . '"></td></tr>';
    echo '<tr><th><label for="pay_notes">Check notes</label></th><td><textarea name="pay_notes" id="pay_notes" rows="2" class="large-text"></textarea></td></tr>';
    echo '</table><p><button class="button button-primary">Save booking</button></p></form></div>';
}

function brb_admin_cal() {
    global $wpdb;
    $m = (isset($_GET['m']) && preg_match('/^\d{4}-\d{2}$/', $_GET['m'])) ? $_GET['m'] : brb_now()->format('Y-m');
    $tz = brb_tz();
    $first = new DateTimeImmutable($m . '-01 00:00:00', $tz);
    $next = $first->modify('+1 month');
    list($block, $args) = brb_blocking_sql();
    $sql = 'SELECT * FROM ' . brb_table() . " WHERE start_dt < %s AND end_dt > %s AND $block";
    $params = array_merge([$next->format('Y-m-d H:i:s'), $first->format('Y-m-d H:i:s')], $args);
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
    $bo = brb_blackouts();
    echo '<div class="wrap"><h1>Calendar – ' . esc_html($first->format('F Y')) . '</h1><p>';
    printf(
        '<a class="button" href="%s">← Prev</a> <a class="button" href="%s">Next →</a> <a class="button" href="%s">Edit blackout dates</a></p>',
        esc_url(admin_url('admin.php?page=brb-cal&m=' . $first->modify('-1 month')->format('Y-m'))),
        esc_url(admin_url('admin.php?page=brb-cal&m=' . $next->format('Y-m'))),
        esc_url(admin_url('admin.php?page=brb-settings'))
    );
    echo '<div class="brb-admincal">';
    foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d) echo "<div class='h'>$d</div>";
    for ($i = 0; $i < (int) $first->format('w'); $i++) echo '<div class="e"></div>';
    for ($d = $first; $d < $next; $d = $d->modify('+1 day')) {
        $k = $d->format('Y-m-d');
        $items = '';
        foreach ($rows as $b) {
            $s = new DateTimeImmutable($b->start_dt, $tz);
            $e = new DateTimeImmutable($b->end_dt, $tz);
            if ($s < $d->modify('+1 day') && $e > $d) {
                $items .= '<a class="brb-badge ' . esc_attr($b->status) . '" href="' . esc_url(admin_url('admin.php?page=brb&booking=' . (int) $b->id)) . '">' . esc_html(brb_ref($b) . ' ' . ($b->mode === 'hourly' ? $s->format('ga') . '-' . $e->format('ga') : 'day') . ' ' . $b->name) . '</a>';
            }
        }
        if (in_array($k, $bo, true)) $items .= '<span class="brb-badge blackout">Blackout</span>';
        echo '<div><b>' . $d->format('j') . '</b>' . $items . '</div>';
    }
    echo '</div></div>';
}

function brb_admin_settings() {
    if (!current_user_can('manage_options')) return;
    $kept = false;
    if (isset($_POST['brb_save']) && check_admin_referer('brb_settings')) {
        $prev = get_option('brb_settings', []);
        if (!is_array($prev)) $prev = [];
        $new = [];
        $defaults = brb_defaults();
        foreach ($defaults as $k => $v) {
            $raw = wp_unslash($_POST[$k] ?? '');
            $new[$k] = in_array($k, ['policy', 'blackouts', 'pay_instructions'], true)
                ? sanitize_textarea_field($raw)
                : sanitize_text_field($raw);
        }
        if (!in_array($new['mode'], ['both', 'day', 'hourly'], true)) $new['mode'] = 'both';
        if (!in_array($new['deposit_type'], ['full', 'percent', 'fixed'], true)) $new['deposit_type'] = 'full';
        $new['min_notice_hours'] = (string) max(0, (int) $new['min_notice_hours']);
        $new['hold_hours'] = (string) max(1, (int) $new['hold_hours']);
        $new['balance_due_days'] = (string) max(0, (int) $new['balance_due_days']);
        $new['deposit_pct'] = (string) max(1, min(100, (float) $new['deposit_pct']));
        $new['deposit_fixed'] = brb_dec(max(0, (float) $new['deposit_fixed']));
        $new['min_hours'] = (string) max(1, (int) $new['min_hours']);
        $new['max_guests'] = (string) max(1, (int) $new['max_guests']);
        $new['max_days'] = (string) max(1, (int) $new['max_days']);
        $new['open_hour'] = (string) max(0, min(23, (int) $new['open_hour']));
        $new['close_hour'] = (string) max(1, min(24, (int) $new['close_hour']));
        if (trim($new['blackouts']) === '' && trim((string) ($prev['blackouts'] ?? '')) !== '' && empty($_POST['clear_blackouts'])) {
            $new['blackouts'] = (string) $prev['blackouts'];
            $kept = true;
        }
        foreach (brb_legacy_keys() as $lk) unset($new[$lk]);
        update_option('brb_settings', $new);
        echo '<div class="updated"><p>Saved.' . ($kept ? ' Blackout dates were kept because the list was empty and “clear all blackout dates” was not ticked.' : '') . '</p></div>';
    }
    $o = brb_opt();
    $groups = [
        'Rates and hours' => [
            ['mode', 'Booking type offered', 'select', ['both' => 'Full day + hourly', 'day' => 'Full day only', 'hourly' => 'Hourly only'], ''],
            ['day_rate', 'Full-day rate ($)', 'text', null, 'Placeholder until you enter the real rate.'],
            ['hourly_rate', 'Hourly rate ($)', 'text', null, 'Placeholder until you enter the real rate.'],
            ['min_hours', 'Minimum hours', 'number', null, ''],
            ['open_hour', 'Hourly open (0–23)', 'number', null, ''],
            ['close_hour', 'Hourly close (1–24)', 'number', null, ''],
            ['max_days', 'Max consecutive days', 'number', null, ''],
            ['max_guests', 'Max guests', 'number', null, ''],
            ['cleaning_fee', 'Cleaning fee ($)', 'text', null, 'Placeholder until you enter the real fee.'],
            ['tax_rate', 'Tax rate (%)', 'text', null, '[VERIFY] Confirm the local rate before charging it.'],
            ['currency', 'Currency code', 'text', null, 'Checks are US dollars. This is kept for records.'],
        ],
        'Notice and holds' => [
            ['min_notice_hours', 'Minimum notice (hours)', 'number', null, 'A booking must start at least this many hours from now. Default 48. The owner described the rule as not renting inside 48 hours; change this number here if that rule changes. Set 0 to allow same-day starts.'],
            ['hold_hours', 'Hold length (hours)', 'number', null, 'After a guest saves a date, it stays reserved for this long while you wait for the check. Default 48. When the hold ends, the date is released and the guest and you are emailed.'],
        ],
        'Check payment' => [
            ['deposit_type', 'Amount of the check that secures the date', 'select', ['full' => 'Full amount', 'percent' => 'Percent of the total', 'fixed' => 'Fixed dollar amount'], 'Full amount is the default.'],
            ['deposit_pct', 'Deposit percent', 'number', null, 'Used when the choice above is percent. 100 is the same as the full amount.'],
            ['deposit_fixed', 'Fixed deposit amount ($)', 'text', null, 'Used when the choice above is a fixed dollar amount. Cannot exceed the total.'],
            ['balance_due_days', 'Balance due (days before the event)', 'number', null, '[VERIFY] Used when the first check is less than the total. 7 means the rest is due 7 days before the start date.'],
            ['payee', 'Check payable to [VERIFY]', 'text', null, 'The name guests write on the payee line. Replace the [VERIFY] text.'],
            ['pay_location', 'Where to bring the check [VERIFY]', 'text', null, 'Do not leave the placeholder in place.'],
            ['pay_hours', 'In-person hours [VERIFY]', 'text', null, 'Days and hours a guest can drop off a check.'],
            ['pay_instructions', 'Payment instructions [VERIFY]', 'textarea', null, 'Extra text shown on the confirmation page and in the guest email.'],
            ['admin_email', 'Admin notification email', 'text', null, 'Hold, check, and expiry emails are sent here.'],
        ],
        'Calendar and policy' => [
            ['blackouts', 'Blackout dates (YYYY-MM-DD, one per line)', 'textarea', null, 'Saved dates stay in the database when the plugin is updated. Emptying this box does not delete them unless you tick the box under it.'],
            ['policy', 'Cancellation policy', 'textarea', null, 'Shown before a guest saves a date, and included in emails.'],
        ],
    ];
    echo '<div class="wrap"><h1>Booking Settings</h1>';
    echo '<p>Guests save a date on the website and bring a check. Nothing is collected through the website.</p>';
    $sampleStart = brb_now()->modify('+10 days')->setTime(0, 0);
    $sampleDue = brb_deposit_due_amount(100);
    echo '<div class="brb-paybox"><strong>Preview of the check instructions</strong>';
    brb_echo_lines(brb_preview_lines(['total' => 100, 'due' => $sampleDue], $sampleStart));
    echo '</div>';
    echo '<form method="post">';
    wp_nonce_field('brb_settings');
    foreach ($groups as $title => $fields) {
        echo '<h2>' . esc_html($title) . '</h2><table class="form-table">';
        foreach ($fields as $d) {
            list($k, $label, $type, $opts, $help) = $d;
            echo '<tr><th><label for="' . esc_attr($k) . '">' . esc_html($label) . '</label></th><td>';
            if ($type === 'select') {
                echo '<select name="' . esc_attr($k) . '" id="' . esc_attr($k) . '">';
                foreach ($opts as $v => $l) printf('<option value="%s"%s>%s</option>', esc_attr($v), selected($o[$k], $v, false), esc_html($l));
                echo '</select>';
            } elseif ($type === 'textarea') {
                printf('<textarea name="%s" id="%s" rows="6" class="large-text">%s</textarea>', esc_attr($k), esc_attr($k), esc_textarea($o[$k]));
            } else {
                printf('<input type="%s" name="%s" id="%s" value="%s" class="regular-text">', $type === 'number' ? 'number' : 'text', esc_attr($k), esc_attr($k), esc_attr($o[$k]));
            }
            if ($help) echo '<p class="description">' . esc_html($help) . '</p>';
            if ($k === 'blackouts') echo '<p><label><input type="checkbox" name="clear_blackouts" value="1"> Clear all blackout dates (only if you mean to delete every saved date)</label></p>';
            echo '</td></tr>';
        }
        echo '</table>';
    }
    echo '<p><button class="button button-primary" name="brb_save" value="1">Save settings</button></p></form></div>';
}
