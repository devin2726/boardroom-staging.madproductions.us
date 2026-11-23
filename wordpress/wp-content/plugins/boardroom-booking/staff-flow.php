<?php
/**
 * 2.5.87 booking flow for the staff dashboard (Max, Nov 23 2026, 10:10 AM CT): one booking record.
 * No guest-facing email, terms, FAQ, chat or book-page wording is changed here.
 *
 *  1. Calendar holds become booking records in HOLD status (brb_sd_mig_*). Dates stay blocked the whole time.
 *     Run only by an administrator, never automatically. Upgrade stores a dry-run report only.
 *  2. The 48-hour pay window starts when the pay link is sent (default). hold_clock_start can be 'link_sent', 'approve' or 'request'.
 *  3. A passed window is a staff flag. Release is a person's decision with a confirm step. No email, no auto-cancel.
 *     An approved booking whose pay link was never sent also gets a staff-only flag. No auto-cancel, no email.
 *  4. Balance reminder queue for Paid Deposit bookings. Placeholder wording; cannot send until Max approves it in settings.
 *     The week-out checklist email no longer sends on its own; staff preview and confirm it. Its wording is unchanged.
 *  5. Remove a hold or closed day with a confirm step.
 */
if (!defined('ABSPATH')) exit;

/* ---------- pay window (48 hours) ---------- */

/** Countdown facts for an unpaid booking on a pay window, or null when there is none. */
function brb_sd_hold_info($b) {
    if (!$b || !in_array((string) $b->status, ['approved', 'held'], true) || (float) $b->amount_paid > 0.009) return null;
    $until = trim((string) ($b->hold_until ?? ''));
    $t = $until === '' ? 0 : (int) strtotime($until . ' UTC');
    if (!$t) {
        // No clock yet. With the default setting the 48 hours start when the pay link is sent.
        if (brb_hold_clock_start() !== 'link_sent' || !brb_price_is_set($b)) return null;
        return ['seconds_left' => null, 'lapsed' => false, 'short' => 'No link', 'text' => 'Pay link not sent. The 48-hour pay window starts when it is sent.', 'until_text' => '', 'clock' => 'link_sent', 'waiting_for_link' => true];
    }
    $left = $t - time();
    $lapsed = $left < 0;
    $h = (int) floor(abs($left) / 3600);
    $m = (int) floor((abs($left) % 3600) / 60);
    $short = $lapsed ? 'Lapsed' : ($h >= 1 ? $h . 'h left' : $m . 'm left');
    $long = $lapsed ? 'Pay window passed ' . ($h >= 1 ? $h . 'h ' : '') . $m . 'm ago' : $h . 'h ' . $m . 'm left to pay';
    try {
        $until_text = (new DateTimeImmutable('@' . $t))->setTimezone(brb_tz())->format('D, M j, g:i A');
    } catch (Exception $e) {
        $until_text = '';
    }
    $sent = trim((string) ($b->pay_link_sent_at ?? ''));
    $sent_text = '';
    if ($sent !== '' && strtotime($sent . ' UTC')) {
        try { $sent_text = (new DateTimeImmutable('@' . strtotime($sent . ' UTC')))->setTimezone(brb_tz())->format('D, M j, g:i A'); } catch (Exception $e) {}
    }
    return ['seconds_left' => $left, 'lapsed' => $lapsed, 'short' => $short, 'text' => $long, 'until_text' => $until_text, 'clock' => brb_hold_clock_start(), 'link_sent_text' => $sent_text, 'waiting_for_link' => false];
}
function brb_sd_lapsed() {
    global $wpdb;
    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . brb_table() . " WHERE status IN ('approved','held') AND amount_paid < 0.01 AND hold_until IS NOT NULL AND hold_until < %s ORDER BY hold_until ASC, id ASC LIMIT 200",
        gmdate('Y-m-d H:i:s')
    ));
    return array_values(array_filter($rows, 'brb_hold_lapsed'));
}
/** Approved or held, unpaid, priced, still waiting for the pay link. Staff flag only; nothing is freed or emailed. */
function brb_sd_awaiting_link() {
    if (brb_hold_clock_start() !== 'link_sent') return [];
    global $wpdb;
    $rows = (array) $wpdb->get_results("SELECT * FROM " . brb_table() . " WHERE status IN ('approved','held') AND amount_paid < 0.01 AND (hold_until IS NULL OR hold_until = '' OR hold_until LIKE '0000%') ORDER BY start_dt ASC, id ASC LIMIT 200");
    $out = [];
    foreach ($rows as $b) {
        $hi = brb_sd_hold_info($b);
        if ($hi && !empty($hi['waiting_for_link'])) $out[] = $b;
    }
    return $out;
}
/**
 * Staff chose to release a date whose pay window passed. Frees the date (status expired) but sends nothing.
 * Needs the confirm flag from the confirm screen.
 */
