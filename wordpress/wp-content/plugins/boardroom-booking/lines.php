<?php
if (!defined('ABSPATH')) exit;

function brb_line_table() {
    global $wpdb;
    return $wpdb->prefix . 'br_line_items';
}
function brb_damage_amount() {
    $n = (float) brb_opt('damage_deposit_amount');
    if ($n <= 0) $n = 250;
    return round($n, 2);
}
function brb_install_line_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $t = brb_line_table();
    dbDelta("CREATE TABLE $t (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      booking_id bigint(20) unsigned NOT NULL,
      label varchar(190) NOT NULL DEFAULT '',
      amount decimal(10,2) NOT NULL DEFAULT 0.00,
      kind varchar(20) NOT NULL DEFAULT 'fee',
      refundable tinyint(1) NOT NULL DEFAULT 0,
      sort_order int(11) NOT NULL DEFAULT 0,
      locked tinyint(1) NOT NULL DEFAULT 0,
      created datetime NOT NULL,
      PRIMARY KEY  (id),
      KEY booking_id (booking_id)
    ) $c;");
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t;
}
function brb_lines($booking_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . brb_line_table() . ' WHERE booking_id=%d ORDER BY sort_order ASC, id ASC',
        (int) $booking_id
    ));
}
function brb_line($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . brb_line_table() . ' WHERE id=%d', (int) $id));
}
function brb_find_line_kind($booking_id, $kind) {
    foreach (brb_lines($booking_id) as $line) {
        if ((string) $line->kind === $kind) return $line;
    }
    return null;
}
function brb_line_kinds() {
    return ['rental', 'addon', 'fee', 'discount', 'damage_deposit'];
}
function brb_damage_left($b) {
    if (!$b || !empty($b->damage_refunded)) return 0.0;
    return max(0, round((float) ($b->damage_deposit ?? 0) - (float) ($b->damage_paid ?? 0), 2));
}
function brb_rental_left($b) {
    if (!$b) return 0.0;
    return max(0, round((float) $b->total - (float) $b->amount_paid, 2));
}
function brb_nonrefundable_total($booking_id) {
    $sum = 0.0;
    foreach (brb_lines($booking_id) as $line) {
        if ((string) $line->kind === 'damage_deposit' || !empty($line->refundable)) continue;
        $sum += (float) $line->amount;
    }
    return round($sum, 2);
}
function brb_insert_line($booking_id, $label, $amount, $kind, $refundable = 0, $sort = 50) {
    global $wpdb;
    $kind = in_array($kind, brb_line_kinds(), true) ? $kind : 'fee';
    $ok = $wpdb->insert(brb_line_table(), [
        'booking_id' => (int) $booking_id,
        'label' => mb_substr(sanitize_text_field($label), 0, 190),
        'amount' => brb_dec($amount),
        'kind' => $kind,
        'refundable' => $refundable ? 1 : 0,
        'sort_order' => (int) $sort,
        'locked' => 0,
        'created' => current_time('mysql', true),
    ]);
    return $ok ? (int) $wpdb->insert_id : 0;
}
function brb_lock_lines($booking_id) {
    global $wpdb;
    $wpdb->query($wpdb->prepare('UPDATE ' . brb_line_table() . ' SET locked=1 WHERE booking_id=%d AND locked=0', (int) $booking_id));
}
function brb_recalc_booking($booking_id) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return null;
    $damage = 0.0;
    foreach (brb_lines($b->id) as $line) {
        if ((string) $line->kind === 'damage_deposit') $damage += (float) $line->amount;
    }
    $damage = round($damage, 2);
    $fields = ['damage_deposit' => brb_dec(max(0, $damage))];
    if (brb_price_is_set($b)) {
        $rent = brb_nonrefundable_total($b->id);
        if ($rent < 0) $rent = 0;
        $due = brb_deposit_due_amount($rent);
        $fields['subtotal'] = brb_dec($rent);
        $fields['total'] = brb_dec($rent);
        $fields['deposit_due'] = brb_dec($due);
        $fields['amount_due'] = brb_dec($due);
        $paid = (float) $b->amount_paid;
        if ($paid + 0.009 >= $rent && $rent > 0.009) $fields['status'] = 'paid';
        elseif ($paid > 0.009 || (float) ($b->damage_paid ?? 0) > 0.009) $fields['status'] = 'deposit_paid';
    }
    $wpdb->update(brb_table(), $fields, ['id' => (int) $b->id]);
    return brb_booking($b->id);
}
function brb_ensure_damage_line($booking_id) {
    $existing = brb_find_line_kind($booking_id, 'damage_deposit');
    if ($existing) return $existing;
    $id = brb_insert_line($booking_id, 'Refundable damage deposit', brb_damage_amount(), 'damage_deposit', 1, 90);
    if (!$id) return null;
    $b = brb_booking($booking_id);
    if ($b && brb_price_is_set($b)) return brb_line($id);
    global $wpdb;
    $wpdb->update(brb_table(), ['damage_deposit' => brb_dec(brb_damage_amount())], ['id' => (int) $booking_id]);
    return brb_line($id);
}
function brb_ensure_rental_line($booking_id, $amount) {
    $amount = round((float) $amount, 2);
    $line = brb_find_line_kind($booking_id, 'rental');
    if ($line) {
        if (!empty($line->locked) && abs((float) $line->amount - $amount) > 0.009) {
            return new WP_Error('locked', 'Paid charges are locked. Add a new line or a credit.');
        }
        if (empty($line->locked)) {
            global $wpdb;
            $wpdb->update(brb_line_table(), ['amount' => brb_dec($amount), 'label' => 'Rental'], ['id' => (int) $line->id]);
        }
        return brb_line($line->id);
    }
    $id = brb_insert_line($booking_id, 'Rental', $amount, 'rental', 0, 10);
    return $id ? brb_line($id) : new WP_Error('db', 'Could not save the rental line.');
}
function brb_ensure_addon_line($booking_id, $which) {
    $which = $which === 'takedown' ? 'takedown' : 'setup';
    $label = $which === 'setup' ? 'Setup day' : 'Takedown day';
    foreach (brb_lines($booking_id) as $line) {
        if ((string) $line->kind === 'addon' && (string) $line->label === $label) return $line;
    }
    $id = brb_insert_line($booking_id, $label, brb_addon_price(), 'addon', 0, $which === 'setup' ? 20 : 21);
    return $id ? brb_line($id) : null;
}
function brb_save_custom_line($booking_id, $label, $amount, $line_id = 0) {
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if (in_array($b->status, ['cancelled', 'declined'], true)) return new WP_Error('status', 'That booking cannot take a charge.');
    $label = trim(sanitize_text_field($label));
    if ($label === '') return new WP_Error('label', 'Enter a label for the charge.');
    $amount = round((float) $amount, 2);
    if (abs($amount) < 0.009) return new WP_Error('amount', 'Enter an amount other than zero. Use a negative amount for a discount or credit.');
    $kind = $amount < 0 ? 'discount' : 'fee';
    if ($line_id) {
        $line = brb_line($line_id);
        if (!$line || (int) $line->booking_id !== (int) $b->id) return new WP_Error('missing', 'Charge not found.');
        if (!empty($line->locked)) return new WP_Error('locked', 'Paid charges are locked. Add a new line or a credit.');
        if (!in_array((string) $line->kind, ['fee', 'discount'], true)) return new WP_Error('kind', 'That charge is managed with the rental, add-ons, or damage deposit.');
        global $wpdb;
        $wpdb->update(brb_line_table(), [
            'label' => mb_substr($label, 0, 190),
            'amount' => brb_dec($amount),
            'kind' => $kind,
            'refundable' => 0,
        ], ['id' => (int) $line->id]);
    } else {
        $sort = 50;
        foreach (brb_lines($b->id) as $line) $sort = max($sort, (int) $line->sort_order + 1);
        if ($sort >= 90) $sort = 80;
        if (!brb_insert_line($b->id, $label, $amount, $kind, 0, $sort)) return new WP_Error('db', 'Could not save that charge.');
    }
    if (brb_price_is_set($b)) return brb_recalc_booking($b->id);
    return brb_booking($b->id);
}
function brb_remove_custom_line($booking_id, $line_id) {
    $b = brb_booking($booking_id);
    $line = brb_line($line_id);
    if (!$b || !$line || (int) $line->booking_id !== (int) $b->id) return new WP_Error('missing', 'Charge not found.');
    if (!empty($line->locked)) return new WP_Error('locked', 'Paid charges are locked.');
    if (!in_array((string) $line->kind, ['fee', 'discount'], true)) return new WP_Error('kind', 'That charge is managed with the rental, add-ons, or damage deposit.');
    global $wpdb;
    $wpdb->delete(brb_line_table(), ['id' => (int) $line->id], ['%d']);
    if (brb_price_is_set($b)) return brb_recalc_booking($b->id);
    return brb_booking($b->id);
}
function brb_split_payment($b, $amount, $damage_explicit = null) {
    $amount = round((float) $amount, 2);
    $rental_left = brb_rental_left($b);
    $damage_left = brb_damage_left($b);
    if ($damage_explicit === null) {
        $deposit_left = max(0, round((float) $b->deposit_due - (float) $b->amount_paid, 2));
        if ($deposit_left > 0.009) {
            $to_deposit = min($deposit_left, $amount);
            $rest = round($amount - $to_deposit, 2);
            $to_damage = min($damage_left, $rest);
            $to_rental = round($to_deposit + ($rest - $to_damage), 2);
        } else {
            $to_rental = min($rental_left, $amount);
            $to_damage = 0.0;
            if ($amount - $to_rental > 0.009) return new WP_Error('amount', 'That amount is more than the balance of ' . brb_money($rental_left) . '.');
        }
    } else {
        $to_damage = round((float) $damage_explicit, 2);
        $to_rental = round($amount - $to_damage, 2);
    }
    if ($to_damage < -0.001 || $to_rental < -0.001) return new WP_Error('amount', 'That payment could not be split.');
    if ($to_damage - $damage_left > 0.009) return new WP_Error('amount', 'That amount is more than the refundable damage deposit still due.');
    if ($to_rental - $rental_left > 0.009) return new WP_Error('amount', 'That amount is more than the balance of ' . brb_money($rental_left + $damage_left) . '.');
    return ['rental' => $to_rental, 'damage' => $to_damage];
}
function brb_checkout_lines($b) {
    $lines = [];
    $damage_cents = 0;
    $deposit_left = max(0, round((float) $b->deposit_due - (float) $b->amount_paid, 2));
    $damage_left = brb_damage_left($b);
    if ($deposit_left > 0.009) {
        $o = brb_opt();
        $name = 'Deposit';
        if (($o['deposit_type'] ?? '') === 'percent') {
            $pct = (float) ($o['deposit_pct'] ?? 50);
            if ($pct <= 0) $pct = 50;
            if ($pct > 100) $pct = 100;
            $label = (abs($pct - round($pct)) < 0.001) ? (string) (int) round($pct) : rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
            $name = 'Deposit (' . $label . '%)';
        }
        $lines[] = ['name' => $name, 'cents' => brb_cents_from_amount($deposit_left)];
        if ($damage_left > 0.009) {
            $damage_cents = brb_cents_from_amount($damage_left);
            $lines[] = ['name' => 'Refundable damage deposit', 'cents' => $damage_cents];
        }
    } else {
        $credit = (float) $b->amount_paid;
        foreach (brb_lines($b->id) as $line) {
            if ((string) $line->kind === 'damage_deposit' || !empty($line->refundable)) continue;
            $amt = round((float) $line->amount, 2);
            if ($amt < 0) $credit += -$amt;
        }
        $credit = round($credit, 2);
        foreach (brb_lines($b->id) as $line) {
            if ((string) $line->kind === 'damage_deposit' || !empty($line->refundable)) continue;
            $amt = round((float) $line->amount, 2);
            if ($amt <= 0.009) continue;
            $take = min($amt, max(0, $credit));
            $credit = round($credit - $take, 2);
            $left = round($amt - $take, 2);
            if ($left > 0.009) $lines[] = ['name' => (string) $line->label, 'cents' => brb_cents_from_amount($left)];
        }
        if ($damage_left > 0.009) {
            $damage_cents = brb_cents_from_amount($damage_left);
            $lines[] = ['name' => 'Refundable damage deposit', 'cents' => $damage_cents];
        }
    }
    $base = 0;
    foreach ($lines as $line) $base += (int) $line['cents'];
    return ['lines' => $lines, 'base_cents' => $base, 'damage_cents' => $damage_cents];
}
function brb_payment_link_body($b) {
    $url = brb_pay_link($b);
    $charge = brb_checkout_lines($b);
    $fee_cents = brb_card_fee_cents($charge['base_cents']);
    $lines = "Here is a link to pay for " . brb_ref($b) . ".\n\n";
    foreach ($charge['lines'] as $line) {
        $lines .= $line['name'] . ': ' . brb_money($line['cents'] / 100) . "\n";
    }
    if ($fee_cents > 0) $lines .= 'Card processing fee (' . brb_fee_percent_label() . '): ' . brb_money($fee_cents / 100) . "\n";
    $lines .= 'Total charged to your card: ' . brb_money(($charge['base_cents'] + $fee_cents) / 100) . "\n\n";
    if ($url !== '') $lines .= "Pay online: " . $url . "\n\n";
    $lines .= "The refundable damage deposit is separate from the 50% that holds the date, and it is charged with that deposit. The card processing fee applies to both and is not part of the booking balance. You can still pay by check or in person.\n";
    return $lines;
}
function brb_send_payment_link($booking_id) {
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    $url = brb_pay_link($b);
    if ($url === '') return new WP_Error('link', 'There is no pay link to send yet. The walk-through, the price, and card payment all need to be ready.');
    $body = brb_guest_hello_line() . "\n\n" . brb_payment_link_body($b);
    brb_mail($b->email, 'Your Boardroom payment link (' . brb_ref($b) . ')', $body, brb_opt('admin_email'));
    return $b;
}
function brb_mark_damage_refunded($booking_id) {
    global $wpdb;
    $b = brb_booking($booking_id);
    if (!$b) return new WP_Error('missing', 'Booking not found.');
    if ((float) ($b->damage_paid ?? 0) <= 0.009) return new WP_Error('damage', 'The refundable damage deposit has not been paid yet.');
    $wpdb->update(brb_table(), ['damage_refunded' => 1], ['id' => (int) $b->id]);
    return brb_booking($b->id);
}
function brb_backfill_line_items() {
    global $wpdb;
    if (!brb_install_line_table()) return;
    brb_ensure_column(brb_table(), 'damage_paid', 'decimal(10,2) NOT NULL DEFAULT 0.00');
    brb_ensure_column(brb_table(), 'damage_refunded', 'tinyint(1) NOT NULL DEFAULT 0');
    $rows = $wpdb->get_results("SELECT * FROM " . brb_table() . " WHERE status NOT IN ('cancelled','declined','expired')");
    foreach ((array) $rows as $b) {
        if (!brb_lines($b->id)) {
            $addon_sum = ((int) $b->setup_addon + (int) $b->takedown_addon) * brb_addon_price();
            $rent = round((float) $b->total - $addon_sum, 2);
            if (brb_price_is_set($b) || (float) $b->total > 0.009) {
                if ($rent > 0.009) brb_insert_line($b->id, 'Rental', $rent, 'rental', 0, 10);
                elseif ((float) $b->total > 0.009) brb_insert_line($b->id, 'Rental', (float) $b->total, 'rental', 0, 10);
                if ($rent > 0.009 && !empty($b->setup_addon)) brb_insert_line($b->id, 'Setup day', brb_addon_price(), 'addon', 0, 20);
                if ($rent > 0.009 && !empty($b->takedown_addon)) brb_insert_line($b->id, 'Takedown day', brb_addon_price(), 'addon', 0, 21);
            }
        }
        brb_ensure_damage_line($b->id);
        if ((float) $b->amount_paid > 0.009 || (float) ($b->damage_paid ?? 0) > 0.009) brb_lock_lines($b->id);
    }
}
