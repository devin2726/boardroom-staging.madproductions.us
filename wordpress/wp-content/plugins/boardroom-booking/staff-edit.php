<?php
/**
 * 2.5.89 staff booking edit on the /staff/ dashboard record.
 * Staff can fill in or fix guest name, email, guest count, agreed price, event time and notes.
 * Especially for bookings copied from calendar holds (source hold-migrate). Saving does not email the guest.
 */

if (!defined('ABSPATH')) exit;

function brb_sd_can_edit($b) {
    return $b && !in_array((string) $b->status, brb_sd_closed_statuses(), true);
}

function brb_sd_hm($dt) {
    try {
        return (new DateTimeImmutable((string) $dt, brb_tz()))->format('H:i');
    } catch (Exception $e) {
        return '';
    }
}

/** Accept "10:00", "10:00:00" or "10:00 AM". */
function brb_sd_parse_hm($s) {
    $s = trim((string) $s);
    if ($s === '') return '';
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $s, $m)) {
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h <= 23 && $min <= 59) return sprintf('%02d:%02d', $h, $min);
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('g:i A', strtoupper($s), brb_tz());
    if ($dt instanceof DateTimeImmutable) return $dt->format('H:i');
    $dt = DateTimeImmutable::createFromFormat('g:iA', strtoupper(str_replace(' ', '', $s)), brb_tz());
    return $dt instanceof DateTimeImmutable ? $dt->format('H:i') : '';
}

function brb_sd_parse_price($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') return null;
    $raw = str_replace([',', '$', ' '], '', $raw);
    if (!is_numeric($raw)) return new WP_Error('price', 'Enter a valid agreed price.');
    $n = round((float) $raw, 2);
    if ($n <= 0) return new WP_Error('price', 'Enter an agreed price greater than zero.');
    return $n;
}

function brb_sd_edit_when_text($date, $start_hm, $end_hm) {
    $when = brb_sd_fmt_date($date, 'l, F j, Y');
    $s = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $start_hm, brb_tz());
    $e = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $end_hm, brb_tz());
    $hours = ($s && $e) ? ($s->format('g:i A') . ' – ' . $e->format('g:i A')) : ($start_hm . ' – ' . $end_hm);
    return trim($when . ', ' . $hours, ' ,');
}

function brb_sd_edit_fields($b) {
    if (!$b) return null;
    return [
        'name' => (string) $b->name,
        'email' => (string) $b->email,
        'guests' => (int) $b->guests,
        'max_guests' => brb_sd_max_guests(),
        'price' => brb_price_is_set($b) ? brb_dec($b->total) : '',
        'price_locked' => brb_booking_has_recorded_payment($b),
        'date' => substr((string) $b->start_dt, 0, 10),
        'start_time' => brb_sd_hm($b->start_dt),
        'end_time' => brb_sd_hm($b->end_dt),
        'notes' => (string) ($b->event_about ?: $b->notes),
    ];
}