function brb_sd_release($id, $p) {
    $b = brb_booking($id);
    if (!$b) return new WP_Error('missing', 'Booking not found.', ['status' => 404]);
    if (empty($p['confirmed'])) return new WP_Error('confirm', 'Confirm the release first. Nothing was changed.', ['status' => 409]);
    if (!brb_hold_lapsed($b)) return new WP_Error('not_lapsed', 'The pay window has not passed, or a payment is recorded. Nothing was changed.', ['status' => 409]);
    if (!brb_sd_lock()) return new WP_Error('lock', 'Please try again in a moment.');
    try {
        global $wpdb;
        $b = brb_booking($id);
        if (!brb_hold_lapsed($b)) return new WP_Error('not_lapsed', 'The pay window has not passed, or a payment is recorded. Nothing was changed.', ['status' => 409]);
        $before = brb_status_label($b->status);
        $wpdb->update(brb_table(), ['status' => 'expired', 'hold_until' => null], ['id' => (int) $b->id]);
        brb_audit_append((int) $b->id, 'staff dashboard: pay window passed; date released by staff; status from "' . $before . '" to "' . brb_status_label('expired') . '"; no email sent');
    } finally {
        brb_sd_unlock();
    }
    return brb_sd_booking_detail(brb_booking($id));
}

/* ---------- remove a hold or closed day ---------- */

function brb_sd_remove_hold($id, $p) {
    $h = brb_hold((int) $id);
    if (!$h) return new WP_Error('missing', 'That hold was not found.', ['status' => 404]);
    if (empty($p['confirmed'])) return new WP_Error('confirm', 'Confirm the removal first. Nothing was changed.', ['status' => 409]);
    if (!brb_sd_lock()) return new WP_Error('lock', 'Please try again in a moment.');
    try {
        $h = brb_hold((int) $id);
        if (!$h) return new WP_Error('missing', 'That hold was not found.', ['status' => 404]);
        if (!empty($h->migrated_to)) return new WP_Error('migrated', 'This hold is now a booking record. Open that booking instead.', ['status' => 409]);
        $done = brb_delete_hold((int) $id);
    } finally {
        brb_sd_unlock();
    }
    if (is_wp_error($done)) return $done;
    $log = get_option('brb_sd_removed', []);
    if (!is_array($log)) $log = [];
    $log[] = ['hold_id' => (int) $h->id, 'type' => (string) $h->hold_type, 'name' => (string) $h->name, 'start' => (string) $h->start_dt, 'who' => brb_audit_who(), 'at' => brb_now()->format('Y-m-d H:i:s')];
    update_option('brb_sd_removed', array_slice($log, -100), false);
    return ['removed' => true, 'date' => substr((string) $h->start_dt, 0, 10)];
}

/* ---------- migrate calendar holds into booking records ---------- */