function brb_sd_edit_input($p, $b) {
    $p = is_array($p) ? $p : [];
    $name = sanitize_text_field((string) ($p['name'] ?? $b->name));
    if ($name === '') return new WP_Error('name', 'Enter the guest name.');

    $email_raw = array_key_exists('email', $p) ? trim((string) $p['email']) : (string) $b->email;
    $email = $email_raw === '' ? '' : sanitize_email($email_raw);
    if ($email_raw !== '' && !is_email($email)) return new WP_Error('email', 'Enter a valid guest email.');

    $max = brb_sd_max_guests();
    if (!array_key_exists('guests', $p) || $p['guests'] === '' || $p['guests'] === null) {
        return new WP_Error('guests', 'Enter the guest count (up to ' . $max . ').');
    }
    if (!is_numeric($p['guests']) || (string) (int) $p['guests'] !== (string) trim((string) $p['guests'])) {
        return new WP_Error('guests', 'Enter the guest count as a whole number (up to ' . $max . ').');
    }
    $guests = (int) $p['guests'];
    if ($guests < 0 || $guests > $max) {
        return new WP_Error('guests', 'Guest count can be 0 to ' . $max . '.');
    }

    $date = sanitize_text_field((string) ($p['date'] ?? substr((string) $b->start_dt, 0, 10)));
    if (!brb_sd_valid_ymd($date)) return new WP_Error('date', 'Choose a valid date.');
    $today = brb_now()->format('Y-m-d');
    $old_date = substr((string) $b->start_dt, 0, 10);
    if ($date !== $old_date && $date < $today) return new WP_Error('past', 'That date is in the past.');

    $start_hm = brb_sd_parse_hm((string) ($p['start_time'] ?? brb_sd_hm($b->start_dt)));
    $end_hm = brb_sd_parse_hm((string) ($p['end_time'] ?? brb_sd_hm($b->end_dt)));
    if ($start_hm === '' || $end_hm === '') return new WP_Error('time', 'Enter a valid start and end time.');
    if ($end_hm <= $start_hm) return new WP_Error('time', 'The end time must be after the start time.');

    $notes = sanitize_textarea_field((string) ($p['notes'] ?? ($b->event_about ?: $b->notes)));
    if (strlen($notes) > 5000) return new WP_Error('notes', 'Notes are too long.');

    $price_in = array_key_exists('price', $p) ? brb_sd_parse_price($p['price']) : null;
    if (is_wp_error($price_in)) return $price_in;

    $old_price = brb_price_is_set($b) ? round((float) $b->total, 2) : null;
    $price_changed = $price_in !== null && ($old_price === null || abs($old_price - $price_in) >= 0.005);
    if ($price_changed && brb_booking_has_recorded_payment($b)) {
        return new WP_Error('paid', 'A payment is already recorded, so the price is locked.');
    }

    $start_dt = $date . ' ' . $start_hm . ':00';
    $end_dt = $date . ' ' . $end_hm . ':00';
    $time_changed = $start_dt !== (string) $b->start_dt || $end_dt !== (string) $b->end_dt;

    return [
        'name' => $name,
        'email' => $email,
        'guests' => $guests,
        'date' => $date,
        'start_time' => $start_hm,
        'end_time' => $end_hm,
        'start_dt' => $start_dt,
        'end_dt' => $end_dt,
        'notes' => $notes,
        'price' => $price_in,
        'price_changed' => $price_changed,
        'time_changed' => $time_changed,
    ];
}

function brb_sd_edit_diff($b, $in) {
    $changes = [];
    $old_notes = (string) ($b->event_about ?: $b->notes);
    $pairs = [
        ['name', 'Guest name', (string) $b->name, $in['name']],
        ['email', 'Guest email', (string) $b->email, $in['email']],
        ['guests', 'Guest count', (string) (int) $b->guests, (string) (int) $in['guests']],
        ['notes', 'Notes', $old_notes, $in['notes']],
    ];
    foreach ($pairs as $row) {
        if ((string) $row[2] !== (string) $row[3]) {
            $changes[] = ['field' => $row[0], 'label' => $row[1], 'from' => $row[2], 'to' => $row[3]];
        }
    }
    if ($in['time_changed']) {
        $changes[] = [
            'field' => 'time',
            'label' => 'Event time',
            'from' => brb_sd_edit_when_text(substr((string) $b->start_dt, 0, 10), brb_sd_hm($b->start_dt), brb_sd_hm($b->end_dt)),
            'to' => brb_sd_edit_when_text($in['date'], $in['start_time'], $in['end_time']),
        ];
    }
    if ($in['price_changed']) {
        $changes[] = [
            'field' => 'price',
            'label' => 'Agreed price',
            'from' => brb_price_is_set($b) ? brb_money($b->total) : 'Price to be confirmed',
            'to' => brb_money($in['price']),
        ];
    }
    return $changes;
}