/** Unpaid and reserved calendar holds that have not been copied yet. Closes (blocked) and blackouts are never touched. */
/** The migrated_to column normally arrives with the plugin upgrade; make sure it is there before any migration step. */
function brb_sd_mig_ready() {
    if (brb_hold_live_sql() === '') brb_install_hold_table();
    return brb_hold_live_sql() !== '';
}
function brb_sd_mig_candidates($only = null) {
    global $wpdb;
    if (!brb_sd_mig_ready()) return [];
    $today = brb_now()->setTime(0, 0)->format('Y-m-d H:i:s');
    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . brb_hold_table() . " WHERE hold_type IN ('hold','reserved') AND migrated_to = 0 AND end_dt > %s ORDER BY start_dt ASC, id ASC LIMIT 500",
        $today
    ));
    if (is_array($only)) $rows = array_values(array_filter($rows, function ($h) use ($only) { return in_array((int) $h->id, array_map('intval', $only), true); }));
    return $rows;
}
function brb_sd_mig_existing($hold_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . brb_table() . " WHERE source = 'hold-migrate' AND status NOT IN ('cancelled','declined','expired','spam') AND audit_log LIKE %s ORDER BY id ASC LIMIT 1",
        '%' . $wpdb->esc_like('calendar hold #' . (int) $hold_id . ' ') . '%'
    ));
}
function brb_sd_mig_conflict($h) {
    $s = new DateTimeImmutable($h->start_dt, brb_tz());
    $e = new DateTimeImmutable($h->end_dt, brb_tz());
    return brb_range_has_booking($s, $e, 0);
}
/** What a run would do. Reads only. */
function brb_sd_mig_plan($only = null) {
    $rows = [];
    foreach (brb_sd_mig_candidates($only) as $h) {
        $act = 'convert';
        $why = '';
        if (brb_sd_mig_existing($h->id)) { $act = 'mark_only'; $why = 'Already copied earlier; only marks the old hold.'; }
        elseif (brb_sd_mig_conflict($h)) { $act = 'skip'; $why = 'A booking already covers this date. The hold stays as it is.'; }
        $rows[] = ['id' => (int) $h->id, 'type' => (string) $h->hold_type, 'name' => brb_sd_scrub($h->name), 'date' => substr((string) $h->start_dt, 0, 10), 'action' => $act, 'reason' => $why];
    }
    return $rows;
}
function brb_sd_mig_summary() {
    global $wpdb;
    $plan = brb_sd_mig_plan();
    $done = brb_sd_mig_ready() ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . brb_hold_table() . ' WHERE migrated_to > 0') : 0;
    return [
        'pending' => $plan,
        'to_convert' => count(array_filter($plan, function ($r) { return $r['action'] !== 'skip'; })),
        'skipped' => count(array_filter($plan, function ($r) { return $r['action'] === 'skip'; })),
        'converted' => $done,
        'last_report' => get_option('brb_sd_migration_report', null),
    ];
}
/**
 * Copy each unpaid or reserved hold into a booking in HOLD status, all under GET_LOCK('brb_book').
 * Order for every hold: (1) insert the booking, which blocks the date, (2) only then mark the old hold migrated.
 * The date is blocked before, between and after the two steps. The old row is kept, so this can be undone.
 * Step hook: do_action('brb_sd_migrate_step', $stage, $hold, $booking_id) for before_insert, after_insert, after_mark.
 */
function brb_sd_mig_run($dry = true, $only = null) {
    global $wpdb;
    $report = ['dry' => (bool) $dry, 'at' => brb_now()->format('Y-m-d H:i:s'), 'who' => is_user_logged_in() ? brb_audit_who() : 'system', 'converted' => [], 'skipped' => [], 'would_convert' => []];
    if ($dry) {
        foreach (brb_sd_mig_plan($only) as $r) {
            if ($r['action'] === 'skip') $report['skipped'][] = ['hold_id' => $r['id'], 'reason' => $r['reason']];
            else $report['would_convert'][] = ['hold_id' => $r['id'], 'type' => $r['type'], 'date' => $r['date']];
        }
        update_option('brb_sd_migration_report', $report, false);
        return $report;
    }
    if (!brb_sd_lock()) return new WP_Error('lock', 'Please try again in a moment.');
    try {
        foreach (brb_sd_mig_candidates($only) as $h) {
            $booking_id = 0;
            $existing = brb_sd_mig_existing($h->id);
            if ($existing) {
                $booking_id = (int) $existing->id;
            } elseif (brb_sd_mig_conflict($h)) {
                $report['skipped'][] = ['hold_id' => (int) $h->id, 'reason' => 'A booking already covers this date. The hold stays as it is.'];
                continue;
            } else {
                do_action('brb_sd_migrate_step', 'before_insert', $h, 0);
                $start = new DateTimeImmutable($h->start_dt, brb_tz());
                $reserved = (string) $h->hold_type === 'reserved';
                // Reserved (paid elsewhere) stays Reserved/Booked: a blocking status with no pay window and no lapse flag.
                $status = $reserved ? 'reserved' : 'held';
                $ok = $wpdb->insert(brb_table(), [
                    'token' => wp_generate_password(32, false),
                    'mode' => 'day',
                    'start_dt' => $h->start_dt,
                    'end_dt' => $h->end_dt,
                    'guests' => 0,
                    'name' => (string) $h->name !== '' ? mb_substr((string) $h->name, 0, 190) : 'Held date',
                    'email' => '',
                    'phone' => '',
                    'notes' => (string) $h->notes,
                    'subtotal' => '0.00', 'cleaning' => '0.00', 'tax' => '0.00', 'total' => '0.00', 'amount_due' => '0.00', 'deposit_due' => '0.00', 'amount_paid' => '0.00',
                    'status' => $status,
                    'source' => 'hold-migrate',
                    'hold_until' => null,
                    'balance_due_on' => brb_balance_due_on($start),
                    'price_set' => 0,
                    'attention' => 'Copied from a calendar hold. Add the guest email, guest count and price before sending an invoice.' . ($reserved ? ' It was marked Reserved (paid elsewhere or cash): confirm the payment and record it.' : ''),
                    'created' => current_time('mysql', true),
                ]);
                if (!$ok) {
                    $report['skipped'][] = ['hold_id' => (int) $h->id, 'reason' => 'Could not save the booking record. The hold stays as it is.'];
                    continue;
                }
                $booking_id = (int) $wpdb->insert_id;
                brb_audit_append($booking_id, 'copied from calendar hold #' . (int) $h->id . ' (' . (string) $h->hold_type . ') by the staff dashboard; status ' . brb_status_label($status) . '; no email sent');
                do_action('brb_sd_migrate_step', 'after_insert', $h, $booking_id);
            }
            $wpdb->update(brb_hold_table(), ['migrated_to' => $booking_id], ['id' => (int) $h->id]);
            do_action('brb_sd_migrate_step', 'after_mark', $h, $booking_id);
            $report['converted'][] = ['hold_id' => (int) $h->id, 'booking_id' => $booking_id, 'date' => substr((string) $h->start_dt, 0, 10)];
        }
    } finally {
        brb_sd_unlock();
    }
    $log = get_option('brb_sd_migration_log', []);
    if (!is_array($log)) $log = [];
    $log[] = $report;
    update_option('brb_sd_migration_log', array_slice($log, -30), false);
    update_option('brb_sd_migration_report', $report, false);
    return $report;
}
/** Put back the old holds that were copied, for bookings nobody has worked on yet. */
function brb_sd_mig_undo($only = null) {
    global $wpdb;
    brb_sd_mig_ready();
    $report = ['undone' => [], 'kept' => [], 'at' => brb_now()->format('Y-m-d H:i:s'), 'who' => brb_audit_who()];
    if (!brb_sd_lock()) return new WP_Error('lock', 'Please try again in a moment.');
    try {
        foreach ((array) $wpdb->get_results('SELECT * FROM ' . brb_hold_table() . ' WHERE migrated_to > 0 ORDER BY id ASC') as $h) {
            if (is_array($only) && !in_array((int) $h->id, array_map('intval', $only), true)) continue;
            $b = brb_booking((int) $h->migrated_to);
            $untouched = $b && (string) $b->source === 'hold-migrate' && in_array((string) $b->status, ['held', 'reserved'], true) && (float) $b->amount_paid < 0.01 && empty($b->hold_until) && !brb_payments($b->id);
            if (!$untouched) {
                $report['kept'][] = ['hold_id' => (int) $h->id, 'booking_id' => (int) $h->migrated_to, 'reason' => 'The booking has been worked on, so it stays a booking.'];
                continue;
            }
            // Old hold blocks again first, then the booking record is closed: no gap.
            $wpdb->update(brb_hold_table(), ['migrated_to' => 0], ['id' => (int) $h->id]);
            $before = brb_status_label($b->status);
            $wpdb->update(brb_table(), ['status' => 'cancelled', 'source' => 'hold-migrate-undone'], ['id' => (int) $b->id]);
            brb_audit_append((int) $b->id, 'undo of the calendar hold copy (hold #' . (int) $h->id . ' is a calendar hold again); status from "' . $before . '" to "' . brb_status_label('cancelled') . '"; no email sent');
            $report['undone'][] = ['hold_id' => (int) $h->id, 'booking_id' => (int) $b->id];
        }
    } finally {
        brb_sd_unlock();
    }
    return $report;
}

/* ---------- balance reminder queue ---------- */