function brb_sd_edit_load($id, $p) {
    $b = brb_booking((int) $id);
    if (!$b) return new WP_Error('missing', 'Booking not found.', ['status' => 404]);
    if (!brb_sd_can_edit($b)) {
        return new WP_Error('status', 'This booking cannot be edited.', ['status' => 409]);
    }
    $in = brb_sd_edit_input($p, $b);
    if (is_wp_error($in)) return $in;
    $changes = brb_sd_edit_diff($b, $in);
    if (!$changes) return new WP_Error('nothing', 'Nothing was changed.', ['status' => 409]);
    return ['booking' => $b, 'in' => $in, 'changes' => $changes];
}

function brb_sd_edit_preview($id, $p) {
    $got = brb_sd_edit_load($id, $p);
    if (is_wp_error($got)) return $got;
    return ['changes' => $got['changes']];
}

function brb_sd_edit_audit_line($changes) {
    $bits = [];
    foreach ($changes as $c) {
        $bits[] = strtolower($c['label']) . ' from "' . brb_audit_clean($c['from']) . '" to "' . brb_audit_clean($c['to']) . '"';
    }
    return implode('; ', $bits) . '; no email sent';
}

function brb_sd_edit($id, $p) {
    $p = is_array($p) ? $p : [];
    if (empty($p['confirmed'])) {
        return new WP_Error('confirm', 'Confirm first. Nothing was changed.', ['status' => 409]);
    }
    $got = brb_sd_edit_load($id, $p);
    if (is_wp_error($got)) return $got;
    $b = $got['booking'];
    $in = $got['in'];
    $changes = $got['changes'];

    brb_sd_guard_guest_mail(array_filter([(string) $b->email, (string) $in['email']]));
    $locked = false;
    try {
        if ($in['time_changed']) {
            if (!brb_sd_lock()) return new WP_Error('lock', 'Please try again in a moment.');
            $locked = true;
            $conflict = brb_sd_day_conflict($in['date'], ['exclude' => (int) $b->id]);
            if ($conflict) {
                return new WP_Error('busy', $conflict['message'] . ' Nothing was saved.', ['code' => $conflict['code']]);
            }
        }

        global $wpdb;
        $row = [];
        foreach ($changes as $c) {
            if ($c['field'] === 'name') $row['name'] = $in['name'];
            if ($c['field'] === 'email') $row['email'] = $in['email'];
            if ($c['field'] === 'guests') $row['guests'] = $in['guests'];
            if ($c['field'] === 'notes') {
                $row['notes'] = $in['notes'];
                $row['event_about'] = $in['notes'];
            }
            if ($c['field'] === 'time') {
                $row['start_dt'] = $in['start_dt'];
                $row['end_dt'] = $in['end_dt'];
            }
        }
        if ($row && false === $wpdb->update(brb_table(), $row, ['id' => (int) $b->id])) {
            return new WP_Error('db', 'Could not save the booking.');
        }

        if ($in['price_changed']) {
            $set = brb_set_agreed_price((int) $b->id, $in['price'], false);
            if (is_wp_error($set)) return $set;
        } elseif ($in['time_changed'] && brb_price_is_set(brb_booking((int) $b->id))) {
            brb_recalc_booking((int) $b->id);
        }

        $fresh = brb_booking((int) $b->id);
        if ($fresh && brb_price_is_set($fresh) && ($in['time_changed'] || trim((string) ($fresh->balance_due_on ?? '')) === '')) {
            $start = new DateTimeImmutable((string) $fresh->start_dt, brb_tz());
            $wpdb->update(brb_table(), ['balance_due_on' => brb_balance_due_on($start)], ['id' => (int) $fresh->id]);
        }

        brb_audit_append((int) $b->id, 'staff dashboard: ' . brb_sd_edit_audit_line($changes));
    } finally {
        if ($locked) brb_sd_unlock();
        brb_sd_release_guard();
    }

    $fresh = brb_booking((int) $b->id);
    return $fresh ? brb_sd_booking_detail($fresh) : new WP_Error('missing', 'Booking not found.', ['status' => 404]);
}