function brb_sd_reminder_approved() { return (string) brb_opt('balance_reminder_template_approved') === '1'; }
function brb_sd_balance_due_days() {
    $n = (int) brb_opt('balance_due_days');
    return $n > 0 ? $n : 7;
}
function brb_sd_reminder_lead_days() { return max(1, (int) apply_filters('brb_sd_reminder_lead_days', brb_sd_balance_due_days())); }
function brb_sd_reminder_opens_on($b) {
    $on = trim((string) ($b->balance_due_on ?? ''));
    if ($on === '') $on = brb_balance_due_on(new DateTimeImmutable($b->start_dt, brb_tz()));
    return (new DateTimeImmutable($on, brb_tz()))->modify('-' . brb_sd_reminder_lead_days() . ' days')->setTime(0, 0);
}
function brb_sd_reminder_is_open($b) {
    return brb_now()->setTime(0, 0) >= brb_sd_reminder_opens_on($b);
}
function brb_sd_reminder_notice() { return 'DRAFT WORDING, needs Max approval'; }
/**
 * Placeholder text. The structure follows Les's requirements; the wording is NOT signed off (DRAFT WORDING, needs Max approval).
 * Shows the exact balance, the card fee as its own line, the due date (7 days before the event) and the signed pay link.
 * Names Boardroom QC LLC only. No phone number, no entry codes, no fixed dollar figure other than this booking's own.
 */
function brb_sd_balance_reminder($b) {
    $link = brb_pay_link($b);
    if ($link === '') return new WP_Error('link', 'There is no pay link yet. Still needed: ' . implode(', ', brb_sd_blockers($b)) . '.');
    $due = brb_amount_due_now($b); // the same figure the pay page charges
    $base = brb_cents_from_amount($due);
    $fee = brb_card_fee_cents($base);
    $due_ymd = brb_balance_due_on(new DateTimeImmutable($b->start_dt, brb_tz()));
    $first = trim((string) strtok((string) $b->name, ' '));
    $subject = 'A friendly reminder about your Boardroom QC balance (' . brb_ref($b) . ')';
    $body = 'Hello ' . ($first !== '' ? $first : 'there') . ",\n\n"
        . 'A friendly reminder about your event on ' . brb_sd_fmt_date($b->start_dt, 'l, F j, Y') . ' (' . brb_ref($b) . ").\n\n";
    $body .= 'Remaining balance: ' . brb_money($due) . "\n";
    $body .= 'Card processing fee (' . brb_fee_percent_label() . '): ' . brb_money($fee / 100) . "\n";
    $body .= 'Total charged to your card: ' . brb_money(($base + $fee) / 100) . "\n";
    $days = brb_sd_balance_due_days();
    $body .= 'Balance due by: ' . brb_sd_fmt_date($due_ymd, 'l, F j, Y') . ' (' . $days . ' day' . ($days === 1 ? '' : 's') . " before your event)\n\n";
    $body .= 'Pay online: ' . $link . "\n\n";
    $body .= "The card processing fee is not part of the booking balance. You can also pay by check or in person.\n\nThank you,\nBoardroom QC LLC\n";
    if (!brb_sd_reminder_approved()) {
        $subject = 'DRAFT: ' . $subject;
        $body = brb_sd_reminder_notice() . ".\n\n" . $body;
    }
    return ['subject' => $subject, 'body' => $body];
}
/** One balance reminder per booking, ever. The marker lives on the booking row (balance_reminded). */
function brb_sd_reminder_sent($booking_id) {
    $b = brb_booking((int) $booking_id);
    if ($b && !empty($b->balance_reminded)) return true;
    foreach (brb_sd_sent_log() as $r) if ((int) $r['booking_id'] === (int) $booking_id && $r['kind'] === 'balance') return true;
    return false;
}
/** Atomically claim the one balance reminder. Returns false if one was already sent (or is being sent). */
function brb_sd_reminder_claim($booking_id) {
    global $wpdb;
    brb_ensure_column(brb_table(), 'balance_reminded', 'datetime NULL DEFAULT NULL');
    $n = $wpdb->query($wpdb->prepare('UPDATE ' . brb_table() . ' SET balance_reminded = %s WHERE id = %d AND balance_reminded IS NULL', current_time('mysql', true), (int) $booking_id));
    return (int) $n === 1;
}
function brb_sd_reminder_release($booking_id) {
    global $wpdb;
    $wpdb->query($wpdb->prepare('UPDATE ' . brb_table() . ' SET balance_reminded = NULL WHERE id = %d', (int) $booking_id));
}

/** Week-out checklist email (Max, Nov 23 10:19 AM CT): staff preview it first. Same wording as before. */
function brb_sd_checklist_eligible($b) {
    if (!$b || !in_array((string) $b->status, ['approved', 'deposit_paid', 'paid', 'confirmed', 'held'], true)) return false;
    if (!empty($b->checklist_reminded)) return false;
    $day = substr((string) $b->start_dt, 0, 10);
    $today = brb_now()->format('Y-m-d');
    return $day >= $today && $day <= brb_now()->modify('+7 days')->format('Y-m-d');
}
/**
 * The reminders staff may preview. One balance reminder per Paid Deposit booking with a balance, and one week-out
 * checklist email per booking in its last 7 days. Derived from the bookings, so nothing can double up, and nothing here sends.
 */
function brb_sd_reminder_queue() {
    global $wpdb;
    $today = brb_now()->setTime(0, 0);
    $out = [];
    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . brb_table() . " WHERE status = 'deposit_paid' AND end_dt > %s ORDER BY start_dt ASC, id ASC LIMIT 200",
        $today->format('Y-m-d H:i:s')
    ));
    foreach ($rows as $b) {
        $left = round((float) $b->total - (float) $b->amount_paid, 2);
        if ($left <= 0.009 || brb_sd_reminder_sent($b->id)) continue;
        $on = trim((string) ($b->balance_due_on ?? ''));
        if ($on === '') continue;
        $opens = (new DateTimeImmutable($on, brb_tz()))->modify('-' . brb_sd_reminder_lead_days() . ' days')->setTime(0, 0);
        $out[] = [
            'booking_id' => (int) $b->id, 'ref' => brb_ref($b), 'name' => brb_sd_scrub($b->name), 'kind' => 'balance',
            'title' => 'Balance reminder for ' . brb_sd_scrub($b->name),
            'detail' => brb_money(brb_amount_due_now($b) > 0.009 ? brb_amount_due_now($b) : $left) . ' due ' . brb_sd_fmt_date(brb_balance_due_on(new DateTimeImmutable($b->start_dt, brb_tz())), 'D, M j, Y'),
            'when' => brb_sd_fmt_date($b->start_dt, 'D, M j, Y'),
            'ready' => $today >= $opens, 'opens_text' => $opens->format('D, M j'),
            'draft' => true, 'can_send' => brb_sd_reminder_approved(),
            'has_email' => (bool) is_email((string) $b->email),
            'has_link' => brb_pay_link($b) !== '',
        ];
    }
    $to = brb_now()->modify('+7 days')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
    $rows = (array) $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . brb_table() . " WHERE checklist_reminded IS NULL AND status IN ('approved','held','deposit_paid','paid','confirmed') AND start_dt >= %s AND start_dt <= %s ORDER BY start_dt ASC, id ASC LIMIT 200",
        $today->format('Y-m-d H:i:s'), $to
    ));
    foreach ($rows as $b) {
        if (!brb_sd_checklist_eligible($b)) continue;
        $out[] = [
            'booking_id' => (int) $b->id, 'ref' => brb_ref($b), 'name' => brb_sd_scrub($b->name), 'kind' => 'checklist',
            'title' => 'Week-out checklist for ' . brb_sd_scrub($b->name),
            'detail' => 'Event ' . brb_sd_fmt_date($b->start_dt, 'D, M j, Y'),
            'when' => brb_sd_fmt_date($b->start_dt, 'D, M j, Y'),
            'ready' => true, 'opens_text' => '',
            'draft' => false, 'can_send' => true,
            'has_email' => (bool) is_email((string) $b->email),
        ];
    }
    return $out;
}

/* ---------- upgrade: dry-run report only ---------- */

// On a new plugin version, look at the calendar holds and store a report of what a run would do. Changes nothing.
add_action('init', function () {
    if (get_option('brb_sd_flow_version') === BRB_VER) return;
    update_option('brb_sd_flow_version', BRB_VER, false);
    global $wpdb;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', brb_hold_table())) === brb_hold_table() && brb_sd_mig_ready()) brb_sd_mig_run(true);
}, 31);
